<?php

declare(strict_types=1);

namespace App\Domain\Tailors;

use App\Domain\Support\DomainRuleViolation;
use App\Domain\Support\StateMachine;

/**
 * A tailor shows a badge only after an OSAPS admin has checked every required document.
 * Nothing is marked verified automatically: a failed or unavailable face match goes to a person.
 */
final class TailorVerification extends StateMachine
{
    public const UNVERIFIED = 'unverified';
    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';
    public const SUSPENDED = 'suspended';

    public const ID_DOCUMENT = 'id_document';
    public const SELFIE = 'selfie';
    public const WORKSHOP_PHOTO = 'workshop_photo';
    public const CAC_CERTIFICATE = 'cac_certificate';

    /** Must be uploaded before a review can be requested. */
    public const REQUIRED = [self::ID_DOCUMENT, self::SELFIE, self::WORKSHOP_PHOTO];

    /** Uploaded only by tailors who trade as a registered company. */
    public const OPTIONAL = [self::CAC_CERTIFICATE];

    /** Identity and biometric data: needs explicit consent before it is taken. */
    public const BIOMETRIC = [self::ID_DOCUMENT, self::SELFIE];

    public const KIND_LABELS = [
        self::ID_DOCUMENT => 'Government ID (NIN, passport or driver\'s licence)',
        self::SELFIE => 'Selfie (face clearly visible, taken with the camera)',
        self::WORKSHOP_PHOTO => 'Workshop photo or short video',
        self::CAC_CERTIFICATE => 'CAC business registration',
    ];

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
            self::UNVERIFIED => [self::PENDING => ['tailor']],
            self::PENDING => [self::VERIFIED => ['admin'], self::REJECTED => ['admin']],
            self::REJECTED => [self::PENDING => ['tailor']],
            self::VERIFIED => [self::SUSPENDED => ['admin']],
            self::SUSPENDED => [self::VERIFIED => ['admin']],
        ];
    }

    /** @return list<string> kinds in this set that are not yet covered */
    public static function missing(array $statusByKind, bool $needApproved): array
    {
        $ok = $needApproved ? ['approved'] : ['submitted', 'approved'];

        return array_values(array_filter(self::REQUIRED, fn (string $k) => ! in_array($statusByKind[$k] ?? null, $ok, true)));
    }

    public static function assertNoteGiven(string $to, ?string $note): void
    {
        if (in_array($to, [self::REJECTED, self::SUSPENDED], true) && mb_strlen(trim((string) $note)) < 5) {
            throw new DomainRuleViolation('Say what the tailor needs to fix, so they can act on it.', 'verification_note_required');
        }
    }
}
