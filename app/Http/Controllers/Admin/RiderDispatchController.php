<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\JobOrder;
use App\Models\PickupRequest;
use App\Models\User;
use App\Support\Activity;
use App\Support\Geocoder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Dispatch view: every rider on the map, every open run in a list, and the
 * assignment control that connects the two.
 */
class RiderDispatchController extends Controller
{
    private const ORDER_STATUSES = ['pending', 'washing', 'drying', 'folding', 'ready_for_pickup', 'ready_for_delivery', 'completed', 'cancelled'];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', ...self::ORDER_STATUSES])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $selectedStatus = $filters['status'] ?? 'ready_for_delivery';
        $search = trim($filters['search'] ?? '');
        $user = $request->user();
        $canChooseBranch = $user->canManageAllBranches();

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get(['id', 'name', 'address']);

        $selectedBranchId = $canChooseBranch
            ? ($request->integer('branch_id') ?: null)
            : $user->branch_id;

        $riders = User::query()
            ->riders()
            ->where('status', 'active')
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))
            ->withCount(['assignedPickupRequests as open_jobs_count' => fn ($query) => $query
                ->whereIn('status', ['confirmed', 'picked_up'])])
            ->orderBy('name')
            ->get();

        $activeRuns = PickupRequest::query()
            ->whereNotNull('rider_id')
            ->whereIn('status', ['confirmed', 'picked_up'])
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))
            ->with(['rider:id,name', 'customer:id,name'])
            ->orderBy('pickup_date')
            ->get();

        // Counter-created orders need delivery too, even without a pickup booking.
        $ordersQuery = JobOrder::query()
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))
            ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches
                ->where('job_order_number', 'like', '%'.$search.'%')
                ->orWhereHas('customer', fn ($customer) => $customer
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('address', 'like', '%'.$search.'%'))
                ->orWhereHas('pickupRequest', fn ($run) => $run->where('delivery_address', 'like', '%'.$search.'%'))));
        $statusCounts = (clone $ordersQuery)->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')->pluck('aggregate', 'status');
        $readyOrders = $ordersQuery
            ->when($selectedStatus !== 'all', fn ($query) => $query->where('status', $selectedStatus))
            ->when($selectedStatus === 'ready_for_delivery', fn ($query) => $query->whereNull('released_at'))
            ->with(['customer', 'branch:id,name', 'pickupRequest.rider:id,name'])
            ->orderByDesc('is_rush')
            ->orderBy('created_at')
            ->get();

        return view('admin.riders.index', [
            'branches' => $branches,
            'canChooseBranch' => $canChooseBranch,
            'selectedBranchId' => $selectedBranchId,
            'riders' => $riders,
            'activeRuns' => $activeRuns,
            'readyOrders' => $readyOrders,
            'selectedStatus' => $selectedStatus,
            'orderStatuses' => self::ORDER_STATUSES,
            'statusCounts' => $statusCounts,
            'search' => $search,
        ]);
    }

    public function assignDelivery(Request $request, JobOrder $jobOrder)
    {
        abort_unless($request->user()->canManageAllBranches()
            || (int) $jobOrder->branch_id === (int) $request->user()->branch_id, 403);

        $validated = $request->validate(['rider_id' => ['required', 'integer', 'exists:users,id']]);
        $rider = User::query()->riders()->where('status', 'active')
            ->where('branch_id', $jobOrder->branch_id)->find($validated['rider_id']);
        if (! $rider) {
            throw ValidationException::withMessages(['rider_id' => 'Choose an active rider from the order branch.']);
        }

        DB::transaction(function () use ($request, $jobOrder, $rider) {
            // Serialize assignment so a retry cannot create a second delivery run.
            $order = JobOrder::query()->lockForUpdate()->findOrFail($jobOrder->id);
            if ($order->status !== 'ready_for_delivery' || $order->released_at) {
                throw ValidationException::withMessages(['rider_id' => 'This order is no longer ready for delivery.']);
            }

            $run = $order->pickupRequest()->lockForUpdate()->first();
            if ($run && in_array($run->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['rider_id' => 'The linked run is already closed.']);
            }

            if (! $run) {
                $customer = $order->customer;
                $run = $order->pickupRequest()->create([
                    'reference_no' => 'DL-JO-'.$order->id,
                    'branch_id' => $order->branch_id,
                    'customer_id' => $order->customer_id,
                    'contact_name' => $customer?->name ?? '',
                    'contact_phone' => $customer?->phone ?? '',
                    'contact_email' => $customer?->email,
                    'pickup_address' => $customer?->address ?? '',
                    'pickup_date' => today(),
                    'delivery_address' => $customer?->address,
                    'delivery_latitude' => $customer?->latitude,
                    'delivery_longitude' => $customer?->longitude,
                    'is_rush' => $order->is_rush,
                    'estimated_total' => $order->total,
                    'status' => 'picked_up',
                    'delivery_preference' => 'deliver',
                    'handled_by' => $request->user()->id,
                ]);
            }

            // The laundry is already at the counter; never send it through pickup again.
            $run->update(['status' => 'picked_up', 'delivery_preference' => 'deliver']);
            $run->assignRider($rider->id);
            Activity::log($request, 'pickup_request_rider_assigned', $run, [
                'reference_no' => $run->reference_no,
                'job_order_id' => $order->id,
                'rider_id' => $rider->id,
            ], $order->branch_id);
        });

        return back()->with('success', 'Delivery rider assigned to '.$jobOrder->job_order_number.'.');
    }

    /**
     * Live positions for the dispatch map, polled on an interval.
     */
    public function locations(Request $request)
    {
        $user = $request->user();
        $branchId = $user->canManageAllBranches()
            ? ($request->integer('branch_id') ?: null)
            : $user->branch_id;

        $riders = User::query()
            ->riders()
            ->where('status', 'active')
            ->where('is_sharing_location', true)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->get(['id', 'name', 'last_latitude', 'last_longitude', 'last_location_heading', 'last_location_at']);

        return response()->json([
            'riders' => $riders
                ->filter(fn (User $rider) => $rider->hasLiveLocation())
                ->map(fn (User $rider) => [
                    'id' => $rider->id,
                    'name' => $rider->name,
                    'latitude' => (float) $rider->last_latitude,
                    'longitude' => (float) $rider->last_longitude,
                    'heading' => $rider->last_location_heading,
                    'seconds_ago' => $rider->last_location_at ? (int) $rider->last_location_at->diffInSeconds(now()) : null,
                ])
                ->values(),
            'poll_interval' => (int) config('maps.tracking.poll_interval_seconds'),
        ]);
    }

    /**
     * Put a rider on a booking, or take them off it.
     */
    public function assign(Request $request, PickupRequest $pickupRequest)
    {
        $this->authorizeBranch($request, $pickupRequest);

        $validated = $request->validate([
            'rider_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (! $pickupRequest->isAssignable() && $pickupRequest->status !== 'picked_up') {
            return back()->with('error', 'Only open bookings can be assigned to a rider.');
        }

        $riderId = $validated['rider_id'] ?? null;

        if ($riderId !== null) {
            $rider = User::query()->riders()->whereKey($riderId)->first();

            if (! $rider) {
                return back()->with('error', 'That user is not a rider.');
            }

            // A branch-scoped dispatcher must not reach across to another
            // branch's riders.
            if (! $request->user()->canManageAllBranches()
                && (int) $rider->branch_id !== (int) $request->user()->branch_id) {
                return back()->with('error', 'That rider belongs to another branch.');
            }
        }

        $pickupRequest->assignRider($riderId);

        Activity::log($request, $riderId ? 'pickup_request_rider_assigned' : 'pickup_request_rider_unassigned', $pickupRequest, [
            'reference_no' => $pickupRequest->reference_no,
            'rider_id' => $riderId,
        ], $pickupRequest->branch_id);

        return back()->with('success', $riderId
            ? 'Rider assigned to '.$pickupRequest->reference_no.'.'
            : 'Rider removed from '.$pickupRequest->reference_no.'.');
    }

    /**
     * Riders a dispatcher may pick from, nearest to the pickup pin first so the
     * obvious choice is at the top of the list.
     */
    public static function assignableRiders(Request $request, ?PickupRequest $pickupRequest = null)
    {
        $user = $request->user();

        $riders = User::query()
            ->riders()
            ->where('status', 'active')
            ->when(! $user->canManageAllBranches(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->get(['id', 'name', 'branch_id', 'last_latitude', 'last_longitude', 'last_location_at']);

        if (! $pickupRequest || $pickupRequest->pickup_latitude === null) {
            return $riders->sortBy('name')->values();
        }

        return $riders
            ->sortBy(fn (User $rider) => $rider->hasLiveLocation()
                ? Geocoder::distanceKm(
                    (float) $rider->last_latitude,
                    (float) $rider->last_longitude,
                    (float) $pickupRequest->pickup_latitude,
                    (float) $pickupRequest->pickup_longitude
                )
                // Riders with no live fix sort to the bottom, still selectable.
                : PHP_INT_MAX)
            ->values();
    }

    private function authorizeBranch(Request $request, PickupRequest $pickupRequest): void
    {
        if ($request->user()->canManageAllBranches()) {
            return;
        }

        abort_unless((int) $pickupRequest->branch_id === (int) $request->user()->branch_id, 403);
    }
}
