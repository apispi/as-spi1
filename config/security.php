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

];
