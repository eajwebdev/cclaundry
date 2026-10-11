<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Receipt and bill photos attached to expenses.
 *
 * Photos are re-encoded as a small JPEG (at most MAX_BYTES, longest side at
 * most MAX_SIDE) so a phone picture of a few MB is stored in KB. Re-encoding
 * also drops EXIF data such as GPS location. Files live on the private disk
 * and are only served through the authorized expense receipt route.
 */
class ExpenseReceipt
{
    public const DISK = 'local';

    public const DIRECTORY = 'expense-receipts';

    public const MAX_BYTES = 200 * 1024;

    public const MAX_SIDE = 1400;

    private const MIN_SIDE = 480;

    public static function store(UploadedFile $file): string
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $image = @imagecreatefromstring($contents);

        if (! $image) {
            throw ValidationException::withMessages([
                'receipt' => 'The receipt photo could not be read. Please use a JPG, PNG or WEBP picture.',
            ]);
        }

        $image = self::orient($image, $contents);
        $jpeg = self::compress($image);
        imagedestroy($image);

        $path = self::DIRECTORY.'/'.now()->format('Y/m').'/'.Str::uuid().'.jpg';
        Storage::disk(self::DISK)->put($path, $jpeg);

        return $path;
    }

    public static function exists(?string $path): bool
    {
        return filled($path) && Storage::disk(self::DISK)->exists($path);
    }

    public static function response(string $path): StreamedResponse
    {
        return Storage::disk(self::DISK)->response($path, basename($path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function delete(?string $path): void
    {
        if (filled($path) && str_starts_with($path, self::DIRECTORY.'/')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** Shrink and lower JPEG quality until the photo fits in MAX_BYTES. */
    private static function compress(\GdImage $source): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min(self::MAX_SIDE, max($width, $height));

        while (true) {
            $scale = $side / max($width, $height);
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            // Transparent PNG areas become white instead of black.
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            foreach ([72, 62, 52, 42] as $quality) {
                ob_start();
                imagejpeg($canvas, null, $quality);
                $jpeg = (string) ob_get_clean();

                if (strlen($jpeg) <= self::MAX_BYTES) {
                    imagedestroy($canvas);

                    return $jpeg;
                }
            }

            imagedestroy($canvas);

            if ($side <= self::MIN_SIDE) {
                return $jpeg;
            }

            $side = max(self::MIN_SIDE, (int) round($side * 0.8));
        }
    }

    /** Phone photos are often stored sideways with an EXIF rotation flag. */
    private static function orient(\GdImage $image, string $contents): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($contents, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contents));
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }
}
