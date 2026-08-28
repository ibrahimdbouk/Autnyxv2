<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform owner
    |--------------------------------------------------------------------------
    |
    | The single protected "super-super-admin" account. Identified by email so
    | it survives re-seeding. The owner:
    |   • is always a super admin (cannot be demoted),
    |   • can never be deleted — not even by other super admins,
    |   • is the only account allowed to grant super-admin to others.
    |
    | The password is NEVER stored in the repo — it is seeded from OWNER_PASSWORD
    | (set in the environment / Laravel Cloud secrets) on first creation only.
    |
    */
    'owner_email' => env('OWNER_EMAIL', 'ibrahim@autnyx.com'),

];
