<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Most templates are stored on the local disk. However, on occasion you
    | may wish to store views on a different location or even remotely such
    | as in an S3 bucket. Here you may specify any view paths to scan.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | This option determines where all the compiled Blade templates will be
    | stored for your application. Typically, this is within the storage
    | directory. However, this path is platform-agnostic.
    |
    | When this is null/empty, Illuminate\View\Compilers\Compiler throws
    | "Please provide a valid cache path." — that's the error every PDF
    | endpoint was hitting because Laravel 11 ships without a default
    | config/view.php.
    |
    */

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views')) ?: storage_path('framework/views'),
    ),

];
