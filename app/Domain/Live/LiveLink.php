<?php

declare(strict_types=1);

namespace App\Domain\Live;

use App\Domain\Support\DomainRuleViolation;

/**
 * Vendors go live on the platforms their customers already use. We only store an https link
 * to the stream, checked against the platform's own domains, so no one can slip in another site.
 */
final class LiveLink
{
    public const HOSTS = [
        'tiktok' => ['tiktok.com', 'www.tiktok.com', 'vm.tiktok.com'],
        'instagram' => ['instagram.com', 'www.instagram.com'],
        'youtube' => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'],
        'facebook' => ['facebook.com', 'www.facebook.com', 'fb.watch', 'm.facebook.com'],
    ];

    /** @throws DomainRuleViolation */
    public static function assertValid(string $platform, string $url): void
    {
        $hosts = self::HOSTS[$platform] ?? null;
        if ($hosts === null) {
            throw new DomainRuleViolation('Choose TikTok, Instagram, YouTube or Facebook.', 'platform_invalid');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! in_array(strtolower($parts['host'] ?? ''), $hosts, true) || isset($parts['user']) || isset($parts['port'])) {
            throw new DomainRuleViolation('Paste the https link to your '.ucfirst($platform).' live or profile.', 'url_invalid');
        }
    }

    /** The YouTube video id, when the link points at one (for an embedded player). */
    public static function youtubeId(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $id = null;
        if ($host === 'youtu.be') {
            $id = ltrim($parts['path'] ?? '', '/');
        } elseif (str_ends_with($host, 'youtube.com')) {
            parse_str($parts['query'] ?? '', $q);
            $id = $q['v'] ?? null;
            if ($id === null && preg_match('#^/(?:live|embed|shorts)/([^/?]+)#', $parts['path'] ?? '', $m)) {
                $id = $m[1];
            }
        }

        return is_string($id) && preg_match('/\A[A-Za-z0-9_-]{11}\z/', $id) ? $id : null;
    }
}
