<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Earth Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Google Earth Engine REST API integration.
    | You need to set up a service account and download the private key.
    |
    */

    'service_account_email' => env('GEE_SERVICE_ACCOUNT_EMAIL'),
    'private_key_path' => env('GEE_PRIVATE_KEY_PATH', 'gee/private-key.json'),
    'project_id' => env('GEE_PROJECT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Cache settings for GEE computation results.
    |
    */

    'cache' => [
        'ttl' => env('GEE_CACHE_TTL', 30), // Days
        'enabled' => env('GEE_CACHE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Parameters
    |--------------------------------------------------------------------------
    |
    | Default parameters for RUSLE computations.
    |
    */

    'defaults' => [
        'start_year' => 1993,
        'end_year' => date('Y'),
        'resolution' => 1000, // meters
        'tile_size' => 1000, // pixels
    ],
];
