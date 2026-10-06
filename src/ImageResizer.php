<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageResizer
{
    protected bool $useImagick;

    public function __construct(protected string $disk = 'public', protected string $visibility = 'public')
    {
        $this->useImagick = extension_loaded('imagick');
    }

    public function setDisk(string $disk): self
    {
        $this->disk = $disk;
        return $this;
    }

    public function setVisibility(string $visibility): self
    {
        $this->visibility = $visibility;
        return $this;
    }

    /**
     * Resize an UploadedFile and store the result on the configured disk.
     */
    public function resizeAndPreserveExif(
        UploadedFile|string $file,
        string $fileNameWithPath,
        int $width = 800,
        ?int $height = null,
        string $watermarkPath = ''
    ): string|false {
        if ($file instanceof UploadedFile) {
            $realPath = $file->getRealPath();
            if ($realPath === false) {
                throw new \RuntimeException('Could not determine the real path of the uploaded file.');
            }
            $sourcePath = $realPath;
        } else {
            $sourcePath = $file;
        }

        return $this->resizeFromPath($sourcePath, $fileNameWithPath, $width, $height, $watermarkPath);
    }

    /**
     * Resize a file that is already stored on the configured disk.
     *
     * The source file is streamed to a local temp location, resized, and the
     * result is written back to the same disk as $targetFileName.
     */
    public function resizeFromStoredFile(
        string $sourceFileName,
        string $targetFileName,
        int $width = 800,
        ?int $height = null,
        string $watermarkPath = ''
    ): string|false {
        $tmpPath = $this->downloadStoredFileToTempPath($sourceFileName);

        try {
            return $this->resizeFromPath($tmpPath, $targetFileName, $width, $height, $watermarkPath);
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * Core resize pipeline — operates on a local filesystem path.
     */
    protected function resizeFromPath(
        string $srcPath,
        string $fileNameWithPath,
        int $width,
        ?int $height,
        string $watermarkPath
    ): string|false {
        $this->validateResizeDimensions($srcPath, $width, $height);

        try {
            if ($this->useImagick) {
                return $this->processWithImagick($srcPath, $fileNameWithPath, $width, $height, $watermarkPath);
            }

            return $this->processWithGD($srcPath, $fileNameWithPath, $width, $height, $watermarkPath);
        } catch (\Exception $e) {
            logger()->error('Image processing failed: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function processWithImagick(string $srcPath, string $fileNameWithPath, int $width, ?int $height, string $watermarkPath): string
    {
        $imagick = new \Imagick($srcPath);

        try {
            if (!$imagick->valid()) {
                throw new \Exception('Invalid image file');
            }

            if (!$imagick->getImageWidth() || !$imagick->getImageHeight()) {
                throw new \Exception('Invalid image dimensions');
            }

            $originalWidth = $imagick->getImageWidth();
            $originalHeight = $imagick->getImageHeight();
            $width = min($width, $originalWidth);
            $resizeHeight = $height ?? intval($originalHeight * ($width / $originalWidth));

            if (!$resizeHeight || !$width) {
                throw new \Exception("Cannot resize image with invalid dimensions: width=$width, height=$resizeHeight");
            }

            $imagick->autoOrient();
            $imagick->resizeImage($width, $resizeHeight, \Imagick::FILTER_LANCZOS, 1, true);

            if ($watermarkPath && file_exists($watermarkPath)) {
                $this->addWatermarkImagick($imagick, $watermarkPath);
            }

            $quality = (int) config('documan.imageQuality', 90);
            $imagick->setImageCompressionQuality($quality);

            $primaryTmp = $this->createImageTempFile($fileNameWithPath);

            try {
                $imagick->writeImage($primaryTmp);
                $this->putTempFile($primaryTmp, $fileNameWithPath);

                if (config('documan.outputWebp', false)) {
                    $webpPath = preg_replace('/\.\w+$/', '.webp', $fileNameWithPath);
                    if ($webpPath !== null) {
                        $webp = clone $imagick;
                        try {
                            $webp->setImageFormat('webp');
                            $webp->setImageCompressionQuality($quality);
                            $webpTmp = $this->createImageTempFile($webpPath);
                            try {
                                $webp->writeImage($webpTmp);
                                $this->putTempFile($webpTmp, $webpPath);
                            } finally {
                                @unlink($webpTmp);
                            }
                        } finally {
                            $webp->clear();
                            $webp->destroy();
                        }
                    }
                }
            } finally {
                @unlink($primaryTmp);
            }

            return $fileNameWithPath;
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    protected function addWatermarkImagick(\Imagick $imagick, string $watermarkPath): void
    {
        $watermark = new \Imagick($watermarkPath);
        $watermark->evaluateImage(\Imagick::EVALUATE_MULTIPLY, 0.5, \Imagick::CHANNEL_ALPHA);

        $imagick->compositeImage(
            $watermark,
            \Imagick::COMPOSITE_OVER,
            $imagick->getImageWidth() - $watermark->getImageWidth() - 20,
            $imagick->getImageHeight() - $watermark->getImageHeight() - 20
        );
    }

    protected function processWithGD(string $srcPath, string $fileNameWithPath, int $width, ?int $height, string $watermarkPath): string
    {
        [$originalWidth, $originalHeight, $type] = getimagesize($srcPath);

        if (!$originalWidth || !$originalHeight) {
            throw new \Exception('Invalid image dimensions.');
        }

        $width = min($width, $originalWidth);
        $resizeHeight = $height ?? intval($originalHeight * ($width / $originalWidth));

        $srcImage = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($srcPath),
            IMAGETYPE_PNG  => imagecreatefrompng($srcPath),
            IMAGETYPE_GIF  => imagecreatefromgif($srcPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp')
                ? imagecreatefromwebp($srcPath)
                : throw new \Exception('WebP images are not supported by this GD installation.'),
            default        => throw new \Exception('Unsupported image type.')
        };

        $dstImage = imagecreatetruecolor($width, $resizeHeight);

        try {
            if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
                imagealphablending($dstImage, false);
                imagesavealpha($dstImage, true);
                $transparent = imagecolorallocatealpha($dstImage, 0, 0, 0, 127);
                imagefill($dstImage, 0, 0, $transparent);
            }

            imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $width, $resizeHeight, $originalWidth, $originalHeight);

            if ($watermarkPath && file_exists($watermarkPath)) {
                $watermark = @imagecreatefrompng($watermarkPath);

                if ($watermark === false) {
                    throw new DocumanException('Failed to load watermark PNG image: ' . $watermarkPath);
                }

                try {
                    $wmWidth = imagesx($watermark);
                    $wmHeight = imagesy($watermark);
                    imagecopy($dstImage, $watermark, $width - $wmWidth - 20, $resizeHeight - $wmHeight - 20, 0, 0, $wmWidth, $wmHeight);
                } finally {
                    imagedestroy($watermark);
                }
            }

            $quality = (int) config('documan.imageQuality', 90);
            $primaryTmp = $this->createImageTempFile($fileNameWithPath);

            try {
                $this->writeGdImageToPath($dstImage, $type, $primaryTmp, $quality);
                $this->putTempFile($primaryTmp, $fileNameWithPath);

                if (config('documan.outputWebp', false) && function_exists('imagewebp')) {
                    $webpPath = preg_replace('/\.\w+$/', '.webp', $fileNameWithPath);
                    if ($webpPath !== null) {
                        $webpTmp = $this->createImageTempFile($webpPath);
                        try {
                            imagewebp($dstImage, $webpTmp, 85);
                            $this->putTempFile($webpTmp, $webpPath);
                        } finally {
                            @unlink($webpTmp);
                        }
                    }
                }
            } finally {
                @unlink($primaryTmp);
            }

            return $fileNameWithPath;
        } finally {
            imagedestroy($srcImage);
            imagedestroy($dstImage);
        }
    }

    protected function downloadStoredFileToTempPath(string $sourceFileName): string
    {
        $stream = Storage::disk($this->disk)->readStream($sourceFileName);

        if ($stream === false) {
            throw new \Exception('Unable to read source image from disk.');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'documan_');
        if ($tmpPath === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new \Exception('Unable to create local temp file for image processing.');
        }
        $tmpHandle = fopen($tmpPath, 'wb');

        if ($tmpHandle === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            @unlink($tmpPath);
            throw new \Exception('Unable to create local temp file for image processing.');
        }

        try {
            if (stream_copy_to_stream($stream, $tmpHandle) === false) {
                throw new \Exception('Unable to copy source image to a local temp file.');
            }
        } finally {
            fclose($tmpHandle);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $tmpPath;
    }

    protected function createImageTempFile(string $fileNameWithPath): string
    {
        $extension = pathinfo($fileNameWithPath, PATHINFO_EXTENSION);
        $tmpPath = tempnam(sys_get_temp_dir(), 'documan_');

        if ($extension === '') {
            return $tmpPath;
        }

        $renamedPath = $tmpPath . '.' . $extension;
        if (!rename($tmpPath, $renamedPath)) {
            @unlink($tmpPath);
            throw new \RuntimeException('Unable to create image output temp file.');
        }

        return $renamedPath;
    }

    protected function validateResizeDimensions(string $sourcePath, int $width, ?int $height): void
    {
        if ($width <= 0 || ($height !== null && $height <= 0)) {
            throw new \InvalidArgumentException('Image resize dimensions must be positive.');
        }

        $dimensions = @getimagesize($sourcePath);
        if ($dimensions === false || $dimensions[0] <= 0 || $dimensions[1] <= 0) {
            throw new \Exception('Invalid image file or dimensions.');
        }

        $maxPixels = (int) config('documan.maxImagePixels', 40000000);
        if ($maxPixels <= 0) {
            return;
        }

        [$sourceWidth, $sourceHeight] = $dimensions;
        if ($sourceWidth > intdiv($maxPixels, $sourceHeight)) {
            throw new \Exception('Source image exceeds the configured maximum pixel count.');
        }

        $targetWidth = min($width, $sourceWidth);
        $targetHeight = $height ?? max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));
        if ($targetWidth > intdiv($maxPixels, $targetHeight)) {
            throw new \Exception('Resized image exceeds the configured maximum pixel count.');
        }
    }

    private function putTempFile(string $path, string $targetFileName): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open the processed image file.');
        }

        try {
            Storage::disk($this->disk)->put(
                $targetFileName,
                $stream,
                ['visibility' => $this->visibility]
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param mixed $image GD image resource (\GdImage when ext-gd is installed)
     */
    protected function writeGdImageToPath($image, int $type, string $path, int $quality): void
    {
        match ($type) {
            IMAGETYPE_PNG => imagepng($image, $path, (int) round((100 - $quality) / 10)),
            IMAGETYPE_GIF => imagegif($image, $path),
            IMAGETYPE_WEBP => imagewebp($image, $path, $quality),
            default       => imagejpeg($image, $path, $quality),
        };
    }

}
