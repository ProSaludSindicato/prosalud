<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CloudConvert API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración para la integración con CloudConvert API
    | para conversión de documentos Office a PDF.
    |
    */

    'api_key' => env('CLOUDCONVERT_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | CloudConvert Base URL
    |--------------------------------------------------------------------------
    |
    | URL base de la API de CloudConvert.
    | Por defecto: https://api.cloudconvert.com/v2
    | Para Sandbox: https://api.sandbox.cloudconvert.com/v2
    | Para regiones específicas: https://eu-central.api.cloudconvert.com/v2
    |
    */

    'base_url' => env('CLOUDCONVERT_BASE_URL', 'https://api.cloudconvert.com/v2'),

    /*
    |--------------------------------------------------------------------------
    | Timeout Settings
    |--------------------------------------------------------------------------
    |
    | Tiempo máximo de espera para la conversión (en segundos).
    | CloudConvert puede tardar ~10-30 segundos dependiendo del tamaño del archivo.
    | Aumentado a 120 segundos para permitir procesamiento de múltiples archivos.
    |
    */

    'timeout' => env('CLOUDCONVERT_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | File Size Limits
    |--------------------------------------------------------------------------
    |
    | Límites de tamaño de archivo (en bytes).
    | CloudConvert Free: 25 MB, Pro: 1 GB
    |
    */

    'max_file_size' => env('CLOUDCONVERT_MAX_FILE_SIZE', 25 * 1024 * 1024), // 25 MB por defecto

    /*
    |--------------------------------------------------------------------------
    | Storage Settings
    |--------------------------------------------------------------------------
    |
    | Configuración para almacenamiento temporal de archivos.
    |
    */

    'temp_storage_path' => storage_path('app/tmp'),

    /*
    |--------------------------------------------------------------------------
    | PDF Security Settings
    |--------------------------------------------------------------------------
    |
    | Configuración para proteger los PDFs generados contra falsificación.
    | owner_password: Contraseña interna para establecer permisos (no bloquea la apertura)
    | permissions: Permisos permitidos o prohibidos en el PDF
    |
    */

    'pdf_security' => [
        'enabled' => true,
        'owner_password' => env('CLOUDCONVERT_PDF_OWNER_PASSWORD'),
        'permissions' => [
            // allow_print: enum - "full" (permitir), "low" (baja calidad), "none" (prohibir)
            'allow_print' => 'full',
            // allow_extract: boolean - true (permitir), false (prohibir)
            'allow_extract' => false,
            // allow_modify: enum - "all", "annotate", "form", "assembly", "none"
            'allow_modify' => 'none',
            // allow_accessibility: boolean - true (permitir), false (prohibir)
            'allow_accessibility' => true,
        ],
    ],
];

