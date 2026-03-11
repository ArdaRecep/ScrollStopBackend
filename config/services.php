<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
        'tts_model' => env('OPENAI_TTS_MODEL', 'tts-1-hd'),
        'tts_speed' => (float) env('OPENAI_TTS_SPEED', 0.95),
        'request_timeout_seconds' => (int) env('OPENAI_REQUEST_TIMEOUT_SECONDS', 900),
        'tts_timeout_seconds' => (int) env('OPENAI_TTS_TIMEOUT_SECONDS', 900),
    ],

    'flux' => [
        'api_key' => env('FLUXAI_API_KEY'),
        'endpoint' => env('FLUXAI_API_ENDPOINT', 'https://api.fluxapi.ai/api/v1/flux/kontext/generate'),
        'poll_endpoint' => env('FLUXAI_POLL_ENDPOINT', ''),
        'model' => env('FLUXAI_MODEL', 'flux-kontext-pro'),
        'enable_translation' => env('FLUXAI_ENABLE_TRANSLATION', true),
        'prompt_upsampling' => env('FLUXAI_PROMPT_UPSAMPLING', false),
        'output_format' => env('FLUXAI_OUTPUT_FORMAT', 'jpeg'),
        'safety_tolerance' => env('FLUXAI_SAFETY_TOLERANCE', 2),
        'timeout_seconds' => (int) env('FLUXAI_TIMEOUT_SECONDS', 600),
        'poll_request_timeout_seconds' => (int) env('FLUXAI_POLL_REQUEST_TIMEOUT_SECONDS', 180),
        'job_timeout_seconds' => (int) env('FLUXAI_JOB_TIMEOUT_SECONDS', 1800),
        'poll_attempts' => (int) env('FLUXAI_POLL_ATTEMPTS', 1800),
        'poll_delay_ms' => (int) env('FLUXAI_POLL_DELAY_MS', 1000),
    ],

    'firebase' => [
        'firestore_timeout_seconds' => (int) env('FIRESTORE_TIMEOUT_SECONDS', 120),
    ],

];
