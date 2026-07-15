<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SSRF DNS resolution
    |--------------------------------------------------------------------------
    |
    | When true, the PubliclyRoutableUrl rule resolves hostnames and rejects
    | URLs that resolve to private, loopback, or reserved addresses. Disabled
    | in the test environment, where outbound hosts are faked and would not
    | resolve.
    |
    */

    'ssrf_resolve_dns' => env('SSRF_RESOLVE_DNS', true),

    /*
    |--------------------------------------------------------------------------
    | Seeded admin password
    |--------------------------------------------------------------------------
    |
    | Password for the seeded admin@apispi.com account. Read from config (not
    | env() directly) so it still resolves when the config cache is warm. When
    | null, the seeder generates a random password and prints it once.
    |
    */

    'admin_password' => env('ADMIN_PASSWORD'),

];
