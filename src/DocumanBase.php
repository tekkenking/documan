<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

abstract class DocumanBase
{
    protected array $filesArr;

    public object $files;

    public function __construct(array $documanArr)
    {
        $this->set($documanArr);
    }

    public function set(array $documanArr): static
    {
        $this->filesArr = $documanArr;
        $this->files = (object) json_decode(json_encode($documanArr));
        return $this;
    }
}
