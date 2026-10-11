<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Pulls the first complete JSON object out of a model reply (models sometimes wrap JSON in
 * prose or code fences). Returns null when there is no parseable object — callers then fall back.
 */
final class JsonExtractor
{
    public static function object(string $text): ?array
    {
        $text = trim($text);
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($text);
        for ($i = $start; $i < $len; $i++) {
            $c = $text[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $decoded = json_decode(substr($text, $start, $i - $start + 1), true);

                    return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }
}
