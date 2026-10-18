<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Shop\DeliveryZones;
use PHPUnit\Framework\TestCase;

final class DeliveryZonesTest extends TestCase
{
    private function zones(): DeliveryZones
    {
        return new DeliveryZones(
            ['lagos' => ['label' => 'Lagos', 'fee_minor' => 300_000, 'days' => '1–2 working days', 'express_fee_minor' => 600_000, 'express_days' => 'Next working day'],
                'south_west' => ['label' => 'South west', 'fee_minor' => 450_000, 'days' => '2–3 working days'],
                'other' => ['label' => 'Other', 'fee_minor' => 600_000, 'days' => '3–5 working days']],
            ['Lagos' => 'lagos', 'Oyo' => 'south_west', 'Ogun' => 'south_west'],
        );
    }

    public function test_every_state_and_abuja_is_listed_once(): void
    {
        $this->assertCount(37, DeliveryZones::STATES);
        $this->assertCount(37, array_unique(DeliveryZones::STATES));
        $this->assertSame('FCT (Abuja)', DeliveryZones::normalise('abuja'));
        $this->assertSame('FCT (Abuja)', DeliveryZones::normalise('FCT'));
        $this->assertSame('Akwa Ibom', DeliveryZones::normalise(' akwa ibom '));
        $this->assertNull(DeliveryZones::normalise('Atlantis'));
        $this->assertNull(DeliveryZones::normalise(''));
    }

    public function test_fee_and_time_follow_the_state(): void
    {
        $z = $this->zones();
        $this->assertSame(300_000, $z->feeFor('lagos'));
        $this->assertSame(450_000, $z->feeFor('Oyo'));
        $this->assertSame(600_000, $z->feeFor('Kano'));
        $this->assertSame(600_000, $z->feeFor('Abuja'));
        $this->assertSame('1–2 working days', $z->daysFor('Lagos'));
        $this->assertSame(300_000, $z->lowestFee());
    }

    public function test_an_unknown_state_is_charged_the_highest_zone_not_nothing(): void
    {
        $this->assertSame(600_000, $this->zones()->feeFor(null));
        $this->assertSame(600_000, $this->zones()->feeFor('Atlantis'));
    }

    public function test_the_table_puts_every_state_in_exactly_one_zone(): void
    {
        $seen = [];
        foreach ($this->zones()->table() as $row) {
            foreach ($row['states'] as $s) {
                $seen[] = $s;
            }
        }
        sort($seen);
        $all = DeliveryZones::STATES;
        sort($all);
        $this->assertSame($all, $seen);
    }

    public function test_express_is_priced_per_zone_and_can_be_switched_off(): void
    {
        $z = $this->zones();
        $this->assertSame(600_000, $z->expressFeeFor('Lagos'));
        $this->assertSame('Next working day', $z->expressDaysFor('Lagos'));
        $this->assertNull($z->expressFeeFor('Kano'));
        $this->assertNull($z->expressDaysFor('Kano'));
        $rows = array_column($z->table(), null, 'key');
        $this->assertSame(600_000, $rows['lagos']['express_fee_minor']);
        $this->assertNull($rows['other']['express_fee_minor']);
    }
}
