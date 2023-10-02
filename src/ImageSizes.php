<?php

namespace Tekkenking\Documan;

trait ImageSizes
{

    /**
     * @var array
     */
    private array $defaultSizes = [];

    public function sizes(array $sizes = []): Documan
    {
        if(!empty($sizes)) {
            $workingSizes = [];

            foreach ($sizes as $size => $value) {
                if(is_int($size)) {
                    //This mean it's unassociated array
                    //Is this available amongst the default sizes
                    if(!isset($this->defaultSizes[$value])) {
                        dd("{$value} is not a valid size");
                    }
                    $workingSizes[$value] = $this->defaultSizes[$value];
                } else {
                    //Meaning it's an associated array that should have width and height

                    //Let's make sure it's properly formed with width and height
                    if(!is_array($value)) {
                        dd("{$size} value must be properly formed array with width or height or both");
                    } else {
                        if(isset($this->defaultSizes[$size])) {
                            //Meaning we want to overwrite config size at run time
                            if(isset($value['width'])) {
                                $this->defaultSizes[$size]['width'] = $value['width'];
                            }

                            if(isset($value['height'])) {
                                $this->defaultSizes[$size]['height'] = $value['height'];
                            }
                        } else {
                            //We are adding customer size type at run time
                            if(!isset($value['width']) || !isset($value['height'])) {
                                dd("{$size} value must be properly formed array with width and height");
                            }

                            $this->defaultSizes[$size]['width'] = $value['width'];
                            $this->defaultSizes[$size]['height'] = $value['height'];
                        }
                    }
                    $workingSizes[$size] = $this->defaultSizes[$size];
                }
            }

            $this->chosenSizes = array_merge($this->chosenSizes, $workingSizes);

        }
        return $this;
    }

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

    public function addToChoseSizes($key)
    {
        $this->chosenSizes[$key] = $this->defaultSizes[$key];
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

        $this->addToChoseSizes($key);
    }

}
