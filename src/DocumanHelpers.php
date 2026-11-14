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
        $usesDocumanPath = is_object($imagePath) && method_exists($imagePath, 'localPath');
        $resolvedPath = $usesDocumanPath
            ? $imagePath->localPath($size)
            : (string) $imagePath;

        if (filter_var($resolvedPath, FILTER_VALIDATE_URL) || str_starts_with($resolvedPath, 'file://') || !is_file($resolvedPath)) {
            return '';
        }

        $stream = @fopen($resolvedPath, 'rb');
        if ($stream === false) {
            return '';
        }

        try {
            return documan_base64_from_stream($stream);
        } finally {
            fclose($stream);
        }
    }
}

if (! function_exists('documan_base64_from_stream')) {
    /**
     * Encode a stream in chunks, so a large image is never held in memory
     * twice. Chunks are cut on a 3-byte boundary so the pieces join cleanly.
     *
     * @param resource $stream
     */
    function documan_base64_from_stream($stream): string
    {
        $encoded = '';
        $carry = '';

        while (!feof($stream)) {
            $chunk = fread($stream, 12288);
            if ($chunk === false) {
                return '';
            }

            $chunk = $carry . $chunk;
            $completeLength = strlen($chunk) - (strlen($chunk) % 3);
            $encoded .= base64_encode(substr($chunk, 0, $completeLength));
            $carry = substr($chunk, $completeLength);
        }

        return $encoded . base64_encode($carry);
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

if (! function_exists('documan_extensions_for_mime')) {
    /**
     * Every extension that is correct for a MIME type, the usual one first.
     *
     * @return array<int, string>
     */
    function documan_extensions_for_mime(string $mimeType): array
    {
        if ($mimeType === 'image/jpeg') {
            return ['jpg', 'jpeg'];
        }

        $extension = documan_safe_extension_from_mime($mimeType);

        return $extension === 'bin' ? [] : [$extension];
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
