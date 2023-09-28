<?php

namespace Tekkenking\Documan;

use stdClass;

class DocumanCollections
{
    private $filesArr;

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

    /*private function convertToObject($array)
    {
        $object = new stdClass();
        foreach ($array as $keyx => $value) {
            if (is_array($value)) {
                $value = $this->convertToObject($value);
            }
            $object->$keyx = $value;
        }

        //dd($object);
        return $object;
    }*/

    public function toArray()
    {
        return $this->filesArr;
    }

    /*public function toBase64($size = 'original')
    {
        return convertImageToBase64($this->documan, $size);
    }*/

}
