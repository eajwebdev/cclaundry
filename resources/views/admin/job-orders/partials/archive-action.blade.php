@if(auth()->user()->isAdmin() && ($order->archived_at || $order->status === 'cancelled'))
    <form method="POST" action="{{ $order->archived_at ? route('admin.job-orders.restore-archive', $order) : route('admin.job-orders.archive', $order) }}" class="inline">
        @csrf
        @method('PATCH')
        @if($order->archived_at)
            <button type="submit" title="Restore archived job order" aria-label="Restore archived job order" class="inline-flex h-8 items-center justify-center gap-1.5 rounded-md border border-border px-2 text-xs font-medium text-primary hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                <span data-lucide="refresh-cw" class="h-4 w-4"></span>Restore
            </button>
        @else
            <button type="submit" x-on:click.prevent="Swal.fire({ title: 'Archive cancelled job order?', text: 'This will hide the order from the main list. Payments, inventory records, and history will be preserved. You can restore it from the Archived filter.', icon: 'question', showCancelButton: true, confirmButtonText: 'Archive' }).then((result) => { if (result.isConfirmed) $el.closest('form').submit(); })" title="Archive cancelled job order" aria-label="Archive cancelled job order" class="inline-flex h-8 items-center justify-center gap-1.5 rounded-md border border-border px-2 text-xs font-medium text-muted hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                <span data-lucide="archive" class="h-4 w-4"></span>Archive
            </button>
        @endif
    </form>
@endif
