<?php

return [
    /*
    |--------------------------------------------------------------------------
    | GeoTIFF Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for GDAL-based GeoTIFF processing.
    |
    */

    'gdal_path' => env('GDAL_PATH', 'gdal'),
    'gdal2tiles_path' => env('GDAL2TILES_PATH', 'gdal2tiles.py'),

    /*
    |--------------------------------------------------------------------------
    | File Upload Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for file uploads and processing.
    |
    */

    'upload' => [
        'max_size' => env('GEOTIFF_MAX_SIZE', 500 * 1024 * 1024), // 500MB
        'allowed_types' => ['tif', 'tiff', 'geotiff'],
        'storage_disk' => 'local',
        'storage_path' => 'geotiff/uploads',
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for GeoTIFF processing.
    |
    */

    'processing' => [
        'tile_zoom_levels' => '0-12',
        'tile_format' => 'png',
        'compression' => 'LZW',
        'block_size' => 512,
        'overview_levels' => [2, 4, 8, 16],
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for processed output files.
    |
    */

    'output' => [
        'cog_path' => 'geotiff/processed',
        'tiles_path' => 'geotiff/tiles',
        'public_url' => env('APP_URL') . '/storage/geotiff/tiles',
    ],
];
