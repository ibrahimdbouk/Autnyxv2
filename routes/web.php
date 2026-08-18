<?php

use App\Http\Controllers\AnomalyReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Anomaly PDF report — protected by Filament's auth middleware
Route::middleware(['auth'])->group(function () {
    Route::get('/anomaly/{id}/report.pdf', [AnomalyReportController::class, 'download'])
        ->name('anomaly.report.pdf');
});
