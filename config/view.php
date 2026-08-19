<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | Deliberately NOT using realpath() here. On Laravel Cloud the persistent
    | storage volume is mounted over storage/ at runtime, which wipes the
    | compiled-views directory that the build created. Laravel's default
    | (`realpath(storage_path('framework/views'))`) resolves to FALSE while that
    | directory is momentarily absent, producing the post-deploy 500
    | "Please provide a valid cache path.". A plain string path is always valid;
    | AppServiceProvider::register() (re)creates the directory on every boot.
    |
    */

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        storage_path('framework/views')
    ),

];
