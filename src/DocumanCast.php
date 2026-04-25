<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Str;

/** @implements CastsAttributes<Documan, mixed> */
class DocumanCast implements CastsAttributes
{
    public string $disk = '';

    public array $sizes = [];

    private bool $is_remote = false;

    public function __construct(private readonly string $option)
    {
        // How many options
        $options = explode(':', $this->option);

        $disk = $options[0];

        // Let's check if the setDisk has remote prefix
        if (str_starts_with($disk, 'remote')) {
            // This means it's a remote disk
            $this->is_remote = true;
            $disk = str_replace('remote_', '', $disk);
        }

        $this->setDisk($disk);

        if (isset($options[1])) {
            $this->setSizes(explode('|', $options[1]));
        }

    }

    /**
     * @return void
     */
    private function setDisk($disk)
    {
        $this->disk = $disk;
    }

    /**
     * @return void
     */
    private function setSizes($sizes)
    {
        $this->sizes = $sizes;
    }

    public function get($model, string $key, $value, array $attributes)
    {
        if (! $value) {
            return $value;
        }

        $documan = new Documan;
        if (Str::startsWith($value, 'http')) {
            return $documan->plain($value);
        }

        $doc = $this->chooseDisk($documan);

        return $doc->show($value);
    }

    private function chooseDisk($documan)
    {
        if ($this->is_remote) {
            $doc = $documan->remoteDisk($this->disk);
        } else {
            $doc = $documan->setDisk($this->disk);
        }

        return $doc;
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value instanceof Documan) {
            return $value->showFileName();
        }

        if (request()->hasFile($value)) {
            $documan = $this->chooseDisk(new Documan);
            $documan->sizesInArr($this->sizes);
            $filesArr = $documan->upload(request(), $value);
            if (isset($filesArr['base_name'])) {
                return $filesArr['base_name'];
            }

            $this->throwException('Oops! Sorry, DocumanCast does not support multiple file uploads! Use the direct method (documan())');

            // return $filesArr;
        } else {
            return $value ?? null;
        }
    }

    private function getValueExtension($value): string
    {
        return str($value)->afterLast('.')->value;
    }

    private function throwException($msg)
    {
        throw new DocumanException($msg);
    }
}
