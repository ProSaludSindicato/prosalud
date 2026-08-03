<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modo de entrega de convenios
    |--------------------------------------------------------------------------
    |
    | production: envía correos a los afiliados como flujo normal.
    | test: generación disponible para descarga del usuario; reenvíos al correo
    |       del usuario autenticado que realiza la solicitud.
    |
    */
    'delivery_mode' => env('CONVENIO_DELIVERY_MODE', 'production'),

];
