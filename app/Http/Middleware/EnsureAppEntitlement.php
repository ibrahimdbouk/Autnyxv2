<?php

namespace App\Http\Middleware;

use App\Support\Apps\AppGate;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WP7.4 (audit L11) — a screen of an app the tenant does not hold is a 403,
 * by URL and on every Livewire update (registered as persistent tenant
 * middleware), not merely missing from the navigation.
 */
class EnsureAppEntitlement
{
    public function handle(Request $request, Closure $next): Response
    {
        $class = $request->route()?->getControllerClass();

        if (! AppGate::allows(Filament::getTenant(), $class)) {
            abort(403, 'Your organisation does not have this app.');
        }

        return $next($request);
    }
}
