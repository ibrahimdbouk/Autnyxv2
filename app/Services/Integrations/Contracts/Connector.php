<?php

namespace App\Services\Integrations\Contracts;

use App\Models\ApiConnection;
use App\Models\ApiFeed;

/**
 * A source-system connector. Implementations fetch records from an external API
 * and yield them as associative arrays already keyed by canonical-ish header
 * names (the standard column auto-mapper then maps those to DB columns), so the
 * downstream import pipeline is identical to a flat-file import.
 *
 * See claude/api-integration-library.md.
 */
interface Connector
{
    /**
     * Fetch all records for a feed (handling auth + pagination).
     *
     * @return iterable<int,array<string,mixed>>  rows keyed by header name
     */
    public function fetch(ApiConnection $connection, ApiFeed $feed): iterable;

    /**
     * Lightweight connectivity/auth check for the "Test" action.
     *
     * @return array{ok:bool,message:string}
     */
    public function test(ApiConnection $connection): array;
}
