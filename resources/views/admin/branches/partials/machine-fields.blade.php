{{--
    Washing machine count plus which machine numbers are Titans (15 kg).
    Unticked machines are Giants (10 kg), per the Machine Load Guide.
    Expects: $machineCount (int), $titanNumbers (list<int>), $help (string)
--}}
@php($oldTitans = collect(old('titan_machines', $titanNumbers))->map(fn ($number) => (int) $number)->values()->all())
<div x-data="{ count: @js((int) $machineCount), titans: @js($oldTitans) }" class="space-y-3">
    <div>
        <label class="mb-1.5 block text-sm font-medium">Washing Machines</label>
        <input type="number" min="0" max="100" name="machine_count" x-model.number="count" value="{{ (int) $machineCount }}" placeholder="5" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
        <p class="mt-1 text-xs text-muted">{{ $help }}</p>
    </div>

    <div>
        <input type="hidden" name="titan_machines_present" value="1">
        <p class="mb-1.5 text-sm font-medium">Titan Machines</p>
        <div class="flex flex-wrap gap-1.5" x-show="count > 0">
            <template x-for="machine in Array.from({ length: Math.min(Math.max(count || 0, 0), 100) }, (_, index) => index + 1)" :key="machine">
                <label class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border border-border px-2.5 text-xs font-semibold dark:border-gray-700" :class="titans.includes(machine) ? 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' : ''">
                    <input type="checkbox" name="titan_machines[]" :value="machine" x-model.number="titans" class="h-3.5 w-3.5 rounded border-border text-primary">
                    <span x-text="'#' + machine"></span>
                    <span class="text-[10px] font-medium uppercase" x-text="titans.includes(machine) ? 'Titan' : 'Giant'"></span>
                </label>
            </template>
        </div>
        <p class="mt-1 text-xs text-muted" x-show="count > 0">Tick the Titan washers (rated 15 kg). Unticked machines are Giant (rated 10 kg). Cycle Monitoring shows the size on each machine.</p>
        <p class="text-xs text-muted" x-show="! count">Add machines above to choose the Titan washers.</p>
    </div>
</div>
