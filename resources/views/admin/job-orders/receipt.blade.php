<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $order->job_order_number }} Receipt</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @page {
            size: 58mm 3276mm;
            margin: 0;
        }
        @media print {
            .no-print { display: none !important; }
            html, body {
                background: white !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 58mm !important;
                display: flex !important;
                justify-content: center !important;
            }
            main {
                max-width: 58mm !important;
                width: 58mm !important;
                margin: 0 auto !important;
                padding: 0 !important;
                min-height: 0 !important;
                display: flex !important;
                justify-content: center !important;
            }
            .receipt {
                box-shadow: none !important;
                border: 0 !important;
                width: 52mm !important;
                max-width: 52mm !important;
                min-width: 52mm !important;
                margin: 0 auto !important;
                position: relative !important;
                left: -7mm !important;
                overflow: visible !important;
            }
        }
    </style>
</head>
<body class="bg-smoke text-dark">
    <main class="mx-auto min-h-screen max-w-md p-4">
        <div class="no-print mb-3 flex justify-end gap-2">
            <button onclick="window.print()" class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-white">Print</button>
        </div>

        @include('admin.job-orders.partials.receipt-card', compact('order', 'settings', 'branchSetting'))
    </main>
</body>
</html>
