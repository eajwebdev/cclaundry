@if($order->replacementJobOrder)
    <div class="rounded-lg border border-border bg-smoke p-4 text-sm dark:border-gray-800 dark:bg-gray-900">
        Original payments were reused for
        <a href="{{ route('admin.job-orders.show', $order->replacementJobOrder) }}" class="font-semibold text-primary underline">{{ $order->replacementJobOrder->job_order_number }}</a>.
        The cancelled charge was removed through a recorded ledger adjustment.
    </div>
@elseif(auth()->user()->isAdmin() && $order->status === 'cancelled' && $order->payments->sum('amount') > 0)
    <details @if($errors->hasAny(['replacement_number', 'correction_reason', 'void_duplicate'])) open @endif class="rounded-lg border border-border bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <summary class="cursor-pointer text-sm font-semibold">Reuse original payment on a replacement order</summary>
        <p class="mt-2 text-sm text-muted">Move the existing {{ $settings->currency ?? 'PHP' }} {{ number_format((float) $order->payments->sum('amount'), 2) }} to the replacement order for this same customer and branch. The original payment details stay intact, and this cancelled order’s charge is cleared with an audit entry.</p>
        <form method="POST" action="{{ route('admin.job-orders.reuse-payments', $order) }}" class="mt-4 space-y-3">
            @csrf
            <label class="block text-sm font-medium">Replacement JO number
                <input name="replacement_number" value="{{ old('replacement_number') }}" required maxlength="255" placeholder="Enter the replacement JO number" class="mt-1 h-10 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
            </label>
            <label class="block text-sm font-medium">Reason for correction
                <textarea name="correction_reason" required maxlength="1000" rows="2" placeholder="Why is this order being replaced?" class="mt-1 w-full rounded-md border border-border bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950">{{ old('correction_reason') }}</textarea>
            </label>
            <label class="flex items-start gap-2 rounded-md bg-smoke p-3 text-sm dark:bg-gray-950">
                <input name="void_duplicate" type="checkbox" value="1" @checked(old('void_duplicate')) class="mt-1 rounded border-border text-primary">
                <span>The replacement was also marked paid by mistake. No additional money was received. Void its duplicate payment entries and use the original payment instead.
                    <span class="mt-1 block text-xs text-muted">Only use this when the replacement’s recorded payment total matches the original exactly. Voided entries remain in the audit history.</span>
                </span>
            </label>
            @foreach(['replacement_number', 'correction_reason', 'void_duplicate'] as $field)
                @error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            @endforeach
            <button type="submit" x-on:click.prevent="if ($el.closest('form').reportValidity()) Swal.fire({ title: 'Reuse the original payment?', text: 'This moves all original payments to the replacement and clears the cancelled charge. If you selected the duplicate option, the replacement payment entries will be voided. Only continue after checking both orders.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Confirm correction' }).then((result) => { if (result.isConfirmed) $el.closest('form').submit(); })" class="inline-flex h-10 items-center justify-center rounded-md bg-primary px-4 text-sm font-semibold text-white hover:opacity-90">Reuse original payment</button>
        </form>
    </details>
@endif
