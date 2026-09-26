<?php

namespace App\Http\Controllers;

use App\Models\Anomaly;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Anomaly\AnomalyFeedback;
use Illuminate\Http\Request;

/**
 * W11 — "Real / Not real" straight from the digest e-mail.
 *
 * The link is signed for one anomaly, one answer and one recipient, and
 * expires after 14 days. Opening it only shows a confirmation page (mail
 * scanners that pre-fetch links change nothing); the answer is saved when the
 * person presses the button, which posts back to the same signed address.
 */
class AnomalyFeedbackLinkController extends Controller
{
    public function show(Request $request, int $anomaly, string $verdict)
    {
        [$a, $who] = $this->resolve($request, $anomaly);
        $label = $verdict === AnomalyFeedback::REAL ? 'a real problem' : 'not a real problem';

        return response($this->page(
            'Is this ' . e($label) . '?',
            '<p style="color:#374151;line-height:1.5">' . e($a->description) . '</p>'
            . ($a->feedback ? '<p style="color:#6b7280">Current answer: <strong>' . ($a->feedback === 'real' ? 'real' : 'not real') . '</strong>.</p>' : '')
            . '<form method="post" action="' . e($request->fullUrl()) . '">'
            . '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">'
            . '<button type="submit" style="background:#6d28d9;color:#fff;border:0;border-radius:8px;padding:.7rem 1.4rem;font-size:1rem;cursor:pointer">'
            . ($verdict === AnomalyFeedback::REAL ? 'Yes — it is real' : 'Confirm — not real') . '</button></form>'
            . '<p style="color:#9ca3af;font-size:.8rem;margin-top:1.5rem">Answering as ' . e($who) . '.</p>'
        ));
    }

    public function store(Request $request, int $anomaly, string $verdict, AnomalyFeedback $feedback)
    {
        [$a, $who, $user] = $this->resolve($request, $anomaly);
        $feedback->record($a, $verdict, $user, AnomalyFeedback::VIA_EMAIL, $who);

        return response($this->page('Thank you', '<p style="color:#374151">Your answer is saved. It is how Autnyx measures — and improves — its accuracy on your data.</p>'));
    }

    /** @return array{0:Anomaly, 1:string, 2:?User} */
    private function resolve(Request $request, int $anomalyId): array
    {
        $a = Anomaly::query()->findOrFail($anomalyId);
        [$kind, $id] = array_pad(explode(':', (string) $request->query('r'), 2), 2, null);
        $user = null;
        if ($kind === 'user' && ($user = User::find((int) $id)) && (int) $user->tenant_id === (int) $a->tenant_id && $user->isActive()) {
            $who = $user->email;
        } elseif ($kind === 'tenant' && (int) $id === (int) $a->tenant_id && ($t = Tenant::find((int) $id))) {
            $user = null;
            $who = 'the ' . $t->name . ' notification address';
        } else {
            abort(403);
        }

        return [$a, $who, $user];
    }

    private function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e(strip_tags($title)) . ' — Autnyx</title>'
            . '<body style="font-family:-apple-system,Segoe UI,sans-serif;background:#f3f4f6;margin:0">'
            . '<div style="max-width:34rem;margin:4rem auto;background:#fff;border-radius:12px;padding:2rem;box-shadow:0 1px 3px rgba(0,0,0,.1)">'
            . '<h1 style="font-size:1.3rem;margin:0 0 1rem">' . $title . '</h1>' . $body . '</div></body></html>';
    }
}
