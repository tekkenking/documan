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

        $this->assertSafeStorageFileName((string) $fileName);
        $this->assertSafeStorageFileName((string) $size);
        $fileNameBySize = $size.'_'.$fileName;

        if ($this->remoteHost) {
            $this->arrFilesToShow[] = $this->remoteHost.'/'.$fileNameBySize;

            return $this;
        }

        $this->isDiskSet();
        $disk = $this->getDisk();

        if ($this->isLocalDisk($disk)) {
            $fileSystemDisk = $this->getFileSystemDisk($disk);

            if (!file_exists($fileSystemDisk['root'] . '/' . $fileNameBySize)) {
                // Supporting those files without the size prefix in their naming
                $fileNameBySize = $fileName;
            }

            $this->_arrayFileNames($onlyFileName, $fileSystemDisk, $fileNameBySize);

            return $this;
        }

        // Remote/S3-compatible disk (e.g. DigitalOcean Spaces) — never assume
        // a local 'root' path exists. Resolve the public URL through the
        // disk's own driver instead of touching the local filesystem.
        if (!Storage::disk($disk)->exists($fileNameBySize)) {
            // Supporting those files without the size prefix in their naming
            $fileNameBySize = $fileName;
        }

        if ($onlyFileName) {
            $this->arrFilesToShow[] = $fileNameBySize;
        } else {
            // For private files on remote disks, generate a signed URL
            $url = Storage::disk($disk)->url($fileNameBySize);
            
            if ($this->getVisibility() === 'private') {
                try {
                    $expiry = $this->config['s3_url_expiry'] ?? 1440;
                    $url = Storage::disk($disk)->temporaryUrl(
                        $fileNameBySize,
                        now()->addMinutes($expiry)
                    );
                } catch (\Exception $e) {
                    // If temporaryUrl fails (e.g., driver doesn't support it),
                    // fall back to the regular URL and log the issue
                    logger()->warning('Documan: Failed to generate temporary URL for private file', [
                        'disk' => $disk,
                        'file' => $fileNameBySize,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            $this->arrFilesToShow[] = $url;
        }

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
            if (!Storage::disk($disk)->exists($fileName)) {
                $fileName = $this->showFile;
            }

            $url = Storage::disk($disk)->url($fileName);
            
            // For private files on remote disks, generate a signed URL
            if ($this->getVisibility() === 'private') {
                try {
                    $expiry = $this->config['s3_url_expiry'] ?? 1440;
                    $url = Storage::disk($disk)->temporaryUrl(
                        $fileName,
                        now()->addMinutes($expiry)
                    );
                } catch (\Exception $e) {
                    logger()->warning('Documan: Failed to generate temporary URL for private file in localPath', [
                        'disk' => $disk,
                        'file' => $fileName,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            return $url;
        }

        $fileSystemDisk = $this->getFileSystemDisk($disk);
        $localFile = $fileSystemDisk['root'].'/'.$fileName;

        if (! file_exists($localFile)) {
            return $fileSystemDisk['root'].'/'.$this->showFile;
        }

        return $localFile;
    }


}
