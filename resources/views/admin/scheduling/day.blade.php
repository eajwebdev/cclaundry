@php
    $opening = $planner::minutes($settings->opens_at);
    $closing = $planner::minutes($settings->closes_at);
    $span = max(1, $closing - $opening);
    $dayShifts = $byDay->get($date->toDateString(), collect());
    $scheduledHours = $dayShifts->sum(fn ($shift) => $planner::paidHours($planner::snapshot($shift)));
    $lateArrivals = 0;
    $clockedHours = 0;
@endphp
<div class="overflow-x-auto rounded-xl border border-border bg-white dark:border-gray-800 dark:bg-gray-900">
    <div class="min-w-[850px] p-4">
        <div class="mb-3 flex justify-between text-xs text-muted"><span>{{ $branch->name }} · {{ substr($settings->opens_at, 0, 5) }}–{{ substr($settings->closes_at, 0, 5) }}</span><span>{{ $dayShifts->whereNotNull('starts_at')->count() }} scheduled · {{ $dayShifts->where('template', 'rest')->count() }} rest</span></div>
        <div class="ml-48 flex justify-between border-b border-border pb-2 text-[10px] text-muted dark:border-gray-800">@for($minute = $opening; $minute <= $closing; $minute += 60)<span>{{ sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60) }}</span>@endfor</div>
        @foreach($employees as $person)
            @php
                $cell = $person->id.':'.$date->toDateString();
                $shift = $byCell->get($cell);
                $data = $shift ? $planner::snapshot($shift) : null;
                $record = $attendance->get($cell);
                $status = $shift ? $planner::attendanceStatus($shift, $record, $settings->grace_minutes) : ['label' => 'Not scheduled', 'late' => 0];
                $lateArrivals += $status['late'] ? 1 : 0;
                $clockedHours += $planner::workedHours($record, $shift?->published_data, $date);
                $left = $data && $data['starts_at'] ? max(0, ($planner::minutes($data['starts_at']) - $opening) / $span * 100) : 0;
                $right = $data && $data['ends_at'] ? min(100, ($planner::minutes($data['ends_at']) - $opening) / $span * 100) : 0;
            @endphp
            <div class="flex min-h-24 items-center border-b border-border py-3 dark:border-gray-800">
                <div class="w-48 shrink-0 pr-3"><p class="text-sm font-semibold">{{ $person->name }}</p><p class="mt-1 text-xs {{ $status['late'] ? 'text-red-600' : 'text-muted' }}">{{ $status['label'] }}</p>@if($record?->schedule_flags['missing_punch'] ?? false)<p class="text-xs text-amber-700">Missing punch · auto-closed</p>@endif</div>
                <div class="relative h-16 flex-1 rounded bg-smoke dark:bg-gray-950" data-timeline data-opening="{{ $opening }}" data-span="{{ $span }}">
                    @if($data && $data['starts_at'])
                        <button type="button" @if($canEdit) @click="cellClick(@js($cell))" @endif class="absolute top-1 h-14 overflow-hidden rounded-lg border border-primary/20 bg-primary/10 px-3 text-left text-xs text-primary" style="left:{{ $left }}%;width:{{ max(0, $right-$left) }}%"><strong>{{ $templates[$data['template']]['label'] }}</strong><span class="block">{{ $data['starts_at'] }}–{{ $data['ends_at'] }} {{ $planner::isDraft($shift) ? '· draft' : '' }}</span></button>
                        @if($data['break_minutes'])@php($breakLeft = ($planner::minutes($data['break_starts_at']) - $opening) / $span * 100)@php($breakWidth = $data['break_minutes'] / $span * 100)<div @if($canEdit) @pointerdown.prevent="resize($event, @js($cell), 'break_starts_at')" @endif class="absolute top-1 flex h-14 touch-none items-center justify-center border-2 border-dashed border-gray-400 bg-white/80 text-[9px] text-gray-600 dark:bg-gray-900/90" style="left:{{ $breakLeft }}%;width:{{ $breakWidth }}%">break</div>@endif
                        @if($canEdit)<button type="button" aria-label="Adjust shift start" @pointerdown.prevent="resize($event, @js($cell), 'starts_at')" class="absolute top-1 h-14 w-2 touch-none cursor-ew-resize rounded-l bg-primary/50" style="left:{{ $left }}%"></button><button type="button" aria-label="Adjust shift end" @pointerdown.prevent="resize($event, @js($cell), 'ends_at')" class="absolute top-1 h-14 w-2 -translate-x-full touch-none cursor-ew-resize rounded-r bg-primary/50" style="left:{{ $right }}%"></button>@endif
                    @else <button @if($canEdit) @click="cellClick(@js($cell))" @endif class="flex h-full w-full items-center justify-center text-xs text-muted">{{ $data ? $templates[$data['template']]['label'] : '+ Add shift' }}</button>@endif
                    @if($date->isToday() && $planner::minutes(now()->format('H:i')) >= $opening && $planner::minutes(now()->format('H:i')) <= $closing)<div class="pointer-events-none absolute inset-y-0 w-px bg-red-500" style="left:{{ ($planner::minutes(now()->format('H:i')) - $opening) / $span * 100 }}%"></div>@endif
                </div>
            </div>
        @endforeach
        <div class="mt-3 flex gap-3"><span class="w-44 shrink-0 text-xs font-semibold">On shift<br><span class="font-normal text-muted">Staff + riders · breaks excluded</span></span><div class="grid flex-1 gap-px" style="grid-template-columns:repeat({{ count($coverage[$date->toDateString()]) }},minmax(0,1fr))">@foreach($coverage[$date->toDateString()] as $block)<div title="{{ $block['time'] }} · {{ $block['staff'] }} shop staff + {{ $block['riders'] }} riders" class="h-8 {{ $block['short'] ? 'bg-red-200 text-red-900 dark:bg-red-900 dark:text-red-100' : 'bg-emerald-200 text-emerald-900 dark:bg-emerald-900 dark:text-emerald-100' }} text-center text-[9px] leading-8">{{ $loop->index % 4 === 0 ? $block['staff'].'+'.$block['riders'] : '' }}</div>@endforeach</div></div>
    </div>
</div>
<div class="grid grid-cols-2 gap-3 md:grid-cols-4">@foreach(['Scheduled hours' => number_format($scheduledHours, 1).' h', 'Clocked so far' => number_format($clockedHours, 1).' h', 'Late arrivals' => $lateArrivals, 'Rider on duty' => $dayShifts->filter(fn ($shift) => $shift->template === 'rider' || $shift->employee?->schedule_role === 'rider')->whereNotNull('starts_at')->map(fn ($shift) => $shift->employee?->name)->implode(', ') ?: 'None'] as $label => $value)<div class="rounded-xl border border-border bg-white p-4 dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">{{ $label }}</p><p class="mt-1 text-lg font-semibold">{{ $value }}</p></div>@endforeach</div>
