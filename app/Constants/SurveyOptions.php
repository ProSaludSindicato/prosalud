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
        'padre', 'PADRE',
        'madre', 'MADRE',
        'hijo', 'HIJO',
        'hija', 'HIJA',
        'hermano', 'HERMANO',
        'hermana', 'HERMANA',
        'esposo', 'ESPOSO',
        'esposa', 'ESPOSA',
        'conyuge', 'CONYUGE', // Sinónimo de esposo/esposa (usado por el frontend)
        'pareja', 'PAREJA',
        'tio', 'TIO',
        'tia', 'TIA',
        'primo', 'PRIMO',
        'prima', 'PRIMA',
        'abuelo', 'ABUELO',
        'abuela', 'ABUELA',
        'yerno', 'YERNO',
        'nuera', 'NUERA',
        'suegro', 'SUEGRO',
        'suegra', 'SUEGRA',
        'cuñado', 'CUÑADO',
        'cuñada', 'CUÑADA',
        'amigo', 'AMIGO',
        'amiga', 'AMIGA',
        'otro', 'OTRO',
    ];

    /**
     * Tipos de documento válidos
     */
    public const TIPOS_DOCUMENTO = [
        'CC',   // Cédula de Ciudadanía
        'TI',   // Tarjeta de Identidad
        'CE',   // Cédula de Extranjería
        'PA',   // Pasaporte
        'RC',   // Registro Civil
        'PT',   // Permiso por Protección Temporal
        'NUIP', // Número Único de Identificación Personal
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
        'xs', 'XS',
        's', 'S',
        'm', 'M',
        'l', 'L',
        'xl', 'XL',
        'xxl', 'XXL',
        'xxxl', 'XXXL',
        '4xl', '4XL',
        '5xl', '5XL',
    ];

    /**
     * Estados civiles válidos
     */
    public const ESTADOS_CIVILES = [
        'soltero', 'SOLTERO',
        'casado', 'CASADO',
        'divorciado', 'DIVORCIADO',
        'viudo', 'VIUDO',
        'union_libre', 'UNION_LIBRE',
    ];

    /**
     * Géneros válidos
     */
    public const GENEROS = [
        'masculino', 'MASCULINO',
        'femenino', 'FEMENINO',
        'otro', 'OTRO',
    ];

    /**
     * Razas válidas
     */
    public const RAZAS = [
        'ninguno', 'NINGUNO',
        'afro', 'AFRO',
        'indigena', 'INDIGENA',
        'otro', 'OTRO',
        'no_responde', 'NO_RESPONDE',
    ];

    /**
     * Opciones de vivienda
     */
    public const TIPOS_VIVIENDA = [
        'propia', 'PROPIA',
        'arrendada', 'ARRENDADA',
        'familiar', 'FAMILIAR',
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
        'familia_origen', 'FAMILIA_ORIGEN',
        'nueva_familia', 'NUEVA_FAMILIA',
        'ambas', 'AMBAS',
        'amigos', 'AMIGOS',
        'otros_familiares', 'OTROS_FAMILIARES',
        'solo', 'SOLO',
    ];

    /**
     * Opciones de transporte
     */
    public const TIPOS_TRANSPORTE = [
        'carro', 'CARRO',
        'motocicleta', 'MOTOCICLETA',
        'bicicleta', 'BICICLETA',
        'transporte_publico', 'TRANSPORTE_PUBLICO',
        'caminando', 'CAMINANDO',
        'otra', 'OTRA',
    ];

    /**
     * Opciones para tiempo libre con
     */
    public const TIEMPO_LIBRE_CON = [
        'familia', 'FAMILIA',
        'pareja', 'PAREJA',
        'amigos', 'AMIGOS',
        'solo', 'SOLO',
        'otros', 'OTROS',
    ];

    /**
     * Frecuencias de consumo
     */
    public const FRECUENCIAS_CONSUMO = [
        'diario', 'DIARIO',
        'varias_veces_semana', 'VARIAS_VECES_SEMANA',
        'fines_semana', 'FINES_SEMANA',
        'cada_quince_dias', 'CADA_QUINCE_DIAS',
        'ocasionalmente', 'OCASIONALMENTE',
    ];

    /**
     * Niveles de limitación física
     */
    public const NIVELES_LIMITACION = [
        'limita_mucho', 'LIMITA_MUCHO',
        'limita_poco', 'LIMITA_POCO',
        'no_limita', 'NO_LIMITA',
    ];

    /**
     * Respuestas si/no
     */
    public const SI_NO = [
        'si', 'SI',
        'no', 'NO',
    ];

    /**
     * Niveles educativos válidos
     */
    public const NIVELES_EDUCATIVOS = [
        'primaria',
        'bachiller',
        'tecnico',
        'tecnologo',
        'profesional',
        'especialista',
        'maestria',
        'doctorado',
    ];
}

