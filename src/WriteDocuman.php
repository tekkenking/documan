<?php

namespace Tekkenking\Documan;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait WriteDocuman
{
    public mixed $formFile = null;

    /**
     * MIME type → extension group map (used instead of client-supplied extension).
     */
    private static array $mimeToGroup = [
        'image/jpeg'          => 'image',
        'image/png'           => 'image',
        'image/gif'           => 'image',
        'image/webp'          => 'image',
        'application/vnd.ms-excel'                                                       => 'excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'              => 'excel',
        'text/csv'                                                                       => 'excel',
        'application/msword'                                                             => 'document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'       => 'document',
        'application/vnd.ms-powerpoint'                                                  => 'powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'     => 'powerpoint',
        'application/pdf'                                                                => 'pdf',
    ];

    /**
     * @return void
     */
    private function checkToKeepOriginalSize()
    {
        // This would add or remove original size.
        if ($this->config['keepOriginalSize']) {
            $this->chosenSizes = ['original' => ['width' => 999999, 'height' => 999999]] + $this->chosenSizes;
        } elseif (isset($this->chosenSizes['original'])) {
            unset($this->chosenSizes['original']);
        }
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

        $this->checkToKeepOriginalSize();

        if (is_array($file)) {
            return $this->processUploadMultiple($file);
        }

        return $this->processUploadSingle($file);
    }

    protected function processUploadSingle($file): array
    {
        // Validate against actual MIME type (not client-supplied extension)
        $mimeType = $file->getMimeType();
        $extnGroup = self::$mimeToGroup[$mimeType] ?? null;

        if (!$extnGroup || !array_key_exists($extnGroup, $this->allowedFileExtensions)) {
            throw new DocumanException("File type '{$mimeType}' is not allowed.");
        }

        // Derive a safe extension from the MIME type rather than trusting the client
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensionsForGroup = $this->allowedFileExtensions[$extnGroup];
        if (!in_array($extension, $allowedExtensionsForGroup, true)) {
            // Fall back to a known-safe extension for this MIME type
            $extension = $this->safeExtensionFromMime($mimeType);
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

        // The original (full-size) copy is always stored synchronously so the
        // queue job can read it as its source.
        $originalFileName = 'original_' . $fileName . '.' . $extension;

        // Read the uploaded file content once to avoid repeated I/O in the loop
        $originalContent = file_get_contents($this->formFile);

        foreach ($this->chosenSizes as $key => $size) {
            $this->filename = $key . '_' . $fileName . '.' . $extension;

            if ($key === 'original') {
                Storage::disk($this->getDisk())
                    ->put($this->filename, $originalContent);
            } elseif ($queueEnabled) {
                // Store the original first (idempotent if already stored)
                if (!Storage::disk($this->getDisk())->exists($originalFileName)) {
                    Storage::disk($this->getDisk())
                        ->put($originalFileName, $originalContent);
                }

                $job = new \Tekkenking\Documan\Jobs\ProcessDocumanImage(
                    disk: $this->getDisk(),
                    sourceFileName: $originalFileName,
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
                $imageProcessor = new ImageResizer($this->getDisk());
                $imageProcessor->resizeAndPreserveExif(
                    $this->formFile,
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

    private function safeExtensionFromMime(string $mimeType): string
    {
        $map = [
            'image/jpeg'          => 'jpg',
            'image/png'           => 'png',
            'image/gif'           => 'gif',
            'image/webp'          => 'webp',
            'application/vnd.ms-excel'                                                       => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'              => 'xlsx',
            'text/csv'                                                                       => 'csv',
            'application/msword'                                                             => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'       => 'docx',
            'application/vnd.ms-powerpoint'                                                  => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'     => 'pptx',
            'application/pdf'                                                                => 'pdf',
        ];

        return $map[$mimeType] ?? 'bin';
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

