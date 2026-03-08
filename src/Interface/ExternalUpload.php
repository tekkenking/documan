<?php

namespace Tekkenking\Documan\Interface;

use Illuminate\Http\UploadedFile;

interface ExternalUpload
{
    public function externalUpload(UploadedFile|array $file, array $sizes = []): array|bool;

    public function forDocuman(array $assets): string|array;
}
