<?php

namespace App\Domain\Support;

/**
 * Remembers which install tokens have already been used, so a link that was shared, logged or
 * forgotten in .env stops working after its first successful run. Only SHA-256 hashes are kept.
 * Framework-free so it can be tested without Laravel.
 */
final class InstallLedger
{
    public const MIN_TOKEN_LENGTH = 32;

    /** @param list<string> $usedHashes */
    private function __construct(
        public readonly bool $installed,
        private array $usedHashes,
        private array $runs,
    ) {}

    /** Reads the lock file's contents. Accepts the old format (a plain timestamp line). */
    public static function fromLock(?string $contents): self
    {
        if ($contents === null || trim($contents) === '') {
            return new self(false, [], []);
        }
        $data = json_decode($contents, true);
        if (! is_array($data)) {
            return new self(true, [], [['at' => trim($contents), 'kind' => 'legacy']]);
        }

        return new self(true, array_values(array_filter($data['used'] ?? [], 'is_string')), $data['runs'] ?? []);
    }

    public static function tokenIsStrong(string $token): bool
    {
        return strlen($token) >= self::MIN_TOKEN_LENGTH && ctype_alnum($token);
    }

    public function wasUsed(string $token): bool
    {
        return in_array(hash('sha256', $token), $this->usedHashes, true);
    }

    public function record(string $token, string $at, string $kind): self
    {
        $used = $this->usedHashes;
        $used[] = hash('sha256', $token);
        $runs = $this->runs;
        $runs[] = ['at' => $at, 'kind' => $kind];

        return new self(true, array_values(array_unique($used)), array_slice($runs, -20));
    }

    public function toLock(): string
    {
        return json_encode(['used' => $this->usedHashes, 'runs' => $this->runs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
