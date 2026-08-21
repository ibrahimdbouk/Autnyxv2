<?php

use App\Http\Controllers\AnomalyReportController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
