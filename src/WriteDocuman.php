<?php

namespace Tekkenking\Documan;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait WriteDocuman
{
    public mixed $formFile = null;

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
    public function upload(Request $request, string $inputName): DocumanCollections|array|bool
    {
        $request1 = $request;
        $inputName1 = $inputName;

        if ($request1->hasFile($inputName1)) {
            $file = $request1->file($inputName1);

            $externalUploadResponse = $this->useExternalUploader($file);
            if ($externalUploadResponse) {
                return $externalUploadResponse;
            }

            return $this->upload_without_request($file);
        } else {
            return false;
        }
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
        // $sourcePath = config('filesystems.disks.'.$source_disk.'.root');
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

        $extension = strtolower($file->getClientOriginalExtension());

        $check = false;
        $extnGroup = '';
        foreach ($this->allowedFileExtensions as $grp => $extn) {
            $check = in_array($extension, $extn);
            if ($check) {
                $extnGroup = $grp;
                break;
            }
        }

        if (! $check) {
            throw new DocumanException("File extension '{$extension}' is not allowed.");
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

        foreach ($this->chosenSizes as $key => $size) {
            $this->filename = $key.'_'.$fileName.'.'.$extension;

            if ($key === 'original') {
                Storage::disk($this->getDisk())
                    ->put($this->filename, file_get_contents($this->formFile));
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
                    ? $this->linkPath.'/'.$this->filename
                    : null;
            }

            if ($this->returnResultWithPaths) {
                $fileNameInSizes['paths'][$key] = ($this->localPath)
                    ? $this->localPath.'/'.$this->filename
                    : null;
            }
        }

        return $fileNameInSizes;
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

