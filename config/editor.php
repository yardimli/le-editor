<?php

return [
    'dev_mode' => env('OPENROUTER_DEV_MODE', false),
    'api_key' => env('OPENROUTER_API_KEY'),
    'model' => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
    'providers' => array_values(array_unique(array_filter(array_map(fn ($provider) => strtolower(trim($provider)),
        explode(',', env('OPENROUTER_PROVIDERS', 'deepseek,anthropic,openai,moonshotai,google,qwen')))))),
    'min_output_price' => (float) env('OPENROUTER_MIN_OUTPUT_PRICE', 0.01),
    'max_output_price' => (float) env('OPENROUTER_MAX_OUTPUT_PRICE', 30),
    'base_url' => 'https://openrouter.ai/api/v1',
];
