<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bulk PDF export settings
    |--------------------------------------------------------------------------
    |
    | DomPDF consumes significant memory when rendering many surveys in a single
    | document. Keep this value conservative for worker memory limits.
    |
    */
    'bulk_pdf' => [
        'surveys_per_part' => (int) env('SURVEY_BULK_PDF_SURVEYS_PER_PART', 20),
    ],

];
