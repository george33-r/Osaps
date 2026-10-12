<?php

namespace App\Services;

use App\Domain\Support\DomainRuleViolation;
use App\Models\Fabric;
use App\Models\FabricMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Vendor photos and short videos of the real bolt. Files go in public/uploads/fabrics/{id}/ with
 * a random name and an extension chosen from the detected file type (never the uploaded name).
 * The uploads folder carries an .htaccess that refuses to run scripts.
 */
class MediaService
{
    public const MAX_PHOTOS = 6;
    public const MAX_VIDEOS = 1;
    private const EXT = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
    ];

    public function add(Fabric $fabric, UploadedFile $file): FabricMedia
    {
        $mime = (string) $file->getMimeType(); // from the file's contents, not the browser's claim
        $ext = self::EXT[$mime] ?? null;
        if ($ext === null) {
            throw new DomainRuleViolation('Upload a JPG, PNG or WebP photo, or an MP4, WebM or MOV video.', 'media_type');
        }
        $kind = str_starts_with($mime, 'video/') ? 'video' : 'photo';
        $count = $fabric->media()->where('kind', $kind)->count();
        if ($kind === 'photo' && $count >= self::MAX_PHOTOS) {
            throw new DomainRuleViolation('Up to '.self::MAX_PHOTOS.' photos per fabric. Remove one first.', 'media_limit');
        }
        if ($kind === 'video' && $count >= self::MAX_VIDEOS) {
            throw new DomainRuleViolation('One video per fabric. Remove the old one first.', 'media_limit');
        }

        $this->guard();
        $dir = "fabrics/{$fabric->id}";
        $name = Str::lower(Str::random(24)).'.'.$ext;
        $size = (int) $file->getSize();
        $file->move(public_path('uploads/'.$dir), $name);

        $media = new FabricMedia(['kind' => $kind, 'path' => "{$dir}/{$name}", 'mime' => $mime, 'size_bytes' => $size, 'sort' => $count]);
        $media->fabric_id = $fabric->id;
        $media->save();

        return $media;
    }

    public function remove(FabricMedia $media): void
    {
        $path = public_path('uploads/'.$media->path);
        // Only ever delete inside the uploads folder.
        $real = realpath($path);
        $root = realpath(public_path('uploads'));
        if ($real !== false && $root !== false && str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            @unlink($real);
        }
        $media->delete();
    }

    /** Make sure the uploads folder exists and can never execute code. */
    private function guard(): void
    {
        $root = public_path('uploads');
        if (! is_dir($root)) {
            mkdir($root, 0755, true);
        }
        $ht = $root.'/.htaccess';
        if (! is_file($ht)) {
            file_put_contents($ht, "Options -Indexes\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pl .py .cgi\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh|html?|svg|js)$\">\n    Require all denied\n</FilesMatch>\n<IfModule mod_headers.c>\n    Header set X-Content-Type-Options nosniff\n</IfModule>\n");
        }
    }
}
