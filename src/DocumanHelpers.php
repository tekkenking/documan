<?php

use Tekkenking\Documan\Documan as DocumanAlias;
use Tekkenking\Documan\DocumanCollections;

if(! function_exists('documan')) {
    function documan(string $disk = '') {
        return app('documan', [$disk]);
    }
}

if(! function_exists('documan_collections')) {
    function documan_collections($documan) {
       return new DocumanCollections($documan);
    }
}

if (!function_exists('convertImageToBase64')) {

    /**
     * @param mixed $imagePath
     * @param string $size
     * @return string
     */
    function convertImageToBase64($imagePath, $size = 'original'): string
    {
        $resolvedPath = is_object($imagePath) && method_exists($imagePath, 'localPath')
            ? $imagePath->localPath($size)
            : (string) $imagePath;

        $contents = @file_get_contents($resolvedPath);

        return $contents === false ? '' : base64_encode($contents);
    }
}


if (! function_exists('documan_mime_group')) {
    function documan_mime_group(string $mimeType): ?string
    {
        static $map = [
            'image/jpeg' => 'image',
            'image/png' => 'image',
            'image/gif' => 'image',
            'image/webp' => 'image',
            'application/vnd.ms-excel' => 'excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'excel',
            'text/csv' => 'excel',
            'application/msword' => 'document',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'document',
            'application/vnd.ms-powerpoint' => 'powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'powerpoint',
            'application/pdf' => 'pdf',
        ];

        return $map[$mimeType] ?? null;
    }
}

if (! function_exists('documan_safe_extension_from_mime')) {
    function documan_safe_extension_from_mime(string $mimeType): string
    {
        static $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/csv' => 'csv',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/pdf' => 'pdf',
        ];

        return $map[$mimeType] ?? 'bin';
    }
}

if (! function_exists('documan_recursive_to_object')) {
    function documan_recursive_to_object(array $value): object
    {
        $object = new \stdClass();

        foreach ($value as $key => $item) {
            $object->{$key} = is_array($item)
                ? documan_recursive_to_object($item)
                : $item;
        }

        return $object;
    }
}
