<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Str;

class DocumanCast implements CastsAttributes
{
    /**
     * @var string
     */
    public string $disk = '';

    /**
     * @var array
     */
    public array $sizes = [];

    /**
     * @var bool
     */
    private $is_remote = false;

    /**
     * @param $option
     */
    public function __construct(private $option)
    {
        //How many options
        $options = explode(':', $this->option);

        $disk = $options[0];

        //Let's check if the setDisk has remote prefix
        if(str_starts_with($disk, 'remote')) {
            //This means it's a remote disk
            $this->is_remote = true;
            $disk = str_replace('remote_', '', $disk);
        }

        $this->setDisk($disk);


        if(isset($options[1])) {
            $this->setSizes(explode('|', $options[1]));
        }

    }

    /**
     * @param $disk
     * @return void
     */
    private function setDisk($disk)
    {
        $this->disk = $disk;
    }

    /**
     * @param $sizes
     * @return void
     */
    private function setSizes($sizes)
    {
        $this->sizes = $sizes;
    }

    public function get($model, string $key, $value, array $attributes)
    {
        if(!$value){
            return $value;
        }

        $documan = new Documan();
        if(Str::startsWith($value, 'http')) {
            return $documan->plain($value);
        }

        $doc = $this->chooseDisk($documan);

        return $doc->show($value);
    }

    private function chooseDisk($documan)
    {
        if($this->is_remote) {
            $doc = $documan->remoteDisk($this->disk);
        } else {
            $doc = $documan->setDisk($this->disk);
        }

        return $doc;
    }

    public function set($model, string $key, $value, array $attributes)
    {

        if($value instanceof Documan) {
            return $value->showFileName();
        }

        if(request()->$value) {
            $documan = $this->chooseDisk(new Documan());

            if($this->sizes){
                foreach ($this->sizes as $size) {
                    $documan->$size();
                }
            }

            $filesArr = $documan->upload(request(), $value);

            if(isset($filesArr['base_name'])) {
                return $filesArr['base_name'];
            }

            return $filesArr;
        } else {
            return $value;
        }
    }
}
