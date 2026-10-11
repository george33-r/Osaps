<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Keeps model output tied to real records. The model is shown candidate IDs and may only
 * pick from them; anything else it returns — invented IDs, duplicates — is dropped here.
 */
final class Grounding
{
    /**
     * @param  mixed  $picked  model output (expected list of ids)
     * @param  list<int|string>  $allowed  ids the server actually offered
     * @return list<int|string>
     */
    public static function pick(mixed $picked, array $allowed, int $max): array
    {
        if (! is_array($picked)) {
            return [];
        }
        $allowedSet = array_flip(array_map('strval', $allowed));
        $out = [];
        foreach ($picked as $id) {
            if ((is_int($id) || is_string($id)) && isset($allowedSet[(string) $id]) && ! in_array($id, $out, false)) {
                $out[] = $id;
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** Strip text the model should never assert: prices, stock claims and guarantees. */
    public static function scrubClaims(string $text): string
    {
        $text = preg_replace('/(₦|NGN|N)\s?\d[\d,\.]*\s?(k|m)?/iu', 'a price shown by the vendor', $text) ?? $text;
        $text = preg_replace('/\b(guaranteed?|in stock|available now|will arrive by)\b/iu', '', $text) ?? $text;

        return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
    }
}
