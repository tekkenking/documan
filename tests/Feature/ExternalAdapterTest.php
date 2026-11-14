<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tekkenking\Documan\DocumanCast;
use Tekkenking\Documan\Interface\ExternalShow;
use Tekkenking\Documan\Interface\ExternalUpload;

/*
|--------------------------------------------------------------------------
| The external adapter: upload() and show() handed to another service.
|
| An application in production depends on this path, so what an adapter
| is given, and what comes back, is pinned here.
|--------------------------------------------------------------------------
*/

class RecordingUploadAdapter implements ExternalUpload
{
    /** @var array<int, array{files: int, sizes: array}> */
    public static array $calls = [];

    public static bool $decline = false;

    public function externalUpload(UploadedFile|array $file, array $sizes = []): array|bool
    {
        if (self::$decline) {
            return false;
        }

        $files = is_array($file) ? $file : [$file];
        self::$calls[] = ['files' => count($files), 'sizes' => $sizes];

        return $this->forDocuman(array_map(
            fn (UploadedFile $one) => sha1($one->getClientOriginalName()),
            $files
        ));
    }

    public function forDocuman(array $assets): string|array
    {
        if (count($assets) === 1) {
            return ['base_name' => $assets[0]];
        }

        return array_map(fn (string $sha) => ['base_name' => $sha], $assets);
    }
}

class RecordingShowAdapter implements ExternalShow
{
    /** @var array<int, int|string|null> */
    public static array $sizes = [];

    public function externalShow(string $fileSha, int|string|null $size = null): string
    {
        self::$sizes[] = $size;

        return "https://media.test/{$fileSha}/".$this->resolveSize($size);
    }

    public function resolveSize(int|string|null $size): int
    {
        return is_int($size) ? $size : (int) (config("documan.defaultImageSizes.{$size}.width") ?? 0);
    }
}

beforeEach(function () {
    RecordingUploadAdapter::$calls = [];
    RecordingUploadAdapter::$decline = false;
    RecordingShowAdapter::$sizes = [];

    $config = require __DIR__.'/../../config/documan.php';
    $config['externalAdapter'] = [
        'enabled' => true,
        'adapter' => ['upload' => RecordingUploadAdapter::class, 'show' => RecordingShowAdapter::class],
    ];
    config()->set('documan', $config);
});

function adapterFile(string $name = 'photo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 60, 40);
}

function adapterRequest(string $field, UploadedFile|array $file): Request
{
    $request = Request::create('/upload', 'POST', [], [], [$field => $file]);
    app()->instance('request', $request);

    return $request;
}

it('hands an upload to the adapter and returns its answer, with no disk set', function () {
    $result = documan()->upload(adapterRequest('avatar', adapterFile()), 'avatar');

    expect($result)->toBe(['base_name' => sha1('photo.jpg')])
        ->and(RecordingUploadAdapter::$calls)->toBe([['files' => 1, 'sizes' => []]]);
});

it('hands several files to the adapter in one call', function () {
    $result = documan()->upload(adapterRequest('photos', [adapterFile('a.jpg'), adapterFile('b.jpg')]), 'photos');

    expect($result)->toBe([['base_name' => sha1('a.jpg')], ['base_name' => sha1('b.jpg')]])
        ->and(RecordingUploadAdapter::$calls)->toBe([['files' => 2, 'sizes' => []]]);
});

it('hands upload_without_request to the adapter', function () {
    expect(documan()->upload_without_request(adapterFile()))->toBe(['base_name' => sha1('photo.jpg')]);
});

it('stores nothing itself when the adapter takes the upload', function () {
    Storage::fake('photos');

    documan('photos')->medium()->upload(adapterRequest('avatar', adapterFile()), 'avatar');

    expect(Storage::disk('photos')->allFiles())->toBe([]);
});

it('falls back to the built-in upload when the adapter returns false', function () {
    Storage::fake('photos');
    RecordingUploadAdapter::$decline = true;

    $result = documan('photos')->upload_without_request(adapterFile());

    Storage::disk('photos')->assertExists($result['base_name']);
});

it('asks the adapter for each named size, as a method and as a property', function () {
    $sha = sha1('photo.jpg');

    expect((string) documan()->show($sha)->big())->toBe("https://media.test/{$sha}/1600")
        ->and((string) documan()->show($sha)->medium())->toBe("https://media.test/{$sha}/800")
        ->and((string) documan()->show($sha)->thumbnail())->toBe("https://media.test/{$sha}/400")
        ->and((string) documan()->show($sha)->small())->toBe("https://media.test/{$sha}/170")
        ->and((string) documan()->show($sha)->tiny())->toBe("https://media.test/{$sha}/50")
        ->and(documan()->show($sha)->big()->first())->toBe("https://media.test/{$sha}/1600")
        ->and(documan()->show($sha)->medium)->toBe("https://media.test/{$sha}/800")
        ->and(RecordingShowAdapter::$sizes)->toBe(['big', 'medium', 'thumbnail', 'small', 'tiny', 'big', 'medium']);
});

it('gives the adapter a numeric custom size as an integer', function () {
    $sha = sha1('photo.jpg');

    expect((string) documan()->show($sha)->custom(300))->toBe("https://media.test/{$sha}/300")
        ->and(RecordingShowAdapter::$sizes)->toBe([300]);
});

it('gives the adapter a named custom size and the original by name', function () {
    $sha = sha1('photo.jpg');

    documan()->show($sha)->custom('large');
    documan()->show($sha)->original();

    expect(RecordingShowAdapter::$sizes)->toBe(['large', 'original']);
});

it('ignores dimensions passed to a size method when showing a file', function () {
    $sha = sha1('photo.jpg');

    expect((string) documan()->show($sha)->big(['width' => 10, 'height' => 10]))->toBe("https://media.test/{$sha}/1600");
});

it('does not check the file id against storage file name rules', function () {
    expect((string) documan()->show('tenant/abc123')->big())->toBe('https://media.test/tenant/abc123/1600');
});

it('uploads through the adapter on save and shows through it on read, from a model', function () {
    adapterRequest('avatar', adapterFile());

    $user = new class extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
        protected $casts = ['avatar' => DocumanCast::class.':photos:small|medium'];
    };
    $user->avatar = 'avatar';
    $sha = sha1('photo.jpg');

    expect($user->getAttributes()['avatar'])->toBe($sha)
        ->and((string) $user->avatar->big())->toBe("https://media.test/{$sha}/1600")
        ->and($user->avatar->medium)->toBe("https://media.test/{$sha}/800")
        ->and($user->toArray()['avatar'])->toBe($sha);
});
