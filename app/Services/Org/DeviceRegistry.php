<?php

namespace App\Services\Org;

use App\Models\User;
use App\Models\UserDevice;

/**
 * Platform core — phones registered for push notifications.
 *
 * A push token identifies one app install. Registering a token that is
 * already known moves it to the new user (a shared store handheld changing
 * hands) rather than duplicating it. Deactivating a person revokes every
 * device they have, so a leaver's phone stops receiving anything at once.
 * Notifications themselves carry no business data (see the task-execution
 * notification rules): only a pointer that needs a signed-in app to open.
 */
class DeviceRegistry
{
    public function register(User $user, string $platform, string $token, array $meta = []): UserDevice
    {
        if (! in_array($platform, UserDevice::PLATFORMS, true)) {
            throw new \InvalidArgumentException("Unknown platform '{$platform}'.");
        }
        $token = trim($token);
        if ($token === '' || strlen($token) > 4096) {
            throw new \InvalidArgumentException('A push token is required.');
        }
        if (! $user->isActive() || $user->tenant_id === null) {
            throw new \InvalidArgumentException('This account cannot register devices.');
        }

        $hash = hash('sha256', $token);
        $device = UserDevice::where('token_hash', $hash)->first() ?? new UserDevice(['token_hash' => $hash]);
        $device->fill([
            'tenant_id'      => $user->tenant_id,
            'user_id'        => $user->id,
            'platform'       => $platform,
            'token'          => $token,
            'device_name'    => isset($meta['device_name']) ? mb_substr((string) $meta['device_name'], 0, 120) : $device->device_name,
            'app_version'    => isset($meta['app_version']) ? mb_substr((string) $meta['app_version'], 0, 30) : $device->app_version,
            'locale'         => isset($meta['locale']) && isset(User::LOCALES[$meta['locale']]) ? $meta['locale'] : ($user->locale ?? $device->locale),
            'last_seen_at'   => now(),
            'revoked_at'     => null,
            'revoked_reason' => null,
        ])->save();

        return $device;
    }

    public function revoke(UserDevice $device, string $reason = 'signed_out'): void
    {
        if ($device->revoked_at === null) {
            $device->forceFill(['revoked_at' => now(), 'revoked_reason' => mb_substr($reason, 0, 40)])->save();
        }
    }

    /** @return int devices revoked */
    public function revokeAllFor(User $user, string $reason): int
    {
        return UserDevice::where('user_id', $user->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => mb_substr($reason, 0, 40), 'updated_at' => now()]);
    }

    /** Active devices of the given people (for sending). @return \Illuminate\Support\Collection<int,UserDevice> */
    public function activeFor(array $userIds)
    {
        return UserDevice::active()->whereIn('user_id', $userIds)
            ->whereIn('user_id', User::active()->select('id'))->get();
    }
}
