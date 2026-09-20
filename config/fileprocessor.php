<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File Size & Batch Limits
    |--------------------------------------------------------------------------
    |
    | Maximum allowed file sizes in kilobytes and batch processing limits.
    |
    */
    'limits' => [
        'max_pdf_size_kb'    => (int) env('FP_MAX_PDF_SIZE_KB', 51200),     // 50 MB
        'max_image_size_kb'  => (int) env('FP_MAX_IMAGE_SIZE_KB', 25600),   // 25 MB
        'max_batch_files'    => (int) env('FP_MAX_BATCH_FILES', 20),
        'max_total_batch_kb' => (int) env('FP_MAX_TOTAL_BATCH_KB', 102400), // 100 MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Timeouts
    |--------------------------------------------------------------------------
    |
    | Execution timeout in seconds for synchronous processing and subprocesses.
    |
    */
    'timeouts' => [
        'sync_process_seconds' => (int) env('FP_SYNC_TIMEOUT_SECONDS', 30),
        'cli_process_seconds'  => (int) env('FP_CLI_TIMEOUT_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Routing & Thresholds
    |--------------------------------------------------------------------------
    |
    | Define when operations transition from synchronous to asynchronous queues.
    |
    */
    'queues' => [
        'sync_threshold_pages'   => (int) env('FP_QUEUE_THRESHOLD_PAGES', 5),
        'sync_threshold_size_kb' => (int) env('FP_QUEUE_THRESHOLD_SIZE_KB', 10240), // 10 MB
        'default_queue'          => env('FP_DEFAULT_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data & File Retention Policies
    |--------------------------------------------------------------------------
    |
    | Define expiration for temporary files and analytics pruning schedule.
    |
    */
    'retention' => [
        'file_retention_minutes'   => (int) env('FP_FILE_RETENTION_MINUTES', 60),
        'analytics_retention_days' => (int) env('FP_ANALYTICS_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting & Abuse Prevention
    |--------------------------------------------------------------------------
    |
    | Throttling definitions per minute per IP address.
    |
    */
    'rate_limits' => [
        'global_per_minute' => (int) env('FP_RATE_LIMIT_GLOBAL', 30),
        'heavy_per_minute'  => (int) env('FP_RATE_LIMIT_HEAVY', 10),
        'downloads_per_min' => (int) env('FP_RATE_LIMIT_DOWNLOADS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Target Size Compression Settings
    |--------------------------------------------------------------------------
    |
    | Configurable limits and iteration profiles for target size compression.
    |
    */
    'target_compression' => [
        'min_target_kb' => (int) env('FP_TARGET_MIN_KB', 10),
        'max_target_mb' => (int) env('FP_TARGET_MAX_MB', 50),
        'image' => [
            'max_iterations' => 10,
            'min_quality'    => 15,
            'min_dimension'  => 80,
        ],
        'pdf' => [
            'max_iterations' => 6,
            'min_dpi'        => 50,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Background Removal Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for background removal engines, active engine selection,
    | available drivers, and safety limits.
    |
    */
    'background_removal' => [
        'engine' => env('FP_BACKGROUND_REMOVAL_ENGINE', 'hybrid'),
        'engines' => [
            'hybrid'         => \App\Services\Image\HybridBackgroundRemovalEngine::class,
            'ml_modnet'      => \App\Services\Image\ModnetBackgroundRemovalEngine::class,
            'ml_u2net'       => \App\Services\Image\U2NetBackgroundRemovalEngine::class,
            'advanced'       => \App\Services\Image\AdvancedBackgroundRemovalEngine::class,
            'color_sampling' => \App\Services\Image\ColorSamplingBackgroundRemovalEngine::class,
        ],
        'max_dimension'     => (int) env('FP_BG_MAX_DIMENSION', 4096),
        'default_tolerance' => (float) env('FP_BG_DEFAULT_TOLERANCE', 25.0),
        'default_feather'   => (int) env('FP_BG_DEFAULT_FEATHER', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | System Binaries Configuration
    |--------------------------------------------------------------------------
    |
    | Path overrides for optional system binaries. When set to null, the
    | SystemBinaryDetector will discover binaries on the system PATH.
    |
    */
    'binaries' => [
        'ghostscript' => env('FP_GHOSTSCRIPT_PATH', null),
        'poppler'     => env('FP_POPPLER_PATH', null),
        'node'        => env('FP_NODE_PATH', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Storage disk and relative base path for secure ephemeral files.
    |
    */
    'storage' => [
        'disk'       => env('FP_STORAGE_DISK', 'local'),
        'temp_path'  => 'secure_temp',
    ],
];
