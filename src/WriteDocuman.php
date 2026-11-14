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

    /**
     * Every file this upload call has put on the disk so far, so a failure
     * part-way through can take them back off.
     *
     * @var array<int, string>
     */
    private array $writtenFiles = [];

    public function plain($value): static
    {
        $this->showFile = $value;

        return $this;
    }

    /**
     * @return array
     */
    public function upload(Request $request, string $inputName): array|DocumanCollections|DocumanSingle
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

    public function upload_without_request($file): array|DocumanCollections|DocumanSingle
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

        $files = [];
        $totalBytesCopied = 0;

        try {
            foreach ($names as $name) {
                if (!is_string($name)) {
                    throw new DocumanException('File names must be strings.');
                }
                $this->assertSafeStorageFileName($name);

                [$files[], $bytesCopied] = $this->copyFromSourceDisk($source_disk, $name, $totalBytesCopied);
                $totalBytesCopied += $bytesCopied;
            }

            return $this->processUpload(is_array($fileName) ? $files : ($files[0] ?? null));
        } finally {
            foreach ($files as $file) {
                @unlink($file->getPathname());
            }
        }
    }

    /**
     * Bring one file from another disk to a local temporary file, stopping
     * as soon as it passes the size limits.
     *
     * @return array{0: UploadedFile, 1: int} [file, bytes copied]
     */
    private function copyFromSourceDisk(string $sourceDisk, string $name, int $bytesSoFar): array
    {
        $stream = $this->openSourceStream($sourceDisk, $name);
        $maxUploadSize = (int) ($this->config['maxUploadSizeBytes'] ?? 20971520);
        $maxTotalSize = (int) ($this->config['maxTotalUploadSizeBytes'] ?? 41943040);

        //One byte past the limit is enough to know the file is too big,
        //without copying the rest of it.
        $limits = array_filter([
            $maxUploadSize > 0 ? $maxUploadSize : null,
            $maxTotalSize > 0 ? max(0, $maxTotalSize - $bytesSoFar) : null,
        ], fn ($limit) => $limit !== null);
        $copyLength = $limits === [] ? null : min($limits) + 1;

        [$temporaryPath, $bytesCopied] = $this->copyStreamToTempFile($stream, 'documan_move_', $copyLength);

        try {
            if ($bytesCopied === 0) {
                throw new DocumanException("Unable to copy '{$name}' from the source disk.");
            }
            if ($maxUploadSize > 0 && $bytesCopied > $maxUploadSize) {
                throw new DocumanException('The file exceeds the configured maximum upload size.');
            }
            if ($maxTotalSize > 0 && $bytesCopied > $maxTotalSize - $bytesSoFar) {
                throw new DocumanException('The upload exceeds the configured maximum total size.');
            }
        } catch (\Throwable $e) {
            @unlink($temporaryPath);

            throw $e;
        }

        $mimeType = mime_content_type($temporaryPath) ?: 'application/octet-stream';

        return [new UploadedFile($temporaryPath, $name, $mimeType, UPLOAD_ERR_OK, true), $bytesCopied];
    }

    /**
     * Open a stored file for reading, also under the 'original_' prefix
     * that older versions stored originals with.
     *
     * @return resource
     */
    private function openSourceStream(string $sourceDisk, string $name)
    {
        foreach ([$name, 'original_'.$name] as $candidate) {
            try {
                $stream = Storage::disk($sourceDisk)->readStream($candidate);
            } catch (\Throwable) {
                $stream = null;
            }

            if (is_resource($stream)) {
                return $stream;
            }
        }

        throw new DocumanException("Unable to read '{$name}' from the source disk.");
    }

    /**
     * Copy a stream into a new local temporary file and close it.
     *
     * @param resource $stream
     * @return array{0: string, 1: int} [path, bytes copied]
     */
    private function copyStreamToTempFile($stream, string $prefix, ?int $maxBytes = null): array
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $handle = $path === false ? false : fopen($path, 'wb');

        if ($path === false || $handle === false) {
            fclose($stream);
            if ($path !== false) {
                @unlink($path);
            }

            throw new RuntimeException('Unable to create a local temporary file.');
        }

        try {
            $bytesCopied = stream_copy_to_stream($stream, $handle, $maxBytes);
        } finally {
            fclose($handle);
            fclose($stream);
        }

        if ($bytesCopied === false) {
            @unlink($path);

            throw new RuntimeException('Unable to copy the file to a local temporary file.');
        }

        return [$path, $bytesCopied];
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
        $this->assertVariantLimit(is_array($file) ? $file : [$file]);
        foreach ($this->chosenSizes as $sizeName => $size) {
            if ($sizeName !== 'original') {
                $this->chosenSizes[$sizeName] = $this->validateSizeDefinition((string) $sizeName, $size);
            }
        }

        $this->writtenFiles = [];

        try {
            return is_array($file)
                ? $this->processUploadMultiple($file)
                : $this->processUploadSingle($file);
        } catch (\Throwable $e) {
            //Nothing refers to these files once the call has failed, so
            //leaving them would fill the disk with orphans.
            $this->removeWrittenFiles();

            throw $e;
        }
    }

    /**
     * Every image in the call produces one file per chosen size.
     */
    private function assertVariantLimit(array $files): void
    {
        $maxVariants = (int) ($this->config['maxVariantsPerUpload'] ?? 20);
        $imageCount = 0;

        foreach ($files as $candidate) {
            $mimeType = $candidate instanceof UploadedFile ? $candidate->getMimeType() : null;
            if (is_string($mimeType) && documan_mime_group($mimeType) === 'image') {
                $imageCount++;
            }
        }

        if ($maxVariants > 0 && count($this->chosenSizes) * $imageCount > $maxVariants) {
            throw new DocumanException('The upload requests more image variants than allowed.');
        }
    }

    /**
     * Take back everything a failed upload call stored.
     */
    private function removeWrittenFiles(): void
    {
        foreach ($this->writtenFiles as $name) {
            try {
                Storage::disk($this->getDisk())->delete($name);
            } catch (\Throwable $e) {
                logger()->warning('Documan: could not remove a file from a failed upload: '.$name, ['exception' => $e]);
            }
        }

        $this->writtenFiles = [];
    }

    /**
     * A variant and, when WebP copies are on, its .webp twin.
     */
    private function rememberVariant(string $fileName): void
    {
        $this->writtenFiles[] = $fileName;

        if ($this->config['outputWebp'] ?? false) {
            $this->writtenFiles[] = preg_replace('/\.\w+$/', '.webp', $fileName) ?? $fileName;
        }
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

        [$extnGroup, $extension] = $this->resolveTypeAndExtension($file);

        $fileName = Str::random();
        $this->prepareStoragePath();
        $this->filename = $fileName.'.'.$extension;
        $this->setResultRoots();

        $this->formFile = $file;
        if ($extnGroup === 'image') {
            return $this->_processImage($extnGroup, $fileName, $extension);
        }

        return $this->_processOtherDocs($extnGroup);
    }

    /**
     * Decide what the file is from its contents, and the extension to
     * store it under.
     *
     * @return array{0: string, 1: string} [group, extension]
     */
    private function resolveTypeAndExtension(UploadedFile $file): array
    {
        $mimeType = $file->getMimeType();
        if (!is_string($mimeType) || $mimeType === '') {
            throw new DocumanException('Unable to determine the uploaded file type.');
        }

        $clientExtension = strtolower($file->getClientOriginalExtension());
        $group = documan_mime_group($mimeType);

        if ($group && array_key_exists($group, $this->allowedFileExtensions)) {
            //The extension follows the contents, never the name the client
            //sent: a PNG uploaded as photo.jpg is stored as .png.
            $candidates = documan_extensions_for_mime($mimeType);
            if (in_array($clientExtension, $candidates, true)) {
                array_unshift($candidates, $clientExtension);
            }

            foreach ($candidates as $candidate) {
                if (in_array($candidate, $this->allowedFileExtensions[$group], true)) {
                    return [$group, $candidate];
                }
            }
        }

        //Types added with addExtension() are not ones this package can
        //recognise by content, so they are matched on the file's extension.
        $others = $this->allowedFileExtensions['others'] ?? [];
        if (in_array($clientExtension, $others, true)) {
            $this->assertNotExecutableExtension($clientExtension);

            return ['others', $clientExtension];
        }

        throw new DocumanException("File type '{$mimeType}' is not allowed.");
    }

    /**
     * Extensions a web server may run are refused even when added by hand.
     */
    private function assertNotExecutableExtension(string $extension): void
    {
        $executable = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht', 'cgi', 'pl', 'sh', 'htaccess'];

        if (in_array($extension, $executable, true)) {
            throw new DocumanException("Files with the '{$extension}' extension cannot be uploaded.");
        }
    }

    /**
     * Where the links and paths in the upload result are rooted.
     */
    private function setResultRoots(): void
    {
        $this->linkPath = '';
        $this->localPath = '';
        $fileSysDisk = $this->getFileSystemDisk($this->getDisk());

        if ($this->returnResultWithLinks) {
            $this->linkPath = isset($fileSysDisk['url']) ? rtrim((string) $fileSysDisk['url'], '/') : '';
        }

        if ($this->returnResultWithPaths) {
            $this->localPath = (string) ($fileSysDisk['root'] ?? '');
        }
    }

    /**
     * The link reported for a stored file: the disk's configured url when
     * it has one, otherwise whatever its driver gives. A private file on
     * a remote disk gets the signed URL show() would give, since its
     * plain one is refused.
     */
    private function linkFor(string $fileName): ?string
    {
        $disk = (string) $this->getDisk();

        if ($this->getVisibility() === 'private' && !$this->isLocalDisk($disk)) {
            try {
                return $this->remoteUrl($disk, $fileName);
            } catch (\Throwable) {
                return null;
            }
        }

        if ($this->linkPath !== '') {
            return $this->linkPath.'/'.$fileName;
        }

        try {
            return $this->urlDisk($this->getDisk())->url($fileName);
        } catch (\Throwable) {
            return null;
        }
    }

    private function _processOtherDocs($extnGroup): array
    {
        $fileNameInSizes['fileType'] = $extnGroup;
        $fileNameInSizes['base_name'] = $this->filename;

        $this->putFileFromPath($this->formFile->getRealPath(), $this->filename);

        if ($this->returnResultWithLinks) {
            $fileNameInSizes['link'] = $this->linkFor($this->filename);
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

        // The original is the base_name itself — no prefix.
        // It is always stored synchronously so queue jobs have a source to read from.
        $baseFileName = $fileName . '.' . $extension;   // == $this->filename at this point

        [$localSourcePath, $isTempSourcePath] = $this->resolveLocalImageSourcePath();

        try {
            $this->assertImageWithinLimits($localSourcePath);
            $this->putFileFromPath($localSourcePath, $baseFileName);

            foreach ($this->chosenSizes as $key => $size) {
                $this->filename = $key === 'original'
                    ? $baseFileName
                    : $key . '_' . $fileName . '.' . $extension;

                if ($key !== 'original') {
                    $this->createVariant($localSourcePath, $baseFileName, $this->filename, $size);
                }

                $fileNameInSizes = $this->addToResult($fileNameInSizes, (string) $key, $this->filename);
            }
        } finally {
            // Only clean up temp files that we materialized from a remote
            // disk. Locally-uploaded files are left untouched — their
            // lifecycle is not owned by us.
            if ($isTempSourcePath) {
                @unlink($localSourcePath);
            }
        }

        return $fileNameInSizes;
    }

    /**
     * Record one stored size in the upload result.
     */
    private function addToResult(array $result, string $size, string $fileName): array
    {
        $result['variations'][$size] = $fileName;

        if ($this->returnResultWithLinks) {
            $result['links'][$size] = $this->linkFor($fileName);
        }

        if ($this->returnResultWithPaths) {
            $result['paths'][$size] = $this->localPath ? $this->localPath . '/' . $fileName : null;
        }

        return $result;
    }

    /**
     * Refuse a file that is not an image, or one too large to decode safely.
     */
    private function assertImageWithinLimits(string $path): void
    {
        $dimensions = @getimagesize($path);
        if ($dimensions === false || $dimensions[0] <= 0 || $dimensions[1] <= 0) {
            throw new DocumanException('Invalid image file or dimensions.');
        }

        $maxPixels = (int) ($this->config['maxImagePixels'] ?? 40000000);
        if ($maxPixels > 0 && $dimensions[0] > intdiv($maxPixels, $dimensions[1])) {
            throw new DocumanException('Source image exceeds the configured maximum pixel count.');
        }
    }

    /**
     * Make one size now, or hand it to the queue when that is switched on.
     *
     * @param array{width: int, height: int} $size
     */
    private function createVariant(string $localSourcePath, string $baseFileName, string $target, array $size): void
    {
        if (!($this->config['queue']['enabled'] ?? false)) {
            $this->rememberVariant($target);

            (new ImageResizer($this->getDisk()))
                ->setVisibility($this->getVisibility())
                ->resizeAndPreserveExif($localSourcePath, $target, $size['width'], $size['height']);

            return;
        }

        $job = new \Tekkenking\Documan\Jobs\ProcessDocumanImage(
            disk: $this->getDisk(),
            sourceFileName: $baseFileName,   // plain base_name, no prefix
            targetFileName: $target,
            width: $size['width'],
            height: $size['height'],
            visibility: $this->getVisibility(),
        );

        if ($this->config['queue']['connection'] ?? null) {
            $job->onConnection($this->config['queue']['connection']);
        }

        if ($this->config['queue']['name'] ?? null) {
            $job->onQueue($this->config['queue']['name']);
        }

        dispatch($job);
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

        $fetched = is_string($this->formFile) ? $this->fetchStoredImage($this->formFile) : null;
        if ($fetched !== null) {
            return [$fetched, true];
        }

        $sourceType = is_object($this->formFile) ? get_class($this->formFile) : gettype($this->formFile);

        throw new RuntimeException(
            'Unable to resolve a local image source path for resizing '
            . "(disk: " . ($this->getDisk() ?? 'none') . ", source type: {$sourceType})."
        );
    }

    /**
     * Copy an image already on the configured (possibly remote) disk to a
     * local temporary file, or null when there is no such file.
     */
    private function fetchStoredImage(string $name): ?string
    {
        if ($name === '' || !$this->getDisk()) {
            return null;
        }

        try {
            $stream = Storage::disk($this->getDisk())->readStream($name);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_resource($stream)) {
            return null;
        }

        [$tmpPath, $bytesCopied] = $this->copyStreamToTempFile($stream, 'documan_');

        if ($bytesCopied === 0) {
            @unlink($tmpPath);

            throw new RuntimeException('The stored image is empty (disk: '.$this->getDisk().').');
        }

        return $tmpPath;
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

        $this->writtenFiles[] = $targetFileName;

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
