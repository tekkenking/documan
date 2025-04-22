<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageResizer
{
    protected string $disk = 'public';
    protected bool $useImagick = false;

    public function __construct(string $disk = '')
    {
        $this->useImagick = extension_loaded('imagick');

        if ($disk) {
            $this->setDisk($disk);
        }
    }

    public function setDisk(string $disk): self
    {
        $this->disk = $disk;
        return $this;
    }

    /**
     * Resize and store image with optional watermark.
     */
    public function resizeAndPreserveExif(
        UploadedFile $file,
        string $fileNameWithPath,
        int $width = 800,
        int $height = null,
        string $watermarkPath = ''
    ): string|false {
        try {
            if ($this->useImagick) {
                return $this->processWithImagick($file, $fileNameWithPath, $width, $height, $watermarkPath);
            }

            return $this->processWithGD($file, $fileNameWithPath, $width, $height, $watermarkPath);
        } catch (\Exception $e) {
            logger()->error('Image processing failed: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function processWithImagick(UploadedFile $file, string $fileNameWithPath, int $width, ?int $height, string $watermarkPath): string
    {
        $imagick = new \Imagick($file->getRealPath());

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

        $imagick->setImageCompressionQuality(90);
        $imageContent = $imagick->getImageBlob();

        Storage::disk($this->disk)->put($fileNameWithPath, $imageContent);

        /*// Also save WebP version
        $webpPath = preg_replace('/\.\w+$/', '.webp', $fileNameWithPath);
        $webpContent = $this->convertToWebp($imageContent);

        if ($webpContent) {
            Storage::disk($this->disk)->put($webpPath, $webpContent);
        }*/

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

    protected function processWithGD(UploadedFile $file, string $fileNameWithPath, int $width, ?int $height, string $watermarkPath): string
    {
        $srcPath = $file->getRealPath();
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

        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $width, $resizeHeight, $originalWidth, $originalHeight);

        // Add watermark if present
        if ($watermarkPath && file_exists($watermarkPath)) {
            $watermark = imagecreatefrompng($watermarkPath);
            $wmWidth = imagesx($watermark);
            $wmHeight = imagesy($watermark);

            imagecopy($dstImage, $watermark, $width - $wmWidth - 20, $resizeHeight - $wmHeight - 20, 0, 0, $wmWidth, $wmHeight);
            imagedestroy($watermark);
        }

        ob_start();
        imagejpeg($dstImage, null, 90);
        $imageContent = ob_get_clean();

        imagedestroy($srcImage);
        imagedestroy($dstImage);

        Storage::disk($this->disk)->put($fileNameWithPath, $imageContent);

        /*// Also save WebP version
        $webpPath = preg_replace('/\.\w+$/', '.webp', $fileNameWithPath);
        $webpContent = $this->convertToWebp($imageContent);

        if ($webpContent) {
            Storage::disk($this->disk)->put($webpPath, $webpContent);
        }*/

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
