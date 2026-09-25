<?php

namespace App\Services\Processing;

use GdImage;

/**
 * Metadata and WebP variants with ext-gd (§12, §62). Works on bytes rather
 * than paths so the storage disk can later be non-local (§11). Never writes
 * back to the original.
 */
final class GdImageProcessor
{
    /**
     * Display dimensions: raw size with the EXIF orientation applied, so a
     * portrait photo stored sideways reports portrait width/height.
     *
     * @return array{width: int, height: int}
     *
     * @throws StageFailure METADATA_EXTRACTION_FAILED, not retryable.
     */
    public function inspect(string $contents): array
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new StageFailure(
                ProcessingState::ERROR_METADATA_EXTRACTION_FAILED,
                'Could not read image dimensions',
                retryable: false,
            );
        }

        [$width, $height] = [$info[0], $info[1]];

        if (in_array($this->exifOrientation($contents), [5, 6, 7, 8], true)) {
            [$width, $height] = [$height, $width];
        }

        return ['width' => $width, 'height' => $height];
    }

    /**
     * Decodes once and encodes each requested variant as WebP, scaled down to
     * fit its box (never upscaled), EXIF-oriented, alpha preserved.
     *
     * @param  array<string, array{max_width: int, max_height: int, quality: int}>  $variants
     * @return array<string, string> name => WebP bytes
     *
     * @throws StageFailure VARIANT_GENERATION_FAILED, not retryable.
     */
    public function webpVariants(string $contents, array $variants): array
    {
        if (! function_exists('imagewebp')) {
            throw new StageFailure(
                ProcessingState::ERROR_VARIANT_GENERATION_FAILED,
                'WebP encoding is not available',
                retryable: false,
            );
        }

        $source = @imagecreatefromstring($contents);
        if (! $source instanceof GdImage) {
            throw new StageFailure(
                ProcessingState::ERROR_VARIANT_GENERATION_FAILED,
                'Could not decode image',
                retryable: false,
            );
        }

        $source = $this->orient($source, $this->exifOrientation($contents));

        $result = [];
        foreach ($variants as $name => $spec) {
            $result[$name] = $this->encodeFitted($source, $spec['max_width'], $spec['max_height'], $spec['quality']);
        }

        return $result;
    }

    private function encodeFitted(GdImage $source, int $maxWidth, int $maxHeight, int $quality): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, $maxWidth / $width, $maxHeight / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $ok = imagewebp($target, null, $quality);
        $bytes = (string) ob_get_clean();

        if (! $ok || $bytes === '') {
            throw new StageFailure(
                ProcessingState::ERROR_VARIANT_GENERATION_FAILED,
                'Could not encode WebP variant',
                retryable: false,
            );
        }

        return $bytes;
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $flipped = in_array($orientation, [2, 4, 5, 7], true);
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270, // GD rotates counter-clockwise; 6 means "rotate 90° CW to display".
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, imagecolorallocatealpha($image, 0, 0, 0, 127));
            if ($rotated instanceof GdImage) {
                $image = $rotated;
            }
        }

        if ($flipped) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    private function exifOrientation(string $contents): int
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($contents, "\xFF\xD8")) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $exif = @exif_read_data($stream);
        } catch (\Throwable) {
            $exif = false;
        } finally {
            fclose($stream);
        }

        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }
}
