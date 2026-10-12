<?php

namespace App\TryOn;

use App\Domain\Support\DomainRuleViolation;

class UnavailableTryOn implements TryOnProvider
{
    public function available(): bool
    {
        return false;
    }

    public function render(string $photo, string $photoMime, ?string $fabricPhoto, ?string $fabricMime, string $prompt): string
    {
        throw new DomainRuleViolation('Try-on is not switched on yet.', 'try_on_disabled');
    }
}
