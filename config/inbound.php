<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inbound email → comment webhook
    |--------------------------------------------------------------------------
    |
    | Shared secret an email provider (Mailgun / Postmark / SendGrid inbound
    | route) must present to POST /webhooks/inbound-email. If unset, the endpoint
    | is disabled (returns 403). Set INBOUND_EMAIL_SECRET to enable it.
    |
    | 'domain' is the inbound mail domain used to build reply addresses:
    |   reply+<token>@<domain>
    |
    */

    'secret' => env('INBOUND_EMAIL_SECRET'),
    'domain' => env('INBOUND_EMAIL_DOMAIN', 'inbound.autnyx.io'),

];
