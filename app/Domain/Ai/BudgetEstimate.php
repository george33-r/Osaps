<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Transparent outfit budget. Every line says where its number comes from:
 *   listed       — a real price from the catalogue
 *   tailor_from  — a real tailor's published "prices from"
 *   assumption   — a labelled assumption (e.g. typical yardage)
 *   calculated   — derived from the lines above using published fee rules
 * Missing pieces are named instead of guessed, and no total is given when a core piece is missing.
 */
final class BudgetEstimate
{
    /**
     * @param  ?int  $fabricUnitMinor  listed price per unit of the chosen fabric
     * @param  ?int  $yards  typical yardage for the garment (assumption)
     * @param  list<int>  $tailorFromMinor  "prices from" of matched tailors
     * @param  bool  $needsFabric  false when the customer already has fabric
     * @return array{lines:list<array{key:string,label:string,minor:int,basis:string}>, missing:list<string>, total_minor:?int, budget_minor:?int, within_budget:?bool, note:?string}
     */
    public static function build(
        ?int $fabricUnitMinor,
        ?int $yards,
        array $tailorFromMinor,
        bool $needsFabric,
        bool $needsTailoring,
        int $feeBps,
        int $feeCapMinor,
        ?int $budgetMinor,
    ): array {
        $lines = [];
        $missing = [];

        if ($needsFabric) {
            if ($fabricUnitMinor !== null && $yards !== null && $yards > 0) {
                $lines[] = ['key' => 'fabric', 'label' => "Fabric, about {$yards} units", 'minor' => $fabricUnitMinor * $yards, 'basis' => 'listed × assumption'];
            } else {
                $missing[] = 'a fabric with a listed price';
            }
        }

        $tailoring = null;
        if ($needsTailoring) {
            $prices = array_values(array_filter($tailorFromMinor, fn ($p) => $p > 0));
            if ($prices !== []) {
                sort($prices);
                $tailoring = $prices[intdiv(count($prices) - 1, 2)]; // lower median of real "prices from"
                $lines[] = ['key' => 'tailoring', 'label' => 'Tailoring, typical starting price', 'minor' => $tailoring, 'basis' => 'tailor_from'];
            } else {
                $missing[] = 'tailor prices for this garment';
            }
        }

        if ($tailoring !== null) {
            $fee = min(intdiv($tailoring * $feeBps + 5_000, 10_000), $feeCapMinor > 0 ? $feeCapMinor : PHP_INT_MAX);
            $lines[] = ['key' => 'service_fee', 'label' => 'OSAPS service fee', 'minor' => $fee, 'basis' => 'calculated'];
        }

        $complete = $missing === [];
        $total = $complete && $lines !== [] ? array_sum(array_column($lines, 'minor')) : null;

        $within = ($total !== null && $budgetMinor !== null && $budgetMinor > 0) ? $total <= $budgetMinor : null;
        $note = match (true) {
            $within === false => 'This is above your budget. A simpler style, a less costly fabric or a different tailor would bring it down; actual offers may also differ.',
            $total !== null => 'An estimate, not a quote. Tailors set their own prices in their offers, and delivery is extra if charged.',
            default => null,
        };

        return [
            'lines' => $lines,
            'missing' => $missing,
            'total_minor' => $total,
            'budget_minor' => $budgetMinor,
            'within_budget' => $within,
            'note' => $note,
        ];
    }
}
