<?php

declare(strict_types=1);

namespace App\Domain\Support;

use DomainException;

/** A business rule was broken (e.g. too many counteroffers). Rendered as HTTP 422 by the API. */
final class DomainRuleViolation extends DomainException
{
    public function __construct(string $message, public readonly string $rule)
    {
        parent::__construct($message);
    }
}
