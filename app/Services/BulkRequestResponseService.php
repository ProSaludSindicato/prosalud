<?php

namespace App\Services;

use App\Constants\{RequestStatuses, RequestTypes};
use App\Models\{RequestForm, RequestResponse};
use App\Mail\RequestFormResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\{Log, Mail};
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\{IOFactory, Spreadsheet, Writer\Xlsx};
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

class BulkRequestResponseService
{
    public function __construct(
        private AuditLogService $auditLogService,
    ) {
    }

    /**
     * Generar plantilla Excel para respuesta masiva
     */
    public function generateTemplate(array $filters = []): string
    {
        try {
            // Obtener solicitudes pendientes o en revisión
            $requests = $this->getRequestsForTemplate($filters);

            // Crear spreadsheet
            $spreadsheet = new Spreadsheet();
            $spreadsheet->removeSheetByIndex(0);

            // Crear hoja principal
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Respuestas Masivas');

            // Construir hoja
            $this->buildTemplateSheet($sheet, $requests);

            // Guardar en archivo temporal
            $tempFile = tempnam(sys_get_temp_dir(), 'bulk_response_template_') . '.xlsx';
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            Log::info('Plantilla de respuesta masiva generada exitosamente', [
                'requests_count' => $requests->count(),
                'filters' => $filters,
            ]);

            return $tempFile;
        } catch (\Exception $e) {
            Log::error('Error generando plantilla de respuesta masiva', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $filters,
            ]);
            throw $e;
        }
    }

    /**
     * Procesar archivo Excel con respuestas masivas
     */
    public function processBulkResponse(string $filePath, $user = null): array
    {
        $results = [
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'successful_requests' => [],
        ];

        try {
            // Leer archivo Excel
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $data = $worksheet->toArray();

            if (empty($data) || count($data) < 2) {
                throw new \Exception('El archivo Excel está vacío o no tiene datos válidos');
            }

            // Obtener encabezados (primera fila)
            $headers = array_map('trim', $data[0]);

            // Validar estructura de columnas requeridas
            $requiredColumns = ['ID Solicitud', 'Nuevo Estado', 'Asunto Correo', 'Cuerpo Correo'];
            $columnIndexes = $this->validateAndGetColumnIndexes($headers, $requiredColumns);

            // Obtener índices de columnas adicionales para información de errores
            $additionalColumns = ['Tipo Documento', 'Número Documento', 'Nombre Completo', 'Tipo Solicitud'];
            $additionalColumnIndexes = [];
            foreach ($additionalColumns as $col) {
                $index = array_search($col, $headers);
                if ($index !== false) {
                    $additionalColumnIndexes[$col] = $index;
                }
            }

            // Procesar cada fila (empezar desde la fila 2, índice 1)
            $processedRows = 0;
            $skippedRows = 0;

            for ($rowIndex = 1; $rowIndex < count($data); $rowIndex++) {
                $row = $data[$rowIndex];
                $rowNumber = $rowIndex + 1; // Para mostrar en errores (1-indexed)

                try {
                    // Extraer datos de la fila
                    $requestId = trim($row[$columnIndexes['ID Solicitud']] ?? '');
                    $newStatus = trim($row[$columnIndexes['Nuevo Estado']] ?? '');
                    $emailSubject = trim($row[$columnIndexes['Asunto Correo']] ?? '');
                    $emailBody = trim($row[$columnIndexes['Cuerpo Correo']] ?? '');

                    // Detectar filas de INSTRUCCIONES (contienen texto "INSTRUCCIONES" o "Complete las columnas")
                    $firstCell = trim($row[0] ?? '');
                    if (stripos($firstCell, 'INSTRUCCIONES') !== false ||
                        stripos($firstCell, 'Complete las columnas') !== false ||
                        stripos($firstCell, 'Los estados válidos') !== false ||
                        stripos($firstCell, 'No modifique') !== false ||
                        stripos($firstCell, 'Puede dejar filas') !== false) {
                        $skippedRows++;
                        continue; // Saltar filas de instrucciones
                    }

                    // Validar que la fila tenga ID de solicitud
                    if (empty($requestId)) {
                        $skippedRows++;
                        continue; // Saltar filas sin ID
                    }

                    // Si no tiene nuevo estado, asunto ni cuerpo, saltar sin error (fila intencionalmente vacía)
                    if (empty($newStatus) && empty($emailSubject) && empty($emailBody)) {
                        $skippedRows++;
                        continue; // Saltar filas vacías intencionalmente
                    }

                    // Procesar respuesta individual
                    $this->processSingleResponse(
                        $requestId,
                        $newStatus,
                        $emailSubject,
                        $emailBody,
                        $user,
                        $rowNumber
                    );

                    // Agregar información de la solicitud procesada exitosamente
                    $successInfo = [
                        'row' => $rowNumber,
                        'request_id' => $requestId,
                    ];

                    // Agregar información adicional si está disponible
                    if (isset($additionalColumnIndexes['Tipo Documento'])) {
                        $successInfo['document_type'] = trim($row[$additionalColumnIndexes['Tipo Documento']] ?? '') ?: 'N/A';
                    }
                    if (isset($additionalColumnIndexes['Número Documento'])) {
                        $successInfo['document_number'] = trim($row[$additionalColumnIndexes['Número Documento']] ?? '') ?: 'N/A';
                    }
                    if (isset($additionalColumnIndexes['Nombre Completo'])) {
                        $successInfo['full_name'] = trim($row[$additionalColumnIndexes['Nombre Completo']] ?? '') ?: 'N/A';
                    }
                    if (isset($additionalColumnIndexes['Tipo Solicitud'])) {
                        $successInfo['request_type'] = trim($row[$additionalColumnIndexes['Tipo Solicitud']] ?? '') ?: 'N/A';
                    }
                    $successInfo['new_status'] = $newStatus;

                    $results['successful_requests'][] = $successInfo;
                    $results['successful']++;
                    $processedRows++;
                } catch (\Exception $e) {
                    $results['failed']++;
                    $processedRows++;

                    // Obtener información adicional de la fila para el error
                    $errorInfo = [
                        'row' => $rowNumber,
                        'request_id' => $requestId ?? 'N/A',
                        'error' => $e->getMessage(),
                    ];

                    // Agregar información adicional si está disponible
                    if (isset($additionalColumnIndexes['Tipo Documento'])) {
                        $errorInfo['document_type'] = trim($row[$additionalColumnIndexes['Tipo Documento']] ?? '') ?: 'N/A';
                    }
                    if (isset($additionalColumnIndexes['Número Documento'])) {
                        $errorInfo['document_number'] = trim($row[$additionalColumnIndexes['Número Documento']] ?? '') ?: 'N/A';
                    }
                    if (isset($additionalColumnIndexes['Nombre Completo'])) {
                        $errorInfo['full_name'] = trim($row[$additionalColumnIndexes['Nombre Completo']] ?? '') ?: 'N/A';
                    }
                    if (isset($additionalColumnIndexes['Tipo Solicitud'])) {
                        $errorInfo['request_type'] = trim($row[$additionalColumnIndexes['Tipo Solicitud']] ?? '') ?: 'N/A';
                    }

                    $results['errors'][] = $errorInfo;

                    Log::error('Error procesando fila en respuesta masiva', [
                        'row' => $rowNumber,
                        'request_id' => $requestId ?? null,
                        'error' => $e->getMessage(),
                        'error_info' => $errorInfo,
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            // Actualizar total: solo contar filas procesadas (excluyendo saltadas)
            $results['total'] = $processedRows;

            Log::info('Procesamiento masivo de respuestas completado', [
                'total' => $results['total'],
                'successful' => $results['successful'],
                'failed' => $results['failed'],
                'user_id' => $user?->id,
            ]);

            return $results;
        } catch (\Exception $e) {
            Log::error('Error procesando archivo Excel de respuestas masivas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Obtener solicitudes para la plantilla
     */
    private function getRequestsForTemplate(array $filters): Collection
    {
        $query = RequestForm::query();

        // Filtrar solo solicitudes pendientes o en revisión
        $query->whereIn('status', [RequestStatuses::PENDING, RequestStatuses::IN_REVIEW]);

        // Filtro por tipo de solicitud si se especifica
        $requestType = $filters['request_type'] ?? null;
        if ($requestType && $requestType !== 'all') {
            $query->where('request_type', $requestType);
        }

        // Filtro por rango de fechas si se especifica
        $dateRange = $filters['date_range'] ?? [];
        if (!($dateRange['include_all'] ?? true)) {
            if (isset($dateRange['start_date'])) {
                $startDate = Carbon::parse($dateRange['start_date'])->startOfDay();
                $query->where('created_at', '>=', $startDate);
            }

            if (isset($dateRange['end_date'])) {
                $endDate = Carbon::parse($dateRange['end_date'])->endOfDay();
                $query->where('created_at', '<=', $endDate);
            }
        }

        // Ordenar por fecha de creación descendente
        $query->orderBy('created_at', 'desc');

        return $query->get();
    }

    /**
     * Construir hoja de plantilla Excel
     */
    private function buildTemplateSheet(Worksheet $sheet, Collection $requests): void
    {
        // Encabezados
        $headers = [
            'ID Solicitud',
            'Tipo Documento',
            'Número Documento',
            'Nombre Completo',
            'Email',
            'Teléfono',
            'Tipo Solicitud',
            'Estado Actual',
            'Fecha Creación',
            'Nuevo Estado',
            'Asunto Correo',
            'Cuerpo Correo',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Estilizar encabezados
        $headerRange = 'A1:L1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Ajustar anchos de columna
        $columnWidths = [
            'A' => 15, // ID Solicitud
            'B' => 15, // Tipo Documento
            'C' => 18, // Número Documento
            'D' => 30, // Nombre Completo
            'E' => 30, // Email
            'F' => 18, // Teléfono
            'G' => 30, // Tipo Solicitud
            'H' => 18, // Estado Actual
            'I' => 18, // Fecha Creación
            'J' => 18, // Nuevo Estado
            'K' => 60, // Asunto Correo (aumentado)
            'L' => 80, // Cuerpo Correo (aumentado)
        ];

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        // Llenar datos
        $row = 2;
        foreach ($requests as $request) {
            // Generar asunto por defecto
            $defaultSubject = $this->generateDefaultEmailSubject($request);

            $rowData = [
                $request->id,
                $request->document_type,
                $request->document_number,
                $request->full_name,
                $request->email,
                $request->phone_number,
                $this->getRequestTypeLabel($request->request_type),
                $this->getStatusLabel($request->status),
                $this->formatDate($request->created_at),
                '', // Nuevo Estado (editable)
                $defaultSubject, // Asunto Correo (prediligenciado, editable)
                '', // Cuerpo Correo (editable)
            ];

            $sheet->fromArray([$rowData], null, "A{$row}");

            // Aplicar color de fondo a columna "Estado Actual" (H) según el estado
            $statusCell = "H{$row}";
            $this->applyStatusColor($sheet, $statusCell, $request->status);

            // Agregar validación de datos para columna "Nuevo Estado" (J)
            $newStatusCell = "J{$row}";
            $this->addStatusValidation($sheet, $newStatusCell);

            // Configurar formato de texto para columna de cuerpo
            $bodyCell = "L{$row}";
            $sheet->getStyle($bodyCell)->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(-1); // Auto-height

            $row++;
        }

        // Aplicar bordes a todas las filas de datos
        if ($row > 2) {
            $dataRange = "A1:L" . ($row - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            ]);
        }

        // Agregar autofiltro a columnas de identificación (A-I) y columna Nuevo Estado (J)
        // Excluir solo columnas editables de texto largo: K (Asunto Correo), L (Cuerpo Correo)
        if ($row > 2) {
            $sheet->setAutoFilter("A1:J" . ($row - 1));
        }

        // Congelar primera fila
        $sheet->freezePane('A2');

        // Agregar nota informativa
        $noteRow = $row + 2;
        $sheet->setCellValue("A{$noteRow}", 'INSTRUCCIONES:');
        $sheet->getStyle("A{$noteRow}")->getFont()->setBold(true);
        $noteRow++;
        $sheet->setCellValue("A{$noteRow}", '1. Complete las columnas "Nuevo Estado", "Asunto Correo" y "Cuerpo Correo" para cada solicitud.');
        $noteRow++;
        $sheet->setCellValue("A{$noteRow}", '2. Los estados válidos son: Pendiente, En Revisión, Completada, Rechazada');
        $noteRow++;
        $sheet->setCellValue("A{$noteRow}", '3. No modifique las columnas de identificación (ID Solicitud, Tipo Documento, etc.)');
        $noteRow++;
        $sheet->setCellValue("A{$noteRow}", '4. Puede dejar filas vacías si no desea procesarlas');
        $noteRow++;
        $sheet->setCellValue("A{$noteRow}", '5. IMPORTANTE: Las respuestas masivas NO permiten agregar archivos como anexos del correo.');
        $sheet->getStyle("A{$noteRow}")->getFont()->setBold(true);
        $noteRow++;
        $sheet->setCellValue("A{$noteRow}", '   Si requiere anexos en la respuesta, debe hacerlo manualmente desde el panel de administración.');
    }

    /**
     * Aplicar color de fondo según el estado actual
     */
    private function applyStatusColor(Worksheet $sheet, string $cell, string $status): void
    {
        $normalizedStatus = strtoupper($status);

        // Mapeo de estados a colores RGB
        $colorMap = [
            RequestStatuses::PENDING => 'FFF2CC',    // Amarillo claro para Pendiente
            RequestStatuses::IN_REVIEW => 'B4C6E7',  // Azul claro para En Revisión
            RequestStatuses::COMPLETED => 'D5E8D4',  // Verde claro para Completada
            RequestStatuses::REJECTED => 'F8CECC',   // Rojo claro para Rechazada
            'PENDING' => 'FFF2CC',                   // Amarillo claro
            'IN_REVIEW' => 'B4C6E7',                 // Azul claro
            'COMPLETED' => 'D5E8D4',                 // Verde claro
            'REJECTED' => 'F8CECC',                   // Rojo claro
        ];

        $color = $colorMap[$normalizedStatus] ?? null;

        if ($color) {
            $sheet->getStyle($cell)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($color);
        }
    }

    /**
     * Agregar validación de datos para estado (lista desplegable en español)
     */
    private function addStatusValidation(Worksheet $sheet, string $cell): void
    {
        $validation = $sheet->getCell($cell)->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(true);
        $validation->setErrorTitle('Estado inválido');
        $validation->setError('El estado debe ser uno de los valores permitidos: Pendiente, En Revisión, Completada, Rechazada');
        $validation->setPromptTitle('Seleccione un estado');
        $validation->setPrompt('Seleccione un estado válido de la lista desplegable');
        // Usar lista en español separada por comas para la validación
        $validation->setFormula1('"Pendiente,En Revisión,Completada,Rechazada"');
    }

    /**
     * Validar y obtener índices de columnas requeridas
     */
    private function validateAndGetColumnIndexes(array $headers, array $requiredColumns): array
    {
        $indexes = [];
        $missingColumns = [];

        foreach ($requiredColumns as $column) {
            $index = array_search($column, $headers);
            if ($index === false) {
                $missingColumns[] = $column;
            } else {
                $indexes[$column] = $index;
            }
        }

        if (!empty($missingColumns)) {
            throw new \Exception('Faltan columnas requeridas en el archivo: ' . implode(', ', $missingColumns));
        }

        return $indexes;
    }

    /**
     * Procesar respuesta individual
     */
    private function processSingleResponse(
        string $requestId,
        string $newStatus,
        string $emailSubject,
        string $emailBody,
        $user,
        int $rowNumber
    ): void {
        // Validar que la solicitud exista
        $requestForm = RequestForm::find($requestId);
        if (!$requestForm) {
            throw new \Exception("Solicitud con ID {$requestId} no encontrada");
        }

        // Validar que la solicitud esté en estado pendiente o en revisión
        $currentStatus = strtoupper($requestForm->status);
        $allowedStatuses = [
            strtoupper(RequestStatuses::PENDING),
            strtoupper(RequestStatuses::IN_REVIEW),
            'PENDING',
            'IN_REVIEW',
        ];

        if (!in_array($currentStatus, $allowedStatuses)) {
            $currentStatusLabel = $this->getStatusLabel($requestForm->status);
            throw new \Exception("La solicitud con ID {$requestId} no está en estado pendiente o en revisión. Estado actual: {$currentStatusLabel}");
        }

        // Normalizar estado desde español a valor técnico
        $normalizedStatus = $this->normalizeStatusFromSpanish($newStatus);

        if (!$normalizedStatus) {
            $validStatusesSpanish = ['Pendiente', 'En Revisión', 'Completada', 'Rechazada'];
            throw new \Exception("Estado inválido: {$newStatus}. Estados válidos: " . implode(', ', $validStatusesSpanish));
        }

        // Validar campos requeridos
        if (empty($emailSubject)) {
            throw new \Exception('El asunto del correo es requerido');
        }

        if (empty($emailBody)) {
            throw new \Exception('El cuerpo del correo es requerido');
        }

        // Guardar estado anterior
        $oldStatus = $requestForm->status;

        // Determinar email destinatario
        $recipientEmail = $requestForm->email;
        $isPersonalDataUpdate = $requestForm->request_type === RequestTypes::ACTUALIZAR_DATOS_PERSONALES;
        $isCompletedOrApproved = $normalizedStatus === RequestStatuses::COMPLETED;

        if ($isPersonalDataUpdate && $isCompletedOrApproved) {
            $payload = $requestForm->payload ?? [];
            $nuevoCorreo = $payload['correo'] ?? null;

            if (!empty($nuevoCorreo) && filter_var($nuevoCorreo, FILTER_VALIDATE_EMAIL)) {
                $recipientEmail = $nuevoCorreo;
            }
        }

        // Enviar correo PRIMERO (si falla, no actualizamos el estado)
        try {
            $mail = Mail::to($recipientEmail);

            // Agregar CC para solicitudes de microcrédito
            if ($requestForm->request_type === RequestTypes::SOLICITUD_MICROCREDITO) {
                $mail->cc('ceiisas@hotmail.com');
            }

            $mail->send(new RequestFormResponse(
                $requestForm,
                $emailSubject,
                $emailBody,
                $normalizedStatus,
                [] // Sin adjuntos en respuesta masiva por ahora
            ));

            Log::info('Correo de respuesta masiva enviado exitosamente', [
                'request_id' => $requestId,
                'row' => $rowNumber,
                'email_recipient' => $recipientEmail,
                'status' => $normalizedStatus,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error enviando correo en respuesta masiva', [
                'request_id' => $requestId,
                'row' => $rowNumber,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Error al enviar el correo: ' . $e->getMessage());
        }

        // Actualizar estado de la solicitud (solo si el correo se envió exitosamente)
        $updateData = ['status' => $normalizedStatus];

        if ($normalizedStatus === RequestStatuses::COMPLETED || $normalizedStatus === RequestStatuses::REJECTED) {
            $updateData['processed_at'] = now();
        } else {
            $updateData['processed_at'] = null;
        }

        $requestForm->update($updateData);
        $requestForm->refresh();

        // Crear registro de respuesta
        $userId = $user ? $user->id : null;
        RequestResponse::create([
            'request_form_id' => $requestId,
            'responded_by' => $userId,
            'status' => $normalizedStatus,
            'email_subject' => $emailSubject,
            'email_body' => $emailBody,
            'created_at' => now(),
        ]);

        // Log de auditoría
        Log::info('Respuesta masiva procesada exitosamente', [
            'request_id' => $requestId,
            'row' => $rowNumber,
            'old_status' => $oldStatus,
            'new_status' => $normalizedStatus,
            'user_id' => $userId,
        ]);
    }

    /**
     * Obtener etiqueta del tipo de solicitud
     */
    private function getRequestTypeLabel(string $requestType): string
    {
        // Normalizar el tipo de solicitud primero (por si viene con alias)
        $normalizedType = RequestTypes::normalize($requestType);

        $labels = [
            RequestTypes::CERTIFICADO_CONVENIO => 'Certificado de Convenio',
            RequestTypes::COMPENSACION_ANUAL => 'Compensación Anual Diferida',
            RequestTypes::COMPENSACION_DESCANSO => 'Compensación por Descanso',
            RequestTypes::VERIFICACION_PAGOS => 'Verificación de Pagos',
            RequestTypes::SOLICITUD_RETIRO_SINDICAL => 'Retiro Sindical',
            RequestTypes::ACTUALIZAR_DATOS_PERSONALES => 'Actualizar Datos Personales',
            RequestTypes::SOLICITUD_MICROCREDITO => 'Microcrédito CEII',
            RequestTypes::INCAPACIDADES_LICENCIAS => 'Incapacidades y Licencias',
            // Alias para compatibilidad con datos antiguos
            'retiro-sindical' => 'Retiro Sindical',
            'incapacidad-licencia' => 'Incapacidades y Licencias',
            'incapacidad-laboral' => 'Incapacidades y Licencias',
            'solicitud-microcredito' => 'Microcrédito CEII',
        ];

        return $labels[$normalizedType] ?? $labels[$requestType] ?? $requestType;
    }

    /**
     * Obtener etiqueta del estado
     */
    private function getStatusLabel(string $status): string
    {
        $labels = [
            RequestStatuses::PENDING => 'Pendiente',
            RequestStatuses::IN_REVIEW => 'En Revisión',
            RequestStatuses::COMPLETED => 'Completada',
            RequestStatuses::REJECTED => 'Rechazada',
        ];

        return $labels[strtoupper($status)] ?? $status;
    }

    /**
     * Formatear fecha para Excel
     */
    private function formatDate($date): string
    {
        if (!$date) {
            return '';
        }

        if (is_string($date)) {
            try {
                $date = Carbon::parse($date);
            } catch (\Exception $e) {
                return '';
            }
        }

        return $date->format('d/m/Y H:i:s');
    }

    /**
     * Normalizar estado desde español a valor técnico
     *
     * @param string $statusInSpanish Estado en español (ej: "Pendiente", "En Revisión")
     * @return string|null Valor técnico normalizado o null si es inválido
     */
    private function normalizeStatusFromSpanish(string $statusInSpanish): ?string
    {
        $statusInSpanish = trim($statusInSpanish);

        // Mapeo de estados en español a valores técnicos
        $statusMap = [
            'Pendiente' => RequestStatuses::PENDING,
            'pendiente' => RequestStatuses::PENDING,
            'PENDIENTE' => RequestStatuses::PENDING,
            'En Revisión' => RequestStatuses::IN_REVIEW,
            'En revisión' => RequestStatuses::IN_REVIEW,
            'en revisión' => RequestStatuses::IN_REVIEW,
            'EN REVISIÓN' => RequestStatuses::IN_REVIEW,
            'En Revision' => RequestStatuses::IN_REVIEW, // Sin tilde
            'en revision' => RequestStatuses::IN_REVIEW,
            'EN REVISION' => RequestStatuses::IN_REVIEW,
            'Completada' => RequestStatuses::COMPLETED,
            'completada' => RequestStatuses::COMPLETED,
            'COMPLETADA' => RequestStatuses::COMPLETED,
            'Rechazada' => RequestStatuses::REJECTED,
            'rechazada' => RequestStatuses::REJECTED,
            'RECHAZADA' => RequestStatuses::REJECTED,
            // También aceptar valores técnicos directamente (por compatibilidad)
            RequestStatuses::PENDING => RequestStatuses::PENDING,
            RequestStatuses::IN_REVIEW => RequestStatuses::IN_REVIEW,
            RequestStatuses::COMPLETED => RequestStatuses::COMPLETED,
            RequestStatuses::REJECTED => RequestStatuses::REJECTED,
            'PENDING' => RequestStatuses::PENDING,
            'IN_REVIEW' => RequestStatuses::IN_REVIEW,
            'COMPLETED' => RequestStatuses::COMPLETED,
            'REJECTED' => RequestStatuses::REJECTED,
        ];

        return $statusMap[$statusInSpanish] ?? null;
    }

    /**
     * Generar asunto por defecto para el correo basado en el tipo de solicitud y condiciones especiales
     * Sigue la misma lógica que el frontend
     */
    private function generateDefaultEmailSubject(RequestForm $request): string
    {
        $requestId = $request->id;
        $requestType = $request->request_type;
        $requestTypeLabel = $this->getRequestTypeLabel($requestType);

        // Caso 1: Actualización de Datos Personales
        if ($requestType === RequestTypes::ACTUALIZAR_DATOS_PERSONALES) {
            return "Actualización de Datos Personales - Solicitud #{$requestId}";
        }

        // Parsear infoCertificado del payload
        $infoCertificado = $this->parseInfoCertificado($request);

        // Verificar condiciones especiales
        $needsCompensaciones = $this->requiresManualCompensaciones($request, $infoCertificado);
        $isFondoPensiones = $this->isDirigidoFondoPensiones($infoCertificado);
        $needsActividades = $this->requiresAdicionarActividades($infoCertificado);
        $paraSubsidioDesempleo = $this->checkFlag($infoCertificado['paraSubsidioDesempleo'] ?? false);
        $paraSubsidioVivienda = $this->checkFlag($infoCertificado['paraSubsidioVivienda'] ?? false);

        // Caso 2: Certificados con Compensaciones Manuales
        if ($needsCompensaciones) {
            $emailSubject = "Certificado de Convenio - Solicitud #{$requestId}";

            if ($paraSubsidioDesempleo) {
                $emailSubject = "Certificado de Convenio - Subsidio de Desempleo - Solicitud #{$requestId}";
            } elseif ($paraSubsidioVivienda) {
                $emailSubject = "Certificado de Convenio - Subsidio de Vivienda - Solicitud #{$requestId}";
            }

            return $emailSubject;
        }

        // Caso 3: Formulario Normal (caso por defecto)
        $emailSubject = "Respuesta a su solicitud #{$requestId} de {$requestTypeLabel}";

        // Excepciones especiales
        if ($isFondoPensiones) {
            $emailSubject = "Certificado de Convenio - Fondo de Pensiones - Solicitud #{$requestId}";
        } elseif ($needsActividades) {
            $emailSubject = "Certificado de Convenio - Con Actividades - Solicitud #{$requestId}";
        }

        return $emailSubject;
    }

    /**
     * Parsear infoCertificado del payload (puede ser objeto o string JSON)
     */
    private function parseInfoCertificado(RequestForm $request): array
    {
        $payload = $request->payload ?? [];
        $infoCertificado = $payload['infoCertificado'] ?? [];

        if (is_string($infoCertificado)) {
            try {
                $infoCertificado = json_decode($infoCertificado, true);
                if (!is_array($infoCertificado)) {
                    $infoCertificado = [];
                }
            } catch (\Exception $e) {
                $infoCertificado = [];
            }
        }

        if (!is_array($infoCertificado)) {
            $infoCertificado = [];
        }

        return $infoCertificado;
    }

    /**
     * Verificar si requiere compensaciones manuales
     */
    private function requiresManualCompensaciones(RequestForm $request, array $infoCertificado): bool
    {
        // Solo para certificados de convenio
        if ($request->request_type !== RequestTypes::CERTIFICADO_CONVENIO) {
            return false;
        }

        // Verificar si tiene valorCompensaciones activo
        $valorCompensaciones = $this->checkFlag($infoCertificado['valorCompensaciones'] ?? false);

        // Verificar otros flags que requieren compensaciones manuales
        $paraSubsidioDesempleo = $this->checkFlag($infoCertificado['paraSubsidioDesempleo'] ?? false);
        $paraSubsidioVivienda = $this->checkFlag($infoCertificado['paraSubsidioVivienda'] ?? false);
        $dirigidoFondoPensiones = $this->checkFlag($infoCertificado['dirigidoFondoPensiones'] ?? false);

        return $valorCompensaciones || $paraSubsidioDesempleo || $paraSubsidioVivienda || $dirigidoFondoPensiones;
    }

    /**
     * Verificar si está dirigido a fondo de pensiones
     */
    private function isDirigidoFondoPensiones(array $infoCertificado): bool
    {
        return $this->checkFlag($infoCertificado['dirigidoFondoPensiones'] ?? false);
    }

    /**
     * Verificar si requiere adicionar actividades
     */
    private function requiresAdicionarActividades(array $infoCertificado): bool
    {
        return $this->checkFlag($infoCertificado['adicionarActividades'] ?? false);
    }

    /**
     * Verificar flags booleanos en múltiples formatos
     */
    private function checkFlag($value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }
        if ($value === false || $value === 0 || $value === null || $value === '') {
            return false;
        }

        $strValue = strtolower(trim((string) $value));
        return in_array($strValue, ['true', '1', 'yes', 'si', 'sí']);
    }
}

