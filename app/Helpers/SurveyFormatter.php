<?php

namespace App\Helpers;

use Carbon\Carbon;

class SurveyFormatter
{
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
        if (!$value || $value === 'active_affiliate') {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$value) {
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
        if (!$dateString) {
            return 'No especificado';
        }

        try {
            $date = Carbon::parse($dateString);
            $months = [
                1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
                5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
                9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
            ];
            
            return $date->day . ' de ' . $months[$date->month] . ', ' . $date->year;
        } catch (\Exception $e) {
            return (string) $dateString;
        }
    }

    /**
     * Formatear fecha con hora.
     */
    public static function formatDateTime($dateString): string
    {
        if (!$dateString) {
            return 'No especificado';
        }

        try {
            $date = Carbon::parse($dateString);
            $months = [
                1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
                5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
                9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
            ];
            
            return $date->day . ' de ' . $months[$date->month] . ', ' . $date->year . ' a las ' . $date->format('H:i');
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
        if (!$value) {
            return '';
        }

        $map = [
            'ABEJORRAL' => 'E.S.E. Hospital San Juan de Dios - Abejorral',
            'ABEJORRAL - ADMON' => 'E.S.E. Hospital San Juan de Dios - Abejorral',
            'ABEJORRAL - ADMON ' => 'E.S.E. Hospital San Juan de Dios - Abejorral',
            'ABEJORRAL - ASIST' => 'E.S.E. Hospital San Juan de Dios - Abejorral',
            'ABEJORRAL - BUEN COMIENZO' => 'E.S.E. Hospital San Juan de Dios Abejorral - Programa Buen Comienzo',
            'ABEJORRAL - CBA' => 'E.S.E. Hospital San Juan de Dios - Abejorral',
            'ABEJORRAL - SALUD P' => 'E.S.E. Hospital San Juan de Dios Abejorral - Programa Salud Pública',
            'ABEJORRAL SP' => 'E.S.E. Hospital San Juan de Dios - Abejorral',
            'ADMON' => 'Sede Administrativa',
            'ADMON-HSJDRionegro' => 'E.S.E. Hospital San Juan de Dios - Rionegro',
            'BARBOSA' => 'E.S.E. Hospital San Vicente de Paul de Barbosa (Ant)',
            'BELLO' => 'E.S.E. Hospital Marco Fidel Suarez de Bello',
            'BETANIA' => 'E.S.E. Hospital San Antonio de Betania',
            'CALDAS' => 'E.S.E. Hospital San Vicente de Paúl de Caldas',
            'CENTRO NEUROLOGICO' => 'Centro Neurológico',
            'CISNEROS' => 'E.S.E. Hospital San Antonio - Cisneros (Ant)',
            'CIUDAD BOLIVAR' => 'E.S.E. Hospital La Merced - Ciudad Bolivar (Ant)',
            'CIUDADBOLIVAR' => 'E.S.E. Hospital La Merced - Ciudad Bolivar (Ant)',
            'COPACABANA' => 'E.S.E. Hospital Santa Margarita',
            'COPACABANA ' => 'E.S.E. Hospital Santa Margarita',
            'E.S.E CARISMA ADMON ' => 'E.S.E. Hospital Carisma',
            'E.S.E CARISMA ASISTENCIAL' => 'E.S.E. Hospital Carisma',
            'E.S.ECARISMA' => 'E.S.E. Hospital Carisma',
            'FREDONIA' => 'E.S.E. Hospital Santa Lucia - Fredonia (Ant)',
            'HGM SEDE 80 ADMON' => 'E.S.E. Hospital General de Medellín - Sede 80',
            'HGM SEDE 80 ASISTENCIAL' => 'E.S.E. Hospital General de Medellín - Sede 80',
            'HGM SEDE 80 ASISTENCIAL ' => 'E.S.E. Hospital General de Medellín - Sede 80',
            'HLM - GRUPO 1' => 'E.S.E. Hospital La María',
            'HLM - GRUPO 2' => 'E.S.E. Hospital La María',
            'HLM - GRUPO 3' => 'E.S.E. Hospital La María',
            'HMFS - BELLO' => 'E.S.E. Hospital Marco Fidel Suarez de Bello',
            'HSJD Rionegro - ADMON' => 'E.S.E. Hospital San Juan de Dios - Rionegro',
            'HSJD Rionegro - ASISTENCIAL' => 'Centro Neurológico',
            'HSJD Rionegro - PIC ' => 'E.S.E. Hospital San Antonio - Cisneros (Ant)',
            'HSJDRionegro' => 'E.S.E. Hospital San Juan de Dios - Rionegro',
            'HSRI' => 'E.S.E. Hospital San Rafael de Itagüí',
            'HSRI ' => 'E.S.E. Hospital San Rafael de Itagüí',
            'JARDIN' => 'E.S.E. Hospital Gabriel Peláez Montoya',
            'LA MARIA' => 'E.S.E. Hospital La María',
            'LA MARIA - 000065-2021' => 'E.S.E. Hospital La María',
            'LA MARIA - 262-2021' => 'E.S.E. Hospital La María',
            'LA MARIA - COOSALUD' => 'E.S.E. Hospital La María',
            'LA MARIA - ENTERRITORIO' => 'E.S.E. Hospital La María',
            'LA MARIA - ENTERRITORIO 1 - 044' => 'E.S.E. Hospital La María',
            'LA MARIA - ENTERRITORIO 2' => 'E.S.E. Hospital La María',
            'LA MARIA - ENTERRITORIO 2 - 045' => 'E.S.E. Hospital La María',
            'LA MARIA - INFECCIOSA PS 268' => 'E.S.E. Hospital La María',
            'LA MARIA - ITS 257' => 'E.S.E. Hospital La María',
            'LA MARIA - PROGRAMA ESPECIAL SAVIA SALUD EPS - VIH-SIDA' => 'E.S.E. Hospital La María',
            'LA MARIA - TRANSMISIBLES' => 'E.S.E. Hospital La María',
            'LA MARIA - TRANSMISIBLES - 122 - 2023' => 'E.S.E. Hospital La María',
            'LA MARIA - TRANSMISIBLES 176' => 'E.S.E. Hospital La María',
            'LA MARIA - UNION TEMPORAL' => 'E.S.E. Hospital La María',
            'LA MARIA - UNION TEMPORAL 020 - 2023' => 'E.S.E. Hospital La María',
            'LA MARIA - VIH' => 'E.S.E. Hospital La María',
            'LA MARIA - VIH - 1' => 'E.S.E. Hospital La María',
            'LA MARIA 216 - 2021' => 'E.S.E. Hospital La María',
            'LA MARIA 317 COOSALUD' => 'E.S.E. Hospital La María',
            'LA MARIA COOSALUD - 046' => 'E.S.E. Hospital La María',
            'LA MARIA COOSALUD 191' => 'E.S.E. Hospital La María',
            'LA MARIA COOSALUD 36-2022' => 'E.S.E. Hospital La María',
            'LA MARIA ENTERRITORIO - 287' => 'E.S.E. Hospital La María',
            'LA MARIA ENTERRITORIO 038' => 'E.S.E. Hospital La María',
            'LA MARIA ENTERRITORIO 238' => 'E.S.E. Hospital La María',
            'LA MARIA- INFECCIOSA PS 268' => 'E.S.E. Hospital La María',
            'LA MARIA ITS ' => 'E.S.E. Hospital La María',
            'LA MARIA ITS 127' => 'E.S.E. Hospital La María',
            'LA MARIA ITS- 376' => 'E.S.E. Hospital La María',
            'LA MARIA PAI ' => 'E.S.E. Hospital La María',
            'LA MARIA TB 137' => 'E.S.E. Hospital La María',
            'LA MARIA TB Y LEPRA  319-2021' => 'E.S.E. Hospital La María',
            'LA MARIA TBC' => 'E.S.E. Hospital La María',
            'LA MARIA TRANSMISIBLES - 122' => 'E.S.E. Hospital La María',
            'LA MARIA TRANSMISIBLES - 275' => 'E.S.E. Hospital La María',
            'LA MARIA TRANSMISIBLES 234' => 'E.S.E. Hospital La María',
            'LA MARIA UPAI - 0028 - 2023' => 'E.S.E. Hospital La María',
            'LA MARIA UPAI - 140 - 2023' => 'E.S.E. Hospital La María',
            'LA MARIA UPAI - 271' => 'E.S.E. Hospital La María',
            'LA MARIA UPAI 0028 - 2023' => 'E.S.E. Hospital La María',
            'LA MARIA UPAI 245' => 'E.S.E. Hospital La María',
            'LA MARIA UPAI 35' => 'E.S.E. Hospital La María',
            'LA MARIA VIH - 158' => 'E.S.E. Hospital La María',
            'LA MARIA VIH 037' => 'E.S.E. Hospital La María',
            'LA MARIA VIH 131' => 'E.S.E. Hospital La María',
            'LA MARIA VIH 131 - 2023' => 'E.S.E. Hospital La María',
            'LA MARIA VIH 158' => 'E.S.E. Hospital La María',
            'LA MARIA VIH 188' => 'E.S.E. Hospital La María',
            'LA MARIA VIH N°043' => 'E.S.E. Hospital La María',
            'LA MARIA VIH UT ' => 'E.S.E. Hospital La María',
            'LAMARIACOOSALUD36' => 'E.S.E. Hospital La María',
            'LAMARIAENTERRITORIO038' => 'E.S.E. Hospital La María',
            'LAMARIAITS127' => 'E.S.E. Hospital La María',
            'LAMARIATB2022' => 'E.S.E. Hospital La María',
            'LAMARIAUPAI35' => 'E.S.E. Hospital La María',
            'LAMARIAVIH037' => 'E.S.E. Hospital La María',
            'POLICLINICO' => 'POLICLINICO',
            'PROMOTORA MEDICA Y ODONTOLOGICA DE ANTIOQUIA S.A.' => 'PROMOTORA MEDICA Y ODONTOLOGICA DE ANTIOQUIA S.A.',
            'PUERTO BERRIO' => 'E.S.E. Hospital La Cruz',
            'SOMER' => 'SOMER',
            'STA GERTRUDIS' => 'E.S.E. Santa Gertrudis',
            'UNION TEMPORAL - 020 - 2023' => 'E.S.E. Hospital La María',
            'VENANCIO' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
            'VENANCIO -  SALUD MENTAL ' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
            'VENANCIO - ADMON' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
            'VENANCIO - ASIST' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
            'VENANCIO - ASIST ' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
            'VENANCIO - PIC ' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
            'VENANCIO - SALUD MENTAL ' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
            'VENANCIO - SALUD P.' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
            'VENANCIO - UCI' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
            'VENANCIO ADMON - APH' => 'E.S.E. Hospital Venancio Diaz Diaz (Sabaneta)',
            'VENECIA' => 'ESE Hospital San Rafael de Venecia',
        ];

        // Normalizar el valor (trim y buscar en el mapa)
        $normalized = trim($value);
        
        // Buscar coincidencia exacta primero
        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        // Si no hay coincidencia, retornar el valor original
        return $value;
    }
}

