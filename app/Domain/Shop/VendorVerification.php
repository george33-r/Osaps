<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Support\DomainRuleViolation;
use App\Domain\Support\StateMachine;

/**
 * A fabric shop sells and gets paid only once an OSAPS admin has checked it. Vendors can build
 * their shop and listings before that, but nothing is visible to buyers until it is verified.
 */
final class VendorVerification extends StateMachine
{
    public const UNVERIFIED = 'unverified';
    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';
    public const SUSPENDED = 'suspended';

    public const LABELS = [
        self::UNVERIFIED => 'Not submitted',
        self::PENDING => 'Waiting for review',
        self::VERIFIED => 'Verified',
        self::REJECTED => 'Changes needed',
        self::SUSPENDED => 'Suspended',
    ];

    protected static function transitions(): array
    {
        return [
            self::UNVERIFIED => [self::PENDING => ['vendor']],
            self::PENDING => [self::VERIFIED => ['admin'], self::REJECTED => ['admin']],
            self::REJECTED => [self::PENDING => ['vendor']],
            self::VERIFIED => [self::SUSPENDED => ['admin']],
            self::SUSPENDED => [self::VERIFIED => ['admin']],
        ];
    }

    public static function canSell(?string $status): bool
    {
        return $status === self::VERIFIED;
    }

    /**
     * What a shop must fill in before asking for review.
     *
     * @param  array<string, mixed>  $profile
     * @return list<string> the missing field names
     */
    public static function missingForReview(array $profile): array
    {
        $missing = [];
        foreach (['business_name', 'city', 'market', 'phone', 'bank_name', 'bank_account_name'] as $field) {
            if (trim((string) ($profile[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }
        if (preg_match('/\A\d{10}\z/', (string) ($profile['bank_account_number'] ?? '')) !== 1) {
            $missing[] = 'bank_account_number';
        }

        return $missing;
    }

    /** @param array<string, mixed> $profile */
    public static function assertReadyForReview(array $profile): void
    {
        $missing = self::missingForReview($profile);
        if ($missing !== []) {
            throw new DomainRuleViolation('Fill in '.implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $missing)).' before asking for review.', 'verification_incomplete');
        }
    }

    public static function assertNoteGiven(string $to, ?string $note): void
    {
        if (in_array($to, [self::REJECTED, self::SUSPENDED], true) && mb_strlen(trim((string) $note)) < 5) {
            throw new DomainRuleViolation('Say what the vendor needs to fix, so they can act on it.', 'verification_note_required');
        }
    }
}
