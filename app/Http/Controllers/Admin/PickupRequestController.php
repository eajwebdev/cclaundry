<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PickupRequest;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
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
            ->with(['items', 'customer', 'branch', 'jobOrder', 'handler', 'rider:id,name'])
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
