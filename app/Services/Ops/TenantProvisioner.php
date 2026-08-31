<?php

namespace App\Services\Ops;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 2b — provision a new tenant and, optionally, its first admin user, from the
 * super-admin control plane. One transaction; audited.
 */
class TenantProvisioner
{
    /**
     * @param  array{name:string,slug?:string,plan?:string,status?:string,currency?:string,apps?:array}  $tenantData
     * @param  array{name?:string,email?:string,password?:string}  $adminData
     */
    public function create(array $tenantData, array $adminData = []): Tenant
    {
        return DB::transaction(function () use ($tenantData, $adminData) {
            $tenant = Tenant::create([
                'name'     => $tenantData['name'],
                'slug'     => $this->uniqueSlug($tenantData['slug'] ?? $tenantData['name']),
                'plan'     => $tenantData['plan'] ?? Tenant::PLAN_TRIAL,
                'status'   => $tenantData['status'] ?? Tenant::STATUS_ACTIVE,
                'apps'     => ! empty($tenantData['apps']) ? array_values($tenantData['apps']) : Tenant::DEFAULT_APPS,
                'currency' => $tenantData['currency'] ?? 'USD',
            ]);

            if (! empty($adminData['email'])) {
                User::create([
                    'tenant_id'       => $tenant->id,
                    'name'            => $adminData['name'] ?? $adminData['email'],
                    'email'           => $adminData['email'],
                    'password'        => Hash::make($adminData['password'] ?? Str::random(20)),
                    'is_tenant_admin' => true,
                    'is_super_admin'  => false,
                ]);
            }

            $this->audit($tenant);

            return $tenant;
        });
    }

    /** Generate a unique slug from a base string. */
    public function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'tenant';
        $candidate = $slug;
        $n = 2;

        while (Tenant::where('slug', $candidate)->exists()) {
            $candidate = $slug . '-' . $n;
            $n++;
        }

        return $candidate;
    }

    private function audit(Tenant $tenant): void
    {
        try {
            AuditLog::create([
                'tenant_id'   => $tenant->id,
                'user_id'     => auth()->id(),
                'event_type'  => AuditLog::EVENT_TENANT_CREATED,
                'description' => 'Tenant created: ' . $tenant->name,
            ]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}
