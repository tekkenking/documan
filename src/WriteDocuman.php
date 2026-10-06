<?php

namespace Tekkenking\Documan;

use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

trait WriteDocuman
{
    public mixed $formFile = null;

    public function plain($value): static
    {
        $this->showFile = $value;

        return $this;
    }

    /**
     * @return array
     */
    public function upload(Request $request, string $inputName): array
    {
        if (!$request->hasFile($inputName)) {
            throw new DocumanException("No file found for input '{$inputName}'.");
        }

        $file = $request->file($inputName);
        $this->assertUploadLimits($file);

        $externalUploadResponse = $this->useExternalUploader($file);
        if ($externalUploadResponse) {
            return $externalUploadResponse;
        }

        return $this->upload_without_request($file);
    }

    private function useExternalUploader($file)
    {
        if ($this->config['externalAdapter']['enabled']) {
            // Your external uploader logic here
            $adapterClass = $this->config['externalAdapter']['adapter']['upload'];
            $adapter = new $adapterClass;

            return $adapter->externalUpload($file);
        }

        return false;
    }

    public function upload_without_request($file): DocumanCollections|array
    {
        $this->assertUploadLimits($file);

        $externalUploadResponse = $this->useExternalUploader($file);
        if ($externalUploadResponse) {
            return $externalUploadResponse;
        }

        $responseArr = $this->processUpload($file);
        if ($this->config['defaultReturn'] === 'array') {
            return $responseArr;
        }

        return $this->returnAsCollection($responseArr, (is_array($file)));
    }

    public function move(string|array $fileName, string $source_disk): array
    {
        $this->isDiskSet();
        $names = is_array($fileName) ? $fileName : [$fileName];
        $maxFiles = (int) ($this->config['maxFilesPerUpload'] ?? 20);
        if ($maxFiles > 0 && count($names) > $maxFiles) {
            throw new DocumanException('The upload contains more files than allowed.');
        }
        $sourceDisk = Storage::disk($source_disk);
        $temporaryFiles = [];
        $totalBytesCopied = 0;

        try {
            $files = [];
            foreach ($names as $name) {
                if (!is_string($name)) {
                    throw new DocumanException('File names must be strings.');
                }
                $this->assertSafeStorageFileName($name);

                try {
                    $stream = $sourceDisk->readStream($name);
                } catch (\Throwable) {
                    $stream = false;
                }

                if (!is_resource($stream)) {
                    try {
                        $stream = $sourceDisk->readStream('original_' . $name);
                    } catch (\Throwable) {
                        $stream = false;
                    }
                }

                if (!is_resource($stream)) {
                    throw new DocumanException("Unable to read '{$name}' from the source disk.");
                }

                $temporaryPath = tempnam(sys_get_temp_dir(), 'documan_move_');
                if ($temporaryPath === false) {
                    fclose($stream);
                    throw new RuntimeException('Unable to create a temporary file for moving.');
                }
                $temporaryFiles[] = $temporaryPath;

                $temporaryStream = fopen($temporaryPath, 'wb');
                if ($temporaryStream === false) {
                    fclose($stream);
                    throw new RuntimeException('Unable to write the temporary file for moving.');
                }

                $maxUploadSize = (int) ($this->config['maxUploadSizeBytes'] ?? 20971520);
                $copyLength = $maxUploadSize > 0 && $maxUploadSize < PHP_INT_MAX
                    ? $maxUploadSize + 1
                    : null;

                try {
                    $bytesCopied = stream_copy_to_stream($stream, $temporaryStream, $copyLength);
                } finally {
                    fclose($temporaryStream);
                    fclose($stream);
                }

                if ($bytesCopied === false || $bytesCopied === 0) {
                    throw new DocumanException("Unable to copy '{$name}' from the source disk.");
                }
                if ($maxUploadSize > 0 && $bytesCopied > $maxUploadSize) {
                    throw new DocumanException('The file exceeds the configured maximum upload size.');
                }
                $maxTotalSize = (int) ($this->config['maxTotalUploadSizeBytes'] ?? 41943040);
                if ($maxTotalSize > 0 && $bytesCopied > $maxTotalSize - $totalBytesCopied) {
                    throw new DocumanException('The upload exceeds the configured maximum total size.');
                }
                $totalBytesCopied += $bytesCopied;

                $mimeType = mime_content_type($temporaryPath) ?: 'application/octet-stream';
                $files[] = new UploadedFile(
                    $temporaryPath,
                    $name,
                    $mimeType,
                    UPLOAD_ERR_OK,
                    true
                );
            }

            return $this->processUpload(is_array($fileName) ? $files : ($files[0] ?? null));
        } finally {
            foreach ($temporaryFiles as $temporaryPath) {
                @unlink($temporaryPath);
            }
        }
    }

    protected function processUpload($file): array
    {
        $this->isDiskSet();

        // Original is now mandatory — always prepend it to whichever sizes the
        // caller selected. Using array union preserves an explicit 'original'
        // entry the caller may have added while guaranteeing it always exists.
        $this->chosenSizes = ['original' => ['width' => 999999, 'height' => 999999]] + $this->chosenSizes;

        $maxFiles = (int) ($this->config['maxFilesPerUpload'] ?? 20);
        if (is_array($file) && $maxFiles > 0 && count($file) > $maxFiles) {
            throw new DocumanException('The upload contains more files than allowed.');
        }
        $maxVariants = (int) ($this->config['maxVariantsPerUpload'] ?? 20);
        $imageCount = 0;
        foreach (is_array($file) ? $file : [$file] as $candidate) {
            $mimeType = $candidate instanceof UploadedFile ? $candidate->getMimeType() : null;
            if (is_string($mimeType) && documan_mime_group($mimeType) === 'image') {
                $imageCount++;
            }
        }
        $totalVariants = count($this->chosenSizes) * $imageCount;
        if ($maxVariants > 0 && $totalVariants > $maxVariants) {
            throw new DocumanException('The upload requests more image variants than allowed.');
        }
        foreach ($this->chosenSizes as $sizeName => $size) {
            if ($sizeName !== 'original') {
                $this->chosenSizes[$sizeName] = $this->validateSizeDefinition((string) $sizeName, $size);
            }
        }

        if (is_array($file)) {
            return $this->processUploadMultiple($file);
        }

        return $this->processUploadSingle($file);
    }

    protected function processUploadSingle($file): array
    {
        if (!$file instanceof UploadedFile) {
            throw new DocumanException('Only uploaded files can be processed.');
        }

        $maxUploadSize = (int) ($this->config['maxUploadSizeBytes'] ?? 20971520);
        $fileSize = $file->getSize();
        if ($maxUploadSize > 0 && ($fileSize === false || $fileSize > $maxUploadSize)) {
            throw new DocumanException('The file exceeds the configured maximum upload size.');
        }

        // Validate against actual MIME type (not client-supplied extension)
        $mimeType = $file->getMimeType();
        if (!is_string($mimeType) || $mimeType === '') {
            throw new DocumanException('Unable to determine the uploaded file type.');
        }
        $extnGroup = documan_mime_group($mimeType);

        if (!$extnGroup || !array_key_exists($extnGroup, $this->allowedFileExtensions)) {
            throw new DocumanException("File type '{$mimeType}' is not allowed.");
        }

        // Derive a safe extension from the MIME type rather than trusting the client
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensionsForGroup = $this->allowedFileExtensions[$extnGroup];
        if (!in_array($extension, $allowedExtensionsForGroup, true)) {
            // Fall back to a known-safe extension for this MIME type
            $extension = documan_safe_extension_from_mime($mimeType);
        }

        $fileName = Str::random();
        $this->prepareStoragePath();
        $this->filename = $fileName.'.'.$extension;

        $this->linkPath = '';
        $this->localPath = '';
        $fileSysDisk = $this->getFileSystemDisk($this->getDisk());
        if ($this->returnResultWithLinks) {
            $this->linkPath = (isset($fileSysDisk['url']))
                ? $fileSysDisk['url']
                : null;
        }

        if ($this->returnResultWithPaths) {
            $this->localPath = (isset($fileSysDisk['root']))
                ? $fileSysDisk['root']
                : null;
        }

        $this->formFile = $file;
        if ($extnGroup === 'image') {
            return $this->_processImage($extnGroup, $fileName, $extension);
        }

        return $this->_processOtherDocs($extnGroup);

    }

    private function _processOtherDocs($extnGroup): array
    {
        $fileNameInSizes['fileType'] = $extnGroup;
        $fileNameInSizes['base_name'] = $this->filename;

        $this->putFileFromPath($this->formFile->getRealPath(), $this->filename);

        if ($this->returnResultWithLinks) {
            $fileNameInSizes['link'] = ($this->linkPath)
                ? $this->linkPath.'/'.$this->filename
                : null;
        }

        if ($this->returnResultWithPaths) {
            $fileNameInSizes['path'] = ($this->localPath)
                ? $this->localPath.'/'.$this->filename
                : null;
        }

        return $fileNameInSizes;
    }

    private function assertUploadLimits(mixed $files): void
    {
        $files = is_array($files) ? $files : [$files];
        $maxFiles = (int) ($this->config['maxFilesPerUpload'] ?? 20);
        if ($maxFiles > 0 && count($files) > $maxFiles) {
            throw new DocumanException('The upload contains more files than allowed.');
        }

        $maxUploadSize = (int) ($this->config['maxUploadSizeBytes'] ?? 20971520);
        $maxTotalSize = (int) ($this->config['maxTotalUploadSizeBytes'] ?? 41943040);
        $totalSize = 0;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                throw new DocumanException('Only uploaded files can be processed.');
            }

            $fileSize = $file->getSize();
            if ($maxUploadSize > 0 && ($fileSize === false || $fileSize > $maxUploadSize)) {
                throw new DocumanException('The file exceeds the configured maximum upload size.');
            }
            if ($fileSize === false || ($maxTotalSize > 0 && $fileSize > $maxTotalSize - $totalSize)) {
                throw new DocumanException('The upload exceeds the configured maximum total size.');
            }
            if ($maxTotalSize > 0) {
                $totalSize += $fileSize;
            }
        }
    }

    private function _processImage(string $extnGroup, string $fileName, string $extension): array
    {
        $fileNameInSizes['fileType'] = $extnGroup;
        $fileNameInSizes['base_name'] = $this->filename;

        $queueEnabled = (bool) ($this->config['queue']['enabled'] ?? false);
        $queueConnection = $this->config['queue']['connection'] ?? null;
        $queueName = $this->config['queue']['name'] ?? null;

        // The original is the base_name itself — no prefix.
        // It is always stored synchronously so queue jobs have a source to read from.
        $baseFileName = $fileName . '.' . $extension;   // == $this->filename at this point

        [$localSourcePath, $isTempSourcePath] = $this->resolveLocalImageSourcePath();

        try {
            // Always persist the original immediately (idempotent).
            $this->putFileFromPath($localSourcePath, $baseFileName);

            foreach ($this->chosenSizes as $key => $size) {
                if ($key === 'original') {
                    // Original is already stored above as the plain base_name.
                    $this->filename = $baseFileName;
                } elseif ($queueEnabled) {
                    $this->filename = $key . '_' . $fileName . '.' . $extension;

                    $job = new \Tekkenking\Documan\Jobs\ProcessDocumanImage(
                        disk: $this->getDisk(),
                        sourceFileName: $baseFileName,   // plain base_name, no prefix
                        targetFileName: $this->filename,
                        width: $size['width'],
                        height: $size['height'],
                        visibility: $this->getVisibility(),
                    );

                    if ($queueConnection) {
                        $job->onConnection($queueConnection);
                    }

                    if ($queueName) {
                        $job->onQueue($queueName);
                    }

                    dispatch($job);
                } else {
                    $this->filename = $key . '_' . $fileName . '.' . $extension;

                    $imageProcessor = new ImageResizer($this->getDisk());
                    $imageProcessor->setVisibility($this->getVisibility());
                    $imageProcessor->resizeAndPreserveExif(
                        $localSourcePath,
                        $this->filename,
                        $size['width'],
                        $size['height']
                    );
                }

                $fileNameInSizes['variations'][$key] = $this->filename;

                if ($this->returnResultWithLinks) {
                    $fileNameInSizes['links'][$key] = ($this->linkPath)
                        ? $this->linkPath . '/' . $this->filename
                        : null;
                }

                if ($this->returnResultWithPaths) {
                    $fileNameInSizes['paths'][$key] = ($this->localPath)
                        ? $this->localPath . '/' . $this->filename
                        : null;
                }
            }
        } finally {
            // Only clean up temp files that we materialized from a remote
            // disk. Locally-uploaded files (UploadedFile tmp paths, or
            // caller-provided local paths) are left untouched — their
            // lifecycle is not owned by us.
            if ($isTempSourcePath) {
                @unlink($localSourcePath);
            }
        }

        return $fileNameInSizes;
    }

    /**
     * Resolve a local filesystem path that can be handed to the image
     * resizer. Supports:
     *
     *  - an UploadedFile (its PHP tmp upload path is always local)
     *  - an already-local absolute path (string, existing file)
     *  - an object key on a remote/S3-compatible disk (e.g. DigitalOcean
     *    Spaces) — the object is streamed to a secure local temp file so it
     *    can be processed by the resizer.
     *
     * @return array{0: string, 1: bool} [$localPath, $isTemporaryFile]
     */
    private function resolveLocalImageSourcePath(): array
    {
        if ($this->formFile instanceof UploadedFile) {
            $path = $this->formFile->getRealPath();

            if ($path !== false && $path !== '') {
                return [$path, false];
            }
        }

        if (is_string($this->formFile) && is_file($this->formFile)) {
            return [$this->formFile, false];
        }

        // Not a local file — if we have a disk configured and the value
        // looks like a valid object key/path, try to fetch it from the
        // configured (potentially remote) disk.
        if (is_string($this->formFile) && $this->formFile !== '' && $this->getDisk()) {
            $disk = $this->getDisk();

            try {
                $stream = Storage::disk($disk)->readStream($this->formFile);
            } catch (\Throwable $e) {
                $stream = null;
            }

            if (is_resource($stream)) {
                $tmpPath = tempnam(sys_get_temp_dir(), 'documan_');

                if ($tmpPath === false) {
                    fclose($stream);
                    throw new RuntimeException(
                        "Unable to create a secure local temp file for image resizing (disk: {$disk})."
                    );
                }

                $tmpHandle = fopen($tmpPath, 'wb');

                if ($tmpHandle === false) {
                    fclose($stream);
                    @unlink($tmpPath);
                    throw new RuntimeException(
                        "Unable to write remote image contents to a local temp file (disk: {$disk})."
                    );
                }

                try {
                    $bytesCopied = stream_copy_to_stream($stream, $tmpHandle);
                } finally {
                    fclose($tmpHandle);
                    fclose($stream);
                }

                if ($bytesCopied === false || $bytesCopied === 0) {
                    @unlink($tmpPath);

                    throw new RuntimeException(
                        "Failed to copy remote image contents to a local temp file for resizing (disk: {$disk})."
                    );
                }

                return [$tmpPath, true];
            }
        }

        $sourceType = is_object($this->formFile) ? get_class($this->formFile) : gettype($this->formFile);

        throw new RuntimeException(
            'Unable to resolve a local image source path for resizing '
            . "(disk: " . ($this->getDisk() ?? 'none') . ", source type: {$sourceType})."
        );
    }

    protected function processUploadMultiple(array $files): array
    {
        $fileNames = [];
        foreach ($files as $file) {
            $fileNames[] = $this->processUploadSingle($file);
        }

        return $fileNames;
    }

    private function putFileFromPath(string|false $path, string $targetFileName): void
    {
        if ($path === false) {
            throw new RuntimeException('Unable to determine the source file path.');
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open the source file.');
        }

        try {
            Storage::disk($this->getDisk())->put(
                $targetFileName,
                $stream,
                ['visibility' => $this->getVisibility()]
            );
        } finally {
            fclose($stream);
        }
    }
}
