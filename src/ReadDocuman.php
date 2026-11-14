<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
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
                $customSize = ['width' => $args[0], 'height' => $args[1]];
                $customSize = $this->validateSizeDefinition('custom', $customSize);
                $this->defaultSizes['custom'] = $customSize;
                $this->chosenSizes['custom'] = $customSize;
            }
            return $this;
        }

        if ($method === 'original' && $this->showFile) {
            return $this->getDocBySize('original', $args);
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
        $this->arrFilesToShow[] = $this->resolveShowValue((string) $size, (string) $fileName, (bool) $onlyFileName);

        return $this;
    }

    /**
     * The URL, or the file name when $onlyFileName is set, of one size.
     */
    private function resolveShowValue(string $size, string $fileName, bool $onlyFileName): string
    {
        if ($this->config['externalAdapter']['enabled']) {
            $adapterClass = $this->config['externalAdapter']['adapter']['show'];

            return (string) (new $adapterClass)->externalShow($fileName, $size);
        }

        $this->assertSafeStorageFileName($fileName);
        $this->assertSafeStorageFileName($size);
        $fileNameBySize = $size.'_'.$fileName;

        if ($this->remoteHost) {
            return $this->remoteHost.'/'.$fileNameBySize;
        }

        $this->isDiskSet();
        $disk = $this->getDisk();

        if ($this->isLocalDisk($disk)) {
            $fileSystemDisk = $this->getFileSystemDisk($disk);

            //The original is stored without a prefix, and so are files
            //from before sizes were prefixed.
            if (!Storage::disk($disk)->exists($fileNameBySize)) {
                $fileNameBySize = $fileName;
            }

            return $this->localFileUrl($onlyFileName, $fileSystemDisk, $fileNameBySize);
        }

        $fileNameBySize = $this->remoteFileName($disk, $fileNameBySize, $fileName);

        return $onlyFileName ? $fileNameBySize : $this->remoteUrl($disk, $fileNameBySize);
    }

    /**
     * The name to serve from a remote disk.
     *
     * Checking that the variant exists costs one request to the storage
     * provider per image shown. 'verifyVariantExists' => false skips it and
     * trusts the name, which suits a page listing many images.
     */
    private function remoteFileName(string $disk, string $fileNameBySize, string $fileName): string
    {
        if (!($this->config['verifyVariantExists'] ?? true)) {
            //The original carries no prefix, so its name is known without asking.
            return str_starts_with($fileNameBySize, 'original_') ? $fileName : $fileNameBySize;
        }

        return Storage::disk($disk)->exists($fileNameBySize) ? $fileNameBySize : $fileName;
    }

    /**
     * A public URL, or a signed temporary one when the files are private.
     */
    private function remoteUrl(string $disk, string $fileName): string
    {
        $storage = $this->urlDisk($disk);

        if ($this->getVisibility() !== 'private') {
            return $storage->url($fileName);
        }

        try {
            $expiry = $this->config['s3_url_expiry'] ?? 1440;

            return $storage->temporaryUrl($fileName, now()->addMinutes($expiry));
        } catch (\Exception $e) {
            //Not every driver can sign a URL; fall back to the plain one.
            logger()->warning('Documan: Failed to generate temporary URL for private file', [
                'disk' => $disk,
                'file' => $fileName,
                'error' => $e->getMessage(),
            ]);

            return $storage->url($fileName);
        }
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
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
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
            'webp' => 'image/webp',
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

    /**
     * Resolve the "local" path/URL for a given size variant.
     *
     * For local disks this returns an actual filesystem path (existing
     * behaviour, unchanged). For remote/S3-compatible disks (e.g.
     * DigitalOcean Spaces) there is no local filesystem path — instead the
     * disk's public URL is returned via Storage::disk($disk)->url(), or a
     * temporary signed URL if the file is private.
     */
    public function localPath(string|int $size): string
    {
        if (Str::startsWith($this->showFile, 'http')) {
            return $this->showFile;
        }

        $this->isDiskSet();
        $this->assertSafeStorageFileName($this->showFile ?? '');
        $this->assertSafeStorageFileName((string) $size);
        $fileName = $size.'_'.$this->showFile;
        $disk = $this->getDisk();

        if (!$this->isLocalDisk($disk)) {
            return $this->remoteUrl($disk, $this->remoteFileName($disk, $fileName, (string) $this->showFile));
        }

        $fileSystemDisk = $this->getFileSystemDisk($disk);
        $localFile = $fileSystemDisk['root'].'/'.$fileName;

        if (! file_exists($localFile)) {
            return $fileSystemDisk['root'].'/'.$this->showFile;
        }

        return $localFile;
    }


}
