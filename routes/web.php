<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
});

// Ruta temporal para probar generación de certificados
Route::get('/test-certificado', function () {
    return view('test-certificado');
});
