<?php

declare(strict_types=1);

use Tekkenking\Documan\Documan;
use Tekkenking\Documan\DocumanException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Upload pipeline — feature tests
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('documan', array_merge(
        require __DIR__ . '/../../config/documan.php',
        ['disk' => 'testing', 'keepOriginalSize' => false]
    ));
    config()->set('filesystems.disks.testing', [
        'driver' => 'local',
        'root'   => storage_path('framework/testing/documan'),
        'url'    => 'http://localhost/storage',
    ]);
    Storage::fake('testing');
});

it('throws DocumanException when no file is present in the request', function () {
    $request = \Illuminate\Http\Request::create('/upload', 'POST');
    $documan = new Documan('testing');
    $documan->upload($request, 'avatar');
})->throws(DocumanException::class);

it('upload_without_request rejects a file with a disallowed MIME type', function () {
    $file    = UploadedFile::fake()->createWithContent('malicious.php', '<?php echo "pwned"; ?>');
    $documan = new Documan('testing');
    $documan->upload_without_request($file);
})->throws(DocumanException::class);

it('documan() helper returns a fresh instance on every call', function () {
    $a = documan('testing');
    $b = documan('testing');
    expect($a)->not->toBe($b);
});

it('delete() removes all size variants from disk', function () {
    Storage::fake('testing');

    $disk     = Storage::disk('testing');
    $baseName = 'abc123.jpg';

    $disk->put($baseName, 'data');
    $disk->put('original_' . $baseName, 'data');
    $disk->put('medium_' . $baseName, 'data');
    $disk->put('small_' . $baseName, 'data');

    $documan = new Documan('testing');
    $documan->delete($baseName);

    Storage::disk('testing')->assertMissing($baseName);
    Storage::disk('testing')->assertMissing('original_' . $baseName);
    Storage::disk('testing')->assertMissing('medium_' . $baseName);
    Storage::disk('testing')->assertMissing('small_' . $baseName);
});
