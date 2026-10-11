<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Rule-based reading of plain-language requests. It is the fallback when no AI provider is
 * configured or the provider fails, and it is labelled "basic search" in the UI. It recognises
 * fabric types, colours (with everyday synonyms), occasions, garments and a price ceiling.
 */
final class KeywordInterpreter
{
    private const CATEGORY_WORDS = [
        'aso_oke' => ['aso oke', 'aso-oke', 'asooke', 'aso ofi', 'sanyan', 'alaari', 'etu'],
        'ankara' => ['ankara', 'wax print', 'african print', 'kitenge'],
        'adire' => ['adire', 'tie dye', 'tie-dye', 'tiedye', 'kampala', 'batik'],
        'lace' => ['lace', 'guipure', 'cord lace', 'french lace', 'tulle'],
        'senator' => ['senator'],
        'suiting' => ['suit', 'suiting', 'wool', 'tweed'],
        'george' => ['george', 'wrapper'],
        'damask' => ['damask', 'brocade', 'jacquard', 'atiku'],
    ];

    private const COLOR_WORDS = [
        'burgundy' => ['burgundy', 'wine', 'maroon', 'oxblood', 'plum', 'claret'],
        'navy' => ['navy'],
        'royal_blue' => ['royal blue', 'cobalt'],
        'indigo' => ['indigo'],
        'gold' => ['gold', 'golden', 'champagne'],
        'emerald' => ['emerald', 'bottle green', 'forest green'],
        'green' => ['green', 'olive', 'lime'],
        'cream' => ['cream', 'ivory', 'off white', 'off-white', 'beige', 'nude'],
        'white' => ['white'],
        'black' => ['black', 'charcoal'],
        'purple' => ['purple', 'lilac', 'lavender', 'violet'],
        'orange' => ['orange', 'coral', 'peach'],
        'red' => ['red', 'scarlet'],
        'pink' => ['pink', 'rose', 'fuchsia'],
        'brown' => ['brown', 'chocolate', 'coffee'],
        'terracotta' => ['terracotta', 'rust'],
        'silver' => ['silver', 'grey', 'gray'],
    ];

    /** Generic "blue" means any blue we stock. */
    private const COLOR_FAMILIES = ['blue' => ['navy', 'royal_blue', 'indigo']];

    private const OCCASION_WORDS = [
        'own_wedding' => ['my wedding', 'bride', 'bridal', 'groom', 'my traditional wedding', 'engagement'],
        'traditional_wedding' => ['traditional wedding', 'introduction', 'trad'],
        'wedding_guest' => ['wedding guest', 'wedding', 'owambe'],
        'naming_ceremony' => ['naming'],
        'church_mosque' => ['church', 'mosque', 'jumat', 'sunday service', 'eid', 'sallah'],
        'work' => ['work', 'office', 'corporate', 'interview'],
        'party' => ['party', 'birthday', 'dinner', 'evening'],
        'family_event' => ['family', 'aso ebi', 'aso-ebi', 'asoebi', 'matching'],
        'everyday' => ['everyday', 'casual', 'daily'],
        'festival' => ['festival', 'durbar', 'ojude oba', 'iri ji', 'new yam', 'carnival'],
        'december' => ['detty december', 'detty', 'december', 'christmas', 'new year'],
    ];

    private const GARMENT_WORDS = [
        'agbada' => ['agbada'],
        'senator' => ['senator outfit', 'senator for'],
        'kaftan' => ['kaftan', 'caftan', 'jalabiya', 'kaftans'],
        'buba_sokoto' => ['buba and sokoto', 'buba & sokoto', 'sokoto'],
        'iro_buba' => ['iro and buba', 'iro & buba', 'iro'],
        'gown' => ['gown', 'dress', 'maxi'],
        'skirt_blouse' => ['skirt and blouse', 'skirt & blouse', 'skirt', 'blouse'],
        'suit' => ['suit', 'tuxedo', 'blazer'],
        'bridal' => ['wedding dress', 'bridal gown'],
        'kids' => ['child', 'kids', 'children', 'boy', 'girl'],
        'alteration' => ['alter', 'alteration', 'adjust', 'resize'],
    ];

    /**
     * @return array{categories:list<string>, colors:list<string>, max_price_naira:?int, occasion:?string, garment:?string, budget_naira:?int}
     */
    public static function read(string $text): array
    {
        $t = ' '.self::normalise($text).' ';

        $categories = self::matchKeys($t, self::CATEGORY_WORDS);
        // "suit" is a garment more often than a fabric request when another fabric is named.
        if (in_array('suiting', $categories, true) && count($categories) > 1 && ! str_contains($t, 'suiting')) {
            $categories = array_values(array_diff($categories, ['suiting']));
        }

        $colors = self::matchKeys($t, self::COLOR_WORDS);
        foreach (self::COLOR_FAMILIES as $word => $members) {
            if (preg_match('/\b'.$word.'\b/u', $t) && ! array_intersect($members, $colors)) {
                $colors = array_merge($colors, $members);
            }
        }

        $occasions = self::matchKeys($t, self::OCCASION_WORDS);
        $garments = self::matchKeys($t, self::GARMENT_WORDS);
        $amount = self::amount($t);
        $isCeiling = (bool) preg_match('/\b(under|below|less than|max(imum)?|not more than|within|up to|cheaper than)\b/u', $t);

        return [
            'categories' => $categories,
            'colors' => array_values(array_unique($colors)),
            'max_price_naira' => $amount !== null && $isCeiling ? $amount : null,
            'occasion' => $occasions[0] ?? null,
            'garment' => $garments[0] ?? null,
            'budget_naira' => $amount,
        ];
    }

    /** "₦45,000", "45k", "45000 naira", "1.5m" → whole naira. */
    public static function amount(string $text): ?int
    {
        $t = self::normalise($text);
        if (! preg_match_all('/(₦|ngn)?\s*(\d{1,3}(?:,\d{3})+|\d+(?:\.\d+)?)\s*(k|m|thousand|million|naira)?(?![\p{L}\d])/u', $t, $all, PREG_SET_ORDER)) {
            return null;
        }
        // The first number that is clearly money: has a currency mark or unit, or is at least 1,000.
        // Bare small numbers ("6 people", "2 yards") are ignored.
        foreach ($all as $m) {
            $raw = (float) str_replace(',', '', $m[2]);
            $unit = $m[3] ?? '';
            $value = match ($unit) {
                'k', 'thousand' => $raw * 1_000,
                'm', 'million' => $raw * 1_000_000,
                default => $raw,
            };
            $marked = ($m[1] ?? '') !== '' || $unit !== '';
            if (($marked || $value >= 1000) && $value >= 100) {
                return (int) round($value);
            }
        }

        return null;
    }

    public static function normalise(string $text): string
    {
        $t = mb_strtolower($text);
        $t = strtr($t, ['ṣ' => 's', 'ọ' => 'o', 'ẹ' => 'e', 'ò' => 'o', 'ó' => 'o', 'è' => 'e', 'é' => 'e', 'à' => 'a', 'á' => 'a', 'ì' => 'i', 'í' => 'i', 'ù' => 'u', 'ú' => 'u']);
        $t = preg_replace('/[\x{0300}-\x{036f}]/u', '', \Normalizer::isNormalized($t, \Normalizer::FORM_D) ? $t : (\Normalizer::normalize($t, \Normalizer::FORM_D) ?: $t)) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /** @param array<string, list<string>> $map @return list<string> */
    private static function matchKeys(string $t, array $map): array
    {
        $found = [];
        foreach ($map as $key => $words) {
            foreach ($words as $w) {
                if (preg_match('/(?<![\p{L}])'.preg_quote($w, '/').'(?![\p{L}])/u', $t)) {
                    $found[] = $key;
                    break;
                }
            }
        }

        return $found;
    }
}
