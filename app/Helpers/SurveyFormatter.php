<?php

namespace App\Helpers;

use App\Support\HospitalCatalog;
use Carbon\Carbon;

class SurveyFormatter
{
    public static function formatDireccionConBarrio(?string $direccion, ?string $barrio): string
    {
        $direccion = trim((string) ($direccion ?? ''));
        $barrio = trim((string) ($barrio ?? ''));

        if ($direccion !== '' && $barrio !== '') {
            return $direccion.', '.$barrio;
        }

        return $direccion !== '' ? $direccion : $barrio;
    }

    /**
     * Formatear valores Sí/No.
     */
    public static function formatSiNo($value): string
    {
        if ($value === null || $value === '') {
            return 'No especificado';
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['si', 'sí', 'yes', 'true', '1', '1.0'])) {
            return 'Sí';
        }

        if (in_array($normalized, ['no', 'false', '0', '0.0'])) {
            return 'No';
        }

        return 'No especificado';
    }

    /**
     * Transformar tipo de encuesta.
     */
    public static function getSurveyTypeDisplayName(?string $value): string
    {
        if (! $value || $value === 'active_affiliate') {
            return 'Afiliados Activos';
        }

        if ($value === 'new_entry') {
            return 'Nuevo Ingreso';
        }

        return 'Afiliados Activos';
    }

    /**
     * Transformar tipo de documento.
     */
    public static function getTipoDocumentoDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'CC' => 'Cédula de Ciudadanía',
            'TI' => 'Tarjeta de Identidad',
            'CE' => 'Cédula de Extranjería',
            'PA' => 'Pasaporte',
            'PT' => 'Permiso por Protección Temporal',
            'RC' => 'Registro Civil',
            'NUIP' => 'Número Único de Identificación Personal',
        ];

        return $map[strtoupper(trim($value))] ?? $value;
    }

    /**
     * Transformar género.
     */
    public static function getGeneroDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'masculino' => 'Masculino',
            'femenino' => 'Femenino',
            'otro' => 'Otro',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar estado civil.
     */
    public static function getEstadoCivilDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'soltero' => 'Soltero(a)',
            'casado' => 'Casado(a)',
            'divorciado' => 'Divorciado(a)',
            'viudo' => 'Viudo(a)',
            'union_libre' => 'Unión libre',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar nivel educativo.
     */
    public static function getNivelEducativoDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'primaria' => 'Primaria',
            'bachiller' => 'Bachiller',
            'tecnico' => 'Técnico',
            'tecnologo' => 'Tecnólogo',
            'profesional' => 'Profesional',
            'especialista' => 'Especialista',
            'maestria' => 'Maestría',
            'doctorado' => 'Doctorado',
        ];

        return $map[strtolower(trim($value))] ?? ucfirst($value);
    }

    /**
     * Transformar raza/grupo étnico.
     */
    public static function getRazaDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'ninguno' => 'Ninguno',
            'afro' => 'Afrocolombiano',
            'indigena' => 'Indígena',
            'otro' => 'Otro',
            'no_responde' => 'Prefiere no responder',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar tipo de vivienda.
     */
    public static function getViviendaDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'propia' => 'Propia',
            'arrendada' => 'Arrendada',
            'familiar' => 'Familiar',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar convive con.
     */
    public static function getConviveConDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'familia_origen' => 'Familia de origen',
            'nueva_familia' => 'Nueva familia (cónyuge e hijos)',
            'ambas' => 'Las dos anteriores',
            'amigos' => 'Amigos',
            'otros_familiares' => 'Otros familiares',
            'solo' => 'Vive solo',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar transporte.
     */
    public static function getTransporteDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'carro' => 'Carro',
            'motocicleta' => 'Motocicleta',
            'bicicleta' => 'Bicicleta',
            'transporte_publico' => 'Transporte público',
            'caminando' => 'Caminando',
            'otra' => 'Otra',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar tiempo libre con.
     */
    public static function getTiempoLibreConDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'familia' => 'Con la familia',
            'pareja' => 'Con la pareja',
            'amigos' => 'Con amigos',
            'solo' => 'Solo',
            'otros' => 'Otros',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar relación contacto emergencia.
     */
    public static function getRelacionContactoEmergenciaDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'conyuge' => 'Cónyuge',
            'esposo' => 'Cónyuge',
            'esposa' => 'Cónyuge',
            'padre' => 'Padre',
            'madre' => 'Madre',
            'hijo' => 'Hijo/a',
            'hija' => 'Hijo/a',
            'hermano' => 'Hermano/a',
            'hermana' => 'Hermano/a',
            'abuelo' => 'Abuelo/a',
            'abuela' => 'Abuelo/a',
            'tio' => 'Tío/a',
            'tia' => 'Tío/a',
            'primo' => 'Primo/a',
            'prima' => 'Primo/a',
            'amigo' => 'Amigo/a',
            'amiga' => 'Amigo/a',
            'otro' => 'Otro',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar frecuencia.
     */
    public static function getFrecuenciaDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'diario' => 'Diario',
            'varias_veces_semana' => 'Varias veces en la semana',
            'fines_semana' => 'Fines de semana',
            'cada_quince_dias' => 'Cada quince días',
            'ocasionalmente' => 'Ocasionalmente',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Transformar talla vestimenta.
     */
    public static function getTallaVestimentaDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'xs' => 'XS',
            's' => 'S',
            'm' => 'M',
            'l' => 'L',
            'xl' => 'XL - Extra Grande',
            'xxl' => 'XXL',
            'xxxl' => 'XXXL',
            '4xl' => '4XL',
            '5xl' => '5XL',
        ];

        return $map[strtolower(trim($value))] ?? strtoupper($value);
    }

    /**
     * Transformar país de nacimiento.
     */
    public static function getPaisDisplayName(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $map = [
            'colombia' => 'Colombia',
            'venezuela' => 'Venezuela',
            'ecuador' => 'Ecuador',
            'peru' => 'Perú',
            'brasil' => 'Brasil',
            'argentina' => 'Argentina',
            'chile' => 'Chile',
            'panama' => 'Panamá',
            'costa_rica' => 'Costa Rica',
            'nicaragua' => 'Nicaragua',
            'honduras' => 'Honduras',
            'guatemala' => 'Guatemala',
            'el_salvador' => 'El Salvador',
            'mexico' => 'México',
            'cuba' => 'Cuba',
            'republica_dominicana' => 'República Dominicana',
            'puerto_rico' => 'Puerto Rico',
            'bolivia' => 'Bolivia',
            'paraguay' => 'Paraguay',
            'uruguay' => 'Uruguay',
            'estados_unidos' => 'Estados Unidos',
            'canada' => 'Canadá',
            'espana' => 'España',
            'francia' => 'Francia',
            'italia' => 'Italia',
            'alemania' => 'Alemania',
            'reino_unido' => 'Reino Unido',
            'portugal' => 'Portugal',
            'holanda' => 'Holanda',
            'belgica' => 'Bélgica',
            'suiza' => 'Suiza',
            'australia' => 'Australia',
            'nueva_zelanda' => 'Nueva Zelanda',
            'japon' => 'Japón',
            'china' => 'China',
            'india' => 'India',
            'rusia' => 'Rusia',
            'corea_del_sur' => 'Corea del Sur',
            'filipinas' => 'Filipinas',
            'indonesia' => 'Indonesia',
            'tailandia' => 'Tailandia',
            'singapur' => 'Singapur',
            'malasia' => 'Malasia',
            'vietnam' => 'Vietnam',
            'israel' => 'Israel',
            'turquia' => 'Turquía',
            'egipto' => 'Egipto',
            'sudafrica' => 'Sudáfrica',
            'nigeria' => 'Nigeria',
            'kenia' => 'Kenia',
            'marruecos' => 'Marruecos',
            'argelia' => 'Argelia',
            'tunez' => 'Túnez',
            'otro' => 'Otro',
        ];

        return $map[strtolower(trim($value))] ?? ucfirst(str_replace('_', ' ', $value));
    }

    /**
     * Transformar limitación física.
     */
    public static function formatLimitacion(?string $value): string
    {
        if (! $value) {
            return 'No especificado';
        }

        $map = [
            'limita_mucho' => 'Me limita mucho',
            'limita_poco' => 'Me limita un poco',
            'no_limita' => 'No me limita nada',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Formatear fecha (sin hora).
     */
    public static function formatDate($dateString): string
    {
        if (! $dateString) {
            return 'No especificado';
        }

        try {
            $date = Carbon::parse($dateString);
            $months = [
                1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
                5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
                9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
            ];

            return $date->day.' de '.$months[$date->month].', '.$date->year;
        } catch (\Exception $e) {
            return (string) $dateString;
        }
    }

    /**
     * Formatear fecha con hora.
     */
    public static function formatDateTime($dateString): string
    {
        if (! $dateString) {
            return 'No especificado';
        }

        try {
            $date = Carbon::parse($dateString);
            $months = [
                1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
                5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
                9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
            ];

            return $date->day.' de '.$months[$date->month].', '.$date->year.' a las '.$date->format('H:i');
        } catch (\Exception $e) {
            return (string) $dateString;
        }
    }

    /**
     * Obtener nombre de condición de salud.
     */
    public static function getCondicionSaludLabel(string $key): string
    {
        $labels = [
            'accidenteLaboral' => 'Accidente Laboral',
            'accidenteTransitoCasero' => 'Accidente Tránsito Casero',
            'alergias' => 'Alergias',
            'antecedentesMedicosMentales' => 'Antecedentes Médicos Mentales',
            'cancer' => 'Cáncer',
            'cirugias' => 'Cirugías',
            'depresionBipolaridad' => 'Depresión Bipolaridad',
            'diabetes' => 'Diabetes',
            'doloresArticulares' => 'Dolores Articulares',
            'enfermedadesCorazon' => 'Enfermedades Corazón',
            'epilepsiaConvulsiones' => 'Epilepsia Convulsiones',
            'hipertensionArterial' => 'Hipertensión Arterial',
            'medicamentoPermanente' => 'Medicamento Permanente',
            'otraEnfermedad' => 'Otra Enfermedad',
            'problemasPulmonares' => 'Problemas Pulmonares',
            'problemasRenales' => 'Problemas Renales',
            'problemasSangre' => 'Problemas Sangre',
            'problemasVisuales' => 'Problemas Visuales',
            'protesisArticular' => 'Prótesis Articular',
            'sobrepesoObesidad' => 'Sobrepeso Obesidad',
            'trasplante' => 'Trasplante',
            'tratamientoMedico' => 'Tratamiento Médico',
            'tuberculosis' => 'Tuberculosis',
            'vacunadoCovid' => 'Vacunado Covid',
        ];

        return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Transformar código de hospital a nombre legible.
     */
    public static function getHospitalDisplayName(?string $value): string
    {
        return HospitalCatalog::resolve($value);
    }
}
