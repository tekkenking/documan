<?php

namespace Tekkenking\Documan\ExternalProviders\TinyPeexi;

class ShowAdapter implements \Tekkenking\Documan\Interface\ExternalShow
{
    public function externalShow(string $fileSha, int|string|null $size = null): string
    {
        $size = $this->resolveSize($size);

        return tinypeexi($fileSha)
            ->resize($size)
            ->format('webp')
            ->url();
    }

    public function resolveSize(int|string|null $size): int
    {
        if (is_string($size)) {
            $size = strtolower($size);
            $sizes = config('documan.defaultImageSizes');
            if (isset($sizes[$size])) {
                return (int) ($sizes[$size]['width'] ?? 1600);
            }
        }

        return (int) $size;
    }
}
