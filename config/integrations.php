<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Integration API (/api/v1)
    |--------------------------------------------------------------------------
    |
    | Server-to-server API authenticated with expiring bearer tokens
    | (api_tokens). Tokens always expire: the default and maximum lifetimes
    | are in days.
    |
    */

    'tokens' => [
        'default_days' => 90,
        'max_days' => 365,
    ],

    'limits' => [
        'reads_per_minute' => (int) env('INTEGRATIONS_READS_PER_MINUTE', 600),
        'writes_per_minute' => (int) env('INTEGRATIONS_WRITES_PER_MINUTE', 60),
    ],

    'pagination' => [
        'default' => 25,
        'max' => 100,
    ],

    /** Maximum length of a message sent through the API. */
    'message_max_length' => 4096,

];
