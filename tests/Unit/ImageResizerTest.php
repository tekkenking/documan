<?php

declare(strict_types=1);

use Tekkenking\Documan\ImageResizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| ImageResizer – unit tests
|--------------------------------------------------------------------------
*/

it('constructs with default disk', function () {
    $resizer = new ImageResizer();
    expect($resizer)->toBeInstanceOf(ImageResizer::class);
});

it('setDisk returns self and changes disk', function () {
    $resizer = new ImageResizer('public');
    $result  = $resizer->setDisk('local');
    expect($result)->toBe($resizer);
});

it('throws when given an invalid image path', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->createWithContent('not_an_image.jpg', 'not real image data');

    $resizer = new ImageResizer('public');
    $resizer->resizeAndPreserveExif($file, 'output/test.jpg', 800);
})->throws(\Exception::class);


it('throws a clear exception when a watermark png cannot be loaded by gd', function () {
    if (extension_loaded('imagick')) {
        $this->markTestSkipped('GD-specific watermark failure path is skipped when Imagick is enabled.');
    }

    Storage::fake('public');

    $sourcePath = sys_get_temp_dir() . '/documan_source_' . uniqid() . '.png';
    $image = imagecreatetruecolor(20, 20);
    imagepng($image, $sourcePath);
    imagedestroy($image);

    $watermarkPath = sys_get_temp_dir() . '/documan_bad_watermark_' . uniqid() . '.png';
    file_put_contents($watermarkPath, 'not a real png');

    try {
        $resizer = new ImageResizer('public');
        $resizer->resizeAndPreserveExif($sourcePath, 'output/test.png', 10, 10, $watermarkPath);
    } finally {
        @unlink($sourcePath);
        @unlink($watermarkPath);
    }
})->throws(\Tekkenking\Documan\DocumanException::class, 'Failed to load watermark PNG image');
