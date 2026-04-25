<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest bootstrap — configure Orchestra Testbench as the base test case
|--------------------------------------------------------------------------
*/
uses(
    \Tekkenking\Documan\Tests\TestCase::class,
)->in('Feature', 'Unit');
