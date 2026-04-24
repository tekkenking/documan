<?php

declare(strict_types=1);

use Tekkenking\Documan\Documan;
use Tekkenking\Documan\DocumanException;

/*
|--------------------------------------------------------------------------
| ImageSizes trait — unit tests
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('documan', require __DIR__ . '/../../config/documan.php');
    $this->documan = new Documan();
});

it('sizes() adds a known size to chosenSizes without mutating defaultSizes', function () {
    $originalMedium = config('documan.defaultImageSizes.medium');

    $this->documan->sizes(['medium' => ['width' => 100, 'height' => 100]]);

    // $defaultSizes on the instance should NOT be changed
    $reflection = new ReflectionProperty(Documan::class, 'defaultSizes');
    $reflection->setAccessible(true);
    $defaultSizes = $reflection->getValue($this->documan);

    expect($defaultSizes['medium'])->toBe($originalMedium);
});

it('sizes() merges chosen sizes from an indexed array', function () {
    $this->documan->sizes(['medium', 'small']);

    $reflection = new ReflectionProperty(Documan::class, 'chosenSizes');
    $reflection->setAccessible(true);
    $chosen = $reflection->getValue($this->documan);

    expect($chosen)->toHaveKeys(['medium', 'small']);
});

it('sizes() throws for an unknown indexed size', function () {
    $this->documan->sizes(['unknown_size']);
})->throws(DocumanException::class);

it('sizes() throws when associative value is not an array', function () {
    $this->documan->sizes(['medium' => 'bad_value']);
})->throws(DocumanException::class);

it('sizes() throws for a new custom size missing width or height', function () {
    $this->documan->sizes(['portrait' => ['width' => 300]]);
})->throws(DocumanException::class);

it('custom() via __call registers a custom size for upload', function () {
    $this->documan->custom(320, 240);

    $reflection = new ReflectionProperty(Documan::class, 'chosenSizes');
    $reflection->setAccessible(true);
    $chosen = $reflection->getValue($this->documan);

    expect($chosen['custom'])->toBe(['width' => 320, 'height' => 240]);
});

it('explicit size methods (medium/small/thumbnail) add to chosenSizes', function () {
    $this->documan->medium()->small()->thumbnail();

    $reflection = new ReflectionProperty(Documan::class, 'chosenSizes');
    $reflection->setAccessible(true);
    $chosen = $reflection->getValue($this->documan);

    expect($chosen)->toHaveKeys(['medium', 'small', 'thumbnail']);
});
