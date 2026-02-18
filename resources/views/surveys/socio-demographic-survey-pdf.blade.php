<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Encuesta Sociodemográfica - {{ $survey->id }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-size: 9px;
            line-height: 1.3;
            color: #1e293b;
            background: white;
            padding: 20px;
        }

        body * {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
        }

        strong {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-weight: 600;
        }

        .container {
            max-width: 100%;
            background: white;
            padding: 20px;
        }

        .header {
            border-bottom: 2px solid #00529B;
            padding-bottom: 10px;
            margin-bottom: 12px;
            position: relative;
        }

        .header h1 {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            color: #00529B;
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .header-logo {
            position: absolute;
            top: 0;
            right: 0;
            max-width: 80px;
            max-height: 60px;
        }

        .header-generated-date {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-size: 7px;
            color: #64748b;
            margin-top: 4px;
        }

        .header-info {
            display: table;
            width: 100%;
            table-layout: fixed;
            margin-top: 16px;
        }

        .header-info-row {
            display: table-row;
        }

        .header-info-item {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            display: table-cell;
            padding: 2px 8px 2px 0;
            font-size: 8px;
            vertical-align: top;
        }

        .badge {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 8px;
            font-weight: 600;
            border: 1px solid #cbd5e1;
        }

        .badge-new-entry {
            background-color: #fff7ed;
            color: #c2410c;
            border-color: #fed7aa;
        }

        .badge-active {
            background-color: #E3F2FD;
            color: #003A70;
            border-color: #00529B;
        }

        .section {
            margin-bottom: 10px;
            page-break-inside: avoid;
        }

        .section-title {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-size: 11px;
            font-weight: bold;
            color: #00529B;
            margin-bottom: 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e2e8f0;
        }

        .grid {
            display: table;
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
        }

        .grid-row {
            display: table-row;
        }

        .field {
            display: table-cell;
            padding: 4px 8px 4px 0;
            vertical-align: top;
            width: 33.33%;
        }

        .field-4cols {
            width: 25%;
        }

        .field-2cols {
            width: 50%;
        }

        .field-half {
            width: 50%;
        }

        .field-label {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-size: 7px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 2px;
            text-transform: uppercase;
            line-height: 1.2;
        }

        .field-value {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-size: 9px;
            color: #1e293b;
            word-wrap: break-word;
            line-height: 1.3;
        }

        .field-value-bold {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-weight: 600;
        }

        .si-no {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            display: inline-block;
            font-weight: 600;
            font-size: 8px;
        }

        .si-no.si {
            color: #16a34a;
        }

        .si-no.no {
            color: #dc2626;
        }

        .si-no.no-especificado {
            color: #64748b;
        }

        .badge-item {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            display: inline-block;
            padding: 1px 4px;
            margin: 1px;
            background: #E3F2FD;
            color: #003A70;
            border-radius: 2px;
            font-size: 7px;
            border: 1px solid #00529B;
        }

        .signature-container {
            margin-top: 8px;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            background: #f8fafc;
            text-align: center;
        }

        .signature-image {
            max-width: 250px;
            max-height: 100px;
            margin: 5px 0;
        }

        .no-signature {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            color: #64748b;
            font-style: italic;
            padding: 10px;
            font-size: 8px;
        }

        .children-section {
            margin-top: 6px;
            padding: 6px;
            background: #f8fafc;
            border-radius: 3px;
            border: 1px solid #e2e8f0;
        }

        .child-item {
            margin-bottom: 6px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
        }

        .child-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .details-section {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
        }

        .details-title {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-size: 9px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 6px;
        }

        .two-columns {
            width: 50%;
        }

        .four-columns {
            width: 25%;
        }

        @page {
            margin: 2cm 1.5cm;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            @if($logoBase64)
            <img src="data:image/png;base64,{{ $logoBase64 }}" alt="ProSalud" class="header-logo" />
            @endif
            <h1>Encuesta Sociodemográfica</h1>
            <div class="header-info">
                <div class="header-info-row">
                    <div class="header-info-item"><strong>ID:</strong> <span style="font-family: monospace;">{{ $survey->id }}</span></div>
                    <div class="header-info-item"><strong></strong> 
                        <span class="badge {{ $survey->survey_type === 'new_entry' ? 'badge-new-entry' : 'badge-active' }}">
                            Tipo: {{ \App\Helpers\SurveyFormatter::getSurveyTypeDisplayName($survey->survey_type) }}
                        </span>
                    </div>
                    <div class="header-info-item"><strong>Fecha Registro:</strong> {{ \App\Helpers\SurveyFormatter::formatDateTime($survey->created_at) }}</div>
                </div>
            </div>
            @if(isset($generatedAt))
            <div class="header-generated-date">
                <strong>Fecha de Generación del Reporte:</strong> {{ \App\Helpers\SurveyFormatter::formatDateTime($generatedAt) }}
            </div>
            @endif
        </div>

        <!-- Información General -->
        <div class="section">
            <div class="section-title">Información General</div>
            <div class="grid">
                <div class="grid-row">
                    @if($survey->nombres || $survey->apellidos)
                    <div class="field field-4cols">
                        <div class="field-label">Nombre Completo</div>
                        <div class="field-value field-value-bold">{{ trim(($survey->nombres ?? '') . ' ' . ($survey->apellidos ?? '')) }}</div>
                    </div>
                    @endif
                    <div class="field field-4cols">
                        <div class="field-label">Documento</div>
                        <div class="field-value">
                            <strong>{{ \App\Helpers\SurveyFormatter::getTipoDocumentoDisplayName($survey->tipo_documento) }}</strong>
                            <span style="font-family: monospace; margin-left: 4px;">{{ $survey->numero_documento }}</span>
                        </div>
                    </div>
                    @if($survey->hospital)
                    <div class="field field-4cols">
                        <div class="field-label">Hospital</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::getHospitalDisplayName($survey->hospital) }}</div>
                    </div>
                    @endif
                    @if($survey->profesion)
                    <div class="field field-4cols">
                        <div class="field-label">Proceso</div>
                        <div class="field-value">{{ $survey->profesion }}</div>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Datos Básicos Adicionales -->
        @if($survey->correo || $survey->rh || $survey->fecha_expedicion || $survey->departamento || $survey->municipio || $survey->celular || $survey->direccion || $survey->talla_calzado || $survey->talla_vestimenta || $survey->pais_nacimiento)
        <div class="section">
            <div class="section-title">Datos Básicos Adicionales</div>
            <div class="grid">
                <div class="grid-row">
                    <div class="field field-4cols">
                        <div class="field-label">Correo</div>
                        <div class="field-value">{{ $survey->correo }}</div>
                    </div>
                    @if($survey->rh)
                    <div class="field field-4cols">
                        <div class="field-label">RH</div>
                        <div class="field-value">{{ $survey->rh }}</div>
                    </div>
                    @endif
                    @if($survey->fecha_expedicion)
                    <div class="field field-4cols">
                        <div class="field-label">Fecha Expedición</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::formatDate($survey->fecha_expedicion) }}</div>
                    </div>
                    @endif
                    @if($survey->departamento || $survey->municipio)
                    <div class="field field-4cols">
                        <div class="field-label">Depto. y Municipio</div>
                        <div class="field-value">
                            {{ $survey->departamento ?? 'N/A' }}
                            @if($survey->departamento && $survey->municipio) - @endif
                            {{ $survey->municipio ?? '' }}
                        </div>
                    </div>
                    @endif
                </div>
                @if($survey->pais_nacimiento || $survey->celular || $survey->direccion || $survey->talla_calzado || $survey->talla_vestimenta)
                <div class="grid-row">
                    @if($survey->pais_nacimiento)
                    <div class="field field-4cols">
                        <div class="field-label">País</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::getPaisDisplayName($survey->pais_nacimiento) }}</div>
                    </div>
                    @endif
                    @if($survey->celular)
                    <div class="field field-4cols">
                        <div class="field-label">Celular</div>
                        <div class="field-value">{{ $survey->celular }}</div>
                    </div>
                    @endif
                    @if($survey->direccion)
                    <div class="field field-4cols">
                        <div class="field-label">Dirección</div>
                        <div class="field-value">{{ $survey->direccion }}</div>
                    </div>
                    @endif
                    @if($survey->talla_calzado || $survey->talla_vestimenta)
                    <div class="field field-4cols">
                        <div class="field-label">Tallas</div>
                        <div class="field-value">
                            @if($survey->talla_calzado && $survey->talla_vestimenta)
                                Calzado: {{ $survey->talla_calzado }} - Vestimenta: {{ \App\Helpers\SurveyFormatter::getTallaVestimentaDisplayName($survey->talla_vestimenta) }}
                            @elseif($survey->talla_calzado)
                                Calzado: {{ $survey->talla_calzado }}
                            @elseif($survey->talla_vestimenta)
                                Vestimenta: {{ \App\Helpers\SurveyFormatter::getTallaVestimentaDisplayName($survey->talla_vestimenta) }}
                            @endif
                        </div>
                    </div>
                    @endif
                </div>
                @endif
            </div>
        </div>
        @endif

        <!-- Contacto de Emergencia -->
        @if($survey->nombre_contacto_emergencia || $survey->relacion_contacto_emergencia || $survey->telefono_contacto_emergencia)
        <div class="section">
            <div class="section-title">Contacto de Emergencia</div>
            <div class="grid">
                <div class="grid-row">
                    @if($survey->nombre_contacto_emergencia)
                    <div class="field">
                        <div class="field-label">Nombre</div>
                        <div class="field-value">{{ $survey->nombre_contacto_emergencia }}</div>
                    </div>
                    @endif
                    @if($survey->relacion_contacto_emergencia)
                    <div class="field">
                        <div class="field-label">Relación</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::getRelacionContactoEmergenciaDisplayName($survey->relacion_contacto_emergencia) }}</div>
                    </div>
                    @endif
                    @if($survey->telefono_contacto_emergencia)
                    <div class="field">
                        <div class="field-label">Teléfono</div>
                        <div class="field-value">{{ $survey->telefono_contacto_emergencia }}</div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
        @endif

        <!-- Datos Sociodemográficos -->
        @if($survey->datos_sociodemograficos || $survey->lugar_nacimiento)
        <div class="section">
            <div class="section-title">Datos Sociodemográficos</div>
            <div class="grid">
                <!-- Fila 1: Lugar Nacimiento, Fecha Nacimiento, Estatura, Peso -->
                <div class="grid-row">
                    @if($survey->lugar_nacimiento)
                    <div class="field field-4cols">
                        <div class="field-label">Lugar Nacimiento</div>
                        <div class="field-value">{{ $survey->lugar_nacimiento }}</div>
                    </div>
                    @endif
                    @if(isset($survey->datos_sociodemograficos['fechaNacimiento']))
                    <div class="field field-4cols">
                        <div class="field-label">Fecha Nacimiento</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::formatDate($survey->datos_sociodemograficos['fechaNacimiento']) }}</div>
                    </div>
                    @endif
                    @if(isset($survey->datos_sociodemograficos['estatura']))
                    <div class="field field-4cols">
                        <div class="field-label">Estatura (cm)</div>
                        <div class="field-value">{{ $survey->datos_sociodemograficos['estatura'] }}</div>
                    </div>
                    @endif
                    @if(isset($survey->datos_sociodemograficos['peso']))
                    <div class="field field-4cols">
                        <div class="field-label">Peso (kg)</div>
                        <div class="field-value">{{ $survey->datos_sociodemograficos['peso'] }}</div>
                    </div>
                    @endif
                </div>
                <!-- Fila 2: Género, Estado Civil, Nivel Educativo, Personas a Cargo -->
                <div class="grid-row">
                    @if(isset($survey->datos_sociodemograficos['genero']))
                    <div class="field field-4cols">
                        <div class="field-label">Género</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::getGeneroDisplayName($survey->datos_sociodemograficos['genero']) }}</div>
                    </div>
                    @endif
                    @if(isset($survey->datos_sociodemograficos['estadoCivil']))
                    <div class="field field-4cols">
                        <div class="field-label">Estado Civil</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::getEstadoCivilDisplayName($survey->datos_sociodemograficos['estadoCivil']) }}</div>
                    </div>
                    @endif
                    @if(isset($survey->datos_sociodemograficos['nivelEducativo']))
                    <div class="field field-4cols">
                        <div class="field-label">Nivel Educativo</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::getNivelEducativoDisplayName($survey->datos_sociodemograficos['nivelEducativo']) }}</div>
                    </div>
                    @endif
                    @if(isset($survey->datos_sociodemograficos['tienePersonasACargo']))
                    <div class="field field-4cols">
                        <div class="field-label">Personas a Cargo</div>
                        <div class="field-value">
                            <span class="si-no {{ strtolower($survey->datos_sociodemograficos['tienePersonasACargo']) === 'si' ? 'si' : (strtolower($survey->datos_sociodemograficos['tienePersonasACargo']) === 'no' ? 'no' : 'no-especificado') }}">
                                {{ \App\Helpers\SurveyFormatter::formatSiNo($survey->datos_sociodemograficos['tienePersonasACargo']) }}
                            </span>
                        </div>
                    </div>
                    @endif
                </div>
                <!-- Fila 3: N° Hijos, Personas Dependientes, Grupo Étnico, Tipo Vivienda -->
                <div class="grid-row">
                    @if(isset($survey->datos_sociodemograficos['numeroHijos']))
                    <div class="field field-4cols">
                        <div class="field-label">N° Hijos</div>
                        <div class="field-value">{{ $survey->datos_sociodemograficos['numeroHijos'] ?? '0' }}</div>
                    </div>
                    @endif
                    @if(isset($survey->datos_sociodemograficos['numeroPersonasDependientes']))
                    <div class="field field-4cols">
                        <div class="field-label">Personas Dependientes</div>
                        <div class="field-value">{{ $survey->datos_sociodemograficos['numeroPersonasDependientes'] ?? '0' }}</div>
                    </div>
                    @endif
                    @if(isset($survey->datos_sociodemograficos['raza']))
                    <div class="field field-4cols">
                        <div class="field-label">Grupo Étnico</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::getRazaDisplayName($survey->datos_sociodemograficos['raza']) }}</div>
                    </div>
                    @endif
                    @if(isset($survey->datos_sociodemograficos['vivienda']))
                    <div class="field field-4cols">
                        <div class="field-label">Tipo Vivienda</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::getViviendaDisplayName($survey->datos_sociodemograficos['vivienda']) }}</div>
                    </div>
                    @endif
                </div>
                <!-- Fila 4: Estrato, Convive Con, Transporte, Tiempo Libre Con -->
                <div class="grid-row">
                    @if(isset($survey->datos_sociodemograficos['estratoSocioeconomico']))
                    <div class="field field-4cols">
                        <div class="field-label">Estrato</div>
                        <div class="field-value">{{ $survey->datos_sociodemograficos['estratoSocioeconomico'] }}</div>
                    </div>
                    @endif
                    @if(isset($survey->datos_sociodemograficos['conviveCon']))
                    <div class="field field-4cols">
                        <div class="field-label">Convive Con</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::getConviveConDisplayName($survey->datos_sociodemograficos['conviveCon']) }}</div>
                    </div>
                    @endif
                    @if(isset($survey->datos_sociodemograficos['transporte']))
                    <div class="field field-4cols">
                        <div class="field-label">Transporte</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::getTransporteDisplayName($survey->datos_sociodemograficos['transporte']) }}</div>
                    </div>
                    @endif
                    @if(isset($survey->datos_sociodemograficos['tiempoLibreCon']))
                    <div class="field field-4cols">
                        <div class="field-label">Tiempo Libre Con</div>
                        <div class="field-value">{{ \App\Helpers\SurveyFormatter::getTiempoLibreConDisplayName($survey->datos_sociodemograficos['tiempoLibreCon']) }}</div>
                    </div>
                    @endif
                </div>
            </div>

            @if(isset($survey->datos_sociodemograficos['hijos']) && is_array($survey->datos_sociodemograficos['hijos']) && count($survey->datos_sociodemograficos['hijos']) > 0)
            <div class="children-section">
                <div style="font-weight: 600; margin-bottom: 6px; color: #00529B; font-size: 9px;">Información de Hijos</div>
                @foreach($survey->datos_sociodemograficos['hijos'] as $hijo)
                <div class="child-item">
                    <div class="grid">
                        <div class="grid-row">
                            <div class="field">
                                <div class="field-label">Nombre</div>
                                <div class="field-value">{{ $hijo['nombre'] ?? '' }}</div>
                            </div>
                            <div class="field">
                                <div class="field-label">Documento</div>
                                <div class="field-value">
                                    <strong>{{ \App\Helpers\SurveyFormatter::getTipoDocumentoDisplayName($hijo['tipoDocumento'] ?? '') }}</strong>
                                    <span style="font-family: monospace; margin-left: 4px;">{{ $hijo['numeroDocumento'] ?? '' }}</span>
                                </div>
                            </div>
                            <div class="field">
                                <div class="field-label">Fecha Nacimiento</div>
                                <div class="field-value">{{ \App\Helpers\SurveyFormatter::formatDate($hijo['fechaNacimiento'] ?? null) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif

            @php
                $servicios = $survey->datos_sociodemograficos['serviciosPublicos'] ?? null;
                $tiempoLibre = $survey->datos_sociodemograficos['manejoTiempoLibre'] ?? null;
                $hasAnyService = $servicios && (
                    ($servicios['agua'] ?? false) || 
                    ($servicios['luz'] ?? false) || 
                    ($servicios['telefono'] ?? false) || 
                    ($servicios['internet'] ?? false) || 
                    ($servicios['gas'] ?? false)
                );
                $hasAnyActivity = $tiempoLibre && (
                    ($tiempoLibre['recreativas'] ?? false) || 
                    ($tiempoLibre['deportivas'] ?? false) || 
                    ($tiempoLibre['educativas'] ?? false) || 
                    ($tiempoLibre['descanso'] ?? false) || 
                    ($tiempoLibre['artisticas'] ?? false) || 
                    ($tiempoLibre['religiosas'] ?? false) || 
                    ($tiempoLibre['otras'] ?? false)
                );
            @endphp

            @if($hasAnyService || $hasAnyActivity || ($survey->datos_consumo ?? null))
            <div style="margin-top: 8px;">
                <div class="grid">
                    <div class="grid-row">
                        @if($hasAnyService)
                        <div class="field field-4cols">
                            <div class="field-label">Servicios Públicos</div>
                            <div style="margin-top: 4px;">
                                @if($servicios['agua'] ?? false)<span class="badge-item">Agua</span>@endif
                                @if($servicios['luz'] ?? false)<span class="badge-item">Luz</span>@endif
                                @if($servicios['telefono'] ?? false)<span class="badge-item">Teléfono</span>@endif
                                @if($servicios['internet'] ?? false)<span class="badge-item">Internet</span>@endif
                                @if($servicios['gas'] ?? false)<span class="badge-item">Gas</span>@endif
                            </div>
                        </div>
                        @endif
                        @if($hasAnyActivity)
                        <div class="field field-4cols">
                            <div class="field-label">Tiempo Libre</div>
                            <div style="margin-top: 4px;">
                                @if($tiempoLibre['recreativas'] ?? false)<span class="badge-item">Recreativas</span>@endif
                                @if($tiempoLibre['deportivas'] ?? false)<span class="badge-item">Deportivas</span>@endif
                                @if($tiempoLibre['educativas'] ?? false)<span class="badge-item">Educativas</span>@endif
                                @if($tiempoLibre['descanso'] ?? false)<span class="badge-item">Descanso</span>@endif
                                @if($tiempoLibre['artisticas'] ?? false)<span class="badge-item">Artísticas</span>@endif
                                @if($tiempoLibre['religiosas'] ?? false)<span class="badge-item">Religiosas</span>@endif
                                @if($tiempoLibre['otras'] ?? false)<span class="badge-item">Otras</span>@endif
                            </div>
                        </div>
                        @endif
                        @if($survey->datos_consumo ?? null)
                        <div class="field field-4cols">
                            <div class="field-label">Consumo de Licor</div>
                            <div class="field-value">
                                <span class="si-no {{ strtolower($survey->datos_consumo['consumoLicor'] ?? '') === 'si' ? 'si' : (strtolower($survey->datos_consumo['consumoLicor'] ?? '') === 'no' ? 'no' : 'no-especificado') }}">
                                    {{ \App\Helpers\SurveyFormatter::formatSiNo($survey->datos_consumo['consumoLicor'] ?? null) }}
                                </span>
                                @if(isset($survey->datos_consumo['consumoLicor']) && strtolower($survey->datos_consumo['consumoLicor']) === 'si' && isset($survey->datos_consumo['frecuenciaLicor']))
                                <div style="font-size: 7px; color: #64748b; margin-top: 2px;">
                                    Frecuencia: {{ \App\Helpers\SurveyFormatter::getFrecuenciaDisplayName($survey->datos_consumo['frecuenciaLicor']) }}
                                </div>
                                @endif
                            </div>
                        </div>
                        @endif
                        @if($survey->datos_consumo ?? null)
                        <div class="field field-4cols">
                            <div class="field-label">Consumo de Cigarrillo</div>
                            <div class="field-value">
                                <span class="si-no {{ strtolower($survey->datos_consumo['consumoCigarrillo'] ?? '') === 'si' ? 'si' : (strtolower($survey->datos_consumo['consumoCigarrillo'] ?? '') === 'no' ? 'no' : 'no-especificado') }}">
                                    {{ \App\Helpers\SurveyFormatter::formatSiNo($survey->datos_consumo['consumoCigarrillo'] ?? null) }}
                                </span>
                                @if(isset($survey->datos_consumo['consumoCigarrillo']) && strtolower($survey->datos_consumo['consumoCigarrillo']) === 'si' && isset($survey->datos_consumo['frecuenciaCigarrillo']))
                                <div style="font-size: 7px; color: #64748b; margin-top: 2px;">
                                    Frecuencia: {{ \App\Helpers\SurveyFormatter::getFrecuenciaDisplayName($survey->datos_consumo['frecuenciaCigarrillo']) }}
                                </div>
                                @endif
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            @endif
        </div>
        @endif


        <!-- Condiciones de Salud -->
        @if($survey->condiciones_salud)
        <div class="section">
            <div class="section-title">Condiciones de Salud</div>
            <div class="grid">
                @php
                    $condiciones = $survey->condiciones_salud;
                    $condicionesKeys = array_keys($condiciones);
                    $condicionesToShow = array_filter($condicionesKeys, function($key) {
                        return !str_starts_with($key, 'tipo') && !str_starts_with($key, 'tiempo');
                    });
                    usort($condicionesToShow, function($a, $b) {
                        return strcmp(
                            \App\Helpers\SurveyFormatter::getCondicionSaludLabel($a),
                            \App\Helpers\SurveyFormatter::getCondicionSaludLabel($b)
                        );
                    });
                    $chunks = array_chunk($condicionesToShow, 4);
                @endphp
                @foreach($chunks as $chunk)
                <div class="grid-row">
                    @foreach($chunk as $key)
                    <div class="field field-4cols">
                        <div class="field-label">{{ \App\Helpers\SurveyFormatter::getCondicionSaludLabel($key) }}</div>
                        <div class="field-value">
                            <span class="si-no {{ strtolower($condiciones[$key] ?? '') === 'si' ? 'si' : (strtolower($condiciones[$key] ?? '') === 'no' ? 'no' : 'no-especificado') }}">
                                {{ \App\Helpers\SurveyFormatter::formatSiNo($condiciones[$key] ?? null) }}
                            </span>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endforeach
            </div>

            @php
                $hasDetails = false;
                $details = [];
                
                if (($condiciones['problemasPulmonares'] ?? '') === 'si' && isset($condiciones['tipoProblemaPulmonar'])) {
                    $hasDetails = true;
                    $details[] = ['label' => 'Tipo Problema Pulmonar', 'value' => $condiciones['tipoProblemaPulmonar']];
                }
                if (($condiciones['alergias'] ?? '') === 'si' && isset($condiciones['tipoAlergia'])) {
                    $hasDetails = true;
                    $details[] = ['label' => 'Tipo Alergia', 'value' => $condiciones['tipoAlergia']];
                }
                if (($condiciones['problemasVisuales'] ?? '') === 'si' && isset($condiciones['tipoProblemaVisual'])) {
                    $hasDetails = true;
                    $details[] = ['label' => 'Tipo Problema Visual', 'value' => $condiciones['tipoProblemaVisual']];
                }
                if (($condiciones['doloresArticulares'] ?? '') === 'si' && isset($condiciones['tipoDolorArticular'])) {
                    $hasDetails = true;
                    $details[] = ['label' => 'Tipo Dolor Articular', 'value' => $condiciones['tipoDolorArticular']];
                }
                if (($condiciones['trasplante'] ?? '') === 'si' && isset($condiciones['tipoTrasplante'])) {
                    $hasDetails = true;
                    $details[] = ['label' => 'Tipo Trasplante', 'value' => $condiciones['tipoTrasplante']];
                }
                if (($condiciones['medicamentoPermanente'] ?? '') === 'si' && isset($condiciones['tipoMedicamento'])) {
                    $hasDetails = true;
                    $details[] = ['label' => 'Medicamento Permanente', 'value' => $condiciones['tipoMedicamento']];
                }
                if (($condiciones['otraEnfermedad'] ?? '') === 'si' && isset($condiciones['tipoOtraEnfermedad'])) {
                    $hasDetails = true;
                    $details[] = ['label' => 'Otra Enfermedad', 'value' => $condiciones['tipoOtraEnfermedad']];
                }
                if (($condiciones['cirugias'] ?? '') === 'si') {
                    $hasDetails = true;
                    $details[] = [
                        'label' => 'Cirugías',
                        'value' => $condiciones['tipoCirugia'] ?? 'N/A',
                        'extra' => isset($condiciones['tiempoCirugia']) ? 'Tiempo: ' . $condiciones['tiempoCirugia'] : null
                    ];
                }
                if (($condiciones['accidenteLaboral'] ?? '') === 'si') {
                    $hasDetails = true;
                    $details[] = [
                        'label' => 'Accidente Laboral',
                        'value' => $condiciones['tipoAccidenteLaboral'] ?? 'N/A',
                        'extra' => isset($condiciones['tiempoAccidenteLaboral']) ? 'Tiempo: ' . $condiciones['tiempoAccidenteLaboral'] : null
                    ];
                }
                if (($condiciones['accidenteTransitoCasero'] ?? '') === 'si') {
                    $hasDetails = true;
                    $details[] = [
                        'label' => 'Accidente Tránsito/Casero',
                        'value' => $condiciones['tipoAccidenteTransito'] ?? 'N/A',
                        'extra' => isset($condiciones['tiempoAccidenteTransito']) ? 'Tiempo: ' . $condiciones['tiempoAccidenteTransito'] : null
                    ];
                }
                $detailChunks = array_chunk($details, 4);
            @endphp

            @if($hasDetails)
            <div class="details-section">
                <div class="grid">
                    @foreach($detailChunks as $chunk)
                    <div class="grid-row">
                        @foreach($chunk as $detail)
                        <div class="field field-4cols">
                            <div class="field-label">{{ $detail['label'] }}</div>
                            <div class="field-value">
                                {{ $detail['value'] }}
                                @if(isset($detail['extra']))
                                <div style="font-size: 7px; color: #64748b; margin-top: 2px;">{{ $detail['extra'] }}</div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
        @endif

        <!-- Limitaciones Físicas y Firma Digital -->
        <div class="grid">
            <div class="grid-row">
                <!-- Limitaciones Físicas (2 columnas izquierda) -->
                @if($survey->limitaciones_fisicas)
                <div class="field field-half">
                    <div class="section" style="margin-bottom: 0;">
                        <div class="section-title">
                            Limitaciones Físicas
                            @if(strtolower($survey->recomendacion_restriccion_laboral ?? '') !== 'si')
                            <span style="font-weight: normal; color: #64748b; font-size: 7px;">(No reporta recomendación o restricción laboral)</span>
                            @endif
                        </div>
                        <div class="grid">
                            <!-- Fila 1: Esfuerzos Intensos, Esfuerzos Moderados -->
                            <div class="grid-row">
                                @if(isset($survey->limitaciones_fisicas['esfuerzosIntensos']))
                                <div class="field field-2cols">
                                    <div class="field-label">Esfuerzos Intensos</div>
                                    <div class="field-value">{{ \App\Helpers\SurveyFormatter::formatLimitacion($survey->limitaciones_fisicas['esfuerzosIntensos']) }}</div>
                                </div>
                                @endif
                                @if(isset($survey->limitaciones_fisicas['esfuerzosModerados']))
                                <div class="field field-2cols">
                                    <div class="field-label">Esfuerzos Moderados</div>
                                    <div class="field-value">{{ \App\Helpers\SurveyFormatter::formatLimitacion($survey->limitaciones_fisicas['esfuerzosModerados']) }}</div>
                                </div>
                                @endif
                            </div>
                            <!-- Fila 2: Subir Pisos, Agacharse/Arrodillarse -->
                            <div class="grid-row">
                                @if(isset($survey->limitaciones_fisicas['subirPisos']))
                                <div class="field field-2cols">
                                    <div class="field-label">Subir Pisos</div>
                                    <div class="field-value">{{ \App\Helpers\SurveyFormatter::formatLimitacion($survey->limitaciones_fisicas['subirPisos']) }}</div>
                                </div>
                                @endif
                                @if(isset($survey->limitaciones_fisicas['agacharseArrodillarse']))
                                <div class="field field-2cols">
                                    <div class="field-label">Agacharse/Arrodillarse</div>
                                    <div class="field-value">{{ \App\Helpers\SurveyFormatter::formatLimitacion($survey->limitaciones_fisicas['agacharseArrodillarse']) }}</div>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                @endif
                <!-- Firma Digital (2 columnas derecha) -->
                @if(!empty($survey->firma_path))
                <div class="field field-half">
                    <div class="signature-container">
                        <div class="field-label" style="margin-bottom: 4px;">Firma Digital</div>
                        @if($signatureImageBase64)
                        <img src="data:image/png;base64,{{ $signatureImageBase64 }}" alt="Firma digital" class="signature-image" />
                        @else
                        <div class="no-signature">No se pudo cargar la imagen de la firma</div>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </div>

        <!-- Recomendaciones Laborales -->
        @if(strtolower($survey->recomendacion_restriccion_laboral ?? '') === 'si')
        <div class="section">
            <div class="section-title">Recomendaciones Laborales</div>
            <div class="grid">
                <div class="grid-row">
                    <div class="field">
                        <div class="field-label">¿Tiene recomendación o restricción laboral?</div>
                        <div class="field-value">
                            <span class="si-no si">
                                {{ \App\Helpers\SurveyFormatter::formatSiNo($survey->recomendacion_restriccion_laboral) }}
                            </span>
                        </div>
                    </div>
                    @if($survey->detalle_recomendacion_laboral)
                    <div class="field" style="width: 66.66%;">
                        <div class="field-label">Detalle Recomendación</div>
                        <div class="field-value" style="white-space: pre-wrap; font-size: 8px;">{{ $survey->detalle_recomendacion_laboral }}</div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
        @endif

    </div>
</body>
</html>

