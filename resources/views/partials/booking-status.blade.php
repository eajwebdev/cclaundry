@php
    /**
     * Status pill for a pickup booking. Expects $status (string) and optional
     * $size ('sm' by default).
     *
     * Classes are written out in full rather than composed, so Tailwind's
     * source scanner can actually see them.
     */
    $tones = [
        'pending' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
        'confirmed' => 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300',
        'picked_up' => 'border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-300',
        'washing' => 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-300',
        'drying' => 'border-cyan-200 bg-cyan-50 text-cyan-700 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-300',
        'folding' => 'border-purple-200 bg-purple-50 text-purple-700 dark:border-purple-500/30 dark:bg-purple-500/10 dark:text-purple-300',
        'ironing' => 'border-fuchsia-200 bg-fuchsia-50 text-fuchsia-700 dark:border-fuchsia-500/30 dark:bg-fuchsia-500/10 dark:text-fuchsia-300',
        'ready_for_pickup' => 'border-teal-200 bg-teal-50 text-teal-700 dark:border-teal-500/30 dark:bg-teal-500/10 dark:text-teal-300',
        'ready_for_delivery' => 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300',
        'out_for_delivery' => 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300',
        'completed' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
        'cancelled' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
    ];

    $labels = [
        'pending' => 'Awaiting confirmation',
        'confirmed' => 'Pickup scheduled',
        'picked_up' => 'With us now',
        'washing' => 'Washing',
        'drying' => 'Drying',
        'folding' => 'Folding',
        'ironing' => 'Ironing / Steaming',
        'ready_for_pickup' => 'Ready for pickup',
        'ready_for_delivery' => 'Ready for delivery',
        'out_for_delivery' => 'Out for delivery',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    $icons = [
        'pending' => 'timer',
        'confirmed' => 'calendar',
        'picked_up' => 'laundry',
        'washing' => 'waves',
        'drying' => 'wind',
        'folding' => 'shirt',
        'ironing' => 'shirt',
        'ready_for_pickup' => 'package-check',
        'ready_for_delivery' => 'package-check',
        'out_for_delivery' => 'truck',
        'completed' => 'check-check',
        'cancelled' => 'x',
    ];
@endphp

<span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold whitespace-nowrap {{ $tones[$status] ?? $tones['pending'] }}">
    <span data-lucide="{{ $icons[$status] ?? 'timer' }}" class="h-3 w-3"></span>
    {{ $labels[$status] ?? ucfirst(str_replace('_', ' ', $status)) }}
</span>
