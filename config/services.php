<?php

return [
    'openrouter' => [
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model' => env('OPENROUTER_MODEL', 'openai/gpt-oss-20b:free'),
    ],

    'freemodel' => [
        'base_url' => 'https://api.freemodel.dev/v1',
        'model' => env('FREEMODEL_MODEL', 'gpt-5.5'),
        'key' => env('FREEMODEL_API_KEY'),
    ],
];
