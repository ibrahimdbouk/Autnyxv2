<?php

namespace App\Services\Teams;

/**
 * Builds the Adaptive Card posted to a Teams channel for an Autnyx notification.
 * Kept deliberately simple and schema-valid (Adaptive Cards 1.4, which Teams
 * renders): a title, an optional body, an optional FactSet, and an "Open in
 * Autnyx" action. The same card content is used whether it is delivered via a
 * Workflows/Incoming-Webhook or via Graph (both wrap this content identically).
 *
 * See claude/teams-notifications.md.
 */
class AdaptiveCardBuilder
{
    /**
     * @param  array<string,string>  $facts  ordered label => value pairs (e.g. SKU, Revenue at risk, Rule)
     * @return array<string,mixed>
     */
    public function build(string $title, ?string $body = null, ?string $url = null, array $facts = []): array
    {
        $bodyBlocks = [[
            'type'   => 'TextBlock',
            'text'   => $title,
            'weight' => 'Bolder',
            'size'   => 'Medium',
            'wrap'   => true,
        ]];

        if ($body !== null && $body !== '') {
            $bodyBlocks[] = [
                'type'     => 'TextBlock',
                'text'     => $body,
                'wrap'     => true,
                'spacing'  => 'Small',
                'isSubtle' => true,
            ];
        }

        if ($facts !== []) {
            $bodyBlocks[] = [
                'type'  => 'FactSet',
                'facts' => array_values(array_map(
                    fn ($label, $value) => ['title' => (string) $label, 'value' => (string) $value],
                    array_keys($facts),
                    array_values($facts),
                )),
            ];
        }

        $card = [
            'type'    => 'AdaptiveCard',
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'version' => '1.4',
            'body'    => $bodyBlocks,
        ];

        if ($url !== null && $url !== '') {
            $card['actions'] = [[
                'type'  => 'Action.OpenUrl',
                'title' => 'Open in Autnyx',
                'url'   => $url,
            ]];
        }

        return $card;
    }
}
