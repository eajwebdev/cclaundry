<form method="POST" action="{{ $taskAction ?? route('admin.branches.daily-tasks.store', $branchId) }}" class="space-y-2">
    @csrf
    <input name="name" placeholder="Task name" required class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
    <label class="block text-xs text-muted">Schedule
        <select name="category" required class="mt-1 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
            @foreach(\App\Models\DailyTask::CATEGORIES as $category => $label)
                <option value="{{ $category }}" @selected('end_of_day' === $category)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <label class="inline-flex items-center gap-2 text-sm text-muted">
            <input type="hidden" name="requires_photo" value="0">
            <input type="checkbox" name="requires_photo" value="1" checked class="rounded border-border text-primary">
            Requires photo
        </label>
        <input type="hidden" name="is_active" value="1">
        <button class="inline-flex h-8 items-center gap-2 rounded-md bg-primary px-3 text-xs font-medium text-white hover:opacity-90">
            <span data-lucide="plus" class="h-3.5 w-3.5"></span>
            Add Task
        </button>
    </div>
</form>
