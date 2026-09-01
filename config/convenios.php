<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modo de entrega de convenios
    |--------------------------------------------------------------------------
    |
    | production: envía correos a los afiliados como flujo normal.
    | cualquier otro valor: mismo flujo (PDF o enlace de firma), pero los correos
    | van al usuario autenticado, el historial queda marcado como TEST y se puede
    | limpiar con `php artisan convenios:clean-test-tracking`.
    |
    */
    'delivery_mode' => env('CONVENIO_DELIVERY_MODE', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Disco de almacenamiento de PDFs
    |--------------------------------------------------------------------------
    |
    | En Laravel Cloud, prosalud-private es el bucket S3 privado. Fuera de Cloud
    | el disco cae a almacenamiento local para desarrollo y tests.
    |
    */
    'storage_disk' => env('CONVENIO_STORAGE_DISK', 'prosalud-private'),

];
