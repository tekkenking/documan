<?php

namespace Tekkenking\Documan;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

trait WriteDocuman
{
    public mixed $formFile = null;

    /**
     * @return void
     * @deprecated Original copy is now always stored; this method does nothing
     *             and will be removed in a future release.
     */
    private function checkToKeepOriginalSize()
    {
        // Original storage is now mandatory. This method is intentionally a no-op
        // and exists only to avoid fatal errors if called from overriding code.
    }

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
        $sourcePath = $this->getFileSystemDisk($source_disk)['root'];

        if (! is_array($fileName)) {
            $name = $this->buildFileToBeMoved($fileName, $sourcePath);
            $this->checkMovingFileIfExist($name);
        } else {
            $name = [];
            foreach ($fileName as $file) {
                $nx = $this->buildFileToBeMoved($file, $sourcePath);
                $this->checkMovingFileIfExist($nx);
                $name[] = $nx;
            }
        }

        return $this->processUpload($name);
    }

    protected function processUpload($file): array
    {
        $this->isDiskSet();

        // Original is now mandatory — always prepend it to whichever sizes the
        // caller selected. Using array union preserves an explicit 'original'
        // entry the caller may have added while guaranteeing it always exists.
        $this->chosenSizes = ['original' => ['width' => 999999, 'height' => 999999]] + $this->chosenSizes;

        if (is_array($file)) {
            return $this->processUploadMultiple($file);
        }

        return $this->processUploadSingle($file);
    }

    protected function processUploadSingle($file): array
    {
        // Validate against actual MIME type (not client-supplied extension)
        $mimeType = $file->getMimeType();
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

        Storage::disk($this->getDisk())
            ->put($this->filename, file_get_contents($this->formFile));

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

        $localSourcePath = $this->resolveLocalImageSourcePath();

        // Always persist the original immediately (idempotent).
        Storage::disk($this->getDisk())->put($baseFileName, fopen($localSourcePath, 'rb'));

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

        return $fileNameInSizes;
    }

    private function resolveLocalImageSourcePath(): string
    {
        if ($this->formFile instanceof UploadedFile) {
            $path = $this->formFile->getRealPath();

            if ($path !== false && $path !== '') {
                return $path;
            }
        }

        if (is_string($this->formFile) && is_file($this->formFile)) {
            return $this->formFile;
        }

        throw new RuntimeException('Unable to resolve a local image source path for resizing.');
    }

    protected function processUploadMultiple(array $files): array
    {
        $fileNames = [];
        foreach ($files as $file) {
            $fileNames[] = $this->processUploadSingle($file);
        }

        return $fileNames;
    }
}

