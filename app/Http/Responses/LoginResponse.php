<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
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
        $panels = Filament::getPanels();

        if ($user && $user->is_super_admin && isset($panels['ops'])) {
            return redirect()->to($panels['ops']->getUrl());
        }

        $panel = Filament::getCurrentPanel() ?? Filament::getDefaultPanel();

        return redirect()->intended($panel->getUrl());
    }
}
