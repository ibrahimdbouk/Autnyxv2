<?php

namespace Tests\Feature;

use App\Services\Recovery\AnomalyIdentity;
use Tests\TestCase;

/**
 * R1 — the persistent identity key is deterministic and subject-scoped, and
 * reproduces the detection layer's M15 dedup semantics (store_id is always part
 * of the key; NULL store / NULL sku are distinct, stable key parts).
 */
class AnomalyIdentityTest extends TestCase
{
    public function test_key_is_deterministic(): void
    {
        $a = AnomalyIdentity::key(1, 'stockout_risk', 5, 'SKU-1');
        $b = AnomalyIdentity::key(1, 'stockout_risk', 5, 'SKU-1');

        $this->assertSame($a, $b);
        $this->assertSame(40, strlen($a)); // sha1 hex
    }

    public function test_key_is_scoped_by_every_subject_dimension(): void
    {
        $base = AnomalyIdentity::key(1, 'stockout_risk', 5, 'SKU-1');

        $this->assertNotSame($base, AnomalyIdentity::key(2, 'stockout_risk', 5, 'SKU-1'), 'tenant');
        $this->assertNotSame($base, AnomalyIdentity::key(1, 'sales_drop', 5, 'SKU-1'), 'rule');
        $this->assertNotSame($base, AnomalyIdentity::key(1, 'stockout_risk', 6, 'SKU-1'), 'store — M15: store A vs store B are separate incidents');
        $this->assertNotSame($base, AnomalyIdentity::key(1, 'stockout_risk', 5, 'SKU-2'), 'sku');
    }

    public function test_null_store_and_null_sku_are_distinct_stable_keys(): void
    {
        $withStore = AnomalyIdentity::key(1, 'store_outlier', 5, null);
        $noStore   = AnomalyIdentity::key(1, 'store_outlier', null, null);

        $this->assertNotSame($withStore, $noStore);
        // Stable: same null-subject inputs always collapse to the same key.
        $this->assertSame($noStore, AnomalyIdentity::key(1, 'store_outlier', null, null));

        $chainSku = AnomalyIdentity::key(1, 'sales_drop', null, 'SKU-9');
        $this->assertNotSame($chainSku, AnomalyIdentity::key(1, 'sales_drop', null, 'SKU-8'));
    }

    public function test_sku_is_trimmed_so_whitespace_does_not_split_identity(): void
    {
        $this->assertSame(
            AnomalyIdentity::key(1, 'sales_drop', 5, 'SKU-1'),
            AnomalyIdentity::key(1, 'sales_drop', 5, '  SKU-1  '),
        );
    }
}
