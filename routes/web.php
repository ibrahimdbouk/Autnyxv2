<?php

use App\Http\Controllers\AnomalyReportController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InboundEmailController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Safety net: Laravel's AuthenticateSession middleware redirects to a route
// named `login` when it force-logs-out a session; Filament names its own login
// routes per-panel, so without this a forced logout 500s. Points at the tenant
// panel login.
Route::get('/login', fn () => redirect('/admin/login'))->name('login');

// 1b — per-tenant OIDC single sign-on (session-backed state/nonce via `web`).
Route::prefix('sso')->group(function () {
    Route::get('/start', [SsoController::class, 'start'])->name('sso.start');
    Route::post('/start', [SsoController::class, 'discover'])->name('sso.discover');
    Route::get('/{tenant}/redirect', [SsoController::class, 'redirect'])->name('sso.redirect');
    Route::get('/{tenant}/callback', [SsoController::class, 'callback'])->name('sso.callback');
});

// WP5.4 — signed, login-free unsubscribe link in every anomaly digest.
Route::get('/digest/unsubscribe/{kind}/{id}', \App\Http\Controllers\DigestUnsubscribeController::class)
    ->middleware('signed')->whereIn('kind', ['user', 'tenant'])->whereNumber('id')->name('digest.unsubscribe');

// W11 — "Real / Not real" from the digest: signed per anomaly, answer and recipient.
Route::get('/feedback/{anomaly}/{verdict}', [\App\Http\Controllers\AnomalyFeedbackLinkController::class, 'show'])
    ->middleware(['signed', 'throttle:60,1'])->whereNumber('anomaly')->whereIn('verdict', ['real', 'not_real'])->name('feedback.show');
Route::post('/feedback/{anomaly}/{verdict}', [\App\Http\Controllers\AnomalyFeedbackLinkController::class, 'store'])
    ->middleware(['signed', 'throttle:60,1'])->whereNumber('anomaly')->whereIn('verdict', ['real', 'not_real'])->name('feedback.store');

// WP7.4 (D12) — Data Readiness and the AI "Data Quality" page are sections of
// Data Health now; old links and bookmarks land on the right section.
Route::get('/admin/{tenant}/data-readiness', fn (string $tenant) => redirect('/admin/' . $tenant . '/data-health#readiness', 301))
    ->where('tenant', '[A-Za-z0-9_-]+');
Route::get('/admin/{tenant}/data-quality', fn (string $tenant) => redirect('/admin/' . $tenant . '/data-health#ai-check', 301))
    ->where('tenant', '[A-Za-z0-9_-]+');

// WP7.3 — Content-Security-Policy violation reports (browser-sent; CSRF-exempt, throttled).
Route::post('/csp-report', \App\Http\Controllers\CspReportController::class)
    ->middleware('throttle:60,1')->name('csp.report');

// Inbound email → investigation comment (secret-protected, CSRF-exempt).
Route::post('/webhooks/inbound-email', [InboundEmailController::class, 'handle'])
    ->name('webhooks.inbound-email');

// 2c — super-admin impersonation ("enter tenant as admin"). Kept off the /ops
// path prefix so it never collides with the ops panel's own routes.
Route::middleware(['auth'])->group(function () {
    Route::get('/impersonate/leave', [ImpersonationController::class, 'stop'])->name('ops.leave-impersonation');
    // WP2.4 (audit L7): a short-lived SIGNED link, minted only by the confirmed
    // "Enter" action — a link on another site can no longer start impersonation.
    Route::get('/impersonate/{tenant}', [ImpersonationController::class, 'start'])->middleware('signed')->name('ops.impersonate');
});

// WP2.2 (audit H12/M7) — bind a Teams connection to a Microsoft 365 tenant by
// a signed admin sign-in + consent (never a typed-in tenant id).
Route::middleware(['auth'])->group(function () {
    Route::get('/teams/connect/{tenant}', [\App\Http\Controllers\TeamsConsentController::class, 'connect'])->name('teams.consent.connect');
    Route::get('/teams/consent/callback', [\App\Http\Controllers\TeamsConsentController::class, 'callback'])->name('teams.consent.callback');
});

// Reports & PDF exports — protected by Filament's auth middleware
// W10: blank import templates (field names only — no tenant data).
Route::middleware(['auth'])->get('/import-templates/{type}.csv', \App\Http\Controllers\ImportTemplateController::class)->name('import-template');

Route::middleware(['auth'])->group(function () {
    Route::get('/anomaly/{id}/report.pdf', [AnomalyReportController::class, 'download'])
        ->name('anomaly.report.pdf');

    Route::get('/investigation/{id}/report.pdf', [\App\Http\Controllers\InvestigationReportController::class, 'download'])
        ->name('investigation.report.pdf');

    // Reporting page downloads: /reports/{type}/{format}?tenant=&from=&to=
    Route::get('/reports/{type}/{format}', [ReportController::class, 'download'])
        ->whereIn('type', \App\Services\Reporting\ReportDataService::TYPES)
        ->whereIn('format', ['pdf', 'xlsx'])
        ->name('reports.download');
});
