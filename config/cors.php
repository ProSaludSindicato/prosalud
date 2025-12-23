<?php

// Get allowed origins from environment variable
// Format: comma-separated list of origins (e.g., "https://example.com,https://app.example.com")
$allowedOriginsEnv = env('CORS_ALLOWED_ORIGINS', 'https://sindicatoprosalud.com,https://prosalud.org.co');

// Convert comma-separated string to array and trim whitespace
$allowedOrigins = array_map('trim', explode(',', $allowedOriginsEnv));

// Filter out empty values
$allowedOrigins = array_filter($allowedOrigins);

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values($allowedOrigins),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400, // 24 hours

    // Supports credentials must be false when using wildcard origins
    // Set to false by default (change to true if cookies/sessions are needed)
    'supports_credentials' => false,
];
