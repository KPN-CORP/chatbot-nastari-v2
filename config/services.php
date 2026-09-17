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
    
    'darwinbox' => [
        'auth_token' => env('DARWINBOX_AUTH_TOKEN'),
        'dataset_key' => env('DARWINBOX_DATASET_KEY'),
        'api_keys' => [
            'employee'      => env('DARWINBOX_API_KEY_EMPLOYEE'),
            'attendance'    => env('DARWINBOX_API_KEY_ATTENDANCE'),
            'leave_balance' => env('DARWINBOX_API_KEY_LEAVE_BALANCE'),
            'dependent'     => env('DARWINBOX_API_KEY_DEPENDENT'),
            'overtime'      => env('DARWINBOX_API_KEY_OVERTIME'),
            'weeklyoff'     => env('DARWINBOX_API_KEY_WEEKLYOFF'),
            'holiday'       => env('DARWINBOX_API_KEY_HOLIDAYLIST'),
        ],
    ],

    'whatsapp' => [
        'verify_token'      => env('WHATSAPP_VERIFY_TOKEN'),
        'access_token'     => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id'  => env('WHATSAPP_PHONE_NUMBER_ID'),
        'version'          => env('WHATSAPP_API_VERSION', 'v22.0'),
    ],
    
    'gemini' => [
        // 'key' reads GEMINI_KEY, which .env does not define, so
        // config('services.gemini.key') has always been null. Kept as-is
        // because DarwinboxService::generateBirthdayMessage() and
        // generateWorkAnniversaryMessage() still read it; 'api_key' below is
        // the one that resolves, and is what new code should use.
        'key'     => env('GEMINI_KEY'),
        'api_key' => env('GEMINI_API_KEY', env('GEMINI_KEY')),
        'model'   => env('GEMINI_MODEL', 'gemini-1.5-flash'),
    ],

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

];
