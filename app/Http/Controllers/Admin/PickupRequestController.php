<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PickupRequest;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PickupRequestController extends Controller
{
    /** Tabs on the list. "open" is everything someone still has to act on. */
    public const VIEWS = [
        'open' => ['pending', 'confirmed', 'picked_up'],
        'pending' => ['pending'],
        'confirmed' => ['confirmed'],
        'picked_up' => ['picked_up'],
        'completed' => ['completed'],
        'cancelled' => ['cancelled'],
        'all' => null,
    ];

    private const OPEN_STATUSES = ['pending', 'confirmed', 'picked_up'];

    private const PER_PAGE = [15, 25, 50, 100];

    public function index(Request $request)
    {
        $user = $request->user();

        // A search (the new-booking alert links here with the reference) looks
        // across every status unless a tab was picked on purpose.
        $view = $request->query('view');
        if (! array_key_exists((string) $view, self::VIEWS)) {
            $view = $request->filled('search') ? 'all' : 'open';
        }
        $when = in_array($request->query('when'), ['overdue', 'today', 'tomorrow', 'week'], true) ? $request->query('when') : null;
        $dateFrom = $this->parseDate($request->query('date_from'));
        $dateTo = $this->parseDate($request->query('date_to'));
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        $perPage = in_array($request->integer('per_page'), self::PER_PAGE, true) ? $request->integer('per_page') : 15;

        $scoped = PickupRequest::query()
            ->when(! $user->canManageAllBranches(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->when($request->filled('branch_id') && $user->canManageAllBranches(),
                fn ($query) => $query->where('branch_id', $request->integer('branch_id')));

        // Every filter except the tab, so each tab can show what it would hold.
        $filtered = (clone $scoped)
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim($request->search);
                $query->where(function ($inner) use ($search) {
                    $inner->where('reference_no', 'like', "%{$search}%")
                        ->orWhere('tag_code', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('contact_phone', 'like', "%{$search}%")
                        ->orWhere('pickup_address', 'like', "%{$search}%");
                });
            })
            ->when($request->query('rider') === 'unassigned', fn ($query) => $query->whereNull('rider_id'))
            ->when(ctype_digit((string) $request->query('rider')), fn ($query) => $query->where('rider_id', (int) $request->query('rider')))
            ->when($when === 'overdue', fn ($query) => $query
                ->whereIn('status', ['pending', 'confirmed'])
                ->whereDate('pickup_date', '<', today()))
            ->when($when === 'today', fn ($query) => $query->whereDate('pickup_date', today()))
            ->when($when === 'tomorrow', fn ($query) => $query->whereDate('pickup_date', today()->addDay()))
            ->when($when === 'week', fn ($query) => $query
                ->whereDate('pickup_date', '>=', now()->startOfWeek())
                ->whereDate('pickup_date', '<=', now()->endOfWeek()))
            ->when(! $when && $dateFrom, fn ($query) => $query->whereDate('pickup_date', '>=', $dateFrom))
            ->when(! $when && $dateTo, fn ($query) => $query->whereDate('pickup_date', '<=', $dateTo));

        $byStatus = (clone $filtered)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $viewCounts = collect(self::VIEWS)->map(fn ($statuses) => $statuses === null
            ? (int) $byStatus->sum()
            : (int) collect($statuses)->sum(fn ($status) => $byStatus[$status] ?? 0));

        // Late pickups across the branch, whatever is filtered: the banner that
        // says someone was missed.
        $overdueCount = (clone $scoped)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereDate('pickup_date', '<', today())
            ->count();

        $statuses = self::VIEWS[$view];
        $closedView = in_array($view, ['completed', 'cancelled'], true);

        $requests = (clone $filtered)
            ->with(['items', 'customer', 'branch', 'jobOrder', 'handler', 'rider:id,name'])
            ->when($statuses !== null, fn ($query) => $query->whereIn('status', $statuses))
            // Open bookings first, then by the day the van has to show up.
            // Closed tabs read as history: newest first.
            ->when(! $closedView, fn ($query) => $query
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'confirmed' THEN 1 WHEN 'picked_up' THEN 2 ELSE 3 END")
                ->orderBy('pickup_date')
                ->orderBy('id'))
            ->when($closedView, fn ($query) => $query->orderByDesc('pickup_date')->orderByDesc('id'))
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.pickup-requests.index', [
            'requests' => $requests,
            'view' => $view,
            'viewCounts' => $viewCounts,
            'overdueCount' => $overdueCount,
            'when' => $when,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE,
            'branches' => $user->canManageAllBranches()
                ? Branch::where('is_active', true)->orderBy('name')->get()
                : collect(),
            // Riders the dispatcher on this screen is allowed to send out.
            'riders' => User::query()
                ->riders()
                ->where('status', 'active')
                ->when(! $user->canManageAllBranches(), fn ($query) => $query->where('branch_id', $user->branch_id))
                ->orderBy('name')
                ->get(['id', 'name', 'branch_id']),
        ]);
    }

    /**
     * One action over many ticked bookings. Each booking still goes through the
     * same rules as its own buttons; the ones an action does not apply to are
     * skipped and counted, never forced.
     */
    public function bulk(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['confirm', 'assign', 'complete', 'cancel'])],
            'rider_id' => ['nullable', 'required_if:action,assign', 'integer'],
        ]);

        $riderId = null;
        if ($validated['action'] === 'assign') {
            $rider = User::query()->riders()->where('status', 'active')->whereKey($validated['rider_id'])->first();

            if (! $rider || (! $user->canManageAllBranches() && (int) $rider->branch_id !== (int) $user->branch_id)) {
                return back()->with('error', 'Pick a rider from your branch.');
            }

            $riderId = $rider->id;
        }

        $bookings = PickupRequest::query()
            ->whereKey($validated['ids'])
            ->when(! $user->canManageAllBranches(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->get();

        $done = 0;
        foreach ($bookings as $booking) {
            $applies = match ($validated['action']) {
                'confirm' => $booking->status === 'pending',
                'assign' => $booking->isAssignable() || $booking->status === 'picked_up',
                'complete' => $booking->status === 'picked_up',
                'cancel' => in_array($booking->status, self::OPEN_STATUSES, true),
            };

            if (! $applies) {
                continue;
            }

            if ($validated['action'] === 'assign') {
                $booking->assignRider($riderId);
                Activity::log($request, 'pickup_request_rider_assigned', $booking, [
                    'reference_no' => $booking->reference_no,
                    'rider_id' => $riderId,
                    'bulk' => true,
                ], $booking->branch_id);
            } else {
                $status = ['confirm' => 'confirmed', 'complete' => 'completed', 'cancel' => 'cancelled'][$validated['action']];
                $this->applyStatus($request, $booking, $status, $status === 'cancelled' ? 'Cancelled by staff' : null, true);
            }

            $done++;
        }

        $skipped = count($validated['ids']) - $done;
        $verb = ['confirm' => 'confirmed', 'assign' => 'assigned to the rider', 'complete' => 'marked completed', 'cancel' => 'cancelled'][$validated['action']];
        $message = $done.' booking'.($done === 1 ? '' : 's').' '.$verb.'.';
        if ($skipped > 0) {
            $message .= ' '.$skipped.' skipped (not in a status this action applies to).';
        }

        return back()->with($done > 0 ? 'success' : 'error', $message);
    }

    /**
     * What the staff screens poll for the new-booking alert: bookings newer than
     * the last one this browser has seen, and how many are still waiting.
     * Scoped like the list itself, so a branch only hears about its own.
     */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $after = max(0, $request->integer('after'));

        $base = PickupRequest::query()
            ->when(! $user->canManageAllBranches(), fn ($query) => $query->where('branch_id', $user->branch_id));

        $bookings = $after > 0
            ? (clone $base)
                ->with('branch:id,name')
                ->where('id', '>', $after)
                // Cancelled before anyone saw it: nothing to act on.
                ->where('status', '!=', 'cancelled')
                ->orderBy('id')
                ->limit(10)
                ->get()
            : collect();

        $waiting = (clone $base)
            ->with('branch:id,name')
            ->where('status', 'pending')
            ->latest('id')
            ->limit(10)
            ->get();

        return response()->json([
            'latest_id' => (int) (clone $base)->max('id'),
            'next_after' => (int) ($bookings->last()?->id ?? (clone $base)->max('id')),
            'pending' => (clone $base)->where('status', 'pending')->count(),
            'bookings' => $bookings->map(fn (PickupRequest $booking) => [
                'id' => $booking->id,
                'reference_no' => $booking->reference_no,
                'contact_name' => $booking->contact_name,
                'branch' => $user->canManageAllBranches() ? $booking->branch?->name : null,
                'pickup' => $booking->pickup_date?->format('M j').' · '.$booking->pickupSlotLabel(),
                'url' => route('admin.pickup-requests.index', ['search' => $booking->reference_no]),
            ])->values(),
            'waiting' => $waiting->map(fn (PickupRequest $booking) => [
                'id' => $booking->id,
                'reference_no' => $booking->reference_no,
                'contact_name' => $booking->contact_name,
                'branch' => $user->canManageAllBranches() ? $booking->branch?->name : null,
                'pickup' => $booking->pickup_date?->format('M j').' · '.$booking->pickupSlotLabel(),
                'url' => route('admin.pickup-requests.index', ['search' => $booking->reference_no]),
            ])->values(),
        ])->header('Cache-Control', 'no-store');
    }

    public function updateStatus(Request $request, PickupRequest $pickupRequest)
    {
        $this->authorizeBranch($request, $pickupRequest);

        $validated = $request->validate([
            'status' => ['required', Rule::in(PickupRequest::STATUSES)],
            'cancellation_reason' => ['nullable', 'string', 'max:200'],
        ]);

        $this->applyStatus($request, $pickupRequest, $validated['status'], $validated['cancellation_reason'] ?? null);

        return back()->with('success', 'Booking '.$pickupRequest->reference_no.' is now '.str_replace('_', ' ', $validated['status']).'.');
    }

    /**
     * Hands the booking over to the counter. We do not invent line items here:
     * the load still has to be weighed, so we prefill the job order form with
     * the customer and delivery flow and let staff price it as usual.
     */
    public function convert(Request $request, PickupRequest $pickupRequest)
    {
        $this->authorizeBranch($request, $pickupRequest);

        if ($pickupRequest->job_order_id) {
            return redirect()->route('admin.job-orders.show', $pickupRequest->job_order_id);
        }

        if (in_array($pickupRequest->status, ['cancelled', 'completed'], true)) {
            return back()->with('error', 'This booking is already closed.');
        }

        return redirect()->route('admin.job-orders.create', [
            // Pass the branch too: the create screen only prefills a customer
            // that belongs to the branch it is scoped to.
            'branch_id' => $pickupRequest->branch_id,
            'customer_id' => $pickupRequest->customer_id,
            'pickup_request_id' => $pickupRequest->id,
            'transaction_type' => $pickupRequest->wantsDelivery() ? 'delivery' : 'walk_in',
            'is_rush' => $pickupRequest->is_rush ? 1 : 0,
        ]);
    }

    private function applyStatus(Request $request, PickupRequest $pickupRequest, string $status, ?string $reason, bool $bulk = false): void
    {
        $pickupRequest->update([
            'status' => $status,
            'handled_by' => $request->user()->id,
            'confirmed_at' => $status === 'confirmed'
                ? ($pickupRequest->confirmed_at ?: now())
                : $pickupRequest->confirmed_at,
            'cancelled_at' => $status === 'cancelled' ? now() : null,
            'cancellation_reason' => $status === 'cancelled'
                ? ($reason ?? 'Cancelled by staff')
                : null,
        ]);

        Activity::log($request, 'pickup_request_status_updated', $pickupRequest, array_filter([
            'reference_no' => $pickupRequest->reference_no,
            'status' => $status,
            'bulk' => $bulk ?: null,
        ]));
    }

    private function parseDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function authorizeBranch(Request $request, PickupRequest $pickupRequest): void
    {
        $user = $request->user();

        abort_unless(
            $user->canManageAllBranches() || (int) $user->branch_id === (int) $pickupRequest->branch_id,
            403
        );
    }
}
