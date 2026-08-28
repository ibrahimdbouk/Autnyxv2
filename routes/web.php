<?php

use App\Http\Controllers\AnomalyReportController;
use App\Http\Controllers\InboundEmailController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 1b — per-tenant OIDC single sign-on (session-backed state/nonce via `web`).
Route::prefix('sso')->group(function () {
    Route::get('/start', [SsoController::class, 'start'])->name('sso.start');
    Route::post('/start', [SsoController::class, 'discover'])->name('sso.discover');
    Route::get('/{tenant}/redirect', [SsoController::class, 'redirect'])->name('sso.redirect');
    Route::get('/{tenant}/callback', [SsoController::class, 'callback'])->name('sso.callback');
});

// Inbound email → investigation comment (secret-protected, CSRF-exempt).
Route::post('/webhooks/inbound-email', [InboundEmailController::class, 'handle'])
    ->name('webhooks.inbound-email');

// Reports & PDF exports — protected by Filament's auth middleware
Route::middleware(['auth'])->group(function () {
    Route::get('/anomaly/{id}/report.pdf', [AnomalyReportController::class, 'download'])
        ->name('anomaly.report.pdf');

    // Reporting page downloads: /reports/{type}/{format}?tenant=&from=&to=
    Route::get('/reports/{type}/{format}', [ReportController::class, 'download'])
        ->whereIn('type', ['recovery', 'investigations', 'anomalies', 'data-health'])
        ->whereIn('format', ['pdf', 'xlsx'])
        ->name('reports.download');
});
