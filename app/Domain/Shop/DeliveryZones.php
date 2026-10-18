<?php

declare(strict_types=1);

namespace App\Domain\Shop;

/**
 * Delivery inside Nigeria, priced by the buyer's state. Fees (kobo) and delivery times come from
 * config, per zone; a state not listed falls into "other". The defaults are placeholders the owner
 * replaces with their courier's real prices.
 */
final class DeliveryZones
{
    public const STATES = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River', 'Delta',
        'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT (Abuja)', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi',
        'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto',
        'Taraba', 'Yobe', 'Zamfara',
    ];

    /**
     * @param  array<string, array{fee_minor:int, days:string, label:string}>  $zones  e.g. lagos, south_west, other
     * @param  array<string, string>  $stateZone  state name → zone key
     */
    public function __construct(private readonly array $zones, private readonly array $stateZone) {}

    public static function normalise(?string $state): ?string
    {
        $s = trim((string) $state);
        if ($s === '') {
            return null;
        }
        foreach (self::STATES as $known) {
            if (strcasecmp($known, $s) === 0 || (str_starts_with($known, 'FCT') && in_array(strtolower($s), ['fct', 'abuja', 'fct abuja'], true))) {
                return $known;
            }
        }

        return null;
    }

    public function zoneFor(?string $state): string
    {
        $s = self::normalise($state);
        $zone = $s !== null ? ($this->stateZone[$s] ?? 'other') : 'other';

        return isset($this->zones[$zone]) ? $zone : 'other';
    }

    public function feeFor(?string $state): int
    {
        return max(0, (int) ($this->zones[$this->zoneFor($state)]['fee_minor'] ?? 0));
    }

    public function daysFor(?string $state): string
    {
        return (string) ($this->zones[$this->zoneFor($state)]['days'] ?? '');
    }

    /** The cheapest delivery anywhere, for "Delivery from ₦…" before a state is chosen. */
    public function lowestFee(): int
    {
        $fees = array_map(fn ($z) => (int) $z['fee_minor'], $this->zones);

        return $fees === [] ? 0 : min($fees);
    }

    /** @return list<array{key:string, label:string, fee_minor:int, days:string, states:list<string>}> */
    public function table(): array
    {
        $out = [];
        foreach ($this->zones as $key => $z) {
            $states = $key === 'other'
                ? array_values(array_filter(self::STATES, fn ($s) => ($this->stateZone[$s] ?? 'other') === 'other'))
                : array_keys(array_filter($this->stateZone, fn ($v) => $v === $key));
            $out[] = ['key' => $key, 'label' => (string) $z['label'], 'fee_minor' => (int) $z['fee_minor'], 'days' => (string) $z['days'], 'states' => $states];
        }

        return $out;
    }
}
