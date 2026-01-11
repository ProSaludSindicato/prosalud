<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSocioDemographicSurveyRequest;
use App\Models\SocioDemographicSurvey;
use App\Services\AuditLogService;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\{DB, Log, Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SocioDemographicSurveyController extends Controller
{
    public function __construct(
        private AuditLogService $auditLogService,
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

            // Crear el registro de la encuesta
            $survey = new SocioDemographicSurvey([
                'correo' => $validated['correo'],
                'tipo_documento' => $validated['tipoDocumento'],
                'numero_documento' => $validated['numeroDocumento'],
                'nombres' => $validated['nombres'] ?? null,
                'apellidos' => $validated['apellidos'] ?? null,
                'hospital' => $validated['hospital'],
                'profesion' => $validated['profesion'],
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

        // Paginación
        $perPage = min($request->input('per_page', 15), 100);
        $surveys = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $surveys->items(),
            'pagination' => [
                'current_page' => $surveys->currentPage(),
                'last_page' => $surveys->lastPage(),
                'per_page' => $surveys->perPage(),
                'total' => $surveys->total(),
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

}
