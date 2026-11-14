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
        $workingSizes = [];

        foreach ($sizes as $size => $value) {
            //A bare name picks one of the configured sizes; a named entry
            //gives its own dimensions.
            $name = is_int($size) ? (string) $value : (string) $size;
            $workingSizes[$name] = $this->validateSizeDefinition($name, $this->sizeDefinitionFor($size, $value));
        }

        $this->chosenSizes = array_merge($this->chosenSizes, $workingSizes);

        return $this;
    }

    /**
     * @return array{width: mixed, height: mixed}
     */
    private function sizeDefinitionFor(int|string $size, mixed $value): array
    {
        if (is_int($size)) {
            if (!is_string($value) || !isset($this->defaultSizes[$value])) {
                throw new DocumanException((is_scalar($value) ? $value : 'That').' is not a valid size');
            }

            return $this->defaultSizes[$value];
        }

        if (!is_array($value)) {
            throw new DocumanException("{$size} value must be properly formed array with width or height or both");
        }

        if (isset($this->defaultSizes[$size])) {
            //A configured size, with either dimension overridden for this call.
            return [
                'width'  => $value['width'] ?? $this->defaultSizes[$size]['width'],
                'height' => $value['height'] ?? $this->defaultSizes[$size]['height'],
            ];
        }

        if (!isset($value['width']) || !isset($value['height'])) {
            throw new DocumanException("{$size} value must be properly formed array with width and height");
        }

        return ['width' => $value['width'], 'height' => $value['height']];
    }

    /**
     * @param array $newSize [optional] $newSize
     * @return Documan
     */
    public function medium(array $newSize = []): Documan
    {
        //After show(), a size names the variant to fetch rather than one
        //to create.
        if ($this->showFile) {
            return $this->buildShow('medium', $this->showFile, $this->onlyFileName);
        }

        $this->sizeSetter('medium', $newSize);
        return $this;
    }

    /**
     * @param array $newSize [optional] $newSize
     * @return Documan
     */
    public function thumbnail(array $newSize = []): Documan
    {
        //After show(), a size names the variant to fetch rather than one
        //to create.
        if ($this->showFile) {
            return $this->buildShow('thumbnail', $this->showFile, $this->onlyFileName);
        }

        $this->sizeSetter('thumbnail', $newSize);
        return $this;
    }

    /**
     * @param array $newSize [optional] $newSize
     * @return Documan
     */
    public function small(array $newSize = []): Documan
    {
        //After show(), a size names the variant to fetch rather than one
        //to create.
        if ($this->showFile) {
            return $this->buildShow('small', $this->showFile, $this->onlyFileName);
        }

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
