<?php

namespace App\Services\DataQuality;

use App\Models\CleansingRule;
use App\Models\EntityAlias;
use App\Models\Import;
use App\Models\ImportQuality;
use App\Models\Product;
use App\Models\QuarantinedRow;
use App\Models\ValueMap;

/**
 * The Data Quality Firewall — cleanse → validate → dedup → (promote | quarantine),
 * plus per-import quality/profile accounting. One instance is reused across a chunk's
 * rows within a request; state that must survive across chunks lives in the
 * `import_quality` row. Nothing reaches the canonical tables unless `screen()` returns
 * a null reason. See claude/data-quality-firewall.md.
 */
class DataQualityFirewall
{
    private ?int $preparedImportId = null;
    private array $ctx = [];
    private array $productSkus = [];
    private bool $referentialGate = false;
    private bool $strict = false;

    private array $seen = [];          // row hash → true (within this request/chunk)
    private array $reasonCounts = [];  // reason/warning → count (this request)
    private int $promoted = 0;
    private int $quarantined = 0;
    private int $cleansed = 0;

    private array $profileRows = [];   // sample buffer for the first chunk
    private int $profileCap = 2000;
    private bool $captureProfile = false;
    private ?ImportQuality $quality = null;
    private bool $duplicateBatch = false;

    /** W9 (WP9.5): personal-data columns of this import — header → kind, and mapped field → kind. */
    private array $pii = [];
    private array $piiFields = [];

    public function __construct(
        private CleansingEngine $cleanser,
        private RowValidator $validator,
        private DataProfiler $profiler,
        private Deduplicator $dedup,
        private BatchDecisionService $decider,
    ) {
    }

    /**
     * An identical file already ingested? When idempotent uploads are on, the caller
     * skips the whole batch (no re-promotion → no duplicate canonical rows).
     */
    public function isDuplicateBatch(): bool
    {
        return $this->duplicateBatch;
    }

    public static function isEnabled(): bool
    {
        return (bool) config('data_quality.enabled', true);
    }

    /** Prepare per-import context. Cheap on later chunks (no re-fingerprint, no re-create). */
    public function begin(Import $import): void
    {
        $tenantId = (int) $import->tenant_id;
        $this->prepare($import);
        $this->beginQuality($import, $tenantId);
    }

    /** Per-request state and the import's cleansing context (no writes). */
    private function prepare(Import $import): void
    {
        $tenantId = (int) $import->tenant_id;

        // Per-request state always resets (a request handles one chunk).
        $this->seen = [];
        $this->writeWarnings = [];
        $this->reasonCounts = [];
        $this->promoted = $this->quarantined = $this->cleansed = 0;
        $this->profileRows = [];
        $this->profileCap = (int) config('data_quality.profile_sample', 2000);
        $this->referentialGate = (bool) config('data_quality.referential_gate', false);
        $this->strict = (bool) config('data_quality.strict_validation', false);

        // Context is stable for the import — rebuild only when the import changes.
        if ($this->preparedImportId !== $import->id) {
            $this->ctx = [
                'aliases'    => $this->loadAliases($tenantId),
                'value_maps' => $this->loadValueMaps($tenantId, $import->data_type),
                'rules'      => $this->loadRules($tenantId, $import->data_type),
                'upper_keys' => (bool) config('data_quality.canonicalize.uppercase_keys', true),
                'strip_zeros'=> (bool) config('data_quality.canonicalize.strip_leading_zeros', false),
                // WP3.2: the import's date order + decimal mark.
                'parser'     => \App\Services\Import\ValueParser::forImport($import),
            ];
            $this->productSkus = Product::where('tenant_id', $tenantId)
                ->pluck('sku')
                ->mapWithKeys(fn ($s) => [trim((string) $s) => true])
                ->all();
            $this->preparedImportId = $import->id;
        }
    }

    private function beginQuality(Import $import, int $tenantId): void
    {
        $quality = ImportQuality::firstOrNew(['import_id' => $import->id]);
        $this->captureProfile = ! $quality->exists;
        if (! $quality->exists) {
            // Best-effort re-upload fingerprint — never let it break ingestion.
            $fingerprint = null;
            try {
                $path = app(\App\Services\Storage\TenantStorage::class)->importCopy($import);
                $fingerprint = $this->dedup->fileFingerprint($path);
            } catch (\Throwable) {
                $fingerprint = null;
            }
            $quality->fill([
                'tenant_id'         => $tenantId,
                'data_type'         => $import->data_type,
                'source'            => $this->sourceLabel($import),
                'file_fingerprint'  => $fingerprint,
                // WP3.5: only an identical file whose data is still loaded counts —
                // not one that was rolled back, cancelled, failed or abandoned.
                'is_duplicate_file' => $fingerprint !== null && ImportQuality::where('import_quality.tenant_id', $tenantId)
                    ->where('import_quality.data_type', $import->data_type)
                    ->where('import_quality.file_fingerprint', $fingerprint)
                    ->where('import_quality.import_id', '!=', $import->id)
                    ->whereHas('import', fn ($q) => $q->whereNotIn('status', [
                        Import::STATUS_ROLLED_BACK, Import::STATUS_FAILED, Import::STATUS_ABANDONED,
                    ]))
                    ->exists(),
            ])->save();
        }
        $this->quality = $quality;
        $this->loadPii($quality);

        // Idempotency: an identical file already ingested → the caller skips the batch.
        $this->duplicateBatch = $quality->is_duplicate_file
            && (bool) config('data_quality.idempotent_uploads', true);
    }

    /**
     * W9 (WP9.1): prepare a rehearsal — the same cleansing context and gates
     * as a real run (optionally with gates overridden), but nothing is read
     * from or written to the batch's quality record.
     *
     * @param  array{strict?:bool, referential_gate?:bool}  $overrides
     */
    public function beginDry(Import $import, array $overrides = []): void
    {
        $this->prepare($import);
        $this->strict          = (bool) ($overrides['strict'] ?? $this->strict);
        $this->referentialGate = (bool) ($overrides['referential_gate'] ?? $this->referentialGate);
        $this->captureProfile  = true;
        $this->quality         = null;
        $this->duplicateBatch  = false;
        $this->pii = $this->piiFields = [];
    }

    /**
     * W9 (WP9.1): what a rehearsal pass saw.
     *
     * @return array{promoted:int, cleansed:int, reason_counts:array<string,int>, profile:array}
     */
    public function dryTally(Import $import): array
    {
        return [
            'promoted'      => $this->promoted,
            'cleansed'      => $this->cleansed,
            'reason_counts' => $this->reasonCounts,
            'profile'       => $this->profileRows ? $this->profiler->profile($import->data_type, $this->profileRows) : [],
        ];
    }

    /** A rehearsal's quarantine: counted, never stored. */
    public function dryReject(string $reason): void
    {
        $this->quarantined++;
        $this->reasonCounts[$reason] = ($this->reasonCounts[$reason] ?? 0) + 1;
    }

    /** Coarse source label for the batch/source-health view (W9: the feed's source when known). */
    private function sourceLabel(Import $import): string
    {
        return $import->source ?: ($import->original_filename ? 'file' : 'api');
    }

    /**
     * W9 (WP9.5): on the first chunk, find the columns holding personal data,
     * remember them on the batch and mask the upload sample. Later chunks and
     * the write pass reuse what was found.
     *
     * @param  array<int,array<string,mixed>>  $rawRows
     * @param  array<string,?string>           $targets  source header → mapped field
     */
    public function inspectPii(Import $import, array $rawRows, array $targets): void
    {
        if (! PiiGuard::enabled() || ! $this->quality || $this->quality->pii_columns !== null) {
            return;
        }
        $found = PiiGuard::detect(array_slice($rawRows, 0, 500), $targets);
        $this->quality->pii_columns = array_map(fn ($h, $k) => ['column' => $h, 'kind' => $k, 'field' => $targets[$h] ?? null], array_keys($found), $found);
        if ($found !== []) {
            $counts = $this->quality->reason_counts ?? [];
            $counts[Reasons::PII_DETECTED] = count($found);
            $this->quality->reason_counts = $counts;
            if (is_array($import->sample_rows)) {
                $import->sample_rows = array_map(fn ($r) => is_array($r) ? PiiGuard::maskRow($r, $found) : $r, $import->sample_rows);
                $import->saveQuietly();
            }
        }
        $this->quality->save();
        $this->loadPii($this->quality);
    }

    private function loadPii(ImportQuality $q): void
    {
        $this->pii = $this->piiFields = [];
        foreach ($q->pii_columns ?? [] as $c) {
            $this->pii[$c['column']] = $c['kind'];
            if (! empty($c['field'])) {
                $this->piiFields[$c['field']] = $c['kind'];
            }
        }
    }

    /** W9 (WP9.5): a raw row with its personal-data columns masked (for anything kept for review). */
    public function maskRaw(array $raw): array
    {
        return $this->pii === [] ? $raw : PiiGuard::maskRow($raw, $this->pii);
    }

    /**
     * Cleanse then validate one mapped row.
     * @return array{data: array<string,mixed>, reason: ?string, changed: bool}
     */
    public function screen(Import $import, array $data): array
    {
        $clean   = $this->cleanser->clean($import->data_type, $data, $this->ctx);
        $data    = $clean['data'];
        $changed = $clean['changed'];

        // W9 (WP9.5): a customer reference that is an e-mail / phone / card
        // number is stored as a stable pseudonym, never as the value itself.
        if (isset($data['customer_ref']) && PiiGuard::enabled() && PiiGuard::isPersonal((string) $data['customer_ref'])) {
            $data['customer_ref'] = PiiGuard::pseudonym((int) $import->tenant_id, (string) $data['customer_ref']);
            $changed = true;
        }

        if ($this->captureProfile && count($this->profileRows) < $this->profileCap) {
            $this->profileRows[] = \App\Services\Import\ValueParser::stripMeta($data);
        }

        // Validate (required, type, referential).
        $result = $this->validator->check($import->data_type, $data, [
            'product_skus'     => $this->productSkus,
            'referential_gate' => $this->referentialGate,
            'strict'           => $this->strict,
        ]);
        foreach ($result['warnings'] as $w) {
            $this->reasonCounts[$w] = ($this->reasonCounts[$w] ?? 0) + 1;
        }
        if ($result['reason'] !== null) {
            return ['data' => $data, 'reason' => $result['reason'], 'changed' => $changed];
        }

        // Exact duplicate within this import. Hard-gated only in strict mode; otherwise
        // recorded as a warning and still promoted (pre-firewall behaviour).
        $hash = $this->dedup->rowHash($import->data_type, $data);
        if (isset($this->seen[$hash])) {
            if ($this->strict) {
                return ['data' => $data, 'reason' => Reasons::DUPLICATE_ROW, 'changed' => $changed];
            }
            $this->reasonCounts[Reasons::DUPLICATE_ROW] = ($this->reasonCounts[Reasons::DUPLICATE_ROW] ?? 0) + 1;
        }
        $this->seen[$hash] = true;

        if ($changed) {
            $this->cleansed++;
        }
        $this->promoted++;

        return ['data' => $data, 'reason' => null, 'changed' => $changed];
    }

    /** WP3.6: start a second pass over the same rows (the write pass) with clean per-pass state. */
    public function resetPass(): void
    {
        $this->seen = [];
        $this->reasonCounts = [];
        $this->writeWarnings = [];
        $this->promoted = $this->quarantined = $this->cleansed = 0;
        $this->profileRows = [];
        $this->captureProfile = false;
    }

    /** @var array<string,int> WP3.4/3.6: warnings raised by writers in the write pass. */
    private array $writeWarnings = [];

    /** WP3.4: a soft data-quality warning raised by a writer (row still promoted). */
    public function warn(string $code): void
    {
        $this->reasonCounts[$code] = ($this->reasonCounts[$code] ?? 0) + 1;
        $this->writeWarnings[$code] = ($this->writeWarnings[$code] ?? 0) + 1;
    }

    /**
     * WP3.6: in the WRITE pass the batch was already counted while screening —
     * only the writers' warnings are added to the quality record.
     */
    public function recordWarnings(Import $import): void
    {
        if ($this->writeWarnings === []) {
            return;
        }
        $q = ImportQuality::where('import_id', $import->id)->first();
        if ($q) {
            $counts = $q->reason_counts ?? [];
            foreach ($this->writeWarnings as $k => $v) {
                $counts[$k] = ($counts[$k] ?? 0) + $v;
            }
            $q->reason_counts = $counts;
            $q->save();
        }
        $this->writeWarnings = [];
    }

    public function quarantine(Import $import, array $raw, array $cleansed, string $reason, ?int $rowNumber): void
    {
        $this->quarantined++;
        $this->reasonCounts[$reason] = ($this->reasonCounts[$reason] ?? 0) + 1;

        QuarantinedRow::create([
            'tenant_id'     => $import->tenant_id,
            'import_id'     => $import->id,
            'data_type'     => $import->data_type,
            'row_number'    => $rowNumber,
            'raw_data'      => $this->maskRaw($raw),   // W9 (WP9.5)
            'cleansed_data' => $this->piiFields === [] ? $cleansed : PiiGuard::maskRow($cleansed, $this->piiFields),
            'reason_code'   => $reason,
            'severity'      => Reasons::severity($reason),
            'message'       => Reasons::label($reason),
            'status'        => QuarantinedRow::STATUS_OPEN,
        ]);
    }

    /**
     * Flush this request's counters into the per-import quality summary.
     * WP3.6: $alert=false while screening is still in progress — the RED alert
     * is raised once, when the whole batch has been screened.
     */
    public function recordChunk(Import $import, bool $alert = true): void
    {
        $q = $this->quality ?? ImportQuality::firstOrCreate(
            ['import_id' => $import->id],
            ['tenant_id' => $import->tenant_id, 'data_type' => $import->data_type],
        );

        $q->rows_seen        += $this->promoted + $this->quarantined;
        $q->rows_promoted    += $this->promoted;
        $q->rows_quarantined += $this->quarantined;
        $q->rows_cleansed    += $this->cleansed;

        $counts = $q->reason_counts ?? [];
        foreach ($this->reasonCounts as $k => $v) {
            $counts[$k] = ($counts[$k] ?? 0) + $v;
        }
        $q->reason_counts = $counts;

        if ($this->captureProfile && empty($q->column_profile) && $this->profileRows !== []) {
            $q->column_profile = $this->profiler->profile($import->data_type, $this->profileRows);
        }

        // Batch decision (GREEN/AMBER/RED) from the cumulative counts — the autonomy
        // envelope and the input to the detection-readiness contract.
        $decision = $this->decider->decide(
            $import->data_type,
            (int) $q->rows_promoted,
            (int) $q->rows_quarantined,
            (bool) $q->is_duplicate_file,
        );
        // Detect the transition INTO red so a blocked feed alerts exactly once,
        // not on every subsequent chunk that stays red.
        $wasRed = $q->state === ImportQuality::STATE_RED;

        $q->state    = $decision['state'];
        $q->decision = $decision['decision'];
        $q->blocked  = $decision['blocked'];

        $q->save();
        $this->quality = $q;

        // A RED batch must never be silent (the firewall guardrail). Best-effort —
        // an alert failure can never break ingestion.
        if ($alert && $decision['state'] === ImportQuality::STATE_RED && ! $wasRed) {
            app(ReadinessAlerter::class)->redBatch($q);
        }
    }

    // ── Loaders ────────────────────────────────────────────────────────────────

    private function loadAliases(int $tenantId): array
    {
        $out = ['sku' => [], 'store' => [], 'supplier' => []];
        foreach (EntityAlias::where('tenant_id', $tenantId)->get(['entity_type', 'alias', 'canonical']) as $a) {
            $out[$a->entity_type][mb_strtolower(trim((string) $a->alias))] = $a->canonical;
        }

        return $out;
    }

    private function loadValueMaps(int $tenantId, string $dataType): array
    {
        $out = [];
        foreach (ValueMap::where('tenant_id', $tenantId)->where('data_type', $dataType)->get() as $m) {
            $out[$m->field][mb_strtolower(trim((string) $m->from_value))] = $m->to_value;
        }

        return $out;
    }

    private function loadRules(int $tenantId, string $dataType): array
    {
        $out = [];
        $rules = CleansingRule::where('tenant_id', $tenantId)
            ->where('data_type', $dataType)
            ->where('enabled', true)
            ->orderBy('ordinal')
            ->get(['field', 'rule_type', 'params']);
        foreach ($rules as $r) {
            $out[$r->field][] = ['rule_type' => $r->rule_type, 'params' => $r->params ?? []];
        }

        return $out;
    }
}
