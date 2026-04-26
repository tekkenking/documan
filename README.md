# Documan — Laravel File Upload & Display Manager

Documan is a Laravel file upload manager package that provides a convenient way to upload files and, for images, automatically creates multiple resized variants that can be retrieved by name — similar to Cloudinary but fully self-hosted. It works with any Laravel filesystem disk (local, S3, etc.).

## Requirements

- PHP ^8.1
- Laravel ^10.0 | ^11.0 | ^12.0 | ^13.0

## Installation

```bash
composer require tekkenking/documan
```

The package is auto-discovered by Laravel (5.5+). No manual provider registration is needed.

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

    // $result is an array:
    // [
    //   'fileType'   => 'image',
    //   'base_name'  => 'AbCdEfGhIj.jpg',        // ← the original on disk
    //   'variations' => [
    //     'original'  => 'AbCdEfGhIj.jpg',        // plain base_name, no prefix
    //     'small'     => 'small_AbCdEfGhIj.jpg',
    //     'thumbnail' => 'thumbnail_AbCdEfGhIj.jpg',
    //     'medium'    => 'medium_AbCdEfGhIj.jpg',
    //   ],
    //   'links' => [ ... ],  // present when returnUploadWith.links = true (default)
    // ]

    // Save base_name to the database
    $user->avatar = $result['base_name'];
    $user->save();
}
```

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

### Adding Extra Allowed Extensions

```php
$result = documan('my_disk')
    ->addExtension(['svg', 'webp'])
    ->upload($request, 'file');
```

---

## Displaying / Showing a File

Use `show($baseName)` to set the file, then chain a size method to get the URL:

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

### Show Helper Methods

After calling `show()`, the following helper methods are also available:

| Method | Returns | Description |
|---|---|---|
| `getExtension()` | `string` | File extension derived from the stored `base_name` |
| `getType()` | `string` | Group name (`image`, `pdf`, `document`, `excel`, `powerpoint`, `other`) |
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

Both the current (un-prefixed) and the legacy (`original_`-prefixed) originals are handled automatically.

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

    // Remote disk (resolves URLs against the configured remote host)
    'avatar' => DocumanCast::class.':remote_my_disk',
];
```

**Reading** — the attribute returns a `Documan` instance; chain a size method to get the URL:

```php
echo $user->avatar->medium();
echo $user->avatar->thumbnail();
```

**Writing** — pass the HTML input name and Documan uploads automatically:

```php
$user->avatar = 'avatar'; // input name in the current request
$user->save();            // upload happens here; base_name is stored in the DB
```

---

## Remote Disk

Use `remoteDisk()` to build URLs against a remote host (e.g. a CDN) instead of using the local filesystem URL:

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

By default `upload()` returns a plain PHP array. Set `defaultReturn` to `'Collection'` in `config/documan.php` to receive a `DocumanCollections` object instead.

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
| `convertImageToBase64($documanInstance, string $size = 'original')` | Read a file via `localPath()` and return its Base64-encoded content |

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
    'uploadDefaulImageSizes' => [
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

    // Include links (full URLs) and/or paths (absolute local paths) in upload result
    'returnUploadWith' => [
        'links' => true,
        'paths' => false,
    ],

    // 'array' returns a plain PHP array; 'Collection' returns a DocumanCollections object
    'defaultReturn' => 'array',
];
```

---

## Migration from Earlier Versions

| What changed | Old behaviour | New behaviour |
|---|---|---|
| Original file name on disk | `original_AbCdEfGhIj.jpg` | `AbCdEfGhIj.jpg` (plain `base_name`) |
| Original storage | Optional (`keepOriginalSize` config) | Always stored (mandatory) |
| `keepOriginalSize` config key | Present | **Removed** — replace with the `delete` block |
| Delete | Permanent only | `hard` (permanent) or `soft` (move to trash) |

**No action is needed for existing files.** Documan reads and deletes both the old `original_`-prefixed files and the new un-prefixed originals automatically.

---

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
