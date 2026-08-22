<?php

namespace App\Services\Collaboration;

use App\Models\Investigation;

/**
 * Signed, opaque token that ties a notification email's reply address to an
 * investigation. Used so a user can reply to an email and have it posted as a
 * comment. Format: "<investigation_id>-<hmac16>". Tamper-proof via APP_KEY.
 *
 * Reply address convention: reply+<token>@<inbound domain>, or a subject that
 * contains [INV-<token>].
 */
class EmailReplyToken
{
    public static function for(Investigation $investigation): string
    {
        $id = (string) $investigation->id;
        return $id . '-' . self::sign($id);
    }

    public static function resolve(?string $token): ?int
    {
        if (! $token || ! str_contains($token, '-')) {
            return null;
        }
        [$id, $sig] = explode('-', $token, 2);
        if (! ctype_digit($id)) {
            return null;
        }
        return hash_equals(self::sign($id), $sig) ? (int) $id : null;
    }

    private static function sign(string $id): string
    {
        return substr(hash_hmac('sha256', $id, self::key()), 0, 16);
    }

    private static function key(): string
    {
        return (string) (config('app.key') ?: 'autnyx-inbound');
    }
}
