<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| convertImageToBase64() helper — unit tests
|--------------------------------------------------------------------------
*/

it('encodes a plain string file path', function () {
    $path = tempnam(sys_get_temp_dir(), 'documan');
    file_put_contents($path, 'hello world');

    $result = convertImageToBase64($path);

    expect($result)->toBe(base64_encode('hello world'));

    unlink($path);
});

it('uses localPath() when an object with that method is provided', function () {
    $path = tempnam(sys_get_temp_dir(), 'documan');
    file_put_contents($path, 'object content');

    $imagePath = new class($path) {
        public function __construct(private string $path) {}

        public function localPath(string $size = 'original'): string
        {
            return $this->path;
        }
    };

    $result = convertImageToBase64($imagePath, 'medium');

    expect($result)->toBe(base64_encode('object content'));

    unlink($path);
});

it('returns an empty string instead of crashing for an unreadable path', function () {
    $result = convertImageToBase64('/non/existent/path/to/file.jpg');

    expect($result)->toBe('');
});
