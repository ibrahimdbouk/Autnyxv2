<?php

namespace Tests\Feature;

use App\Models\Product;
use Tests\TestCase;

/** Product physical dimensions (length / width / height / volume) persist and cast. */
class ProductDimensionsTest extends TestCase
{
    public function test_product_dimensions_persist_as_decimals(): void
    {
        $t = $this->createTenant();
        $p = Product::create([
            'tenant_id' => $t->id, 'sku' => 'DIM-1', 'name' => 'Box',
            'length_mm' => 120.5, 'width_mm' => 80, 'height_mm' => 45.25, 'volume_cm3' => 433.9,
        ])->fresh();

        $this->assertSame('120.50', $p->length_mm);
        $this->assertSame('80.00', $p->width_mm);
        $this->assertSame('45.25', $p->height_mm);
        $this->assertSame('433.90', $p->volume_cm3);
    }
}
