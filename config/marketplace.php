<?php

/*
| Marketplace business rules. All values are configurable through .env.
| The defaults are development placeholders — the business must confirm real
| fee levels, limits and expiry windows before launch.
*/

return [

    'brand' => [
        'name' => env('BRAND_NAME', 'OSAPS'),
        'tagline' => env('BRAND_TAGLINE', 'From fabric to finished outfit — your style, your budget, your choice.'),
        // Optional licensed campaign photograph for the homepage hero, e.g. /spa/brand/campaign.jpg.
        // Without it the hero shows draped illustrations of real catalogue fabrics, captioned as such.
        'campaign_image' => env('BRAND_CAMPAIGN_IMAGE'),
        'campaign_caption' => env('BRAND_CAMPAIGN_CAPTION'),
    ],

    'currency' => 'NGN',

    // Shown when customers need a human. Leave empty to hide.
    'support' => [
        'email' => env('SUPPORT_EMAIL'),
        // An empty SUPPORT_WHATSAPP= line in .env still falls back to the shop's number.
        'whatsapp' => env('SUPPORT_WHATSAPP') ?: '+2348162898760',
    ],

    // Disputes stay off until an admin can resolve them (see CHECKLIST.md). While off, customers are
    // pointed to support instead and the API refuses the "disputed" transition.
    'disputes_enabled' => (bool) env('DISPUTES_ENABLED', false),

    // Typical yardage per garment, used ONLY to label an estimated fabric cost — never as a fact.
    'typical_yards' => [
        'agbada' => 10, 'senator' => 5, 'kaftan' => 5, 'buba_sokoto' => 5, 'iro_buba' => 6, 'gown' => 5,
        'skirt_blouse' => 5, 'suit' => 4, 'bridal' => 8, 'aso_ebi' => 5, 'kids' => 3, 'alteration' => 0,
    ],

    // Which fabric types usually suit each garment — used to shortlist real catalogue items for the stylist.
    'garment_fabrics' => [
        'agbada' => ['aso_oke', 'damask', 'senator'], 'senator' => ['senator', 'damask', 'plain'], 'kaftan' => ['senator', 'damask', 'adire', 'plain'],
        'buba_sokoto' => ['aso_oke', 'adire', 'damask'], 'iro_buba' => ['aso_oke', 'adire', 'ankara', 'lace'], 'gown' => ['lace', 'ankara', 'george', 'print', 'plain'],
        'skirt_blouse' => ['ankara', 'lace', 'adire', 'print'], 'suit' => ['suiting'], 'bridal' => ['lace', 'aso_oke', 'george'],
        'aso_ebi' => ['ankara', 'lace', 'aso_oke'], 'kids' => ['ankara', 'adire'], 'alteration' => [],
    ],

    'occasions' => [
        'wedding_guest' => 'Wedding guest', 'traditional_wedding' => 'Traditional wedding', 'own_wedding' => 'My own wedding',
        'naming_ceremony' => 'Naming ceremony', 'church_mosque' => 'Church or mosque', 'work' => 'Work',
        'party' => 'Party or birthday', 'everyday' => 'Everyday', 'family_event' => 'Family event (aṣọ-ẹbí)',
        'festival' => 'Festival (Durbar, Ojude Oba, Iri Ji)', 'december' => 'Detty December', 'other' => 'Something else',
    ],

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


    // Fabric-only shopping. Placeholder values — confirm with the business before launch.
    'shop' => [
        'service_fee_bps' => (int) env('SHOP_SERVICE_FEE_BPS', 250),
        'service_fee_cap_minor' => (int) env('SHOP_SERVICE_FEE_CAP_MINOR', 500_000),
        'vendor_commission_bps' => (int) env('SHOP_VENDOR_COMMISSION_BPS', 500),
        // Fallback delivery charge per vendor (used only when no state is known). Pickup is free.
        'delivery_fee_per_vendor_minor' => (int) env('SHOP_DELIVERY_FEE_MINOR', 300_000),
        // Delivery inside Nigeria by the buyer's state. PLACEHOLDER fees and times: put your courier's real ones in .env.
        'delivery_zones' => [
            // Express: a faster courier for a higher fee. Set an express fee to 0 to switch it off for that zone.
            'lagos' => ['label' => 'Lagos', 'fee_minor' => (int) env('DELIVERY_LAGOS_MINOR', 300_000), 'days' => env('DELIVERY_LAGOS_DAYS', '1–2 working days'),
                'express_fee_minor' => (int) env('EXPRESS_LAGOS_MINOR', 600_000), 'express_days' => env('EXPRESS_LAGOS_DAYS', 'Next working day')],
            'south_west' => ['label' => 'Ogun, Oyo, Osun, Ondo, Ekiti', 'fee_minor' => (int) env('DELIVERY_SOUTH_WEST_MINOR', 450_000), 'days' => env('DELIVERY_SOUTH_WEST_DAYS', '2–3 working days'),
                'express_fee_minor' => (int) env('EXPRESS_SOUTH_WEST_MINOR', 900_000), 'express_days' => env('EXPRESS_SOUTH_WEST_DAYS', '1–2 working days')],
            'other' => ['label' => 'Other states and Abuja', 'fee_minor' => (int) env('DELIVERY_OTHER_MINOR', 600_000), 'days' => env('DELIVERY_OTHER_DAYS', '3–5 working days'),
                'express_fee_minor' => (int) env('EXPRESS_OTHER_MINOR', 1_200_000), 'express_days' => env('EXPRESS_OTHER_DAYS', '2 working days')],
        ],
        'delivery_state_zones' => ['Lagos' => 'lagos', 'Ogun' => 'south_west', 'Oyo' => 'south_west', 'Osun' => 'south_west', 'Ondo' => 'south_west', 'Ekiti' => 'south_west'],
        // Fabric usually sold by the piece: yards in a full piece, by category. Buyers can still buy any number of yards.
        'piece_yards' => [
            'lace' => (int) env('PIECE_YARDS_LACE', 5),
            'ankara' => (int) env('PIECE_YARDS_ANKARA', 6),
        ],
        // Whether pickup is offered (the store needs an address buyers can come to).
        'pickup_enabled' => (bool) env('SHOP_PICKUP_ENABLED', false),
        // How long stock is held while the buyer pays.
        'checkout_hold_minutes' => (int) env('SHOP_CHECKOUT_HOLD_MINUTES', 60),
        // Buyer protection: after a confirmed delivery (buyer tap or delivery code) the buyer has this
        // many days to report a problem before the order completes and the vendor can be paid.
        'auto_complete_days' => (int) env('SHOP_AUTO_COMPLETE_DAYS', 7),
        // Paying a vendor needs two different admins: one approves, another records the transfer.
        // Turn off only while OSAPS has a single admin, and turn it back on as soon as it has two.
        // Paid orders with no handover after this many days show up on the admin "stuck" list.
        'stuck_after_days' => (int) env('SHOP_STUCK_AFTER_DAYS', 7),
        'two_person_payouts' => (bool) env('SHOP_TWO_PERSON_PAYOUTS', true),
        'offers' => [
            // Lowest offer allowed, as a share of the list price (7000 = 70%).
            'floor_bps' => (int) env('SHOP_OFFER_FLOOR_BPS', 7_000),
            'response_hours' => (int) env('SHOP_OFFER_RESPONSE_HOURS', 24),
            'hold_hours' => (int) env('SHOP_OFFER_HOLD_HOURS', 48),
            'max_rounds_per_side' => (int) env('SHOP_OFFER_MAX_ROUNDS', 3),
            'max_live_per_buyer' => (int) env('SHOP_OFFER_MAX_LIVE', 10),
        ],
    ],

    // Shoppers abroad. Prices are always charged in naira; these rates only show approximate amounts.
    // Rates are naira per one unit of the currency. Update them regularly (placeholders, not live rates).
    'diaspora' => [
        'naira_per_unit' => [
            'GBP' => (float) env('FX_GBP', 0),
            'USD' => (float) env('FX_USD', 0),
            'EUR' => (float) env('FX_EUR', 0),
            'CAD' => (float) env('FX_CAD', 0),
        ],
        'rates_updated' => env('FX_UPDATED'),
        // International shipping per vendor parcel, by zone, in kobo. 0 = not offered.
        'shipping_minor' => [
            'uk' => (int) env('SHIP_UK_MINOR', 0),
            'us' => (int) env('SHIP_US_MINOR', 0),
            'ca' => (int) env('SHIP_CA_MINOR', 0),
            'eu' => (int) env('SHIP_EU_MINOR', 0),
            'africa' => (int) env('SHIP_AFRICA_MINOR', 0),
            'other' => (int) env('SHIP_OTHER_MINOR', 0),
        ],
    ],

    // WhatsApp Cloud API (Meta). Off until both values are set. See docs/GROWTH.md.
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'template' => env('WHATSAPP_TEMPLATE', 'osaps_update'),
        'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
    ],

    'requests' => [
        'open_days' => (int) env('REQUEST_OPEN_DAYS', 7),
        'max_open_per_customer' => (int) env('REQUEST_MAX_OPEN_PER_CUSTOMER', 10),
        // Express requests ("I need it urgently") close for offers sooner, and are listed first for tailors.
        'express_offer_hours' => (int) env('REQUEST_EXPRESS_OFFER_HOURS', 24),
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
        'plain' => 'Plain fabric',
        'print' => 'Printed fabric',
        'other' => 'Other textiles',
    ],

    'fabric_units' => ['yard', 'metre', 'piece', 'bundle'],

    'colors' => ['navy', 'indigo', 'gold', 'terracotta', 'emerald', 'burgundy', 'cream', 'black', 'white', 'orange', 'royal_blue', 'purple', 'green', 'red', 'brown', 'pink', 'silver', 'yellow'],
];
