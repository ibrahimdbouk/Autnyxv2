<?php

namespace App\Filament\Concerns;

/**
 * ResolvesRecordKey — hardens custom record pages against Filament v5 /
 * Livewire route-model-binding passing a *serialized model* (JSON) as the
 * `{record}` route parameter instead of its key.
 *
 * When that happens, a naive `findOrFail($record)` sends the JSON blob to a
 * `where id = …` query, which on PostgreSQL fails with:
 *   SQLSTATE[22P02]: invalid input syntax for type bigint: "{"id":1,...}"
 *
 * Any custom Page that resolves its own record via `mount(int|string $record)`
 * should pass the incoming value through resolveRecordKey() before querying.
 */
trait ResolvesRecordKey
{
    /**
     * Normalise a route `{record}` value to a scalar key.
     * Accepts an int, a numeric string, or a serialized-model JSON string.
     */
    protected function resolveRecordKey(int|string $record): int|string
    {
        if (is_string($record)) {
            $trimmed = trim($record);

            // Serialized Eloquent model arrived as JSON — pull out the key.
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    if (isset($decoded['id'])) {
                        return $decoded['id'];
                    }
                    // Livewire snapshot shape: {"data":{"id":…}} or nested model
                    if (isset($decoded['data']['id'])) {
                        return $decoded['data']['id'];
                    }
                }
            }
        }

        return $record;
    }
}
