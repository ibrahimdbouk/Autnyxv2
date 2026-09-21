<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Import;
use App\Services\Integrations\PipelineIngestor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Push data INTO Autnyx (scope write:ingest). Rows run through the standard import
 * pipeline — same validation, auto-mapping, and async detection as any other import.
 */
class IngestController extends BaseApiController
{
    public function store(Request $request, PipelineIngestor $ingestor)
    {
        $data = Validator::make($request->all(), [
            'data_type' => ['required', 'string', 'in:' . implode(',', array_keys(Import::dataTypeLabels()))],
            'rows'      => ['required', 'array', 'min:1', 'max:' . PipelineIngestor::MAX_ROWS],
            'rows.*'    => ['array'],
        ])->validate();

        $import = $ingestor->ingestRows(
            $this->tenantId($request),
            $data['data_type'],
            $data['rows'],
            'apiingest',
        );

        if (! $import) {
            return response()->json(['error' => 'no_rows', 'message' => 'No rows were ingested.'], 422);
        }

        return response()->json([
            'import_id'     => $import->id,
            'data_type'     => $import->data_type,
            'status'        => $import->status,
            'total_rows'    => (int) $import->total_rows,
            'imported_rows' => (int) $import->imported_rows,
            'failed_rows'   => (int) $import->failed_rows,
        ], 201);
    }
}
