<?php

namespace App\Domain\Ai;

/**
 * Makes customer-supplied text safe to place inside a prompt's <user_input> block:
 * strips control characters, neutralises angle brackets so the text cannot close the block
 * or open a fake one, collapses whitespace, and caps the length.
 */
final class PromptText
{
    public static function quote(?string $text, int $max = 500): string
    {
        $t = (string) $text;
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t) ?? '';
        $t = strtr($t, ['<' => '‹', '>' => '›']);
        $t = preg_replace('/\s+/u', ' ', $t) ?? '';

        return mb_substr(trim($t), 0, $max);
    }

    /** JSON-encodes structured context and neutralises brackets inside every string value. */
    public static function data(array $value, int $max = 2000): string
    {
        array_walk_recursive($value, function (&$v) {
            if (is_string($v)) {
                $v = self::quote($v, 300);
            }
        });

        return mb_substr((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, $max);
    }

    /** Wraps quoted text in the delimiter the guardrails tell the model to treat as data. */
    public static function block(?string $text, int $max = 500): string
    {
        return '<user_input>'.self::quote($text, $max).'</user_input>';
    }
}
