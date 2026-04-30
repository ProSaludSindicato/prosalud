<?php

namespace Database\Seeders;

use App\Models\Hospital;
use App\Models\Survey;
use Illuminate\Database\Seeder;

class SurveyTestSeeder extends Seeder
{
    public function run(): void
    {
        $hospitalIds = Hospital::orderBy('id')->pluck('id')->toArray();
        $restrictedHospitalIds = array_slice($hospitalIds, 0, 2);

        // 1. All question types — public, no signature, multiple responses
        $survey1 = Survey::create([
            'title' => '[TEST] Todos los tipos de preguntas',
            'description' => 'Encuesta de prueba que cubre los 9 tipos de preguntas disponibles. Acceso público, sin verificación.',
            'status' => 'active',
            'access_type' => 'public',
            'allowed_affiliate_statuses' => ['activo', 'retirado'],
            'requires_signature' => false,
            'allows_multiple_responses' => true,
            'start_date' => now()->subDays(1),
            'end_date' => now()->addDays(30),
        ]);

        $survey1->questions()->insert([
            [
                'survey_id' => $survey1->id,
                'type' => 'text',
                'label' => '¿Cuál es su nombre completo?',
                'help_text' => 'Escriba su nombre tal como aparece en su documento.',
                'is_required' => true,
                'order' => 1,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey1->id,
                'type' => 'textarea',
                'label' => 'Describa brevemente su experiencia laboral en el último año',
                'help_text' => null,
                'is_required' => false,
                'order' => 2,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey1->id,
                'type' => 'yes_no',
                'label' => '¿Ha asistido a alguna capacitación sindical en los últimos 6 meses?',
                'help_text' => null,
                'is_required' => true,
                'order' => 3,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey1->id,
                'type' => 'single_choice',
                'label' => '¿Cuál es su cargo actual?',
                'help_text' => 'Seleccione la opción que mejor describe su cargo.',
                'is_required' => true,
                'order' => 4,
                'options' => json_encode([
                    ['value' => 'medico', 'label' => 'Médico/a'],
                    ['value' => 'enfermero', 'label' => 'Enfermero/a'],
                    ['value' => 'auxiliar', 'label' => 'Auxiliar de enfermería'],
                    ['value' => 'administrativo', 'label' => 'Administrativo/a'],
                    ['value' => 'otro', 'label' => 'Otro'],
                ]),
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey1->id,
                'type' => 'multiple_choice',
                'label' => '¿Qué beneficios sindicales ha utilizado en el último año? (Puede seleccionar varias)',
                'help_text' => null,
                'is_required' => false,
                'order' => 5,
                'options' => json_encode([
                    ['value' => 'capacitacion', 'label' => 'Capacitaciones'],
                    ['value' => 'recreacion', 'label' => 'Actividades recreativas'],
                    ['value' => 'auxilios', 'label' => 'Auxilios económicos'],
                    ['value' => 'convenios', 'label' => 'Convenios comerciales'],
                    ['value' => 'juridica', 'label' => 'Asesoría jurídica'],
                    ['value' => 'ninguno', 'label' => 'Ninguno'],
                ]),
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey1->id,
                'type' => 'date',
                'label' => '¿Cuál es su fecha de ingreso a la institución?',
                'help_text' => null,
                'is_required' => false,
                'order' => 6,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey1->id,
                'type' => 'number',
                'label' => '¿Cuántos años lleva trabajando en el sector salud?',
                'help_text' => 'Ingrese un número entero.',
                'is_required' => true,
                'order' => 7,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey1->id,
                'type' => 'scale',
                'label' => 'Del 1 al 10, ¿cómo califica la gestión del sindicato en el último año?',
                'help_text' => '1 = Muy mala, 10 = Excelente.',
                'is_required' => true,
                'order' => 8,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey1->id,
                'type' => 'ranking',
                'label' => 'Ordene los siguientes temas según su importancia para el sindicato (1 = más importante)',
                'help_text' => 'Asigne un número diferente a cada opción.',
                'is_required' => true,
                'order' => 9,
                'options' => json_encode([
                    ['value' => 'salarios', 'label' => 'Mejora salarial'],
                    ['value' => 'condiciones', 'label' => 'Condiciones laborales'],
                    ['value' => 'salud_mental', 'label' => 'Salud mental y bienestar'],
                    ['value' => 'dotacion', 'label' => 'Dotación y EPP'],
                    ['value' => 'formacion', 'label' => 'Formación y capacitación'],
                ]),
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 2. Climate survey — authenticated, activos only, WITH signature, single response
        $survey2 = Survey::create([
            'title' => '[TEST] Encuesta de Clima Laboral 2026',
            'description' => 'Encuesta confidencial sobre el clima organizacional. Solo para afiliados activos verificados. Requiere firma digital.',
            'status' => 'active',
            'access_type' => 'authenticated',
            'allowed_affiliate_statuses' => ['activo'],
            'requires_signature' => true,
            'allows_multiple_responses' => false,
            'start_date' => now()->subDays(3),
            'end_date' => now()->addDays(15),
        ]);

        $survey2->questions()->insert([
            [
                'survey_id' => $survey2->id,
                'type' => 'scale',
                'label' => '¿Cómo califica el ambiente de trabajo en su área?',
                'help_text' => '1 = Muy malo, 10 = Excelente.',
                'is_required' => true,
                'order' => 1,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey2->id,
                'type' => 'single_choice',
                'label' => '¿Siente que su trabajo es reconocido por sus superiores?',
                'help_text' => null,
                'is_required' => true,
                'order' => 2,
                'options' => json_encode([
                    ['value' => 'siempre', 'label' => 'Siempre'],
                    ['value' => 'casi_siempre', 'label' => 'Casi siempre'],
                    ['value' => 'aveces', 'label' => 'A veces'],
                    ['value' => 'nunca', 'label' => 'Nunca'],
                ]),
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey2->id,
                'type' => 'yes_no',
                'label' => '¿Recomendaría trabajar en esta institución a un colega?',
                'help_text' => null,
                'is_required' => true,
                'order' => 3,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey2->id,
                'type' => 'textarea',
                'label' => '¿Qué aspectos mejoraría del entorno laboral?',
                'help_text' => 'Su respuesta es anónima y confidencial.',
                'is_required' => false,
                'order' => 4,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 3. Satisfaction survey — authenticated, retirados ONLY, no signature
        $survey3 = Survey::create([
            'title' => '[TEST] Satisfacción con Servicios para Afiliados Retirados',
            'description' => 'Encuesta dirigida exclusivamente a afiliados en estado de retiro. Nos ayuda a mejorar los servicios post-laborales.',
            'status' => 'active',
            'access_type' => 'authenticated',
            'allowed_affiliate_statuses' => ['retirado'],
            'requires_signature' => false,
            'allows_multiple_responses' => true,
            'start_date' => now()->subDays(5),
            'end_date' => null,
        ]);

        $survey3->questions()->insert([
            [
                'survey_id' => $survey3->id,
                'type' => 'scale',
                'label' => '¿Qué tan satisfecho está con los servicios que recibe como afiliado retirado?',
                'help_text' => '1 = Muy insatisfecho, 10 = Muy satisfecho.',
                'is_required' => true,
                'order' => 1,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey3->id,
                'type' => 'multiple_choice',
                'label' => '¿Qué servicios utiliza con mayor frecuencia?',
                'help_text' => null,
                'is_required' => true,
                'order' => 2,
                'options' => json_encode([
                    ['value' => 'pension', 'label' => 'Asesoría pensional'],
                    ['value' => 'salud', 'label' => 'Beneficios de salud'],
                    ['value' => 'recreacion', 'label' => 'Actividades recreativas'],
                    ['value' => 'juridica', 'label' => 'Asesoría jurídica'],
                    ['value' => 'ninguno', 'label' => 'Ninguno actualmente'],
                ]),
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey3->id,
                'type' => 'textarea',
                'label' => 'Comentarios o sugerencias para mejorar los servicios',
                'help_text' => null,
                'is_required' => false,
                'order' => 3,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 4. Health survey — authenticated, activos+retirados, no signature, multiple responses
        $survey4 = Survey::create([
            'title' => '[TEST] Encuesta de Salud y Bienestar',
            'description' => 'Encuesta sobre hábitos de salud y bienestar. Disponible para todos los afiliados (activos y retirados).',
            'status' => 'active',
            'access_type' => 'authenticated',
            'allowed_affiliate_statuses' => ['activo', 'retirado'],
            'requires_signature' => false,
            'allows_multiple_responses' => true,
            'start_date' => now(),
            'end_date' => now()->addDays(60),
        ]);

        $survey4->questions()->insert([
            [
                'survey_id' => $survey4->id,
                'type' => 'single_choice',
                'label' => '¿Realiza actividad física regularmente?',
                'help_text' => null,
                'is_required' => true,
                'order' => 1,
                'options' => json_encode([
                    ['value' => 'diario', 'label' => 'Diariamente'],
                    ['value' => 'semanal', 'label' => '3-4 veces por semana'],
                    ['value' => 'ocasional', 'label' => 'Ocasionalmente'],
                    ['value' => 'nunca', 'label' => 'Nunca'],
                ]),
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey4->id,
                'type' => 'yes_no',
                'label' => '¿Ha recibido atención psicológica en el último año?',
                'help_text' => null,
                'is_required' => true,
                'order' => 2,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey4->id,
                'type' => 'number',
                'label' => '¿Cuántas horas duerme en promedio por noche?',
                'help_text' => 'Ingrese un número entre 1 y 24.',
                'is_required' => true,
                'order' => 3,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 5. SST survey — restricted to first 2 hospitals, activos, WITH signature
        $survey5 = Survey::create([
            'title' => '[TEST] Encuesta SST — Seguridad y Salud en el Trabajo',
            'description' => 'Encuesta de condiciones de seguridad laboral. Restringida a hospitales seleccionados. Requiere firma digital.',
            'status' => 'active',
            'access_type' => 'restricted',
            'allowed_affiliate_statuses' => ['activo'],
            'requires_signature' => true,
            'allows_multiple_responses' => false,
            'start_date' => now()->subDays(2),
            'end_date' => now()->addDays(20),
        ]);

        $survey5->questions()->insert([
            [
                'survey_id' => $survey5->id,
                'type' => 'yes_no',
                'label' => '¿Cuenta con todos los elementos de protección personal (EPP) necesarios para su labor?',
                'help_text' => null,
                'is_required' => true,
                'order' => 1,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey5->id,
                'type' => 'single_choice',
                'label' => '¿Ha reportado algún accidente o incidente de trabajo en el último año?',
                'help_text' => null,
                'is_required' => true,
                'order' => 2,
                'options' => json_encode([
                    ['value' => 'accidente', 'label' => 'Sí, un accidente de trabajo'],
                    ['value' => 'incidente', 'label' => 'Sí, un incidente (sin lesión)'],
                    ['value' => 'ambos', 'label' => 'Ambos'],
                    ['value' => 'ninguno', 'label' => 'No, ninguno'],
                ]),
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey5->id,
                'type' => 'ranking',
                'label' => 'Ordene los siguientes riesgos laborales de mayor a menor presencia en su área',
                'help_text' => '1 = mayor presencia, 4 = menor presencia.',
                'is_required' => true,
                'order' => 3,
                'options' => json_encode([
                    ['value' => 'biologico', 'label' => 'Riesgo biológico'],
                    ['value' => 'ergonomico', 'label' => 'Riesgo ergonómico'],
                    ['value' => 'psicosocial', 'label' => 'Riesgo psicosocial'],
                    ['value' => 'fisico', 'label' => 'Riesgo físico'],
                ]),
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey5->id,
                'type' => 'textarea',
                'label' => 'Describa brevemente las condiciones de riesgo más críticas en su área de trabajo',
                'help_text' => 'Esta información será tratada de forma confidencial.',
                'is_required' => false,
                'order' => 4,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        if (! empty($restrictedHospitalIds)) {
            $survey5->hospitals()->sync($restrictedHospitalIds);
        }

        // 6. Draft pre-inscription — authenticated, activos, no signature (not yet published)
        $survey6 = Survey::create([
            'title' => '[TEST] Preinscripción Evento Deportivo 2026 (BORRADOR)',
            'description' => 'Formulario de preinscripción para el evento deportivo anual. Aún en configuración, no disponible para afiliados.',
            'status' => 'draft',
            'access_type' => 'authenticated',
            'allowed_affiliate_statuses' => ['activo'],
            'requires_signature' => false,
            'allows_multiple_responses' => false,
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(40),
        ]);

        $survey6->questions()->insert([
            [
                'survey_id' => $survey6->id,
                'type' => 'single_choice',
                'label' => '¿En qué disciplina deportiva desea participar?',
                'help_text' => null,
                'is_required' => true,
                'order' => 1,
                'options' => json_encode([
                    ['value' => 'futbol', 'label' => 'Fútbol'],
                    ['value' => 'voleibol', 'label' => 'Voleibol'],
                    ['value' => 'atletismo', 'label' => 'Atletismo'],
                    ['value' => 'natacion', 'label' => 'Natación'],
                    ['value' => 'baloncesto', 'label' => 'Baloncesto'],
                ]),
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey6->id,
                'type' => 'yes_no',
                'label' => '¿Tiene alguna condición médica que deba informar para su participación?',
                'help_text' => null,
                'is_required' => true,
                'order' => 2,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey6->id,
                'type' => 'text',
                'label' => 'Talla de camiseta',
                'help_text' => 'Ej: XS, S, M, L, XL, XXL',
                'is_required' => true,
                'order' => 3,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 7. Ranking with non-unique priority — public, no signature
        $survey7 = Survey::create([
            'title' => '[TEST] Prioridades de Negociación Colectiva (ranking con empates)',
            'description' => 'Encuesta pública para conocer las prioridades de los trabajadores. Permite asignar la misma prioridad a varios ítems.',
            'status' => 'active',
            'access_type' => 'public',
            'allowed_affiliate_statuses' => ['activo', 'retirado'],
            'requires_signature' => false,
            'allows_multiple_responses' => true,
            'start_date' => now(),
            'end_date' => now()->addDays(45),
        ]);

        $survey7->questions()->insert([
            [
                'survey_id' => $survey7->id,
                'type' => 'ranking',
                'label' => 'Indique la importancia de cada tema en la próxima negociación (puede empatar)',
                'help_text' => 'Puede asignar el mismo número a varios temas si los considera igual de importantes.',
                'is_required' => true,
                'order' => 1,
                'options' => json_encode([
                    ['value' => 'salario', 'label' => 'Incremento salarial'],
                    ['value' => 'jornada', 'label' => 'Reducción de jornada'],
                    ['value' => 'prima', 'label' => 'Prima extralegal'],
                    ['value' => 'vacaciones', 'label' => 'Días adicionales de vacaciones'],
                    ['value' => 'pension', 'label' => 'Beneficios pensionales'],
                    ['value' => 'salud', 'label' => 'Plan de salud complementario'],
                ]),
                'ranking_unique_priority' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey7->id,
                'type' => 'single_choice',
                'label' => '¿Cuál es su principal motivación para participar en la negociación?',
                'help_text' => null,
                'is_required' => true,
                'order' => 2,
                'options' => json_encode([
                    ['value' => 'economico', 'label' => 'Mejorar mis ingresos'],
                    ['value' => 'condiciones', 'label' => 'Mejorar condiciones de trabajo'],
                    ['value' => 'colectivo', 'label' => 'Beneficio colectivo'],
                    ['value' => 'otro', 'label' => 'Otro'],
                ]),
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'survey_id' => $survey7->id,
                'type' => 'textarea',
                'label' => '¿Hay algún tema que no está en la lista y que considera prioritario?',
                'help_text' => null,
                'is_required' => false,
                'order' => 3,
                'options' => null,
                'ranking_unique_priority' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->command->info('SurveyTestSeeder: 7 encuestas de prueba creadas correctamente.');
    }
}
