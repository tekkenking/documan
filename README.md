# Documan Laravel file upload and display manager

Documan is a Laravel file upload manager package that provides a more convenient way to upload, files and especially allows you to create multiple sizes of the same image and display them in the size of your choice, similar to cloudinary. This works perfectly with your file storage config.

## Installation

To install the Swissecho package, simply require it via Composer:

```bash
composer require tekkenking/documan
```

## Laravel Version Compatibility

For Laravel 5.5 and above, the package should be automatically discovered.
For Laravel versions below 5.5, you may need to add the service provider to your config/app.php file:

```php
'providers' => [
    // ...
    Tekkenking\Documan\DocumanServiceProvider::class,
],
```

## Basic Usage

The following are different ways to use the Documan package:



Feel free to customize the examples based on your specific use case and requirements.

## License

This package is open-sourced software licensed under the MIT license.
