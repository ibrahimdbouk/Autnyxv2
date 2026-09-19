<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        // Model selection for the AI layer. Fast = bulk narration/flagging
        // (narrator, data-quality, follow-up); reasoning = planning/synthesis
        // (campaign action-plan, weekly briefing, supplier prep). Overridable
        // per environment so models can be tuned without a deploy.
        'model_fast'      => env('ANTHROPIC_MODEL_FAST', 'claude-haiku-4-5'),
        'model_reasoning' => env('ANTHROPIC_MODEL_REASONING', 'claude-sonnet-4-5'),
    ],

];
