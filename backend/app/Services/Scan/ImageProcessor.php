<?php

declare(strict_types=1);

namespace App\Services\Scan;

use App\Exceptions\ImageRejected;
use App\Support\ApiResponse;
use App\Support\Locale;
use GdImage;

/**
 * Validates magic bytes, rejects oversized frames, and re-encodes to JPEG
 * so EXIF/GPS never leave the process.
 */
final class ImageProcessor
{
    /**
     * @return array{bytes: string, mime: string, hash: string, width: int, height: int}
     */
    public function normalize(string $binary, string $lang = 'id'): array
    {
        $max = (int) config('sanggah.scan.max_bytes');
        if (strlen($binary) > $max) {
            throw new ImageRejected('FILE_TOO_LARGE', ApiResponse::message('FILE_TOO_LARGE', $lang), 413);
        }
        $mime = $this->mime($binary);
        if ($mime === null) {
            throw new ImageRejected('UNSUPPORTED_MEDIA_TYPE', ApiResponse::message('UNSUPPORTED_MEDIA_TYPE', $lang), 415);
        }
        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            throw new ImageRejected('UNSUPPORTED_MEDIA_TYPE', ApiResponse::message('UNSUPPORTED_MEDIA_TYPE', $lang), 415);
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        $edge = (int) config('sanggah.scan.max_edge');
        if ($width < 1 || $height < 1 || $width > $edge || $height > $edge || ($width * $height) > 20_000_000) {
            throw new ImageRejected('VALIDATION_ERROR', $lang === 'en'
                ? 'The image dimensions are outside the allowed range.'
                : 'Ukuran piksel gambar di luar batas yang diizinkan.', 422);
        }
        $image = @imagecreatefromstring($binary);
        if (! $image instanceof GdImage) {
            throw new ImageRejected('UNSUPPORTED_MEDIA_TYPE', ApiResponse::message('UNSUPPORTED_MEDIA_TYPE', $lang), 415);
        }
        $target = (int) config('sanggah.scan.normalize_edge', 1024);
        $scale = min(1, $target / max($width, $height));
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($newW, $newH);
        if ($canvas === false) {
            imagedestroy($image);
            throw new ImageRejected('VALIDATION_ERROR', 'Could not process the image.', 422);
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $newW, $newH, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($image);
        ob_start();
        imagejpeg($canvas, null, 85);
        imagedestroy($canvas);
        $jpeg = ob_get_clean();
        if (! is_string($jpeg) || $jpeg === '') {
            throw new ImageRejected('VALIDATION_ERROR', 'Could not re-encode the image.', 422);
        }

        return [
            'bytes' => $jpeg,
            'mime' => 'image/jpeg',
            'hash' => hash('sha256', $jpeg),
            'width' => $newW,
            'height' => $newH,
        ];
    }

    private function mime(string $binary): ?string
    {
        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($binary, "\x89PNG\x0D\x0A\x1A\x0A")) {
            return 'image/png';
        }
        if (strlen($binary) >= 12 && str_starts_with($binary, 'RIFF') && substr($binary, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }
}
