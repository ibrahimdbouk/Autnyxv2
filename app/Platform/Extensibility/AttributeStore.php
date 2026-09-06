<?php

namespace App\Platform\Extensibility;

use App\Models\CustomAttributeDefinition;
use App\Models\EntityAttributeValue;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * P3.2 — read/write custom-dimension values for canonical entities WITHOUT a
 * schema change. A value may only be set against a dimension the tenant has
 * declared (the governance gate), and is coerced to the declared data_type on
 * read. This is the "add a dimension" half of P3.2's definition of done.
 */
class AttributeStore
{
    /** Declare a custom dimension (idempotent on tenant+entity_type+key). */
    public function declare(
        int $tenantId,
        string $entityType,
        string $key,
        string $label,
        string $dataType = CustomAttributeDefinition::TYPE_STRING,
    ): CustomAttributeDefinition {
        return CustomAttributeDefinition::updateOrCreate(
            ['tenant_id' => $tenantId, 'entity_type' => $entityType, 'key' => $key],
            ['label' => $label, 'data_type' => $dataType, 'active' => true],
        );
    }

    /**
     * Set a value for an entity on a declared dimension. Throws if the dimension
     * is not declared — custom attributes are typed and enumerable, not arbitrary.
     */
    public function set(int $tenantId, string $entityType, int $entityId, string $key, mixed $value): EntityAttributeValue
    {
        if (! $this->definition($tenantId, $entityType, $key)) {
            throw new RuntimeException("Attribute '{$key}' is not declared for {$entityType}; declare it first.");
        }

        return EntityAttributeValue::updateOrCreate(
            ['tenant_id' => $tenantId, 'entity_type' => $entityType, 'entity_id' => $entityId, 'attribute_key' => $key],
            ['value' => $value === null ? null : (string) (is_bool($value) ? ($value ? '1' : '0') : $value)],
        );
    }

    /** Read a value, coerced to the dimension's declared data_type. */
    public function get(int $tenantId, string $entityType, int $entityId, string $key): mixed
    {
        $row = EntityAttributeValue::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('attribute_key', $key)
            ->first();

        if (! $row || $row->value === null) {
            return null;
        }

        return $this->coerce($row->value, $this->definition($tenantId, $entityType, $key)?->data_type);
    }

    /**
     * The declared dimensions for an entity type.
     *
     * @return Collection<int,CustomAttributeDefinition>
     */
    public function dimensions(int $tenantId, string $entityType): Collection
    {
        return CustomAttributeDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->where('active', true)
            ->get();
    }

    private function definition(int $tenantId, string $entityType, string $key): ?CustomAttributeDefinition
    {
        return CustomAttributeDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->where('key', $key)
            ->where('active', true)
            ->first();
    }

    private function coerce(string $value, ?string $dataType): mixed
    {
        return match ($dataType) {
            CustomAttributeDefinition::TYPE_NUMBER  => is_numeric($value) ? $value + 0 : null,
            CustomAttributeDefinition::TYPE_BOOLEAN => in_array(strtolower($value), ['1', 'true', 'yes'], true),
            default                                 => $value, // string / date kept as-is
        };
    }
}
