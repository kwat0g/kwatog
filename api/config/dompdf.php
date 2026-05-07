<?php

declare(strict_types=1);

return [

    /*
     * barryvdh/laravel-dompdf — see vendor/barryvdh/laravel-dompdf/config/dompdf.php
     * for the full reference. We override only what we need so PDF rendering
     * works out of the box in dev and prod containers without requiring a
     * separate `vendor:publish` step.
     */

    'show_warnings' => false,

    'public_path' => null,

    /*
     * If true, the dompdf will use the storage_path('app/...') for
     * ImageRemoteFiles. Avoids hitting the network when the Blade references
     * a relative img.
     */
    'convert_entities' => true,

    'options' => [

        // Where dompdf writes its font cache. Must be writable by the PHP-FPM
        // user; the dockerfile already chown's storage/ so storage/fonts/ is
        // safe. Some images don't have storage/fonts/ — make sure both this
        // and `temp_dir` exist + are writable.
        'font_dir' => storage_path('fonts'),

        'font_cache' => storage_path('fonts'),

        // Working temp directory for in-progress PDFs. Default sys_get_temp_dir
        // is fine on most hosts but breaks when /tmp is read-only inside the
        // container (read-only root FS, restrictive volume mounts, etc.).
        'temp_dir' => storage_path('app/dompdf-tmp'),

        // Restrict file:// inclusion to within the Laravel base path so
        // attacker-controlled HTML can't read /etc/passwd via @import.
        'chroot' => realpath(base_path()) ?: base_path(),

        // No logging by default; flip to a path under storage_path('logs') if
        // you need to debug rendering issues.
        'log_output_file' => null,

        'enable_font_subsetting' => false,

        'pdf_backend' => 'CPDF',

        'default_media_type' => 'screen',

        'default_paper_size' => 'a4',

        'default_paper_orientation' => 'portrait',

        'default_font' => 'serif',

        'dpi' => 96,

        'enable_php' => true,

        'enable_javascript' => true,

        'enable_remote' => false,

        'allowed_remote_hosts' => null,

        'font_height_ratio' => 1.1,

        'enable_html5_parser' => true,
    ],
];
