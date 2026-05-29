<?php

namespace App\Services;

class ProSanetAfiliadoMapper
{
    /**
     * @return array<string, mixed>
     */
    public function mapDetailItemToAfiliadoFull(array $item): array
    {
        $personal = $this->extractPersonalInformation($item);

        $education = is_array($item['education'] ?? null) ? $item['education'] : [];
        $financial = is_array($item['financial_information'] ?? null) ? $item['financial_information'] : [];
        $labor = is_array($item['labor_information'] ?? null) ? $item['labor_information'] : [];

        return [
            'tipo_documento' => $this->stringValue($personal['document_type_label'] ?? $item['document_type_label'] ?? null),
            'documento' => $this->stringValue($personal['document_number'] ?? $item['document_number'] ?? null),
            'nombres' => $this->stringValue($personal['first_name'] ?? $item['first_name'] ?? null),
            'apellidos' => $this->stringValue($personal['last_name'] ?? $item['last_name'] ?? null),
            'estado' => $this->stringValue($personal['status'] ?? $item['status'] ?? null),
            'hospital' => null,
            'fecha_expedicion' => $this->normalizeDate($personal['expedition_date'] ?? $item['expedition_date'] ?? null),
            'fecha_nacimiento' => $this->normalizeDate($personal['birth_date'] ?? $item['birth_date'] ?? null),
            'lugar_nacimiento' => $this->stringValue($personal['birth_place'] ?? $item['birth_place'] ?? null),
            'sexo' => $this->stringValue($personal['gender'] ?? $item['gender'] ?? null),
            'rh' => $this->stringValue($personal['rh'] ?? $item['rh'] ?? null),
            'fecha_ingreso' => $this->normalizeDate($personal['admission_date'] ?? $item['admission_date'] ?? null),
            'estado_civil' => $this->stringValue($personal['marital_status'] ?? $item['marital_status'] ?? null),
            'carnet' => $this->stringValue($personal['card'] ?? $item['card'] ?? null),
            'direccion' => $this->stringValue($personal['address'] ?? $item['address'] ?? null),
            'departamento' => $this->stringValue($personal['department_name'] ?? $item['department_name'] ?? null),
            'municipio' => $this->stringValue($personal['city_name'] ?? $item['city_name'] ?? null),
            'telefono' => $this->stringValue($personal['phone_number'] ?? $item['phone_number'] ?? null),
            'celular' => $this->stringValue($personal['mobile_phone_number'] ?? $item['mobile_phone_number'] ?? null),
            'correo_personal' => $this->stringValue($personal['email'] ?? $item['email'] ?? null),
            'contacto_emergencia' => $this->stringValue($personal['emergency_contact'] ?? $item['emergency_contact'] ?? null),
            'archivo_liquidado' => $this->stringValue($personal['liquidated_file'] ?? $item['liquidated_file'] ?? null),
            'fecha_liquidacion' => $this->normalizeDate($personal['settled_date'] ?? $item['settled_date'] ?? null),
            'talla_uniforme' => $this->stringValue($personal['uniform_size'] ?? $item['uniform_size'] ?? null),
            'talla_calzado' => $this->stringValue($personal['shoe_size'] ?? $item['shoe_size'] ?? null),
            'nivel_educacion' => $this->stringValue($education['education_level'] ?? $item['education_level'] ?? null),
            'otros_estudios' => $this->stringValue($education['other_studies'] ?? $item['other_studies'] ?? null),
            'numero_cuenta' => $this->stringValue($financial['bank_account_number'] ?? $item['bank_account_number'] ?? null),
            'tipo_cuenta' => $this->stringValue($financial['bank_account_type'] ?? $item['bank_account_type'] ?? null),
            'banco' => $this->stringValue($financial['bank_name'] ?? $item['bank_name'] ?? null),
            'fecha_rethus' => $this->normalizeDate($labor['rethus_date'] ?? $item['rethus_date'] ?? null),
            'compensacion_basica' => $this->normalizeNumeric($labor['monthly_compensation'] ?? $item['monthly_compensation'] ?? null),
            'tipo_afiliacion' => $this->stringValue($labor['affiliation_type'] ?? $item['affiliation_type'] ?? null),
            'eps' => $this->stringValue($labor['eps_name'] ?? $personal['eps_name'] ?? $item['eps_name'] ?? null),
            'afp' => $this->stringValue($labor['afp_name'] ?? $personal['afp_name'] ?? $item['afp_name'] ?? null),
            'arl' => $this->stringValue($labor['arl_name'] ?? $item['arl_name'] ?? null),
            'caja_compensacion' => $this->stringValue($labor['compensation_box_name'] ?? $item['compensation_box_name'] ?? null),
            'nivel_riesgo' => $this->stringValue($labor['risk_level'] ?? $item['risk_level'] ?? null),
            'fecha_vencimiento_poliza' => $this->normalizeDate($labor['policy_expiration_date'] ?? $item['policy_expiration_date'] ?? null),
            'emisor_poliza' => $this->stringValue($labor['policy_issuer'] ?? $item['policy_issuer'] ?? null),
            'detalles' => $this->stringValue($labor['details'] ?? $item['details'] ?? null),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mapDetailItemToConveniosFull(array $item, ?string $documentoAfiliado = null): array
    {
        $covenants = $item['covenants'] ?? [];
        if (! is_array($covenants)) {
            return [];
        }

        $documento = $documentoAfiliado ?? $this->stringValue(
            $this->extractPersonalInformation($item)['document_number'] ?? $item['document_number'] ?? null
        );

        $convenios = [];
        foreach ($covenants as $covenant) {
            if (! is_array($covenant)) {
                continue;
            }

            $endDate = $covenant['end_date'] ?? null;
            $estado = $this->deriveConvenioEstado($endDate);

            $convenios[] = [
                'documento_afiliado' => $documento,
                'nombre_afiliado' => $this->stringValue($item['first_name'] ?? null),
                'apellidos_afiliado' => $this->stringValue($item['last_name'] ?? null),
                'cliente' => $this->stringValue($covenant['client_business_name'] ?? null),
                'sucursal' => $this->stringValue($covenant['branch_name'] ?? null),
                'proceso' => $this->stringValue($covenant['charge_name'] ?? null),
                'estado' => $estado,
                'fecha_ingreso' => $this->normalizeDate($covenant['admission_date'] ?? null),
                'fecha_fin' => $this->normalizeDate($endDate),
                'notas' => $this->stringValue($covenant['notes'] ?? null),
            ];
        }

        return $convenios;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mapDetailItemToBeneficiarios(array $item, ?string $documentoAfiliado = null): array
    {
        $beneficiaries = $item['beneficiaries'] ?? [];
        if (! is_array($beneficiaries)) {
            return [];
        }

        $documento = $documentoAfiliado ?? $this->stringValue(
            $this->extractPersonalInformation($item)['document_number'] ?? $item['document_number'] ?? null
        );

        $result = [];
        foreach ($beneficiaries as $beneficiary) {
            if (! is_array($beneficiary)) {
                continue;
            }

            $result[] = [
                'documento_afiliado' => $documento,
                'tipo_documento' => $this->stringValue($beneficiary['document_type_label'] ?? null),
                'documento' => $this->stringValue($beneficiary['document_number'] ?? null),
                'nombres' => $this->stringValue($beneficiary['first_name'] ?? null),
                'apellidos' => $this->stringValue($beneficiary['last_name'] ?? null),
                'fecha_nacimiento' => $this->normalizeDate($beneficiary['birth_date'] ?? null),
                'sexo' => $this->stringValue($beneficiary['gender'] ?? null),
                'parentesco' => $this->stringValue($beneficiary['relationship'] ?? $beneficiary['notes'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function mapSummaryItemToBasicAfiliado(array $item): array
    {
        return [
            'tipo_documento' => $this->stringValue($item['document_type_label'] ?? null),
            'documento' => $this->stringValue($item['document_number'] ?? null),
            'nombres' => $this->stringValue($item['first_name'] ?? null),
            'apellidos' => $this->stringValue($item['last_name'] ?? null),
            'estado' => $this->stringValue($item['status'] ?? null),
            'convenios' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPersonalInformation(array $item): array
    {
        $personal = $item['personal_information'] ?? null;

        return is_array($personal) ? $personal : $item;
    }

    private function deriveConvenioEstado(mixed $endDate): string
    {
        if ($endDate === null || $endDate === '') {
            return 'Activo';
        }

        return 'Retirado';
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $stringValue = $this->stringValue($value);
        if ($stringValue === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $stringValue)) {
            return $stringValue;
        }

        $timestamp = strtotime($stringValue);
        if ($timestamp === false) {
            return $stringValue;
        }

        return date('Y-m-d', $timestamp);
    }

    private function normalizeNumeric(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        $normalized = preg_replace('/[^\d.-]/', '', (string) $value);
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }
}
