<?php

namespace App\Http\Controllers;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Illuminate\Http\Response;

class AnomalyReportController extends Controller
{
    public function download(int $id): Response
    {
        $anomaly = Anomaly::with([
            'investigation',
            'investigation.assignedTeam',
            'investigation.evidence',
            'investigation.outcome',
        ])->findOrFail($id);

        // Tenant scope: user must belong to this anomaly's tenant
        $user = auth()->user();
        abort_unless($user && $user->canAccessTenant($anomaly->tenant), 403);

        $pdf = Pdf::loadView('pdf.anomaly-report', [
            'anomaly'        => $anomaly,
            'investigation'  => $anomaly->investigation,
            'ruleLabel'      => AnomalySetting::RULES[$anomaly->rule_type]['label'] ?? $anomaly->rule_type,
            'generatedAt'    => now()->format('d M Y, H:i'),
            'currencyPrefix' => \App\Support\Money::prefix($anomaly->tenant->currency ?? null),
        ])->setPaper('a4', 'portrait');

        $filename = 'anomaly-report-' . $anomaly->id . '-' . now()->format('Ymd') . '.pdf';

        return $pdf->download($filename);
    }
}
