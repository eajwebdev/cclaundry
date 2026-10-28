@extends('layouts.app')
@section('page_title', 'Staff Scheduling')
@section('content')
@php
    $planner = \App\Support\StaffScheduling::class;
    $cellData = $byCell->map(fn ($shift) => $planner::snapshot($shift))->all();
    $draftCount = $byCell->filter(fn ($shift) => $planner::isDraft($shift))->count();
    $step = $view === 'month' ? 'month' : ($view === 'day' ? 'day' : 'week');
    $previous = $date->copy()->sub(1, $step)->toDateString();
    $next = $date->copy()->add(1, $step)->toDateString();
@endphp
<div x-data="staffScheduling(@js($cellData), @js($templates))" class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><p class="text-xs font-semibold uppercase tracking-widest text-primary">People & shifts</p><h1 class="mt-1 text-2xl font-semibold">Staff Scheduling</h1><p class="mt-1 text-sm text-muted">Plan coverage, publish shifts and review time off.</p></div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.scheduling.pdf', ['branch_id' => $branch->id, 'date' => $date->toDateString(), 'view' => $view, 'employee_id' => request('employee_id')]) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-lg border border-border bg-white px-3 py-2 text-sm font-medium dark:border-gray-700 dark:bg-gray-900"><span data-lucide="printer" class="h-4 w-4"></span>Print / PDF</a>
            <div class="flex items-center gap-2 rounded-lg border border-border bg-white p-1 dark:border-gray-800 dark:bg-gray-900">
            @foreach(['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $key => $label)
                <a href="{{ route('admin.scheduling.index', array_merge(request()->only('branch_id', 'date', 'employee_id'), ['view' => $key])) }}" @class(['rounded-md px-4 py-2 text-sm font-medium', 'bg-primary text-white' => $view === $key, 'hover:bg-smoke dark:hover:bg-gray-800' => $view !== $key])>{{ $label }}</a>
            @endforeach
            </div>
        </div>
    </div>
    @if($errors->any())<div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
        <div class="flex items-center gap-3">
            <a aria-label="Previous period" href="{{ route('admin.scheduling.index', array_merge(request()->only('view', 'employee_id'), ['branch_id' => $branch->id, 'date' => $previous])) }}" class="rounded-md border border-border px-3 py-2 dark:border-gray-700">‹</a>
            <h2 class="text-sm font-semibold">{{ $view === 'month' ? $date->format('F Y') : ($view === 'day' ? $date->format('l, M j, Y') : $start->format('M j').' – '.$end->format('M j, Y')) }}</h2>
            <a aria-label="Next period" href="{{ route('admin.scheduling.index', array_merge(request()->only('view', 'employee_id'), ['branch_id' => $branch->id, 'date' => $next])) }}" class="rounded-md border border-border px-3 py-2 dark:border-gray-700">›</a>
        </div>
        <form class="flex flex-wrap items-center gap-2" method="GET">
            <input type="hidden" name="view" value="{{ $view }}">
            <input type="date" name="date" value="{{ $date->toDateString() }}" class="rounded-md border border-border bg-transparent px-2 py-2 text-sm dark:border-gray-700">
            <select name="branch_id" aria-label="Branch" class="rounded-md border border-border bg-white px-2 py-2 text-sm dark:border-gray-700 dark:bg-gray-950">@foreach($branches as $option)<option value="{{ $option->id }}" @selected($branch->id === $option->id)>{{ $option->name }}</option>@endforeach</select>
            <select name="employee_id" aria-label="Employee" class="rounded-md border border-border bg-white px-2 py-2 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">All staff</option>@foreach($allEmployees as $person)<option value="{{ $person->id }}" @selected(request('employee_id') == $person->id)>{{ $person->name }}</option>@endforeach</select>
            <button class="rounded-md bg-primary px-3 py-2 text-sm font-medium text-white">View</button>
        </form>
    </div>
    @if($canEdit)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-2">
                @foreach(['last_week' => 'Copy last week', 'month' => 'Repeat for month'] as $mode => $label)
                    <form method="POST" action="{{ route('admin.scheduling.copy') }}" @submit="if (!window.confirm('Copy this pattern into draft cells? Holidays, approved leave and published shifts will be skipped.')) $event.preventDefault()">@csrf<input type="hidden" name="branch_id" value="{{ $branch->id }}"><input type="hidden" name="date" value="{{ $date->toDateString() }}"><input type="hidden" name="mode" value="{{ $mode }}"><button class="rounded-lg border border-border bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">{{ $label }}</button></form>
                @endforeach
                <button type="button" @click="settingsOpen = true" :aria-expanded="settingsOpen" aria-controls="schedule-settings-dialog" class="rounded-lg border border-border bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">Settings & staff</button>
            </div>
            <div class="flex items-center gap-3"><span class="text-xs font-medium text-muted">{{ $draftCount ? $draftCount.' draft changes · not sent' : 'Published / no draft changes' }}</span><button @click="publishOpen = true" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white">Publish week</button></div>
        </div>
    @endif
    @if($view === 'week')
        <div class="overflow-x-auto rounded-xl border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full min-w-[1100px] table-fixed text-left text-sm">
                <thead class="border-b border-border bg-smoke dark:border-gray-800 dark:bg-gray-950"><tr><th class="w-44 p-3">Employee</th>@foreach($days as $day)<th class="p-3 {{ $day->isToday() ? 'text-primary' : '' }}">{{ $day->format('D') }}<span class="block text-xs font-normal text-muted">{{ $day->format('M j') }}</span>@if($holiday = $holidays->get($day->toDateString()))<span class="block text-[10px] font-normal text-amber-700">{{ $holiday->name }} · {{ $holiday->type }} · review premium pay</span>@endif</th>@endforeach<th class="w-32 p-3">Hours @if($showCosts)& cost @endif</th></tr></thead>
                <tbody class="divide-y divide-border dark:divide-gray-800">
                    @forelse($employees as $person)
                        @php($personShifts = $byCell->filter(fn ($shift) => $shift->attendance_employee_id === $person->id))
                        @php($hours = $personShifts->sum(fn ($shift) => $planner::paidHours($planner::snapshot($shift))))
                        <tr><th class="p-3 align-top"><span class="font-semibold">{{ $person->name }}</span><span class="mt-1 block text-xs font-normal text-muted">{{ str_replace('_', ' ', ucfirst($person->schedule_role)) }}</span></th>
                        @foreach($days as $day)
                            @php($cell = $person->id.':'.$day->toDateString())
                            @php($shift = $byCell->get($cell))
                            <td class="p-1.5 align-top">
                                <button type="button" @if($canEdit) @click="cellClick(@js($cell))" @dragover.prevent @drop.prevent="dropTemplate($event, @js($cell))" @touchstart.passive="hold(@js($cell))" @touchend="releaseHold()" @touchcancel="releaseHold()" @endif
                                    :class="selected.includes(@js($cell)) ? 'ring-2 ring-primary' : ''"
                                    @class(['min-h-24 w-full rounded-lg border p-2 text-left transition', 'border-dashed border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800' => !$shift, 'border-amber-200 bg-amber-50/40 dark:border-amber-900/50 dark:bg-amber-500/5' => $shift && $planner::isDraft($shift), 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900/50 dark:bg-emerald-500/5' => $shift && !$planner::isDraft($shift)])>
                                    @if($shift)
                                        @php($data = $planner::snapshot($shift))
                                        <span class="block font-semibold">{{ $templates[$data['template']]['label'] }}</span>
                                        @if($data['starts_at'])<span class="mt-1 block text-xs tabular-nums">{{ $data['starts_at'] }}–{{ $data['ends_at'] }}</span><span class="block text-[10px] text-muted">Break {{ $data['break_minutes'] }} min</span>@endif
                                        @if($data['note'])<span class="mt-1 block break-words text-[10px] text-muted">{{ $data['note'] }}</span>@endif
                                        <span class="mt-1 block text-[9px] uppercase tracking-wide text-muted">{{ $planner::isDraft($shift) ? 'Draft' : 'Published' }}@if($data['starts_at'] && ($data['starts_at'] !== $templates[$data['template']]['starts_at'] || $data['ends_at'] !== $templates[$data['template']]['ends_at'])) · Custom ✎@endif</span>
                                    @else <span class="text-xs text-muted">{{ $canEdit ? '+ Add shift' : 'Not scheduled' }}</span> @endif
                                </button>
                            </td>
                        @endforeach
                        <td class="p-3 align-top"><span class="font-semibold">{{ number_format($hours, 1) }} h</span> @if($showCosts)<span class="mt-1 block text-xs text-muted">{{ $person->daily_rate ? ($appSettings?->currency ?? 'PHP').' '.number_format($personShifts->sum(fn ($shift) => $planner::cost($planner::snapshot($shift), (float) $person->daily_rate)), 2) : 'Set daily rate' }}</span>@endif</td></tr>
                    @empty<tr><td colspan="9" class="p-8 text-center text-muted">Add active employees to this branch in Employees to start scheduling.</td></tr>@endforelse
                </tbody>
                <tfoot class="border-t border-border bg-smoke text-xs dark:border-gray-800 dark:bg-gray-950"><tr><th class="p-3">Coverage<br><span class="font-normal text-muted">15-min blocks</span></th>@foreach($days as $day)@php($short = collect($coverage[$day->toDateString()])->where('short', true)->count())<td class="p-3 {{ $short ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">{{ $short ? '⚠ '.$short.' short blocks' : '✓ Covered' }}</td>@endforeach<td class="p-3 font-semibold">{{ number_format($byCell->sum(fn ($shift) => $planner::paidHours($planner::snapshot($shift))), 1) }} h</td></tr></tfoot>
            </table>
        </div>
        @if($canEdit)
            <div class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                <span class="mr-1 text-xs font-semibold text-muted">Tap or drag</span>
                @foreach($templates as $key => $template)@continue($key === 'unscheduled')<button type="button" draggable="true" @dragstart="$event.dataTransfer.setData('text/plain', @js($key))" @click="chooseTemplate(@js($key))" :class="pickedTemplate === @js($key) ? 'ring-2 ring-primary' : ''" class="rounded-lg border border-border px-3 py-2 text-left dark:border-gray-700"><span class="block text-xs font-semibold">{{ $template['label'] }}</span><span class="block text-[10px] text-muted">{{ $template['starts_at'] ? $template['starts_at'].'–'.$template['ends_at'] : 'Day off' }}</span></button>@endforeach
                <label class="ml-auto flex items-center gap-2 text-xs"><input type="checkbox" x-model="multi">Select multiple</label><button x-show="selected.length" @click="editSelected()" class="rounded-md bg-primary px-3 py-2 text-xs text-white">Edit <span x-text="selected.length"></span> cells</button><button x-show="clipboard" @click="pasteMode = !pasteMode" :class="pasteMode ? 'ring-2 ring-primary' : ''" class="rounded-md border border-border px-3 py-2 text-xs dark:border-gray-700">Paste copied shift</button>
            </div>
        @endif
    @elseif($view === 'month')
        <div class="overflow-x-auto rounded-xl border border-border bg-white dark:border-gray-800 dark:bg-gray-900">
            <div class="grid min-w-[800px] grid-cols-7 border-b border-border bg-smoke text-center text-xs font-semibold dark:border-gray-800 dark:bg-gray-950">@foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $label)<span class="p-3">{{ $label }}</span>@endforeach</div>
            <div class="flex min-w-[800px] flex-wrap gap-2 border-b border-border px-3 py-2 text-xs dark:border-gray-800"><span class="text-muted">Open week:</span>@foreach($days->filter(fn ($day) => $day->isMonday()) as $monday)<a class="text-primary underline" href="{{ route('admin.scheduling.index', ['view' => 'week', 'date' => $monday->toDateString(), 'branch_id' => $branch->id, 'employee_id' => request('employee_id')]) }}">{{ $monday->format('M j') }}</a>@endforeach</div>
            <div class="grid min-w-[800px] grid-cols-7">@foreach($days as $day)
                @php($dayShifts = $byDay->get($day->toDateString(), collect())->whereIn('attendance_employee_id', $employees->pluck('id')))
                @php($short = collect($coverage[$day->toDateString()])->contains('short', true))
                <a href="{{ route('admin.scheduling.index', ['view' => 'day', 'date' => $day->toDateString(), 'branch_id' => $branch->id, 'employee_id' => request('employee_id')]) }}" @class(['min-h-36 border-b border-r border-border p-3 text-xs hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-800', 'opacity-40' => $day->month !== $date->month, 'bg-amber-50/60 dark:bg-amber-500/5' => $holidays->has($day->toDateString())])>
                    <span class="flex justify-between"><strong class="{{ $day->isToday() ? 'text-primary' : '' }}">{{ $day->day }}</strong><span class="{{ $short ? 'text-red-600' : 'text-emerald-600' }}">{{ $short ? '⚠ ' : '' }}{{ $dayShifts->whereNotNull('starts_at')->count() }} on</span></span>
                    @if($holiday = $holidays->get($day->toDateString()))<span class="mt-2 block font-semibold text-amber-700">{{ $holiday->name }}</span><span class="block text-[10px] text-amber-700">{{ $holiday->type }} · review premium pay</span>@endif
                    @foreach(['rest' => 'Rest', 'leave' => 'Leave'] as $type => $label)@php($people = $dayShifts->where('template', $type)->map(fn ($shift) => $shift->employee?->name)->filter())@if($people->isNotEmpty())<span class="mt-2 block text-muted">{{ $label }}: {{ $people->implode(', ') }}</span>@endif @endforeach
                    <span class="mt-2 block text-[10px] uppercase text-muted">{{ $dayShifts->contains(fn ($shift) => $planner::isDraft($shift)) ? 'Draft' : ($dayShifts->isNotEmpty() ? 'Published' : 'Not scheduled') }}</span>
                </a>
            @endforeach</div>
        </div>
    @else
        @include('admin.scheduling.day')
    @endif
    @if($canEdit)
        @include('admin.scheduling.management')
        @include('admin.scheduling.editor')
    @endif
</div>
@endsection
