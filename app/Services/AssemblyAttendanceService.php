<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyAttendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class AssemblyAttendanceService
{
    /**
     * Registers an attendance entry for an authenticated delegate.
     */
    public function record(
        ?string $documentNumber,
        ?string $fullName,
        ?string $issueDate,
        Request $request,
        ?string $signatureData = null
    ): ?AssemblyAttendance {
        $documentNumber = $this->normalizeDocument($documentNumber);

        if (empty($documentNumber)) {
            return null;
        }

        $normalizedIssueDate = $this->normalizeDate($issueDate);
        $signaturePath = $this->storeSignature($documentNumber, $signatureData);

        // Get the active assembly
        $assembly = Assembly::getCurrent() ?? Assembly::getOrCreateDefault();

        $attendance = AssemblyAttendance::create([
            'assembly_id' => $assembly->id,
            'document_number' => $documentNumber,
            'full_name' => $this->normalizeName($fullName),
            'issue_date_normalized' => $normalizedIssueDate,
            'signature_path' => $signaturePath,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit($request->userAgent() ?? '', 512, ''),
            'authenticated_at' => now(),
        ]);

        Log::info('Asistencia de delegado registrada', [
            'document_number' => $attendance->document_number,
            'full_name' => $attendance->full_name,
            'issue_date' => $attendance->issue_date_normalized?->toDateString(),
            'ip_address' => $attendance->ip_address,
            'source' => $request->path(),
            'signature_path' => $attendance->signature_path,
        ]);

        return $attendance;
    }

    private function storeSignature(string $documentNumber, ?string $signatureData): ?string
    {
        if (empty($signatureData)) {
            return null;
        }

        if (!preg_match('/^data:(image\/(png|jpe?g));base64,/', $signatureData, $matches)) {
            throw new \InvalidArgumentException('Formato de firma inválido.');
        }

        $mimeType = $matches[1];
        $extension = 'jpeg' === $matches[2] ? 'jpg' : $matches[2];
        $base64 = substr($signatureData, strpos($signatureData, ',') + 1);
        $binary = base64_decode($base64, true);

        if (false === $binary) {
            throw new \InvalidArgumentException('La firma no se pudo decodificar correctamente.');
        }

        if (strlen($binary) > 1024 * 1024) {
            throw new \InvalidArgumentException('La firma excede el tamaño máximo permitido de 1MB.');
        }

        $safeDocument = preg_replace('/[^A-Za-z0-9_\-]/', '_', $documentNumber);
        $path = sprintf('assembly-signatures/%s/%s.%s', $safeDocument, Str::uuid(), $extension);

        Storage::disk('prosalud-private')->put($path, $binary, ['visibility' => 'private', 'ContentType' => $mimeType]);

        return $path;
    }

    private function normalizeDocument(?string $document): ?string
    {
        if (null === $document) {
            return null;
        }

        $normalized = trim($document);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeName(?string $name): ?string
    {
        if (null === $name) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $name));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeDate(?string $date): ?string
    {
        if (null === $date) {
            return null;
        }

        $value = trim((string) $date);

        if ($value === '') {
            return null;
        }

        $formats = [
            'Y-m-d',
            'd/m/Y',
            'j/m/Y',
            'd-m-Y',
            'j-n-Y',
            'm/d/Y',
            'd/M/Y',
            'j/M/Y',
        ];

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $value);
            if ($parsed && $parsed->format($format) === $value) {
                return $parsed->format('Y-m-d');
            }
        }

        // Excel serial number
        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}

