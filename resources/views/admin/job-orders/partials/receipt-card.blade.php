@php
    $settings ??= \App\Models\SystemSetting::current();
    $branchSetting ??= $order->branch?->setting;
    $receiptHeader = $branchSetting?->receipt_header ?: $settings?->receipt_header;
    $receiptFooter = $branchSetting?->receipt_footer ?: $settings?->receipt_footer;
    $totalPaid = $order->payments->sum('amount');
    $isCrossBranchProduction = (int) ($order->processing_branch_id ?: $order->branch_id) !== (int) $order->branch_id;
@endphp

<style>
    /* ---- VOZY P50 / POS-58 THERMAL PRINT CONFIGURATION (58mm continuous) ---- */
    @page {
        size: 58mm 3276mm;
        margin: 0;
    }
    @media print {
        *, *::before, *::after {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            box-sizing: border-box !important;
        }
        html, body {
            width: 58mm !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #ffffff !important;
            color: #000000 !important;
            display: flex !important;
            justify-content: center !important;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
        }
        .receipt {
            width: 46mm !important;
            max-width: 46mm !important;
            min-width: 46mm !important;
            margin: 0 auto !important;
            padding: 2mm 0 !important;
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            background: #ffffff !important;
            color: #000000 !important;
            font-size: 7.5px !important;
            line-height: 1.25 !important;
            overflow: hidden !important;
        }
        .receipt * {
            color: #000000 !important;
            border-color: #000000 !important;
        }
        .receipt span[class*="rounded-full"],
        .receipt .brand-mark,
        .receipt svg {
            max-height: 20px !important;
            max-width: 20px !important;
            margin: 0 auto 1px !important;
        }
        .receipt h1 {
            font-size: 10px !important;
            font-weight: 800 !important;
            line-height: 1.15 !important;
            text-transform: uppercase !important;
            letter-spacing: -0.01em !important;
        }
        .receipt p,
        .receipt span {
            font-size: 7.2px !important;
            line-height: 1.25 !important;
        }
        .receipt .text-xs {
            font-size: 7.2px !important;
            line-height: 1.3 !important;
        }
        .receipt .text-sm {
            font-size: 7.5px !important;
            line-height: 1.3 !important;
        }
        .receipt .border-dashed,
        .receipt .border-y {
            border-top: 0.8px dashed #000000 !important;
            border-bottom: 0.8px dashed #000000 !important;
            margin: 1.5mm 0 !important;
            padding: 1.2mm 0 !important;
        }
        .receipt table {
            font-size: 7.2px !important;
            width: 100% !important;
            margin: 1mm 0 !important;
        }
        .receipt table th {
            font-size: 7.2px !important;
            padding: 0 0 1.5px 0 !important;
            border-bottom: 0.8px solid #000000 !important;
        }
        .receipt table td {
            font-size: 7.2px !important;
            padding: 1.5px 0 !important;
            border-bottom: 0.5px dashed #444444 !important;
        }
        .receipt table td p {
            font-size: 7.2px !important;
            line-height: 1.2 !important;
        }
        .receipt table td p.text-muted {
            font-size: 6.2px !important;
            color: #333333 !important;
        }
        .receipt .total-row,
        .receipt .font-semibold,
        .receipt .font-medium {
            font-weight: 700 !important;
        }
        .receipt .text-right,
        .receipt th.text-right,
        .receipt td.text-right {
            text-align: right !important;
            white-space: nowrap !important;
        }
        .receipt .bg-smoke {
            background: #f0f0f0 !important;
            padding: 1mm !important;
            margin-top: 1.5mm !important;
            border-radius: 1mm !important;
            font-size: 6.8px !important;
        }
        /* Hide QR code in print unconditionally */
        .receipt [class*="qr"],
        .receipt img[alt*="QR"],
        .receipt-qr,
        .receipt-qr-section {
            display: none !important;
        }
        .receipt tr,
        .receipt table,
        .receipt > div {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
    }
</style>

<section class="receipt rounded-lg border border-border bg-white p-5 shadow-sm">
    <div class="text-center">
        <x-brand-mark :ring="false" class="mx-auto mb-2 h-14 w-14" />
        <h1 class="text-lg font-semibold">{{ $settings?->business_name ?? config('app.name') }}</h1>
        <p class="text-xs font-medium">{{ $order->branch?->name }}</p>
        <p class="text-xs text-muted">{{ $order->branch?->address ?: $settings?->business_address }}</p>
        <p class="text-xs text-muted">{{ $order->branch?->contact_number ?: $settings?->contact_number }} @if($settings?->business_email) - {{ $settings?->business_email }} @endif</p>
    </div>

    @if($receiptHeader)
        <p class="mt-4 rounded-md bg-smoke p-2 text-center text-xs text-muted">{{ $receiptHeader }}</p>
    @endif

    <div class="my-4 border-y border-dashed border-border py-3 text-xs">
        <div class="flex justify-between"><span>JO #</span><span class="font-medium">{{ $order->job_order_number }}</span></div>
        <div class="flex justify-between"><span>Date</span><span>{{ $order->created_at->format('M d, Y h:i A') }}</span></div>
        <div class="flex justify-between"><span>Sales Branch</span><span>{{ $order->branch?->name }}</span></div>
        <div class="flex justify-between"><span>Receiving Branch</span><span>{{ $order->processingBranch?->name ?? $order->branch?->name }}</span></div>
        @if($isCrossBranchProduction)
            <div class="flex justify-between gap-3"><span>Receiving Status</span><span class="text-right">{{ $order->production_accepted_at ? 'QR received '.$order->production_accepted_at->format('M d, h:i A') : 'Pending QR scan' }}</span></div>
        @endif
        <div class="flex justify-between"><span>Release At</span><span>{{ $order->releaseBranch?->name ?? $order->currentBranch?->name ?? $order->branch?->name }}</span></div>
        <div class="flex justify-between"><span>Customer</span><span>{{ $order->customer?->name }}</span></div>
        <div class="flex justify-between"><span>Transaction</span><span>{{ $order->transaction_type === 'delivery' ? 'Delivery / Pick-up' : 'Walk-in / Drop Off' }}</span></div>
        <div class="flex justify-between"><span>Priority</span><span>{{ $order->is_rush ? 'Rush' : 'Standard' }}</span></div>
        <div class="flex justify-between"><span>Status</span><span>{{ \App\Support\StatusBadge::label($order->status) }}</span></div>
    </div>

    <table class="w-full text-xs">
        <thead>
            <tr class="border-b border-border text-left text-muted">
                <th class="py-2">Item</th>
                <th class="py-2 text-right">Qty</th>
                <th class="py-2 text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr class="border-b border-dashed border-border">
                    <td class="py-2">
                        <p class="font-medium">{{ $item->description }}</p>
                        <p class="text-muted">{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $item->unit_price, 2) }}</p>
                    </td>
                    <td class="py-2 text-right">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="py-2 text-right">{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $item->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="mt-4 space-y-1 text-sm">
        <div class="flex justify-between"><span class="text-muted">Subtotal</span><span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $order->subtotal, 2) }}</span></div>
        <div class="flex justify-between"><span class="text-muted">Discount</span><span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $order->discount, 2) }}</span></div>
        <div class="flex justify-between"><span class="text-muted">VAT</span><span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $order->tax, 2) }}</span></div>
        <div class="flex justify-between border-t border-border pt-2 font-semibold"><span>Total</span><span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $order->total, 2) }}</span></div>
        <div class="flex justify-between"><span class="text-muted">Paid</span><span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $totalPaid, 2) }}</span></div>
        <div class="flex justify-between font-semibold"><span>Balance</span><span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</span></div>
    </div>

    <div class="mt-4 border-t border-dashed border-border pt-3">
        <p class="mb-2 text-xs font-semibold">Payments</p>
        @forelse($order->payments as $payment)
            <div class="flex justify-between gap-3 text-xs">
                <span>{{ \App\Support\StatusBadge::label($payment->payment_type) }} - {{ $payment->paid_at?->format('M d, h:i A') }} - Collected at {{ $payment->collectedBranch?->name ?? $order->branch?->name }}{{ $payment->reference_no ? ' - Ref: '.$payment->reference_no : '' }}</span>
                <span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $payment->amount, 2) }}</span>
            </div>
        @empty
            <p class="text-xs text-muted">No payment yet. Remaining balance: {{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</p>
        @endforelse
    </div>

    @if($receiptFooter)
        <p class="mt-4 rounded-md bg-smoke p-2 text-center text-xs text-muted">{{ $receiptFooter }}</p>
    @endif
</section>