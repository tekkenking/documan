<?php

namespace Tekkenking\Documan;

trait ImageSizes
{

    /**
     * @var array
     */
    private array $defaultSizes = [];

    public function sizesInArr(array $sizes=[]): Documan
    {
        return $this->sizes($sizes);
    }

    public function sizes(array $sizes = []): Documan
    {
        if (!empty($sizes)) {
            $workingSizes = [];

            foreach ($sizes as $size => $value) {
                if (is_int($size)) {
                    // Indexed array: reference an existing default size by name
                    if (!isset($this->defaultSizes[$value])) {
                        throw new DocumanException("{$value} is not a valid size");
                    }
                    $workingSizes[$value] = $this->validateSizeDefinition(
                        (string) $value,
                        $this->defaultSizes[$value]
                    );
                } else {
                    // Associative array: must be ['width' => ..., 'height' => ...]
                    if (!is_array($value)) {
                        throw new DocumanException("{$size} value must be properly formed array with width or height or both");
                    }

                    if (isset($this->defaultSizes[$size])) {
                        // Override an existing default size at runtime without mutating $defaultSizes
                        $sizeDefinition = $this->defaultSizes[$size];
                        if (isset($value['width'])) {
                            $sizeDefinition['width'] = $value['width'];
                        }
                        if (isset($value['height'])) {
                            $sizeDefinition['height'] = $value['height'];
                        }
                    } else {
                        // Register a brand-new custom size at runtime
                        if (!isset($value['width']) || !isset($value['height'])) {
                            throw new DocumanException("{$size} value must be properly formed array with width and height");
                        }
                        $sizeDefinition = ['width' => $value['width'], 'height' => $value['height']];
                    }

                    $workingSizes[$size] = $this->validateSizeDefinition((string) $size, $sizeDefinition);
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

    protected function validateSizeDefinition(string $name, array $definition): array
    {
        $this->assertSafeStorageFileName($name);

        $width = $definition['width'] ?? null;
        $height = $definition['height'] ?? null;

        if (
            (!is_int($width) && !(is_string($width) && ctype_digit($width)))
            || (!is_int($height) && !(is_string($height) && ctype_digit($height)))
        ) {
            throw new DocumanException("The {$name} size must have integer width and height values.");
        }

        $width = (int) $width;
        $height = (int) $height;
        if ($width <= 0 || $height <= 0) {
            throw new DocumanException("The {$name} size dimensions must be positive.");
        }

        $maxPixels = (int) ($this->config['maxImagePixels'] ?? 40000000);
        if ($maxPixels > 0 && $width > intdiv($maxPixels, $height)) {
            throw new DocumanException("The {$name} size exceeds the configured maximum pixel count.");
        }

        return ['width' => $width, 'height' => $height];
    }

    /**
     * @param string $key
     * @param array $newSize
     * @return void
     */
    private function sizeSetter(string $key, array $newSize): void
    {

        if(!empty($newSize)) {
            $width = (isset($newSize['w']))
                ? $newSize['w']
                : ($newSize['width'] ?? null);

            $height = (isset($newSize['h']))
                ? $newSize['h']
                : ($newSize['height'] ?? null);

            $this->defaultSizes[$key] = $this->validateSizeDefinition($key, [
                'width' => $width,
                'height' => $height,
            ]);
        }

        $this->defaultSizes[$key] = $this->validateSizeDefinition($key, $this->defaultSizes[$key] ?? []);
        $this->addToChoseSizes($key);
    }

}
