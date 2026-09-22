<?php

namespace App\Providers;

use App\Models\Inventory;
use App\Models\SystemSetting;
use App\Support\PublicUpload;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RedirectIfAuthenticated::redirectUsing(function (Request $request) {
            return Auth::guard('customer')->check() && ! Auth::guard('web')->check()
                ? route('customer.bookings.index')
                : route('dashboard');
        });

        View::composer('*', function ($view) {
            $request = request();

            if (! $request->attributes->has('app_shared_view_data')) {
                $settings = null;

                try {
                    if (Schema::hasTable('system_settings')) {
                        $settings = SystemSetting::current();
                    }
                } catch (\Throwable) {
                    $settings = null;
                }

                $businessName = $settings?->business_name ?: config('app.name', 'Laundry System');
                $uploadedLogo = PublicUpload::url($settings?->business_logo);

                if ($uploadedLogo) {
                    $businessLogo = $uploadedLogo;

                    // Append settings updated timestamp to force-refresh when settings change
                    try {
                        $settingsStamp = $settings?->updated_at?->timestamp ?? time();
                    } catch (\Throwable $e) {
                        $settingsStamp = time();
                    }

                    $businessLogo .= (str_contains($businessLogo, '?') ? '&' : '?').'ts='.$settingsStamp;
                } else {
                    $default = asset('logo.png');
                    try {
                        $stamp = file_exists(public_path('logo.png')) ? filemtime(public_path('logo.png')) : time();
                    } catch (\Throwable $e) {
                        $stamp = time();
                    }

                    try {
                        $settingsStamp = $settings?->updated_at?->timestamp ?? time();
                    } catch (\Throwable $e) {
                        $settingsStamp = time();
                    }

                    $businessLogo = $default.'?v='.$stamp.'&ts='.$settingsStamp;
                }

                $lowStockNotifications = collect();
                $lowStockNotificationCount = 0;
                $user = Auth::user();

                try {
                    if ($user && $user->hasMenuAccess('inventory') && Schema::hasTable('inventories')) {
                        $lowStockQuery = Inventory::query()
                            ->with('branch:id,name')
                            ->where('is_active', true)
                            ->when(! $user->isAdmin(), fn ($query) => $query->where('branch_id', $user->branch_id))
                            ->whereColumn('quantity', '<=', 'reorder_level');
                        $lowStockNotificationCount = (clone $lowStockQuery)->count();
                        $lowStockNotifications = $lowStockQuery
                            ->orderByDesc('updated_at')
                            ->limit(10)
                            ->get();
                    }
                } catch (\Throwable) {
                    $lowStockNotifications = collect();
                }

                $request->attributes->set('app_shared_view_data', [
                    'appSettings' => $settings,
                    'appSystemName' => config('app.name', 'Cane & Cotton Laundry'),
                    'appBusinessName' => $businessName,
                    'appBusinessLogo' => $businessLogo,
                    'appPrimaryColor' => $settings?->primary_color ?: SystemSetting::DEFAULT_PRIMARY_COLOR,
                    'appDarkModeDefault' => (bool) ($settings?->dark_mode_default ?? false),
                    'lowStockNotifications' => $lowStockNotifications,
                    'lowStockNotificationCount' => $lowStockNotificationCount,
                ]);
            }

            $view->with($request->attributes->get('app_shared_view_data'));
        });
    }
}
