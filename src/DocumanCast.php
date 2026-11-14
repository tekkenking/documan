<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** @implements CastsAttributes<Documan|string, mixed> */
class DocumanCast implements CastsAttributes, SerializesCastableAttributes
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
            //An empty column stays as it is stored.
            return is_string($value) ? $value : null;
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

        if ($value instanceof UploadedFile || (is_array($value) && $this->isUploadedFileArray($value))) {
            $documan = $this->chooseDisk(new Documan);
            $documan->sizesInArr($this->sizes);
            $filesArr = $documan->upload_without_request($value);
            if (isset($filesArr['base_name'])) {
                return $filesArr['base_name'];
            }

            $this->throwException('Oops! Sorry, DocumanCast does not support multiple file uploads! Use the direct method (documan())');
        }

        if (is_string($value) && request()->hasFile($value)) {
            $documan = $this->chooseDisk(new Documan);
            $documan->sizesInArr($this->sizes);
            $filesArr = $documan->upload(request(), $value);
            if (isset($filesArr['base_name'])) {
                return $filesArr['base_name'];
            }

            $this->throwException('Oops! Sorry, DocumanCast does not support multiple file uploads! Use the direct method (documan())');
        }

        return $value ?? null;
    }

    /**
     * In toArray() and toJson() the attribute is the stored file name, as
     * it is in the database. Without this the model serialised an object
     * with none of the file's details in it.
     */
    public function serialize($model, string $key, $value, array $attributes)
    {
        return $value instanceof Documan ? $value->showFileName() : $value;
    }

    private function isUploadedFileArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        foreach ($value as $file) {
            if (! $file instanceof UploadedFile) {
                return false;
            }
        }

        return true;
    }

    private function throwException($msg)
    {
        throw new DocumanException($msg);
    }
}
