<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * The consultation script: what the stylist already knows and which question is most useful next.
 * Deciding the order of questions is deterministic on purpose — the AI reads free-text answers and
 * writes the final concept, but it never decides what is asked, so the flow is predictable and cheap.
 */
final class StylistConsultation
{
    public const SLOTS = ['occasion', 'garment', 'colors', 'budget_naira', 'needs', 'timing'];

    public const NEEDS = ['fabric_and_tailoring' => 'Fabric and tailoring', 'tailoring_only' => 'Tailoring only — I have fabric', 'fabric_only' => 'Fabric only'];

    public const TIMING = ['two_weeks' => 'Within 2 weeks', 'month' => 'Within a month', 'three_months' => 'Within 3 months', 'no_rush' => 'No rush'];

    /**
     * @param  array<string,string>  $occasions  key => label
     * @param  array<string,string>  $garments  key => label
     * @param  list<string>  $colors
     */
    public function __construct(
        private readonly array $occasions,
        private readonly array $garments,
        private readonly array $colors,
    ) {}

    /**
     * Keeps only well-formed answers; anything else is dropped so it gets asked again.
     *
     * @param  array<string,mixed>  $known
     * @return array<string,mixed>
     */
    public function clean(array $known): array
    {
        $out = [];
        if (is_string($known['occasion'] ?? null) && isset($this->occasions[$known['occasion']])) {
            $out['occasion'] = $known['occasion'];
        }
        if (is_string($known['garment'] ?? null) && isset($this->garments[$known['garment']])) {
            $out['garment'] = $known['garment'];
        }
        if (is_array($known['colors'] ?? null)) {
            $colors = array_values(array_unique(array_filter($known['colors'], fn ($c) => is_string($c) && in_array($c, $this->colors, true))));
            if ($colors !== [] || ($known['colors_open'] ?? false) === true) {
                $out['colors'] = array_slice($colors, 0, 4);
            }
        }
        if ((is_int($known['budget_naira'] ?? null) && $known['budget_naira'] >= 1_000 && $known['budget_naira'] <= 100_000_000)
            || ($known['budget_naira'] ?? null) === 0) {
            $out['budget_naira'] = $known['budget_naira']; // 0 = "not sure yet"
        }
        if (is_string($known['needs'] ?? null) && isset(self::NEEDS[$known['needs']])) {
            $out['needs'] = $known['needs'];
        }
        if (is_string($known['timing'] ?? null) && isset(self::TIMING[$known['timing']])) {
            $out['timing'] = $known['timing'];
        }
        if (is_string($known['preferences'] ?? null) && trim($known['preferences']) !== '') {
            $out['preferences'] = mb_substr(trim($known['preferences']), 0, 300);
        }

        return $out;
    }

    /** @param array<string,mixed> $known */
    public function complete(array $known): bool
    {
        return $this->next($known) === null;
    }

    /**
     * The next question, phrased for the person, with tappable choices. Null when ready for a concept.
     *
     * @param  array<string,mixed>  $known
     * @return null|array{slot:string, text:string, choices:list<array{value:string|int, label:string}>, multiple:bool, allow_text:bool}
     */
    public function next(array $known): ?array
    {
        $kv = fn (array $m) => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys($m), array_values($m));

        foreach (self::SLOTS as $slot) {
            if (array_key_exists($slot, $known)) {
                continue;
            }

            return match ($slot) {
                'occasion' => ['slot' => $slot, 'text' => 'What is the occasion?', 'choices' => $kv($this->occasions), 'multiple' => false, 'allow_text' => true],
                'garment' => ['slot' => $slot, 'text' => $this->garmentQuestion($known), 'choices' => $kv(array_diff_key($this->garments, ['alteration' => 1])), 'multiple' => false, 'allow_text' => true],
                'colors' => ['slot' => $slot, 'text' => 'Which colours are you drawn to? Pick up to four, or skip if you are open.', 'choices' => array_map(fn ($c) => ['value' => $c, 'label' => ucwords(str_replace('_', ' ', $c))], $this->colors), 'multiple' => true, 'allow_text' => true],
                'budget_naira' => ['slot' => $slot, 'text' => 'Roughly what would you like to spend in total, fabric and tailoring together?', 'choices' => [
                    ['value' => 30_000, 'label' => 'About ₦30,000'], ['value' => 60_000, 'label' => 'About ₦60,000'],
                    ['value' => 120_000, 'label' => 'About ₦120,000'], ['value' => 250_000, 'label' => '₦250,000 or more'], ['value' => 0, 'label' => 'Not sure yet'],
                ], 'multiple' => false, 'allow_text' => true],
                'needs' => ['slot' => $slot, 'text' => 'Do you need fabric, tailoring, or both?', 'choices' => $kv(self::NEEDS), 'multiple' => false, 'allow_text' => false],
                'timing' => ['slot' => $slot, 'text' => 'When do you need to wear it?', 'choices' => $kv(self::TIMING), 'multiple' => false, 'allow_text' => false],
            };
        }

        return null;
    }

    /** @param array<string,mixed> $known */
    private function garmentQuestion(array $known): string
    {
        $occasion = isset($known['occasion']) ? mb_strtolower($this->occasions[$known['occasion']]) : null;

        return $occasion !== null && $occasion !== 'something else'
            ? "Lovely. What would you like to wear for the {$occasion}?"
            : 'What kind of outfit do you have in mind?';
    }
}
