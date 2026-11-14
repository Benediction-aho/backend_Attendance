<?php

namespace App\Services;

use App\Exceptions\ApiError;
use GdImage;

/**
 * Re-encodes uploaded images (P1-13): only the decoded pixels are kept, so EXIF metadata (GPS position,
 * camera) and any bytes appended to the file (polyglots) are dropped. The image is also turned upright
 * (EXIF orientation) and scaled down to the configured maximum size.
 */
class ImageSanitizer
{
    /** @return string the re-encoded image bytes, in the same format (jpg, png or webp) */
    public static function reencode(string $sourcePath, string $extension, int $maxDimension): string
    {
        $data = @file_get_contents($sourcePath);
        $image = $data !== false ? @imagecreatefromstring($data) : false;
        if (! $image instanceof GdImage) {
            throw new ApiError(422, 'The photo could not be read. Please use a JPEG, PNG or WebP image.', 'invalid_image');
        }

        if ($extension === 'jpg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourcePath);
            $rotated = match (is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => $image,
            };
            $image = $rotated instanceof GdImage ? $rotated : $image;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxDimension / max($width, $height));
        if ($scale < 1) {
            $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);
            $image = $scaled instanceof GdImage ? $scaled : $image;
        }
        if ($extension !== 'jpg') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();
        $ok = match ($extension) {
            'png' => imagepng($image, null, 6),
            'webp' => imagewebp($image, null, 85),
            default => imagejpeg($image, null, 85),
        };
        $bytes = (string) ob_get_clean();
        if (! $ok || $bytes === '') {
            throw new ApiError(422, 'The photo could not be read. Please use a JPEG, PNG or WebP image.', 'invalid_image');
        }

        return $bytes;
    }
}
