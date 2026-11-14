<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tekkenking\Documan\Documan;
use Tekkenking\Documan\DocumanCast;
use Tekkenking\Documan\DocumanCollections;
use Tekkenking\Documan\DocumanException;
use Tekkenking\Documan\DocumanSingle;
use Tekkenking\Documan\ImageResizer;

/*
|--------------------------------------------------------------------------
| One test per defect found in the 0.8.2 audit.
|
| These run on real directories with the shipped config - no disk set in
| it - because the earlier tests set one, which hid the first defect.
|--------------------------------------------------------------------------
*/

function auditRoot(string $disk): string
{
    return sys_get_temp_dir().'/documan_audit_'.$disk;
}

function auditImage(int $width = 900, int $height = 900, string $name = 'photo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

function auditResizer(bool $imagick): ImageResizer
{
    $resizer = new class('photos') extends ImageResizer {
        public function engine(bool $imagick): static
        {
            $this->useImagick = $imagick;

            return $this;
        }
    };

    return $resizer->engine($imagick);
}

beforeEach(function () {
    foreach (['photos', 'other'] as $disk) {
        File::deleteDirectory(auditRoot($disk));
        File::makeDirectory(auditRoot($disk), 0755, true);
        config()->set("filesystems.disks.$disk", [
            'driver' => 'local',
            'root'   => auditRoot($disk),
            'url'    => "http://localhost/$disk",
        ]);
    }
});

afterEach(function () {
    foreach (['photos', 'other'] as $disk) {
        File::deleteDirectory(auditRoot($disk));
    }
});

// ── The disk ────────────────────────────────────────────────────────────

it('uses the disk passed to documan()', function () {
    expect(documan('photos')->getDisk())->toBe('photos');

    $result = documan('photos')->upload_without_request(auditImage());

    expect(Storage::disk('photos')->exists($result['base_name']))->toBeTrue();
});

it('prefers the disk passed to documan() over the configured one', function () {
    config()->set('documan.disk', 'other');

    expect(documan('photos')->getDisk())->toBe('photos')
        ->and(documan()->getDisk())->toBe('other');
});

// ── Showing ─────────────────────────────────────────────────────────────

it('returns a url from every size method after show()', function (string $size) {
    $result = documan('photos')->small()->thumbnail()->medium()->sizes(['big', 'tiny'])
        ->upload_without_request(auditImage(1700, 1700));
    $name = $result['base_name'];

    $shown = documan('photos')->show($name)->{$size}();

    expect($shown->first())->toBe("http://localhost/photos/{$size}_{$name}")
        ->and((string) $shown)->toBe("http://localhost/photos/{$size}_{$name}");
})->with(['small', 'thumbnail', 'medium', 'big', 'tiny']);

it('gives each size its own url when several are asked of one object', function () {
    $name = documan('photos')->medium()->small()->upload_without_request(auditImage())['base_name'];
    $doc = documan('photos')->show($name);

    expect($doc->medium)->toBe("http://localhost/photos/medium_{$name}")
        ->and($doc->small)->toBe("http://localhost/photos/small_{$name}")
        ->and($doc->medium)->toBe("http://localhost/photos/medium_{$name}");
});

it('shows the original by name', function () {
    $name = documan('photos')->medium()->upload_without_request(auditImage())['base_name'];

    expect(documan('photos')->show($name)->original)->toBe("http://localhost/photos/{$name}")
        ->and((string) documan('photos')->show($name)->original())->toBe("http://localhost/photos/{$name}");
});

it('shows a file on a local disk that declares no url', function () {
    config()->set('filesystems.disks.photos', ['driver' => 'local', 'root' => auditRoot('photos')]);
    $name = documan('photos')->medium()->upload_without_request(auditImage())['base_name'];

    expect(documan('photos')->show($name)->medium)->toEndWith("medium_{$name}");
});

it('can skip the existence check on a remote disk', function () {
    config()->set('filesystems.disks.spaces', ['driver' => 's3', 'bucket' => 'b', 'region' => 'r', 'key' => 'k', 'secret' => 's']);
    Storage::fake('spaces');

    //Nothing is stored, so the check falls back to the bare name...
    expect(documan('spaces')->show('abc.jpg', true)->medium)->toBe('abc.jpg');

    //...and with the check off the variant's name is trusted.
    config()->set('documan.verifyVariantExists', false);

    expect(documan('spaces')->show('abc.jpg', true)->medium)->toBe('medium_abc.jpg')
        ->and(documan('spaces')->show('abc.jpg', true)->original)->toBe('abc.jpg');
});

// ── Serialising ─────────────────────────────────────────────────────────

it('serialises a shown file to its stored name', function () {
    $doc = documan('photos')->show('abc.jpg');

    expect(json_encode(['avatar' => $doc]))->toBe('{"avatar":"abc.jpg"}')
        ->and((new DocumanCast('photos'))->serialize(null, 'avatar', $doc, []))->toBe('abc.jpg');
});

it('registers a facade alias that exists', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true);

    expect(class_exists($composer['extra']['laravel']['aliases']['Documan']))->toBeTrue();
});

// ── Uploading ───────────────────────────────────────────────────────────

it('returns a collection object from upload() when asked to', function () {
    config()->set('documan.defaultReturn', 'Collection');
    $request = Request::create('/upload', 'POST', [], [], ['one' => auditImage(), 'many' => [auditImage(), auditImage()]]);

    expect(documan('photos')->upload($request, 'one'))->toBeInstanceOf(DocumanSingle::class)
        ->and(documan('photos')->upload($request, 'many'))->toBeInstanceOf(DocumanCollections::class);
});

it('accepts a type added with addExtension()', function () {
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('a.txt', 'hello');
    $zip->close();

    $result = documan('photos')->addExtension(['zip'])
        ->upload_without_request(new UploadedFile($path, 'archive.zip', null, null, true));

    expect($result['fileType'])->toBe('others')
        ->and($result['base_name'])->toEndWith('.zip')
        ->and(Storage::disk('photos')->exists($result['base_name']))->toBeTrue();
})->skip(!class_exists(ZipArchive::class), 'ext-zip is not installed');

it('still refuses a zip that was not added', function () {
    $file = UploadedFile::fake()->createWithContent('archive.zip', "PK\x03\x04".str_repeat("\0", 40));

    documan('photos')->upload_without_request($file);
})->throws(DocumanException::class);

it('refuses a php file even when php is added by hand', function () {
    $file = UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;');

    documan('photos')->addExtension(['php'])->upload_without_request($file);
})->throws(DocumanException::class, "cannot be uploaded");

it('names a file after its contents, not the name it was sent with', function () {
    $png = UploadedFile::fake()->image('real.png', 40, 40);
    $disguised = new UploadedFile($png->getRealPath(), 'photo.jpg', null, null, true);

    $result = documan('photos')->small()->upload_without_request($disguised);

    expect($result['base_name'])->toEndWith('.png')
        ->and($result['variations']['small'])->toEndWith('.png');
});

it('keeps a jpeg extension the client sent when the config allows it', function () {
    $result = documan('photos')->upload_without_request(auditImage(40, 40, 'photo.jpeg'));

    expect($result['base_name'])->toEndWith('.jpeg');
});

it('reads the always-upload sizes under both spellings of the key', function (string $key) {
    $config = config('documan');
    unset($config['uploadDefaultImageSizes'], $config['uploadDefaulImageSizes']);
    config()->set('documan', $config + [$key => ['tiny']]);

    $result = documan('photos')->upload_without_request(auditImage());

    expect($result['variations'])->toHaveKey('tiny');
})->with(['uploadDefaultImageSizes', 'uploadDefaulImageSizes']);

it('leaves nothing behind when a multi-file upload fails', function () {
    $files = [auditImage(), UploadedFile::fake()->createWithContent('x.php', '<?php echo 1;')];

    try {
        documan('photos')->medium()->upload_without_request($files);
        $this->fail('The upload should have been refused.');
    } catch (DocumanException) {
        expect(Storage::disk('photos')->allFiles())->toBe([]);
    }
});

// ── Deleting ────────────────────────────────────────────────────────────

it('deletes webp copies and the custom size along with the rest', function () {
    config()->set('documan.outputWebp', true);
    $result = documan('photos')->medium()->custom(120, 120)->upload_without_request(auditImage());

    expect(count(Storage::disk('photos')->allFiles()))->toBeGreaterThan(3);

    documan('photos')->delete($result['base_name']);

    expect(Storage::disk('photos')->allFiles())->toBe([]);
});

// ── Resizing ────────────────────────────────────────────────────────────

it('keeps the shape of the picture', function (bool $imagick) {
    $source = auditImage(1600, 900);
    auditResizer($imagick)->resizeAndPreserveExif($source->getRealPath(), 'wide.jpg', 800, 800);

    [$width, $height] = getimagesize(Storage::disk('photos')->path('wide.jpg'));

    expect([$width, $height])->toBe([800, 450]);
})->with([
    'gd'      => [false],
    'imagick' => [true],
])->skip(fn () => !extension_loaded('gd'), 'ext-gd is not installed');

it('never enlarges a small picture', function (bool $imagick) {
    $source = auditImage(300, 200);
    auditResizer($imagick)->resizeAndPreserveExif($source->getRealPath(), 'small.jpg', 800, 800);

    [$width, $height] = getimagesize(Storage::disk('photos')->path('small.jpg'));

    expect([$width, $height])->toBe([300, 200]);
})->with([
    'gd'      => [false],
    'imagick' => [true],
]);

it('turns a phone photo upright with gd', function () {
    //A 400x200 JPEG carrying the EXIF note "rotate 90 degrees", which is
    //how a phone stores a portrait photo. Upright it is 200 wide, 400 tall.
    ob_start();
    imagejpeg(imagecreatetruecolor(400, 200));
    $jpeg = ob_get_clean();

    $tiff = "II*\0".pack('V', 8).pack('v', 1)
        .pack('vvVV', 0x0112, 3, 1, 6)   // Orientation = 6
        .pack('V', 0);
    $exif = "\xFF\xE1".pack('n', strlen($tiff) + 8)."Exif\0\0".$tiff;

    $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($path, substr($jpeg, 0, 2).$exif.substr($jpeg, 2));

    expect(exif_read_data($path)['Orientation'])->toBe(6);

    auditResizer(false)->resizeAndPreserveExif($path, 'upright.jpg', 800, 800);
    [$width, $height] = getimagesize(Storage::disk('photos')->path('upright.jpg'));
    @unlink($path);

    expect([$width, $height])->toBe([200, 400]);
})->skip(fn () => !extension_loaded('gd') || !function_exists('exif_read_data'), 'needs ext-gd and ext-exif');

// ── Through a model ─────────────────────────────────────────────────────

it('gives sizes from a model attribute as a property and as a method', function () {
    $name = documan('photos')->medium()->small()->upload_without_request(auditImage())['base_name'];

    $user = new class extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
        protected $casts = ['avatar' => DocumanCast::class.':photos:small|medium'];
    };
    $user->setRawAttributes(['avatar' => $name]);

    expect($user->avatar->medium)->toBe("http://localhost/photos/medium_{$name}")
        ->and($user->avatar->small)->toBe("http://localhost/photos/small_{$name}")
        ->and((string) $user->avatar->medium())->toBe("http://localhost/photos/medium_{$name}")
        ->and($user->avatar->original)->toBe("http://localhost/photos/{$name}")
        ->and($user->toArray()['avatar'])->toBe($name);
});

// ── Private uploads ─────────────────────────────────────────────────────

it('returns signed links from upload for a private file on a remote disk', function () {
    config()->set('filesystems.disks.spaces', [
        'driver' => 's3', 'bucket' => 'b', 'region' => 'r', 'key' => 'k', 'secret' => 's',
        'url' => 'https://b.example.com',
    ]);
    Storage::fake('spaces');
    Storage::disk('spaces')->buildTemporaryUrlsUsing(
        fn (string $path, $expiry) => "https://signed.example.com/{$path}?expires=".$expiry->getTimestamp()
    );

    $private = documan('spaces')->visibility('private')->medium()->upload_without_request(auditImage());
    $name = $private['base_name'];

    expect($private['links']['medium'])->toStartWith("https://signed.example.com/medium_{$name}?expires=")
        ->and($private['links']['original'])->toStartWith("https://signed.example.com/{$name}?expires=")
        ->and($private['links']['medium'])->toBe((string) documan('spaces')->visibility('private')->show($name)->medium());

    $public = documan('spaces')->medium()->upload_without_request(auditImage());

    expect($public['links']['medium'])->toBe('https://b.example.com/medium_'.$public['base_name']);
});

it('returns a signed link from upload for a private document on a remote disk', function () {
    config()->set('filesystems.disks.spaces', [
        'driver' => 's3', 'bucket' => 'b', 'region' => 'r', 'key' => 'k', 'secret' => 's',
        'url' => 'https://b.example.com',
    ]);
    Storage::fake('spaces');
    Storage::disk('spaces')->buildTemporaryUrlsUsing(
        fn (string $path, $expiry) => "https://signed.example.com/{$path}?expires=".$expiry->getTimestamp()
    );

    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    $result = documan('spaces')->visibility('private')->upload_without_request($file);

    expect($result['link'])->toStartWith('https://signed.example.com/'.$result['base_name'].'?expires=');
});
