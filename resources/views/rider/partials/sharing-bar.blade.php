{{--
    The location switch, pinned to the bottom of the rider's screen.

    Sharing is explicit and always visible: a rider can see at a glance whether
    they are broadcasting, and can stop with one tap. Nothing here starts
    tracking on its own.
--}}
<div class="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur dark:border-gray-800 dark:bg-[#241a13]/95">
    <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-3">
        <span class="relative flex h-3 w-3 shrink-0">
            <span x-show="sharing" x-cloak
                  class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
            <span class="relative inline-flex h-3 w-3 rounded-full"
                  :class="sharing ? 'bg-emerald-500' : 'bg-slate-400'"></span>
        </span>

        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold"
               x-text="sharing ? 'Sharing your location' : 'Location off'"></p>
            <p class="truncate text-xs text-muted" x-text="statusLine"></p>
        </div>

        <button type="button" @click="toggle()"
                class="inline-flex h-12 shrink-0 touch-manipulation items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold text-white transition"
                :class="sharing ? 'bg-slate-600 hover:bg-slate-700' : 'bg-emerald-600 hover:bg-emerald-700'">
            <span data-lucide="navigation" class="h-4 w-4"></span>
            <span x-text="sharing ? 'Stop' : 'Go live'"></span>
        </button>
    </div>
</div>
