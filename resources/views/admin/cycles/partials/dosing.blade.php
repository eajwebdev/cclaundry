{{-- Liquid dosing guide per order line: machine size and dose for each load. Expects: $order --}}
@php($dosingLines = $order->items
    ->filter(fn ($item) => $item->service)
    ->map(fn ($item) => [
        'name' => $item->service->name,
        'loads' => \App\Support\LaundryDosingGuide::loadsForService($item->service, (float) $item->quantity),
    ])
    ->filter(fn ($line) => $line['loads'] !== [])
    ->values())
@if($dosingLines->isNotEmpty())
    <div class="mb-2.5 rounded-md border border-border bg-smoke px-2.5 py-2 text-[11px] sm:mb-3 sm:text-xs dark:border-gray-800 dark:bg-gray-950">
        <p class="mb-1 font-semibold uppercase tracking-[0.12em] text-muted">Dosing per load</p>
        <ul class="space-y-1">
            @foreach($dosingLines as $line)
                @php($load = $line['loads'][0])
                @php($loadCount = count($line['loads']))
                <li class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                    <span class="font-medium">{{ $line['name'] }}</span>
                    <span class="text-muted">{{ $loadCount }} × {{ rtrim(rtrim(number_format($load['kilos'], 2), '0'), '.') }} kg</span>
                    <span class="rounded px-1 py-0.5 text-[9px] font-bold uppercase sm:text-[10px] {{ $load['machine'] === \App\Support\MachineClass::TITAN ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300' }}">{{ \App\Support\MachineClass::label($load['machine']) }}</span>
                    <span>{{ (int) $load['detergent_ml'] }} mL Mrs. Bloom</span>
                    @if($load['softener_ml'] > 0)
                        <span>+ {{ (int) $load['softener_ml'] }} mL Yen Yen</span>
                    @else
                        <span class="font-semibold text-red-600 dark:text-red-400">No Yen Yen</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
