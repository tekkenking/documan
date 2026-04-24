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
     * JPEG/WebP output quality (1-100). Also used for PNG compression (scaled to 0-9).
     */
    'imageQuality' => 90,

    /**
     * When true, a .webp copy is saved alongside every resized image.
     */
    'outputWebp' => false,

    /**
     * To keep the original size
     */
    'keepOriginalSize' => true,

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
