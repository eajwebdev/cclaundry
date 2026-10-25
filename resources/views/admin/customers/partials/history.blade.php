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
    <div class="overflow-x-auto rounded-md border border-border dark:border-gray-800">
        <table class="w-full text-left text-sm">
            <thead class="bg-smoke text-xs uppercase text-muted dark:bg-gray-950">
                <tr>
                    @foreach(['Date', 'Job order', 'Services', 'Status', 'Total', 'Balance'] as $heading)
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
                        <td class="px-3 py-3"><span class="whitespace-nowrap {{ \App\Support\StatusBadge::classes($order->status) }}">{{ \App\Support\StatusBadge::label($order->status) }}</span></td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->total, 2) }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-8 text-center text-muted">No job orders yet for this customer.</td></tr>
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
