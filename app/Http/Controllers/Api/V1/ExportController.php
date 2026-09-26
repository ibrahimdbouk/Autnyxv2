<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Api\BiExport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * W13 — BI exports: flat tables for Power BI and other BI tools.
 *
 *   GET /api/v1/exports                     the datasets
 *   GET /api/v1/exports/{dataset}           JSON page: {data, next_after, next}
 *       ?since=2026-09-01T00:00:00Z         only rows changed since then
 *       ?after=<id>&limit=<n ≤ 10000>       page by id
 *   GET /api/v1/exports/{dataset}?format=csv   the whole table as CSV (streamed)
 *
 * Power BI: Get data → Web → Advanced, URL …/exports/findings?format=csv,
 * header X-Api-Key = the key (Anonymous authentication), then refresh on a
 * schedule. See claude/public-api.md.
 */
class ExportController extends BaseApiController
{
    public function index(Request $request)
    {
        $base = rtrim(config('app.url'), '/') . '/api/v1/exports/';
        $data = [];
        foreach (BiExport::catalogue() as $key => $d) {
            $data[] = ['dataset' => $key, 'label' => $d['label'], 'description' => $d['description'],
                'incremental' => ! ($d['computed'] ?? false), 'json' => $base . $key, 'csv' => $base . $key . '?format=csv',
                'columns' => app(BiExport::class)->columns($key)];
        }

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $dataset, BiExport $export)
    {
        if (! BiExport::exists($dataset)) {
            return response()->json(['message' => 'Unknown dataset. See /api/v1/exports.'], 404);
        }
        $tenantId = $this->tenantId($request);
        $csv = strtolower((string) $request->query('format', 'json')) === 'csv';
        try {
            $since = $request->filled('since') ? Carbon::parse((string) $request->query('since')) : null;
        } catch (\Throwable) {
            return response()->json(['message' => 'since must be a date or date-time (ISO 8601).'], 422);
        }
        $filename = $dataset . '-' . now()->format('Ymd-His') . '.csv';

        if (BiExport::computed($dataset)) {
            $rows = $export->computedRows($dataset, $tenantId);
            if ($csv) {
                return response()->streamDownload(function () use ($rows, $export, $dataset) {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, $export->columns($dataset));
                    foreach ($rows as $r) {
                        fputcsv($out, array_map(fn ($c) => $this->cell($r[$c] ?? null), $export->columns($dataset)));
                    }
                    fclose($out);
                }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
            }

            return response()->json(['dataset' => $dataset, 'data' => $rows, 'next_after' => null, 'next' => null]);
        }

        $query = $export->query($dataset, $tenantId, $since);

        if ($csv) {
            return response()->streamDownload(function () use ($query, $export, $dataset) {
                $out = fopen('php://output', 'w');
                $cols = $export->columns($dataset);
                fputcsv($out, $cols);
                $query->chunkById(2000, function ($chunk) use ($out, $export, $dataset, $cols) {
                    foreach ($chunk as $row) {
                        $r = $export->map($dataset, $row);
                        fputcsv($out, array_map(fn ($c) => $this->cell($r[$c]), $cols));
                    }
                    flush();
                });
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $limit = max(1, min(BiExport::MAX_LIMIT, (int) $request->integer('limit', BiExport::DEFAULT_LIMIT)));
        $after = max(0, (int) $request->integer('after', 0));
        $rows = (clone $query)->where('id', '>', $after)->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit)->map(fn ($r) => $export->map($dataset, $r))->values();
        $nextAfter = $more ? $rows->last()['id'] : null;

        return response()->json([
            'dataset'    => $dataset,
            'data'       => $rows,
            'next_after' => $nextAfter,
            'next'       => $nextAfter ? $request->fullUrlWithQuery(['after' => $nextAfter]) : null,
        ]);
    }

    private function cell(mixed $v): mixed
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        // A cell starting with = + - @ is read as a formula by spreadsheet tools.
        if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($v)) {
            return "'" . $v;
        }

        return $v;
    }
}
