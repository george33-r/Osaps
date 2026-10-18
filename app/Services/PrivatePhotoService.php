<?php

namespace App\Services;

use App\Domain\Support\DomainRuleViolation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Customer photos (design samples, chat pictures) kept outside the web folder in
 * storage/app/private, under random names. When the server has GD, each photo is redrawn as a
 * fresh JPEG at most 1600px wide: that drops hidden data such as the phone's GPS location and
 * any bytes that are not really an image.
 */
class PrivatePhotoService
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    private const OK = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** @return array{path:string, mime:string, size_bytes:int} */
    public function store(UploadedFile $file, string $folder): array
    {
        $mime = (string) $file->getMimeType(); // from the contents, not the browser's claim
        if (! isset(self::OK[$mime])) {
            throw new DomainRuleViolation('Send a JPG, PNG or WebP photo.', 'photo_type');
        }
        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw new DomainRuleViolation('Photos can be up to 8 MB.', 'photo_too_big');
        }
        $folder = trim(preg_replace('/[^a-z0-9\/_-]/i', '', $folder) ?? 'misc', '/');
        $dir = storage_path("app/private/{$folder}");
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new DomainRuleViolation('Photos cannot be saved right now. Please try again later.', 'photo_storage');
        }

        $name = Str::lower(Str::random(28));
        $clean = $this->reencode((string) file_get_contents($file->getRealPath()));
        if ($clean !== null) {
            file_put_contents("{$dir}/{$name}.jpg", $clean);

            return ['path' => "{$folder}/{$name}.jpg", 'mime' => 'image/jpeg', 'size_bytes' => strlen($clean)];
        }
        $ext = self::OK[$mime];
        $file->move($dir, "{$name}.{$ext}");

        return ['path' => "{$folder}/{$name}.{$ext}", 'mime' => $mime, 'size_bytes' => (int) filesize("{$dir}/{$name}.{$ext}")];
    }

    public function response(string $path, string $mime): BinaryFileResponse
    {
        $full = realpath(storage_path('app/private/'.$path));
        $root = realpath(storage_path('app/private'));
        abort_unless($full !== false && $root !== false && str_starts_with($full, $root.DIRECTORY_SEPARATOR), 404);

        return response()->file($full, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function delete(?string $path): void
    {
        if (! $path) {
            return;
        }
        $full = realpath(storage_path('app/private/'.$path));
        $root = realpath(storage_path('app/private'));
        if ($full !== false && $root !== false && str_starts_with($full, $root.DIRECTORY_SEPARATOR)) {
            @unlink($full);
        }
    }

    private function reencode(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new DomainRuleViolation('That file is not a photo we can open.', 'photo_unreadable');
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w > 1600) {
            $scaled = imagescale($img, 1600, (int) round($h * 1600 / $w));
            if ($scaled !== false) {
                imagedestroy($img);
                $img = $scaled;
            }
        }
        ob_start();
        imagejpeg($img, null, 84);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
