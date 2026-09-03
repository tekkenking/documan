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
        ['disk' => 'testing']
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

it('delete() hard mode removes base_name, legacy original_ prefix, and all size variants', function () {
    Storage::fake('testing');

    $disk     = Storage::disk('testing');
    $baseName = 'abc123.jpg';

    // New-style original (plain base_name) + legacy original_ + size variants
    $disk->put($baseName, 'data');
    $disk->put('original_' . $baseName, 'data');   // backward-compat legacy file
    $disk->put('medium_' . $baseName, 'data');
    $disk->put('small_' . $baseName, 'data');

    $documan = new Documan('testing');
    $documan->delete($baseName);

    Storage::disk('testing')->assertMissing($baseName);
    Storage::disk('testing')->assertMissing('original_' . $baseName);
    Storage::disk('testing')->assertMissing('medium_' . $baseName);
    Storage::disk('testing')->assertMissing('small_' . $baseName);
});

it('delete() soft mode moves files to the trash folder instead of deleting them', function () {
    Storage::fake('testing');

    config()->set('documan.delete.mode', 'soft');
    config()->set('documan.delete.trash_folder', 'trash');

    $disk     = Storage::disk('testing');
    $baseName = 'abc123.jpg';

    $disk->put($baseName, 'data');
    $disk->put('medium_' . $baseName, 'data');

    $documan = new Documan('testing');
    $documan->delete($baseName);

    // Originals moved to trash
    Storage::disk('testing')->assertMissing($baseName);
    Storage::disk('testing')->assertMissing('medium_' . $baseName);
    Storage::disk('testing')->assertExists('trash/' . $baseName);
    Storage::disk('testing')->assertExists('trash/medium_' . $baseName);
});


it('delete() skips missing candidates without failing', function () {
    $documan = new Documan('testing');

    expect($documan->delete('missing.jpg'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Image resize — local vs remote (S3-compatible) disks
|--------------------------------------------------------------------------
*/

it('resizes and stores an uploaded image on a local disk without throwing', function () {
    $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

    $documan = new Documan('testing');
    $result  = $documan->small()->upload_without_request($file);

    expect($result['fileType'])->toBe('image');
    expect($result['variations'])->toHaveKeys(['original', 'small']);

    Storage::disk('testing')->assertExists($result['base_name']);
    Storage::disk('testing')->assertExists($result['variations']['small']);
});

it('resizes and stores an uploaded image on a remote (S3-compatible) disk without a local-path error', function () {
    config()->set('documan', array_merge(
        require __DIR__ . '/../../config/documan.php',
        ['disk' => 'spaces']
    ));
    config()->set('filesystems.disks.spaces', [
        'driver'     => 's3',
        'key'        => 'test-key',
        'secret'     => 'test-secret',
        'region'     => 'us-east-1',
        'bucket'     => 'test-bucket',
        'endpoint'   => 'https://nyc3.digitaloceanspaces.com',
        'url'        => 'https://test-bucket.nyc3.digitaloceanspaces.com',
        'visibility' => 'public',
    ]);
    Storage::fake('spaces');

    // Storage::fake() swaps the resolved filesystem instance, but must not
    // rewrite the disk's config — isLocalDisk() reads config directly, so
    // this guards against a Laravel version regressing that guarantee and
    // silently turning this into a local-disk test.
    expect(config('filesystems.disks.spaces.driver'))->toBe('s3');

    $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

    $documan = new Documan('spaces');
    $result  = $documan->small()->upload_without_request($file);

    expect($result['fileType'])->toBe('image');
    expect($result['variations'])->toHaveKeys(['original', 'small']);

    Storage::disk('spaces')->assertExists($result['base_name']);
    Storage::disk('spaces')->assertExists($result['variations']['small']);
});

it('show() resolves a public URL via Storage::disk()->url() for a remote disk instead of a local path', function () {
    config()->set('documan', array_merge(
        require __DIR__ . '/../../config/documan.php',
        ['disk' => 'spaces']
    ));
    config()->set('filesystems.disks.spaces', [
        'driver'     => 's3',
        'key'        => 'test-key',
        'secret'     => 'test-secret',
        'region'     => 'us-east-1',
        'bucket'     => 'test-bucket',
        'endpoint'   => 'https://nyc3.digitaloceanspaces.com',
        'url'        => 'https://test-bucket.nyc3.digitaloceanspaces.com',
        'visibility' => 'public',
    ]);
    Storage::fake('spaces');

    expect(config('filesystems.disks.spaces.driver'))->toBe('s3');

    Storage::disk('spaces')->put('small_abc123.jpg', 'fake-image-data');

    $documan = new Documan('spaces');
    $url     = $documan->show('abc123.jpg')->small()->first();

    expect($url)->toBe(Storage::disk('spaces')->url('small_abc123.jpg'));
});
