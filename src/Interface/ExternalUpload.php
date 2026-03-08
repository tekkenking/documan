<?php

namespace Tekkenking\Documan\Interface;

use Illuminate\Http\Request;

interface ExternalUpload
{
    public function externalUpload(Request $file, array $sizes = []): array|bool;

    public function forDocuman(string|array $assets): string|array;
}
