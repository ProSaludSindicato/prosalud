<?php

return [

    'enabled' => (bool) env('CONVENIO_DIGITAL_SIGNING_ENABLED', true),

    'token_expires_days' => (int) env('CONVENIO_SIGNING_TOKEN_EXPIRES_DAYS', 30),

    'affiliate_submitted_max_kb' => (int) env('CONVENIO_AFFILIATE_PDF_MAX_KB', 12288),

    'affiliate_signature_max_execution_seconds' => (int) env('CONVENIO_AFFILIATE_SIGNATURE_MAX_EXECUTION_SECONDS', 120),

    'affiliate_signature_submit_lock_seconds' => (int) env('CONVENIO_AFFILIATE_SIGNATURE_SUBMIT_LOCK_SECONDS', 180),

    /*
     * Título mostrado en el visor de firma (firma-digital-documentos). Puede sobrescribirse por registro
     * en `convenio_email_tracking.viewer_header_title`.
     */
    'viewer_header_title' => env('CONVENIO_VIEWER_HEADER_TITLE', 'Convenio de afiliación ProSalud'),

];
