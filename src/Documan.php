<?php

declare(strict_types=1);

namespace Tekkenking\Documan;


use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * version 0.4.1
 *
 * //How to upload
 * This return array [
 *  'base_name' => 'mainFileName'
 *  'variations'  =>  [
 *      'original' => 'original_mainFileName'
 *      ]
 *  ]
 *
 * @required
 *  - You must set disk either passing it directly to te documan(diskName) or through setDisk(diskName) method, which is a key under disks array key from filesystem config file
 *
 * @optional
 *  - setSizes(array $sizes): You can add sizes to the default sizes
 *
 * @default size methods
 * ->small()
 * ->thumbnail()
 * ->medium()
 * ->custom(280, 400) // require 2 params(width, height)
 *
 * @handles the upload
 *  ->upload($request, 'fileName') //$request object from post | 'fileName' the name given at input tag from the html form
 *
documan()
->setDisk('agent_photo')
->setSizes([
'large' =>  ['width' => 1000, 'height' => 1000]
])
->small()
->thumbnail()
->medium()
->custom(280, 400)
->upload($request, 'agent_photo');

//How to display the image
 *  To view the uploaded image is different variants
 * @required
 *  - You must set disk either passing it directly to te documan(diskName) or through setDisk(diskName) method, which is a key under disks array key from filesystem config file
 *  ->show($fileBaseName) // This is the file base name
 *
 * @default sizes
 *  * @default size methods
 * ->small()
 * ->thumbnail()
 * ->medium()
 * ->custom($customSizeName) // optional 1 params('large'), else defaults to 'custom'
 *
documan('agent_photo')
->show($fileBaseName, true) //Show method accepts 2 param [1 requred, 2 optional (To return only file name)]
->medium()

 */

class Documan
{
    use ReadDocuman, WriteDocuman, ImageSizes;

    protected array $allowedFileExtensions = [];

    private ?string $disk = null;

    private array $chosenSizes = [];

    private ?string $showFile = null;

    private bool $onlyFileName = false;

    private array $arrFilesToShow = [];

    private ?string $remoteHost = null;

    private bool $returnResultWithLinks = false;

    private bool $returnResultWithPaths = false;

    private string $linkPath = '';

    private string $localPath = '';

    public string $filename = '';

    private array $config = [];


    /**
     * @param string $disk
     */
    public function __construct(string $disk= '')
    {
        $this->setupConfig($disk);
    }

    /**
     * @param string $disk
     * @return void
     */
    private function setupConfig(string $disk): void
    {
        $this->config = config('documan');

        if($this->config) {
            $this->allowedFileExtensions = $this->config['allowedFileExtensions'];
            $this->defaultSizes = $this->config['defaultImageSizes'];

            //return upload with links
            $this->returnWithLinks($this->config['returnUploadWith']['links']);

            //return upload with paths
            $this->returnWithPaths($this->config['returnUploadWith']['paths']);

            //Set default disk from config
            $this->setDisk($this->config['disk'] ?? $disk);

            //Are there default sizes to be uploaded at all time
            if(!empty($this->config['uploadDefaulImageSizes'])) {
                foreach($this->config['uploadDefaulImageSizes'] as $sizeKey){
                    $this->chosenSizes[$sizeKey] = $this->defaultSizes[$sizeKey];
                }
            }

        }

    }



    /**
     * Delete a file and all its size variants from the configured disk.
     *
     * Behaviour is controlled by config('documan.delete.mode'):
     *   'hard' (default) — files are permanently removed.
     *   'soft'           — files are moved to the trash folder (config: delete.trash_folder)
     *                      on the same disk, allowing recovery before a hard purge.
     *
     * Backward compatibility: both the legacy prefixed original (`original_abc.jpg`)
     * and the current un-prefixed original (`abc.jpg`) are handled automatically.
     *
     * @param string|array $baseName The base_name returned by upload()
     * @return bool
     */
    public function delete(string|array $baseName): bool
    {
        $this->isDiskSet();
        $disk = Storage::disk($this->getDisk());
        $baseNames = is_array($baseName) ? $baseName : [$baseName];

        $mode        = $this->config['delete']['mode'] ?? 'hard';
        $trashFolder = trim($this->config['delete']['trash_folder'] ?? 'trash', '/');

        foreach ($baseNames as $name) {
            // Candidates:
            //   $name            — current: base_name IS the original (no prefix)
            //   'original_'.$name — legacy: original stored with prefix
            //   '{size}_'.$name  — all resized variants
            $candidates = [$name, 'original_' . $name];
            foreach (array_keys($this->defaultSizes) as $size) {
                $candidates[] = $size . '_' . $name;
            }

            foreach ($candidates as $candidate) {
                if (!$disk->exists($candidate)) {
                    continue;
                }

                if ($mode === 'soft') {
                    $disk->move($candidate, $trashFolder . '/' . $candidate);
                } else {
                    $disk->delete($candidate);
                }
            }
        }

        return true;
    }

    public function addExtension(array $extns): Documan
    {
        $this->allowedFileExtensions = array_merge($this->allowedFileExtensions, ['others' => $extns]);
        return $this;
    }

    /**
     * @param $size
     * @return mixed|string|null
     */
    public function __get($size): mixed
    {
        if($size === 'plain' || $size === 'ordinary' || Str::startsWith($this->showFile, 'http')) {
            return $this->showFile;
        }

        if(!isset($this->defaultSizes[$size])) {
            return '';
        }

        return $this->getDocBySize($size, [])->first();
    }

    /**
     * @param $method
     * @param $args
     * @return Documan
     */
    private function getDocBySize($method, $args): Documan
    {
        if($this->showFile) {
            $method = (isset($args[0])) ? $args[0] : $method;
            return $this->buildShow($method, $this->showFile, $this->onlyFileName);
        }

        return $this->buildShow($method, $args[0], isset($args[1]));
    }


    /**
     * @param null $disk
     * @param null $root_uri
     * @return $this
     */
    public function remoteDisk($disk = null, $root_uri = null): Documan
    {
        $root = $root_uri ?? $this->config['remote']['host_url'];

        if(!$root) {
            throw new DocumanException('Remote host url is required in config file of documan');
        }

        if($disk) {
            $diskAsSegment = $disk;
        } elseif($this->config['remote']['disk']) {
            $diskAsSegment = $this->config['remote']['disk'];
        }else {
            $diskAsSegment = $this->getDisk();
        }

        if($diskAsSegment) {
            $diskAsSegment = '/'.$diskAsSegment;
        }

        $this->remoteHost = $root.$diskAsSegment;
        return $this;
    }


    /**
     * @param string|null $disk
     * @return Documan
     */
    public function setDisk(?string $disk = null): Documan
    {
        if($disk) {
            $this->disk = $disk;
        }

        return $this;
    }

    /**
     * @return string
     */
    public function getDisk(): ?string
    {
        return $this->disk;
    }

    /**
     * @return void
     */
    private function isDiskSet(): void
    {
        if(!$this->getDisk()) {
            throw new DocumanException('Please set a filesystem disk (setDisk(DiskName) method to upload image');
        }
    }

    private function getFileSystemDisk($disk)
    {
        return config('filesystems.disks.'.$disk);
    }


    /**
     * @param $onlyFileName
     * @param $fileSystemDisk
     * @param $fileName
     * @return void
     */
    private function _arrayFileNames($onlyFileName, $fileSystemDisk, $fileName): void
    {
        if($onlyFileName) {
            $this->arrFilesToShow[] = $fileName;
        } else {
            $this->arrFilesToShow[] = $fileSystemDisk['url'].'/'.$fileName;
        }
    }


    /**
     * Resolve the local path of the original file on the source disk.
     *
     * New uploads store the original as the plain base_name (e.g. abc123.jpg).
     * Legacy uploads used an `original_` prefix (e.g. original_abc123.jpg).
     * This method tries the unprefixed path first, then falls back to the
     * legacy prefix so that existing files can still be moved.
     *
     * @param $fileName
     * @param $sourcePath
     * @return string
     */
    private function buildFileToBeMoved($fileName, $sourcePath): string
    {
        $newPath    = $sourcePath . '/' . $fileName;
        $legacyPath = $sourcePath . '/original_' . $fileName;

        if (file_exists($newPath)) {
            return $newPath;
        }

        return $legacyPath;
    }

    /**
     * @param $file
     * @return void
     */
    private function checkMovingFileIfExist($file): void
    {
        if(!file_exists($file)) {
            throw new DocumanException('MOVE: '.$file.' does not exist');
        }
    }

    /**
     * @return void
     */
    protected function prepareStoragePath(): void
    {
        //$path = config('filesystems.disks.'.$this->getDisk().'.root');
        $path = $this->getFileSystemDisk($this->getDisk())['root'];

        if(!File::isDirectory($path)){
            File::makeDirectory($path, 0777, true, true);
        }

    }

    /**
     * @return $this
     */
    public function returnWithLinks(bool $bool = true): Documan
    {
        $this->returnResultWithLinks = $bool;
        return $this;
    }

    public function returnWithPaths(bool $bool = true): Documan
    {
        $this->returnResultWithPaths = $bool;
        return $this;
    }

    /**
     * @param array $responseArr
     * @return DocumanCollections
     */
    public function returnAsCollection($responseArr, $hasCollection = false): DocumanCollections|DocumanSingle
    {

        if($hasCollection) {
            return new DocumanCollections($responseArr);
        } else {
            return new DocumanSingle($responseArr);
        }
    }

}
