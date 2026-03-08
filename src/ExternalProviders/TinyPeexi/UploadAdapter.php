<?php

namespace Tekkenking\Documan\ExternalProviders\TinyPeexi;

class UploadAdapter implements \Tekkenking\Documan\Interface\ExternalUpload
{
    public function externalUpload(Request $file, array $sizes = []): array|bool
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
            $fileNameInSizes['base_name'] = $assets;
        } else {
            foreach ($assets as $sha) {
                $fileNameInSizes['base_name'][] = $sha;
            }
        }

        return $fileNameInSizes;

    }
}
