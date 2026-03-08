<?php

namespace Tekkenking\Documan\Interface;

use Illuminate\Http\UploadedFile;

interface ExternalUpload
{
    public function externalUpload(UploadedFile $file, array $sizes = []): array|bool;

    public function forDocuman(string|array $assets): string|array;
}
