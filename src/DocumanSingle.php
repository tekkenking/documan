<?php

namespace Tekkenking\Documan;

class DocumanSingle
{
    private mixed $filesArr;

    public function __construct(array $documanArr)
    {
        return $this->set($documanArr);
    }

    public function set($documanArr)
    {
        $this->filesArr = $documanArr;
        $this->files = json_decode(json_encode($documanArr));
        //$this->convertToObject($this->filesArr);
        return $this;
    }

}
