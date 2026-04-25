<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait ReadDocuman
{
    /**
     * @return $this|Documan|mixed|string|void
     */
    public function __call($method, $args)
    {
        // Special case: custom(width, height) — works in both upload and show mode
        if ($method === 'custom') {
            if ($this->showFile) {
                return $this->getDocBySize($args[0] ?? 'custom', $args);
            }
            if (count($args) >= 2) {
                $customSize = ['width' => (int) $args[0], 'height' => (int) $args[1]];
                $this->defaultSizes['custom'] = $customSize;
                $this->chosenSizes['custom'] = $customSize;
            }
            return $this;
        }

        if (isset($this->defaultSizes[$method])) {
            if (!$this->showFile) {
                // Upload mode: register the size to process
                $this->chosenSizes[$method] = $this->defaultSizes[$method];
                return $this;
            }

            return $this->getDocBySize($method, $args);
        }

        throw new DocumanException($method . ' method call is not allowed in documan');
    }

    private function buildShow($size, $fileName, $onlyFileName): Documan
    {
        if ($this->config['externalAdapter']['enabled']) {
            // Your external provider show logic here
            $adapterClass = $this->config['externalAdapter']['adapter']['show'];
            $adapter = new $adapterClass;
            $this->arrFilesToShow[] = $adapter->externalShow($fileName, $size);

            return $this;
        }

        $fileNameBySize = $size.'_'.$fileName;

        if ($this->remoteHost) {
            $this->arrFilesToShow[] = $this->remoteHost.'/'.$fileNameBySize;

            return $this;
        }

        $this->isDiskSet();
        $fileSystemDisk = $this->getFileSystemDisk($this->getDisk());

        if (!file_exists($fileSystemDisk['root'] . '/' . $fileNameBySize)) {
            // Supporting those files without the size prefix in their naming
            $fileNameBySize = $fileName;
        }

        $this->_arrayFileNames($onlyFileName, $fileSystemDisk, $fileNameBySize);

        return $this;
    }

    public function show(string $showFile, bool $onlyFileName = false): static
    {
        $this->showFile = $showFile;
        $this->onlyFileName = $onlyFileName;

        return $this;
    }

    public function showFileName(): ?string
    {
        return $this->showFile;
    }

    /**
     * @return mixed|string
     */
    public function first(): mixed
    {
        if (count($this->arrFilesToShow) > 0) {
            return $this->arrFilesToShow[0];
        }

        return '';
    }

    public function get(): Collection
    {
        return collect($this->arrFilesToShow);
    }

    public function getExtension(): string
    {
        if (!$this->showFile) {
            return '';
        }
        return pathinfo($this->showFile, PATHINFO_EXTENSION);
    }

    public function getType(): string
    {
        $ext = strtolower($this->getExtension());
        $map = [
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
            'pdf' => 'pdf',
            'doc' => 'document', 'docx' => 'document',
            'xls' => 'excel', 'xlsx' => 'excel', 'csv' => 'excel',
            'ppt' => 'powerpoint', 'pptx' => 'powerpoint',
        ];
        return $map[$ext] ?? 'other';
    }

    public function mimeType(): string
    {
        $ext = strtolower($this->getExtension());
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    public function localPath($size): string
    {
        if (Str::startsWith($this->showFile, 'http')) {
            return $this->showFile;
        }

        $fileName = $size.'_'.$this->showFile;
        $fileSystemDisk = $this->getFileSystemDisk($this->getDisk());
        $localFile = $fileSystemDisk['root'].'/'.$fileName;

        if (! file_exists($localFile)) {
            return $fileSystemDisk['root'].'/'.$this->showFile;
        }

        return $localFile;
    }


}
