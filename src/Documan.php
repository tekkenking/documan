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

    /**
     * @var array
     */
    private array $defaultSizes = [];

    /**
     * @var array|string[]
     */
    private array $reservedSizes = [
        'original'
    ];

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
     * @var string
     */
    private string $linkPath = '';

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
            $this->defaultSizes['original'] = ['width' => '', 'height' => ''];

            //Set default disk from config
            $this->setDisk($this->config['disk'] ?? $disk);

            //This would add or remove original size.
            if($this->config['addOriginalSize']) {
                $this->chosenSizes['original'] = $this->defaultSizes['original'];
            }elseif(isset($this->chosenSizes['original'])) {
                unset($this->chosenSizes['original']);
            }

            //Are there default sizes to be uploaded at all time
            if(!empty($this->config['uploadDefaulImageSizes'])) {

                foreach($this->config['uploadDefaulImageSizes'] as $sizeKey){
                    $this->chosenSizes[$sizeKey] = $this->defaultSizes[$sizeKey];
                }
            }

        }

    }

    /**
     * @param array $newSize [optional] $newSize
     * @return Documan
     */
    public function medium(array $newSize = []): Documan
    {
        $this->sizeSetter('medium', $newSize);
        return $this;
    }

    /**
     * @param array $newSize [optional] $newSize
     * @return Documan
     */
    public function thumbnail(array $newSize = []): Documan
    {
        $this->sizeSetter('thumbnail', $newSize);
        return $this;
    }

    /**
     * @param array $newSize [optional] $newSize
     * @return Documan
     */
    public function small(array $newSize = []): Documan
    {
        $this->sizeSetter('small', $newSize);
        return $this;
    }

    /**
     * @return Documan
     */
    public function forceExcludeOriginalCopy(): Documan
    {
        unset($this->chosenSizes['original']);
        return $this;
    }

    /**
     * @param string $key
     * @param array $newSize
     * @return void
     */
    private function sizeSetter(string $key, array $newSize): void
    {

        if(!empty($newSize)) {
            $this->defaultSizes[$key]['width'] = (isset($newSize['w']))
                ? $newSize['w']
                : $newSize['width'];

            $this->defaultSizes[$key]['height'] = (isset($newSize['h']))
                ? $newSize['h']
                : $newSize['height'];
        }

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
    public function __get($size)
    {
        if($size === 'plain' || $size === 'ordinary' || Str::startsWith($this->showFile, 'http')) {
            return $this->showFile;
        }

        if(!isset($this->defaultSizes[$size])) {
            dd('Unknown file size '. $size);
        }

        //dd($size);

        return $this->getDocBySize($size, [])->first();
    }

    /**
     * @param $value
     * @return static
     */
    public function plain($value): static
    {
        $this->showFile = $value;
        return $this;
    }

    /**
     * @return ?string
     */
    public function showFileName(): ?string
    {
        return $this->showFile;
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
    public function addSize(array $sizes): static
    {
        $this->defaultSizes = array_merge($this->defaultSizes, $sizes);

        //We default add the custom added sizes on the fly
        foreach ($sizes as $key => $size) {
            $this->chosenSizes[$key] = $size;
        }

        return $this;
    }

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

    /**
     * @return mixed|string
     */
    public function first(): mixed
    {
        if(count($this->arrFilesToShow) > 0) {
            return $this->arrFilesToShow[0];
        }

        return '';
    }

    /**
     * @return Collection
     */
    public function get(): Collection
    {
        return collect($this->arrFilesToShow);
    }

    private function getFileSystemDisk($disk)
    {
        return config('filesystems.disks.'.$disk);
    }

    /**
     * @param $size
     * @return string
     */
    public function localPath($size): string
    {
        $fileName = $size.'_'.$this->showFile;
        $fileSystemDisk = $this->getFileSystemDisk($this->getDisk());
        $localFile = $fileSystemDisk['root'].'/'.$fileName;

        if(!file_exists($localFile)) {
            return $fileSystemDisk['root'].'/'.$this->showFile;
        }

        return $localFile;
    }

    /**
     * @param $size
     * @param $fileName
     * @param $onlyFileName
     * @return Documan
     */
    private function buildShow($size, $fileName, $onlyFileName): Documan
    {
        $fileNameBySize = $size.'_'.$fileName;

        if($this->remoteHost) {
            $this->arrFilesToShow[] = $this->remoteHost.'/'.$fileNameBySize;
            return $this;
        }

        $this->isDiskSet();
        $fileSystemDisk = $this->getFileSystemDisk($this->getDisk());


        if(!file_exists($fileSystemDisk['root'].'/'.$fileNameBySize)) {
            //is show in strict mode
            /*if($this->showMode) {
                dump($this->arrFilesToShow);
                dd('Strict MODE:: The size '.$size.' does not exist');
            }*/
            //Supporting those files without the size prefix in there naming
            $fileNameBySize = $fileName;
        }

        $this->_arrayFileNames($onlyFileName, $fileSystemDisk, $fileNameBySize);
        return $this;
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
     * @param string $showFile
     * @param bool $onlyFileName
     * @return static
     */
    public function show(string $showFile, bool $onlyFileName = false): static
    {
        $this->showFile = $showFile;
        $this->onlyFileName = $onlyFileName;
        //$this->showMode = $strict;
        return $this;
    }

    /**
     * @param $method
     * @param $args
     * @return $this|Documan|mixed|string|void
     */
    public function __call($method, $args)
    {
        $show = false;
        if(Str::startsWith($method, 'show')) {
            //Let's check if it's an allowed method call
            $show  = true;
            $method = strtolower(str_replace('show', '', $method));
        }

        if(isset($this->defaultSizes[$method])) {
            if(!$show && !$this->showFile) {
                return $this->setChosenSize($method, $args);
            } else {

                if($show) {
                    return $this->getDocBySize($method, $args)->first();
                }

                return $this->getDocBySize($method, $args);
            }

        } else {
            dd($method.' method call is not allowed in documan');
        }

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

    private function setChosenSize($size, $param): static
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
    }

    /**
     * @param Request $request
     * @param string $inputName
     * @return array
     */
    public function upload(Request $request, string $inputName): array
    {
        $request1 = $request;
        $inputName1 = $inputName;

        if($request1->hasFile($inputName1)) {
            $file = $request1->file($inputName1);
            return $this->processUpload($file);
        }

        return [];
    }

    /**
     * @param string|array $fileName
     * @param string $source_disk
     * @return array
     */
    public function move(string | array $fileName, string $source_disk): array
    {
        //$sourcePath = config('filesystems.disks.'.$source_disk.'.root');
        $sourcePath = $this->getFileSystemDisk($source_disk)['root'];

        if(!is_array($fileName)) {
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
     * @param $file
     * @return array
     */
    protected function processUpload($file): array
    {
        $this->isDiskSet();

        if(is_array($file)) {
            return $this->processUploadMultiple($file);
        }

        return $this->processUploadSingle($file);
    }

    /**
     * @param $file
     * @return array
     */
    protected function processUploadSingle($file): array
    {

        $extension = strtolower($file->getClientOriginalExtension());

        $check = false;
        $extnGroup = '';
        foreach ($this->allowedFileExtensions as $grp => $extn) {
            $check = in_array($extension, $extn);
            if($check) {
                $extnGroup = $grp;
                break;
            }
        }

        if(!$check) {
            return [];
        }

        $fileName = Str::random();
        $this->prepareStoragePath();
        $this->filename = $fileName.'.'.$extension;


        $this->linkPath = '';
        if($this->returnResultWithLinks) {
            $this->linkPath = $this->getFileSystemDisk($this->getDisk())['url'];
        }

        if($extnGroup === 'image') {
            return $this->_processImage($file, $fileName, $extension);
        }

        return $this->_processOtherDocs($file);

    }

    /**
     * @param $file
     * @return array
     */
    private function _processOtherDocs($file): array
    {
        $fileNameInSizes['base_name'] = $this->filename;

        // Storage::disk($this->disk)->putFileAs(
        //     $this->disk.'/', $file, $this->filename
        // );

        Storage::disk($this->getDisk())->put($this->filename, file_get_contents($file));

        if($this->returnResultWithLinks) {
            $fileNameInSizes['link'] = $this->linkPath.'/'.$this->filename;
        }

        return $fileNameInSizes;
    }

    /**
     * @param $file
     * @param $fileName
     * @param $extension
     * @return array
     */
    private function _processImage($file, $fileName, $extension): array
    {
        $fileNameInSizes['base_name'] = $this->filename;

        $master_image = Image::make($file);

        foreach ($this->chosenSizes as $key => $size) {
            //$img = Image::make($file);
            $img = $master_image;

            $img->resize($size['width'], $size['height'], function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });


            $this->filename = $key.'_'.$fileName.'.'.$extension;

            $finalImg = $img->encode($extension);

            Storage::disk($this->getDisk())->put($this->filename, $finalImg);

            $fileNameInSizes['variations'][$key] = $this->filename;

            if($this->returnResultWithLinks) {
                $fileNameInSizes['links'][$key] = $this->linkPath.'/'. $this->filename;
            }

            $img->destroy();
        }

        return $fileNameInSizes;
    }

    /**
     * @param $files
     * @return array
     */
    protected function processUploadMultiple($files): array
    {
        $fileNames = [];
        foreach ($files as $file) {
            $fileNames[] = $this->processUploadSingle($file);
        }

        return $fileNames;
    }

    /**
     * @return $this
     */
    public function returnWithLinks(): Documan
    {
        $this->returnResultWithLinks = true;
        return $this;
    }

}
