<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\PickupRequest;
use App\Models\RiderLocationPing;
use App\Support\Activity;
use App\Support\Geocoder;
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
            ->with(['customer:id,name,phone', 'branch:id,name,address'])
            ->orderBy('pickup_date')
            ->orderBy('id')
            ->get();

        $toDeliver = PickupRequest::query()
            ->where('rider_id', $rider->id)
            ->where('status', 'picked_up')
            ->with(['customer:id,name,phone', 'branch:id,name,address', 'jobOrder:id,job_order_number,status'])
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
            ->with(['customer:id,name,phone', 'branch:id,name,address'])
            ->orderByDesc('is_rush')
            ->orderBy('pickup_date')
            ->orderBy('id')
            ->get();

        $completedToday = PickupRequest::query()
            ->where('rider_id', $rider->id)
            ->whereDate('delivered_at', today())
            ->count();

        return view('rider.index', [
            'rider' => $rider,
            'toCollect' => $toCollect,
            'toDeliver' => $toDeliver,
            'recent' => $recent,
            'available' => $available,
            'completedToday' => $completedToday,
        ]);
    }

    public function show(Request $request, PickupRequest $pickupRequest)
    {
        $rider = $request->user();
        $isMine = (int) $pickupRequest->rider_id === (int) $rider->id;

        // An unclaimed booking opens too, so a rider can look at where it is
        // before deciding to take it.
        abort_unless($isMine || $this->isClaimableBy($pickupRequest, $rider), 403);

        $pickupRequest->load(['customer:id,name,phone', 'branch:id,name,address']);

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

        abort_unless($this->isClaimableBy($pickupRequest, $rider), 403);

        $claimed = PickupRequest::query()
            ->whereKey($pickupRequest->getKey())
            ->whereNull('rider_id')
            ->whereIn('status', ['pending', 'confirmed'])
            ->update([
                'rider_id' => $rider->id,
                'assigned_at' => now(),
                'status' => 'confirmed',
                'confirmed_at' => $pickupRequest->confirmed_at ?: now(),
                'updated_at' => now(),
            ]);

        if (! $claimed) {
            return redirect()
                ->route('rider.index')
                ->with('error', 'Another rider got there first — that run is taken.');
        }

        Activity::log($request, 'pickup_request_rider_claimed', $pickupRequest, [
            'reference_no' => $pickupRequest->reference_no,
            'rider_id' => $rider->id,
        ], $pickupRequest->branch_id);

        return redirect()
            ->route('rider.jobs.show', $pickupRequest)
            ->with('success', 'Confirmed. '.$pickupRequest->reference_no.' is yours — directions are ready.');
    }

    /**
     * Hand a run back to the branch list. For a rider who cannot make it after
     * all: better than cancelling a booking the customer still wants.
     */
    public function release(Request $request, PickupRequest $pickupRequest)
    {
        $this->authorizeRiderJob($request, $pickupRequest);

        if ($pickupRequest->status !== 'confirmed') {
            return back()->with('error', 'Only a run you have not collected yet can be handed back.');
        }

        $pickupRequest->update(['rider_id' => null, 'assigned_at' => null]);

        Activity::log($request, 'pickup_request_rider_released', $pickupRequest, [
            'reference_no' => $pickupRequest->reference_no,
            'rider_id' => $request->user()->id,
        ], $pickupRequest->branch_id);

        return redirect()
            ->route('rider.index')
            ->with('success', $pickupRequest->reference_no.' is back in the list for another rider.');
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
        ], [
            'reason.required' => 'Please say what happened, so the branch can tell the customer.',
        ]);

        // Once the laundry is in the rider's hands it is the branch's to sort
        // out, not something to close from a phone.
        if (! $pickupRequest->isCancellable()) {
            return back()->with('error', 'That booking can no longer be cancelled here. Call the branch.');
        }

        $pickupRequest->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'Rider: '.$validated['reason'],
        ]);

        Activity::log($request, 'pickup_request_rider_cancelled', $pickupRequest, [
            'reference_no' => $pickupRequest->reference_no,
            'rider_id' => $request->user()->id,
            'reason' => $validated['reason'],
        ], $pickupRequest->branch_id);

        return redirect()
            ->route('rider.index')
            ->with('success', 'Booking '.$pickupRequest->reference_no.' cancelled. The branch can see why.');
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
        ], [
            'tag_code.required_if' => 'Write the tag number on the bag and enter it here, so this load cannot be mixed up with another.',
        ]);

        $target = $validated['status'];

        // A rider can only move a job forward, and only from the stage it is
        // actually in. Anything else is a stale button on a phone that has been
        // sitting in a pocket.
        $allowed = match ($pickupRequest->status) {
            'confirmed' => ['picked_up'],
            'picked_up' => ['completed'],
            default => [],
        };

        if (! in_array($target, $allowed, true)) {
            return back()->with('error', 'That job has already moved on. Pull to refresh.');
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
                return back()->with('error', 'Tag '.$tagCode.' is already on another load. Use a different tag number.');
            }
        }

        DB::transaction(function () use ($request, $pickupRequest, $target, $tagCode, $validated) {
            $pickupRequest->update([
                'status' => $target,
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

        $message = $target === 'picked_up'
            ? 'Collected under tag '.$tagCode.'. Bring it to the branch.'
            : 'Delivered. Nice work.';

        return redirect()->route('rider.index')->with('success', $message);
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
        abort_unless((int) $pickupRequest->rider_id === (int) $request->user()->id, 403);
    }

    /** Free to take: nobody on it, still open, and at this rider's own branch. */
    private function isClaimableBy(PickupRequest $pickupRequest, $rider): bool
    {
        return $pickupRequest->rider_id === null
            && $pickupRequest->isOpen()
            && (int) $pickupRequest->branch_id === (int) $rider->branch_id;
    }
}
