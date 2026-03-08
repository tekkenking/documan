<?php

namespace Tekkenking\Documan\Interface;

interface ExternalShow
{
    public function externalShow(string $fileSha, int|string|null $size = null): string;

    public function resolveSize(int|string|null $size): int;
}
