<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\{Log, Storage};
use PhpOffice\PhpWord\TemplateProcessor;
use PhpOffice\PhpSpreadsheet\{IOFactory, Cell\Coordinate};

class ConvenioGenerationService
{
    private const TEMPLATE_PATH = 'resources/templates/Plantilla_convenios.docx';
    // Usar directorio temporal en lugar de resources (no accesible en producción)
    // El archivo se generará temporalmente y se descargará directamente
    private const OUTPUT_DIR = 'temp/convenios';
    private const AFILIADOS_FILE_PATH = 'data/PROSANET_INFORMACION_AFILIADOS.xlsx';

    public function __construct(
        private AfiliadoService $afiliadoService
    ) {
    }

    /**
     * Genera un convenio desde datos proporcionados
     *
     * @param array $data Datos del convenio
     * @return array Resultado con ruta y nombre del archivo generado
     */
    public function generarConvenio(array $data): array
    {
        $documento = $this->normalizeDocumento($data['numero_documento'] ?? '');
        $nombreCompleto = trim(($data['apellidos'] ?? '') . ' ' . ($data['nombres'] ?? ''));
        
        Log::info('[CONVENIO GENERATION] Iniciando generación de convenio', [
            'documento' => $documento,
            'nombre_completo' => $nombreCompleto,
            'proceso' => $data['proceso'] ?? null,
            'ciudad' => $data['ciudad'] ?? null,
        ]);

        // Validar que existe la plantilla
        $templatePath = base_path(self::TEMPLATE_PATH);
        if (!file_exists($templatePath)) {
            Log::error('[CONVENIO GENERATION] Plantilla no encontrada', [
                'template_path' => $templatePath,
                'documento' => $documento,
            ]);
            throw new \Exception("Plantilla no encontrada en: {$templatePath}");
        }

        Log::debug('[CONVENIO GENERATION] Plantilla encontrada', [
            'template_path' => $templatePath,
            'documento' => $documento,
        ]);

        // Obtener datos faltantes del archivo de afiliados si es necesario
        if (!empty($documento)) {
            Log::debug('[CONVENIO GENERATION] Buscando datos faltantes en archivo de afiliados', [
                'documento' => $documento,
                'direccion_vacio' => empty(trim($data['direccion'] ?? '')),
                'celular_vacio' => empty(trim($data['celular'] ?? '')),
            ]);

            $afiliadoData = $this->obtenerDatosAfiliado($documento);
            
            if ($afiliadoData) {
                Log::info('[CONVENIO GENERATION] Datos de afiliado encontrados', [
                    'documento' => $documento,
                    'tiene_direccion' => !empty($afiliadoData['direccion']),
                    'tiene_celular' => !empty($afiliadoData['celular']),
                ]);
            } else {
                Log::warning('[CONVENIO GENERATION] Afiliado no encontrado en archivo de afiliados', [
                    'documento' => $documento,
                ]);
            }
            
            // Completar datos faltantes (solo si están vacíos en los datos originales)
            if (empty(trim($data['direccion'] ?? ''))) {
                $data['direccion'] = !empty($afiliadoData['direccion']) ? trim($afiliadoData['direccion']) : '';
                if (!empty($data['direccion'])) {
                    Log::debug('[CONVENIO GENERATION] Dirección completada desde afiliado', [
                        'documento' => $documento,
                    ]);
                }
            }
            if (empty(trim($data['celular'] ?? ''))) {
                $data['celular'] = !empty($afiliadoData['celular']) ? trim($afiliadoData['celular']) : '';
                if (!empty($data['celular'])) {
                    Log::debug('[CONVENIO GENERATION] Celular completado desde afiliado', [
                        'documento' => $documento,
                    ]);
                }
            }
        } else {
            Log::warning('[CONVENIO GENERATION] Documento vacío, no se buscará en archivo de afiliados', [
                'numero_documento_original' => $data['numero_documento'] ?? null,
            ]);
        }

        // Preparar datos para la plantilla
        $templateData = $this->prepararDatosPlantilla($data);

        Log::debug('[CONVENIO GENERATION] Datos preparados para plantilla', [
            'documento' => $documento,
            'variables' => array_keys($templateData),
            'fecha_inicio_raw' => $data['fecha_inicio'] ?? null,
            'fecha_finalizacion_raw' => $data['fecha_finalizacion'] ?? null,
            'tiene_fecha_inicio' => !empty($data['fecha_inicio'] ?? null),
            'tiene_fecha_finalizacion' => !empty($data['fecha_finalizacion'] ?? null),
            'duracion_generada' => !empty($templateData['DURACION'] ?? null),
            'duracion_preview' => !empty($templateData['DURACION'] ?? null) ? substr($templateData['DURACION'], 0, 100) . '...' : 'vacía',
        ]);

        // Crear procesador de plantilla
        $templateProcessor = new TemplateProcessor($templatePath);

        // Reemplazar placeholders
        foreach ($templateData as $key => $value) {
            $templateProcessor->setValue($key, $value ?? '');
        }

        Log::debug('[CONVENIO GENERATION] Placeholders reemplazados en plantilla', [
            'documento' => $documento,
            'total_variables' => count($templateData),
        ]);

        // Generar nombre de archivo
        $nombreArchivo = $this->generarNombreArchivo($data);
        
        // Usar storage/app/temp/convenios para archivos temporales (accesible en producción)
        $outputDir = storage_path('app/' . self::OUTPUT_DIR);
        $rutaSalida = $outputDir . '/' . $nombreArchivo;

        Log::debug('[CONVENIO GENERATION] Nombre de archivo generado', [
            'documento' => $documento,
            'nombre_archivo' => $nombreArchivo,
            'ruta_salida' => $rutaSalida,
        ]);

        // Crear directorio si no existe
        Log::debug('[CONVENIO GENERATION] Verificando directorio de salida', [
            'output_dir' => $outputDir,
            'existe' => is_dir($outputDir),
            'es_escribible' => is_dir($outputDir) ? is_writable($outputDir) : false,
        ]);
        
        if (!is_dir($outputDir)) {
            $creado = mkdir($outputDir, 0755, true);
            Log::info('[CONVENIO GENERATION] Intento de creación de directorio de salida', [
                'output_dir' => $outputDir,
                'creado' => $creado,
                'existe_despues' => is_dir($outputDir),
                'permisos' => is_dir($outputDir) ? substr(sprintf('%o', fileperms($outputDir)), -4) : null,
            ]);
            
            if (!is_dir($outputDir)) {
                Log::error('[CONVENIO GENERATION] No se pudo crear el directorio de salida', [
                    'output_dir' => $outputDir,
                ]);
                throw new \Exception("No se pudo crear el directorio de salida: {$outputDir}");
            }
        }

        // Guardar documento
        Log::debug('[CONVENIO GENERATION] Iniciando guardado del documento', [
            'documento' => $documento,
            'ruta_salida' => $rutaSalida,
            'directorio_existe' => is_dir($outputDir),
            'directorio_escribible' => is_writable($outputDir),
            'archivo_existe_antes' => file_exists($rutaSalida),
        ]);
        
        try {
            $templateProcessor->saveAs($rutaSalida);
            
            // Verificar que el archivo realmente se guardó
            $archivoExiste = file_exists($rutaSalida);
            $tamañoArchivo = $archivoExiste ? filesize($rutaSalida) : null;
            $esLegible = $archivoExiste ? is_readable($rutaSalida) : false;
            
            Log::info('[CONVENIO GENERATION] Convenio generado exitosamente', [
                'documento' => $documento,
                'nombre_completo' => $nombreCompleto,
                'nombre_archivo' => $nombreArchivo,
                'ruta' => $rutaSalida,
                'archivo_existe' => $archivoExiste,
                'tamaño_bytes' => $tamañoArchivo,
                'es_legible' => $esLegible,
                'permisos_archivo' => $archivoExiste ? substr(sprintf('%o', fileperms($rutaSalida)), -4) : null,
            ]);
            
            if (!$archivoExiste) {
                Log::error('[CONVENIO GENERATION] El archivo no existe después de guardar', [
                    'documento' => $documento,
                    'ruta_salida' => $rutaSalida,
                    'output_dir' => $outputDir,
                    'directorio_existe' => is_dir($outputDir),
                    'directorio_escribible' => is_writable($outputDir),
                ]);
                throw new \Exception("El archivo no se guardó correctamente en: {$rutaSalida}");
            }
            
            if ($tamañoArchivo === 0 || $tamañoArchivo === null) {
                Log::warning('[CONVENIO GENERATION] El archivo se guardó pero tiene tamaño 0', [
                    'documento' => $documento,
                    'ruta_salida' => $rutaSalida,
                    'tamaño_bytes' => $tamañoArchivo,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('[CONVENIO GENERATION] Error al guardar convenio', [
                'documento' => $documento,
                'nombre_archivo' => $nombreArchivo,
                'ruta_salida' => $rutaSalida,
                'output_dir' => $outputDir,
                'directorio_existe' => is_dir($outputDir),
                'directorio_escribible' => is_dir($outputDir) ? is_writable($outputDir) : false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        return [
            'ruta' => $rutaSalida,
            'nombre' => $nombreArchivo,
            'tipo' => 'docx',
        ];
    }

    /**
     * Prepara los datos para la plantilla Word
     *
     * @param array $data Datos del convenio
     * @return array Datos formateados para la plantilla
     */
    private function prepararDatosPlantilla(array $data): array
    {
        // Formatear número de documento con comas
        $numeroDocumento = $this->formatearDocumentoConComas($data['numero_documento'] ?? '');

        // Formatear fecha de inicio
        $fechaInicio = $this->formatearFechaEspanol($data['fecha_inicio'] ?? null);

        // Formatear fecha de nacimiento (dd/mm/aaaa)
        $fechaNacimiento = $this->formatearFechaNacimiento($data['fecha_nacimiento'] ?? null);

        // Calcular duración
        $duracion = $this->calcularDuracion(
            $data['fecha_inicio'] ?? null,
            $data['fecha_finalizacion'] ?? null
        );

        // Obtener compensación básica redactada o generarla automáticamente si no está definida
        $compensacionBasicaRedactada = $data['compensacion_basica_redactada'] ?? '';
        if (empty(trim($compensacionBasicaRedactada))) {
            $compensacionBasicaRedactada = $this->generarCompensacionBasicaRedactada($data);
        }

        // Formatear valores numéricos para mostrar en la plantilla
        $formatearValor = function($valor) {
            if (empty($valor) && $valor !== '0' && $valor !== 0) {
                return '';
            }
            // Si es numérico, formatear con separador de miles
            if (is_numeric($valor)) {
                return number_format((float) $valor, 0, ',', '.');
            }
            return (string) $valor;
        };

        return [
            'PROCESO' => strtoupper($data['proceso'] ?? ''),
            'CIUDAD' => $data['ciudad'] ?? '',
            'SEDE' => strtoupper($data['sede'] ?? ''),
            'FECHA' => $fechaInicio,
            'APELLIDOS' => strtoupper($data['apellidos'] ?? ''),
            'NOMBRES' => strtoupper($data['nombres'] ?? ''),
            'LUGAR_NACIMIENTO' => strtoupper($data['lugar_nacimiento'] ?? ''),
            'FECHA_NACIMIENTO' => $fechaNacimiento,
            'NUMERO_DOCUMENTO' => $numeroDocumento,
            'COMPENSACION_BASICA_REDACTADA' => $compensacionBasicaRedactada,
            'DURACION' => $duracion,
            'DIRECCION' => !empty(trim($data['direccion'] ?? '')) ? $data['direccion'] : '__________________________',
            'CELULAR' => !empty(trim($data['celular'] ?? '')) ? $data['celular'] : '__________________________',
            // Nuevos campos de compensación
            'BASICO' => $formatearValor($data['basico'] ?? ''),
            'AUXILIOS' => $formatearValor($data['auxilios'] ?? ''),
            'MANUTENCION' => $formatearValor($data['manutencion'] ?? ''),
            'PROVISIONES' => $formatearValor($data['provisiones'] ?? ''),
            'HORAS' => $formatearValor($data['horas'] ?? ''),
            'VALOR_HORA_DIURNA' => $formatearValor($data['valor_hora_diurna'] ?? ''),
            'VALOR_HORA_NOCTURNA' => $formatearValor($data['valor_hora_nocturna'] ?? ''),
            'VALOR_HORA_DIURNA_FESTIVA' => $formatearValor($data['valor_hora_diurna_festiva'] ?? ''),
            'VALOR_HORA_NOCTURNA_FESTIVA' => $formatearValor($data['valor_hora_nocturna_festiva'] ?? ''),
            'AUXILIO_DE_TRANSPORTE' => $formatearValor($data['auxilio_de_transporte'] ?? ''),
            'AUXILIO_DE_MANUTENCION' => $formatearValor($data['auxilio_de_manutencion'] ?? ''),
            'AUXILIO_DE_ENCIERRO' => $formatearValor($data['auxilio_de_encierro'] ?? ''),
            'AUXILIO_DE_RODAMIENTO' => $formatearValor($data['auxilio_de_rodamiento'] ?? ''),
            'AUXILIO_ESPECIAL' => $formatearValor($data['auxilio_especial'] ?? ''),
            'VALOR_AUXILIO_DIURNO' => $formatearValor($data['valor_auxilio_diurno'] ?? ''),
            'VALOR_AUXILIO_RECARGO_NOCTURNO' => $formatearValor($data['valor_auxilio_recargo_nocturno'] ?? ''),
            'VALOR_AUXILIO_RECARGO_FESTIVO' => $formatearValor($data['valor_auxilio_recargo_festivo'] ?? ''),
            'VALOR_AUXILIO_RECARGO_FESTIVO_NOCTURNO' => $formatearValor($data['valor_auxilio_recargo_festivo_nocturno'] ?? ''),
        ];
    }

    /**
     * Construye el texto de compensación a partir de valores individuales (basico, auxilios, transporte, etc.).
     * Misma lógica que la generación de convenios: usa plantillas de mensaje según los valores enviados.
     * Útil para certificados de convenio que reciben estos valores por API.
     *
     * @param array $data Array con claves: basico, auxilios, manutencion, provisiones, horas,
     *                     valor_hora_diurna, valor_hora_nocturna, valor_hora_diurna_festiva, valor_hora_nocturna_festiva,
     *                     auxilio_de_transporte, auxilio_de_manutencion, auxilio_de_encierro, auxilio_de_rodamiento,
     *                     auxilio_especial, auxilio_prosalud, valor_auxilio_diurno, valor_auxilio_recargo_nocturno,
     *                     valor_auxilio_recargo_festivo, valor_auxilio_recargo_festivo_nocturno
     * @return string Texto de compensación redactado (puede ser vacío si no hay valores)
     */
    public function construirTextoCompensacionDesdeValores(array $data): string
    {
        return $this->generarCompensacionBasicaRedactada($data);
    }

    /**
     * Genera automáticamente el texto de compensación básica redactada
     * basándose en los valores proporcionados.
     *
     * @param array $data Datos del convenio con valores de compensación
     * @return string Texto de compensación básica redactada
     */
    private function generarCompensacionBasicaRedactada(array $data): string
    {
        Log::debug('[CONVENIO GENERATION] Iniciando generación automática de compensación básica redactada', [
            'datos_recibidos' => array_keys(array_filter($data, function($v) {
                return !empty($v) && $v !== null && $v !== '';
            })),
        ]);

        // Función auxiliar para formatear valores monetarios
        $formatearMoneda = function ($valor) {
            if (null === $valor || $valor === '') {
                return null;
            }
            if (!is_numeric($valor)) {
                return null;
            }

            return '$' . number_format((float) $valor, 0, ',', '.');
        };

        // Obtener valores (normalizar a números)
        $valorHoraDiurna = $this->normalizarValor($data['valor_hora_diurna'] ?? null);
        $valorHoraNocturna = $this->normalizarValor($data['valor_hora_nocturna'] ?? null);
        $valorHoraDiurnaFestiva = $this->normalizarValor($data['valor_hora_diurna_festiva'] ?? null);
        $valorHoraNocturnaFestiva = $this->normalizarValor($data['valor_hora_nocturna_festiva'] ?? null);

        $auxilioDiurno = $this->normalizarValor($data['valor_auxilio_diurno'] ?? null);
        $auxilioRecargoNocturno = $this->normalizarValor($data['valor_auxilio_recargo_nocturno'] ?? null);
        $auxilioRecargoFestivo = $this->normalizarValor($data['valor_auxilio_recargo_festivo'] ?? null);
        $auxilioRecargoFestivoNocturno = $this->normalizarValor($data['valor_auxilio_recargo_festivo_nocturno'] ?? null);

        $auxilioTransporte = $this->normalizarValor($data['auxilio_de_transporte'] ?? null);
        $auxilioManutencion = $this->normalizarValor($data['auxilio_de_manutencion'] ?? null);
        $auxilioEncierro = $this->normalizarValor($data['auxilio_de_encierro'] ?? null);
        $auxilioRodamiento = $this->normalizarValor($data['auxilio_de_rodamiento'] ?? null);
        $auxilioEspecial = $this->normalizarValor($data['auxilio_especial'] ?? null);
        $auxilioProsalud = $this->normalizarValor($data['auxilio_prosalud'] ?? $data['auxilio_prosalud_no_constitutivo'] ?? null);

        $basico = $this->normalizarValor($data['basico'] ?? null);
        $auxilios = $this->normalizarValor($data['auxilios'] ?? null);
        $provisiones = $this->normalizarValor($data['provisiones'] ?? null); // Devolución de compensaciones
        $horas = $this->normalizarValor($data['horas'] ?? null);

        Log::debug('[CONVENIO GENERATION] Valores normalizados para compensación', [
            'valores_hora' => [
                'diurna' => $valorHoraDiurna,
                'nocturna' => $valorHoraNocturna,
                'diurna_festiva' => $valorHoraDiurnaFestiva,
                'nocturna_festiva' => $valorHoraNocturnaFestiva,
            ],
            'auxilios_por_hora' => [
                'diurno' => $auxilioDiurno,
                'recargo_nocturno' => $auxilioRecargoNocturno,
                'recargo_festivo' => $auxilioRecargoFestivo,
                'recargo_festivo_nocturno' => $auxilioRecargoFestivoNocturno,
            ],
            'auxilios_especiales' => [
                'transporte' => $auxilioTransporte,
                'manutencion' => $auxilioManutencion,
                'encierro' => $auxilioEncierro,
                'rodamiento' => $auxilioRodamiento,
                'auxilio_especial' => $auxilioEspecial,
                'prosalud' => $auxilioProsalud,
            ],
            'otros' => [
                'basico' => $basico,
                'auxilios' => $auxilios,
                'provisiones' => $provisiones,
                'horas' => $horas,
            ],
        ]);

        $tieneValoresHora = $valorHoraDiurna || $valorHoraNocturna || $valorHoraDiurnaFestiva || $valorHoraNocturnaFestiva;
        $tieneAuxiliosEspeciales = $auxilioTransporte || $auxilioManutencion || $auxilioEncierro || $auxilioProsalud;
        $tieneAuxiliosPorHora = $auxilioDiurno || $auxilioRecargoNocturno || $auxilioRecargoFestivo || $auxilioRecargoFestivoNocturno;

        Log::debug('[CONVENIO GENERATION] Análisis de patrones para compensación', [
            'tiene_valores_hora' => $tieneValoresHora,
            'tiene_auxilios_especiales' => $tieneAuxiliosEspeciales,
            'tiene_auxilios_por_hora' => $tieneAuxiliosPorHora,
        ]);

        $texto = '';
        $patronUsado = null;

        // PATRÓN "V/R": V/R Basica Diurna + V/R Auxilio Diurna
        if ($valorHoraDiurna && $auxilioDiurno && !$valorHoraNocturna && !$valorHoraDiurnaFestiva && !$valorHoraNocturnaFestiva) {
            $patronUsado = 'V/R';
            Log::debug('[CONVENIO GENERATION] Usando patrón V/R', [
                'valor_hora_diurna' => $valorHoraDiurna,
                'auxilio_diurno' => $auxilioDiurno,
                'auxilio_transporte' => $auxilioTransporte,
            ]);
            
            $texto = 'V/R Basica Diurna ' . $formatearMoneda($valorHoraDiurna) . '; V/R Auxilio Diurna ' . $formatearMoneda($auxilioDiurno) . '.';

            if ($auxilioTransporte) {
                $horasTexto = $horas ? $horas . ' horas' : '186 horas';
                $texto .= ' El afiliado participe recibirá un auxilio de transporte correspondiente a  ' .
                    $formatearMoneda($auxilioTransporte) . ' por ' . $horasTexto .
                    ' o proporción de las mismas sin que este valor exceda ese monto en caso de superarse las ' .
                    ($horas ? $horas : '186') . ' horas.';
            }
        }
        // PATRÓN 1: Valores por hora + auxilios especiales (transporte, manutención, encierro, prosalud)
        elseif ($tieneValoresHora && $tieneAuxiliosEspeciales) {
            $patronUsado = 'Patrón 1: Valores por hora + auxilios especiales';
            $usarValorHora = $auxilioProsalud && !$auxilioTransporte && !$auxilioManutencion && !$auxilioEncierro;
            $prefijoHora = $usarValorHora ? 'Valor Hora' : 'Hora';
            
            Log::debug('[CONVENIO GENERATION] Usando patrón 1: Valores por hora + auxilios especiales', [
                'prefijo_hora' => $prefijoHora,
                'usar_valor_hora' => $usarValorHora,
                'auxilios_presentes' => [
                    'transporte' => !empty($auxilioTransporte),
                    'manutencion' => !empty($auxilioManutencion),
                    'encierro' => !empty($auxilioEncierro),
                    'prosalud' => !empty($auxilioProsalud),
                ],
            ]);

            $partesHora = [];
            if ($valorHoraDiurna) {
                $partesHora[] = $prefijoHora . ' Diurna ' . $formatearMoneda($valorHoraDiurna);
            }
            if ($valorHoraNocturna) {
                $partesHora[] = $prefijoHora . ' Nocturna ' . $formatearMoneda($valorHoraNocturna);
            }
            if ($valorHoraDiurnaFestiva) {
                $partesHora[] = $prefijoHora . ' Diurna Festiva ' . $formatearMoneda($valorHoraDiurnaFestiva);
            }
            if ($valorHoraNocturnaFestiva) {
                $partesHora[] = $prefijoHora . ' Nocturna Festiva ' . $formatearMoneda($valorHoraNocturnaFestiva);
            }

            if (!empty($partesHora)) {
                $texto = implode('; ', $partesHora) . '.';

                if ($auxilioTransporte) {
                    $horasTexto = $horas ? $horas . ' horas' : '186 horas';
                    $texto .= ' El afiliado participe recibirá un auxilio de transporte correspondiente a  ' .
                        $formatearMoneda($auxilioTransporte) . ' por ' . $horasTexto .
                        ' o proporción de las mismas sin que este valor exceda ese monto en caso de superarse las ' .
                        ($horas ? $horas : '186') . ' horas.';
                }

                if ($auxilioManutencion) {
                    $horasTexto = $horas ? $horas . ' horas' : '186 horas';
                    $texto .= ' Prosalud cancelará un auxilio de manutencion no constitutiva de compensación básica de  ' .
                        $formatearMoneda($auxilioManutencion) . ' por la prestación efectiva de las ' . $horasTexto .
                        ', la cual será proporcional a las mismas pero que en ningún caso excederá dicho valor.';
                }

                if ($auxilioEncierro) {
                    $horasTexto = $horas ? $horas . ' horas' : '186 horas';
                    $texto .= ' Prosalud cancelará un auxilio de encierro por valor de  ' .
                        $formatearMoneda($auxilioEncierro) . ' en caso de que el afiliado participe realice las ' .
                        $horasTexto . ' o proporción pero que en ningún caso excederá dicho valor.';
                }

                if ($auxilioProsalud) {
                    $horasTexto = $horas ? $horas . ' horas' : '186 horas';
                    $texto .= ' Prosalud cancelará un auxilio prosalud no constitutiva de compensación básica de  ' .
                        $formatearMoneda($auxilioProsalud) . ' por la prestación efectiva de las ' . $horasTexto .
                        ', la cual será proporcional a las mismas pero que en ningún caso excederá dicho valor.';
                }
            }
        }
        // PATRÓN 2: Valores por hora + auxilios por hora
        elseif ($tieneValoresHora && $tieneAuxiliosPorHora) {
            $patronUsado = 'Patrón 2: Valores por hora + auxilios por hora';
            Log::debug('[CONVENIO GENERATION] Usando patrón 2: Valores por hora + auxilios por hora', [
                'valores_hora_presentes' => [
                    'diurna' => !empty($valorHoraDiurna),
                    'nocturna' => !empty($valorHoraNocturna),
                    'diurna_festiva' => !empty($valorHoraDiurnaFestiva),
                    'nocturna_festiva' => !empty($valorHoraNocturnaFestiva),
                ],
                'auxilios_por_hora_presentes' => [
                    'diurno' => !empty($auxilioDiurno),
                    'recargo_nocturno' => !empty($auxilioRecargoNocturno),
                    'recargo_festivo' => !empty($auxilioRecargoFestivo),
                    'recargo_festivo_nocturno' => !empty($auxilioRecargoFestivoNocturno),
                ],
            ]);
            
            $partesHora = [];
            if ($valorHoraDiurna) {
                $partesHora[] = 'HORA DIURNA ' . $formatearMoneda($valorHoraDiurna);
            }
            if ($valorHoraNocturna) {
                $partesHora[] = 'HORA NOCTURNA ' . $formatearMoneda($valorHoraNocturna);
            }
            if ($valorHoraDiurnaFestiva) {
                $partesHora[] = 'HORA FESTIVA ' . $formatearMoneda($valorHoraDiurnaFestiva);
            }
            if ($valorHoraNocturnaFestiva) {
                $partesHora[] = 'HORA NOCTURNA FESTIVA ' . $formatearMoneda($valorHoraNocturnaFestiva);
            }

            if (!empty($partesHora)) {
                $texto = implode('; ', $partesHora);

                $partesAuxilio = [];
                if ($auxilioDiurno) {
                    $partesAuxilio[] = 'HORA DIURNA ' . $formatearMoneda($auxilioDiurno);
                }
                if ($auxilioRecargoNocturno) {
                    $partesAuxilio[] = 'HORA NOCTURNA ' . $formatearMoneda($auxilioRecargoNocturno);
                }
                if ($auxilioRecargoFestivo) {
                    $partesAuxilio[] = 'HORA FESTIVA ' . $formatearMoneda($auxilioRecargoFestivo);
                }
                if ($auxilioRecargoFestivoNocturno) {
                    $partesAuxilio[] = 'HORA NOCTURNA FESTIVA ' . $formatearMoneda($auxilioRecargoFestivoNocturno);
                }

                if (!empty($partesAuxilio)) {
                    $texto .= ' y unos AUXILIOS por ' . implode('; ', $partesAuxilio) . '.';
                }
                
                Log::debug('[CONVENIO GENERATION] Patrón 2 - Partes construidas', [
                    'partes_hora_count' => count($partesHora),
                    'partes_auxilio_count' => count($partesAuxilio),
                    'texto_preview' => substr($texto, 0, 200) . '...',
                ]);
            }
        }
        // PATRÓN 3: Básico + auxilios + provisiones (devolución de compensaciones)
        elseif ($basico || $auxilios || $provisiones || $auxilioRodamiento || $auxilioEspecial) {
            $patronUsado = 'Patrón 3: Básico + auxilios + provisiones';
            Log::debug('[CONVENIO GENERATION] Usando patrón 3: Básico + auxilios + provisiones', [
                'valores_presentes' => [
                    'basico' => !empty($basico),
                    'auxilios' => !empty($auxilios),
                    'provisiones' => !empty($provisiones),
                    'auxilio_rodamiento' => !empty($auxilioRodamiento),
                    'auxilio_especial' => !empty($auxilioEspecial),
                ],
            ]);
            
            $texto = '';

            // Si hay básico, empezar con el básico
            if ($basico) {
                $texto = $formatearMoneda($basico);
            }

            // Si hay básico Y hay auxilios (con valor real), agregar auxilio no constitutivo (auxilios generales)
            // Si solo hay básico sin auxilios (null o 0), no se agrega esta línea
            if ($basico && $auxilios !== null && $auxilios !== 0 && $auxilios !== '0') {
                $texto .= ' y un AUXILIO no constitutivo de compensación básica por: ';
                $texto .= $formatearMoneda($auxilios);
            } elseif ($auxilios && $auxilios !== 0 && $auxilios !== '0' && !$basico) {
                // Si no hay básico pero hay auxilios, empezar con auxilios
                $texto = $formatearMoneda($auxilios) . ' y un AUXILIO no constitutivo de compensación básica por:';
            }

            // Agregar auxilio especial (nuevo campo, diferente de auxilio_de_rodamiento)
            // Priorizar auxilio_especial sobre auxilio_de_rodamiento si ambos están presentes
            if ($auxilioEspecial) {
                // Si solo hay auxilio_especial sin básico ni auxilios, empezar con "un" en lugar de "y un"
                if (empty($basico) && empty($auxilios) && empty($provisiones)) {
                    $texto = 'un AUXILIO especial por: ' . $formatearMoneda($auxilioEspecial);
                } else {
                    $texto .= (!empty($texto) ? ' ' : '') . 'y un AUXILIO especial por: ' . $formatearMoneda($auxilioEspecial);
                }
            } elseif ($auxilioRodamiento) {
                // Mantener compatibilidad con auxilio_de_rodamiento (legacy)
                $texto .= (!empty($texto) ? ' ' : '') . 'y un AUXILIO especial por: ' . $formatearMoneda($auxilioRodamiento);
            }

            // Agregar devolución de compensaciones (provisiones)
            if ($provisiones) {
                $texto .= (!empty($texto) ? ' ' : '') . 'y una devolución de compensaciones por valor de: ' . $formatearMoneda($provisiones);
            }

            if (!empty($texto)) {
                $texto .= '.';
            }
        }

        Log::info('[CONVENIO GENERATION] Compensación básica redactada generada automáticamente', [
            'patron_usado' => $patronUsado ?? 'Ninguno (texto vacío)',
            'tiene_valores_hora' => $tieneValoresHora,
            'tiene_auxilios_especiales' => $tieneAuxiliosEspeciales,
            'tiene_auxilios_por_hora' => $tieneAuxiliosPorHora,
            'tiene_basico' => !empty($basico),
            'tiene_auxilios' => !empty($auxilios),
            'tiene_provisiones' => !empty($provisiones),
            'texto_generado_length' => strlen($texto),
            'texto_generado_preview' => !empty($texto) ? substr($texto, 0, 200) . (strlen($texto) > 200 ? '...' : '') : 'vacío',
            'texto_completo' => $texto, // Log completo para debugging
        ]);

        return $texto;
    }

    /**
     * Normaliza un valor numérico removiendo separadores de miles
     *
     * @param mixed $valor Valor a normalizar
     * @return float|null Valor normalizado o null si está vacío
     */
    private function normalizarValor($valor): ?float
    {
        if (empty($valor) && $valor !== '0' && $valor !== 0) {
            return null;
        }

        if (is_numeric($valor)) {
            return (float) $valor;
        }

        // Si es string, remover separadores de miles
        $valorString = (string) $valor;
        $normalizado = str_replace([',', '.', ' '], '', $valorString);
        $normalizado = trim($normalizado);

        if (is_numeric($normalizado) && $normalizado !== '') {
            return (float) $normalizado;
        }

        return null;
    }

    /**
     * Obtiene datos del afiliado por documento
     *
     * @param string $documento Número de documento normalizado
     * @return array|null Datos del afiliado o null si no se encuentra
     */
    private function obtenerDatosAfiliado(string $documento): ?array
    {
        Log::debug('[CONVENIO GENERATION] Obteniendo datos completos de afiliado', [
            'documento' => $documento,
        ]);

        try {
            $afiliadoData = $this->afiliadoService->getAfiliadoByDocumentoOnly($documento);
            
            if (!$afiliadoData) {
                Log::debug('[CONVENIO GENERATION] Afiliado no encontrado en servicio', [
                    'documento' => $documento,
                ]);
                return null;
            }

            // Obtener datos completos del afiliado para dirección, teléfono y celular
            $excelPath = 'data/PROSANET_INFORMACION_AFILIADOS.xlsx';
            $disks = ['prosalud-private', 'local'];

            Log::debug('[CONVENIO GENERATION] Buscando datos de contacto en archivo de afiliados', [
                'documento' => $documento,
                'excel_path' => $excelPath,
                'disks' => $disks,
            ]);

            foreach ($disks as $disk) {
                try {
                    Log::debug('[CONVENIO GENERATION] Intentando acceder a archivo de afiliados', [
                        'documento' => $documento,
                        'disk' => $disk,
                        'excel_path' => $excelPath,
                    ]);

                    if (!Storage::disk($disk)->exists($excelPath)) {
                        Log::debug('[CONVENIO GENERATION] Archivo no existe en disco', [
                            'documento' => $documento,
                            'disk' => $disk,
                            'excel_path' => $excelPath,
                        ]);
                        continue;
                    }

                    $stream = Storage::disk($disk)->readStream($excelPath);
                    if (false === $stream) {
                        Log::warning('[CONVENIO GENERATION] No se pudo leer stream del archivo', [
                            'documento' => $documento,
                            'disk' => $disk,
                        ]);
                        continue;
                    }

                    $tempPath = tempnam(sys_get_temp_dir(), 'prosanet_convenio_') . '.xlsx';
                    $destination = fopen($tempPath, 'w+b');
                    if (false === $destination) {
                        fclose($stream);
                        Log::warning('[CONVENIO GENERATION] No se pudo crear archivo temporal', [
                            'documento' => $documento,
                            'temp_path' => $tempPath,
                        ]);
                        continue;
                    }

                    stream_copy_to_stream($stream, $destination);
                    fclose($stream);
                    fclose($destination);

                    Log::debug('[CONVENIO GENERATION] Archivo temporal creado', [
                        'documento' => $documento,
                        'temp_path' => $tempPath,
                    ]);

                    try {
                        $reader = IOFactory::createReader('Xlsx');
                        if (method_exists($reader, 'setReadDataOnly')) {
                            $reader->setReadDataOnly(true);
                        }

                        if (method_exists($reader, 'setLoadSheetsOnly')) {
                            $reader->setLoadSheetsOnly(['INFORMACIÓN GENERAL']);
                        }

                        $spreadsheet = $reader->load($tempPath);
                        $informacionSheet = $spreadsheet->getSheetByName('INFORMACIÓN GENERAL');

                        if (!$informacionSheet) {
                            @unlink($tempPath);
                            Log::warning('[CONVENIO GENERATION] Hoja INFORMACIÓN GENERAL no encontrada', [
                                'documento' => $documento,
                            ]);
                            continue;
                        }

                        $highestRow = $informacionSheet->getHighestRow();
                        $normalizedDocumento = $this->normalizeDocumento($documento);

                        Log::debug('[CONVENIO GENERATION] Buscando documento en archivo de afiliados', [
                            'documento' => $documento,
                            'documento_normalizado' => $normalizedDocumento,
                            'total_filas' => $highestRow,
                        ]);

                        // Buscar por documento
                        for ($rowIndex = 2; $rowIndex <= $highestRow; ++$rowIndex) {
                            $cell = $informacionSheet->getCell('B' . $rowIndex);
                            $rowDocumento = $this->normalizeDocumento($cell->getValue());

                            if ($rowDocumento === $normalizedDocumento) {
                                // Leer columnas: DIRECCION (13), CELULAR (17)
                                $direccion = $this->getCellValue($informacionSheet->getCell('N' . $rowIndex));
                                $celular = $this->getCellValue($informacionSheet->getCell('R' . $rowIndex));

                                Log::info('[CONVENIO GENERATION] Datos de contacto encontrados en archivo de afiliados', [
                                    'documento' => $documento,
                                    'fila' => $rowIndex,
                                    'tiene_direccion' => !empty(trim($direccion)),
                                    'tiene_celular' => !empty(trim($celular)),
                                ]);

                                $spreadsheet->disconnectWorksheets();
                                unset($spreadsheet);
                                @unlink($tempPath);

                                return [
                                    'direccion' => trim($direccion),
                                    'celular' => trim($celular),
                                ];
                            }
                        }

                        Log::debug('[CONVENIO GENERATION] Documento no encontrado en archivo de afiliados', [
                            'documento' => $documento,
                            'documento_normalizado' => $normalizedDocumento,
                            'filas_revisadas' => $highestRow - 1,
                        ]);

                        $spreadsheet->disconnectWorksheets();
                        unset($spreadsheet);
                        @unlink($tempPath);
                    } catch (\Exception $e) {
                        @unlink($tempPath);
                        Log::error('[CONVENIO GENERATION] Error leyendo archivo de afiliados', [
                            'error' => $e->getMessage(),
                            'documento' => $documento,
                            'disk' => $disk,
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('[CONVENIO GENERATION] Error accediendo a archivo de afiliados', [
                        'error' => $e->getMessage(),
                        'disk' => $disk,
                        'documento' => $documento,
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            Log::debug('[CONVENIO GENERATION] No se encontraron datos de contacto en ningún disco', [
                'documento' => $documento,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('[CONVENIO GENERATION] Error obteniendo datos de afiliado para convenio', [
                'error' => $e->getMessage(),
                'documento' => $documento,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Formatea un documento agregando comas cada 3 dígitos
     *
     * @param string $documento Número de documento
     * @return string Documento formateado
     */
    private function formatearDocumentoConComas(string $documento): string
    {
        // Remover caracteres no numéricos
        $documento = preg_replace('/[^0-9]/', '', $documento);
        
        if (empty($documento)) {
            return '';
        }

        // Agregar comas cada 3 dígitos desde la derecha
        return number_format((int) $documento, 0, '', ',');
    }

    /**
     * Formatea fecha en español
     *
     * @param mixed $fecha Fecha a formatear
     * @return string Fecha formateada
     */
    private function formatearFechaEspanol($fecha): string
    {
        if (!$fecha) {
            return '';
        }

        try {
            $carbon = Carbon::parse($fecha);
            $mes = $this->obtenerMesEspanol($carbon->month);

            return "{$carbon->day} de {$mes} de {$carbon->year}";
        } catch (\Exception $e) {
            Log::warning('Error formateando fecha para convenio', [
                'fecha' => $fecha,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Formatea la fecha de nacimiento en formato dd/mm/aaaa
     *
     * @param mixed $fecha
     * @return string
     */
    private function formatearFechaNacimiento($fecha): string
    {
        if (!$fecha) {
            return '';
        }

        try {
            $carbon = Carbon::parse($fecha);

            return $carbon->format('d/m/Y');
        } catch (\Exception $e) {
            Log::warning('Error formateando fecha de nacimiento para convenio', [
                'fecha' => $fecha,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Obtiene el nombre del mes en español
     *
     * @param int $mes Número del mes (1-12)
     * @return string Nombre del mes
     */
    private function obtenerMesEspanol(int $mes): string
    {
        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
        ];

        return $meses[$mes] ?? '';
    }

    /**
     * Genera el texto de duración según si existe fecha de finalización o no
     *
     * @param mixed $fechaInicio Fecha de inicio
     * @param mixed $fechaFinalizacion Fecha de finalización (puede estar vacía)
     * @return string Texto de duración formateado
     */
    private function calcularDuracion($fechaInicio, $fechaFinalizacion): string
    {
        Log::debug('[CONVENIO GENERATION] Calculando duración', [
            'fecha_inicio' => $fechaInicio,
            'fecha_finalizacion' => $fechaFinalizacion,
            'tiene_fecha_fin' => !empty($fechaFinalizacion),
        ]);

        // Si hay fecha de finalización definida
        if (!empty($fechaFinalizacion)) {
            try {
                $fechaInicioFormateada = $this->formatearFechaEspanol($fechaInicio);
                $fechaFinFormateada = $this->formatearFechaEspanol($fechaFinalizacion);

                if (empty($fechaInicioFormateada) || empty($fechaFinFormateada)) {
                    // Si no se pueden formatear las fechas, usar mensaje por defecto
                    Log::warning('[CONVENIO GENERATION] No se pudieron formatear fechas, usando mensaje por defecto', [
                        'fecha_inicio' => $fechaInicio,
                        'fecha_finalizacion' => $fechaFinalizacion,
                        'fecha_inicio_formateada' => $fechaInicioFormateada,
                        'fecha_fin_formateada' => $fechaFinFormateada,
                    ]);
                    return $this->obtenerMensajeDuracionSinFechaFin();
                }

                Log::debug('[CONVENIO GENERATION] Duración generada con fechas definidas', [
                    'fecha_inicio_formateada' => $fechaInicioFormateada,
                    'fecha_fin_formateada' => $fechaFinFormateada,
                ]);

                return "Desde el {$fechaInicioFormateada} al {$fechaFinFormateada}; debido a que su vigencia depende directamente de la permanencia del vínculo estatutario que el AFILIADO PARTICIPE tenga con PROSALUD, imponiéndole el cumplimiento de obligaciones y el disfrute de derechos mientras permanezca afiliado. Este vínculo también estará supeditado a lo dispuesto en el reglamento del contrato sindical";
            } catch (\Exception $e) {
                Log::error('[CONVENIO GENERATION] Error formateando fechas para duración con fecha fin', [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_finalizacion' => $fechaFinalizacion,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return $this->obtenerMensajeDuracionSinFechaFin();
            }
        }

        // Si NO hay fecha de finalización (caso por defecto)
        Log::debug('[CONVENIO GENERATION] Usando mensaje de duración por defecto (sin fecha fin)', [
            'fecha_inicio' => $fechaInicio,
        ]);
        return $this->obtenerMensajeDuracionSinFechaFin();
    }

    /**
     * Obtiene el mensaje de duración cuando no hay fecha de finalización
     *
     * @return string Mensaje por defecto
     */
    private function obtenerMensajeDuracionSinFechaFin(): string
    {
        return "La duración será definida por el tiempo de duración del CONTRATO SINDICAL que suscriban la Entidad contratante y el Sindicato, además de la necesidad del servicio que tenga la Entidad contratante, dependiendo así mismo del cumplimiento de obligaciones por parte del AFILIADO PARTICIPE. Este vínculo también estará supeditado a lo dispuesto en el reglamento del contrato sindical";
    }

    /**
     * Genera el nombre del archivo para el convenio
     *
     * @param array $data Datos del convenio
     * @return string Nombre del archivo
     */
    private function generarNombreArchivo(array $data): string
    {
        $documento = preg_replace('/[^0-9]/', '', $data['numero_documento'] ?? '');
        $apellidos = preg_replace('/[^a-zA-Z0-9]/', '_', $data['apellidos'] ?? '');
        $nombres = preg_replace('/[^a-zA-Z0-9]/', '_', $data['nombres'] ?? '');

        $nombreBase = "Convenio_{$documento}_{$apellidos}_{$nombres}";

        return $nombreBase . '.docx';
    }

    /**
     * Normaliza un documento removiendo puntos, espacios y caracteres especiales
     *
     * @param mixed $value Valor a normalizar
     * @return string Documento normalizado
     */
    private function normalizeDocumento($value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        return preg_replace('/[.\s-]/', '', (string) $value);
    }

    /**
     * Obtiene el valor de una celda de Excel
     *
     * @param mixed $cell Celda de Excel
     * @return string Valor de la celda
     */
    private function getCellValue($cell): string
    {
        $value = $cell->getValue();
        
        if ($value === null) {
            return '';
        }

        // Si es una fórmula calculada, obtener el valor calculado
        if ($cell->getDataType() === 'f') {
            $value = $cell->getCalculatedValue();
        }

        return trim((string) $value);
    }

    /**
     * Convierte un convenio Word a PDF (comentado para implementación futura)
     * 
     * @param string $rutaWord Ruta del archivo Word
     * @return array Resultado con ruta del PDF
     */
    /*
    public function convertirConvenioAPDF(string $rutaWord): array
    {
        // TODO: Implementar conversión a PDF usando CloudConvert
        // Similar a como se hace en CertificadoConvenioService
        // 
        // Ejemplo:
        // $converterService = app(\App\Services\DocxToPdfCloudConvertService::class);
        // $resultadoPDF = $converterService->convert($rutaWord, true);
        // 
        // return $resultadoPDF;
        
        throw new \Exception('Conversión a PDF no implementada aún');
    }
    */
}

