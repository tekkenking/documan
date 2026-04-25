<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageResizer
{
    protected bool $useImagick;

    public function __construct(protected string $disk = 'public')
    {
        $this->useImagick = extension_loaded('imagick');
    }

    public function setDisk(string $disk): self
    {
        $this->disk = $disk;
        return $this;
    }

    /**
     * Resize an UploadedFile and store the result on the configured disk.
     */
    public function resizeAndPreserveExif(
        UploadedFile $file,
        string $fileNameWithPath,
        int $width = 800,
        ?int $height = null,
        string $watermarkPath = ''
    ): string|false {
        return $this->resizeFromPath($file->getRealPath(), $fileNameWithPath, $width, $height, $watermarkPath);
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
        // Download to a system temp file so both local and cloud disks are supported
        $tmpPath = tempnam(sys_get_temp_dir(), 'documan_');
        file_put_contents($tmpPath, Storage::disk($this->disk)->get($sourceFileName));

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

        if (!$imagick->valid()) {
            throw new \Exception('Invalid image file');
        }

        if (!$imagick->getImageWidth() || !$imagick->getImageHeight()) {
            throw new \Exception('Invalid image dimensions');
        }

        $originalWidth = $imagick->getImageWidth();
        $originalHeight = $imagick->getImageHeight();

        // If image is already smaller, don't upscale
        $width = min($width, $originalWidth);

        // Prevent zero-dimension crash
        $resizeHeight = $height ?? intval($originalHeight * ($width / $originalWidth));

        // Final safety check
        if (!$resizeHeight || !$width) {
            throw new \Exception("Cannot resize image with invalid dimensions: width=$width, height=$resizeHeight");
        }

        $imagick->autoOrient();

        $imagick->resizeImage(
            $width,
            $resizeHeight,
            \Imagick::FILTER_LANCZOS,
            1,
            true
        );

        if ($watermarkPath && file_exists($watermarkPath)) {
            $this->addWatermarkImagick($imagick, $watermarkPath);
        }

        $quality = (int) config('documan.imageQuality', 90);
        $imagick->setImageCompressionQuality($quality);
        $imageContent = $imagick->getImageBlob();

        Storage::disk($this->disk)->put($fileNameWithPath, $imageContent);

        if (config('documan.outputWebp', false)) {
            $webpPath = preg_replace('/\.\w+$/', '.webp', $fileNameWithPath);
            $webpContent = $this->convertToWebp($imageContent);
            if ($webpContent) {
                Storage::disk($this->disk)->put($webpPath, $webpContent);
            }
        }

        $imagick->clear();
        $imagick->destroy();

        return $fileNameWithPath;
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

        // If image is already smaller, don't upscale
        $width = min($width, $originalWidth);
        $resizeHeight = $height ?? intval($originalHeight * ($width / $originalWidth));

        // Create image resource
        $srcImage = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($srcPath),
            IMAGETYPE_PNG  => imagecreatefrompng($srcPath),
            IMAGETYPE_GIF  => imagecreatefromgif($srcPath),
            default        => throw new \Exception('Unsupported image type.')
        };

        $dstImage = imagecreatetruecolor($width, $resizeHeight);

        // Preserve PNG transparency
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($dstImage, false);
            imagesavealpha($dstImage, true);
            $transparent = imagecolorallocatealpha($dstImage, 0, 0, 0, 127);
            imagefill($dstImage, 0, 0, $transparent);
        }

        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $width, $resizeHeight, $originalWidth, $originalHeight);

        // Add watermark if present
        if ($watermarkPath && file_exists($watermarkPath)) {
            $watermark = imagecreatefrompng($watermarkPath);
            $wmWidth = imagesx($watermark);
            $wmHeight = imagesy($watermark);

            imagecopy($dstImage, $watermark, $width - $wmWidth - 20, $resizeHeight - $wmHeight - 20, 0, 0, $wmWidth, $wmHeight);
            imagedestroy($watermark);
        }

        $quality = (int) config('documan.imageQuality', 90);

        ob_start();
        match ($type) {
            IMAGETYPE_PNG => imagepng($dstImage, null, (int) round((100 - $quality) / 10)),
            IMAGETYPE_GIF => imagegif($dstImage),
            default       => imagejpeg($dstImage, null, $quality),
        };
        $imageContent = ob_get_clean();

        imagedestroy($srcImage);
        imagedestroy($dstImage);

        Storage::disk($this->disk)->put($fileNameWithPath, $imageContent);

        if (config('documan.outputWebp', false)) {
            $webpPath = preg_replace('/\.\w+$/', '.webp', $fileNameWithPath);
            $webpContent = $this->convertToWebp($imageContent);
            if ($webpContent) {
                Storage::disk($this->disk)->put($webpPath, $webpContent);
            }
        }

        return $fileNameWithPath;
    }

    protected function convertToWebp(string $imageContent): string|false
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            return false;
        }

        $src = imagecreatefromstring($imageContent);
        if (!$src) {
            return false;
        }

        ob_start();
        imagewebp($src, null, 85); // Adjust quality as needed
        $webp = ob_get_clean();
        imagedestroy($src);

        return $webp;
    }

}
