<div x-data="riderBookingAlerts({
        feedUrl: @js(route('rider.booking-alerts')),
        listUrl: @js(route('rider.index', ['tab' => 'collect'])),
        storageKey: @js('rider-booking-alerts:'.auth()->id()),
    })" class="relative shrink-0">
    <button type="button" @click="open = !open; acknowledge()"
            aria-label="Pickup booking alerts" title="Pickup bookings"
            class="relative inline-flex h-11 w-11 touch-manipulation items-center justify-center rounded-lg border border-border bg-white dark:border-gray-800 dark:bg-gray-900"
            :class="unacknowledged.length && 'border-primary ring-2 ring-primary/30'">
        <span data-lucide="bell" class="h-5 w-5"></span>
        <span x-cloak x-show="count > 0" x-text="count > 99 ? '99+' : count"
              class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold text-white"></span>
    </button>

    <div x-cloak x-show="open" x-transition @click.outside="open = false"
         class="fixed inset-x-2 top-16 z-50 overflow-hidden rounded-lg border border-border bg-white shadow-xl dark:border-gray-800 dark:bg-gray-900 sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-80">
        <div class="flex items-center justify-between border-b border-border px-3 py-2 dark:border-gray-800">
            <p class="text-sm font-semibold">Pickup bookings</p>
            <a :href="listUrl" class="text-xs font-semibold text-primary">To collect</a>
        </div>
        <div class="max-h-72 overflow-y-auto p-2">
            <template x-for="booking in waiting" :key="booking.id">
                <a :href="booking.url" class="mb-1 block rounded-md border border-border px-3 py-2 text-sm last:mb-0 dark:border-gray-800">
                    <p class="font-medium" x-text="booking.contact_name"></p>
                    <p class="text-xs text-muted"><span x-text="booking.reference_no"></span> · <span x-text="booking.pickup"></span></p>
                </a>
            </template>
            <p x-show="!waiting.length" class="px-3 py-5 text-center text-sm text-muted">No pickups waiting to collect.</p>
        </div>
    </div>

    <div x-cloak x-show="unacknowledged.length" x-transition role="alert"
         class="fixed inset-x-2 top-16 z-[60] rounded-lg border-2 border-primary bg-white p-4 shadow-2xl dark:bg-gray-900 sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-80">
        <p class="font-semibold" x-text="unacknowledged.length === 1 ? 'New pickup booking' : unacknowledged.length + ' new pickup bookings'"></p>
        <p class="mt-1 truncate text-sm text-muted" x-text="unacknowledged[0] ? unacknowledged[0].contact_name + ' · ' + unacknowledged[0].pickup : ''"></p>
        <button type="button" x-show="soundBlocked" @click="unlockSound()"
                class="mt-2 rounded-md bg-amber-100 px-2 py-1 text-xs font-medium text-amber-900">Tap to turn on sound</button>
        <div class="mt-3 flex gap-2">
            <a :href="unacknowledged.length === 1 ? unacknowledged[0].url : listUrl" @click="acknowledge()"
               class="inline-flex h-9 items-center rounded-md bg-primary px-3 text-sm font-semibold text-white">View pickup</a>
            <button type="button" @click="acknowledge()"
                    class="inline-flex h-9 items-center rounded-md border border-border px-3 text-sm font-medium dark:border-gray-700">Dismiss</button>
        </div>
    </div>
</div>
