<?php

namespace App\Platform\Intelligence\Clustering;

use InvalidArgumentException;

/**
 * Resolves the configured clustering strategy. Config `clustering.strategy`
 * picks the default; `clustering.strategies` maps a method name to its class.
 * Same ship-dark-then-graduate discipline as incremental detection.
 */
class ClusteringStrategyFactory
{
    public function defaultMethod(): string
    {
        return (string) config('clustering.strategy', 'attribute');
    }

    public function make(?string $method = null): ClusteringStrategy
    {
        $method = $method ?: $this->defaultMethod();
        $map = (array) config('clustering.strategies', []);

        if (! isset($map[$method])) {
            throw new InvalidArgumentException("Unknown clustering strategy [{$method}].");
        }

        return app($map[$method]);
    }
}
