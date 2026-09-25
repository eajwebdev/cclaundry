<?php

use Illuminate\Foundation\Application;
use App\Http\Middleware\EnsureBranchBillingAccess;
use App\Http\Middleware\EnsureAttendanceEmployeeAuthenticated;
use App\Http\Middleware\EnsureSystemNotUnderMaintenance;
use App\Http\Middleware\EnsureSystemSettingsCompleted;
use App\Http\Middleware\EnsureMenuAccess;
use App\Http\Middleware\EnsureRider;
use App\Http\Middleware\EnsureNotRider;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));

        $middleware->alias([
            'attendance.employee' => EnsureAttendanceEmployeeAuthenticated::class,
            'billing.access' => EnsureBranchBillingAccess::class,
            'system.maintenance' => EnsureSystemNotUnderMaintenance::class,
            'settings.completed' => EnsureSystemSettingsCompleted::class,
            'menu.access' => EnsureMenuAccess::class,
            'super.admin' => EnsureSuperAdmin::class,
            'rider' => EnsureRider::class,
            'not.rider' => EnsureNotRider::class,
        ]);

        // PayMongo posts to the webhook without a CSRF token.
        $middleware->validateCsrfTokens(except: [
            'webhooks/paymongo',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * A stale CSRF token — the page sat open past the session lifetime, or
         * another tab signed in and rotated it — otherwise throws a finished
         * booking away behind Laravel's blank "Page Expired" screen. Hand the
         * form back with everything that was typed and say what happened.
         *
         * Laravel has already turned TokenMismatchException into a 419
         * HttpException by the time render callbacks run, hence the status check.
         */
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            return back()
                ->withInput($request->except(['_token', 'password', 'password_confirmation']))
                ->with('error', 'Your session timed out while the page was open. Nothing was lost — please check the details and submit again.');
        });
    })->create();
