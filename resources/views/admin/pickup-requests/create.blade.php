@extends('layouts.app')

@section('page_title', 'Book a Pickup')

@section('content')
@php
    $selectedCustomer = (string) old('customer_id', request('customer_id', ''));
    if (!$customers->contains('id', $selectedCustomer)) $selectedCustomer = '';
    $inputClass = 'mt-1 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950';
    $defaultSlot = array_key_first(\App\Support\Booking::slotsFor($defaultDate, $branch->id));
@endphp
<div class="mx-auto max-w-3xl space-y-5">
    <div>
        <a href="{{ route('admin.job-orders.create', ['branch_id' => $branch->id]) }}" class="text-sm font-semibold text-primary-deep dark:text-primary-soft">&larr; Back to job orders</a>
        <h1 class="mt-3 text-xl font-semibold">Book a pickup</h1>
        <p class="mt-1 text-sm text-muted">For phone, Facebook, or in-shop requests. Assign a rider now; the bag tag is added at collection. Weigh and price the laundry when it arrives.</p>
    </div>

    @if(session('success'))
        <p role="status" class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">{{ session('success') }}</p>
    @endif

    @if($errors->any())
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="GET" action="{{ route('admin.job-orders.pickup-bookings.create') }}" class="rounded-lg border border-border bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <label for="booking_branch" class="text-sm font-semibold">Pickup branch</label>
        <select id="booking_branch" name="branch_id" class="{{ $inputClass }}" onchange="this.form.requestSubmit()">
            @foreach($branches as $option)
                <option value="{{ $option->id }}" @selected($option->id === $branch->id)>{{ $option->name }}</option>
            @endforeach
        </select>
    </form>

    <form method="POST" action="{{ route('admin.job-orders.pickup-bookings.store') }}"
          x-data="{
              customers: @js($customers),
              customerId: @js($selectedCustomer),
              address: @js(old('pickup_address', $customers->firstWhere('id', $selectedCustomer)?->address ?? '')),
              delivery: @js(old('delivery_preference', 'deliver')),
              submitting: false,
              selectCustomer() {
                  const customer = this.customers.find(customer => String(customer.id) === String(this.customerId));
                  if (customer) this.address = customer.address || '';
              }
          }"
          @submit="submitting = true"
          class="space-y-5 rounded-lg border border-border bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        @csrf
        <input type="hidden" name="branch_id" value="{{ $branch->id }}">
        <div>
            <label for="customer_id" class="text-sm font-semibold">Customer</label>
            <select id="customer_id" name="customer_id" x-model="customerId" @change="selectCustomer()" class="{{ $inputClass }}">
                <option value="">New customer</option>
                @foreach($customers as $customer)
                    <option value="{{ $customer->id }}" @selected($selectedCustomer === (string) $customer->id)>{{ $customer->name }} · {{ $customer->phone }}</option>
                @endforeach
            </select>
        </div>
        <div x-show="!customerId" class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="contact_name" class="text-sm font-semibold">Customer name</label>
                <input id="contact_name" name="contact_name" value="{{ old('contact_name') }}" :required="!customerId" :disabled="!!customerId" maxlength="255" class="{{ $inputClass }}">
            </div>
            <div>
                <label for="contact_phone" class="text-sm font-semibold">Phone number</label>
                <input id="contact_phone" name="contact_phone" type="tel" value="{{ old('contact_phone') }}" :required="!customerId" :disabled="!!customerId" maxlength="30" class="{{ $inputClass }}">
            </div>
        </div>
        <div>
            <label for="pickup_address" class="text-sm font-semibold">Pickup address</label>
            <textarea id="pickup_address" name="pickup_address" x-model="address" required maxlength="2000" rows="2" class="{{ $inputClass }}">{{ old('pickup_address') }}</textarea>
        </div>
        <div>
            <label for="pickup_landmark" class="text-sm font-semibold">Landmark / directions <span class="font-normal text-muted">(optional)</span></label>
            <input id="pickup_landmark" name="pickup_landmark" value="{{ old('pickup_landmark') }}" maxlength="500" class="{{ $inputClass }}">
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="pickup_date" class="text-sm font-semibold">Pickup date</label>
                <input id="pickup_date" name="pickup_date" type="date" value="{{ old('pickup_date', $defaultDate) }}" min="{{ today()->toDateString() }}" required class="{{ $inputClass }}">
            </div>
            <div>
                <label for="pickup_slot" class="text-sm font-semibold">Pickup window</label>
                <select id="pickup_slot" name="pickup_slot" required class="{{ $inputClass }}">
                    @foreach($slots as $key => $label)
                        <option value="{{ $key }}" @selected(old('pickup_slot', $defaultSlot) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-muted">For today, choose a window that has not ended.</p>
            </div>
        </div>
        <div>
            <label for="rider_id" class="text-sm font-semibold">Assign rider</label>
            <select id="rider_id" name="rider_id" class="{{ $inputClass }}">
                <option value="">Assign later / let a rider claim it</option>
                @foreach($riders as $rider)
                    <option value="{{ $rider->id }}" @selected((string) old('rider_id') === (string) $rider->id)>{{ $rider->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-muted">Assigned pickups appear immediately on the rider's page.</p>
        </div>
        <div>
            <label for="delivery_preference" class="text-sm font-semibold">After washing</label>
            <select id="delivery_preference" name="delivery_preference" x-model="delivery" class="{{ $inputClass }}">
                <option value="deliver">Deliver to the customer</option>
                <option value="branch_pickup">Customer collects at the branch</option>
            </select>
        </div>
        <div x-show="delivery === 'deliver'">
            <label for="delivery_address" class="text-sm font-semibold">Return delivery address <span class="font-normal text-muted">(optional)</span></label>
            <textarea id="delivery_address" name="delivery_address" rows="2" maxlength="2000" class="{{ $inputClass }}" placeholder="Same as pickup address if left blank">{{ old('delivery_address') }}</textarea>
        </div>
        <div>
            <label for="notes" class="text-sm font-semibold">Booking notes <span class="font-normal text-muted">(optional)</span></label>
            <textarea id="notes" name="notes" rows="2" maxlength="2000" class="{{ $inputClass }}" placeholder="e.g. Facebook request, two bags, call on arrival">{{ old('notes') }}</textarea>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            @if(auth()->user()->hasMenuAccess('pickup_requests'))
                <a href="{{ route('admin.pickup-requests.index', ['branch_id' => $branch->id]) }}" class="text-sm font-semibold text-primary-deep dark:text-primary-soft">View pickup bookings</a>
            @endif
            <button type="submit" :disabled="submitting" class="rounded-lg bg-primary-deep px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" x-text="submitting ? 'Booking…' : 'Book pickup'">Book pickup</button>
        </div>
    </form>
</div>
@endsection
