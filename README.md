# Documan Laravel file upload and display manager

Documan is a Laravel file upload manager package that provides a more convenient way to upload files and especially allows you to create multiple sizes of the same image and display them in the size of your choice, similar to Cloudinary. This works perfectly with your file storage config.

## Requirements

- PHP 8.5+
- Laravel 13+

## Installation

```bash
composer require tekkenking/documan
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --provider="Tekkenking\Documan\DocumanServiceProvider"
```

This creates `config/documan.php` where you can set your default disk, allowed extensions, default image sizes, and more.

## Laravel Version Compatibility

For Laravel 5.5 and above, the package is automatically discovered.
For earlier versions, add the service provider to `config/app.php`:

```php
'providers' => [
    Tekkenking\Documan\DocumanServiceProvider::class,
],
```

## Basic Usage

### Uploading a File

The original file is **always** stored automatically — it is saved as the plain `base_name` on disk (no prefix). Resized variants are stored alongside it with their size prefix (e.g. `medium_AbCdEfGhIj.jpg`).

```php
// In your controller
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
    //   'base_name'  => 'AbCdEfGhIj.jpg',        // ← also the original on disk
    //   'variations' => [
    //     'original'  => 'AbCdEfGhIj.jpg',        // plain base_name, no prefix
    //     'small'     => 'small_AbCdEfGhIj.jpg',
    //     'thumbnail' => 'thumbnail_AbCdEfGhIj.jpg',
    //     'medium'    => 'medium_AbCdEfGhIj.jpg',
    //   ],
    //   'links' => [ ... ],  // if returnUploadWith.links = true in config
    // ]

    // Save base_name to database
    $user->avatar = $result['base_name'];
    $user->save();
}
```

> **Backward compatibility** — Images uploaded with an earlier version of Documan may have their original stored as `original_AbCdEfGhIj.jpg` (with the `original_` prefix). Documan handles both formats transparently when displaying and deleting files.

### Uploading with Custom Sizes

```php
$result = documan('my_disk')
    ->sizes([
        'large' => ['width' => 1200, 'height' => 900],
        'icon'  => ['width' => 32,   'height' => 32],
    ])
    ->upload($request, 'photo');
```

### Displaying / Showing a File

```php
// Get the medium-sized URL
$url = documan('my_disk')->show($user->avatar)->medium();

// Get the thumbnail URL
$url = documan('my_disk')->show($user->avatar)->thumbnail();

// Get the original (full-size)
$url = documan('my_disk')->show($user->avatar)->original();
```

### Deleting a File

`delete()` removes the original **and** all resized variants. It respects the `delete.mode` setting in `config/documan.php`:

- **`hard`** *(default)* — files are permanently removed from disk.
- **`soft`** — files are moved to a configurable trash folder on the same disk. They can be inspected or restored before a final hard purge.

```php
// Hard delete (permanent) — uses config default
documan('my_disk')->delete($user->avatar);

// Soft delete — override mode at runtime via config or use the config file
config(['documan.delete.mode' => 'soft']);
documan('my_disk')->delete($user->avatar);
// Files are moved to: {disk_root}/trash/{filename}
```

Both the current (un-prefixed) and the legacy (`original_`-prefixed) originals are accounted for automatically, so running `delete()` is safe regardless of when the file was originally uploaded.

Configure delete behaviour in `config/documan.php`:

```php
'delete' => [
    'mode'         => 'hard',   // 'hard' | 'soft'
    'trash_folder' => 'trash',  // relative path within disk root (soft mode only)
],
```

### Using the Cast

In your Eloquent model, cast a column to Documan so it auto-uploads on save and auto-resolves on read:

```php
use Tekkenking\Documan\DocumanCast;

protected $casts = [
    'avatar' => DocumanCast::class.':my_disk',
    // With sizes: DocumanCast::class.':my_disk:small|thumbnail|medium',
];
```

### Remote Disk

```php
// Set a remote host URL in config/documan.php:
// 'remote' => ['host_url' => 'https://cdn.example.com', 'disk' => ''],

$url = documan()
    ->remoteDisk()
    ->show($user->avatar)
    ->medium();
```

### Available Default Sizes

| Size      | Width | Height |
|-----------|-------|--------|
| big       | 1600  | 1600   |
| medium    | 800   | 800    |
| thumbnail | 400   | 400    |
| small     | 170   | 170    |
| tiny      | 50    | 50     |

These can be overridden in `config/documan.php` under `defaultImageSizes`.

### Async / Queue Processing

Set `queue.enabled = true` in `config/documan.php` to process resized variants in the background. The original is always stored synchronously first so the queue job has a source to read from.

```php
'queue' => [
    'enabled'    => true,
    'connection' => null,   // null = default queue connection
    'name'       => null,   // null = default queue name
],
```

## Migration from Earlier Versions

| What changed | Old behaviour | New behaviour |
|---|---|---|
| Original file name on disk | `original_AbCdEfGhIj.jpg` | `AbCdEfGhIj.jpg` (plain `base_name`) |
| Original storage | Optional (`keepOriginalSize` config) | Always stored (mandatory) |
| `keepOriginalSize` config key | Present | **Removed** — replace with `delete` block |
| Delete | Permanent only | `hard` (permanent) or `soft` (move to trash) |

**No action is needed for existing files.** Documan reads and deletes both the old `original_`-prefixed files and the new un-prefixed originals automatically.

## License

This package is open-sourced software licensed under the MIT license.
