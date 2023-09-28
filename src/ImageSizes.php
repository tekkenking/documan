<?php

namespace Tekkenking\Documan;

trait ImageSizes
{

    /**
     * @var array
     */
    private array $defaultSizes = [];

    /**
     * @param array $newSize [optional] $newSize
     * @return Documan
     */
    public function medium(array $newSize = []): Documan
    {
        $this->sizeSetter('medium', $newSize);
        return $this;
    }

    /**
     * @param array $newSize [optional] $newSize
     * @return Documan
     */
    public function thumbnail(array $newSize = []): Documan
    {
        $this->sizeSetter('thumbnail', $newSize);
        return $this;
    }

    /**
     * @param array $newSize [optional] $newSize
     * @return Documan
     */
    public function small(array $newSize = []): Documan
    {
        $this->sizeSetter('small', $newSize);
        return $this;
    }


    /**
     * @param string $key
     * @param array $newSize
     * @return void
     */
    private function sizeSetter(string $key, array $newSize): void
    {

        if(!empty($newSize)) {
            $this->defaultSizes[$key]['width'] = (isset($newSize['w']))
                ? $newSize['w']
                : $newSize['width'];

            $this->defaultSizes[$key]['height'] = (isset($newSize['h']))
                ? $newSize['h']
                : $newSize['height'];
        }

    }

}
