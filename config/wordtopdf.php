<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Word-to-PDF Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración para la integración con el microservicio word-to-pdf
    | (Flask + LibreOffice) para conversión de documentos Office a PDF.
    |
    */

    'enabled' => env('WORDTOPDF_ENABLED', true),

    'base_url' => env('WORDTOPDF_BASE_URL', 'http://localhost:5001'),

    'api_key' => env('WORDTOPDF_API_KEY', ''),

    'timeout' => env('WORDTOPDF_TIMEOUT', 120),

    'connect_timeout' => env('WORDTOPDF_CONNECT_TIMEOUT', 10),

    'protect_pdf' => env('WORDTOPDF_PROTECT_PDF', true),

    'verify_ssl' => env('WORDTOPDF_VERIFY_SSL', true),

    'max_file_size' => env('WORDTOPDF_MAX_FILE_SIZE', 20 * 1024 * 1024),

    'temp_storage_path' => storage_path('app/tmp'),
];
