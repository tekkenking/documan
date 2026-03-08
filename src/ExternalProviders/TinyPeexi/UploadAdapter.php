<?php

namespace Tekkenking\Documan\ExternalProviders\TinyPeexi;

use Illuminate\Http\UploadedFile;
use Tekkenking\TinyPeexi\Facades\TinyPeexi;

class UploadAdapter implements \Tekkenking\Documan\Interface\ExternalUpload
{
    public function externalUpload(UploadedFile $file, array $sizes = []): array|bool
    {
        // Your tinyPeexi logic here
        $shaAssetsArr = TinyPeexi::uploadMany($file);

        return $this->forDocuman($shaAssetsArr);
    }

    /**
     * Summary of toDocuman
     *
     * @return array<array|string>
     */
    public function forDocuman(string|array $assets): string|array
    {
        $fileNameInSizes = [];
        if (! is_array($assets)) {
            $fileNameInSizes['base_name'] = $assets[0];
        } else {
            foreach ($assets as $sha) {
                $fileNameInSizes['base_name'][] = $sha;
            }
        }

        return $fileNameInSizes;

    }
}
