<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Address;

/**
 * Platform core — a deactivated person (a leaver) receives no e-mail from
 * Autnyx, whichever feature sends it (digests, store digests, mentions,
 * reports, alerts). Their address is taken off every message at the last
 * moment; a message left with no recipient is not sent at all.
 */
class DropDeactivatedRecipients
{
    public function sending(MessageSending $event): ?bool
    {
        $message = $event->message;
        if (! $message instanceof \Symfony\Component\Mime\Email) {
            return null;
        }

        $all = array_merge($message->getTo(), $message->getCc(), $message->getBcc());
        if ($all === []) {
            return null;
        }
        $emails = array_values(array_unique(array_map(fn (Address $a) => mb_strtolower($a->getAddress()), $all)));

        try {
            $blocked = DB::table('users')->whereNotNull('deactivated_at')
                ->whereIn(DB::raw('lower(email)'), $emails)->pluck('email')
                ->map(fn ($e) => mb_strtolower((string) $e))->all();
        } catch (\Throwable) {
            return null; // never block mail on a lookup failure
        }
        if ($blocked === []) {
            return null;
        }

        $keep = fn (array $list) => array_values(array_filter($list, fn (Address $a) => ! in_array(mb_strtolower($a->getAddress()), $blocked, true)));
        $to = $keep($message->getTo());
        $cc = $keep($message->getCc());
        $bcc = $keep($message->getBcc());

        if ($to === [] && $cc === [] && $bcc === []) {
            return false; // nobody left to send to
        }
        foreach (['To' => $to, 'Cc' => $cc, 'Bcc' => $bcc] as $header => $list) {
            $message->getHeaders()->remove($header);
            if ($list !== []) {
                $message->getHeaders()->addMailboxListHeader($header, $list);
            }
        }

        return null;
    }
}
