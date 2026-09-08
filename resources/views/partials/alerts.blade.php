@php
    // success / info / error, first one present wins. `info` used to be dropped
    // on the floor here, which silently swallowed guidance messages such as the
    // cancelled-subscription notice and the wrong-sign-in-page hint.
    $alertKey = collect(['success', 'info', 'error'])->first(fn ($key) => filled(session($key)));

    $alertStyles = [
        'success' => [
            'title' => 'Success',
            'icon' => 'sparkles',
            'class' => 'border-green-200 text-green-800 dark:border-green-900',
        ],
        'info' => [
            'title' => 'Heads up',
            'icon' => 'bell',
            'class' => 'border-sky-200 text-sky-800 dark:border-sky-900',
        ],
        'error' => [
            'title' => 'Attention',
            'icon' => 'bell',
            'class' => 'border-red-200 text-red-800 dark:border-red-900',
        ],
    ];
@endphp

@if($alertKey)
    @php($alert = $alertStyles[$alertKey])
    <div
        x-data="{
            show: false,
            init() {
                this.$nextTick(() => {
                    this.show = true;
                    setTimeout(() => this.show = false, 5200);
                });
            }
        }"
        x-show="show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-y-3 opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition ease-in duration-1000"
        x-transition:leave-start="translate-y-0 opacity-100"
        x-transition:leave-end="translate-y-3 opacity-0"
        class="fixed bottom-4 right-4 z-[70] w-[min(24rem,calc(100vw-2rem))] rounded-md border bg-white p-3 shadow-lg dark:bg-gray-900 {{ $alert['class'] }}"
    >
        <div class="flex items-start gap-2">
            <span data-lucide="{{ $alert['icon'] }}" class="mt-0.5 h-4 w-4 shrink-0"></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium">{{ $alert['title'] }}</p>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ session($alertKey) }}</p>
            </div>
            <button type="button" @click="show = false" class="rounded-sm p-1 hover:bg-gray-100 dark:hover:bg-gray-800">
                <span data-lucide="x" class="h-4 w-4"></span>
            </button>
        </div>
    </div>
@endif
