<?php

namespace App\Http\Controllers;

use App\Http\Requests\{ExportSocioDemographicSurveysExcelRequest, ExportSocioDemographicSurveysPdfRequest, StoreSocioDemographicSurveyRequest};
use App\Jobs\GenerateBulkSurveyPdfJob;
use App\Models\SocioDemographicSurvey;
use App\Services\{AuditLogService, SocioDemographicSurveyExcelExportService};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\{DB, Log, Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, StreamedResponse};

class SocioDemographicSurveyController extends Controller
{
    public function __construct(
        private AuditLogService $auditLogService,
        private SocioDemographicSurveyExcelExportService $excelExportService,
    ) {
    }

    /**
     * Store a new socio-demographic survey.
     */
    public function store(StoreSocioDemographicSurveyRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            // Procesar y almacenar la firma digital
            $firmaPath = $this->processAndStoreSignature($request, $validated['numeroDocumentoFirma']);

            // Organizar datos sociodemográficos
            $datosSociodemograficos = [
                'tienePersonasACargo' => $validated['tienePersonasACargo'],
                'estadoCivil' => $validated['estadoCivil'],
                'fechaNacimiento' => $validated['fechaNacimiento'],
                'estatura' => $validated['estatura'],
                'peso' => $validated['peso'],
                'genero' => $validated['genero'],
                'raza' => $validated['raza'],
                'nivelEducativo' => $validated['nivelEducativo'],
                'numeroHijos' => $validated['numeroHijos'] ?? null,
                'hijos' => $validated['hijos'] ?? [],
                'numeroPersonasDependientes' => $validated['numeroPersonasDependientes'] ?? '0',
                'vivienda' => $validated['vivienda'],
                'serviciosPublicos' => $validated['serviciosPublicos'],
                'estratoSocioeconomico' => $validated['estratoSocioeconomico'],
                'conviveCon' => $validated['conviveCon'],
                'transporte' => $validated['transporte'],
                'manejoTiempoLibre' => $validated['manejoTiempoLibre'],
                'tiempoLibreCon' => $validated['tiempoLibreCon'],
            ];

            // Organizar datos de consumo
            $datosConsumo = [
                'consumoLicor' => $validated['consumoLicor'],
                'frecuenciaLicor' => $validated['frecuenciaLicor'] ?? null,
                'consumoCigarrillo' => $validated['consumoCigarrillo'],
                'frecuenciaCigarrillo' => $validated['frecuenciaCigarrillo'] ?? null,
            ];

            // Organizar condiciones de salud
            $condicionesSalud = [
                'sobrepesoObesidad' => $validated['sobrepesoObesidad'],
                'hipertensionArterial' => $validated['hipertensionArterial'],
                'enfermedadesCorazon' => $validated['enfermedadesCorazon'],
                'diabetes' => $validated['diabetes'],
                'problemasRenales' => $validated['problemasRenales'],
                'depresionBipolaridad' => $validated['depresionBipolaridad'],
                'antecedentesMedicosMentales' => $validated['antecedentesMedicosMentales'],
                'epilepsiaConvulsiones' => $validated['epilepsiaConvulsiones'],
                'trasplante' => $validated['trasplante'],
                'tipoTrasplante' => $validated['tipoTrasplante'] ?? null,
                'cancer' => $validated['cancer'],
                'problemasPulmonares' => $validated['problemasPulmonares'],
                'tipoProblemaPulmonar' => $validated['tipoProblemaPulmonar'] ?? null,
                'alergias' => $validated['alergias'],
                'tipoAlergia' => $validated['tipoAlergia'] ?? null,
                'tuberculosis' => $validated['tuberculosis'],
                'problemasVisuales' => $validated['problemasVisuales'],
                'tipoProblemaVisual' => $validated['tipoProblemaVisual'] ?? null,
                'doloresArticulares' => $validated['doloresArticulares'],
                'tipoDolorArticular' => $validated['tipoDolorArticular'] ?? null,
                'problemasSangre' => $validated['problemasSangre'],
                'otraEnfermedad' => $validated['otraEnfermedad'],
                'tipoOtraEnfermedad' => $validated['tipoOtraEnfermedad'] ?? null,
                'protesisArticular' => $validated['protesisArticular'],
                'medicamentoPermanente' => $validated['medicamentoPermanente'],
                'tipoMedicamento' => $validated['tipoMedicamento'] ?? null,
                'tratamientoMedico' => $validated['tratamientoMedico'],
                'cirugias' => $validated['cirugias'],
                'tipoCirugia' => $validated['tipoCirugia'] ?? null,
                'tiempoCirugia' => $validated['tiempoCirugia'] ?? null,
                'accidenteLaboral' => $validated['accidenteLaboral'],
                'tipoAccidenteLaboral' => $validated['tipoAccidenteLaboral'] ?? null,
                'tiempoAccidenteLaboral' => $validated['tiempoAccidenteLaboral'] ?? null,
                'accidenteTransitoCasero' => $validated['accidenteTransitoCasero'],
                'tipoAccidenteTransito' => $validated['tipoAccidenteTransito'] ?? null,
                'tiempoAccidenteTransito' => $validated['tiempoAccidenteTransito'] ?? null,
                'vacunadoCovid' => $validated['vacunadoCovid'],
            ];

            // Organizar limitaciones físicas
            $limitacionesFisicas = [
                'esfuerzosIntensos' => $validated['esfuerzosIntensos'],
                'esfuerzosModerados' => $validated['esfuerzosModerados'],
                'subirPisos' => $validated['subirPisos'],
                'agacharseArrodillarse' => $validated['agacharseArrodillarse'],
            ];

            // Determinar el tipo de encuesta según si el afiliado existe o es nuevo ingreso
            // La encuesta siempre está habilitada públicamente
            // Si el afiliado existe y está activo, es "active_affiliate"
            // Si el afiliado no existe o no está activo, es "new_entry" (nuevo ingreso)
            // Mantener compatibilidad con encuestas existentes que usaron "bulk_entry"
            $surveyType = 'new_entry'; // Por defecto, asumimos nuevo ingreso hasta verificar
            
            // Intentar verificar si el afiliado existe en el sistema
            // Nota: fechaExpedicion es opcional, puede no estar disponible
            try {
                $afiliadoService = app(\App\Services\AfiliadoService::class);
                if ($afiliadoService->isFileAvailable()) {
                    // Intentar autenticar con fechaExpedicion si está disponible
                    $fechaExpedicion = $validated['fechaExpedicion'] ?? '';
                    $afiliado = null;
                    
                    if (!empty($fechaExpedicion)) {
                        $afiliado = $afiliadoService->authenticateAndGetAfiliado(
                            $validated['tipoDocumento'],
                            $validated['numeroDocumento'],
                            $fechaExpedicion
                        );
                    }
                    
                    // Si el afiliado existe y está activo, es afiliado activo
                    if (null !== $afiliado) {
                        $estado = strtoupper(trim($afiliado['estado'] ?? ''));
                        $isActivo = $estado === 'ACTIVO';
                        
                        Log::info('Verificación de estado de afiliado para tipo de encuesta', [
                            'tipo_documento' => $validated['tipoDocumento'],
                            'numero_documento' => $validated['numeroDocumento'],
                            'estado' => $afiliado['estado'] ?? 'N/A',
                            'estado_normalizado' => $estado,
                            'es_activo' => $isActivo,
                        ]);
                        
                        if ($isActivo) {
                            $surveyType = 'active_affiliate';
                        }
                    } else {
                        Log::info('Afiliado no encontrado en autenticación, marcando como nuevo ingreso', [
                            'tipo_documento' => $validated['tipoDocumento'],
                            'numero_documento' => $validated['numeroDocumento'],
                            'fecha_expedicion_provista' => !empty($fechaExpedicion),
                        ]);
                    }
                } else {
                    // Si el archivo no está disponible, asumimos nuevo ingreso
                    Log::info('Archivo de afiliados no disponible, marcando como nuevo ingreso');
                }
            } catch (\Exception $e) {
                // Si hay error al verificar, asumimos nuevo ingreso
                Log::warning('Error al verificar estado del afiliado para determinar tipo de encuesta', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'tipo_documento' => $validated['tipoDocumento'],
                    'numero_documento' => $validated['numeroDocumento'],
                ]);
            }

            // Crear el registro de la encuesta
            $survey = new SocioDemographicSurvey([
                'survey_type' => $surveyType,
                'correo' => $validated['correo'],
                'tipo_documento' => $validated['tipoDocumento'],
                'numero_documento' => $validated['numeroDocumento'],
                'nombres' => $validated['nombres'] ?? null,
                'apellidos' => $validated['apellidos'] ?? null,
                'hospital' => $validated['hospital'] ?? null,
                'profesion' => $validated['profesion'] ?? null,
                'rh' => $validated['rh'] ?? null,
                'fecha_expedicion' => $validated['fechaExpedicion'] ?? null,
                'lugar_nacimiento' => $validated['lugarNacimiento'] ?? null,
                'departamento' => $validated['departamento'] ?? null,
                'celular' => $validated['celular'] ?? null,
                'direccion' => $validated['direccion'] ?? null,
                'municipio' => $validated['municipio'] ?? null,
                'talla_calzado' => $validated['tallaCalzado'] ?? null,
                'talla_vestimenta' => $validated['tallaVestimenta'] ?? null,
                'pais_nacimiento' => $validated['paisNacimiento'] ?? 'colombia',
                'nombre_contacto_emergencia' => $validated['nombreContactoEmergencia'] ?? null,
                'relacion_contacto_emergencia' => $validated['relacionContactoEmergencia'] ?? null,
                'telefono_contacto_emergencia' => $validated['telefonoContactoEmergencia'] ?? null,
                'datos_sociodemograficos' => $datosSociodemograficos,
                'datos_consumo' => $datosConsumo,
                'condiciones_salud' => $condicionesSalud,
                'limitaciones_fisicas' => $limitacionesFisicas,
                'recomendacion_restriccion_laboral' => $validated['recomendacionRestriccionLaboral'],
                'detalle_recomendacion_laboral' => $validated['detalleRecomendacionLaboral'] ?? null,
                'firma_path' => $firmaPath,
                'numero_documento_firma' => $validated['numeroDocumentoFirma'],
                'created_at' => now(),
            ]);

            $survey->save();

            Log::info('Nueva encuesta sociodemográfica procesada', [
                'survey_id' => $survey->id,
                'survey_type' => $survey->survey_type,
                'tipo_documento' => $survey->tipo_documento,
                'numero_documento' => $survey->numero_documento,
                'correo' => $survey->correo,
                'hospital' => $survey->hospital,
                'timestamp' => $survey->formatted_created_at,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $this->auditLogService->logBusinessProcess('socio_demographic_survey', 'created', $this->auditLogService->addRequestContext($request, [
                'survey_id' => $survey->id,
                'survey_type' => $survey->survey_type,
                'affiliate_document' => $survey->numero_documento,
                'affiliate_email' => $survey->correo,
                'hospital' => $survey->hospital,
            ]));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Encuesta sociodemográfica registrada exitosamente',
                'data' => [
                    'id' => $survey->id,
                    'survey_type' => $survey->survey_type,
                    'tipo_documento' => $survey->tipo_documento,
                    'numero_documento' => $survey->numero_documento,
                    'created_at' => $survey->formatted_created_at,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error al procesar encuesta sociodemográfica', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip_address' => $request->ip(),
                'request_data' => $request->except(['firma', 'files']),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la encuesta sociodemográfica. Por favor, intente nuevamente.',
            ], 500);
        }
    }

    /**
     * List all surveys (requires authentication and permission).
     */
    public function index(Request $request): JsonResponse
    {
        $query = SocioDemographicSurvey::query();

        // Filtros opcionales
        if ($request->has('hospital')) {
            $query->byHospital($request->input('hospital'));
        }

        if ($request->has('tipo_documento') && $request->has('numero_documento')) {
            $query->byDocument($request->input('tipo_documento'), $request->input('numero_documento'));
        }

        // Filtro por nombre del encuestado
        if ($request->has('nombre')) {
            $query->byName($request->input('nombre'));
        }

        // Filtro por tipo de encuesta
        if ($request->has('survey_type')) {
            $surveyType = $request->input('survey_type');
            if ($surveyType === 'active_affiliate') {
                $query->where(function ($q) {
                    $q->where('survey_type', 'active_affiliate')
                      ->orWhereNull('survey_type');
                });
            } elseif ($surveyType === 'new_entry') {
                $query->where('survey_type', 'new_entry');
            } elseif ($surveyType === 'bulk_entry') {
                // Compatibilidad con encuestas antiguas
                $query->where('survey_type', 'bulk_entry');
            }
            // Si es 'all' o cualquier otro valor, no se aplica filtro
        }

        // Paginación
        $perPage = min($request->input('per_page', 15), 100);
        $surveys = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Asegurar que survey_type esté incluido en cada item
        $surveysData = $surveys->getCollection()->map(function ($survey) {
            return [
                'id' => $survey->id,
                'survey_type' => $survey->survey_type ?? 'active_affiliate', // valor por defecto para encuestas antiguas
                'correo' => $survey->correo,
                'tipo_documento' => $survey->tipo_documento,
                'numero_documento' => $survey->numero_documento,
                'nombres' => $survey->nombres,
                'apellidos' => $survey->apellidos,
                'hospital' => $survey->hospital,
                'profesion' => $survey->profesion,
                'created_at' => $survey->created_at?->format('Y-m-d H:i:s'),
                'formatted_created_at' => $survey->formatted_created_at,
            ];
        });

        // Calcular métricas de trazabilidad
        $baseQuery = SocioDemographicSurvey::query();
        
        // Aplicar los mismos filtros para las métricas
        if ($request->has('hospital')) {
            $baseQuery->byHospital($request->input('hospital'));
        }

        if ($request->has('tipo_documento') && $request->has('numero_documento')) {
            $baseQuery->byDocument($request->input('tipo_documento'), $request->input('numero_documento'));
        }

        // Aplicar filtro por nombre en métricas
        if ($request->has('nombre')) {
            $baseQuery->byName($request->input('nombre'));
        }

        // Aplicar filtro por tipo de encuesta en métricas
        if ($request->has('survey_type')) {
            $surveyType = $request->input('survey_type');
            if ($surveyType === 'active_affiliate') {
                $baseQuery->where(function ($q) {
                    $q->where('survey_type', 'active_affiliate')
                      ->orWhereNull('survey_type');
                });
            } elseif ($surveyType === 'new_entry') {
                $baseQuery->where('survey_type', 'new_entry');
            } elseif ($surveyType === 'bulk_entry') {
                // Compatibilidad con encuestas antiguas
                $baseQuery->where('survey_type', 'bulk_entry');
            }
            // Si es 'all' o cualquier otro valor, no se aplica filtro
        }

        // Total de encuestas
        $totalSurveys = $baseQuery->count();

        // Encuestas del mes actual
        $currentMonthStart = now()->startOfMonth();
        $currentMonthEnd = now()->endOfMonth();
        $surveysCurrentMonth = (clone $baseQuery)
            ->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])
            ->count();

        // Encuestas por tipo
        $surveysByType = (clone $baseQuery)
            ->selectRaw('COALESCE(survey_type, \'active_affiliate\') as survey_type, COUNT(*) as count')
            ->groupBy('survey_type')
            ->pluck('count', 'survey_type')
            ->toArray();

        // Asegurar que todos los tipos estén presentes (incluso si son 0)
        $surveysByType = [
            'active_affiliate' => $surveysByType['active_affiliate'] ?? 0,
            'new_entry' => $surveysByType['new_entry'] ?? 0,
            'bulk_entry' => $surveysByType['bulk_entry'] ?? 0, // Compatibilidad con encuestas antiguas
        ];

        // Encuestas del mes actual por tipo
        $surveysCurrentMonthByType = (clone $baseQuery)
            ->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])
            ->selectRaw('COALESCE(survey_type, \'active_affiliate\') as survey_type, COUNT(*) as count')
            ->groupBy('survey_type')
            ->pluck('count', 'survey_type')
            ->toArray();

        $surveysCurrentMonthByType = [
            'active_affiliate' => $surveysCurrentMonthByType['active_affiliate'] ?? 0,
            'new_entry' => $surveysCurrentMonthByType['new_entry'] ?? 0,
            'bulk_entry' => $surveysCurrentMonthByType['bulk_entry'] ?? 0, // Compatibilidad con encuestas antiguas
        ];

        return response()->json([
            'success' => true,
            'data' => $surveysData,
            'pagination' => [
                'current_page' => $surveys->currentPage(),
                'last_page' => $surveys->lastPage(),
                'per_page' => $surveys->perPage(),
                'total' => $surveys->total(),
            ],
            'metrics' => [
                'total' => $totalSurveys,
                'current_month' => [
                    'total' => $surveysCurrentMonth,
                    'by_type' => $surveysCurrentMonthByType,
                ],
                'by_type' => $surveysByType,
            ],
        ]);
    }

    /**
     * Show a specific survey (requires authentication and permission).
     */
    public function show(SocioDemographicSurvey $survey): JsonResponse
    {
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $survey->id,
                    'survey_type' => $survey->survey_type,
                    'correo' => $survey->correo,
                    'tipo_documento' => $survey->tipo_documento,
                    'numero_documento' => $survey->numero_documento,
                    'nombres' => $survey->nombres,
                    'apellidos' => $survey->apellidos,
                    'hospital' => $survey->hospital,
                'profesion' => $survey->profesion,
                'rh' => $survey->rh,
                'fecha_expedicion' => $survey->fecha_expedicion?->format('Y-m-d'),
                'lugar_nacimiento' => $survey->lugar_nacimiento,
                'departamento' => $survey->departamento,
                'celular' => $survey->celular,
                'direccion' => $survey->direccion,
                'municipio' => $survey->municipio,
                'talla_calzado' => $survey->talla_calzado,
                'talla_vestimenta' => $survey->talla_vestimenta,
                'pais_nacimiento' => $survey->pais_nacimiento,
                'nombre_contacto_emergencia' => $survey->nombre_contacto_emergencia,
                'relacion_contacto_emergencia' => $survey->relacion_contacto_emergencia,
                'telefono_contacto_emergencia' => $survey->telefono_contacto_emergencia,
                'datos_sociodemograficos' => $survey->datos_sociodemograficos,
                'datos_consumo' => $survey->datos_consumo,
                'condiciones_salud' => $survey->condiciones_salud,
                'limitaciones_fisicas' => $survey->limitaciones_fisicas,
                'recomendacion_restriccion_laboral' => $survey->recomendacion_restriccion_laboral,
                'detalle_recomendacion_laboral' => $survey->detalle_recomendacion_laboral,
                'tiene_firma' => !empty($survey->firma_path),
                'firma_download_url' => !empty($survey->firma_path) ? url('/api/socio-demographic-surveys/' . $survey->id . '/signature') : null,
                'numero_documento_firma' => $survey->numero_documento_firma,
                'created_at' => $survey->formatted_created_at,
                'updated_at' => $survey->updated_at?->format('d/m/Y H:i:s'),
            ],
        ]);
    }

    /**
     * Process and store signature (base64 or file upload).
     */
    private function processAndStoreSignature(Request $request, string $numeroDocumento): ?string
    {
        $disk = 'prosalud-private';
        $fallbackDisk = 'local';

        // Intentar obtener la firma como archivo (soporta múltiples formatos: files.firma, files[firma], etc.)
        $allFiles = $request->allFiles();
        $firmaFile = null;

        // Buscar el archivo de firma en diferentes formatos
        if (isset($allFiles['files']['firma'])) {
            $firmaFile = $allFiles['files']['firma'];
        } elseif (isset($allFiles['files.firma'])) {
            $firmaFile = $allFiles['files.firma'];
        } elseif ($request->hasFile('files.firma')) {
            $firmaFile = $request->file('files.firma');
        }

        if ($firmaFile && $firmaFile instanceof \Illuminate\Http\UploadedFile && $firmaFile->isValid()) {
            // Procesar archivo subido
            $extension = $firmaFile->getClientOriginalExtension() ?: 'png';
            $filename = sprintf('firma-%s-%s.%s', $numeroDocumento, Str::uuid(), $extension);
            $storagePath = 'socio-demographic-surveys/signatures/' . date('Y/m') . '/' . $filename;

            $storedPath = Storage::disk($disk)->putFileAs(
                'socio-demographic-surveys/signatures/' . date('Y/m'),
                $firmaFile,
                $filename
            );

            if ($storedPath === false) {
                $storedPath = Storage::disk($fallbackDisk)->putFileAs(
                    'socio-demographic-surveys/signatures/' . date('Y/m'),
                    $firmaFile,
                    $filename
                );
            }

            return $storedPath ?: null;
        }

        // Intentar obtener la firma como base64
        $firmaBase64 = $request->input('firma');
        if ($firmaBase64 && preg_match('/^data:image\/png;base64,/', $firmaBase64)) {
            $base64Data = substr($firmaBase64, strpos($firmaBase64, ',') + 1);
            $fileContent = base64_decode($base64Data, true);

            if ($fileContent !== false) {
                $filename = sprintf('firma-%s-%s.png', $numeroDocumento, Str::uuid());
                $storagePath = 'socio-demographic-surveys/signatures/' . date('Y/m') . '/' . $filename;

                $stored = Storage::disk($disk)->put($storagePath, $fileContent);
                if ($stored === false) {
                    $stored = Storage::disk($fallbackDisk)->put($storagePath, $fileContent);
                }

                return $stored ? $storagePath : null;
            }
        }

        return null;
    }

    /**
     * Download/view signature file (requires authentication and permission).
     */
    public function downloadSignature(SocioDemographicSurvey $survey): Response|BinaryFileResponse|JsonResponse
    {
        if (!$survey->firma_path) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró la firma digital para esta encuesta.',
            ], 404);
        }

        try {
            // Intentar primero con el disco privado
            $disk = Storage::disk('prosalud-private');
            if ($disk->exists($survey->firma_path)) {
                $fileContent = $disk->get($survey->firma_path);
                $mimeType = $disk->mimeType($survey->firma_path) ?: 'image/png';

                return response($fileContent, 200)
                    ->header('Content-Type', $mimeType)
                    ->header('Content-Disposition', 'inline; filename="firma-' . $survey->numero_documento . '.png"');
            }

            // Fallback a local disk
            $localDisk = Storage::disk('local');
            if ($localDisk->exists($survey->firma_path)) {
                $fileContent = $localDisk->get($survey->firma_path);
                $mimeType = $localDisk->mimeType($survey->firma_path) ?: 'image/png';

                return response($fileContent, 200)
                    ->header('Content-Type', $mimeType)
                    ->header('Content-Disposition', 'inline; filename="firma-' . $survey->numero_documento . '.png"');
            }

            return response()->json([
                'success' => false,
                'message' => 'El archivo de firma no existe en el almacenamiento.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error al descargar firma digital', [
                'survey_id' => $survey->id,
                'path' => $survey->firma_path,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al acceder al archivo de firma.',
            ], 500);
        }
    }

    /**
     * Export surveys to Excel file.
     * If signatures are included, the report is generated asynchronously.
     */
    public function exportExcel(ExportSocioDemographicSurveysExcelRequest $request): BinaryFileResponse|JsonResponse
    {
        try {
            $user = $request->user();

            // Preparar filtros
            $dateRange = $request->input('date_range', []);
            $surveyType = $request->input('survey_type', 'all');
            $hospital = $request->input('hospital');
            $profesion = $request->input('profesion');
            $includeSignatures = $request->input('include_signatures', false);

            $filters = [
                'survey_type' => $surveyType,
                'date_range' => [
                    'include_all' => $dateRange['include_all'] ?? true,
                    'start_date' => $dateRange['start_date'] ?? null,
                    'end_date' => $dateRange['end_date'] ?? null,
                ],
                'hospital' => $hospital,
                'profesion' => $profesion,
                'include_signatures' => $includeSignatures,
            ];

            // If signatures are included, generate report asynchronously
            if ($includeSignatures) {
                $jobId = Str::uuid()->toString();

                // Store initial status in cache
                cache()->put(
                    "survey_report:{$jobId}",
                    [
                        'status' => 'processing',
                        'created_at' => now()->toIso8601String(),
                    ],
                    now()->addHours(24)
                );

                // Generate report asynchronously after response (no workers needed)
                $excelExportService = $this->excelExportService;
                $auditLogService = $this->auditLogService;
                dispatch(function () use ($excelExportService, $auditLogService, $filters, $jobId, $user) {
                    try {
                        Log::info('Iniciando generación asíncrona de reporte de encuestas sociodemográficas', [
                            'job_id' => $jobId,
                            'include_signatures' => true,
                            'user_id' => $user?->id,
                        ]);

                        // Generate report
                        $filePath = $excelExportService->generateReport($filters);

                        if (!file_exists($filePath)) {
                            throw new \Exception('El archivo del reporte no fue creado');
                        }

                        // Generate file name
                        $fileName = 'Reporte_Encuestas_Sociodemograficas_ProSalud_' . now()->setTimezone('America/Bogota')->format('Y-m-d_His') . '.xlsx';

                        // Store file in storage for later download
                        $storagePath = 'reports/surveys/' . $jobId . '/' . $fileName;
                        $disk = Storage::disk('local');
                        $disk->put($storagePath, file_get_contents($filePath));

                        // Clean up temporary file
                        @unlink($filePath);

                        // Store metadata in cache for retrieval
                        cache()->put(
                            "survey_report:{$jobId}",
                            [
                                'status' => 'completed',
                                'file_path' => $storagePath,
                                'file_name' => $fileName,
                                'created_at' => now()->toIso8601String(),
                            ],
                            now()->addHours(24) // Keep for 24 hours
                        );

                        Log::info('Reporte de encuestas sociodemográficas generado exitosamente', [
                            'job_id' => $jobId,
                            'file_path' => $storagePath,
                            'file_name' => $fileName,
                            'user_id' => $user?->id,
                        ]);

                        // Registrar en auditoría
                        $auditLogService->logBusinessProcess('socio_demographic_survey', 'excel_export', [
                            'job_id' => $jobId,
                            'filters' => $filters,
                            'file_name' => $fileName,
                            'include_signatures' => true,
                            'user_id' => $user?->id,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('Error generando reporte de encuestas sociodemográficas (background)', [
                            'job_id' => $jobId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                            'user_id' => $user?->id,
                        ]);

                        // Store error in cache
                        cache()->put(
                            "survey_report:{$jobId}",
                            [
                                'status' => 'failed',
                                'error' => $e->getMessage(),
                                'created_at' => now()->toIso8601String(),
                            ],
                            now()->addHours(24)
                        );
                    }
                })->afterResponse();

                Log::info('Reporte de encuestas sociodemográficas encolado para generación asíncrona', [
                    'job_id' => $jobId,
                    'include_signatures' => true,
                    'user_id' => $user?->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'El reporte se está generando. Use el job_id para verificar el estado.',
                    'job_id' => $jobId,
                    'status' => 'processing',
                    'check_status_url' => url("/api/socio-demographic-surveys/export/status/{$jobId}"),
                ], 202);
            }

            // Generate report synchronously (without signatures)
            $filePath = $this->excelExportService->generateReport($filters);

            if (!file_exists($filePath)) {
                Log::error('Error generando reporte Excel de encuestas: archivo no creado', [
                    'user_id' => $user?->id,
                    'filters' => $filters,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar el reporte',
                ], 500);
            }

            // Nombre del archivo
            $fileName = 'Reporte_Encuestas_Sociodemograficas_ProSalud_' . now()->setTimezone('America/Bogota')->format('Y-m-d_His') . '.xlsx';

            Log::info('Reporte Excel de encuestas sociodemográficas generado', [
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'filters' => $filters,
                'file_name' => $fileName,
            ]);

            // Registrar en auditoría
            $this->auditLogService->logBusinessProcess('socio_demographic_survey', 'excel_export', $this->auditLogService->addRequestContext($request, [
                'filters' => $filters,
                'file_name' => $fileName,
            ]));

            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Error de validación al generar reporte Excel de encuestas', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error generando reporte Excel de encuestas sociodemográficas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte. Por favor, intente nuevamente.',
            ], 500);
        }
    }

    /**
     * Check the status of an async report generation job.
     */
    public function checkStatus(string $jobId): JsonResponse
    {
        $cacheKey = "survey_report:{$jobId}";
        $status = cache()->get($cacheKey);

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Job no encontrado o expirado',
            ], 404);
        }

        $response = [
            'success' => true,
            'job_id' => $jobId,
            'status' => $status['status'],
        ];

        if ($status['status'] === 'completed') {
            $response['download_url'] = url("/api/socio-demographic-surveys/export/download/{$jobId}");
            $response['file_name'] = $status['file_name'] ?? null;
            $response['created_at'] = $status['created_at'] ?? null;
        } elseif ($status['status'] === 'failed') {
            $response['error'] = $status['error'] ?? 'Error desconocido';
        }

        return response()->json($response);
    }

    /**
     * Download a completed report.
     */
    public function downloadReport(string $jobId): StreamedResponse|JsonResponse
    {
        $cacheKey = "survey_report:{$jobId}";
        $status = cache()->get($cacheKey);

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Job no encontrado o expirado',
            ], 404);
        }

        if ($status['status'] !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'El reporte aún no está listo. Estado: ' . ($status['status'] ?? 'unknown'),
                'status' => $status['status'],
            ], 400);
        }

        $filePath = $status['file_path'] ?? null;
        $fileName = $status['file_name'] ?? 'Reporte_Encuestas_Sociodemograficas_ProSalud.xlsx';

        if (!$filePath) {
            return response()->json([
                'success' => false,
                'message' => 'Ruta del archivo no encontrada',
            ], 404);
        }

        $disk = Storage::disk('local');

        if (!$disk->exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo no existe en el almacenamiento',
            ], 404);
        }

        try {
            $fileContent = $disk->get($filePath);

            Log::info('Reporte de encuestas sociodemográficas descargado', [
                'job_id' => $jobId,
                'file_name' => $fileName,
            ]);

            return response()->streamDownload(function () use ($fileContent) {
                echo $fileContent;
            }, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (\Exception $e) {
            Log::error('Error descargando reporte de encuestas sociodemográficas', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al descargar el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download survey summary as PDF (includes signature image).
     */
    public function downloadPdf(SocioDemographicSurvey $survey): Response|JsonResponse
    {
        try {
            // Load signature image if available
            $signatureImageBase64 = null;
            if ($survey->firma_path) {
                try {
                    // Try private disk first
                    $disk = Storage::disk('prosalud-private');
                    if ($disk->exists($survey->firma_path)) {
                        $signatureContent = $disk->get($survey->firma_path);
                        $signatureImageBase64 = base64_encode($signatureContent);
                    } else {
                        // Fallback to local disk
                        $localDisk = Storage::disk('local');
                        if ($localDisk->exists($survey->firma_path)) {
                            $signatureContent = $localDisk->get($survey->firma_path);
                            $signatureImageBase64 = base64_encode($signatureContent);
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Error loading signature image for PDF', [
                        'survey_id' => $survey->id,
                        'path' => $survey->firma_path,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue without signature image
                }
            }

            // Get logo path and convert to base64
            $logoBase64 = null;
            $logoPath = public_path('assets/logo.png');
            if (file_exists($logoPath)) {
                try {
                    $logoContent = file_get_contents($logoPath);
                    $logoBase64 = base64_encode($logoContent);
                } catch (\Exception $e) {
                    Log::warning('Error loading logo for PDF', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Generate PDF
            $pdf = Pdf::loadView('surveys.socio-demographic-survey-pdf', [
                'survey' => $survey,
                'signatureImageBase64' => $signatureImageBase64,
                'logoBase64' => $logoBase64,
                'generatedAt' => now()->setTimezone('America/Bogota'),
            ]);

            // Set PDF options
            $pdf->setPaper('a4', 'portrait');
            $pdf->setOption('enable-local-file-access', true);
            $pdf->setOption('isHtml5ParserEnabled', true);
            $pdf->setOption('isRemoteEnabled', false);

            // Generate filename
            $documentoNormalizado = preg_replace('/[^0-9]/', '', $survey->numero_documento);
            $fileName = "Encuesta_Sociodemografica_{$survey->id}_{$documentoNormalizado}.pdf";

            Log::info('PDF de encuesta sociodemográfica generado', [
                'survey_id' => $survey->id,
                'file_name' => $fileName,
                'has_signature' => !empty($signatureImageBase64),
            ]);

            // Register audit log
            $this->auditLogService->logBusinessProcess('socio_demographic_survey', 'pdf_download', [
                'survey_id' => $survey->id,
                'file_name' => $fileName,
            ]);

            // Evitar que cualquier salida previa corrompa el PDF
            if (ob_get_length()) {
                ob_end_clean();
            }

            return $pdf->download($fileName);
        } catch (\Exception $e) {
            Log::error('Error generando PDF de encuesta sociodemográfica', [
                'survey_id' => $survey->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el PDF: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download multiple surveys as a single PDF with filters.
     * Always processed asynchronously using a queue job to avoid timeout issues.
     */
    public function downloadBulkPdf(ExportSocioDemographicSurveysPdfRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $filters = $request->validated();
            
            // Generate unique job ID
            $jobId = Str::uuid()->toString();

            // Store initial status in cache
            cache()->put(
                "survey_report_pdf:{$jobId}",
                [
                    'status' => 'processing',
                    'created_at' => now()->toIso8601String(),
                    'filters' => $filters,
                ],
                now()->addHours(24)
            );

            // Dispatch job to queue
            GenerateBulkSurveyPdfJob::dispatch($jobId, $filters, $user?->id);

            Log::info('PDF masivo de encuestas sociodemográficas encolado para generación asíncrona', [
                'job_id' => $jobId,
                'user_id' => $user?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'El PDF se está generando. Use el job_id para verificar el estado.',
                'job_id' => $jobId,
                'status' => 'processing',
                'check_status_url' => url("/api/socio-demographic-surveys/export/pdf/status/{$jobId}"),
            ], 202);
        } catch (\Exception $e) {
            Log::error('Error encolando PDF masivo de encuestas sociodemográficas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar la generación del PDF masivo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check the status of an async PDF generation job.
     */
    public function checkPdfStatus(string $jobId): JsonResponse
    {
        $cacheKey = "survey_report_pdf:{$jobId}";
        $status = cache()->get($cacheKey);

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Job no encontrado o expirado',
            ], 404);
        }

        $response = [
            'success' => true,
            'job_id' => $jobId,
            'status' => $status['status'],
        ];

        if ($status['status'] === 'completed') {
            $response['download_url'] = url("/api/socio-demographic-surveys/export/pdf/download/{$jobId}");
            $response['file_name'] = $status['file_name'] ?? null;
            $response['created_at'] = $status['created_at'] ?? null;
            $response['count'] = $status['count'] ?? null;
        } elseif ($status['status'] === 'failed') {
            $response['error'] = $status['error'] ?? 'Error desconocido';
        }

        return response()->json($response);
    }

    /**
     * Download a completed PDF report.
     */
    public function downloadPdfReport(string $jobId): StreamedResponse|JsonResponse
    {
        $cacheKey = "survey_report_pdf:{$jobId}";
        $status = cache()->get($cacheKey);

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Job no encontrado o expirado',
            ], 404);
        }

        if ($status['status'] !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'El PDF aún no está listo. Estado: ' . ($status['status'] ?? 'unknown'),
                'status' => $status['status'],
            ], 400);
        }

        $filePath = $status['file_path'] ?? null;
        $fileName = $status['file_name'] ?? 'Encuestas_Sociodemograficas.pdf';

        if (!$filePath) {
            return response()->json([
                'success' => false,
                'message' => 'Ruta del archivo no encontrada',
            ], 404);
        }

        $disk = Storage::disk(config('filesystems.survey_reports_disk', 'local'));

        if (!$disk->exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo no existe en el almacenamiento',
            ], 404);
        }

        try {
            Log::info('PDF masivo de encuestas sociodemográficas descargado', [
                'job_id' => $jobId,
                'file_name' => $fileName,
            ]);

            return $disk->download($filePath, $fileName, [
                'Content-Type' => 'application/pdf',
                'Content-Transfer-Encoding' => 'binary',
            ]);
        } catch (\Exception $e) {
            Log::error('Error descargando PDF masivo de encuestas sociodemográficas', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al descargar el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }

}
