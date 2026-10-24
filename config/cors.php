<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | CORS is closed on every path. The integration API (/api/v1) is server to
    | server and the visitor API (/api/v1/widget) is only called by the
    | widget iframe, which is served from this same origin, so no browser on
    | another origin ever needs to read a response. Without this file the
    | framework would open api/* to every origin.
    |
    */

    'paths' => [],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
