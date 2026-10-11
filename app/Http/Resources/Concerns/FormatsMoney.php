<?php

namespace App\Http\Resources\Concerns;

use App\Domain\Money\Money;

trait FormatsMoney
{
    /** Consistent money shape for every client: { amount_minor, currency, formatted } or null. */
    protected static function money(?int $minor): ?array
    {
        return $minor === null ? null : Money::minor($minor)->toArray();
    }
}
