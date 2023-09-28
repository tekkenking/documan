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
     * @param $imagePath
     * @param $size
     * @return string
     */
    function convertImageToBase64($imagePath, $size = 'original'): string
    {
        return base64_encode(file_get_contents($imagePath->localPath($size)));
    }
}

