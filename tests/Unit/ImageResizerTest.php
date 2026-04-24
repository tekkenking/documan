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
