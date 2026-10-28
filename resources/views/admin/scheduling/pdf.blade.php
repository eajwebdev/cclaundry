<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Staff schedule · {{ $branch->name }}</title>
    <style>
        @page { margin: 25px 26px 36px; }
        body { font-family: DejaVu Sans, sans-serif; color: #292524; font-size: 9px; line-height: 1.4; }
        h1 { margin: 2px 0 4px; font-size: 19px; }
        h2 { margin: 14px 0 5px; font-size: 11px; }
        p { margin: 3px 0; }
        .brand { color: #906442; font-size: 10px; font-weight: bold; }
        .muted { color: #78716c; }
        .meta { margin-bottom: 12px; }
        .new-page { page-break-before: always; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid #d6d3d1; padding: 7px 5px; text-align: left; vertical-align: top; word-wrap: break-word; }
        th { background: #f5f1ec; font-size: 9px; }
        .employee { width: 16%; }
        .total { width: 9%; }
        .day { width: {{ 75 / max(1, min(7, $days->count())) }}%; }
        .shift { font-weight: bold; }
        .draft { color: #92400e; }
        .state { margin-top: 4px; font-size: 7px; text-transform: uppercase; }
        .holiday { margin-top: 3px; color: #92400e; font-size: 7px; font-weight: normal; }
        .note { margin-bottom: 5px; white-space: pre-wrap; word-wrap: break-word; }
        footer { position: fixed; bottom: -24px; left: 0; right: 0; border-top: 1px solid #e7e5e4; padding-top: 5px; color: #78716c; font-size: 7px; }
        .page-number:after { content: counter(page); }
    </style>
</head>
<body>
@php($planner = \App\Support\StaffScheduling::class)
<footer>{{ $branch->name }} · Generated {{ now()->format('M j, Y g:i A') }} · Page <span class="page-number"></span></footer>
@foreach($days->chunk(7) as $weekDays)
    @php($weekShifts = $byCell->filter(fn ($shift) => $employees->contains('id', $shift->attendance_employee_id) && $weekDays->contains(fn ($day) => $day->isSameDay($shift->work_date))))
    @php($hasDrafts = $weekShifts->contains(fn ($shift) => $planner::isDraft($shift)))
    <div class="{{ $loop->first ? '' : 'new-page' }}">
        <p class="brand">{{ $appBusinessName }}</p>
        <h1>{{ ucfirst($view) }} schedule · {{ $branch->name }}</h1>
        <div class="meta">
            @if($view === 'month')<p>{{ $date->format('F Y') }}</p>@endif
            <p>{{ $weekDays->first()->format('l, M j, Y') }}{{ $weekDays->count() > 1 ? ' – '.$weekDays->last()->format('l, M j, Y') : '' }}</p>
            <p class="{{ $hasDrafts ? 'draft' : 'muted' }}">{{ $weekShifts->isEmpty() ? 'No shifts for this selection.' : ($hasDrafts ? 'Contains draft shifts — not yet shared with staff. Draft cells are labeled below.' : 'Published schedule / no draft changes.') }}</p>
            @if(request()->filled('employee_id'))<p class="muted">Employee filter: {{ $employees->first()?->name ?? 'No matching employee' }}</p>@endif
        </div>
        <table>
            <thead><tr>
                <th class="employee">Employee</th>
                @foreach($weekDays as $day)
                    <th class="day">{{ $day->format('D') }}<br>{{ $day->format('M j') }}
                        @if($holiday = $holidays->get($day->toDateString()))<div class="holiday">{{ $holiday->name }}<br>{{ ucfirst($holiday->type) }} holiday</div>@endif
                    </th>
                @endforeach
                <th class="total">Hours</th>
            </tr></thead>
            <tbody>
                @forelse($employees as $person)
                    <tr>
                        <td><strong>{{ $person->name }}</strong><div class="muted">{{ str_replace('_', ' ', ucfirst($person->schedule_role)) }}</div></td>
                        @foreach($weekDays as $day)
                            @php($shift = $byCell->get($person->id.':'.$day->toDateString()))
                            <td>
                                @if($shift)
                                    @php($data = $planner::snapshot($shift))
                                    <div class="shift">{{ $templates[$data['template']]['label'] }}</div>
                                    @if($data['starts_at'])<div>{{ $data['starts_at'] }}–{{ $data['ends_at'] }}</div>@endif
                                    @if($data['break_minutes'])<div class="muted">Break {{ $data['break_starts_at'] }}<br>{{ $data['break_minutes'] }} min unpaid</div>@endif
                                    <div class="state {{ $planner::isDraft($shift) ? 'draft' : 'muted' }}">{{ $planner::isDraft($shift) ? 'Draft' : 'Published' }}</div>
                                @else <span class="muted">Not scheduled</span> @endif
                            </td>
                        @endforeach
                        <td><strong>{{ number_format($weekShifts->where('attendance_employee_id', $person->id)->sum(fn ($shift) => $planner::paidHours($planner::snapshot($shift))), 1) }} h</strong></td>
                    </tr>
                @empty<tr><td colspan="{{ $weekDays->count() + 2 }}" class="muted">No employees match this selection.</td></tr>@endforelse
            </tbody>
        </table>
        <p class="muted">Hours exclude scheduled unpaid breaks. All times are local to {{ config('app.timezone') }}.</p>
        @if($weekShifts->contains(fn ($shift) => filled($shift->note)))
            <h2>Shift notes</h2>
            @foreach($weekShifts->filter(fn ($shift) => filled($shift->note))->sortBy('work_date') as $shift)
                <p class="note"><strong>{{ $shift->work_date->format('D, M j') }} · {{ $shift->employee?->name }}:</strong> {{ $shift->note }}</p>
            @endforeach
        @endif
    </div>
@endforeach
</body>
</html>
