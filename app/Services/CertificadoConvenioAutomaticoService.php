<?php

namespace App\Services;

use App\Constants\{RequestStatuses, RequestTypes};
use App\Domain\RequestForm\RequestFormDTO;
use App\Mail\{RequestFormReceived, RequestFormResponse};
use App\Models\{RequestForm, RequestResponse};
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Log, Mail, Storage};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class CertificadoConvenioAutomaticoService
{
    private const PRIVATE_DISK = 'prosalud-private';
    private const FALLBACK_DISK = 'local';

    public function __construct(
        private readonly CertificadoConvenioService $certificadoService,
    ) {
    }

    /**
     * Procesa una solicitud automática de certificado de convenio con un RequestForm existente y compensaciones
     * Genera el certificado, envía correos y cierra la solicitud
     *
     * @param RequestForm $requestForm RequestForm ya creado
     * @param array|null $compensaciones Datos de compensaciones opcionales: ['t_basicos' => int, 't_auxilios' => int, 't_ingresos' => int]
     * @param string|null $emailSubject Asunto del correo personalizado (opcional)
     * @param string|null $emailBody Cuerpo del correo personalizado (opcional)
     * @param string|null $status Estado final de la solicitud (opcional, por defecto COMPLETED)
     * @return array Resultado del proceso
     */
    public function procesarConRequestFormExistenteYCompensaciones(RequestForm $requestForm, ?array $compensaciones = null, ?string $emailSubject = null, ?string $emailBody = null, ?string $status = null): array
    {
        DB::beginTransaction();

        try {
            Log::info('Iniciando procesamiento automático de certificado con compensaciones', [
                'request_id' => $requestForm->id,
                'documento' => $requestForm->document_number,
                'tiene_compensaciones' => !empty($compensaciones),
                'compensaciones' => $compensaciones,
            ]);

            // Extraer el valor de dirigidoAQuien del payload si existe
            $dirigidoAEntidad = $this->extraerDirigidoAEntidad($requestForm);
            
            // Verificar si es para Bancolombia
            $esParaBancolombia = $this->esParaBancolombia($requestForm);
            
            // Verificar si es para subsidio de vivienda
            $esParaSubsidioVivienda = $this->esParaSubsidioVivienda($requestForm);
            
            // Verificar si es para subsidio de desempleo
            $esParaSubsidioDesempleo = $this->esParaSubsidioDesempleo($requestForm);
            
            // Verificar si es tipo "otros"
            $esOtros = $this->esOtros($requestForm);

            Log::info('Valor de dirigidoAEntidad extraído del payload', [
                'request_id' => $requestForm->id,
                'dirigidoAEntidad' => $dirigidoAEntidad,
                'esParaBancolombia' => $esParaBancolombia,
                'esParaSubsidioVivienda' => $esParaSubsidioVivienda,
                'esParaSubsidioDesempleo' => $esParaSubsidioDesempleo,
                'esOtros' => $esOtros,
                'payload' => $requestForm->payload,
            ]);

            // 1. Generar certificado PDF con el destinatario y compensaciones si están disponibles
            $resultadoCertificado = $this->certificadoService->generarCertificadoPDF(
                $requestForm->document_number,
                $dirigidoAEntidad,
                $compensaciones,
                $esParaBancolombia,
                $esParaSubsidioVivienda,
                $esParaSubsidioDesempleo,
                $esOtros
            );

            Log::info('Certificado PDF generado para solicitud automática con compensaciones', [
                'request_id' => $requestForm->id,
                'consecutivo' => $resultadoCertificado['consecutivo'] ?? null,
                'bucket_path' => $resultadoCertificado['bucket_path'] ?? null,
            ]);

            // 2. Guardar el PDF en los archivos de la solicitud para trazabilidad
            $archivoMetadata = $this->guardarCertificadoEnSolicitud(
                $requestForm->id,
                $resultadoCertificado['ruta'],
                $resultadoCertificado['nombre']
            );

            // Actualizar el RequestForm con el archivo
            $files = $requestForm->files ?? [];
            $files['certificado_convenio'] = $archivoMetadata;
            $requestForm->files = $files;
            $requestForm->save();

            // 3. Crear respuesta automática y enviar correo con certificado
            $this->crearYEnviarRespuestaAutomatica(
                $requestForm,
                $resultadoCertificado['ruta'],
                $resultadoCertificado['nombre'],
                $resultadoCertificado['consecutivo'] ?? ''
            );

            DB::commit();

            Log::info('Solicitud automática de certificado con compensaciones procesada exitosamente', [
                'request_id' => $requestForm->id,
                'consecutivo' => $resultadoCertificado['consecutivo'] ?? null,
            ]);

            return [
                'success' => true,
                'request_id' => $requestForm->id,
                'consecutivo' => $resultadoCertificado['consecutivo'] ?? null,
                'status' => $requestForm->status,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error procesando solicitud automática de certificado con compensaciones', [
                'request_id' => $requestForm->id,
                'documento' => $requestForm->document_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Procesa una solicitud automática de certificado de convenio con un RequestForm existente
     * Genera el certificado, envía correos y cierra la solicitud
     *
     * @param RequestForm $requestForm RequestForm ya creado
     * @return array Resultado del proceso
     */
    public function procesarConRequestFormExistente(RequestForm $requestForm): array
    {
        DB::beginTransaction();

        try {
            Log::info('Iniciando procesamiento automático de certificado con RequestForm existente', [
                'request_id' => $requestForm->id,
                'documento' => $requestForm->document_number,
            ]);

            // Extraer el valor de dirigidoAQuien del payload si existe
            $dirigidoAEntidad = $this->extraerDirigidoAEntidad($requestForm);
            
            // Verificar si es para Bancolombia
            $esParaBancolombia = $this->esParaBancolombia($requestForm);
            
            // Verificar si es para subsidio de vivienda
            $esParaSubsidioVivienda = $this->esParaSubsidioVivienda($requestForm);
            
            // Verificar si es para subsidio de desempleo
            $esParaSubsidioDesempleo = $this->esParaSubsidioDesempleo($requestForm);
            
            // Verificar si es tipo "otros"
            $esOtros = $this->esOtros($requestForm);

            Log::info('Valor de dirigidoAEntidad extraído del payload', [
                'request_id' => $requestForm->id,
                'dirigidoAEntidad' => $dirigidoAEntidad,
                'esParaBancolombia' => $esParaBancolombia,
                'esParaSubsidioVivienda' => $esParaSubsidioVivienda,
                'esParaSubsidioDesempleo' => $esParaSubsidioDesempleo,
                'esOtros' => $esOtros,
                'payload' => $requestForm->payload,
            ]);

            // 1. Generar certificado PDF con el destinatario si está disponible
            $resultadoCertificado = $this->certificadoService->generarCertificadoPDF(
                $requestForm->document_number,
                $dirigidoAEntidad,
                null,
                $esParaBancolombia,
                $esParaSubsidioVivienda,
                $esParaSubsidioDesempleo,
                $esOtros
            );

            Log::info('Certificado PDF generado para solicitud automática', [
                'request_id' => $requestForm->id,
                'consecutivo' => $resultadoCertificado['consecutivo'] ?? null,
                'bucket_path' => $resultadoCertificado['bucket_path'] ?? null,
            ]);

            // 2. Guardar el PDF en los archivos de la solicitud para trazabilidad
            $archivoMetadata = $this->guardarCertificadoEnSolicitud(
                $requestForm->id,
                $resultadoCertificado['ruta'],
                $resultadoCertificado['nombre']
            );

            // Actualizar el RequestForm con el archivo
            $files = $requestForm->files ?? [];
            $files['certificado_convenio'] = $archivoMetadata;
            $requestForm->files = $files;
            $requestForm->save();

            // 3. Crear respuesta automática y enviar correo con certificado
            $this->crearYEnviarRespuestaAutomatica(
                $requestForm,
                $resultadoCertificado['ruta'],
                $resultadoCertificado['nombre'],
                $resultadoCertificado['consecutivo'] ?? ''
            );

            DB::commit();

            Log::info('Solicitud automática de certificado procesada exitosamente', [
                'request_id' => $requestForm->id,
                'consecutivo' => $resultadoCertificado['consecutivo'] ?? null,
            ]);

            return [
                'success' => true,
                'request_id' => $requestForm->id,
                'consecutivo' => $resultadoCertificado['consecutivo'] ?? null,
                'status' => $requestForm->status,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error procesando solicitud automática de certificado', [
                'request_id' => $requestForm->id,
                'documento' => $requestForm->document_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Procesa una solicitud automática de certificado de convenio
     * Crea el RequestForm, genera el certificado, envía correos y cierra la solicitud
     *
     * @param array $solicitudData Datos de la solicitud del afiliado
     * @return array Resultado del proceso
     */
    public function procesarSolicitudAutomatica(array $solicitudData): array
    {
        DB::beginTransaction();

        try {
            // 1. Crear RequestForm para trazabilidad
            $requestForm = $this->crearRequestForm($solicitudData);

            Log::info('RequestForm creado para certificado automático', [
                'request_id' => $requestForm->id,
                'documento' => $solicitudData['documento'],
            ]);

            // 2. Enviar correo de confirmación de recepción
            $this->enviarCorreoConfirmacion($requestForm);

            // Extraer el valor de dirigidoAEntidad de los datos de la solicitud
            $dirigidoAEntidad = $solicitudData['dirigido_a_entidad'] ?? null;
            
            // Verificar si es para Bancolombia
            $esParaBancolombia = $this->esParaBancolombia($requestForm);
            
            // Verificar si es para subsidio de vivienda
            $esParaSubsidioVivienda = $this->esParaSubsidioVivienda($requestForm);
            
            // Verificar si es para subsidio de desempleo
            $esParaSubsidioDesempleo = $this->esParaSubsidioDesempleo($requestForm);
            
            // Verificar si es tipo "otros"
            $esOtros = $this->esOtros($requestForm);

            // 3. Generar certificado PDF con el destinatario si está disponible
            $resultadoCertificado = $this->certificadoService->generarCertificadoPDF(
                $solicitudData['documento'],
                $dirigidoAEntidad,
                null,
                $esParaBancolombia,
                $esParaSubsidioVivienda,
                $esParaSubsidioDesempleo,
                $esOtros
            );

            Log::info('Certificado PDF generado para solicitud automática', [
                'request_id' => $requestForm->id,
                'consecutivo' => $resultadoCertificado['consecutivo'] ?? null,
                'bucket_path' => $resultadoCertificado['bucket_path'] ?? null,
            ]);

            // 4. Guardar el PDF en los archivos de la solicitud para trazabilidad
            $archivoMetadata = $this->guardarCertificadoEnSolicitud(
                $requestForm->id,
                $resultadoCertificado['ruta'],
                $resultadoCertificado['nombre']
            );

            // Actualizar el RequestForm con el archivo
            $files = $requestForm->files ?? [];
            $files['certificado_convenio'] = $archivoMetadata;
            $requestForm->files = $files;
            $requestForm->save();

            // 5. Crear respuesta automática y enviar correo con certificado
            $this->crearYEnviarRespuestaAutomatica(
                $requestForm,
                $resultadoCertificado['ruta'],
                $resultadoCertificado['nombre'],
                $resultadoCertificado['consecutivo'] ?? ''
            );

            DB::commit();

            Log::info('Solicitud automática de certificado procesada exitosamente', [
                'request_id' => $requestForm->id,
                'consecutivo' => $resultadoCertificado['consecutivo'] ?? null,
            ]);

            return [
                'success' => true,
                'request_id' => $requestForm->id,
                'consecutivo' => $resultadoCertificado['consecutivo'] ?? null,
                'status' => $requestForm->status,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error procesando solicitud automática de certificado', [
                'documento' => $solicitudData['documento'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Crea un RequestForm para la solicitud de certificado
     */
    private function crearRequestForm(array $solicitudData): RequestForm
    {
        // Obtener datos del afiliado para completar la información
        $afiliadoData = $this->certificadoService->obtenerDatosAfiliado($solicitudData['documento']);
        
        $afiliado = $afiliadoData['afiliado'] ?? [];
        
        $payload = [
            'solicitud_automatica' => true,
            'tipo_certificado' => $solicitudData['tipo_certificado'] ?? 'fecha_ingreso_retiro',
            'dirigido_a_entidad' => $solicitudData['dirigido_a_entidad'] ?? '',
            'fecha_solicitud' => Carbon::now(config('app.timezone', 'America/Bogota'))->toIso8601String(),
        ];

        // Usar datos del Excel si están disponibles, sino usar los proporcionados en la solicitud
        $nombres = $afiliado['nombres'] ?? $solicitudData['nombres'] ?? '';
        $apellidos = $afiliado['apellidos'] ?? $solicitudData['apellidos'] ?? '';
        $email = $afiliado['correo_personal'] ?? $solicitudData['email'] ?? '';
        $telefono = ''; // No está disponible en los datos del certificado, usar el proporcionado
        if (empty($telefono)) {
            $telefono = $solicitudData['telefono'] ?? '';
        }

        $dto = new RequestFormDTO(
            requestType: RequestTypes::CERTIFICADO_CONVENIO,
            documentType: $afiliado['tipo_documento'] ?? $solicitudData['tipo_documento'] ?? 'CC',
            documentNumber: $solicitudData['documento'],
            name: $nombres,
            lastName: $apellidos,
            email: $email,
            phoneNumber: $telefono,
            payload: $payload,
            files: []
        );

        $requestData = $dto->toArray();
        $requestData['status'] = RequestStatuses::PENDING;

        $requestForm = new RequestForm($requestData);
        $requestForm->created_at = now();
        $requestForm->save();
        $requestForm->refresh();

        return $requestForm;
    }

    /**
     * Envía correo de confirmación de recepción de la solicitud
     */
    private function enviarCorreoConfirmacion(RequestForm $requestForm): void
    {
        try {
            Mail::to($requestForm->email)
                ->send(new RequestFormReceived($requestForm, []));

            Log::info('Correo de confirmación enviado para solicitud automática', [
                'request_id' => $requestForm->id,
                'email' => $requestForm->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error enviando correo de confirmación para solicitud automática', [
                'request_id' => $requestForm->id,
                'error' => $e->getMessage(),
            ]);
            // No lanzar excepción, continuar con el proceso
        }
    }

    /**
     * Guarda el certificado PDF en los archivos de la solicitud para trazabilidad
     */
    private function guardarCertificadoEnSolicitud(string $requestId, string $rutaPdf, string $nombreArchivo): array
    {
        try {
            // Leer el contenido del PDF
            $contenidoPDF = file_get_contents($rutaPdf);
            if ($contenidoPDF === false) {
                throw new \Exception("No se pudo leer el archivo PDF desde: {$rutaPdf}");
            }

            // Crear un nombre único para el archivo
            $nombreSinExtension = pathinfo($nombreArchivo, PATHINFO_FILENAME);
            $extension = pathinfo($nombreArchivo, PATHINFO_EXTENSION) ?: 'pdf';
            $nombreUnico = "certificado-convenio-{$requestId}-" . Str::random(8) . ".{$extension}";

            // Guardar en el bucket privado
            $disk = self::PRIVATE_DISK;
            $directorio = 'request-forms/' . date('Y/m');
            $rutaStorage = "{$directorio}/{$nombreUnico}";

            $guardado = Storage::disk($disk)->put($rutaStorage, $contenidoPDF);

            if (!$guardado) {
                // Intentar con disco de fallback
                $disk = self::FALLBACK_DISK;
                $guardado = Storage::disk($disk)->put($rutaStorage, $contenidoPDF);
                
                if (!$guardado) {
                    throw new \Exception("No se pudo guardar el certificado en storage");
                }
            }

            return [
                'path' => $rutaStorage,
                'disk' => $disk,
                'original_name' => $nombreArchivo,
                'mime_type' => 'application/pdf',
                'size' => strlen($contenidoPDF),
                'original_key' => 'certificado_convenio',
            ];
        } catch (\Exception $e) {
            Log::error('Error guardando certificado en archivos de solicitud', [
                'request_id' => $requestId,
                'ruta_pdf' => $rutaPdf,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Crea y envía la respuesta automática con el certificado adjunto
     * 
     * @param RequestForm $requestForm
     * @param string $rutaPdf
     * @param string $nombreArchivo
     * @param string $consecutivo
     * @param string|null $emailSubject Asunto del correo personalizado (opcional)
     * @param string|null $emailBody Cuerpo del correo personalizado (opcional)
     * @param string|null $status Estado final de la solicitud (opcional, por defecto COMPLETED)
     */
    private function crearYEnviarRespuestaAutomatica(
        RequestForm $requestForm,
        string $rutaPdf,
        string $nombreArchivo,
        string $consecutivo,
        ?string $emailSubject = null,
        ?string $emailBody = null,
        ?string $status = null
    ): void {
        // Preparar contenido del correo (usar valores personalizados si se proporcionan, sino generar automáticamente)
        $finalEmailSubject = $emailSubject ?? "Certificado de Convenio - Consecutivo {$consecutivo}";
        $finalEmailBody = $emailBody ?? $this->generarCuerpoCorreo($requestForm, $consecutivo);
        $finalStatus = $status ?? RequestStatuses::COMPLETED;

        // Crear un archivo temporal para adjuntar al correo con nombre personalizado
        $nombreArchivoAdjunto = $this->generarNombreArchivoAdjunto($requestForm->document_number, $consecutivo);
        $archivoTemporal = $this->crearArchivoTemporalParaCorreo($rutaPdf, $nombreArchivoAdjunto);

        try {
            // Enviar correo con el certificado adjunto
            Mail::to($requestForm->email)
                ->cc('juanpapabon@gmail.com') // Hardcoded as per requirements
                ->send(new RequestFormResponse(
                    $requestForm,
                    $finalEmailSubject,
                    $finalEmailBody,
                    $finalStatus,
                    [$archivoTemporal]
                ));

            Log::info('Correo de respuesta automática enviado con certificado', [
                'request_id' => $requestForm->id,
                'consecutivo' => $consecutivo,
                'status' => $finalStatus,
                'email_subject_custom' => !empty($emailSubject),
                'email_body_custom' => !empty($emailBody),
            ]);

            // Actualizar estado de la solicitud
            $requestForm->status = $finalStatus;
            if (RequestStatuses::COMPLETED === $finalStatus || RequestStatuses::REJECTED === $finalStatus) {
                $requestForm->processed_at = now();
            } else {
                $requestForm->processed_at = null;
            }
            $requestForm->save();

            // Crear registro de respuesta para trazabilidad
            RequestResponse::create([
                'request_form_id' => $requestForm->id,
                'status' => $finalStatus,
                'email_subject' => $finalEmailSubject,
                'email_body' => $finalEmailBody,
                'created_at' => now(),
            ]);

            Log::info('Respuesta automática creada y solicitud cerrada', [
                'request_id' => $requestForm->id,
                'status' => RequestStatuses::COMPLETED,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error enviando correo de respuesta automática', [
                'request_id' => $requestForm->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            // Limpiar archivo temporal
            if (isset($archivoTemporal) && file_exists($archivoTemporal->getRealPath())) {
                @unlink($archivoTemporal->getRealPath());
            }
        }
    }

    /**
     * Genera el cuerpo del correo de respuesta automática
     * Texto simple sin HTML ya que el template del correo escapa el HTML
     */
    private function generarCuerpoCorreo(RequestForm $requestForm, string $consecutivo): string
    {
        $fecha = Carbon::now(config('app.timezone', 'America/Bogota'))->locale('es')->isoFormat('D [de] MMMM [de] YYYY');

        return "Adjunto encontrará su certificado en formato PDF con el siguiente consecutivo: {$consecutivo}.\n\nEste certificado ha sido generado automáticamente y contiene la información solicitada sobre su convenio.\n\nFecha de generación: {$fecha}\nConsecutivo: {$consecutivo}";
    }

    /**
     * Genera el nombre del archivo adjunto para el certificado
     * Formato: Certificado_Sindicato_ProSalud_{documento}_{consecutivo}.pdf
     */
    private function generarNombreArchivoAdjunto(string $documento, string $consecutivo): string
    {
        $documentoNormalizado = preg_replace('/[^0-9]/', '', $documento);
        return "Certificado_Sindicato_ProSalud_{$documentoNormalizado}_{$consecutivo}.pdf";
    }

    /**
     * Crea un archivo temporal compatible con UploadedFile para adjuntar al correo
     */
    private function crearArchivoTemporalParaCorreo(string $rutaPdf, string $nombreArchivo): UploadedFile
    {
        // Leer el contenido del PDF
        $contenido = file_get_contents($rutaPdf);
        if ($contenido === false) {
            throw new \Exception("No se pudo leer el archivo PDF: {$rutaPdf}");
        }

        // Crear un archivo temporal
        $tempPath = tempnam(sys_get_temp_dir(), 'certificado_') . '.pdf';
        file_put_contents($tempPath, $contenido);

        // Crear un UploadedFile simulado desde el archivo temporal
        return new UploadedFile(
            $tempPath,
            $nombreArchivo,
            'application/pdf',
            null,
            true // test = true para evitar validaciones estrictas
        );
    }

    /**
     * Extrae el valor de dirigidoAQuien del payload del RequestForm
     * 
     * @param RequestForm $requestForm
     * @return string|null Nombre de la entidad destinataria o null si no existe
     */
    private function extraerDirigidoAEntidad(RequestForm $requestForm): ?string
    {
        $payload = $requestForm->payload ?? [];
        
        // Primero buscar dirigidoAQuien en el nivel superior del payload
        // (es donde el frontend lo envía cuando dirigidoAEntidad está activo)
        if (isset($payload['dirigidoAQuien']) && !empty(trim($payload['dirigidoAQuien']))) {
            return trim($payload['dirigidoAQuien']);
        }

        // Si no está en el nivel superior, verificar dentro de infoCertificado
        if (!isset($payload['infoCertificado'])) {
            return null;
        }

        // Parsear el JSON string si existe
        $infoCertificado = $payload['infoCertificado'];
        if (is_string($infoCertificado)) {
            $infoCertificado = json_decode($infoCertificado, true);
        }

        if (!is_array($infoCertificado)) {
            return null;
        }

        // Verificar si dirigidoAEntidad está activo
        $dirigidoAEntidad = $infoCertificado['dirigidoAEntidad'] ?? false;
        
        if ($dirigidoAEntidad) {
            // Buscar dirigidoAQuien dentro de infoCertificado (fallback)
            $dirigidoAQuien = $infoCertificado['dirigidoAQuien'] ?? null;
            
            if (!empty($dirigidoAQuien)) {
                return trim($dirigidoAQuien);
            }
        }

        return null;
    }

    /**
     * Verifica si el certificado es para apertura de cuenta en Bancolombia
     * 
     * @param RequestForm $requestForm
     * @return bool
     */
    private function esParaBancolombia(RequestForm $requestForm): bool
    {
        $payload = $requestForm->payload ?? [];
        
        // Verificar si tiene infoCertificado en el payload
        if (!isset($payload['infoCertificado'])) {
            Log::debug('esParaBancolombia: No tiene infoCertificado en payload', [
                'request_id' => $requestForm->id,
            ]);
            return false;
        }

        // Parsear el JSON string si existe
        $infoCertificado = $payload['infoCertificado'];
        if (is_string($infoCertificado)) {
            $infoCertificado = json_decode($infoCertificado, true);
        }

        if (!is_array($infoCertificado)) {
            Log::debug('esParaBancolombia: infoCertificado no es un array', [
                'request_id' => $requestForm->id,
                'infoCertificado_type' => gettype($payload['infoCertificado']),
            ]);
            return false;
        }

        // Verificar si dirigidoBancolombia está activo usando parseBooleanValue
        $dirigidoBancolombiaRaw = $infoCertificado['dirigidoBancolombia'] ?? false;
        $dirigidoBancolombia = $requestForm->parseBooleanValue($dirigidoBancolombiaRaw);
        
        Log::info('esParaBancolombia: Verificando opción dirigidoBancolombia', [
            'request_id' => $requestForm->id,
            'dirigidoBancolombia_raw' => $dirigidoBancolombiaRaw,
            'dirigidoBancolombia_parsed' => $dirigidoBancolombia,
            'infoCertificado' => $infoCertificado,
        ]);
        
        return $dirigidoBancolombia;
    }

    /**
     * Verifica si el certificado es para subsidio de vivienda
     * 
     * @param RequestForm $requestForm
     * @return bool
     */
    private function esParaSubsidioVivienda(RequestForm $requestForm): bool
    {
        $payload = $requestForm->payload ?? [];
        
        // Verificar si tiene infoCertificado en el payload
        if (!isset($payload['infoCertificado'])) {
            Log::debug('esParaSubsidioVivienda: No tiene infoCertificado en payload', [
                'request_id' => $requestForm->id,
            ]);
            return false;
        }

        // Parsear el JSON string si existe
        $infoCertificado = $payload['infoCertificado'];
        if (is_string($infoCertificado)) {
            $infoCertificado = json_decode($infoCertificado, true);
        }

        if (!is_array($infoCertificado)) {
            Log::debug('esParaSubsidioVivienda: infoCertificado no es un array', [
                'request_id' => $requestForm->id,
                'infoCertificado_type' => gettype($payload['infoCertificado']),
            ]);
            return false;
        }

        // Verificar si paraSubsidioVivienda está activo usando parseBooleanValue
        $paraSubsidioViviendaRaw = $infoCertificado['paraSubsidioVivienda'] ?? false;
        $paraSubsidioVivienda = $requestForm->parseBooleanValue($paraSubsidioViviendaRaw);
        
        Log::info('esParaSubsidioVivienda: Verificando opción paraSubsidioVivienda', [
            'request_id' => $requestForm->id,
            'paraSubsidioVivienda_raw' => $paraSubsidioViviendaRaw,
            'paraSubsidioVivienda_parsed' => $paraSubsidioVivienda,
            'infoCertificado' => $infoCertificado,
        ]);
        
        return $paraSubsidioVivienda;
    }

    /**
     * Verifica si el certificado es para subsidio de desempleo
     * 
     * @param RequestForm $requestForm
     * @return bool
     */
    private function esParaSubsidioDesempleo(RequestForm $requestForm): bool
    {
        $payload = $requestForm->payload ?? [];
        
        // Verificar si tiene infoCertificado en el payload
        if (!isset($payload['infoCertificado'])) {
            Log::debug('esParaSubsidioDesempleo: No tiene infoCertificado en payload', [
                'request_id' => $requestForm->id,
            ]);
            return false;
        }

        // Parsear el JSON string si existe
        $infoCertificado = $payload['infoCertificado'];
        if (is_string($infoCertificado)) {
            $infoCertificado = json_decode($infoCertificado, true);
        }

        if (!is_array($infoCertificado)) {
            Log::debug('esParaSubsidioDesempleo: infoCertificado no es un array', [
                'request_id' => $requestForm->id,
                'infoCertificado_type' => gettype($payload['infoCertificado']),
            ]);
            return false;
        }

        // Verificar si paraSubsidioDesempleo está activo usando parseBooleanValue
        $paraSubsidioDesempleoRaw = $infoCertificado['paraSubsidioDesempleo'] ?? false;
        $paraSubsidioDesempleo = $requestForm->parseBooleanValue($paraSubsidioDesempleoRaw);
        
        Log::info('esParaSubsidioDesempleo: Verificando opción paraSubsidioDesempleo', [
            'request_id' => $requestForm->id,
            'paraSubsidioDesempleo_raw' => $paraSubsidioDesempleoRaw,
            'paraSubsidioDesempleo_parsed' => $paraSubsidioDesempleo,
            'infoCertificado' => $infoCertificado,
        ]);
        
        return $paraSubsidioDesempleo;
    }

    /**
     * Verifica si el certificado es tipo "otros" (necesidad específica descrita por el usuario)
     * 
     * @param RequestForm $requestForm
     * @return bool
     */
    private function esOtros(RequestForm $requestForm): bool
    {
        $payload = $requestForm->payload ?? [];
        
        // Verificar si tiene infoCertificado en el payload
        if (!isset($payload['infoCertificado'])) {
            return false;
        }

        // Parsear el JSON string si existe
        $infoCertificado = $payload['infoCertificado'];
        if (is_string($infoCertificado)) {
            $infoCertificado = json_decode($infoCertificado, true);
        }

        if (!is_array($infoCertificado)) {
            return false;
        }

        // Verificar si "otros" está activo usando parseBooleanValue
        $otrosRaw = $infoCertificado['otros'] ?? false;
        $otros = $requestForm->parseBooleanValue($otrosRaw);
        
        Log::info('esOtros: Verificando opción otros', [
            'request_id' => $requestForm->id,
            'otros_raw' => $otrosRaw,
            'otros_parsed' => $otros,
            'infoCertificado' => $infoCertificado,
        ]);
        
        return $otros;
    }
}

