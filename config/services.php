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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    /*
    |--------------------------------------------------------------------------
    | Frontend Application
    |--------------------------------------------------------------------------
    |
    | Configuración de la aplicación frontend (SPA / panel de administración)
    | que se usa para construir enlaces que se envían por correo.
    |
    */

    'frontend' => [
        // URL base del frontend, por ejemplo: https://panel.prosalud.com
        'url' => env('FRONTEND_URL', env('APP_URL')),

        // Ruta donde el usuario define su contraseña inicial
        'password_setup_path' => env('FRONTEND_PASSWORD_SETUP_PATH', '/auth/definir-contraseña'),

        // Ruta donde el usuario restablece su contraseña
        'password_reset_path' => env('FRONTEND_PASSWORD_RESET_PATH', '/auth/restablecer-contraseña'),
    ],

    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'project_id' => env('RECAPTCHA_PROJECT_ID'),
    ],
];
