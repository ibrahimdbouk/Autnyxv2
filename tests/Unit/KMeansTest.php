<?php

namespace Tests\Unit;

use App\Platform\Intelligence\Clustering\KMeans;
use PHPUnit\Framework\TestCase;

/**
 * The deterministic k-means primitive behind behavioural clustering.
 */
class KMeansTest extends TestCase
{
    public function test_separates_two_obvious_groups(): void
    {
        $vectors = [
            [0.0, 0.0], [0.1, 0.1], [0.0, 0.2],       // near origin
            [10.0, 10.0], [10.1, 9.9], [9.9, 10.2],   // near (10,10)
        ];

        $a = KMeans::cluster($vectors, 2)['assignments'];

        $this->assertSame($a[0], $a[1]);
        $this->assertSame($a[1], $a[2]);
        $this->assertSame($a[3], $a[4]);
        $this->assertSame($a[4], $a[5]);
        $this->assertNotSame($a[0], $a[3]);
    }

    public function test_is_deterministic(): void
    {
        $v = [[0.0, 0.0], [1.0, 1.0], [8.0, 8.0], [9.0, 9.0]];

        $this->assertSame(
            KMeans::cluster($v, 2)['assignments'],
            KMeans::cluster($v, 2)['assignments'],
        );
    }

    public function test_k_is_capped_to_the_point_count(): void
    {
        $res = KMeans::cluster([[1.0], [2.0]], 5);

        // Only two points, so at most two clusters, both assigned.
        $this->assertCount(2, $res['assignments']);
        $this->assertLessThanOrEqual(2, count(array_unique($res['assignments'])));
    }
}
