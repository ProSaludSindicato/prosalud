<?php

namespace App\Http\Controllers;

use App\Http\Requests\Survey\StoreSurveyResponseRequest;
use App\Http\Requests\Survey\VerifySurveyRespondentRequest;
use App\Http\Resources\SurveyResource;
use App\Http\Resources\SurveyResponseResource;
use App\Models\Hospital;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\SurveyResponseAnswer;
use App\Services\AfiliadoService;
use App\Services\SurveyResponsesExcelExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SurveyResponseController extends Controller
{
    public function __construct(
        private AfiliadoService $afiliadoService,
        private SurveyResponsesExcelExportService $excelExportService
    ) {}

    /**
     * Return public survey metadata and questions if the survey is active.
     */
    public function info(Survey $survey): JsonResponse
    {
        if (! $survey->isPubliclyAccessible()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta encuesta no está disponible.',
                'code' => $survey->isClosed() ? 'closed' : 'not_available',
            ], 404);
        }

        $survey->load('questions');

        if ($survey->access_type === 'restricted') {
            $survey->load('hospitals');
            $hospitalsForForm = $survey->hospitals
                ->map(fn ($h) => ['id' => $h->id, 'name' => $h->name])
                ->values()
                ->toArray();
        } else {
            $hospitalsForForm = Hospital::query()
                ->where('type', 'hospital')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray();
        }

        return response()->json([
            'success' => true,
            'data' => new SurveyResource($survey),
            'hospitals_for_form' => $hospitalsForForm,
        ]);
    }

    /**
     * Verify affiliate identity (ProSanet) before showing the survey form for authenticated/restricted surveys.
     */
    public function verifyRespondent(VerifySurveyRespondentRequest $request, Survey $survey): JsonResponse
    {
        if (! $survey->isPubliclyAccessible()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta encuesta no está disponible.',
                'code' => $survey->isClosed() ? 'closed' : 'not_available',
            ], 404);
        }

        if ($survey->access_type === 'public') {
            return response()->json([
                'success' => false,
                'message' => 'Esta encuesta no requiere verificación de identidad.',
            ], 422);
        }

        $validated = $request->validated();
        $affiliateResult = $this->verifyAffiliateForRestrictedOrAuthenticatedSurvey(
            $survey,
            $validated['respondent_document_type'],
            $validated['respondent_document_number'],
            $validated['fecha_expedicion'],
        );

        if (isset($affiliateResult['response'])) {
            return $affiliateResult['response'];
        }

        return response()->json([
            'success' => true,
            'message' => 'Identidad verificada. Puede continuar con la encuesta.',
        ]);
    }

    /**
     * Submit a response to a public survey.
     */
    public function store(StoreSurveyResponseRequest $request, Survey $survey): JsonResponse
    {
        if (! $survey->isPubliclyAccessible()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta encuesta no está disponible para recibir respuestas.',
            ], 422);
        }

        $validated = $request->validated();

        $respondentDocumentType = $validated['respondent_document_type'] ?? null;
        $respondentDocumentNumber = $validated['respondent_document_number'] ?? null;
        $respondentName = $validated['respondent_name'] ?? null;
        $hospital = $validated['hospital'] ?? null;
        $fechaExpedicion = $validated['fecha_expedicion'] ?? null;

        if (in_array($survey->access_type, ['authenticated', 'restricted'], true)) {
            if (empty($respondentDocumentType) || empty($respondentDocumentNumber) || empty($fechaExpedicion)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta encuesta requiere autenticación como afiliado.',
                    'errors' => ['auth' => ['Debe proporcionar su tipo de documento, número de documento y fecha de expedición.']],
                ], 422);
            }

            $affiliateResult = $this->verifyAffiliateForRestrictedOrAuthenticatedSurvey(
                $survey,
                $respondentDocumentType,
                $respondentDocumentNumber,
                $fechaExpedicion,
            );

            if (isset($affiliateResult['response'])) {
                return $affiliateResult['response'];
            }

            $afiliado = $affiliateResult['afiliado'];
            $respondentName = $respondentName ?? trim(($afiliado['nombres'] ?? '').' '.($afiliado['apellidos'] ?? ''));
            $hospital = $hospital ?? ($afiliado['hospital'] ?? null);
        }

        // Duplicate prevention
        if (! $survey->allows_multiple_responses && $respondentDocumentNumber) {
            if (! $survey->allowsDocument($respondentDocumentNumber)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe una respuesta registrada con este número de documento.',
                    'errors' => ['documento' => ['Ya respondió esta encuesta anteriormente.']],
                ], 422);
            }
        }

        // Validate required questions have answers
        $survey->loadMissing('questions');
        $answeredQuestionIds = collect($validated['answers'])->pluck('question_id')->toArray();
        $missingRequired = $survey->questions
            ->filter(fn ($q) => $q->is_required && ! in_array($q->id, $answeredQuestionIds))
            ->pluck('label');

        if ($missingRequired->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Hay preguntas obligatorias sin responder.',
                'errors' => ['answers' => $missingRequired->map(fn ($l) => "La pregunta \"{$l}\" es obligatoria.")->values()->toArray()],
            ], 422);
        }

        // Process signature if required
        $signaturePath = null;
        if ($survey->requires_signature) {
            $signature = $validated['signature'] ?? null;
            if (empty($signature)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta encuesta requiere firma digital.',
                    'errors' => ['signature' => ['La firma digital es obligatoria.']],
                ], 422);
            }

            $signaturePath = $this->storeSignature($signature, $survey->id);
            if ($signaturePath === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al procesar la firma digital. Asegúrese de que sea una imagen PNG válida.',
                ], 500);
            }
        }

        DB::beginTransaction();
        try {
            $response = SurveyResponse::create([
                'survey_id' => $survey->id,
                'respondent_document_type' => $respondentDocumentType,
                'respondent_document_number' => $respondentDocumentNumber,
                'respondent_name' => $respondentName,
                'hospital' => $hospital,
                'signature_path' => $signaturePath,
                'metadata' => [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ]);

            $questionMap = $survey->questions->keyBy('id');
            $answersToInsert = [];

            foreach ($validated['answers'] as $answer) {
                $questionId = $answer['question_id'];
                $question = $questionMap->get($questionId);

                if ($question === null) {
                    continue;
                }

                $value = $answer['value'] ?? null;

                // JSON-encode array values for choice types
                if (is_array($value)) {
                    $value = json_encode($value);
                }

                $answersToInsert[] = [
                    'response_id' => $response->id,
                    'question_id' => $questionId,
                    'value' => $value !== null ? (string) $value : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (! empty($answersToInsert)) {
                SurveyResponseAnswer::insert($answersToInsert);
            }

            DB::commit();

            Log::info('[Encuestas dinámicas] Respuesta registrada', [
                'survey_id' => $survey->id,
                'response_id' => $response->id,
                'access_type' => $survey->access_type,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Respuesta registrada correctamente.',
                'data' => [
                    'id' => $response->id,
                    'submitted_at' => $response->submitted_at?->toIso8601String(),
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Encuestas dinámicas] Error registrando respuesta', [
                'survey_id' => $survey->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar la respuesta. Por favor, intente nuevamente.',
            ], 500);
        }
    }

    /**
     * List responses for a survey (admin).
     */
    public function index(Request $request, Survey $survey): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = $survey->responses()->orderByDesc('submitted_at');

        if ($request->filled('hospital')) {
            $query->byHospital($request->input('hospital'));
        }

        if ($request->filled('document')) {
            $query->byDocument($request->input('document'));
        }

        if ($request->filled('start_date') || $request->filled('end_date')) {
            $query->byDateRange($request->input('start_date'), $request->input('end_date'));
        }

        $responses = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => SurveyResponseResource::collection($responses->items()),
            'meta' => [
                'current_page' => $responses->currentPage(),
                'last_page' => $responses->lastPage(),
                'per_page' => $responses->perPage(),
                'total' => $responses->total(),
            ],
        ]);
    }

    /**
     * Show a single response with all answers (admin).
     */
    public function show(Survey $survey, SurveyResponse $response): JsonResponse
    {
        if ($response->survey_id !== $survey->id) {
            return response()->json(['success' => false, 'message' => 'Respuesta no encontrada.'], 404);
        }

        $response->load(['answers.question']);

        return response()->json([
            'success' => true,
            'data' => new SurveyResponseResource($response),
        ]);
    }

    /**
     * Stream the signature image for a response (admin).
     */
    public function downloadSignature(Survey $survey, SurveyResponse $response): StreamedResponse|JsonResponse
    {
        if ($response->survey_id !== $survey->id) {
            return response()->json(['success' => false, 'message' => 'Respuesta no encontrada.'], 404);
        }

        if (empty($response->signature_path)) {
            return response()->json(['success' => false, 'message' => 'Esta respuesta no tiene firma.'], 404);
        }

        $disk = 'prosalud-private';
        $fallback = 'local';

        if (Storage::disk($disk)->exists($response->signature_path)) {
            $content = Storage::disk($disk)->get($response->signature_path);
        } elseif (Storage::disk($fallback)->exists($response->signature_path)) {
            $content = Storage::disk($fallback)->get($response->signature_path);
        } else {
            return response()->json(['success' => false, 'message' => 'Archivo de firma no encontrado.'], 404);
        }

        return response()->streamDownload(
            fn () => print ($content),
            basename($response->signature_path),
            ['Content-Type' => 'image/png']
        );
    }

    /**
     * Initiate async Excel export of responses (admin).
     */
    public function exportExcel(Request $request, Survey $survey): JsonResponse
    {
        $filters = [
            'hospital' => $request->input('hospital'),
            'document' => $request->input('document'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
        ];

        $jobId = Str::uuid()->toString();

        cache()->put(
            "survey_responses_report:{$jobId}",
            ['status' => 'processing', 'created_at' => now()->toIso8601String()],
            now()->addHours(24)
        );

        $surveyId = $survey->id;
        dispatch(function () use ($surveyId, $filters, $jobId) {
            try {
                $excelExportService = app(SurveyResponsesExcelExportService::class);
                $survey = Survey::findOrFail($surveyId);
                $filePath = $excelExportService->generateReport($survey, $filters);

                if (! file_exists($filePath)) {
                    throw new \Exception('El archivo del reporte no fue creado');
                }

                $fileName = 'Respuestas_Encuesta_'.Str::slug($survey->title).'_'.now()->setTimezone('America/Bogota')->format('Y-m-d_His').'.xlsx';
                $storagePath = 'reports/dynamic-surveys/'.$jobId.'/'.$fileName;
                Storage::disk('local')->put($storagePath, file_get_contents($filePath));

                @unlink($filePath);

                cache()->put(
                    "survey_responses_report:{$jobId}",
                    ['status' => 'completed', 'file_path' => $storagePath, 'file_name' => $fileName, 'created_at' => now()->toIso8601String()],
                    now()->addHours(24)
                );
            } catch (\Throwable $e) {
                Log::error('[Encuestas dinámicas] Error generando reporte Excel (background)', [
                    'job_id' => $jobId,
                    'survey_id' => $surveyId,
                    'error' => $e->getMessage(),
                ]);

                cache()->put(
                    "survey_responses_report:{$jobId}",
                    ['status' => 'failed', 'error' => $e->getMessage(), 'created_at' => now()->toIso8601String()],
                    now()->addHours(24)
                );
            }
        })->afterResponse();

        return response()->json([
            'success' => true,
            'message' => 'El reporte se está generando. Use el job_id para verificar el estado.',
            'job_id' => $jobId,
            'status' => 'processing',
        ], 202);
    }

    /**
     * Check status of an async Excel export job.
     */
    public function checkExportStatus(string $surveyId, string $jobId): JsonResponse
    {
        $cacheKey = "survey_responses_report:{$jobId}";
        $status = cache()->get($cacheKey);

        if (! $status) {
            return response()->json(['success' => false, 'message' => 'Job no encontrado o expirado.'], 404);
        }

        $response = ['success' => true, 'job_id' => $jobId, 'status' => $status['status']];

        if ($status['status'] === 'completed') {
            $response['download_url'] = url("/api/surveys/{$surveyId}/export/download/{$jobId}");
            $response['file_name'] = $status['file_name'] ?? null;
            $response['created_at'] = $status['created_at'] ?? null;
        } elseif ($status['status'] === 'failed') {
            $response['error'] = $status['error'] ?? 'Error desconocido';
        }

        return response()->json($response);
    }

    /**
     * Download a completed Excel export.
     */
    public function downloadExport(string $surveyId, string $jobId): StreamedResponse|JsonResponse
    {
        $cacheKey = "survey_responses_report:{$jobId}";
        $status = cache()->get($cacheKey);

        if (! $status) {
            return response()->json(['success' => false, 'message' => 'Job no encontrado o expirado.'], 404);
        }

        if ($status['status'] !== 'completed') {
            return response()->json(['success' => false, 'message' => 'El reporte aún no está listo.', 'status' => $status['status']], 400);
        }

        $filePath = $status['file_path'] ?? null;
        $fileName = $status['file_name'] ?? 'Reporte_Encuesta.xlsx';

        if (! $filePath || ! Storage::disk('local')->exists($filePath)) {
            return response()->json(['success' => false, 'message' => 'El archivo del reporte no está disponible.'], 404);
        }

        return Storage::disk('local')->download($filePath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return array{response: JsonResponse}|array{afiliado: array<string, mixed>}
     */
    private function verifyAffiliateForRestrictedOrAuthenticatedSurvey(
        Survey $survey,
        string $respondentDocumentType,
        string $respondentDocumentNumber,
        string $fechaExpedicion,
    ): array {
        if (! $this->afiliadoService->isFileAvailable()) {
            return [
                'response' => response()->json([
                    'success' => false,
                    'message' => 'El servicio de verificación de afiliados no está disponible temporalmente. Intente más tarde.',
                ], 503),
            ];
        }

        $authResult = $this->afiliadoService->authenticateAndGetAfiliadoDetailed(
            $respondentDocumentType,
            $respondentDocumentNumber,
            $fechaExpedicion
        );

        if ($authResult['status'] !== 'success') {
            $message = $authResult['status'] === 'affiliate_data_mismatch'
                ? 'Los datos ingresados no coinciden con los registros. Verifique su fecha de expedición.'
                : 'No se encontró ningún afiliado activo con los datos proporcionados.';

            return [
                'response' => response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['auth' => [$message]],
                ], 422),
            ];
        }

        /** @var array<string, mixed> $afiliado */
        $afiliado = $authResult['afiliado'];
        $hospital = $afiliado['hospital'] ?? null;

        // Check if the affiliate's status (activo/retirado) is allowed for this survey.
        $allowedStatuses = array_map('strtolower', $survey->allowed_affiliate_statuses ?? ['activo']);
        $affiliateStatus = strtolower(trim((string) ($afiliado['estado'] ?? '')));

        if (! in_array($affiliateStatus, $allowedStatuses, true)) {
            $statusLabel = match (true) {
                $allowedStatuses === ['activo'] => 'afiliados activos',
                $allowedStatuses === ['retirado'] => 'afiliados retirados',
                default => implode(' o ', $allowedStatuses),
            };

            return [
                'response' => response()->json([
                    'success' => false,
                    'message' => "Esta encuesta está habilitada únicamente para {$statusLabel}.",
                ], 403),
            ];
        }

        if ($survey->access_type === 'restricted') {
            $survey->loadMissing('hospitals');
            $allowedHospitalNames = $survey->hospitals->pluck('name')->map(fn ($n) => $this->normalizeHospitalForComparison($n));
            $afiliadoHospital = $this->normalizeHospitalForComparison((string) $hospital);

            if ($allowedHospitalNames->isEmpty() || ! $allowedHospitalNames->contains($afiliadoHospital)) {
                return [
                    'response' => response()->json([
                        'success' => false,
                        'message' => 'Esta encuesta no está disponible para su hospital.',
                    ], 403),
                ];
            }
        }

        return ['afiliado' => $afiliado];
    }

    /**
     * Normalize a hospital name for comparison, stripping the "Hospital " prefix and accents.
     * This bridges the gap between transformCliente() short names (e.g. "Rionegro")
     * and Hospital model names (e.g. "Hospital Rionegro").
     */
    private function normalizeHospitalForComparison(string $name): string
    {
        $name = preg_replace('/^Hospital\s+/i', '', $name) ?? $name;
        $name = mb_strtoupper(trim($name), 'UTF-8');
        $from = ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ', 'Ü'];
        $to = ['A', 'E', 'I', 'O', 'U', 'N', 'U'];

        return str_replace($from, $to, $name);
    }

    private function storeSignature(string $signatureBase64, string $surveyId): ?string
    {
        $disk = 'prosalud-private';
        $fallbackDisk = 'local';

        if (! preg_match('/^data:image\/png;base64,/', $signatureBase64)) {
            return null;
        }

        $base64Data = substr($signatureBase64, strpos($signatureBase64, ',') + 1);
        $fileContent = base64_decode($base64Data, true);

        if ($fileContent === false) {
            return null;
        }

        $filename = sprintf('firma-%s-%s.png', $surveyId, Str::uuid());
        $storagePath = 'dynamic-surveys/signatures/'.date('Y/m').'/'.$filename;

        $stored = Storage::disk($disk)->put($storagePath, $fileContent);

        if ($stored === false) {
            $stored = Storage::disk($fallbackDisk)->put($storagePath, $fileContent);
        }

        return $stored ? $storagePath : null;
    }
}
