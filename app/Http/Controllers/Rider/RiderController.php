<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\PickupRequest;
use App\Models\RiderLocationPing;
use App\Support\Activity;
use App\Support\Geocoder;
use App\Support\RiderMapStages;
use App\Support\Routing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The rider's own console, built for a phone held in one hand.
 *
 * A rider sees two things: the runs they are holding, and the bookings at their
 * own branch nobody has taken yet. Confirming one of those is what assigns it —
 * no dispatcher in the middle. Another rider's runs stay invisible either way.
 */
class RiderController extends Controller
{
    public function index(Request $request)
    {
        $rider = $request->user();

        // Split by what the rider actually has to do next, because a run
        // collected today and delivered tomorrow is two separate jobs in their
        // day and reads terribly as one undifferentiated list.
        $toCollect = PickupRequest::query()
            ->where('rider_id', $rider->id)
            ->where('status', 'confirmed')
            ->with(['items', 'customer:id,name,phone', 'branch:id,name,address'])
            ->orderBy('pickup_date')
            ->orderBy('id')
            ->get();

        $toDeliver = PickupRequest::query()
            ->where('rider_id', $rider->id)
            ->where('status', 'picked_up')
            ->with(['items', 'customer:id,name,phone', 'branch:id,name,address', 'jobOrder:id,job_order_number,status'])
            ->orderByRaw('CASE WHEN delivery_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('delivery_date')
            ->orderBy('id')
            ->get();

        // Recently finished, so a rider can check back on what they dropped off
        // yesterday without ringing the branch.
        $recent = PickupRequest::query()
            ->where('rider_id', $rider->id)
            ->whereIn('status', ['completed', 'cancelled'])
            ->where(fn ($query) => $query
                ->whereDate('delivered_at', '>=', today()->subDays(7))
                ->orWhereDate('cancelled_at', '>=', today()->subDays(7)))
            ->with(['customer:id,name,phone'])
            ->orderByDesc('delivered_at')
            ->orderByDesc('cancelled_at')
            ->limit(15)
            ->get();

        // Up for grabs: this branch's open bookings with no rider on them.
        // Rush first, then whoever has been waiting longest for a van.
        $available = PickupRequest::query()
            ->whereNull('rider_id')
            ->where('branch_id', $rider->branch_id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->with(['items', 'customer:id,name,phone', 'branch:id,name,address'])
            ->orderByDesc('is_rush')
            ->orderBy('pickup_date')
            ->orderBy('id')
            ->get();

        $completedToday = PickupRequest::query()
            ->where('rider_id', $rider->id)
            ->whereDate('delivered_at', today())
            ->count();

        $data = [
            'rider' => $rider,
            // Work in hand is what makes location sharing worth nagging about.
            'riderHasOpenRuns' => $toCollect->isNotEmpty() || $toDeliver->isNotEmpty(),
            'toCollect' => $toCollect,
            'toDeliver' => $toDeliver,
            'recent' => $recent,
            'available' => $available,
            'completedToday' => $completedToday,
        ];

        return view('rider.index', $data + [
            'runsSignature' => $this->runsSignature($data),
        ]);
    }

    /**
     * The same list, rendered again for a phone that is already showing it.
     *
     * The server stays the author of how a run looks: this hands back the very
     * markup the page was built with rather than a shape the client has to
     * know how to draw. The signature lets the phone skip the swap when
     * nothing has moved, which is most of the time.
     */
    public function runsFeed(Request $request)
    {
        $rider = $request->user();
        $page = $this->index($request);
        $data = $page->getData();

        return response()->json([
            'signature' => $data['runsSignature'],
            'html' => view('rider.partials.runs', $data)->render(),
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * A fingerprint of everything the list shows. Any new run, any status
     * change and any reassignment moves it; a page view does not.
     */
    private function runsSignature(array $data): string
    {
        $parts = collect(['toCollect', 'toDeliver', 'recent', 'available'])
            ->flatMap(fn (string $key) => $data[$key]
                ->map(fn (PickupRequest $job) => implode(':', [
                    $job->id,
                    $job->status,
                    $job->rider_id,
                    $job->tag_code,
                    $job->updated_at?->getTimestamp(),
                ]))
                ->all())
            ->push('done:'.$data['completedToday']);

        return md5($parts->implode('|'));
    }

    public function show(Request $request, PickupRequest $pickupRequest)
    {
        $rider = $request->user();
        $isMine = (int) $pickupRequest->rider_id === (int) $rider->id;

        // An unclaimed booking opens too, so a rider can look at where it is
        // before deciding to take it.
        abort_unless($isMine || $this->isClaimableBy($pickupRequest, $rider), 403);

        $pickupRequest->load(['items', 'customer:id,name,phone', 'branch:id,name,address']);

        return view('rider.show', [
            'job' => $pickupRequest,
            'rider' => $rider,
            'isMine' => $isMine,
        ]);
    }

    /**
     * The rider takes the booking themselves: confirming it is what assigns it.
     *
     * Conditional update rather than read-then-write, so two riders tapping at
     * the same moment cannot both end up holding the same run. The second one
     * matches no rows and is told it has gone.
     */
    public function claim(Request $request, PickupRequest $pickupRequest)
    {
        $rider = $request->user();
        $token = $this->validatedClientToken($request);

        $done = fn () => $this->riderActionDone(
            $request,
            'Confirmed. '.$pickupRequest->reference_no.' is yours, directions are ready.',
            route('rider.jobs.show', $pickupRequest)
        );

        // Checked before "is it claimable": once this rider holds it, it no
        // longer is, and the resend of their own tap would be refused. Only
        // while they still hold it, though: if the branch has since handed it
        // to someone else, "it is yours" would be a lie, so fall through.
        if ($this->isRepeatedAction($request, $pickupRequest, $token)
            && (int) $pickupRequest->rider_id === (int) $rider->id) {
            return $done();
        }

        // A message, not a bare 403: a claim queued in a dead spot is refused
        // in the background, and this is the sentence the rider reads later.
        abort_unless(
            $this->isClaimableBy($pickupRequest, $rider),
            403,
            'Someone else has this run now, so it was not added to yours.'
        );

        $claimed = PickupRequest::query()
            ->whereKey($pickupRequest->getKey())
            ->whereNull('rider_id')
            ->whereIn('status', ['pending', 'confirmed'])
            ->update([
                'rider_id' => $rider->id,
                'assigned_at' => now(),
                'status' => 'confirmed',
                'confirmed_at' => $pickupRequest->confirmed_at ?: now(),
                'rider_action_token' => $this->boundActionToken($request, $token),
                'updated_at' => now(),
            ]);

        if (! $claimed) {
            return $this->riderActionRefused($request, 'Another rider got there first, that run is taken.', route('rider.index'));
        }

        Activity::log($request, 'pickup_request_rider_claimed', $pickupRequest, [
            'reference_no' => $pickupRequest->reference_no,
            'rider_id' => $rider->id,
        ], $pickupRequest->branch_id);

        return $done();
    }

    /**
     * Hand a run back to the branch list. For a rider who cannot make it after
     * all: better than cancelling a booking the customer still wants.
     */
    public function release(Request $request, PickupRequest $pickupRequest)
    {
        $token = $this->validatedClientToken($request);

        $done = fn () => $this->riderActionDone(
            $request,
            $pickupRequest->reference_no.' is back in the list for another rider.',
            route('rider.index')
        );

        // Before the ownership check: after handing it back the rider no longer
        // owns it, so their own resend would otherwise be a 403. If it has
        // somehow come back to them, the hand-back is not in effect any more.
        if ($this->isRepeatedAction($request, $pickupRequest, $token)
            && (int) $pickupRequest->rider_id !== (int) $request->user()->id) {
            return $done();
        }

        $this->authorizeRiderJob($request, $pickupRequest);

        if ($pickupRequest->status !== 'confirmed') {
            return $this->riderActionRefused($request, 'Only a run you have not collected yet can be handed back.');
        }

        $pickupRequest->update([
            'rider_id' => null,
            'assigned_at' => null,
            'rider_action_token' => $this->boundActionToken($request, $token),
        ]);

        Activity::log($request, 'pickup_request_rider_released', $pickupRequest, [
            'reference_no' => $pickupRequest->reference_no,
            'rider_id' => $request->user()->id,
        ], $pickupRequest->branch_id);

        return $done();
    }

    /**
     * Cancel from the road — nobody home, wrong address, customer changed their
     * mind at the gate. The reason is required because the branch has to answer
     * for it when the customer rings.
     */
    public function cancel(Request $request, PickupRequest $pickupRequest)
    {
        $this->authorizeRiderJob($request, $pickupRequest);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
            'client_token' => ['nullable', 'string', 'max:40'],
        ], [
            'reason.required' => 'Please say what happened, so the branch can tell the customer.',
        ]);

        $token = $validated['client_token'] ?? null;
        $message = 'Booking '.$pickupRequest->reference_no.' cancelled. The branch can see why.';

        if ($this->isRepeatedAction($request, $pickupRequest, $token)
            && $pickupRequest->status === 'cancelled') {
            return $this->riderActionDone($request, $message, route('rider.index'));
        }

        // Once the laundry is in the rider's hands it is the branch's to sort
        // out, not something to close from a phone.
        if (! $pickupRequest->isCancellable()) {
            return $this->riderActionRefused($request, 'That booking can no longer be cancelled here. Call the branch.');
        }

        $pickupRequest->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'Rider: '.$validated['reason'],
            'rider_action_token' => $this->boundActionToken($request, $token),
        ]);

        Activity::log($request, 'pickup_request_rider_cancelled', $pickupRequest, [
            'reference_no' => $pickupRequest->reference_no,
            'rider_id' => $request->user()->id,
            'reason' => $validated['reason'],
        ], $pickupRequest->branch_id);

        return $this->riderActionDone($request, $message, route('rider.index'));
    }

    /**
     * Position report from the rider's phone.
     *
     * Called on an interval while the rider has sharing switched on, so it is
     * deliberately cheap: one indexed update plus one insert, no eager loads,
     * and a plain JSON body back.
     */
    public function ping(Request $request)
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:65535'],
            'heading' => ['nullable', 'numeric', 'min:0', 'max:360'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'pickup_request_id' => ['nullable', 'integer', 'exists:pickup_requests,id'],
        ]);

        $rider = $request->user();
        $accuracy = $validated['accuracy'] ?? null;

        // A low-confidence fix (indoors, wifi-only) would otherwise throw the
        // marker hundreds of metres and make the trail look like teleporting.
        $maxAccuracy = (int) config('maps.tracking.max_accuracy_meters');
        if ($accuracy !== null && $accuracy > $maxAccuracy) {
            return response()->json([
                'accepted' => false,
                'reason' => 'accuracy_too_low',
            ]);
        }

        // Only accept a ping tied to a job this rider actually holds.
        $pickupRequestId = $validated['pickup_request_id'] ?? null;
        if ($pickupRequestId !== null) {
            $owns = PickupRequest::query()
                ->whereKey($pickupRequestId)
                ->where('rider_id', $rider->id)
                ->exists();

            if (! $owns) {
                $pickupRequestId = null;
            }
        }

        $recordedAt = now();

        $rider->forceFill([
            'last_latitude' => $validated['latitude'],
            'last_longitude' => $validated['longitude'],
            'last_location_accuracy' => $accuracy !== null ? (int) round($accuracy) : null,
            'last_location_heading' => isset($validated['heading'])
                ? (int) round($validated['heading']) % 360
                : null,
            'last_location_at' => $recordedAt,
            'is_sharing_location' => true,
        ])->save();

        RiderLocationPing::create([
            'rider_id' => $rider->id,
            'pickup_request_id' => $pickupRequestId,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'accuracy' => $accuracy !== null ? (int) round($accuracy) : null,
            'heading' => isset($validated['heading']) ? (int) round($validated['heading']) % 360 : null,
            'speed' => $validated['speed'] ?? null,
            'recorded_at' => $recordedAt,
        ]);

        return response()->json([
            'accepted' => true,
            'recorded_at' => $recordedAt->toIso8601String(),
            'next_ping_in' => (int) config('maps.tracking.ping_interval_seconds'),
        ]);
    }

    /**
     * Road route from where the rider is now to where this job is going.
     *
     * Called when the job screen opens, when the rider strays off the line, and
     * when they drop a via point to force a different way round.
     */
    public function route(Request $request, PickupRequest $pickupRequest)
    {
        $rider = $request->user();

        // Same reach as the job screen: a rider can see the way to a booking
        // they are holding, and to one they are deciding whether to take.
        abort_unless(
            (int) $pickupRequest->rider_id === (int) $rider->id
                || $this->isClaimableBy($pickupRequest, $rider),
            403
        );

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // Points the rider tapped to route through, in the order tapped.
            'via' => ['nullable', 'array', 'max:4'],
            'via.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'via.*.longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $destination = $pickupRequest->destinationCoordinates();

        if (! $destination) {
            return response()->json([
                'routes' => [],
                'reason' => 'no_destination_pin',
            ]);
        }

        $via = collect($validated['via'] ?? [])
            ->map(fn ($point) => [(float) $point['latitude'], (float) $point['longitude']])
            ->all();

        $routes = Routing::route(
            [(float) $validated['latitude'], (float) $validated['longitude']],
            $destination,
            $via
        );

        return response()->json([
            'routes' => $routes,
            'destination' => [
                'latitude' => $destination[0],
                'longitude' => $destination[1],
            ],
            'leg' => $pickupRequest->activeLeg(),
            'reroute_after_metres' => (int) config('maps.routing.reroute_after_metres'),
        ]);
    }

    /**
     * Rider goes off duty. Clears the live flag so watching maps stop showing
     * them rather than freezing their last position on screen.
     */
    public function stopSharing(Request $request)
    {
        $request->user()->forceFill(['is_sharing_location' => false])->save();

        return response()->json(['sharing' => false]);
    }

    /**
     * Rider advances a job: collected from the customer, or delivered back.
     */
    public function updateStatus(Request $request, PickupRequest $pickupRequest)
    {
        $this->authorizeRiderJob($request, $pickupRequest);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['picked_up', 'completed'])],
            // Collection is where a load stops being "the customer's bag" and
            // becomes a numbered item the branch can match back to one booking.
            'tag_code' => ['required_if:status,picked_up', 'nullable', 'string', 'max:24'],
            'collected_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'collected_payment_method' => ['nullable', Rule::in(['cash', 'gcash', 'unpaid'])],
            // Sent by the phone so a resend from a dead spot can be recognised
            // as the same tap rather than a second one.
            'client_token' => ['nullable', 'string', 'max:40'],
        ], [
            'tag_code.required_if' => 'Write the tag number on the bag and enter it here, so this load cannot be mixed up with another.',
        ]);

        $target = $validated['status'];
        $token = $validated['client_token'] ?? null;

        // This exact tap already landed; the reply just never made it back to
        // the phone. Answer as though it had, provided the run is still where
        // that tap put it.
        if ($this->isRepeatedAction($request, $pickupRequest, $token)
            && $pickupRequest->status === $target) {
            return $this->riderStatusResponse($request, $pickupRequest, $target, $pickupRequest->tag_code);
        }

        // A rider can only move a job forward, and only from the stage it is
        // actually in. Anything else is a stale button on a phone that has been
        // sitting in a pocket.
        $allowed = match ($pickupRequest->status) {
            'confirmed' => ['picked_up'],
            'picked_up' => ['completed'],
            default => [],
        };

        if (! in_array($target, $allowed, true)) {
            return $this->riderStatusError($request, 'That job has already moved on. Pull to refresh.');
        }

        $tagCode = null;

        if ($target === 'picked_up') {
            $tagCode = strtoupper(trim((string) $validated['tag_code']));

            // The whole point of the tag is that it belongs to one load. If it
            // is already on another bag in play, the rider has to use a
            // different one rather than create the mix-up we are preventing.
            $inUse = PickupRequest::query()
                ->where('tag_code', $tagCode)
                ->whereKeyNot($pickupRequest->getKey())
                ->whereIn('status', ['confirmed', 'picked_up'])
                ->exists();

            if ($inUse) {
                return $this->riderStatusError($request, 'Tag '.$tagCode.' is already on another load. Use a different tag number.');
            }
        }

        DB::transaction(function () use ($request, $pickupRequest, $target, $tagCode, $validated, $token) {
            $pickupRequest->update([
                'status' => $target,
                'rider_action_token' => $this->boundActionToken($request, $token),
                'tag_code' => $tagCode ?: $pickupRequest->tag_code,
                'picked_up_at' => $target === 'picked_up' ? now() : $pickupRequest->picked_up_at,
                'delivered_at' => $target === 'completed' ? now() : null,
                // Payment is taken at the door, so what the rider took is
                // recorded with the collection rather than after the fact.
                'collected_amount' => $target === 'picked_up'
                    ? ($validated['collected_amount'] ?? null)
                    : $pickupRequest->collected_amount,
                'collected_payment_method' => $target === 'picked_up'
                    ? ($validated['collected_payment_method'] ?? null)
                    : $pickupRequest->collected_payment_method,
            ]);

            Activity::log($request, 'pickup_request_rider_status', $pickupRequest, [
                'reference_no' => $pickupRequest->reference_no,
                'status' => $target,
                'tag_code' => $tagCode,
                'collected_amount' => $validated['collected_amount'] ?? null,
                'rider_id' => $request->user()->id,
            ], $pickupRequest->branch_id);
        });

        return $this->riderStatusResponse($request, $pickupRequest, $target, $tagCode);
    }

    /**
     * One reply for both callers: the phone posting in the background wants
     * JSON it can tick off its queue with, a plain form wants the run list.
     */
    private function riderStatusResponse(Request $request, PickupRequest $pickupRequest, string $target, ?string $tagCode)
    {
        $message = $target === 'picked_up'
            ? 'Collected under tag '.($tagCode ?: $pickupRequest->tag_code).'. Bring it to the branch.'
            : 'Delivered. Nice work.';

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'status' => $pickupRequest->fresh()->status,
                'message' => $message,
                'redirect' => route('rider.index'),
            ]);
        }

        return redirect()->route('rider.index')->with('success', $message);
    }

    /**
     * A refusal the phone must not retry: the job moved on, or the tag is
     * taken. Either way resending will never start working.
     */
    private function riderStatusError(Request $request, string $message)
    {
        return $this->riderActionRefused($request, $message);
    }

    /**
     * A refusal the phone must not retry: the run moved on, somebody else
     * took it, the tag is in use. Resending will never start working.
     */
    private function riderActionRefused(Request $request, string $message, ?string $redirect = null)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => $message], 422);
        }

        return ($redirect ? redirect($redirect) : back())->with('error', $message);
    }

    /** For the actions that take nothing else, just the phone's tap token. */
    private function validatedClientToken(Request $request): ?string
    {
        return $request->validate([
            'client_token' => ['nullable', 'string', 'max:40'],
        ])['client_token'] ?? null;
    }

    /**
     * Straight-line distance the rider still has to cover, for the console
     * header. Not a routed distance — it only has to be indicative.
     */
    public static function remainingKm(PickupRequest $job, $rider): ?float
    {
        $destination = $job->destinationCoordinates();

        if (! $destination || $rider->last_latitude === null) {
            return null;
        }

        return round(Geocoder::distanceKm(
            (float) $rider->last_latitude,
            (float) $rider->last_longitude,
            $destination[0],
            $destination[1]
        ), 1);
    }

    private function authorizeRiderJob(Request $request, PickupRequest $pickupRequest): void
    {
        abort_unless(
            (int) $pickupRequest->rider_id === (int) $request->user()->id,
            403,
            // Shared by reads (routing) and writes, so it says only what is true of both.
            'This run is now with another rider.'
        );
    }

    /**
     * The token as stored: prefixed with who sent it.
     *
     * Claiming and handing back change who owns the run, so a resend of either
     * has to be recognised before the ownership check or it would be refused
     * as somebody else's job. Binding the token to the rider keeps that early
     * check from matching a token another account happens to send.
     */
    private function boundActionToken(Request $request, ?string $token): ?string
    {
        return filled($token) ? $request->user()->id.':'.$token : null;
    }

    /** This rider already sent this exact tap, and it was applied. */
    private function isRepeatedAction(Request $request, PickupRequest $pickupRequest, ?string $token): bool
    {
        $bound = $this->boundActionToken($request, $token);

        return $bound !== null
            && $pickupRequest->rider_action_token !== null
            && hash_equals($pickupRequest->rider_action_token, $bound);
    }

    /**
     * One success reply for every rider action: JSON the phone can tick off
     * its outbox with, or the usual redirect and flash for a plain form.
     */
    private function riderActionDone(Request $request, string $message, string $redirect)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message, 'redirect' => $redirect]);
        }

        return redirect($redirect)->with('success', $message);
    }

    /**
     * Every run the rider has a reason to drive to, on one map.
     *
     * The list the console shows is grouped by what to do next; this is the
     * same work seen from the road, so each entry carries the place it is at
     * and how urgent it is rather than which list it came from.
     */
    public function map(Request $request)
    {
        return view('rider.map', [
            'rider' => $request->user(),
            'jobs' => $this->mapJobs($request->user()),
        ]);
    }

    /** The same set as JSON, so the map can refresh without a reload. */
    public function mapJobsFeed(Request $request)
    {
        return response()->json([
            'jobs' => $this->mapJobs($request->user()),
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Stages, in the order a rider meets them:
     *
     *   available  someone must take this one; still at the customer
     *   pickup     mine, not collected yet; go to the pickup address
     *   in_cycle   collected and in the branch; nothing to drive to yet
     *   delivery   washed and going back; go to the delivery address
     *
     * A booking with no pin is still returned, without coordinates, so the
     * screen can say so instead of quietly dropping a job off the map.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapJobs($rider): array
    {
        $mine = PickupRequest::query()
            ->where('rider_id', $rider->id)
            ->whereIn('status', ['confirmed', 'picked_up']);

        $claimable = PickupRequest::query()
            ->whereNull('rider_id')
            ->where('branch_id', $rider->branch_id)
            ->whereIn('status', ['pending', 'confirmed']);

        return $mine->union($claimable)
            ->with(['customer:id,name,phone', 'branch:id,name', 'jobOrder:id,job_order_number,status'])
            ->get()
            ->map(function (PickupRequest $job) use ($rider) {
                $isMine = (int) $job->rider_id === (int) $rider->id;
                $stage = $this->mapStageFor($job, $isMine);

                // Where the rider would actually drive for this stage. A run in
                // the branch is pinned at the address it is going back to, so
                // it reads as "later today, over there" rather than vanishing.
                $coordinates = in_array($stage, [RiderMapStages::DELIVERY, RiderMapStages::IN_CYCLE], true)
                    ? $this->deliveryCoordinates($job)
                    : $this->pickupCoordinates($job);

                return [
                    'id' => $job->id,
                    'reference' => $job->reference_no,
                    'stage' => $stage,
                    'is_mine' => $isMine,
                    'is_rush' => (bool) $job->is_rush,
                    'customer' => $job->customer?->name ?: $job->contact_name,
                    'phone' => $job->contact_phone,
                    'address' => in_array($stage, [RiderMapStages::DELIVERY, RiderMapStages::IN_CYCLE], true)
                        ? ($job->delivery_address ?: $job->pickup_address)
                        : $job->pickup_address,
                    'landmark' => $job->pickup_landmark,
                    'when' => $this->mapWhenLabel($job, $stage),
                    'job_order_status' => $job->jobOrder?->status,
                    'job_order_number' => $job->jobOrder?->job_order_number,
                    'holding_note' => $this->holdingNote($job, $stage),
                    'latitude' => $coordinates[0] ?? null,
                    'longitude' => $coordinates[1] ?? null,
                    'url' => route('rider.jobs.show', $job),
                    'route_url' => route('rider.jobs.route', $job),
                ];
            })
            ->sortBy(fn (array $job) => array_search($job['stage'], RiderMapStages::keys(), true))
            ->values()
            ->all();
    }

    private function mapStageFor(PickupRequest $job, bool $isMine): string
    {
        if (! $isMine) {
            return RiderMapStages::AVAILABLE;
        }

        if ($job->status === 'confirmed') {
            return RiderMapStages::PICKUP;
        }

        // Collected. It is only a delivery once the branch has finished it;
        // until then it is on a machine and there is nowhere to drive.
        $ready = in_array($job->jobOrder?->status, ['ready_for_delivery', 'ready_for_pickup', 'completed'], true);

        return $ready && $job->wantsDelivery() ? RiderMapStages::DELIVERY : RiderMapStages::IN_CYCLE;
    }

    /**
     * Why a collected run is not a delivery yet.
     *
     * The stage depends on the branch moving the job order along, so when it
     * has not, the rider is told what is actually holding it rather than being
     * left with a grey pin that never changes. A run sitting for days is
     * called out: that is the case nobody notices on their own.
     */
    private function holdingNote(PickupRequest $job, string $stage): ?string
    {
        if ($stage !== RiderMapStages::IN_CYCLE) {
            return null;
        }

        $days = $job->picked_up_at?->diffInDays(now()) ?? 0;
        $waited = $days >= 2 ? ' Collected '.$days.' days ago, worth asking about.' : '';

        if (! $job->jobOrder) {
            return 'The branch has not opened a job order for this yet.'.$waited;
        }

        if (! $job->wantsDelivery()) {
            return 'The customer is claiming this at the branch, so there is no delivery run.';
        }

        return 'Still '.str_replace('_', ' ', $job->jobOrder->status).' at the branch.'.$waited;
    }

    private function mapWhenLabel(PickupRequest $job, string $stage): ?string
    {
        $date = in_array($stage, [RiderMapStages::DELIVERY, RiderMapStages::IN_CYCLE], true)
            ? $job->delivery_date
            : $job->pickup_date;

        if (! $date) {
            return null;
        }

        return match (true) {
            $date->isToday() => 'Today',
            $date->isTomorrow() => 'Tomorrow',
            $date->isPast() => 'Overdue',
            default => $date->format('M j'),
        };
    }

    /** @return array{0: float, 1: float}|null */
    private function pickupCoordinates(PickupRequest $job): ?array
    {
        return $job->pickup_latitude !== null
            ? [(float) $job->pickup_latitude, (float) $job->pickup_longitude]
            : null;
    }

    /** @return array{0: float, 1: float}|null */
    private function deliveryCoordinates(PickupRequest $job): ?array
    {
        if ($job->delivery_latitude !== null) {
            return [(float) $job->delivery_latitude, (float) $job->delivery_longitude];
        }

        return $this->pickupCoordinates($job);
    }

    /** Free to take: nobody on it, still open, and at this rider's own branch. */
    private function isClaimableBy(PickupRequest $pickupRequest, $rider): bool
    {
        return $pickupRequest->rider_id === null
            && $pickupRequest->isOpen()
            && (int) $pickupRequest->branch_id === (int) $rider->branch_id;
    }
}
