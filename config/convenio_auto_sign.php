<?php

return [

    /*
     * Habilita la funcionalidad de firma automática del presidente.
     * Cuando está en false, todos los endpoints de firma presidencial devuelven 404.
     * También se requiere que CONVENIO_DIGITAL_SIGNING_ENABLED esté en true.
     */
    'enabled' => (bool) env('CONVENIO_AUTO_SIGN_ENABLED', false),

    /*
     * URL base del servicio Node que expone POST /api/auto-sign.
     * Ejemplo: http://firma-digital-service:3001
     */
    'api_url' => env('CONVENIO_AUTO_SIGN_API_URL', 'http://localhost:3001'),

    /*
     * API key compartida con el servicio Node (X-Api-Key header).
     * Debe coincidir con AUTO_SIGN_API_KEY en el .env del servicio Node.
     */
    'api_key' => env('CONVENIO_AUTO_SIGN_API_KEY', ''),

    /*
     * Timeout total (segundos) esperando la respuesta del servicio de firma.
     */
    'timeout_seconds' => (int) env('CONVENIO_AUTO_SIGN_TIMEOUT', 60),

    /*
     * Timeout de conexión (segundos) al servicio de firma.
     */
    'connect_timeout_seconds' => (int) env('CONVENIO_AUTO_SIGN_CONNECT_TIMEOUT', 10),

    /*
     * Número máximo de reintentos del Job SignConvenioWithPresidentJob.
     */
    'max_retries' => (int) env('CONVENIO_AUTO_SIGN_MAX_RETRIES', 3),

    /*
     * Backoff (segundos) entre reintentos del Job.
     * Índice 0 = primer reintento, 1 = segundo, etc.
     */
    'retry_backoff_seconds' => [30, 120, 600],

    /*
     * Cuando está en true, al finalizar la firma del afiliado se despacha
     * automáticamente el Job de firma presidencial.
     * Mantener en false durante el período de pruebas manuales.
     */
    'auto_dispatch_after_affiliate' => (bool) env('CONVENIO_AUTO_SIGN_AUTODISPATCH', false),

];
