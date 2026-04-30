<?php

namespace Database\Seeders;

use App\Models\Hospital;
use App\Models\Survey;
use Illuminate\Database\Seeder;

class EncuestaRionegroSeeder extends Seeder
{
    public function run(): void
    {
        $hospital = Hospital::where('name', 'like', '%Rionegro%')->firstOrFail();

        Survey::query()
            ->where('title', 'Encuesta de Clima Laboral - Hospital Rionegro')
            ->get()
            ->each(function (Survey $s): void {
                $s->hospitals()->detach();
                $s->delete();
            });

        /** @var Survey $survey */
        $survey = Survey::query()->create([
            'title' => 'Encuesta de Clima Laboral - Hospital Rionegro',
            'description' => 'Encuesta de percepción y clima laboral dirigida a los afiliados activos del Hospital Rionegro. Sus respuestas son confidenciales y servirán para mejorar las condiciones laborales.',
            'access_type' => 'restricted',
            'status' => 'draft',
            'requires_signature' => false,
            'allows_multiple_responses' => false,
        ]);

        $survey->hospitals()->sync([$hospital->id]);

        $questions = [
            [
                'type' => 'single_choice',
                'label' => '¿Cuánto tiempo lleva en el área o servicio actual?',
                'is_required' => true,
                'order' => 1,
                'options' => [
                    ['value' => '1', 'label' => 'Más de 1 año'],
                    ['value' => '2', 'label' => 'Menos de 2 meses'],
                    ['value' => '3', 'label' => 'Entre 2 y 6 meses'],
                    ['value' => '4', 'label' => 'De 6 meses a 1 año'],
                    ['value' => '5', 'label' => 'Más de 1 año'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Ingresó al servicio por voluntad propia o correspondió a un traslado por necesidades del servicio?',
                'is_required' => true,
                'order' => 2,
                'options' => [
                    ['value' => '1', 'label' => 'Yo solicité traslado para esta área'],
                    ['value' => '2', 'label' => 'Fui designado para esta área sin contar con mi consentimiento'],
                    ['value' => '3', 'label' => 'No tengo información al respecto'],
                ],
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Está de acuerdo usted con una jornada de 12 horas?',
                'is_required' => true,
                'order' => 3,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Es consciente que una jornada de 8 horas incrementaría la cantidad de turnos a realizar al mes?',
                'is_required' => true,
                'order' => 4,
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Considera usted que los pacientes asignados en un turno son...?',
                'is_required' => true,
                'order' => 5,
                'options' => [
                    ['value' => '1', 'label' => 'Pocos'],
                    ['value' => '2', 'label' => 'Adecuados'],
                    ['value' => '3', 'label' => 'Me es indiferente la cantidad de pacientes asignados'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Considera usted que las tareas asignadas en un turno son...?',
                'is_required' => true,
                'order' => 6,
                'options' => [
                    ['value' => '1', 'label' => 'Pocas'],
                    ['value' => '2', 'label' => 'Adecuadas'],
                    ['value' => '3', 'label' => 'Indiferente'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Considera usted que el valor económico recibido actualmente (posterior al incremento realizado desde el 1 de abril) es...?',
                'is_required' => true,
                'order' => 7,
                'options' => [
                    ['value' => '1', 'label' => 'Poco'],
                    ['value' => '2', 'label' => 'Adecuado'],
                    ['value' => '3', 'label' => 'Corresponde al valor que actualmente puede ser pagado por el empleador de manera puntual'],
                    ['value' => '4', 'label' => 'Debería ser más alto independientemente de la situación financiera del sector salud'],
                ],
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Usted se siente bien remunerado?',
                'is_required' => true,
                'order' => 8,
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Usted siente que el trato recibido desde ProSalud es...?',
                'is_required' => true,
                'order' => 9,
                'options' => [
                    ['value' => '1', 'label' => 'Satisfactorio'],
                    ['value' => '2', 'label' => 'Insuficiente'],
                    ['value' => '3', 'label' => 'Malo'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => 'Cuando usted respondió la anterior pregunta, ¿lo hizo pensando en la coordinadora Luz María García Rincón o en general en ProSalud?',
                'is_required' => true,
                'order' => 10,
                'options' => [
                    ['value' => '1', 'label' => 'En Luz María García Rincón'],
                    ['value' => '2', 'label' => 'En general en ProSalud'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Usted siente que el trato recibido desde el Hospital es...?',
                'is_required' => true,
                'order' => 11,
                'options' => [
                    ['value' => '1', 'label' => 'Satisfactorio'],
                    ['value' => '2', 'label' => 'Insuficiente'],
                    ['value' => '3', 'label' => 'Malo'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => 'Cuando usted respondió la anterior pregunta, ¿lo hizo pensando en...?',
                'is_required' => true,
                'order' => 12,
                'options' => [
                    ['value' => '1', 'label' => 'El coordinador de su servicio o de su área'],
                    ['value' => '2', 'label' => 'El coordinador general'],
                    ['value' => '3', 'label' => 'El hospital en general'],
                ],
            ],
            [
                'type' => 'ranking',
                'label' => '¿Qué entiende usted por clima laboral? Ordene del 1 al 5 según la prioridad que usted considere (1 = mayor prioridad, 5 = menor prioridad)',
                'help_text' => 'Asigne a cada afirmación un número de prioridad distinto. El 1 indica la mayor importancia para usted.',
                'is_required' => true,
                'order' => 13,
                'ranking_unique_priority' => true,
                'options' => [
                    ['value' => 'A', 'label' => 'Tener los elementos de protección personal adecuados'],
                    ['value' => 'B', 'label' => 'Tener un puesto de trabajo con todos los elementos ergonómicos suficientes y en buen estado'],
                    ['value' => 'C', 'label' => 'Tener un buen equipo de trabajo'],
                    ['value' => 'D', 'label' => 'Tener un buen reconocimiento económico'],
                    ['value' => 'E', 'label' => 'Recibir un buen trato por parte de sus superiores'],
                ],
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Renunciaría usted a ProSalud?',
                'is_required' => true,
                'order' => 14,
            ],
            [
                'type' => 'single_choice',
                'label' => 'En caso de que renunciaría a ProSalud, las razones serían:',
                'help_text' => 'Aplica si respondió Sí en la pregunta anterior',
                'is_required' => false,
                'order' => 15,
                'options' => [
                    ['value' => '1', 'label' => 'Mayor oferta económica'],
                    ['value' => '2', 'label' => 'Un trabajo con menos carga laboral independiente de la oferta económica'],
                    ['value' => '3', 'label' => 'Un empleador con mejor comunicación con sus afiliados'],
                    ['value' => '4', 'label' => 'Por una oferta cercana a mi lugar de residencia'],
                    ['value' => '5', 'label' => 'Por ninguna de las anteriores'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => 'En caso de que NO renunciaría a ProSalud, la razón sería:',
                'help_text' => 'Aplica si respondió No en la pregunta anterior',
                'is_required' => false,
                'order' => 16,
                'options' => [
                    ['value' => '1', 'label' => 'Porque me siento a gusto en ProSalud'],
                    ['value' => '2', 'label' => 'Porque creo que los canales de comunicación que me brinda ProSalud son suficientes'],
                    ['value' => '3', 'label' => 'Porque siento que me cuidan'],
                    ['value' => '4', 'label' => 'Porque aunque creo que existen mejores posibilidades, hoy estoy tranquilo'],
                ],
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Renunciaría usted a realizar actividades en el Hospital?',
                'is_required' => true,
                'order' => 17,
            ],
            [
                'type' => 'single_choice',
                'label' => 'En caso de que renunciaría al Hospital, las razones serían:',
                'help_text' => 'Aplica si respondió Sí en la pregunta anterior',
                'is_required' => false,
                'order' => 18,
                'options' => [
                    ['value' => '1', 'label' => 'Mayor oferta económica'],
                    ['value' => '2', 'label' => 'Un trabajo con menos carga laboral independiente de la oferta económica'],
                    ['value' => '3', 'label' => 'Un empleador con mejor comunicación con sus afiliados'],
                    ['value' => '4', 'label' => 'Por una oferta cercana a mi lugar de residencia'],
                    ['value' => '5', 'label' => 'Por ninguna de las anteriores'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => 'En caso de que NO renunciaría al Hospital, la razón sería:',
                'help_text' => 'Aplica si respondió No en la pregunta anterior',
                'is_required' => false,
                'order' => 19,
                'options' => [
                    ['value' => '1', 'label' => 'Porque me siento a gusto en ProSalud'],
                    ['value' => '2', 'label' => 'Porque creo que los canales de comunicación que me brinda ProSalud son suficientes'],
                    ['value' => '3', 'label' => 'Porque siento que me cuidan'],
                    ['value' => '4', 'label' => 'Porque aunque creo que existen mejores posibilidades, hoy estoy tranquilo'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Usted se ha sentido maltratado?',
                'is_required' => true,
                'order' => 20,
                'options' => [
                    ['value' => '1', 'label' => 'Por parte de ProSalud'],
                    ['value' => '2', 'label' => 'Por parte de algún coordinador del Hospital'],
                    ['value' => '3', 'label' => 'Por parte de ProSalud y del Hospital'],
                    ['value' => '4', 'label' => 'No me he sentido maltratado'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Usted siente que su trabajo es valorado?',
                'is_required' => true,
                'order' => 21,
                'options' => [
                    ['value' => '1', 'label' => 'Por ProSalud'],
                    ['value' => '2', 'label' => 'Por parte del Hospital'],
                    ['value' => '3', 'label' => 'Por parte de ambos'],
                    ['value' => '4', 'label' => 'No siento que mi trabajo sea valorado'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Usted siente acompañamiento desde ProSalud en el área de Seguridad y Salud en el Trabajo (Eucaris Hernández López)?',
                'is_required' => true,
                'order' => 22,
                'options' => [
                    ['value' => '1', 'label' => 'Satisfactorio'],
                    ['value' => '2', 'label' => 'Insuficiente'],
                    ['value' => '3', 'label' => 'Malo'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Usted siente acompañamiento por parte de ProSalud desde el comité de quejas y reclamos (James Acevedo Pamplona)?',
                'is_required' => true,
                'order' => 23,
                'options' => [
                    ['value' => '1', 'label' => 'Satisfactorio'],
                    ['value' => '2', 'label' => 'Insuficiente'],
                    ['value' => '3', 'label' => 'Malo'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Usted siente acompañamiento desde la coordinación de ProSalud (Luz María García Rincón)?',
                'is_required' => true,
                'order' => 24,
                'options' => [
                    ['value' => '1', 'label' => 'Satisfactorio'],
                    ['value' => '2', 'label' => 'Insuficiente'],
                    ['value' => '3', 'label' => 'Malo'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Usted siente acompañamiento desde ProSalud en el área de Talento Humano?',
                'is_required' => true,
                'order' => 25,
                'options' => [
                    ['value' => '1', 'label' => 'Satisfactorio'],
                    ['value' => '2', 'label' => 'Insuficiente'],
                    ['value' => '3', 'label' => 'Malo'],
                ],
            ],
            [
                'type' => 'single_choice',
                'label' => '¿Usted siente acompañamiento desde ProSalud en el área de Comunicaciones?',
                'is_required' => true,
                'order' => 26,
                'options' => [
                    ['value' => '1', 'label' => 'Satisfactorio'],
                    ['value' => '2', 'label' => 'Insuficiente'],
                    ['value' => '3', 'label' => 'Malo'],
                ],
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Usted siente apoyo oportuno por parte de su líder directo?',
                'is_required' => true,
                'order' => 27,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Usted siente que su líder directo comunica claramente los objetivos y lo retroalimenta de manera adecuada?',
                'is_required' => true,
                'order' => 28,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Usted siente que la comunicación interna es clara y oportuna con su equipo?',
                'is_required' => true,
                'order' => 29,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Usted siente que ProSalud valora y escucha sus opiniones?',
                'is_required' => true,
                'order' => 30,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Usted siente que el Hospital valora y escucha sus opiniones?',
                'is_required' => true,
                'order' => 31,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Usted se siente cómodo con su equipo de trabajo?',
                'is_required' => true,
                'order' => 32,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Usted siente que ProSalud le brinda oportunidades de crecimiento personal y profesional?',
                'is_required' => true,
                'order' => 33,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Usted siente que el Hospital le brinda oportunidades de crecimiento personal y profesional?',
                'is_required' => true,
                'order' => 34,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Ha tenido dudas en el pago de su compensación?',
                'is_required' => true,
                'order' => 35,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Han solucionado las dudas por medio de la página web de ProSalud?',
                'help_text' => 'Aplica en caso de haber tenido dudas en el pago',
                'is_required' => false,
                'order' => 36,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Ha recibido respuesta oportuna por parte de ProSalud ante la solución de dudas?',
                'is_required' => true,
                'order' => 37,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Usted cree que los incentivos que reciben los funcionarios del Hospital les mejora el clima laboral a ellos?',
                'is_required' => true,
                'order' => 38,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Recomendaría a ProSalud entre sus familiares y amigos?',
                'is_required' => true,
                'order' => 39,
            ],
            [
                'type' => 'yes_no',
                'label' => '¿Recomendaría el Hospital entre sus familiares y amigos?',
                'is_required' => true,
                'order' => 40,
            ],
        ];

        foreach ($questions as $questionData) {
            $survey->questions()->create($questionData);
        }

        $this->command->info("Encuesta Rionegro creada con ID: {$survey->id} y 40 preguntas (incl. yes_no y ranking de clima laboral).");
    }
}
