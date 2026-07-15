<?php

namespace App\Services;

use App\Constants\RequestTypes;
use Illuminate\Support\Str;

class RequestFileNamingService
{
    /**
     * @var array<string, array{short: string, label: string}>
     */
    private const FIELD_DEFINITIONS = [
        'certificacionBancaria' => [
            'short' => 'CertBanc',
            'label' => 'Certificación bancaria',
        ],
        'diplomaEducativo' => [
            'short' => 'Diploma',
            'label' => 'Diploma educativo',
        ],
        'actaGrado' => [
            'short' => 'ActaGrado',
            'label' => 'Acta de grado',
        ],
        'certificadoEps' => [
            'short' => 'CertEPS',
            'label' => 'Certificado de EPS',
        ],
        'certificadoAfp' => [
            'short' => 'CertAFP',
            'label' => 'Certificado de AFP',
        ],
        'actividadesPdf' => [
            'short' => 'ActividadesPdf',
            'label' => 'PDF con actividades',
        ],
        'adjuntarArchivoAdicional' => [
            'short' => 'ArchivoAdicional',
            'label' => 'Archivo adicional',
        ],
        'anexoDescanso' => [
            'short' => 'AnexoDescanso',
            'label' => 'Anexo descanso laboral',
        ],
        'formatoRetiroAnexo' => [
            'short' => 'FormatoRetiro',
            'label' => 'Formato de retiro diligenciado',
        ],
        'archivoAnexo' => [
            'short' => 'ArchivoAnexo',
            'label' => 'Archivo anexo',
        ],
        'anexoFormatoDiligenciado' => [
            'short' => 'FormatoDiligenciado',
            'label' => 'Formato diligenciado',
        ],
        'anexoEvidenciaSolicitud' => [
            'short' => 'EvidenciaSolicitud',
            'label' => 'Evidencia que respalda la solicitud',
        ],
        'certificadoIncapacidad' => [
            'short' => 'CertIncapacidad',
            'label' => 'Certificado de incapacidad o licencia',
        ],
        'cedula' => [
            'short' => 'Cedula',
            'label' => 'Cédula',
        ],
        'carnet' => [
            'short' => 'Carnet',
            'label' => 'Carnet',
        ],
        'foto' => [
            'short' => 'Foto',
            'label' => 'Foto',
        ],
        'documento' => [
            'short' => 'Documento',
            'label' => 'Documento',
        ],
        'certificado_convenio_actividades' => [
            'short' => 'CertConvenioAct',
            'label' => 'Certificado convenio actividades',
        ],
    ];

    /**
     * @var array<string, array{short: string, label: string}>
     */
    private const REQUEST_TYPE_DEFINITIONS = [
        RequestTypes::CERTIFICADO_CONVENIO => [
            'short' => 'CertConvenio',
            'label' => 'Certificado de convenio',
        ],
        RequestTypes::COMPENSACION_ANUAL => [
            'short' => 'CompAnual',
            'label' => 'Compensación anual diferida',
        ],
        RequestTypes::COMPENSACION_DESCANSO => [
            'short' => 'CompDescanso',
            'label' => 'Compensación por descanso',
        ],
        RequestTypes::VERIFICACION_PAGOS => [
            'short' => 'VerifPagos',
            'label' => 'Verificación de pagos',
        ],
        RequestTypes::ACTUALIZAR_DATOS_PERSONALES => [
            'short' => 'ActDatos',
            'label' => 'Actualizar datos personales',
        ],
        RequestTypes::INCAPACIDADES_LICENCIAS => [
            'short' => 'IncapLic',
            'label' => 'Incapacidades y licencias',
        ],
        RequestTypes::SOLICITUD_MICROCREDITO => [
            'short' => 'Microcredito',
            'label' => 'Microcrédito CEII',
        ],
        RequestTypes::SOLICITUD_RETIRO_SINDICAL => [
            'short' => 'RetiroSind',
            'label' => 'Retiro sindical',
        ],
        'retiro-sindical' => [
            'short' => 'RetiroSind',
            'label' => 'Retiro sindical',
        ],
        'incapacidad-licencia' => [
            'short' => 'IncapLic',
            'label' => 'Incapacidades y licencias',
        ],
        'incapacidad-laboral' => [
            'short' => 'IncapLic',
            'label' => 'Incapacidades y licencias',
        ],
        'solicitud-microcredito' => [
            'short' => 'Microcredito',
            'label' => 'Microcrédito CEII',
        ],
    ];

    /**
     * @param  array{request_type?: string|null, document_number?: string|null}  $context
     */
    public function getStorageFilename(string $key, string $extension, array $context = []): string
    {
        $shortName = $this->getShortName($key);
        $uniqueId = substr(Str::uuid()->toString(), 0, 6);
        $extension = ltrim(strtolower($extension), '.');
        $prefix = $this->buildFilenamePrefix($context, true);

        if ($prefix !== '') {
            return sprintf('%s-%s-%s.%s', $prefix, $shortName, $uniqueId, $extension);
        }

        return sprintf('%s-%s.%s', $shortName, $uniqueId, $extension);
    }

    /**
     * @param  array{request_type?: string|null, document_number?: string|null}  $context
     */
    public function getDisplayFilename(string $key, string $extension, array $context = []): string
    {
        $label = $this->getFieldLabel($key);
        $extension = ltrim(strtolower($extension), '.');
        $prefix = $this->buildFilenamePrefix($context, false);

        if ($prefix !== '') {
            return "{$prefix} - {$label}.{$extension}";
        }

        return "{$label}.{$extension}";
    }

    public function getFieldLabel(string $key): string
    {
        return self::FIELD_DEFINITIONS[$key]['label'] ?? $this->formatKeyToLabel($key);
    }

    public function getRequestTypeLabel(?string $requestType): string
    {
        if ($requestType === null || $requestType === '') {
            return '';
        }

        $normalizedType = RequestTypes::normalize($requestType);

        return self::REQUEST_TYPE_DEFINITIONS[$normalizedType]['label']
            ?? ucfirst(str_replace('-', ' ', $normalizedType));
    }

    /**
     * @param  array{request_type?: string|null, document_number?: string|null}  $context
     */
    private function buildFilenamePrefix(array $context, bool $forStorage): string
    {
        $requestType = $context['request_type'] ?? null;
        $documentNumber = $this->sanitizeDocumentNumber($context['document_number'] ?? null);

        $typePart = '';
        if (is_string($requestType) && $requestType !== '') {
            $normalizedType = RequestTypes::normalize($requestType);
            $typePart = $forStorage
                ? (self::REQUEST_TYPE_DEFINITIONS[$normalizedType]['short'] ?? $this->formatKeyToShortName($normalizedType))
                : $this->getRequestTypeLabel($normalizedType);
        }

        if ($typePart === '' && $documentNumber === '') {
            return '';
        }

        if ($typePart === '') {
            return $documentNumber;
        }

        if ($documentNumber === '') {
            return $typePart;
        }

        $separator = $forStorage ? '-' : ' - ';

        return "{$typePart}{$separator}{$documentNumber}";
    }

    private function getShortName(string $key): string
    {
        if (isset(self::FIELD_DEFINITIONS[$key]['short'])) {
            return self::FIELD_DEFINITIONS[$key]['short'];
        }

        return $this->formatKeyToShortName($key);
    }

    private function formatKeyToShortName(string $key): string
    {
        $formatted = preg_replace('/([a-z])([A-Z])/', '$1$2', $key);
        $formatted = ucfirst((string) $formatted);
        $formatted = preg_replace('/[^a-zA-Z0-9]/', '', $formatted);

        return $formatted ?: 'Archivo';
    }

    private function formatKeyToLabel(string $key): string
    {
        $formatted = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
        $formatted = str_replace(['_', '-'], ' ', (string) $formatted);

        return ucfirst(trim($formatted));
    }

    private function sanitizeDocumentNumber(?string $documentNumber): string
    {
        if ($documentNumber === null || $documentNumber === '') {
            return '';
        }

        return preg_replace('/[^0-9]/', '', $documentNumber) ?: '';
    }
}
