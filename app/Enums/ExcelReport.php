<?php

namespace App\Enums;

use App\Services\SocioDemographicSurveyExcelExportService;
use App\Services\SstDeliveryReportService;
use App\Services\VaccinationSurveyExcelExportService;
use App\Services\WellnessDeliveryExcelExportService;
use App\Services\WellnessExcelExportService;

/**
 * Excel reports that are generated in the background by GenerateExcelReportJob.
 */
enum ExcelReport: string
{
    case SstDelivery = 'sst_delivery';
    case SocioDemographicSurvey = 'socio_demographic_survey';
    case VaccinationSurvey = 'vaccination_survey';
    case WellnessDelivery = 'wellness_delivery';
    case WellnessRequest = 'wellness_request';

    public function cacheKey(string $jobId): string
    {
        $prefix = match ($this) {
            self::SstDelivery => 'sst_report',
            self::SocioDemographicSurvey => 'survey_report',
            self::VaccinationSurvey => 'vaccination_survey_report',
            self::WellnessDelivery => 'wellness_delivery_report',
            self::WellnessRequest => 'wellness_request_report',
        };

        return "{$prefix}:{$jobId}";
    }

    public function storageDirectory(string $jobId): string
    {
        $directory = match ($this) {
            self::SstDelivery => 'reports/sst-delivery',
            self::SocioDemographicSurvey => 'reports/surveys',
            self::VaccinationSurvey => 'reports/vaccination-surveys',
            self::WellnessDelivery => 'reports/wellness-delivery',
            self::WellnessRequest => 'reports/wellness-requests',
        };

        return "{$directory}/{$jobId}";
    }

    public function fileName(): string
    {
        $now = now()->setTimezone('America/Bogota');

        return match ($this) {
            self::SstDelivery => 'reporte-dotacion-epp-'.$now->format('Ymd-His').'.xlsx',
            self::SocioDemographicSurvey => 'Reporte_Encuestas_Sociodemograficas_ProSalud_'.$now->format('Y-m-d_His').'.xlsx',
            self::VaccinationSurvey => 'Reporte_Encuesta_Vacunacion_ProSalud_'.$now->format('Y-m-d_His').'.xlsx',
            self::WellnessDelivery => 'Reporte_Entregas_Bienestar_ProSalud_'.$now->format('Y-m-d_His').'.xlsx',
            self::WellnessRequest => 'Reporte_Bienestar_ProSalud_'.$now->format('Y-m-d_His').'.xlsx',
        };
    }

    /**
     * Process name recorded in the audit log once the report is ready (null when not audited).
     */
    public function auditProcess(): ?string
    {
        return match ($this) {
            self::SocioDemographicSurvey => 'socio_demographic_survey',
            self::WellnessRequest => 'wellness_request',
            default => null,
        };
    }

    /**
     * Generate the report and return the path of the temporary xlsx file.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function generate(array $filters, array $options): string
    {
        return match ($this) {
            self::SstDelivery => app(SstDeliveryReportService::class)->generateReport($filters, $options),
            self::SocioDemographicSurvey => app(SocioDemographicSurveyExcelExportService::class)->generateReport($filters),
            self::VaccinationSurvey => app(VaccinationSurveyExcelExportService::class)->generateReport($filters),
            self::WellnessDelivery => app(WellnessDeliveryExcelExportService::class)->generateReport($filters, $options),
            self::WellnessRequest => app(WellnessExcelExportService::class)->generateReport($filters, $options),
        };
    }
}
