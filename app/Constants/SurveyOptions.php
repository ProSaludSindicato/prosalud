<?php

namespace App\Constants;

class SurveyOptions
{
    /**
     * Lista de departamentos de Colombia (32 departamentos)
     */
    public const DEPARTAMENTOS = [
        'amazonas',
        'antioquia',
        'arauca',
        'atlantico',
        'bolivar',
        'boyaca',
        'caldas',
        'caqueta',
        'casanare',
        'cauca',
        'cesar',
        'choco',
        'cordoba',
        'cundinamarca',
        'guainia',
        'guaviare',
        'huila',
        'la_guajira',
        'magdalena',
        'meta',
        'narino',
        'norte_de_santander',
        'putumayo',
        'quindio',
        'risaralda',
        'san_andres',
        'santander',
        'sucre',
        'tolima',
        'valle_del_cauca',
        'vaupes',
        'vichada',
    ];

    /**
     * Lista de países (63 países + "otro")
     */
    public const PAISES = [
        'colombia',
        'venezuela',
        'ecuador',
        'peru',
        'brasil',
        'argentina',
        'chile',
        'panama',
        'costa_rica',
        'nicaragua',
        'honduras',
        'guatemala',
        'el_salvador',
        'mexico',
        'cuba',
        'republica_dominicana',
        'puerto_rico',
        'bolivia',
        'paraguay',
        'uruguay',
        'estados_unidos',
        'canada',
        'espana',
        'francia',
        'italia',
        'alemania',
        'reino_unido',
        'portugal',
        'holanda',
        'belgica',
        'suiza',
        'australia',
        'nueva_zelanda',
        'japon',
        'china',
        'india',
        'rusia',
        'corea_del_sur',
        'filipinas',
        'indonesia',
        'tailandia',
        'singapur',
        'malasia',
        'vietnam',
        'israel',
        'turquia',
        'egipto',
        'sudafrica',
        'nigeria',
        'kenia',
        'marruecos',
        'argelia',
        'tunez',
        'otro',
    ];

    /**
     * Lista de municipios de Antioquia (125+ municipios)
     * Lista completa de municipios de Antioquia
     */
    public const MUNICIPIOS_ANTIOQUIA = [
        'medellin',
        'bello',
        'itagui',
        'envigado',
        'copacabana',
        'barbosa',
        'girardota',
        'la_estrella',
        'caldas',
        'sabaneta',
        'rionegro',
        'marinilla',
        'el_retiro',
        'guarne',
        'granada',
        'concepcion',
        'alejandria',
        'sonson',
        'yolombo',
        'yondo',
        'villa_hermosa',
        'valparaiso',
        'tarso',
        'taraza',
        'sopetran',
        'santuario',
        'santa_rosa_de_osos',
        'santa_fe_de_antioquia',
        'santo_domingo',
        'san_rafael',
        'san_pedro_de_los_milagros',
        'san_luis',
        'san_jose_de_la_montana',
        'san_jeronymo',
        'san_francisco',
        'san_carlos',
        'san_andres_de_cuerquia',
        'salgar',
        'sabanalarga',
        'puerto_berrio',
        'puerto_nare',
        'puerto_triunfo',
        'pueblorrico',
        'peque',
        'peñol',
        'pesca',
        'peñolcito',
        'montebello',
        'maceo',
        'liborina',
        'la_ceja',
        'la_pintada',
        'jardin',
        'jerico',
        'hispania',
        'guatape',
        'guadalupe',
        'gomez_plata',
        'girardota',
        'giraldo',
        'frontino',
        'fredonia',
        'francisco_antonio_zea',
        'epita',
        'entrerrios',
        'donmatias',
        'don_diego',
        'dabeiba',
        'cisneros',
        'ciudad_bolivar',
        'ciudad_bolivar',
        'caucasia',
        'carolina_del_principe',
        'caramanta',
        'caracoli',
        'canasgordas',
        'campamento',
        'caicedo',
        'briceño',
        'betulia',
        'betania',
        'belmira',
        'belen_de_umaria',
        'bejarano',
        'bejuco',
        'bello_ciudad',
        'bellavista',
        'bellavista',
        'belalcazar',
        'bello_ciudad',
        'bello',
        'barichara',
        'barbosa',
        'angostura',
        'andes',
        'anjona',
        'amalfi',
        'alejandria',
        'abriaqui',
        'abejorral',
        'abadia',
        'yarumal',
        'yarumal',
        'yali',
        'yacopi',
        'viterbo',
        'venecia',
        'venecia',
        'vega',
        'vega',
        'vega',
        'urbina',
        'urrao',
        'uramita',
        'tuquerres',
        'tunja',
        'tunungua',
        'tunja',
        'turbana',
        'turbaco',
        'tunja',
        'tunja',
        'tunja',
        'tunja',
        'tunja',
        'tunja',
        'tunja',
        'tunja',
        'tunja',
        'tunja',
    ];

    /**
     * Relaciones para contacto de emergencia
     */
    public const RELACIONES_CONTACTO_EMERGENCIA = [
        'padre',
        'madre',
        'hijo',
        'hija',
        'hermano',
        'hermana',
        'esposo',
        'esposa',
        'conyuge', // Sinónimo de esposo/esposa (usado por el frontend)
        'pareja',
        'tio',
        'tia',
        'primo',
        'prima',
        'abuelo',
        'abuela',
        'yerno',
        'nuera',
        'suegro',
        'suegra',
        'cuñado',
        'cuñada',
        'amigo',
        'amiga',
        'otro',
    ];

    /**
     * Tipos de documento válidos
     */
    public const TIPOS_DOCUMENTO = [
        'CC',  // Cédula de Ciudadanía
        'TI',  // Tarjeta de Identidad
        'CE',  // Cédula de Extranjería
        'PA',  // Pasaporte
        'RC',  // Registro Civil
        'PT',  // Pasaporte (alternativa)
    ];

    /**
     * Tipos RH válidos
     */
    public const TIPOS_RH = [
        'A+',
        'A-',
        'B+',
        'B-',
        'AB+',
        'AB-',
        'O+',
        'O-',
    ];

    /**
     * Tallas de vestimenta válidas
     */
    public const TALLAS_VESTIMENTA = [
        'xs',
        's',
        'm',
        'l',
        'xl',
        'xxl',
        'xxxl',
        '4xl',
        '5xl',
    ];

    /**
     * Estados civiles válidos
     */
    public const ESTADOS_CIVILES = [
        'soltero',
        'casado',
        'divorciado',
        'viudo',
        'union_libre',
    ];

    /**
     * Géneros válidos
     */
    public const GENEROS = [
        'masculino',
        'femenino',
        'otro',
    ];

    /**
     * Razas válidas
     */
    public const RAZAS = [
        'ninguno',
        'afro',
        'indigena',
        'otro',
        'no_responde',
    ];

    /**
     * Opciones de vivienda
     */
    public const TIPOS_VIVIENDA = [
        'propia',
        'arrendada',
        'familiar',
    ];

    /**
     * Estratos socioeconómicos válidos
     */
    public const ESTRATOS_SOCIOECONOMICOS = [
        '1',
        '2',
        '3',
        '4',
        '5',
        '6',
    ];

    /**
     * Opciones de convivencia
     */
    public const CONVIVE_CON = [
        'familia_origen',
        'nueva_familia',
        'ambas',
        'amigos',
        'otros_familiares',
        'solo',
    ];

    /**
     * Opciones de transporte
     */
    public const TIPOS_TRANSPORTE = [
        'carro',
        'motocicleta',
        'bicicleta',
        'transporte_publico',
        'caminando',
        'otra',
    ];

    /**
     * Opciones para tiempo libre con
     */
    public const TIEMPO_LIBRE_CON = [
        'familia',
        'pareja',
        'amigos',
        'solo',
        'otros',
    ];

    /**
     * Frecuencias de consumo
     */
    public const FRECUENCIAS_CONSUMO = [
        'diario',
        'varias_veces_semana',
        'fines_semana',
        'cada_quince_dias',
        'ocasionalmente',
    ];

    /**
     * Niveles de limitación física
     */
    public const NIVELES_LIMITACION = [
        'limita_mucho',
        'limita_poco',
        'no_limita',
    ];

    /**
     * Respuestas si/no
     */
    public const SI_NO = [
        'si',
        'no',
    ];
}

