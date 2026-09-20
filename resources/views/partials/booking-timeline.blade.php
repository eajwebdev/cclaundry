@php
    /**
     * Where a pickup booking is, as one vertical timeline. The booking itself
     * only knows pending, confirmed, picked up and completed; once the branch
     * turns it into a job order, that order's wash / dry / fold status fills in
     * the steps between. Expects $pickupRequest with its jobOrder loaded.
     */
    $wantsDelivery = $pickupRequest->wantsDelivery();
    $progressStatus = $pickupRequest->customerProgressStatus();
    $stamp = fn ($moment) => $moment?->format('M j, Y · g:i A');

    $steps = [
        ['label' => 'Booking received', 'note' => $stamp($pickupRequest->created_at)],
        // The schedule is known from the start, so it shows even before it is reached.
        ['label' => 'Pickup scheduled', 'note' => $pickupRequest->pickup_date->format('M j, Y').' · '.$pickupRequest->pickupSlotLabel(), 'always' => true],
        ['label' => 'Laundry received', 'note' => $stamp($pickupRequest->picked_up_at)],
        ['label' => 'Washing'],
        ['label' => 'Drying'],
        ['label' => $pickupRequest->jobOrder?->latestCycle?->cycle_type === 'iron' ? 'Ironing / Steaming' : 'Folding'],
        ['label' => $wantsDelivery ? 'Ready for delivery' : 'Ready for pickup'],
    ];

    if ($wantsDelivery) {
        $steps[] = ['label' => 'Out for delivery'];
    }

    $steps[] = [
        'label' => $wantsDelivery ? 'Delivered' : 'Claimed at branch',
        'note' => $stamp($pickupRequest->delivered_at),
    ];

    $lastStep = count($steps) - 1;

    $current = match ($progressStatus) {
        'pending' => 0,
        'confirmed' => 1,
        'picked_up' => 2,
        'washing' => 3,
        'drying' => 4,
        'folding', 'ironing' => 5,
        'ready_for_pickup', 'ready_for_delivery' => 6,
        'out_for_delivery' => 7,
        'completed' => $lastStep,
        default => -1,
    };
@endphp

@if($current < 0)
    <div class="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3.5 text-sm text-red-800">
        <span data-lucide="x" class="mt-0.5 h-4 w-4 shrink-0"></span>
        <div>
            <p class="font-bold">
                This booking was cancelled{{ $pickupRequest->cancelled_at ? ' on '.$pickupRequest->cancelled_at->format('M j, Y') : '' }}.
            </p>
            @if($pickupRequest->cancellation_reason)
                <p class="mt-0.5">{{ $pickupRequest->cancellation_reason }}</p>
            @endif
        </div>
    </div>
@else
    <ol>
        @foreach ($steps as $index => $step)
            @php
                $state = $index < $current ? 'done' : ($index === $current ? 'current' : 'upcoming');
                $note = $step['note'] ?? null;
                $always = $step['always'] ?? false;

                $subtitle = match ($state) {
                    'done' => $note ?: 'Done',
                    'current' => match (true) {
                        $index === 0 => 'Awaiting confirmation',
                        $index === $lastStep => $note ?: 'Completed',
                        $always => $note,
                        default => 'In progress',
                    },
                    default => $always ? $note : 'Pending',
                };
            @endphp
            <li class="relative flex gap-3.5 {{ $loop->last ? '' : 'pb-5' }}">
                @unless($loop->last)
                    <span aria-hidden="true" class="absolute top-7 bottom-0 left-[0.8125rem] w-0.5 {{ $index < $current ? 'bg-cc-brown' : 'bg-cc-line' }}"></span>
                @endunless

                <span class="relative flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2
                    {{ $state === 'upcoming' ? 'border-cc-line bg-cc-surface' : 'border-cc-brown bg-cc-brown text-white' }}
                    {{ $state === 'current' ? 'ring-4 ring-cc-brown/20' : '' }}">
                    @if($state !== 'upcoming')
                        <span data-lucide="check" class="h-3.5 w-3.5"></span>
                    @endif
                </span>

                <div class="min-w-0 pt-0.5">
                    <p class="text-sm font-bold {{ $state === 'upcoming' ? 'text-cc-muted' : 'text-cc-deep' }}">{{ $step['label'] }}</p>
                    <p class="mt-0.5 text-xs {{ $state === 'current' ? 'font-bold text-cc-brown' : 'text-cc-muted' }}">{{ $subtitle }}</p>
                </div>
            </li>
        @endforeach
    </ol>
@endif
