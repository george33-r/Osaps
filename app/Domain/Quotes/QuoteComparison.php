<?php

declare(strict_types=1);

namespace App\Domain\Quotes;

/**
 * Deterministic offer comparison (rule-based — labelled as such in the UI, not presented as AI).
 *
 * It normalises what each offer includes, highlights the cheapest and fastest, and flags
 * missing details or prices far below the others as things to *ask about*. It never labels
 * a tailor as fraudulent.
 */
final class QuoteComparison
{
    /** A quote below this fraction of the median gets a "confirm what's included" prompt. */
    private const LOW_QUOTE_RATIO = 0.6;

    /**
     * @param  list<array{id:int|string, amount_minor:int, delivery_days:?int, includes_fitting:?bool,
     *                    alterations_included:?int, delivery_fee_minor:?int}>  $offers
     * @return array<int|string, array{badges:list<string>, flags:list<string>, questions:list<string>}>
     */
    public function analyse(array $offers): array
    {
        $result = [];
        if ($offers === []) {
            return $result;
        }

        $amounts = array_map(fn ($o) => $o['amount_minor'] + ($o['delivery_fee_minor'] ?? 0), $offers);
        $minAmount = min($amounts);
        $median = $this->median($amounts);
        $days = array_filter(array_map(fn ($o) => $o['delivery_days'], $offers), fn ($d) => $d !== null);
        $minDays = $days === [] ? null : min($days);
        $many = count($offers) > 1;

        foreach ($offers as $i => $offer) {
            $badges = [];
            $flags = [];
            $questions = [];
            $total = $amounts[$i];

            if ($many && $total === $minAmount) {
                $badges[] = 'Lowest price';
            }
            if ($many && $minDays !== null && $offer['delivery_days'] === $minDays) {
                $badges[] = 'Fastest';
            }

            if ($offer['delivery_days'] === null) {
                $flags[] = 'No completion time given';
                $questions[] = 'When will the outfit be ready?';
            }
            if ($offer['includes_fitting'] === null) {
                $flags[] = 'Fitting not specified';
                $questions[] = 'Is a fitting session included before final delivery?';
            }
            if ($offer['alterations_included'] === null) {
                $flags[] = 'Alterations not specified';
                $questions[] = 'How many free alterations are included if it does not fit?';
            }
            if ($offer['delivery_fee_minor'] === null) {
                $flags[] = 'Delivery cost not stated';
                $questions[] = 'Is delivery included, or is there a separate charge?';
            }
            if (count($offers) >= 3 && $median > 0 && $total < $median * self::LOW_QUOTE_RATIO) {
                $flags[] = 'Much lower than other offers';
                $questions[] = 'Can you confirm exactly what this price includes (lining, finishing, embellishment)?';
            }

            $result[$offer['id']] = ['badges' => $badges, 'flags' => $flags, 'questions' => $questions];
        }

        return $result;
    }

    /** @param list<int> $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
