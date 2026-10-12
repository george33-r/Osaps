<?php

/*
| OSAPS AI layer. Everything here is server-side; no key ever reaches the browser.
| With no AI_API_KEY the app keeps working: each feature falls back to its rule-based mode and says so.
*/

return [
    // anthropic | none
    'provider' => env('AI_PROVIDER', env('AI_API_KEY') ? 'anthropic' : 'none'),

    'anthropic' => [
        'api_key' => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('AI_ANTHROPIC_VERSION', '2023-06-01'),
        // Lowest-cost current model with vision support. Change to claude-sonnet-5-5 for richer styling text.
        'model' => env('AI_MODEL', 'claude-haiku-5-5'),
        'vision_model' => env('AI_VISION_MODEL', env('AI_MODEL', 'claude-haiku-5-5')),
    ],

    // Kept well under shared hosting's usual 30-second PHP limit, so a slow answer falls back instead of crashing.
    'timeout_seconds' => (int) env('AI_TIMEOUT', 15),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 900),

    // Per-user daily allowance across all AI features, plus a short-term burst limit.
    'daily_requests_per_user' => (int) env('AI_DAILY_LIMIT_USER', 60),
    'daily_requests_per_ip' => (int) env('AI_DAILY_LIMIT_IP', 30),
    'per_minute' => (int) env('AI_PER_MINUTE', 8),
    // Site-wide ceiling on AI calls per day, whoever makes them. Bounds the bill.
    'daily_requests_global' => (int) env('AI_DAILY_LIMIT_GLOBAL', 400),

    // Inspiration images: analysed in memory and never stored.
    'image_max_kb' => (int) env('AI_IMAGE_MAX_KB', 5120),
    'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],

    // Prompt versions are logged with every call so output changes can be traced to prompt changes.
    'prompt_versions' => [
        'stylist_extract' => 'stylist-extract-v1',
        'stylist_concept' => 'stylist-concept-v1',
        'fabric_search' => 'fabric-search-v1',
        'fabric_ideas' => 'fabric-ideas-v1',
        'inspiration' => 'inspiration-brief-v1',
    ],

    // "See it on me" try-on. Needs an image model; off until TRYON_PROVIDER=openai and a key are set.
    'try_on' => [
        'provider' => env('TRYON_PROVIDER'),
        'openai_key' => env('TRYON_OPENAI_API_KEY'),
        'model' => env('TRYON_MODEL', 'gpt-image-1'),
        'timeout' => (int) env('TRYON_TIMEOUT', 90),
        'daily_limit' => (int) env('TRYON_DAILY_LIMIT', 5),
        'daily_limit_global' => (int) env('TRYON_DAILY_LIMIT_GLOBAL', 200),
    ],
];
