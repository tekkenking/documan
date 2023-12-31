<?php

declare(strict_types=1);

namespace Tekkenking\Documan;


use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

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

    /**
     * @var array|string[]
     */
    protected array $allowedFileExtensions = [];

    /**
     * @var null
     */
    private mixed $disk = null;

    /**
     * @var array
     */
    private array $chosenSizes = [];

    /**
     * @var mixed|null
     */
    private mixed $showFile = null;

    /**
     * @var bool
     */
    private bool $onlyFileName = false;

    /**
     * @var array
     */
    private array $arrFilesToShow = [];

    /**
     * @var null
     */
    private mixed $remoteHost = null;

    /**
     * @var bool
     */
    private bool $returnResultWithLinks = false;

    /**
     * @var bool
     */
    private bool $returnResultWithPaths = false;

    /**
     * @var string
     */
    private string $linkPath = '';

    /**
     * @var string
     */
    private string $localPath = '';

    /**
     * @var string
     */
    public string $filename = '';

    /**
     * @var array
     */
    private array $config= [];


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
     * @return Documan
     */
    public function forceExcludeOriginalCopy(): Documan
    {
        unset($this->chosenSizes['original']);
        return $this;
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
            //dd('Unknown file size '. $size);
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
            dd('Remote host url is required in config file of documan');
        }

        if($disk) {
            $diskAsSegment = $this->config['remote']['disk'];
        } elseif($this->config['remote']['disk']) {
            $diskAsSegment = $disk;
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
     * example structure = ['small'     =>  ['width' => 120, 'height' => 120]],
     * @param array $sizes
     * @return static
     */
    /*public function addSize(array $sizes): static
    {
        $this->defaultSizes = array_merge($this->defaultSizes, $sizes);

        //We default add the custom added sizes on the fly
        foreach ($sizes as $key => $size) {
            $this->chosenSizes[$key] = $size;
        }

        return $this;
    }*/


    /**
     * @param string|null $disk
     * @return Documan
     */
    public function setDisk(string $disk = null): Documan
    {
        if($disk) {
            $this->disk = $disk;
        }

        return $this;
    }

    /**
     * @return string
     */
    public function getDisk(): mixed
    {
        return $this->disk;
    }

    /**
     * @return void
     */
    private function isDiskSet(): void
    {
        if(!$this->getDisk()) {
            dd('Please set a filesystem disk (setDisk(DiskName) method to upload image');
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


    /*private function setChosenSize($size, $param): static
    {
        //Let check if reserve size is called
        if(in_array($size, $this->reservedSizes)) {
            dd("The size ".$size." is reserved.");
        }

        if($size === 'custom') {
            $this->chosenSizes[$size] = ['width' => $param[0], 'height' => $param[1]];
        } else {
            $this->chosenSizes[$size] = $this->defaultSizes[$size];
        }
        return $this;
    }*/

    /**
     * @param $fileName
     * @param $sourcePath
     * @return string
     */
    private function buildFileToBeMoved($fileName, $sourcePath): string
    {
        return $sourcePath.'/original_'.$fileName;
    }

    /**
     * @param $file
     * @return void
     */
    private function checkMovingFileIfExist($file): void
    {
        if(!file_exists($file)) {
            dd('MOVE: '.$file.' does not exist');
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
