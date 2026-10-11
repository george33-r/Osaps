<?php

declare(strict_types=1);

namespace App\Domain\Matching;

/**
 * Explainable, rule-based tailor matching (not machine learning).
 *
 * Every factor is scored 0..1, multiplied by a configurable weight, and the reasons
 * that drove the score are returned as plain sentences. Price and paid promotion are
 * deliberately NOT inputs: matching decides who is a good fit, the customer decides on price.
 *
 * New tailors get a neutral reliability prior (not zero) so they can be discovered,
 * and are clearly labelled as having limited history rather than presented as proven.
 */
final class TailorScorer
{
    public const DEFAULT_WEIGHTS = [
        'specialty' => 30,
        'location' => 20,
        'reliability' => 25,
        'capacity' => 10,
        'responsiveness' => 10,
        'portfolio' => 5,
    ];

    /** Completed orders needed before history is treated as fully established. */
    private const HISTORY_CONFIDENCE_ORDERS = 10;

    private const NEUTRAL_PRIOR = 0.6;

    /** @param array<string,int> $weights */
    public function __construct(private readonly array $weights = self::DEFAULT_WEIGHTS) {}

    /**
     * @param  array{garment_category:string, city:?string, style_tags?:list<string>}  $job
     * @param  array{
     *   specialties: list<string>, service_cities: list<string>, portfolio_tags?: list<string>,
     *   completed_orders: int, on_time_orders: int, upheld_complaints: int,
     *   open_orders: int, weekly_capacity: int, median_response_minutes: ?int
     * }  $tailor
     * @return array{score:int, new_vendor:bool, reasons:list<string>, factors:array<string,float>}
     */
    public function score(array $job, array $tailor): array
    {
        $reasons = [];
        $factors = [];
        $category = strtolower($job['garment_category']);

        // Specialty
        $specialties = array_map('strtolower', $tailor['specialties']);
        $factors['specialty'] = in_array($category, $specialties, true) ? 1.0 : 0.2;
        if ($factors['specialty'] === 1.0) {
            $reasons[] = 'Specialises in '.$this->humanise($category).'.';
        }

        // Location
        $city = $job['city'] !== null ? strtolower($job['city']) : null;
        $cities = array_map('strtolower', $tailor['service_cities']);
        $factors['location'] = $city === null ? 0.5 : (in_array($city, $cities, true) ? 1.0 : 0.1);
        if ($city !== null && $factors['location'] === 1.0) {
            $reasons[] = 'Serves '.ucwords($city).'.';
        }

        // Reliability — on-time rate minus upheld complaints, shrunk toward a neutral prior for thin history.
        $completed = max(0, $tailor['completed_orders']);
        $newVendor = $completed < 3;
        if ($completed === 0) {
            $observed = self::NEUTRAL_PRIOR;
        } else {
            $onTime = min($tailor['on_time_orders'], $completed) / $completed;
            $complaintRate = min($tailor['upheld_complaints'], $completed) / $completed;
            $observed = max(0.0, $onTime - $complaintRate);
        }
        $confidence = min(1.0, $completed / self::HISTORY_CONFIDENCE_ORDERS);
        $factors['reliability'] = $confidence * $observed + (1 - $confidence) * self::NEUTRAL_PRIOR;
        if ($newVendor) {
            $reasons[] = 'New on OSAPS — limited order history so far.';
        } elseif ($observed >= 0.9) {
            $reasons[] = "Strong on-time record across {$completed} completed orders.";
        }

        // Capacity
        $capacity = max(1, $tailor['weekly_capacity']);
        $load = $tailor['open_orders'] / $capacity;
        $factors['capacity'] = max(0.0, 1.0 - $load);
        if ($load >= 1.0) {
            $reasons[] = 'Currently at full capacity — timelines may be longer.';
        }

        // Responsiveness
        $minutes = $tailor['median_response_minutes'];
        $factors['responsiveness'] = $minutes === null ? 0.5 : max(0.0, min(1.0, 1 - ($minutes - 30) / (24 * 60)));
        if ($minutes !== null && $minutes <= 60) {
            $reasons[] = 'Usually responds within an hour.';
        }

        // Portfolio relevance
        $wanted = array_map('strtolower', $job['style_tags'] ?? []);
        $have = array_map('strtolower', $tailor['portfolio_tags'] ?? []);
        $overlap = $wanted === [] ? 0 : count(array_intersect($wanted, $have));
        $factors['portfolio'] = $wanted === [] ? 0.5 : $overlap / count($wanted);
        if ($overlap > 0) {
            $reasons[] = 'Has portfolio work in a similar style.';
        }

        $total = 0.0;
        $weightSum = 0;
        foreach ($this->weights as $key => $weight) {
            $total += ($factors[$key] ?? 0.0) * $weight;
            $weightSum += $weight;
        }

        return [
            'score' => $weightSum > 0 ? (int) round($total / $weightSum * 100) : 0,
            'new_vendor' => $newVendor,
            'reasons' => $reasons,
            'factors' => array_map(fn (float $v) => round($v, 3), $factors),
        ];
    }

    private function humanise(string $slug): string
    {
        return str_replace(['_', '-'], ' ', $slug);
    }
}
