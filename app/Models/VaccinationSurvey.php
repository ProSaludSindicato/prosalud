<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VaccinationSurvey extends Model
{
    use HasFactory;

    protected $fillable = [
        'tipo_documento',
        'numero_documento',
        'fecha_nacimiento',
        'primer_nombre',
        'segundo_nombre',
        'primer_apellido',
        'segundo_apellido',
        'fecha_aplicacion_srp',
        'fecha_aplicacion_sr',
        'fecha_aplicacion_fiebre_amarilla',
        'firma_path',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
        'fecha_aplicacion_srp' => 'date',
        'fecha_aplicacion_sr' => 'date',
        'fecha_aplicacion_fiebre_amarilla' => 'date',
    ];
}
