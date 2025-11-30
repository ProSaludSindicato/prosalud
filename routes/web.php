<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
});

// Ruta temporal para probar generación de certificados
Route::get('/test-certificado', function () {
    return view('test-certificado');
});

// Ruta temporal para consulta/validación de certificados
Route::get('/consulta-certificado', function () {
    return view('consulta-certificado');
});
