<?php

use Illuminate\Support\Facades\Mail;
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

Route::get('/ses-test', function () {
    Mail::raw('SES OK', function ($m) {
        $m->to('juanpapabon@gmail.com')
            ->subject('SES Test OK');
    });

    return 'sent';
});
