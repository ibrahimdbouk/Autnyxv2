<?php

namespace App\Http\Controllers;

use App\Models\Import;
use App\Services\Onboarding\OnboardingService;

/**
 * W10 (WP10.5) — a blank CSV per data type, headed with the field names the
 * mapper recognises exactly (required fields first). Carries no data.
 */
class ImportTemplateController extends Controller
{
    public function __invoke(string $type)
    {
        abort_unless(array_key_exists($type, Import::dataTypeLabels()) && $type !== Import::TYPE_USERS, 404);

        return response(OnboardingService::template($type), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="autnyx-' . $type . '-template.csv"',
        ]);
    }
}
