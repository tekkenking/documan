# Documan — Laravel File Upload & Display Manager

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tekkenking/documan.svg?style=flat-square)](https://packagist.org/packages/tekkenking/documan)
[![Total Downloads](https://img.shields.io/packagist/dt/tekkenking/documan.svg?style=flat-square)](https://packagist.org/packages/tekkenking/documan)
[![PHP Version](https://img.shields.io/packagist/php-v/tekkenking/documan.svg?style=flat-square)](https://packagist.org/packages/tekkenking/documan)
[![License](https://img.shields.io/packagist/l/tekkenking/documan.svg?style=flat-square)](LICENSE)
[![Tests](https://github.com/tekkenking/documan/actions/workflows/tests.yml/badge.svg)](https://github.com/tekkenking/documan/actions/workflows/tests.yml)

Upload a file once and get back every size you need. Documan stores the original on any Laravel filesystem disk and automatically creates named image variants you can fetch by name — like Cloudinary, but fully self-hosted on a disk you control.

```php
// Upload an avatar and generate three sizes
$result = documan('s3')->small()->thumbnail()->medium()->upload($request, 'avatar');

$user->avatar = $result['base_name'];
$user->save();

// Fetch any size back
documan('s3')->show($user->avatar)->medium();
```

Or let an Eloquent cast handle all of it:

```php
protected $casts = [
    'avatar' => DocumanCast::class.':s3:small|thumbnail|medium',
];

$user->avatar = 'avatar';   // the file input name
$user->save();              // uploads and resizes here

echo $user->avatar->medium();
```

## Features

- **Any disk** — local, S3, or any S3-compatible driver, using the disks already in your `filesystems.php`. [Tested on real S3 storage](#test-status).
- **Named image variants** — five built-in sizes plus any custom size you define
- **Eloquent cast** — upload on save, resolve URLs on read
- **Validation rule** — `DocumanFile` covers images, PDFs, documents, spreadsheets and presentations
- **Soft deletes** — move files to a trash folder instead of destroying them
- **CDN support** — build display URLs against a remote host
- **Queue support** — generate variants in background jobs
- **WebP output** — an optional `.webp` companion for every variant
- **Resource limits** — file size, upload total, file count and pixel limits enforced by default

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Basic Usage](#basic-usage)
- [Displaying / Showing a File](#displaying--showing-a-file)
- [Deleting a File](#deleting-a-file)
- [Available Default Sizes](#available-default-sizes)
- [Validation Rule](#validation-rule)
- [Using the Cast](#using-the-cast)
- [Serving Through a CDN](#serving-through-a-cdn)
- [Return Format](#return-format)
- [S3 / Cloud File Visibility](#s3--cloud-file-visibility)
- [Storing on S3 and S3-Compatible Disks](#storing-on-s3-and-s3-compatible-disks)
- [Async / Queue Processing](#async--queue-processing)
- [External Adapter](#external-adapter)
- [Global Helper Functions](#global-helper-functions)
- [Full Configuration Reference](#full-configuration-reference)
- [Upgrading to 1.0](#upgrading-to-10)
- [Migration from Earlier Versions](#migration-from-earlier-versions)
- [Test Status](#test-status)
- [License](#license)

## Requirements

- PHP ^8.2
- Laravel 12.x or 13.x
- `ext-fileinfo`, and for image variants either `ext-imagick` (preferred) or `ext-gd`. With GD, `ext-exif` is needed to turn phone photos the right way up.

## Installation

```bash
composer require tekkenking/documan
```

Laravel discovers the package on its own. There is no provider to register.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --provider="Tekkenking\Documan\DocumanServiceProvider"
```

This creates `config/documan.php`. See the [Full Configuration Reference](#full-configuration-reference) section for every available key.

---

## Basic Usage

### Uploading a File

The original file is **always** stored automatically — it is saved as the plain `base_name` on disk (no prefix). Resized variants are stored alongside it with their size prefix (e.g. `medium_AbCdEfGhIj.jpg`).

```php
use Illuminate\Http\Request;

public function store(Request $request)
{
    $result = documan('my_disk')
        ->small()
        ->thumbnail()
        ->medium()
        ->upload($request, 'avatar');

    // Save base_name to the database
    $user->avatar = $result['base_name'];
    $user->save();
}
```

#### What `upload()` gives back

`$result` is an array describing what was stored. Nothing here needs configuring; it is what the call returns:

```php
[
    'fileType'   => 'image',
    'base_name'  => 'AbCdEfGhIj.jpg',            // the original on disk; store this
    'variations' => [
        'original'  => 'AbCdEfGhIj.jpg',
        'small'     => 'small_AbCdEfGhIj.jpg',
        'thumbnail' => 'thumbnail_AbCdEfGhIj.jpg',
        'medium'    => 'medium_AbCdEfGhIj.jpg',
    ],
    'links' => [                                 // one URL per variation
        'original'  => 'https://example.com/storage/my_disk/AbCdEfGhIj.jpg',
        'small'     => 'https://example.com/storage/my_disk/small_AbCdEfGhIj.jpg',
        'thumbnail' => 'https://example.com/storage/my_disk/thumbnail_AbCdEfGhIj.jpg',
        'medium'    => 'https://example.com/storage/my_disk/medium_AbCdEfGhIj.jpg',
    ],
]
```

`links` is there by default; see [Return Format](#return-format) to turn it off or to add local paths.

> **Backward compatibility** — Images uploaded with an earlier version of Documan may have their original stored as `original_AbCdEfGhIj.jpg` (with the `original_` prefix). Documan handles both formats transparently when displaying and deleting files.

### Uploading Without a Request Object

If you already have a file object (e.g. from a test or a queued job), use `upload_without_request()`:

```php
$result = documan('my_disk')
    ->small()
    ->medium()
    ->upload_without_request($uploadedFileObject);
```

### Moving a File Between Disks

`move()` copies a file from a source disk to the currently configured disk and creates the requested size variants:

```php
$result = documan('destination_disk')
    ->thumbnail()
    ->medium()
    ->move('AbCdEfGhIj.jpg', 'source_disk');
```

The source is copied through a temporary file using streams, and upload size limits are applied during the copy. Pass a plain storage filename, not a path: file names containing `/`, `\`, control characters, `.` or `..` are rejected. The same filename rule applies to `delete()` and custom size names. `move()` also recognizes legacy originals stored with an `original_` prefix.

### Uploading with Custom Sizes

Pass an associative array of `name => ['width' => x, 'height' => y]` to register arbitrary sizes at upload time:

```php
$result = documan('my_disk')
    ->sizes([
        'large' => ['width' => 1200, 'height' => 900],
        'icon'  => ['width' => 32,   'height' => 32],
    ])
    ->upload($request, 'photo');
```

You can also reference an existing default size by its string name in the array:

```php
->sizes(['medium', 'thumbnail'])
```

### Upload and image limits

The defaults limit each file to 20 MiB, the total upload to 40 MiB, and each upload call to 20 files. Image size requests are limited by `maxVariantsPerUpload` (20 by default). Set any of these limits to `0` to disable it. Configure these values in the published `config/documan.php`.

`maxImagePixels` defaults to 40 million pixels and is checked when image resizing runs, including source and output dimensions. An original-only image upload does not invoke resizing. To generate a WebP companion for each resized image where the active image driver supports WebP, enable `outputWebp`.

### Adding Extra Allowed Extensions

```php
$result = documan('my_disk')
    ->addExtension(['zip', 'txt'])
    ->upload($request, 'file');

// $result['fileType'] => 'others'
```

Note the two spellings: `upload()` reports an added type as `others`, while `getType()` on a shown file reports `other`.

The built-in types are recognised by their contents. A type you add this way is not one Documan can recognise, so it is matched on the file's **extension**, which the uploader controls. Add only types you are happy to store from that user, and do not serve them from a path that executes code. Extensions a web server may run (`php`, `phtml`, `phar` and similar) are refused even if you add them.

### How a file is named

The stored extension follows the file's contents, not the name it was sent with: a PNG uploaded as `photo.jpg` is stored as `.png`.

### When an upload fails

If any file in an upload call is refused or cannot be processed, everything that call had already stored is removed before the exception is thrown, so a failed upload leaves nothing behind.

---

## Displaying / Showing a File

Use `show($baseName)` to set the file, then name a size. As a **property** it gives the URL as a string; as a **method** it gives an object that prints as the URL, so both work in a Blade template:

```php
$doc = documan('my_disk')->show($user->avatar);

$url = $doc->medium;            // string
echo $doc->medium();            // prints the URL
$url = $doc->medium()->first(); // string
```

```php
// Named sizes
$url = documan('my_disk')->show($user->avatar)->medium();
$url = documan('my_disk')->show($user->avatar)->thumbnail();
$url = documan('my_disk')->show($user->avatar)->small();
$url = documan('my_disk')->show($user->avatar)->big();
$url = documan('my_disk')->show($user->avatar)->tiny();

// Original (full-size)
$url = documan('my_disk')->show($user->avatar)->original();

// Custom size (resolves to a variant named 'custom', or any name you supply)
$url = documan('my_disk')->show($user->avatar)->custom('large');

// Return only the filename instead of the full URL
$name = documan('my_disk')->show($user->avatar, true)->medium();
```

If the size asked for was never created, the original is returned instead.

On a remote disk such as S3, that fallback costs one request to the storage provider for every image shown. For a page listing many images, set `'verifyVariantExists' => false` in `config/documan.php`: the check is skipped and the name is trusted, so a size that was never created gives a broken link rather than the original.

### Show Helper Methods

After calling `show()`, the following helper methods are also available:

| Method | Returns | Description |
|---|---|---|
| `getExtension()` | `string` | File extension derived from the stored `base_name` |
| `getType()` | `string` | Group name, worked out from the extension: `image`, `pdf`, `document`, `excel`, `powerpoint`, or `other` for anything else |
| `mimeType()` | `string` | MIME type string (e.g. `image/jpeg`) |
| `localPath(string $size)` | `string` | Absolute local filesystem path to the given size variant |
| `showFileName()` | `?string` | The raw `base_name` that was passed to `show()` |

```php
$doc = documan('my_disk')->show($user->avatar);

$ext  = $doc->getExtension();   // 'jpg'
$type = $doc->getType();        // 'image'
$mime = $doc->mimeType();       // 'image/jpeg'
$path = $doc->localPath('medium'); // '/var/www/storage/app/my_disk/medium_AbCdEfGhIj.jpg'
```

---

## Deleting a File

`delete()` removes the original **and** all resized variants. It respects the `delete.mode` setting in `config/documan.php`:

- **`hard`** *(default)* — files are permanently removed from disk.
- **`soft`** — files are moved to a configurable trash folder on the same disk, allowing recovery before a final hard purge.

```php
// Hard delete (permanent) — uses the config default
documan('my_disk')->delete($user->avatar);

// Soft delete — override mode at runtime
config(['documan.delete.mode' => 'soft']);
documan('my_disk')->delete($user->avatar);
// Files are moved to: {disk_root}/trash/{filename}

// Delete multiple files at once
documan('my_disk')->delete([$user->avatar, $user->cover_photo]);
```

Both the current (un-prefixed) and the legacy (`original_`-prefixed) originals are handled automatically, along with the `.webp` copies made when `outputWebp` is on and the `custom` size.
If an upload used runtime-defined sizes under other names, include those names so their variants are deleted too:

```php
documan('my_disk')->delete($user->avatar, ['large', 'icon']);
```

---

## Available Default Sizes

| Size      | Width | Height | Method      |
|-----------|-------|--------|-------------|
| big       | 1600  | 1600   | `->big()`   |
| medium    | 800   | 800    | `->medium()`|
| thumbnail | 400   | 400    | `->thumbnail()` |
| small     | 170   | 170    | `->small()` |
| tiny      | 50    | 50     | `->tiny()`  |

All sizes can be overridden in `config/documan.php` under `defaultImageSizes`, or per-call by passing a `['width' => x, 'height' => y]` array to the named method:

```php
->medium(['width' => 600, 'height' => 400])
```

A size is a box the image is fitted **inside**. The picture keeps its shape and is never enlarged: a 1600×900 photo asked for at `medium` (800×800) is stored at 800×450, and a 300×200 image stays 300×200. Photos carrying a rotation note from a phone camera are turned upright first.

For a fully ad-hoc size, use `custom()`:

```php
// Upload mode: adds a 'custom' size variant
->custom(280, 400)

// Show mode: retrieves the variant named 'large'
->custom('large')
```

---

## Validation Rule

Use the `DocumanFile` rule to validate uploaded files against Documan's supported MIME groups before uploading:

```php
use Tekkenking\Documan\Rules\DocumanFile;

// Allow any Documan-supported type
'file'   => ['required', new DocumanFile()],

// Restrict to a single group
'avatar' => ['required', new DocumanFile('image')],

// Restrict to multiple groups
'report' => ['required', new DocumanFile(['pdf', 'document'])],
```

Supported groups: `image`, `excel`, `document`, `powerpoint`, `pdf`.

---

## Using the Cast

Cast an Eloquent column to `DocumanCast` to get automatic upload-on-save and URL-resolution-on-read:

```php
use Tekkenking\Documan\DocumanCast;

protected $casts = [
    // Basic — disk only
    'avatar' => DocumanCast::class.':my_disk',

    // With size variants
    'avatar' => DocumanCast::class.':my_disk:small|thumbnail|medium',

    // CDN host (resolves URLs against the configured remote host)
    'avatar' => DocumanCast::class.':remote_my_disk',
];
```

**Reading** — the attribute returns a `Documan` instance; chain a size method to get the URL:

```php
echo $user->avatar->medium();
echo $user->avatar->thumbnail();

{{ $user->avatar->small }}   {{-- the same, as a property --}}
```

In `toArray()` and `toJson()` the attribute is the stored file name, as it is in the database.

**Writing** — pass the HTML input name and Documan uploads automatically:

```php
$user->avatar = 'avatar'; // input name in the current request
$user->save();            // upload happens here; base_name is stored in the DB
```

---

## Serving Through a CDN

This is about the **URL** a file is shown at, not where it is stored. Use `remoteDisk()` to build display URLs against another host, such as a CDN in front of your storage. To store files on S3, see [Storing on S3 and S3-Compatible Disks](#storing-on-s3-and-s3-compatible-disks).

```php
// Set in config/documan.php:
// 'remote' => ['host_url' => 'https://cdn.example.com', 'disk' => 'my_disk'],

$url = documan()
    ->remoteDisk()              // uses host_url + disk from config
    ->show($user->avatar)
    ->medium();

// Override at runtime
$url = documan()
    ->remoteDisk('my_disk', 'https://cdn.example.com')
    ->show($user->avatar)
    ->medium();
```

---

## Return Format

### Links and Paths

By default, `upload()` includes a `links` key with full URLs for every variant. You can also request absolute local filesystem paths via the `paths` key. Both can be toggled in config or at runtime:

```php
// Fluent override
$result = documan('my_disk')
    ->returnWithLinks(true)
    ->returnWithPaths(true)
    ->medium()
    ->upload($request, 'photo');

// $result['links']['medium'] => 'https://...my_disk/medium_AbCdEfGhIj.jpg'
// $result['paths']['medium'] => '/var/www/storage/app/my_disk/medium_AbCdEfGhIj.jpg'
```

### Array vs Collection

By default `upload()` returns a plain PHP array. Set `defaultReturn` to `'Collection'` in `config/documan.php` to receive an object instead: a `DocumanSingle` for one file, a `DocumanCollections` for several.

---

## S3 / Cloud File Visibility

By default, files uploaded to cloud disks (such as S3) are stored as **public**, meaning they can be accessed directly via their URL. This is controlled by the `s3_visibility` config key.

### Config default

```php
// config/documan.php
's3_visibility' => 'public',   // 'public' | 'private'
```

Set it to `'private'` to make all uploads private by default (useful when you serve files via signed URLs or a CDN).

### Runtime override

Use the `->visibility()` method to change visibility for a single upload without touching the config:

```php
// Upload as private for this call only
$result = documan('my_disk')
    ->visibility('private')
    ->medium()
    ->upload($request, 'document');

// Upload as public (explicit, even if config is set to private)
$result = documan('my_disk')
    ->visibility('public')
    ->upload($request, 'avatar');
```

The `->visibility()` call is chainable and can be placed anywhere before `->upload()`.

> **Note:** Visibility is passed as an option to every `Storage::disk()->put()` call, including resized variants and WebP copies. For local disks the option is silently ignored by Laravel, so it is safe to set it globally even in mixed-disk setups.

---

## Storing on S3 and S3-Compatible Disks

Documan fully supports S3-compatible remote disks. Image resizing, uploads, and display URLs all work the same way as with a local disk — you only need to point `disk` at a disk configured with the `s3` driver.

Documan does not define or control disk configuration itself — it simply uses whatever disk you've configured in your app's `config/filesystems.php`, exactly as Laravel resolves it via `Storage::disk()`. Configure your `s3` disk following [Laravel's own filesystem documentation](https://laravel.com/docs/filesystem#s3-driver-configuration), then reference it by name:

```php
$result = documan('s3')
    ->medium()
    ->small()
    ->upload($request, 'avatar');
```

### How image resize behaves with a remote disk

- The uploaded file itself is always read from PHP's local temp upload location — no change there.
- The **original** and every resized **variant** are written straight to the remote disk via `Storage::disk($disk)->put(...)`, the same code path used for local disks.
- If Documan ever needs to re-read an *already stored* remote object for processing (for example when resizing from a stored file name rather than a fresh upload), the object is streamed from the disk into a secure local temp file (`tempnam()`), processed, and the temp file is deleted immediately afterwards in a `finally` block — it is never left behind.
- Nothing assumes a local `root` path exists for the disk; disk config without a `root` key (as is the case for `s3`) works out of the box.

### Public vs private objects and URL retrieval

- For **public** objects (`s3_visibility` / `->visibility('public')`), display URLs are generated with `Storage::disk($disk)->url($path)`, which returns the disk's configured public URL — `Storage::path()` is never used for remote disks since it has no meaning there.
- For **private** objects, `show()` returns a signed temporary URL from `Storage::disk($disk)->temporaryUrl()`. It lasts `s3_url_expiry` minutes (1440, one day, by default). If the driver cannot sign a URL, a warning is logged and the plain URL is returned. Visibility is read from `s3_visibility`, or from `->visibility('private')` on the same call: `documan('s3')->visibility('private')->show($file)->medium()`.
- The `links` that `upload()` returns for a private file are signed the same way, so they work straight away and stop working when they expire. Store `base_name`, not a link, and call `show()` when you need a fresh URL.
- `show()`/`localPath()` and the `paths` key in `upload()`'s return value are local-disk concepts. On a remote disk, `localPath()` (the read-side helper) falls back to the disk URL instead of a filesystem path, while the upload-side `paths` key stays `null` (there is no local root to report) — use the `links` key for the public URL instead.

---

## Async / Queue Processing

Set `queue.enabled = true` in `config/documan.php` to dispatch each resized variant as a background job. The original is always stored synchronously first so the queue job has a source image to read from.

```php
'queue' => [
    'enabled'    => true,
    'connection' => null,   // null = default QUEUE_CONNECTION
    'name'       => null,   // null = default queue name
],
```

---

## External Adapter

Documan supports plugging in a third-party upload/show adapter (e.g. a cloud AI service). Enable and configure it in `config/documan.php`:

```php
'externalAdapter' => [
    'enabled' => true,
    'adapter' => [
        'upload' => \App\Documan\MyUploadAdapter::class,
        'show'   => \App\Documan\MyShowAdapter::class,
    ],
],
```

Upload adapters must implement `externalUpload($file): array`.  
Show adapters must implement `externalShow($fileName, $size): string`.

When enabled, `upload()` and `show()` are fully delegated to your adapter classes; the built-in storage logic is bypassed.

---

## Global Helper Functions

| Function | Description |
|---|---|
| `documan(string $disk = '')` | Resolve a fresh `Documan` instance from the container |
| `convertImageToBase64($documanInstance, string $size = 'original')` | Read a local file via `localPath()` in chunks and return its Base64-encoded content; remote URLs are not fetched |

```php
$b64 = convertImageToBase64(documan('my_disk')->show($user->avatar), 'medium');
```

---

## Full Configuration Reference

```php
// config/documan.php

return [

    // Default filesystem disk (overridden by passing a disk name to documan())
    'disk' => '',

    // Visibility for files uploaded to cloud disks (e.g. S3).
    // 'public'  — files are publicly readable (default).
    // 'private' — files require signed URLs / server-side auth.
    // Override per-upload with ->visibility('private').
    's3_visibility' => 'public',

    // How long a signed URL for a private file lasts, in minutes
    's3_url_expiry' => 1440,

    // Remote CDN / host configuration
    'remote' => [
        'host_url' => '',   // e.g. 'https://cdn.example.com'
        'disk'     => '',   // disk name appended as a URL segment
    ],

    // Plug in a custom upload/show adapter (see External Adapter section)
    'externalAdapter' => [
        'enabled' => false,
        'adapter' => [
            'upload' => \Tekkenking\Documan\ExternalProviders\TinyPeexi\UploadAdapter::class,
            'show'   => \Tekkenking\Documan\ExternalProviders\TinyPeexi\ShowAdapter::class,
        ],
    ],

    // Queue processing — resize variants are dispatched as background jobs
    'queue' => [
        'enabled'    => false,
        'connection' => null,   // null = default QUEUE_CONNECTION
        'name'       => null,   // null = default queue name
    ],

    // JPEG/WebP output quality 1–100 (PNG compression is scaled from this value)
    'imageQuality' => 90,

    // When true, a .webp copy is saved alongside every resized image variant
    'outputWebp' => false,

    // Resource limits; set any limit to 0 to disable it
    'maxUploadSizeBytes' => 20971520,
    'maxTotalUploadSizeBytes' => 41943040,
    'maxFilesPerUpload' => 20,
    'maxVariantsPerUpload' => 20,
    'maxImagePixels' => 40000000,

    // Delete behaviour
    'delete' => [
        'mode'         => 'hard',   // 'hard' (permanent) | 'soft' (move to trash folder)
        'trash_folder' => 'trash',  // folder name relative to disk root (soft mode only)
    ],

    // Built-in image sizes — dimensions can be changed; new sizes can be added
    'defaultImageSizes' => [
        'big'       => ['width' => 1600, 'height' => 1600],
        'medium'    => ['width' => 800,  'height' => 800],
        'thumbnail' => ['width' => 400,  'height' => 400],
        'small'     => ['width' => 170,  'height' => 170],
        'tiny'      => ['width' => 50,   'height' => 50],
    ],

    // Sizes to generate on every upload even when no size method is called
    'uploadDefaultImageSizes' => [
        // 'medium',
    ],

    // Allowed file types by group (validated against actual MIME type, not extension)
    'allowedFileExtensions' => [
        'image'       => ['jpg', 'png', 'jpeg', 'gif'],
        'excel'       => ['xlsx', 'xls', 'csv'],
        'document'    => ['doc', 'docx'],
        'powerpoint'  => ['ppt', 'pptx'],
        'pdf'         => ['pdf'],
    ],

    // On a remote disk, check a size exists before linking to it (one request per image)
    'verifyVariantExists' => true,

    // Include links (full URLs) and/or paths (absolute local paths) in upload result
    'returnUploadWith' => [
        'links' => true,
        'paths' => false,
    ],

    // 'array' returns a plain PHP array; 'Collection' returns a DocumanSingle or DocumanCollections object
    'defaultReturn' => 'array',
];
```

---

## Upgrading to 1.0

Coming from 0.8.x, three things change what you see:

- **Sizes fit inside the box.** On a server without Imagick, an image used to be stretched to the exact width and height. On every server it now keeps its shape and is never enlarged, so a 1600×900 photo at `medium` is 800×450, not 800×800. Variants already on disk are left as they are; re-upload or `move()` a file to regenerate them.
- **The stored extension follows the file's contents.** A PNG uploaded as `photo.jpg` is stored as `.png`.
- **Laravel 12 or 13 and PHP 8.2 are required.** Applications on Laravel 10 or 11 stay on 0.9.0.

Every change is listed in the [changelog](CHANGELOG.md).

## Migration from Earlier Versions

| What changed | Old behaviour | New behaviour |
|---|---|---|
| Original file name on disk | `original_AbCdEfGhIj.jpg` | `AbCdEfGhIj.jpg` (plain `base_name`) |
| Original storage | Optional (`keepOriginalSize` config) | Always stored (mandatory) |
| `keepOriginalSize` config key | Present | **Removed** — replace with the `delete` block |
| Delete | Permanent only | `hard` (permanent) or `soft` (move to trash) |

**No action is needed for existing files.** Documan reads and deletes both the old `original_`-prefixed files and the new un-prefixed originals automatically.

---

## Test Status

Beyond the automated tests, 1.0.2 was run end to end in a fresh Laravel 13 app, with every returned URL fetched over HTTP:

- **Local disk** — every example in this README.
- **S3 storage, private files** — on a real Cloudflare R2 bucket and on a local [MinIO](https://min.io) server: uploads, variants, WebP copies, signed links from `upload()` and `show()`, `move()` between disks, the cast, and hard and soft delete.
- **S3 storage, public files** — on the same R2 bucket, served from its public address set as the disk's `url`, and on MinIO: uploads, variants, WebP copies, a PDF, plain links from `upload()` and `show()`, the cast, and a deleted file's link going dead.
- **Queues** — a separate `queue:work` process on the database queue and on Redis, writing to a local disk and to the S3 bucket.

Amazon S3 itself has not been run; R2 and MinIO speak the same S3 API through the same Laravel driver. If you find a difference on AWS, please report it.

---

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
