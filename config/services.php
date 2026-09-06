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
        'enabled' => filter_var(env('RECAPTCHA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'project_id' => env('RECAPTCHA_PROJECT_ID'),
    ],

    'docusign' => [
        'integration_key' => env('DOCUSIGN_INTEGRATION_KEY'),
        'user_id' => env('DOCUSIGN_USER_ID'),
        'account_id' => env('DOCUSIGN_ACCOUNT_ID'),
        'base_path' => env('DOCUSIGN_BASE_PATH', 'https://demo.docusign.net/restapi'),
        'auth_server' => env('DOCUSIGN_AUTH_SERVER', 'https://account-d.docusign.com'),
        'private_key' => env('DOCUSIGN_PRIVATE_KEY'),
        'webhook_secret' => env('DOCUSIGN_WEBHOOK_SECRET'),
    ],

    'signnow' => [
        'access_token' => env('SIGNNOW_ACCESS_TOKEN'),
        'base_url' => env('SIGNNOW_BASE_URL', 'https://api.signnow.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Signing Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which document signing provider to use.
    | Options: 'docusign' or 'signnow'
    |
    */
    'document_signing' => [
        'provider' => env('DOCUMENT_SIGNING_PROVIDER', 'docusign'),
    ],

    'firma_digital' => [
        'app_url' => env('FIRMA_DIGITAL_APP_URL', 'http://localhost:5173'),
        'sign_path' => env('FIRMA_DIGITAL_SIGN_PATH', '/sign'),
    ],

    'auto_sign' => [
        'url' => env('AUTO_SIGN_URL'),
        'api_key' => env('AUTO_SIGN_API_KEY'),
        'timeout' => (int) env('AUTO_SIGN_TIMEOUT', 60),
        'connect_timeout' => (int) env('AUTO_SIGN_CONNECT_TIMEOUT', 10),
        'verify_ssl' => filter_var(env('AUTO_SIGN_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | ProSanet Employees API
    |--------------------------------------------------------------------------
    */
    'prosanet' => [
        'base_url' => env('PROSANET_API_BASE_URL', 'https://api.prosanet.aksingeneo.net/index.php'),
        'username' => env('PROSANET_API_USERNAME'),
        'password' => env('PROSANET_API_PASSWORD'),
        'timeout' => (int) env('PROSANET_API_TIMEOUT', 15),
        'enabled' => filter_var(env('PROSANET_API_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'token_cache_ttl_minutes' => (int) env('PROSANET_API_TOKEN_CACHE_TTL', 55),
    ],
];
