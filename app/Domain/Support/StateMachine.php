<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * Small explicit state machine: a map of from-state => [to-state => allowed actor roles].
 * All status changes for jobs, offers and orders go through one of these on the server.
 */
abstract class StateMachine
{
    /** @return array<string, array<string, list<string>>> */
    abstract protected static function transitions(): array;

    /** @return list<string> */
    public static function states(): array
    {
        $states = array_keys(static::transitions());
        foreach (static::transitions() as $targets) {
            $states = array_merge($states, array_keys($targets));
        }

        return array_values(array_unique($states));
    }

    public static function can(string $from, string $to, ?string $actor = null): bool
    {
        $allowed = static::transitions()[$from][$to] ?? null;
        if ($allowed === null) {
            return false;
        }

        return $actor === null || in_array($actor, $allowed, true);
    }

    /** @throws InvalidTransition */
    public static function assert(string $from, string $to, ?string $actor = null): void
    {
        if (! static::can($from, $to, $actor)) {
            throw InvalidTransition::make(static::class, $from, $to, $actor);
        }
    }

    /** @return list<string> */
    public static function nextFor(string $from, string $actor): array
    {
        $next = [];
        foreach (static::transitions()[$from] ?? [] as $to => $actors) {
            if (in_array($actor, $actors, true)) {
                $next[] = $to;
            }
        }

        return $next;
    }

    public static function isTerminal(string $state): bool
    {
        return (static::transitions()[$state] ?? []) === [];
    }
}
