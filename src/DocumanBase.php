<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

abstract class DocumanBase
{
    protected array $filesArr = [];

    public object $files;

    public function __construct(array $documanArr)
    {
        $this->set($documanArr);
    }

    public function set(array $documanArr): static
    {
        $this->filesArr = $documanArr;
        $this->files = documan_recursive_to_object($documanArr);
        return $this;
    }
}
