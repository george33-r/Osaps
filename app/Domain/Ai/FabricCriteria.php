<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Structured fabric-search criteria. Whatever produced them — the AI or the keyword parser —
 * they pass through sanitize(), which keeps only values the catalogue actually uses.
 * The AI can therefore never invent a category, colour or product; it can only choose filters.
 */
final class FabricCriteria
{
    /**
     * @param  list<string>  $categories
     * @param  list<string>  $colors
     * @param  list<string>  $terms  plain words to match in name/material/description
     */
    public function __construct(
        public readonly array $categories = [],
        public readonly array $colors = [],
        public readonly ?int $maxPriceMinor = null,
        public readonly bool $inStockOnly = true,
        public readonly array $terms = [],
        public readonly ?string $occasion = null,
    ) {}

    /**
     * @param  array<string,mixed>  $raw  e.g. AI output {categories, colors, max_price_naira, terms, occasion}
     * @param  list<string>  $allowedCategories
     * @param  list<string>  $allowedColors
     * @param  list<string>  $allowedOccasions
     */
    public static function sanitize(array $raw, array $allowedCategories, array $allowedColors, array $allowedOccasions = []): self
    {
        $pick = fn ($list, $allowed) => array_values(array_unique(array_filter(
            is_array($list) ? $list : [],
            fn ($v) => is_string($v) && in_array($v, $allowed, true),
        )));

        $max = $raw['max_price_naira'] ?? null;
        $maxMinor = (is_int($max) || is_float($max)) && $max >= 100 && $max <= 10_000_000 ? (int) round($max * 100) : null;

        $terms = array_values(array_filter(array_map(
            fn ($t) => is_string($t) ? mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s-]/u', '', $t) ?? '')) : '',
            is_array($raw['terms'] ?? null) ? array_slice($raw['terms'], 0, 4) : [],
        ), fn ($t) => mb_strlen($t) >= 3 && mb_strlen($t) <= 30));

        $occasion = $raw['occasion'] ?? null;

        return new self(
            categories: $pick($raw['categories'] ?? [], $allowedCategories),
            colors: $pick($raw['colors'] ?? [], $allowedColors),
            maxPriceMinor: $maxMinor,
            inStockOnly: ($raw['in_stock_only'] ?? true) !== false,
            terms: $terms,
            occasion: is_string($occasion) && in_array($occasion, $allowedOccasions, true) ? $occasion : null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->categories === [] && $this->colors === [] && $this->maxPriceMinor === null && $this->terms === [];
    }

    /**
     * Progressively looser versions, used when nothing matches: drop the price cap, then colours, then terms.
     *
     * @return list<array{criteria: self, dropped: string}>
     */
    public function relaxations(): array
    {
        $out = [];
        $c = $this;
        if ($c->maxPriceMinor !== null) {
            $c = new self($c->categories, $c->colors, null, $c->inStockOnly, $c->terms, $c->occasion);
            $out[] = ['criteria' => $c, 'dropped' => 'price limit'];
        }
        if ($c->colors !== []) {
            $c = new self($c->categories, [], $c->maxPriceMinor, $c->inStockOnly, $c->terms, $c->occasion);
            $out[] = ['criteria' => $c, 'dropped' => 'colour'];
        }
        if ($c->terms !== []) {
            $c = new self($c->categories, $c->colors, $c->maxPriceMinor, $c->inStockOnly, [], $c->occasion);
            $out[] = ['criteria' => $c, 'dropped' => 'extra words'];
        }

        return $out;
    }

    /** Filters for Fabric::scopeFilter. */
    public function toFilters(): array
    {
        return array_filter([
            'category' => $this->categories ?: null,
            'any_color' => $this->colors ?: null,
            'max_price' => $this->maxPriceMinor,
            'in_stock' => $this->inStockOnly ?: null,
            'terms' => $this->terms ?: null,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'categories' => $this->categories,
            'colors' => $this->colors,
            'max_price_minor' => $this->maxPriceMinor,
            'in_stock_only' => $this->inStockOnly,
            'terms' => $this->terms,
            'occasion' => $this->occasion,
        ];
    }
}
