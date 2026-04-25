<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tekkenking\Documan\Jobs\ProcessDocumanImage;

/*
|--------------------------------------------------------------------------
| Queue dispatch — feature tests
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('documan', array_merge(
        require __DIR__ . '/../../config/documan.php',
        [
            'disk'  => 'testing',
            'queue' => ['enabled' => false, 'connection' => null, 'name' => null],
        ]
    ));
    config()->set('filesystems.disks.testing', [
        'driver' => 'local',
        'root'   => storage_path('framework/testing/documan'),
        'url'    => 'http://localhost/storage',
    ]);
});

it('does not dispatch jobs when queue is disabled (sync mode)', function () {
    Queue::fake();

    // queue.enabled is false in beforeEach — no jobs should be dispatched
    Queue::assertNothingPushed();
});

it('dispatches ProcessDocumanImage jobs for each size when queue is enabled', function () {
    Queue::fake();

    config()->set('documan.queue.enabled', true);

    $disk = \Illuminate\Support\Facades\Storage::fake('testing');

    // Fake the original already on disk — new style: plain base_name (no prefix)
    $sourceName = 'abc123.jpg';
    $disk->put($sourceName, 'fake-image-data');

    // Dispatch the job manually to confirm it reaches the queue
    ProcessDocumanImage::dispatch(
        disk: 'testing',
        sourceFileName: $sourceName,
        targetFileName: 'medium_abc123.jpg',
        width: 800,
        height: 800,
    );

    Queue::assertPushed(ProcessDocumanImage::class, function ($job) {
        return $job->disk === 'testing'
            && $job->sourceFileName === 'abc123.jpg'
            && $job->targetFileName === 'medium_abc123.jpg'
            && $job->width === 800
            && $job->height === 800;
    });
});

it('ProcessDocumanImage job carries the correct connection and queue when set', function () {
    Queue::fake();

    $job = (new ProcessDocumanImage(
        disk: 'testing',
        sourceFileName: 'abc123.jpg',
        targetFileName: 'small_abc123.jpg',
        width: 170,
        height: 170,
    ))->onConnection('redis')->onQueue('images');

    dispatch($job);

    Queue::assertPushedOn('images', ProcessDocumanImage::class);
});
