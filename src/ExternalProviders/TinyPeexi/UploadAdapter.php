<?php

namespace Tekkenking\Documan\ExternalProviders\TinyPeexi;

use Illuminate\Http\UploadedFile;
use Tekkenking\TinyPeexi\Facades\TinyPeexi;

class UploadAdapter implements \Tekkenking\Documan\Interface\ExternalUpload
{
    public function externalUpload(UploadedFile|array $file, array $sizes = []): array|bool
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
    public function forDocuman(array $assets): string|array
    {
        $fileNameInSizes = [];
        if (count($assets) === 1) {
            $fileNameInSizes['base_name'] = $assets[0]->sha;
        } else {
            foreach ($assets as $asset) {
                $fileNameInSizes[]['base_name'] = $asset->sha;
            }
        }

        return $fileNameInSizes;

    }
}
