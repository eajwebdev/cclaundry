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
        'completed' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
        'cancelled' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
    ];

    $labels = [
        'pending' => 'Awaiting confirmation',
        'confirmed' => 'Pickup scheduled',
        'picked_up' => 'With us now',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    $icons = [
        'pending' => 'timer',
        'confirmed' => 'calendar',
        'picked_up' => 'laundry',
        'completed' => 'check-check',
        'cancelled' => 'x',
    ];
@endphp

<span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold whitespace-nowrap {{ $tones[$status] ?? $tones['pending'] }}">
    <span data-lucide="{{ $icons[$status] ?? 'timer' }}" class="h-3 w-3"></span>
    {{ $labels[$status] ?? ucfirst(str_replace('_', ' ', $status)) }}
</span>
