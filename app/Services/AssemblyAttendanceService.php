<?php

namespace App\Services;

use App\Models\AssemblyAttendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        Request $request
    ): ?AssemblyAttendance {
        $documentNumber = $this->normalizeDocument($documentNumber);

        if (empty($documentNumber)) {
            return null;
        }

        $normalizedIssueDate = $this->normalizeDate($issueDate);

        $attendance = AssemblyAttendance::create([
            'document_number' => $documentNumber,
            'full_name' => $this->normalizeName($fullName),
            'issue_date_normalized' => $normalizedIssueDate,
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
        ]);

        return $attendance;
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

