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
    //   'base_name'  => 'AbCdEfGhIj.jpg',
    //   'variations' => [
    //     'original'  => 'original_AbCdEfGhIj.jpg',
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

// Get original
$url = documan('my_disk')->show($user->avatar)->original();
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

## License

This package is open-sourced software licensed under the MIT license.
