<?php

declare(strict_types=1);

namespace Tekkenking\Documan\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Tekkenking\Documan\ImageResizer;

/**
 * Processes a single image resize variant asynchronously.
 *
 * Dispatched by Documan when `queue.enabled = true` in the config.
 * The original file must already be stored on the disk (as the plain base_name,
 * e.g. abc123.jpg) before this job runs.
 *
 * Usage (automatic when queue is enabled):
 *
 *   documan('photos')
 *       ->medium()
 *       ->small()
 *       ->upload($request, 'avatar');
 *
 * The original file is saved synchronously; each size variant is dispatched
 * as a separate job and processed by your queue worker.
 */
class ProcessDocumanImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * @param string   $disk            The Laravel storage disk name
     * @param string   $sourceFileName  The original file already stored on the disk as its plain
     *                                  base_name (e.g. abc123.jpg). This is the file that was
     *                                  saved synchronously before the job was dispatched.
     * @param string   $targetFileName  The output file to write (e.g. medium_abc123.jpg)
     * @param int      $width           Target width in pixels
     * @param int|null $height          Target height (null = preserve aspect ratio)
     */
    public function __construct(
        public readonly string $disk,
        public readonly string $sourceFileName,
        public readonly string $targetFileName,
        public readonly int $width,
        public readonly ?int $height = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $resizer = new ImageResizer($this->disk);
        $resizer->resizeFromStoredFile(
            $this->sourceFileName,
            $this->targetFileName,
            $this->width,
            $this->height,
        );
    }
}
