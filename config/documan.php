<?php

return [
    'disk' => '',

    'remote' => [
        'host_url' => '',
        'disk' => '',
    ],

    'externalAdapter' => [
        'enabled' => false,
        'adapter' => [
            'upload' => \Tekkenking\Documan\ExternalProviders\TinyPeexi\UploadAdapter::class,
            'show' => \Tekkenking\Documan\ExternalProviders\TinyPeexi\ShowAdapter::class,
        ],
    ],

    /**
     * Queue configuration for async image processing.
     *
     * When enabled, each resized image variant is processed by a queue worker
     * instead of blocking the HTTP request. The original file is always stored
     * synchronously first so the job has a source to read from.
     *
     * 'connection' — null uses the default queue connection (QUEUE_CONNECTION env)
     * 'name'       — null uses the default queue name
     */
    'queue' => [
        'enabled'    => false,
        'connection' => null,
        'name'       => null,
    ],

    /**
     * JPEG/WebP output quality (1-100). Also used for PNG compression (scaled to 0-9).
     */
    'imageQuality' => 90,

    /**
     * When true, a .webp copy is saved alongside every resized image.
     */
    'outputWebp' => false,

    /**
     * Delete behaviour.
     *
     * mode:
     *   'hard' — files are permanently removed from the disk (default).
     *   'soft' — files are moved to a trash folder on the same disk so they
     *             can be restored or audited later. Use Storage::disk()->delete()
     *             on the trashed path when you want to purge them for good.
     *
     * trash_folder:
     *   The folder name (relative to the disk root) used when mode = 'soft'.
     *   Defaults to 'trash'. Nested paths are supported (e.g. '.trash/documan').
     */
    'delete' => [
        'mode'         => 'hard',
        'trash_folder' => 'trash',
    ],

    /**
     * Only the dimensions can be changed.
     * More sizes can be added
     */
    'defaultImageSizes' => [
        'big' => ['width' => 1600,   'height' => 1600],
        'medium' => ['width' => 800,    'height' => 800],
        'thumbnail' => ['width' => 400,    'height' => 400],
        'small' => ['width' => 170,    'height' => 170],
        'tiny' => ['width' => 50,     'height' => 50],
    ],

    /**
     * The sizes to save if no size was selected during upload
     */
    'uploadDefaulImageSizes' => [
        // 'medium'
    ],

    /**
     * Theses are allowed extensions
     */
    'allowedFileExtensions' => [
        'image' => ['jpg', 'png', 'jpeg', 'gif'],
        'excel' => ['xlsx', 'xls', 'csv'],
        'document' => ['doc', 'docx'],
        'powerpoint' => ['ppt', 'pptx'],
        'pdf' => ['pdf'],
    ],

    'returnUploadWith' => [
        'links' => true,
        'paths' => false,
    ],

    // Array | Collection
    'defaultReturn' => 'array',
];
