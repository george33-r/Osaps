<?php

/*
| Marketplace business rules. All values are configurable through .env.
| The defaults are development placeholders — the business must confirm real
| fee levels, limits and expiry windows before launch.
*/

return [

    'brand' => [
        'name' => env('BRAND_NAME', 'Aṣọlà'),
        'tagline' => env('BRAND_TAGLINE', 'From fabric to finished outfit — your style, your budget, your choice.'),
    ],

    'currency' => 'NGN',

    'fees' => [
        'customer_service_fee_bps' => (int) env('FEE_CUSTOMER_SERVICE_BPS', 250),
        'customer_service_fee_cap_minor' => (int) env('FEE_CUSTOMER_SERVICE_CAP_MINOR', 500_000),
        'tailor_commission_bps' => (int) env('FEE_TAILOR_COMMISSION_BPS', 1000),
    ],

    'offers' => [
        'expiry_hours' => (int) env('OFFER_EXPIRY_HOURS', 48),
        'max_counter_rounds_per_side' => (int) env('OFFER_MAX_COUNTER_ROUNDS', 3),
        'max_live_offers_per_tailor' => (int) env('OFFER_MAX_LIVE_PER_TAILOR', 15),
        'max_offers_per_request' => (int) env('OFFER_MAX_PER_REQUEST', 20),
        'min_explanation_length' => 15,
    ],

    'requests' => [
        'open_days' => (int) env('REQUEST_OPEN_DAYS', 7),
        'max_open_per_customer' => (int) env('REQUEST_MAX_OPEN_PER_CUSTOMER', 10),
    ],

    'matching' => [
        'weights' => [
            'specialty' => (int) env('MATCH_W_SPECIALTY', 30),
            'location' => (int) env('MATCH_W_LOCATION', 20),
            'reliability' => (int) env('MATCH_W_RELIABILITY', 25),
            'capacity' => (int) env('MATCH_W_CAPACITY', 10),
            'responsiveness' => (int) env('MATCH_W_RESPONSIVENESS', 10),
            'portfolio' => (int) env('MATCH_W_PORTFOLIO', 5),
        ],
    ],

    'garment_categories' => [
        'agbada' => 'Agbada',
        'senator' => 'Senator',
        'kaftan' => 'Kaftan',
        'buba_sokoto' => 'Buba & Sokoto',
        'iro_buba' => 'Iro & Buba',
        'gown' => 'Gown',
        'skirt_blouse' => 'Skirt & Blouse',
        'suit' => 'Suit',
        'bridal' => 'Bridal',
        'aso_ebi' => 'Aṣọ-ẹbí (group order)',
        'kids' => 'Children\'s wear',
        'alteration' => 'Alteration',
    ],

    'fabric_categories' => [
        'ankara' => 'Ankara',
        'adire' => 'Adire',
        'aso_oke' => 'Aṣọ-òkè',
        'lace' => 'Lace',
        'senator' => 'Senator fabric',
        'suiting' => 'Suiting',
        'george' => 'George',
        'damask' => 'Damask',
        'other' => 'Other textiles',
    ],

    'fabric_units' => ['yard', 'metre', 'piece', 'bundle'],

    'colors' => ['navy', 'indigo', 'gold', 'terracotta', 'emerald', 'burgundy', 'cream', 'black', 'white', 'orange', 'royal_blue', 'purple', 'green', 'red', 'brown', 'pink', 'silver'],
];
