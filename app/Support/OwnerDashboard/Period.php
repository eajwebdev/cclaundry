<?php

namespace App\Support\OwnerDashboard;

use App\Models\Branch;
use App\Models\ZReading;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** The validated from/to/branch filter shared by every owner-dashboard endpoint. */
final class Period
{
    public readonly int $calendarDays;

    public readonly int $openDays;

    public readonly string $bucket;

    /** @var list<string> */
    private readonly array $openDates;

    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $branchId,
    ) {
        $this->calendarDays = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $this->bucket = match (true) {
            $this->calendarDays <= 31 => 'day',
            $this->calendarDays <= 120 => 'week',
            default => 'month',
        };
        $this->openDates = ZReading::query()
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->pluck('business_date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->unique()->sort()->values()->all();
        $this->openDays = count($this->openDates);
    }

    public static function make(string $from, string $to, ?int $branchId): self
    {
        return new self($from, $to, $branchId);
    }

    public static function fromRequest(Request $request): self
    {
        $today = now()->toDateString();
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', $today);

        foreach ([$from, $to] as $date) {
            $parsed = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : false;
            abort_if(! $parsed || $parsed->toDateString() !== $date, 400, 'Dates must be valid YYYY-MM-DD.');
        }
        abort_if($to < $from, 400, '"to" must not be before "from".');
        abort_if($to > $today, 400, '"to" must not be after today.');

        $branch = $request->query('branch', 'all');
        $branchId = null;
        if ($branch !== 'all') {
            abort_unless(is_string($branch) && ctype_digit($branch)
                && Branch::query()->whereKey((int) $branch)->where('is_active', true)->exists(), 400, 'Unknown or inactive branch.');
            $branchId = (int) $branch;
        }

        return new self($from, $to, $branchId);
    }

    /** @return list<array{label: string, start: string, end: string}> */
    public function buckets(): array
    {
        $to = CarbonImmutable::parse($this->to);
        $out = [];
        $start = CarbonImmutable::parse($this->from);

        while ($start <= $to) {
            $end = match ($this->bucket) {
                'day' => $start,
                // Saturday to Friday: days until the next Friday (0 when $start is one).
                'week' => $start->addDays((CarbonImmutable::FRIDAY - $start->dayOfWeek + 7) % 7),
                'month' => $start->endOfMonth()->startOfDay(),
            };
            $end = $end > $to ? $to : $end;
            $out[] = [
                'label' => match ($this->bucket) {
                    'day' => $start->format('j'),
                    'week' => $start->month === $end->month ? $start->format('M j').'–'.$end->format('j') : $start->format('M j').' – '.$end->format('M j'),
                    'month' => $start->format('M Y'),
                },
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ];
            $start = $end->addDay();
        }

        return $out;
    }

    /** @return list<string> */
    public function openDates(): array
    {
        return $this->openDates;
    }

    public function perOpenDay(float $total): float
    {
        return $this->openDays > 0 ? $total / $this->openDays : 0.0;
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'calendar_days' => $this->calendarDays,
            'open_days' => $this->openDays,
            'bucket' => $this->bucket,
            'branch' => $this->branchId ?? 'all',
        ];
    }
}
