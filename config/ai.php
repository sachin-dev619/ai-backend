<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Mode
    |--------------------------------------------------------------------------
    |
    | online = cloud/free providers (complete and verify this first)
    | local  = Ollama (do not enable until you confirm online works)
    |
    */

    'mode' => env('AI_MODE', 'online'),

    /*
    |--------------------------------------------------------------------------
    | Online Provider
    |--------------------------------------------------------------------------
    |
    | gemini       = Google Gemini free tier (recommended) — needs GEMINI_API_KEY
    | groq         = Groq free tier — needs GROQ_API_KEY
    | pollinations = anonymous free GET (unreliable / often 402)
    |
    */

    'online_provider' => env('AI_ONLINE_PROVIDER', 'gemini'),

    'system_prompt' => env(
        'AI_SYSTEM_PROMPT',
        'You are a helpful AI assistant. Keep answers clear and concise.'
    ),

    'timeout' => (int) env('AI_TIMEOUT', 60),

    'providers' => [

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
            'base_url' => env(
                'GEMINI_BASE_URL',
                'https://generativelanguage.googleapis.com/v1beta'
            ),
        ],

        'groq' => [
            'base_url' => env(
                'GROQ_BASE_URL',
                'https://api.groq.com/openai/v1/chat/completions'
            ),
            'model' => env('GROQ_MODEL', 'llama-3.1-8b-instant'),
            'api_key' => env('GROQ_API_KEY'),
        ],

        'pollinations' => [
            'get_url' => env('POLLINATIONS_GET_URL', 'https://text.pollinations.ai'),
            'base_url' => env(
                'POLLINATIONS_BASE_URL',
                'https://text.pollinations.ai/openai'
            ),
            'model' => env('POLLINATIONS_MODEL', 'openai'),
            'api_key' => env('POLLINATIONS_API_KEY'),
        ],

    ],

];
