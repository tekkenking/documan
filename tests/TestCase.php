<?php

declare(strict_types=1);

namespace Tekkenking\Documan\Tests;

use Tekkenking\Documan\DocumanServiceProvider;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DocumanServiceProvider::class];
    }
}
