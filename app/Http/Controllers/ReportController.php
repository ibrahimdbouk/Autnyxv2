<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Reporting\ReportDataService;
use App\Services\Reporting\ReportExcelWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * ReportController — PDF (exec summary) + Excel (full detail) downloads for the
 * four report types, scoped to a tenant and a date range. Auth-protected and
 * tenant-checked via canAccessTenant.
 */
class ReportController extends Controller
{
    public function download(string $type, string $format, ReportDataService $data, ReportExcelWriter $excel): Response
    {
        abort_unless(in_array($type, ReportDataService::TYPES, true), 404);
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);

        $user   = auth()->user();
        $tenant = Tenant::findOrFail((int) request('tenant'));
        abort_unless($user && $user->canAccessTenant($tenant), 403);

        [$from, $to] = $data->clampRange($tenant->id, request('from'), request('to'));
        $payload = $data->build($type, $tenant->id, $from, $to);

        $filename = 'autnyx-' . $type . '-' . $from->format('Ymd') . '-' . $to->format('Ymd');

        if ($format === 'pdf') {
            return Pdf::loadView('reports.pdf.generic', ['r' => $payload])
                ->setPaper('a4', 'portrait')
                ->download($filename . '.pdf');
        }

        return $excel->download($payload, $filename . '.xlsx');
    }
}
