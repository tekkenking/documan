<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

class DocumanCollections extends DocumanBase
{
    public function toArray(): array
    {
        return $this->filesArr;
    }
}
