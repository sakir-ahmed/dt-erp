<?php

return [
    'outbound_enabled' => env('CONVERSE_OUTBOUND_ENABLED', false),
    'graph_version' => env('CONVERSE_GRAPH_VERSION', 'v27.0'),

    'ai' => [
        'auto_reply' => env('CONVERSE_AI_AUTO_REPLY', false),
        'confidence_threshold' => 0.8,
        'agents' => [
            'sales' => ['name' => 'Sales', 'role' => 'Sales', 'tone' => 'friendly and concise'],
            'support' => ['name' => 'Support', 'role' => 'Support', 'tone' => 'patient and concise'],
        ],
        'channels' => [],
        'rules' => [],
        'cloud' => ['live' => false, 'models' => []],
        'embedding_model' => env('CONVERSE_EMBEDDING_MODEL'),
        'primary' => env('CONVERSE_AI_PRIMARY', 'ollama'),
        'allow_cloud' => env('CONVERSE_AI_ALLOW_CLOUD', false),
        'openai_model' => env('CONVERSE_OPENAI_MODEL'),
        'ollama_enabled' => env('CONVERSE_OLLAMA_ENABLED', true),
        'ollama_fallback_model' => env('CONVERSE_OLLAMA_FALLBACK_MODEL'),
        'ollama_model' => env('CONVERSE_OLLAMA_MODEL'),
        'ollama_port' => env('CONVERSE_OLLAMA_PORT', 11434),
    ],

    'webhooks' => [
        'meta' => [
            'secret' => env('CONVERSE_META_APP_SECRET'),
            'verify_token' => env('CONVERSE_META_VERIFY_TOKEN'),
            'object' => 'page',
        ],
        'whatsapp' => [
            'secret' => env('CONVERSE_WHATSAPP_APP_SECRET'),
            'verify_token' => env('CONVERSE_WHATSAPP_VERIFY_TOKEN'),
            'object' => 'whatsapp_business_account',
        ],
    ],
];
