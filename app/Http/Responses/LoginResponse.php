<?php

namespace App\Http\Responses;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;

/**
 * Send a super admin to the /ops control plane (the all-tenants admin view) on
 * login, instead of dropping them onto a single tenant's data dashboard.
 * Everyone else follows the normal panel redirect.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $user = auth()->user();

        if ($user && $user->is_super_admin && Filament::hasPanel('ops')) {
            return redirect()->to(Filament::getPanel('ops')->getUrl());
        }

        $panel = Filament::getCurrentPanel() ?? Filament::getDefaultPanel();

        return redirect()->intended($panel->getUrl());
    }
}
