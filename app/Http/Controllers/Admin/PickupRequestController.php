<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PickupRequest;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PickupRequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $base = PickupRequest::query()
            ->when(! $user->canManageAllBranches(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->when($request->filled('branch_id') && $user->canManageAllBranches(),
                fn ($query) => $query->where('branch_id', $request->integer('branch_id')));

        $statusCounts = [
            'all' => (clone $base)->count(),
            'pending' => (clone $base)->where('status', 'pending')->count(),
            'confirmed' => (clone $base)->where('status', 'confirmed')->count(),
            'picked_up' => (clone $base)->where('status', 'picked_up')->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
        ];

        $requests = (clone $base)
            ->with(['customer', 'branch', 'jobOrder', 'handler'])
            ->when(in_array($request->status, PickupRequest::STATUSES, true),
                fn ($query) => $query->where('status', $request->status))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim($request->search);
                $query->where(function ($inner) use ($search) {
                    $inner->where('reference_no', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('contact_phone', 'like', "%{$search}%");
                });
            })
            // Open bookings first, then by the day the van has to show up.
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'confirmed' THEN 1 WHEN 'picked_up' THEN 2 ELSE 3 END")
            ->orderBy('pickup_date')
            ->paginate(15)
            ->withQueryString();

        return view('admin.pickup-requests.index', [
            'requests' => $requests,
            'statusCounts' => $statusCounts,
            'statuses' => PickupRequest::STATUSES,
            'branches' => $user->canManageAllBranches()
                ? Branch::where('is_active', true)->orderBy('name')->get()
                : collect(),
        ]);
    }

    public function updateStatus(Request $request, PickupRequest $pickupRequest)
    {
        $this->authorizeBranch($request, $pickupRequest);

        $validated = $request->validate([
            'status' => ['required', Rule::in(PickupRequest::STATUSES)],
            'cancellation_reason' => ['nullable', 'string', 'max:200'],
        ]);

        $pickupRequest->update([
            'status' => $validated['status'],
            'handled_by' => $request->user()->id,
            'confirmed_at' => $validated['status'] === 'confirmed'
                ? ($pickupRequest->confirmed_at ?: now())
                : $pickupRequest->confirmed_at,
            'cancelled_at' => $validated['status'] === 'cancelled' ? now() : null,
            'cancellation_reason' => $validated['status'] === 'cancelled'
                ? ($validated['cancellation_reason'] ?? 'Cancelled by staff')
                : null,
        ]);

        Activity::log($request, 'pickup_request_status_updated', $pickupRequest, [
            'reference_no' => $pickupRequest->reference_no,
            'status' => $validated['status'],
        ]);

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

    private function authorizeBranch(Request $request, PickupRequest $pickupRequest): void
    {
        $user = $request->user();

        abort_unless(
            $user->canManageAllBranches() || (int) $user->branch_id === (int) $pickupRequest->branch_id,
            403
        );
    }
}
