<?php

namespace App\Http\Controllers;

use App\Models\Investigation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Investigation → PDF export. Mirrors AnomalyReportController: tenant-scoped,
 * audited, deterministic. Renders the full investigation dossier — signals, the
 * AI narrative, evidence, actions and outcome — as a printable A4 report.
 */
class InvestigationReportController extends Controller
{
    public function download(int $id): Response
    {
        $investigation = Investigation::with([
            'anomalies',
            'evidence',
            'actions.assignedTo',
            'actions.assignedTeam',
            'assignedTeam',
            'assignedUser',
            'outcome',
            'primaryStore',
            'tenant',
        ])->findOrFail($id);

        // Tenant scope: the user must belong to this investigation's tenant.
        $user = auth()->user();
        abort_unless($user && $user->canAccessTenant($investigation->tenant), 403);

        // 3b — audit the export (SOC 2 / ISO).
        \App\Support\ExportAudit::log($investigation->tenant_id, 'investigation report #' . $investigation->id, 'pdf');

        $pdf = Pdf::loadView('pdf.investigation-report', [
            'investigation'  => $investigation,
            'generatedAt'    => now()->format('d M Y, H:i'),
            'currencyPrefix' => \App\Support\Money::prefix($investigation->tenant->currency ?? null),
        ])->setPaper('a4', 'portrait');

        $filename = 'investigation-' . $investigation->id . '-' . now()->format('Ymd') . '.pdf';

        return $pdf->download($filename);
    }
}
