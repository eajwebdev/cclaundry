@php
    $cycleLabels = ['wash' => 'Wash', 'dry' => 'Dry', 'fold' => 'Fold', 'iron' => 'Steam'];
    $machineName = fn ($branch, $number) => '#'.$number.' '.\App\Support\MachineClass::label(\App\Support\MachineClass::forMachine($branch, (int) $number));
@endphp
<div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach([
            'Laundry visits' => number_format($visitCount),
            'Return visits' => number_format(max($visitCount - 1, 0)),
            'First visit' => $firstVisit?->format('M d, Y') ?? 'No visits yet',
            'Latest visit' => $lastVisit?->format('M d, Y') ?? 'No visits yet',
        ] as $label => $value)
            <div class="rounded-md border border-border p-3 dark:border-gray-800">
                <p class="text-xs text-muted">{{ $label }}</p>
                <p class="mt-1 font-semibold">{{ $value }}</p>
            </div>
        @endforeach
    </div>
    <p class="text-xs text-muted">Each job order counts as one visit. Cancelled orders are excluded from visit counts.</p>

    {{-- Which washers and dryers this customer's laundry has gone through. --}}
    <section aria-labelledby="machine-usage-title" class="rounded-md border border-border p-3 dark:border-gray-800">
        <h3 id="machine-usage-title" class="text-sm font-semibold">Machines used</h3>
        @if($machineUsage->isEmpty())
            <p class="mt-1 text-xs text-muted">No machine cycles recorded yet for this customer.</p>
        @else
            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach($machineUsage as $usage)
                    <li class="rounded-md bg-smoke px-2.5 py-1.5 text-xs dark:bg-gray-950">
                        <span class="font-semibold">{{ $cycleLabels[$usage->cycle_type] ?? ucfirst($usage->cycle_type) }} #{{ $usage->machine_number }}</span>
                        <span class="text-muted">{{ $usage->machine_class }}@if($showMachineBranch) · {{ $usage->branch?->name }}@endif</span>
                        <span class="font-semibold">× {{ $usage->uses }}</span>
                        @if($usage->last_used_at)
                            <span class="block text-[11px] text-muted">Last {{ \Illuminate\Support\Carbon::parse($usage->last_used_at)->format('M d, Y') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    <div class="overflow-x-auto rounded-md border border-border dark:border-gray-800">
        <table class="w-full text-left text-sm">
            <thead class="bg-smoke text-xs uppercase text-muted dark:bg-gray-950">
                <tr>
                    @foreach(['Date', 'Job order', 'Services', 'Machines', 'Status', 'Total', 'Balance'] as $heading)
                        <th scope="col" class="whitespace-nowrap px-3 py-2">{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-border dark:divide-gray-800">
                @forelse($orders as $order)
                    <tr>
                        <td class="whitespace-nowrap px-3 py-3">{{ $order->created_at?->format('M d, Y h:i A') }}</td>
                        <td class="px-3 py-3">
                            <p class="whitespace-nowrap font-medium">{{ $order->job_order_number }}</p>
                            <p class="text-xs text-muted">{{ $order->branch?->name }}</p>
                        </td>
                        <td class="min-w-40 px-3 py-3">
                            @forelse($order->items as $item)
                                <p>{{ $item->description }} × {{ (float) $item->quantity }}</p>
                            @empty
                                <span class="text-muted">No service details</span>
                            @endforelse
                        </td>
                        <td class="min-w-44 px-3 py-3">
                            @php $machineBranch = $order->processingBranch ?? $order->branch; @endphp
                            @forelse($order->cycles as $cycle)
                                <p class="whitespace-nowrap">
                                    <span class="font-medium">{{ $cycleLabels[$cycle->cycle_type] ?? ucfirst($cycle->cycle_type) }}</span>
                                    {{ $machineName($machineBranch, $cycle->machine_number) }}
                                </p>
                                <p class="mb-1 whitespace-nowrap text-xs text-muted">
                                    {{ $cycle->started_at?->format('M d, h:i A') ?? 'Not started' }}@if($cycle->ended_at) – {{ $cycle->ended_at->format('h:i A') }}@endif
                                </p>
                            @empty
                                <span class="text-muted">None recorded</span>
                            @endforelse
                        </td>
                        <td class="px-3 py-3"><span class="whitespace-nowrap {{ \App\Support\StatusBadge::classes($order->status) }}">{{ \App\Support\StatusBadge::label($order->status) }}</span></td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->total, 2) }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-8 text-center text-muted">No job orders yet for this customer.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($orders->hasPages())
        <nav aria-label="Customer history pages" class="flex items-center justify-between gap-3 text-sm">
            <span class="text-muted">Page {{ $orders->currentPage() }} of {{ $orders->lastPage() }}</span>
            <div class="flex gap-3">
                @if($orders->previousPageUrl())
                    <a href="{{ $orders->previousPageUrl() }}" data-history-page class="text-primary hover:underline">Previous</a>
                @endif
                @if($orders->nextPageUrl())
                    <a href="{{ $orders->nextPageUrl() }}" data-history-page class="text-primary hover:underline">Next</a>
                @endif
            </div>
        </nav>
    @endif
</div>
