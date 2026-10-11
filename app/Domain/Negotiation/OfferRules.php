<?php

declare(strict_types=1);

namespace App\Domain\Negotiation;

use App\Domain\Money\Money;
use App\Domain\Support\DomainRuleViolation;
use DateTimeImmutable;

/**
 * Configurable negotiation rules shared by both pricing modes.
 *
 * Mode "budget": customer names a price; a tailor either accepts that exact price
 *                or counters with a written explanation.
 * Mode "open":   customer optionally gives a range; tailors quote freely, but quoting
 *                above the stated maximum also needs an explanation.
 */
final class OfferRules
{
    public const MODE_BUDGET = 'budget';
    public const MODE_OPEN = 'open';

    public function __construct(
        public readonly int $offerExpiryHours = 48,
        public readonly int $maxCounterRoundsPerSide = 3,
        public readonly int $maxLiveOffersPerTailor = 15,
        public readonly int $maxOffersPerRequest = 20,
        public readonly int $minExplanationLength = 15,
    ) {}

    public function expiresAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify("+{$this->offerExpiryHours} hours");
    }

    public function isExpired(DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool
    {
        return $now >= $expiresAt;
    }

    /** Is a written explanation required for this quoted amount? */
    public function explanationRequired(string $mode, Money $amount, ?Money $budgetMax): bool
    {
        if ($budgetMax === null) {
            return false;
        }

        return $mode === self::MODE_BUDGET
            ? ! $amount->equals($budgetMax)
            : $amount->greaterThan($budgetMax);
    }

    /**
     * Guards for a tailor opening a brand new offer thread on a request.
     *
     * @throws DomainRuleViolation
     */
    public function assertCanOpenOffer(
        string $requestStatus,
        bool $tailorAlreadyHasOffer,
        int $offersOnRequest,
        int $tailorLiveOffers,
        bool $isOwnRequest,
    ): void {
        if ($isOwnRequest) {
            throw new DomainRuleViolation('You cannot make an offer on your own request.', 'own_request');
        }
        if ($requestStatus !== JobRequestStatus::OPEN) {
            throw new DomainRuleViolation('This request is no longer accepting offers.', 'request_closed');
        }
        if ($tailorAlreadyHasOffer) {
            throw new DomainRuleViolation('You already have an offer on this request. Update it instead.', 'duplicate_offer');
        }
        if ($offersOnRequest >= $this->maxOffersPerRequest) {
            throw new DomainRuleViolation('This request has reached its offer limit.', 'request_offer_limit');
        }
        if ($tailorLiveOffers >= $this->maxLiveOffersPerTailor) {
            throw new DomainRuleViolation(
                "You have {$this->maxLiveOffersPerTailor} live offers. Wait for responses or withdraw some first.",
                'tailor_live_offer_limit',
            );
        }
    }

    /** @throws DomainRuleViolation */
    public function assertExplanation(string $mode, Money $amount, ?Money $budgetMax, ?string $explanation): void
    {
        if (! $this->explanationRequired($mode, $amount, $budgetMax)) {
            return;
        }
        if (mb_strlen(trim((string) $explanation)) < $this->minExplanationLength) {
            throw new DomainRuleViolation(
                'Explain why your price differs from the customer\'s budget (at least '.$this->minExplanationLength.' characters).',
                'explanation_required',
            );
        }
    }

    /**
     * $roundsUsed counts revisions this side has already authored *after* the opening offer.
     *
     * @throws DomainRuleViolation
     */
    public function assertCanCounter(int $roundsUsed): void
    {
        if ($roundsUsed >= $this->maxCounterRoundsPerSide) {
            throw new DomainRuleViolation(
                "Counteroffer limit reached ({$this->maxCounterRoundsPerSide} rounds). Accept, decline or withdraw.",
                'counter_limit',
            );
        }
    }
}
