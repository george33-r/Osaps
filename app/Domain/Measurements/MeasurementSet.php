<?php

declare(strict_types=1);

namespace App\Domain\Measurements;

use App\Domain\Support\DomainRuleViolation;

/** Body measurements a customer saves once and shares with the tailor they hire. */
final class MeasurementSet
{
    /** key => [label, min cm, max cm] */
    public const FIELDS = [
        'neck' => ['Neck', 25, 60],
        'shoulder' => ['Shoulder width', 30, 70],
        'chest' => ['Chest / bust', 60, 180],
        'under_bust' => ['Under bust', 55, 160],
        'waist' => ['Waist', 45, 180],
        'hip' => ['Hip', 60, 200],
        'sleeve' => ['Sleeve length', 20, 90],
        'round_sleeve' => ['Round sleeve (bicep)', 18, 60],
        'top_length' => ['Top length', 40, 130],
        'gown_length' => ['Gown / kaftan length', 70, 180],
        'trouser_length' => ['Trouser length', 60, 130],
        'thigh' => ['Thigh', 35, 90],
        'ankle' => ['Ankle', 15, 40],
        'height' => ['Height', 100, 230],
    ];

    /**
     * Normalise to centimetres with one decimal and check each value is humanly plausible.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, float>
     *
     * @throws DomainRuleViolation
     */
    public static function normalise(array $values, string $unit): array
    {
        if (! in_array($unit, ['cm', 'in'], true)) {
            throw new DomainRuleViolation('Choose centimetres or inches.', 'unit_invalid');
        }
        $out = [];
        foreach ($values as $key => $raw) {
            if ($raw === null || $raw === '') {
                continue;
            }
            if (! isset(self::FIELDS[$key])) {
                throw new DomainRuleViolation('Unknown measurement: '.$key.'.', 'measurement_unknown');
            }
            if (! is_numeric($raw)) {
                throw new DomainRuleViolation(self::FIELDS[$key][0].' must be a number.', 'measurement_invalid');
            }
            $cm = round((float) $raw * ($unit === 'in' ? 2.54 : 1), 1);
            [$label, $min, $max] = self::FIELDS[$key];
            if ($cm < $min || $cm > $max) {
                throw new DomainRuleViolation("{$label} looks unusual. Check it is in ".($unit === 'in' ? 'inches' : 'centimetres').'.', 'measurement_out_of_range');
            }
            $out[$key] = $cm;
        }

        return $out;
    }
}
