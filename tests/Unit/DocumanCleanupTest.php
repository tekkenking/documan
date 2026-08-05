<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tekkenking\Documan\DocumanBase;
use Tekkenking\Documan\DocumanCast;

beforeEach(function () {
    config()->set('documan', array_merge(
        require __DIR__ . '/../../config/documan.php',
        ['disk' => 'testing']
    ));
    config()->set('filesystems.disks.testing', [
        'driver' => 'local',
        'root'   => storage_path('framework/testing/documan'),
        'url'    => 'http://localhost/storage',
    ]);
    Storage::fake('testing');
});

it('recursively casts files arrays to stdClass objects', function () {
    $base = new class(['base_name' => 'file.jpg', 'variations' => ['original' => 'file.jpg']]) extends DocumanBase {};

    expect($base->files)->toBeObject()
        ->and($base->files->variations)->toBeObject()
        ->and($base->files->variations->original)->toBe('file.jpg');
});

it('returns stored filename strings unchanged in DocumanCast::set', function () {
    $cast = new DocumanCast('testing:medium');

    expect($cast->set(new stdClass(), 'avatar', 'stored-file.jpg', []))->toBe('stored-file.jpg');
});

it('uploads an UploadedFile instance directly in DocumanCast::set', function () {
    if (! function_exists('imagepng')) {
        $this->markTestSkipped('GD is required for image upload test.');
    }

    $cast = new DocumanCast('testing:medium');
    $tmp = tempnam(sys_get_temp_dir(), 'documan_cast_') . '.png';
    $image = imagecreatetruecolor(12, 12);
    imagepng($image, $tmp);
    imagedestroy($image);

    try {
        $file = new UploadedFile($tmp, 'avatar.png', 'image/png', null, true);
        $result = $cast->set(new stdClass(), 'avatar', $file, []);
    } finally {
        @unlink($tmp);
    }

    expect($result)->toBeString()->and($result)->toEndWith('.png');
    Storage::disk('testing')->assertExists($result);
    Storage::disk('testing')->assertExists('medium_' . $result);
});
