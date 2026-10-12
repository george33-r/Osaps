<?php

/*
| Online payments. Card, bank transfer and USSD payments go through Paystack.
| Keys live only in .env on the server — never in the web/ frontend, never in git.
| Use test keys (sk_test_…) first, pay with a Paystack test card, then switch to live keys.
*/

return [
    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'timeout' => (int) env('PAYSTACK_TIMEOUT', 15),
        // Channels shown on the Paystack page. Leave empty for Paystack's defaults.
        'channels' => array_filter(explode(',', (string) env('PAYSTACK_CHANNELS', 'card,bank,ussd,bank_transfer'))),
    ],
];
