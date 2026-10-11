<?php

namespace Tests;

use App\Models\Offer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** The terms a customer is looking at when they press Accept (the API refuses an accept without them). */
    protected function acceptPayload(int|string $offerId): array
    {
        $offer = Offer::query()->findOrFail($offerId);

        return ['expected_amount_minor' => $offer->amount_minor, 'expected_delivery_fee_minor' => $offer->delivery_fee_minor];
    }
}
