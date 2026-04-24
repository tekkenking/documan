<?php

declare(strict_types=1);

use Tekkenking\Documan\DocumanServiceProvider;

/*
|--------------------------------------------------------------------------
| Pest bootstrap — configure Orchestra Testbench as the base test case
|--------------------------------------------------------------------------
*/
uses(
    \Orchestra\Testbench\TestCase::class,
)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Register the Documan service provider for all tests
|--------------------------------------------------------------------------
*/
function getPackageProviders($app): array
{
    return [DocumanServiceProvider::class];
}
