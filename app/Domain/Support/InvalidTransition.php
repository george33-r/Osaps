<?php

declare(strict_types=1);

namespace App\Domain\Support;

use DomainException;

final class InvalidTransition extends DomainException
{
    public static function make(string $machine, string $from, string $to, ?string $actor): self
    {
        $name = substr($machine, (int) strrpos($machine, '\\') + 1);
        $who = $actor !== null ? " by {$actor}" : '';

        return new self("{$name}: cannot move from '{$from}' to '{$to}'{$who}.");
    }
}
