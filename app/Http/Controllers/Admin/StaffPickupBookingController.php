<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PickupRequest;
use App\Models\User;
use App\Support\Activity;
use App\Support\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StaffPickupBookingController extends Controller
{
    public function create(Request $request)
    {
        $branches = Branch::query()->where('is_active', true)
            ->when(! $request->user()->canManageAllBranches(), fn ($query) => $query->whereKey($request->user()->branch_id))
            ->orderBy('name')->get();
        $branch = $branches->firstWhere('id', (int) $request->old('branch_id', $request->integer('branch_id')))
            ?? $branches->first();
        abort_unless($branch, 403, 'No active branch is available.');

        return view('admin.pickup-requests.create', [
            'branches' => $branches,
            'branch' => $branch,
            'customers' => Customer::query()->where('branch_id', $branch->id)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'phone', 'address']),
            'riders' => User::query()->riders()->where('branch_id', $branch->id)->where('status', 'active')->orderBy('name')->get(),
            'slots' => Booking::slots($branch->id),
            'defaultDate' => Booking::slotsFor(today()->toDateString(), $branch->id) ? today()->toDateString() : today()->addDay()->toDateString(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canManageAllBranches()
            || $request->integer('branch_id') === (int) $request->user()->branch_id, 403);

        $branchId = $request->integer('branch_id');
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('branch_id', $branchId)->where('is_active', true)->whereNull('deleted_at')],
            'contact_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'contact_phone' => ['required_without:customer_id', 'nullable', 'string', 'max:30', function ($attribute, $value, $fail) {
                if (! preg_match('/^09\d{9}$/', Customer::normalizePhone($value))) {
                    $fail('Enter a mobile number we can reach, like 0917 123 4567.');
                }
            }],
            'pickup_address' => ['required', 'string', 'max:2000'],
            'pickup_landmark' => ['nullable', 'string', 'max:500'],
            'pickup_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'pickup_slot' => ['required', Rule::in(array_keys(Booking::slotsFor($request->input('pickup_date'), $branchId)))],
            'rider_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('branch_id', $branchId)->where('role', 'rider')->where('status', 'active')],
            'delivery_preference' => ['required', Rule::in(['deliver', 'branch_pickup'])],
            'delivery_address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $booking = DB::transaction(function () use ($request, $data, $branchId) {
            $customer = ! empty($data['customer_id'])
                ? Customer::findOrFail($data['customer_id'])
                : Customer::query()->where('branch_id', $branchId)->where('is_active', true)
                    ->matchingPhone($data['contact_phone'])->first();
            $customer ??= Customer::create([
                'branch_id' => $branchId,
                'name' => $data['contact_name'],
                'phone' => Customer::normalizePhone($data['contact_phone']),
                'address' => $data['pickup_address'],
                'is_active' => true,
            ]);

            // This is a dispatch booking. Weighing, pricing and the job order
            // happen once the rider brings the tagged laundry to the counter.
            $booking = PickupRequest::create([
                'reference_no' => PickupRequest::nextReference(),
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'contact_name' => empty($data['customer_id']) ? $data['contact_name'] : $customer->name,
                'contact_phone' => empty($data['customer_id']) ? Customer::normalizePhone($data['contact_phone']) : $customer->phone,
                'contact_email' => $customer->email,
                'pickup_address' => $data['pickup_address'],
                'pickup_landmark' => $data['pickup_landmark'] ?? null,
                'pickup_date' => $data['pickup_date'],
                'pickup_slot' => $data['pickup_slot'],
                'delivery_preference' => $data['delivery_preference'],
                'delivery_address' => $data['delivery_preference'] === 'deliver'
                    ? (($data['delivery_address'] ?? null) ?: $data['pickup_address']) : null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
                'handled_by' => $request->user()->id,
            ]);
            if (! empty($data['rider_id'])) {
                $booking->assignRider((int) $data['rider_id']);
            }
            Activity::log($request, 'pickup_request_created', $booking, [
                'reference_no' => $booking->reference_no,
                'rider_id' => $booking->rider_id,
                'source' => 'in_shop',
            ], $branchId);

            return $booking;
        });

        return redirect()->route('admin.job-orders.pickup-bookings.create', ['branch_id' => $branchId])
            ->with('success', 'Pickup '.$booking->reference_no.' booked. '.($booking->rider_id
                ? 'Assigned to '.$booking->rider->name.' and visible on their rider page.'
                : 'Waiting for rider assignment.').' Add the bag tag when the laundry is collected.');
    }
}
