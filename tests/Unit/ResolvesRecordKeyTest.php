<?php

namespace Tests\Unit;

use App\Filament\Concerns\ResolvesRecordKey;
use PHPUnit\Framework\TestCase;

class ResolvesRecordKeyTest extends TestCase
{
    private function resolver(): object
    {
        return new class {
            use ResolvesRecordKey;

            public function call(int|string $record): int|string
            {
                return $this->resolveRecordKey($record);
            }
        };
    }

    public function test_plain_integer_passes_through(): void
    {
        $this->assertSame(7, $this->resolver()->call(7));
    }

    public function test_numeric_string_passes_through(): void
    {
        $this->assertSame('42', $this->resolver()->call('42'));
    }

    public function test_serialized_model_json_returns_id(): void
    {
        $json = '{"id":1,"tenant_id":1,"rule_type":"import_frequency_gap","severity":"medium"}';
        $this->assertSame(1, $this->resolver()->call($json));
    }

    public function test_livewire_snapshot_shape_returns_id(): void
    {
        $json = '{"data":{"id":99,"title":"x"}}';
        $this->assertSame(99, $this->resolver()->call($json));
    }

    public function test_non_json_string_passes_through(): void
    {
        $this->assertSame('abc', $this->resolver()->call('abc'));
    }
}
