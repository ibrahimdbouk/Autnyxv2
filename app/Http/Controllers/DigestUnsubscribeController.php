<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * WP5.4 — the signed unsubscribe link in every digest. A person's link turns
 * off their own digest; the organisation-address link stops mail to that
 * address (an admin can turn it back on in settings). No login needed — the
 * signature is the authorisation, and it only ever turns mail OFF.
 */
class DigestUnsubscribeController extends Controller
{
    public function __invoke(Request $request, string $kind, int $id)
    {
        if ($kind === 'user') {
            User::whereKey($id)->update(['digest_opt_in' => false]);
        } elseif ($kind === 'tenant' && ($tenant = Tenant::find($id))) {
            $settings = (array) ($tenant->settings ?? []);
            $settings['digest_email_off'] = true;
            $tenant->forceFill(['settings' => $settings])->save();
        } else {
            abort(404);
        }

        return response('<!doctype html><meta charset="utf-8"><title>Unsubscribed</title>'
            . '<p style="font-family:sans-serif;max-width:32rem;margin:4rem auto">You will no longer receive the Autnyx anomaly digest at this address.</p>');
    }
}
