<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the rider console. Riders sign in through the normal staff login but
 * live entirely inside /rider — they have no branch dashboard, no menu, and no
 * reason to reach the admin area.
 *
 * Dispatchers and admins are let through so they can see exactly what a rider
 * sees when a run goes wrong.
 */
class EnsureRider
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->isActive()) {
            return redirect()->route('login')
                ->withErrors(['login' => 'That account is not active.']);
        }

        abort_unless($user->isRider() || $user->isAdmin(), 403);

        return $next($request);
    }
}
