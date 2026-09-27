<?php

namespace App\Platform\Linking;

/**
 * Platform contract: what OTHER apps know about a subject (a product at a
 * store), without the apps depending on each other.
 *
 * An app registers a provider for its own records (Root Cause: open
 * investigations); any app asks "what else is there about this store ×
 * product?" and gets plain links back. The asking app never imports the other
 * app's classes, and a provider answers only for tenants that hold its app.
 *
 * Provider: fn (int $tenantId, ?int $storeId, ?string $sku): array<int, array{label:string, url:?string, status:?string}>
 */
final class SubjectLinks
{
    /** @var array<string, callable> app key => provider */
    private array $providers = [];

    public function register(string $app, callable $provider): void
    {
        $this->providers[$app] = $provider;
    }

    /**
     * @return array<int, array{app:string, label:string, url:?string, status:?string}>
     */
    public function for(int $tenantId, ?int $storeId, ?string $sku, ?string $exceptApp = null): array
    {
        $out = [];
        foreach ($this->providers as $app => $provider) {
            if ($app === $exceptApp) {
                continue;
            }
            try {
                foreach ((array) $provider($tenantId, $storeId, $sku) as $link) {
                    $out[] = ['app' => $app, 'label' => (string) ($link['label'] ?? ''), 'url' => $link['url'] ?? null, 'status' => $link['status'] ?? null];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $out;
    }
}
