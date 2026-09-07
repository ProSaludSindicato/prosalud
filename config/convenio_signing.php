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

    'auto_sign' => [
        'enabled' => filter_var(env('AUTO_SIGN_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'per_minute' => (int) env('AUTO_SIGN_PER_MINUTE', 8),
    ],

    'president_sign_bulk_enabled' => filter_var(env('CONVENIO_PRESIDENT_SIGN_BULK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'president_sign_require_review' => filter_var(env('CONVENIO_PRESIDENT_SIGN_REQUIRE_REVIEW', true), FILTER_VALIDATE_BOOLEAN),

    'president_sign_bulk_max' => (int) env('CONVENIO_PRESIDENT_SIGN_BULK_MAX', 1000),

    'president' => [
        'search_text' => env('AUTO_SIGN_SEARCH_TEXT', 'JORGE IVAN ÁLVAREZ SOTO'),
        'secondary_anchor' => env('AUTO_SIGN_SECONDARY_ANCHOR', 'PRESIDENTE'),
        'search_page' => (int) env('AUTO_SIGN_SEARCH_PAGE', 2),
        'width' => (int) env('AUTO_SIGN_STAMP_WIDTH', 48),
        'height' => (int) env('AUTO_SIGN_STAMP_HEIGHT', 63),
        'offset_x' => (int) env('AUTO_SIGN_OFFSET_X', 0),
        'offset_y' => (int) env('AUTO_SIGN_OFFSET_Y', -14),
        'signature_path' => env('AUTO_SIGN_SIGNATURE_PATH', 'resources/signatures/presidente.png'),
    ],

];
