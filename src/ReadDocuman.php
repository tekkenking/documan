<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait ReadDocuman
{
    /**
     * @param $method
     * @param $args
     * @return $this|Documan|mixed|string|void
     */
    public function __call($method, $args)
    {
        $show = false;
        /*if(Str::startsWith($method, 'show')) {
            //Let's check if it's an allowed method call
            $show  = true;
            $method = strtolower(str_replace('show', '', $method));
        }*/

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
     * @return ?string
     */
    public function showFileName(): ?string
    {
        return $this->showFile;
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

    public function getExtension()
    {

    }

    public function getType()
    {
        //return mime_content_type()
    }

    public function mimeType()
    {

    }

    /**
     * @param $size
     * @return string
     */
    public function localPath($size): string
    {
        if(Str::startsWith($this->showFile, 'http')) {
            return $this->showFile;
        }

        $fileName = $size.'_'.$this->showFile;
        $fileSystemDisk = $this->getFileSystemDisk($this->getDisk());
        $localFile = $fileSystemDisk['root'].'/'.$fileName;

        if(!file_exists($localFile)) {
            return $fileSystemDisk['root'].'/'.$this->showFile;
        }

        return $localFile;
    }


    public function doc_collect()
    {
        //return documan_collections();
    }

    public function dc()
    {
        //return $this->doc_collect();
    }
}
