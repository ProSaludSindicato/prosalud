<?php

namespace App\Http\Requests;

use App\Constants\SurveyOptions;
use App\Rules\RecaptchaRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\{Log, App as LaravelApp};
use Illuminate\Validation\Rule;

class StoreSocioDemographicSurveyRequest extends FormRequest
{
    /**
     * Stop validation on first failure.
     */
    protected $stopOnFirstFailure = true;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Configurar el locale a español para que los mensajes por defecto de Laravel estén en español
        LaravelApp::setLocale('es');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            // Datos Básicos
            'correo' => 'required|email|max:255',
            'tipoDocumento' => ['required', 'string', Rule::in(SurveyOptions::TIPOS_DOCUMENTO)],
            'numeroDocumento' => 'required|string|max:255',
            'hospital' => 'required|string|max:255',
            'profesion' => 'required|string|max:255',
            'rh' => ['nullable', 'string', Rule::in(SurveyOptions::TIPOS_RH)],
            'fechaExpedicion' => 'nullable|date|date_format:Y-m-d',
            'lugarNacimiento' => 'nullable|string|max:255',
            'departamento' => ['nullable', 'string', Rule::in(SurveyOptions::DEPARTAMENTOS)],
            'celular' => 'nullable|string|max:20',
            'direccion' => 'nullable|string|max:500',
            'municipio' => ['nullable', 'string', Rule::in(SurveyOptions::MUNICIPIOS_ANTIOQUIA)],
            'tallaCalzado' => 'nullable|string|max:10',
            'tallaVestimenta' => ['nullable', 'string', Rule::in(SurveyOptions::TALLAS_VESTIMENTA)],
            'paisNacimiento' => ['nullable', 'string', Rule::in(SurveyOptions::PAISES)],

            // Contacto de Emergencia
            'nombreContactoEmergencia' => 'nullable|string|max:255',
            'relacionContactoEmergencia' => ['nullable', 'string', Rule::in(SurveyOptions::RELACIONES_CONTACTO_EMERGENCIA)],
            'telefonoContactoEmergencia' => 'nullable|string|max:20',

            // Información Sociodemográfica
            'tienePersonasACargo' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'estadoCivil' => ['required', 'string', Rule::in(SurveyOptions::ESTADOS_CIVILES)],
            'fechaNacimiento' => 'required|date|date_format:Y-m-d|before:today',
            'estatura' => 'required|numeric|min:50|max:250',
            'peso' => 'required|numeric|min:20|max:300',
            'genero' => ['required', 'string', Rule::in(SurveyOptions::GENEROS)],
            'raza' => ['required', 'string', Rule::in(SurveyOptions::RAZAS)],
            'numeroHijos' => 'nullable|string|max:10',
            'hijos' => 'nullable|array',
            'hijos.*.tipoDocumento' => ['required_with:hijos', 'string', Rule::in(['CC', 'TI', 'RC'])],
            'hijos.*.numeroDocumento' => 'required_with:hijos|string|max:255',
            'hijos.*.nombre' => 'required_with:hijos|string|max:255',
            'hijos.*.genero' => ['required_with:hijos', 'string', Rule::in(SurveyOptions::GENEROS)],
            'hijos.*.fechaNacimiento' => 'required_with:hijos|date|date_format:Y-m-d|before:today',
            'numeroPersonasDependientes' => 'nullable|string|max:10',
            'vivienda' => ['required', 'string', Rule::in(SurveyOptions::TIPOS_VIVIENDA)],
            'serviciosPublicos' => 'required|array',
            'serviciosPublicos.agua' => 'required|boolean',
            'serviciosPublicos.luz' => 'required|boolean',
            'serviciosPublicos.telefono' => 'required|boolean',
            'serviciosPublicos.internet' => 'required|boolean',
            'serviciosPublicos.gas' => 'required|boolean',
            'estratoSocioeconomico' => ['required', 'string', Rule::in(SurveyOptions::ESTRATOS_SOCIOECONOMICOS)],
            'conviveCon' => ['required', 'string', Rule::in(SurveyOptions::CONVIVE_CON)],
            'transporte' => ['required', 'string', Rule::in(SurveyOptions::TIPOS_TRANSPORTE)],
            'manejoTiempoLibre' => 'required|array',
            'manejoTiempoLibre.recreativas' => 'required|boolean',
            'manejoTiempoLibre.deportivas' => 'required|boolean',
            'manejoTiempoLibre.educativas' => 'required|boolean',
            'manejoTiempoLibre.descanso' => 'required|boolean',
            'manejoTiempoLibre.artisticas' => 'required|boolean',
            'manejoTiempoLibre.religiosas' => 'required|boolean',
            'manejoTiempoLibre.otras' => 'required|boolean',
            'tiempoLibreCon' => ['required', 'string', Rule::in(SurveyOptions::TIEMPO_LIBRE_CON)],

            // Consumo
            'consumoLicor' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'frecuenciaLicor' => ['required_if:consumoLicor,si', 'nullable', 'string', Rule::in(SurveyOptions::FRECUENCIAS_CONSUMO)],
            'consumoCigarrillo' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'frecuenciaCigarrillo' => ['required_if:consumoCigarrillo,si', 'nullable', 'string', Rule::in(SurveyOptions::FRECUENCIAS_CONSUMO)],

            // Condiciones de Salud (todas son si/no)
            'sobrepesoObesidad' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'hipertensionArterial' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'enfermedadesCorazon' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'diabetes' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'problemasRenales' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'depresionBipolaridad' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'antecedentesMedicosMentales' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'epilepsiaConvulsiones' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'trasplante' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'tipoTrasplante' => 'required_if:trasplante,si|nullable|string|max:255',
            'cancer' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'problemasPulmonares' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'tipoProblemaPulmonar' => 'required_if:problemasPulmonares,si|nullable|string|max:255',
            'alergias' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'tipoAlergia' => 'required_if:alergias,si|nullable|string|max:255',
            'tuberculosis' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'problemasVisuales' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'tipoProblemaVisual' => 'required_if:problemasVisuales,si|nullable|string|max:255',
            'doloresArticulares' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'tipoDolorArticular' => 'required_if:doloresArticulares,si|nullable|string|max:255',
            'problemasSangre' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'otraEnfermedad' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'tipoOtraEnfermedad' => 'required_if:otraEnfermedad,si|nullable|string|max:255',
            'protesisArticular' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'medicamentoPermanente' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'tipoMedicamento' => 'required_if:medicamentoPermanente,si|nullable|string|max:500',
            'tratamientoMedico' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'cirugias' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'tipoCirugia' => 'required_if:cirugias,si|nullable|string|max:255',
            'tiempoCirugia' => 'required_if:cirugias,si|nullable|string|max:255',
            'accidenteLaboral' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'tipoAccidenteLaboral' => 'required_if:accidenteLaboral,si|nullable|string|max:255',
            'tiempoAccidenteLaboral' => 'required_if:accidenteLaboral,si|nullable|string|max:255',
            'accidenteTransitoCasero' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'tipoAccidenteTransito' => 'required_if:accidenteTransitoCasero,si|nullable|string|max:255',
            'tiempoAccidenteTransito' => 'required_if:accidenteTransitoCasero,si|nullable|string|max:255',
            'vacunadoCovid' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],

            // Limitaciones Físicas
            'esfuerzosIntensos' => ['required', 'string', Rule::in(SurveyOptions::NIVELES_LIMITACION)],
            'esfuerzosModerados' => ['required', 'string', Rule::in(SurveyOptions::NIVELES_LIMITACION)],
            'subirPisos' => ['required', 'string', Rule::in(SurveyOptions::NIVELES_LIMITACION)],
            'agacharseArrodillarse' => ['required', 'string', Rule::in(SurveyOptions::NIVELES_LIMITACION)],

            // Recomendaciones Laborales
            'recomendacionRestriccionLaboral' => ['required', 'string', Rule::in(SurveyOptions::SI_NO)],
            'detalleRecomendacionLaboral' => 'required_if:recomendacionRestriccionLaboral,si|nullable|string|max:1000',

            // Firma Digital
            'firma' => 'nullable|string', // Base64 string
            'numeroDocumentoFirma' => 'required|string|max:255',
            'files.firma' => 'nullable|file|mimes:png|max:2048', // PNG file

            // reCAPTCHA
            'recaptcha_token' => ['required', new RecaptchaRule()],
        ];

        return $rules;
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Validar que la firma se proporcione de alguna forma (base64 o archivo)
            $firmaBase64 = $this->input('firma');
            $firmaFile = $this->file('files.firma') ?? $this->file('files')['firma'] ?? null;

            if (empty($firmaBase64) && empty($firmaFile)) {
                $validator->errors()->add(
                    'firma',
                    'Debe proporcionar la firma digital como base64 o como archivo PNG.'
                );
            }

            // Validar que numeroDocumentoFirma coincida con numeroDocumento
            $numeroDocumento = $this->input('numeroDocumento');
            $numeroDocumentoFirma = $this->input('numeroDocumentoFirma');

            if ($numeroDocumento && $numeroDocumentoFirma && $numeroDocumento !== $numeroDocumentoFirma) {
                $validator->errors()->add(
                    'numeroDocumentoFirma',
                    'El número de documento de la firma debe coincidir con el número de documento proporcionado.'
                );
            }

            // Validar fecha de nacimiento (debe ser una fecha válida y en el pasado)
            $fechaNacimiento = $this->input('fechaNacimiento');
            if ($fechaNacimiento) {
                $fecha = \Carbon\Carbon::createFromFormat('Y-m-d', $fechaNacimiento);
                $edad = $fecha->age;

                if ($edad < 0 || $edad > 120) {
                    $validator->errors()->add(
                        'fechaNacimiento',
                        'La fecha de nacimiento no es válida.'
                    );
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            // Datos Básicos
            'correo.required' => 'El correo electrónico es obligatorio.',
            'correo.email' => 'El correo electrónico debe ser válido.',
            'correo.max' => 'El correo electrónico no puede exceder 255 caracteres.',
            'tipoDocumento.required' => 'El tipo de documento es obligatorio.',
            'tipoDocumento.string' => 'El tipo de documento debe ser texto.',
            'tipoDocumento.in' => 'El tipo de documento seleccionado no es válido. Valores permitidos: CC, TI, CE, PA, RC, PT.',
            'numeroDocumento.required' => 'El número de documento es obligatorio.',
            'numeroDocumento.string' => 'El número de documento debe ser texto.',
            'numeroDocumento.max' => 'El número de documento no puede exceder 255 caracteres.',
            'hospital.required' => 'El hospital es obligatorio.',
            'hospital.string' => 'El hospital debe ser texto.',
            'hospital.max' => 'El hospital no puede exceder 255 caracteres.',
            'profesion.required' => 'La profesión es obligatoria.',
            'profesion.string' => 'La profesión debe ser texto.',
            'profesion.max' => 'La profesión no puede exceder 255 caracteres.',
            'rh.string' => 'El factor RH debe ser texto.',
            'rh.in' => 'El factor RH seleccionado no es válido. Valores permitidos: A+, A-, B+, B-, AB+, AB-, O+, O-.',
            'fechaExpedicion.date' => 'La fecha de expedición debe ser una fecha válida.',
            'fechaExpedicion.date_format' => 'La fecha de expedición debe tener el formato YYYY-MM-DD.',
            'lugarNacimiento.string' => 'El lugar de nacimiento debe ser texto.',
            'lugarNacimiento.max' => 'El lugar de nacimiento no puede exceder 255 caracteres.',
            'departamento.string' => 'El departamento debe ser texto.',
            'departamento.in' => 'El departamento seleccionado no es válido.',
            'celular.string' => 'El celular debe ser texto.',
            'celular.max' => 'El celular no puede exceder 20 caracteres.',
            'direccion.string' => 'La dirección debe ser texto.',
            'direccion.max' => 'La dirección no puede exceder 500 caracteres.',
            'municipio.string' => 'El municipio debe ser texto.',
            'municipio.in' => 'El municipio seleccionado no es válido.',
            'tallaCalzado.string' => 'La talla de calzado debe ser texto.',
            'tallaCalzado.max' => 'La talla de calzado no puede exceder 10 caracteres.',
            'tallaVestimenta.string' => 'La talla de vestimenta debe ser texto.',
            'tallaVestimenta.in' => 'La talla de vestimenta seleccionada no es válida. Valores permitidos: xs, s, m, l, xl, xxl, xxxl, 4xl, 5xl.',
            'paisNacimiento.string' => 'El país de nacimiento debe ser texto.',
            'paisNacimiento.in' => 'El país de nacimiento seleccionado no es válido.',

            // Contacto de Emergencia
            'nombreContactoEmergencia.string' => 'El nombre del contacto de emergencia debe ser texto.',
            'nombreContactoEmergencia.max' => 'El nombre del contacto de emergencia no puede exceder 255 caracteres.',
            'relacionContactoEmergencia.string' => 'La relación del contacto de emergencia debe ser texto.',
            'relacionContactoEmergencia.in' => 'La relación del contacto de emergencia seleccionada no es válida.',
            'telefonoContactoEmergencia.string' => 'El teléfono del contacto de emergencia debe ser texto.',
            'telefonoContactoEmergencia.max' => 'El teléfono del contacto de emergencia no puede exceder 20 caracteres.',

            // Información Sociodemográfica
            'tienePersonasACargo.required' => 'Debe indicar si tiene personas a cargo.',
            'tienePersonasACargo.string' => 'El campo tiene personas a cargo debe ser texto.',
            'tienePersonasACargo.in' => 'El valor debe ser "si" o "no".',
            'estadoCivil.required' => 'El estado civil es obligatorio.',
            'estadoCivil.string' => 'El estado civil debe ser texto.',
            'estadoCivil.in' => 'El estado civil seleccionado no es válido. Valores permitidos: soltero, casado, divorciado, viudo, union_libre.',
            'fechaNacimiento.required' => 'La fecha de nacimiento es obligatoria.',
            'fechaNacimiento.date' => 'La fecha de nacimiento debe ser una fecha válida.',
            'fechaNacimiento.date_format' => 'La fecha de nacimiento debe tener el formato YYYY-MM-DD.',
            'fechaNacimiento.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
            'estatura.required' => 'La estatura es obligatoria.',
            'estatura.numeric' => 'La estatura debe ser un número.',
            'estatura.min' => 'La estatura debe ser al menos 50 cm.',
            'estatura.max' => 'La estatura no puede exceder 250 cm.',
            'peso.required' => 'El peso es obligatorio.',
            'peso.numeric' => 'El peso debe ser un número.',
            'peso.min' => 'El peso debe ser al menos 20 kg.',
            'peso.max' => 'El peso no puede exceder 300 kg.',
            'genero.required' => 'El género es obligatorio.',
            'genero.string' => 'El género debe ser texto.',
            'genero.in' => 'El género seleccionado no es válido. Valores permitidos: masculino, femenino, otro.',
            'raza.required' => 'La raza es obligatoria.',
            'raza.string' => 'La raza debe ser texto.',
            'raza.in' => 'La raza seleccionada no es válida. Valores permitidos: ninguno, afro, indigena, otro, no_responde.',
            'numeroHijos.string' => 'El número de hijos debe ser texto.',
            'numeroHijos.max' => 'El número de hijos no puede exceder 10 caracteres.',
            'hijos.array' => 'Los hijos deben ser un array.',
            'hijos.*.tipoDocumento.required_with' => 'El tipo de documento del hijo es obligatorio.',
            'hijos.*.tipoDocumento.string' => 'El tipo de documento del hijo debe ser texto.',
            'hijos.*.tipoDocumento.in' => 'El tipo de documento del hijo no es válido. Valores permitidos: CC, TI, RC.',
            'hijos.*.numeroDocumento.required_with' => 'El número de documento del hijo es obligatorio.',
            'hijos.*.numeroDocumento.string' => 'El número de documento del hijo debe ser texto.',
            'hijos.*.numeroDocumento.max' => 'El número de documento del hijo no puede exceder 255 caracteres.',
            'hijos.*.nombre.required_with' => 'El nombre del hijo es obligatorio.',
            'hijos.*.nombre.string' => 'El nombre del hijo debe ser texto.',
            'hijos.*.nombre.max' => 'El nombre del hijo no puede exceder 255 caracteres.',
            'hijos.*.genero.required_with' => 'El género del hijo es obligatorio.',
            'hijos.*.genero.string' => 'El género del hijo debe ser texto.',
            'hijos.*.genero.in' => 'El género del hijo no es válido. Valores permitidos: masculino, femenino.',
            'hijos.*.fechaNacimiento.required_with' => 'La fecha de nacimiento del hijo es obligatoria.',
            'hijos.*.fechaNacimiento.date' => 'La fecha de nacimiento del hijo debe ser una fecha válida.',
            'hijos.*.fechaNacimiento.date_format' => 'La fecha de nacimiento del hijo debe tener el formato YYYY-MM-DD.',
            'hijos.*.fechaNacimiento.before' => 'La fecha de nacimiento del hijo debe ser anterior a hoy.',
            'numeroPersonasDependientes.string' => 'El número de personas dependientes debe ser texto.',
            'numeroPersonasDependientes.max' => 'El número de personas dependientes no puede exceder 10 caracteres.',
            'vivienda.required' => 'El tipo de vivienda es obligatorio.',
            'vivienda.string' => 'El tipo de vivienda debe ser texto.',
            'vivienda.in' => 'El tipo de vivienda seleccionado no es válido. Valores permitidos: propia, arrendada, familiar.',
            'serviciosPublicos.required' => 'Debe indicar los servicios públicos.',
            'serviciosPublicos.array' => 'Los servicios públicos deben ser un objeto/array.',
            'serviciosPublicos.agua.required' => 'Debe indicar si tiene servicio de agua.',
            'serviciosPublicos.agua.boolean' => 'El servicio de agua debe ser verdadero o falso.',
            'serviciosPublicos.luz.required' => 'Debe indicar si tiene servicio de luz.',
            'serviciosPublicos.luz.boolean' => 'El servicio de luz debe ser verdadero o falso.',
            'serviciosPublicos.telefono.required' => 'Debe indicar si tiene servicio de teléfono.',
            'serviciosPublicos.telefono.boolean' => 'El servicio de teléfono debe ser verdadero o falso.',
            'serviciosPublicos.internet.required' => 'Debe indicar si tiene servicio de internet.',
            'serviciosPublicos.internet.boolean' => 'El servicio de internet debe ser verdadero o falso.',
            'serviciosPublicos.gas.required' => 'Debe indicar si tiene servicio de gas.',
            'serviciosPublicos.gas.boolean' => 'El servicio de gas debe ser verdadero o falso.',
            'estratoSocioeconomico.required' => 'El estrato socioeconómico es obligatorio.',
            'estratoSocioeconomico.string' => 'El estrato socioeconómico debe ser texto.',
            'estratoSocioeconomico.in' => 'El estrato socioeconómico seleccionado no es válido. Valores permitidos: 1, 2, 3, 4, 5, 6.',
            'conviveCon.required' => 'Debe indicar con quién convive.',
            'conviveCon.string' => 'El campo convive con debe ser texto.',
            'conviveCon.in' => 'La opción seleccionada no es válida. Valores permitidos: familia_origen, nueva_familia, ambas, amigos, otros_familiares, solo.',
            'transporte.required' => 'Debe indicar el medio de transporte.',
            'transporte.string' => 'El medio de transporte debe ser texto.',
            'transporte.in' => 'El medio de transporte seleccionado no es válido. Valores permitidos: carro, motocicleta, bicicleta, transporte_publico, caminando, otra.',
            'manejoTiempoLibre.required' => 'Debe indicar el manejo del tiempo libre.',
            'manejoTiempoLibre.array' => 'El manejo del tiempo libre debe ser un objeto/array.',
            'manejoTiempoLibre.recreativas.required' => 'Debe indicar si realiza actividades recreativas.',
            'manejoTiempoLibre.recreativas.boolean' => 'El campo actividades recreativas debe ser verdadero o falso.',
            'manejoTiempoLibre.deportivas.required' => 'Debe indicar si realiza actividades deportivas.',
            'manejoTiempoLibre.deportivas.boolean' => 'El campo actividades deportivas debe ser verdadero o falso.',
            'manejoTiempoLibre.educativas.required' => 'Debe indicar si realiza actividades educativas.',
            'manejoTiempoLibre.educativas.boolean' => 'El campo actividades educativas debe ser verdadero o falso.',
            'manejoTiempoLibre.descanso.required' => 'Debe indicar si realiza actividades de descanso.',
            'manejoTiempoLibre.descanso.boolean' => 'El campo actividades de descanso debe ser verdadero o falso.',
            'manejoTiempoLibre.artisticas.required' => 'Debe indicar si realiza actividades artísticas.',
            'manejoTiempoLibre.artisticas.boolean' => 'El campo actividades artísticas debe ser verdadero o falso.',
            'manejoTiempoLibre.religiosas.required' => 'Debe indicar si realiza actividades religiosas.',
            'manejoTiempoLibre.religiosas.boolean' => 'El campo actividades religiosas debe ser verdadero o falso.',
            'manejoTiempoLibre.otras.required' => 'Debe indicar si realiza otras actividades.',
            'manejoTiempoLibre.otras.boolean' => 'El campo otras actividades debe ser verdadero o falso.',
            'tiempoLibreCon.required' => 'Debe indicar con quién pasa el tiempo libre.',
            'tiempoLibreCon.string' => 'El campo tiempo libre con debe ser texto.',
            'tiempoLibreCon.in' => 'La opción seleccionada no es válida. Valores permitidos: familia, pareja, amigos, solo, otros.',

            // Consumo
            'consumoLicor.required' => 'Debe indicar si consume licor.',
            'consumoLicor.string' => 'El campo consumo de licor debe ser texto.',
            'consumoLicor.in' => 'El valor debe ser "si" o "no".',
            'frecuenciaLicor.required_if' => 'Debe indicar la frecuencia de consumo de licor.',
            'frecuenciaLicor.string' => 'La frecuencia de consumo de licor debe ser texto.',
            'frecuenciaLicor.in' => 'La frecuencia de consumo de licor no es válida. Valores permitidos: diario, varias_veces_semana, fines_semana, cada_quince_dias, ocasionalmente.',
            'consumoCigarrillo.required' => 'Debe indicar si consume cigarrillo.',
            'consumoCigarrillo.string' => 'El campo consumo de cigarrillo debe ser texto.',
            'consumoCigarrillo.in' => 'El valor debe ser "si" o "no".',
            'frecuenciaCigarrillo.required_if' => 'Debe indicar la frecuencia de consumo de cigarrillo.',
            'frecuenciaCigarrillo.string' => 'La frecuencia de consumo de cigarrillo debe ser texto.',
            'frecuenciaCigarrillo.in' => 'La frecuencia de consumo de cigarrillo no es válida. Valores permitidos: diario, varias_veces_semana, fines_semana, cada_quince_dias, ocasionalmente.',

            // Condiciones de Salud
            'sobrepesoObesidad.required' => 'Debe indicar si tiene sobrepeso u obesidad.',
            'sobrepesoObesidad.string' => 'El campo debe ser texto.',
            'sobrepesoObesidad.in' => 'El valor debe ser "si" o "no".',
            'hipertensionArterial.required' => 'Debe indicar si tiene hipertensión arterial.',
            'hipertensionArterial.in' => 'El valor debe ser "si" o "no".',
            'enfermedadesCorazon.required' => 'Debe indicar si tiene enfermedades del corazón.',
            'enfermedadesCorazon.in' => 'El valor debe ser "si" o "no".',
            'diabetes.required' => 'Debe indicar si tiene diabetes.',
            'diabetes.in' => 'El valor debe ser "si" o "no".',
            'problemasRenales.required' => 'Debe indicar si tiene problemas renales.',
            'problemasRenales.in' => 'El valor debe ser "si" o "no".',
            'depresionBipolaridad.required' => 'Debe indicar si tiene depresión o bipolaridad.',
            'depresionBipolaridad.in' => 'El valor debe ser "si" o "no".',
            'antecedentesMedicosMentales.required' => 'Debe indicar si tiene antecedentes médicos mentales.',
            'antecedentesMedicosMentales.in' => 'El valor debe ser "si" o "no".',
            'epilepsiaConvulsiones.required' => 'Debe indicar si tiene epilepsia o convulsiones.',
            'epilepsiaConvulsiones.in' => 'El valor debe ser "si" o "no".',
            'trasplante.required' => 'Debe indicar si ha tenido un trasplante.',
            'trasplante.in' => 'El valor debe ser "si" o "no".',
            'tipoTrasplante.required_if' => 'Debe especificar el tipo de trasplante.',
            'tipoTrasplante.string' => 'El tipo de trasplante debe ser texto.',
            'tipoTrasplante.max' => 'El tipo de trasplante no puede exceder 255 caracteres.',
            'cancer.required' => 'Debe indicar si tiene o ha tenido cáncer.',
            'cancer.in' => 'El valor debe ser "si" o "no".',
            'problemasPulmonares.required' => 'Debe indicar si tiene problemas pulmonares.',
            'problemasPulmonares.in' => 'El valor debe ser "si" o "no".',
            'tipoProblemaPulmonar.required_if' => 'Debe especificar el tipo de problema pulmonar.',
            'tipoProblemaPulmonar.string' => 'El tipo de problema pulmonar debe ser texto.',
            'tipoProblemaPulmonar.max' => 'El tipo de problema pulmonar no puede exceder 255 caracteres.',
            'alergias.required' => 'Debe indicar si tiene alergias.',
            'alergias.in' => 'El valor debe ser "si" o "no".',
            'tipoAlergia.required_if' => 'Debe especificar el tipo de alergia.',
            'tipoAlergia.string' => 'El tipo de alergia debe ser texto.',
            'tipoAlergia.max' => 'El tipo de alergia no puede exceder 255 caracteres.',
            'tuberculosis.required' => 'Debe indicar si tiene o ha tenido tuberculosis.',
            'tuberculosis.in' => 'El valor debe ser "si" o "no".',
            'problemasVisuales.required' => 'Debe indicar si tiene problemas visuales.',
            'problemasVisuales.in' => 'El valor debe ser "si" o "no".',
            'tipoProblemaVisual.required_if' => 'Debe especificar el tipo de problema visual.',
            'tipoProblemaVisual.string' => 'El tipo de problema visual debe ser texto.',
            'tipoProblemaVisual.max' => 'El tipo de problema visual no puede exceder 255 caracteres.',
            'doloresArticulares.required' => 'Debe indicar si tiene dolores articulares.',
            'doloresArticulares.in' => 'El valor debe ser "si" o "no".',
            'tipoDolorArticular.required_if' => 'Debe especificar el tipo de dolor articular.',
            'tipoDolorArticular.string' => 'El tipo de dolor articular debe ser texto.',
            'tipoDolorArticular.max' => 'El tipo de dolor articular no puede exceder 255 caracteres.',
            'problemasSangre.required' => 'Debe indicar si tiene problemas de sangre.',
            'problemasSangre.in' => 'El valor debe ser "si" o "no".',
            'otraEnfermedad.required' => 'Debe indicar si tiene otra enfermedad.',
            'otraEnfermedad.in' => 'El valor debe ser "si" o "no".',
            'tipoOtraEnfermedad.required_if' => 'Debe especificar el tipo de otra enfermedad.',
            'tipoOtraEnfermedad.string' => 'El tipo de otra enfermedad debe ser texto.',
            'tipoOtraEnfermedad.max' => 'El tipo de otra enfermedad no puede exceder 255 caracteres.',
            'protesisArticular.required' => 'Debe indicar si tiene prótesis articular.',
            'protesisArticular.in' => 'El valor debe ser "si" o "no".',
            'medicamentoPermanente.required' => 'Debe indicar si toma medicamentos permanentes.',
            'medicamentoPermanente.in' => 'El valor debe ser "si" o "no".',
            'tipoMedicamento.required_if' => 'Debe especificar el tipo de medicamento.',
            'tipoMedicamento.string' => 'El tipo de medicamento debe ser texto.',
            'tipoMedicamento.max' => 'El tipo de medicamento no puede exceder 500 caracteres.',
            'tratamientoMedico.required' => 'Debe indicar si está en tratamiento médico.',
            'tratamientoMedico.in' => 'El valor debe ser "si" o "no".',
            'cirugias.required' => 'Debe indicar si ha tenido cirugías.',
            'cirugias.in' => 'El valor debe ser "si" o "no".',
            'tipoCirugia.required_if' => 'Debe especificar el tipo de cirugía.',
            'tipoCirugia.string' => 'El tipo de cirugía debe ser texto.',
            'tipoCirugia.max' => 'El tipo de cirugía no puede exceder 255 caracteres.',
            'tiempoCirugia.required_if' => 'Debe especificar el tiempo de la cirugía.',
            'tiempoCirugia.string' => 'El tiempo de la cirugía debe ser texto.',
            'tiempoCirugia.max' => 'El tiempo de la cirugía no puede exceder 255 caracteres.',
            'accidenteLaboral.required' => 'Debe indicar si ha tenido accidentes laborales.',
            'accidenteLaboral.in' => 'El valor debe ser "si" o "no".',
            'tipoAccidenteLaboral.required_if' => 'Debe especificar el tipo de accidente laboral.',
            'tipoAccidenteLaboral.string' => 'El tipo de accidente laboral debe ser texto.',
            'tipoAccidenteLaboral.max' => 'El tipo de accidente laboral no puede exceder 255 caracteres.',
            'tiempoAccidenteLaboral.required_if' => 'Debe especificar el tiempo del accidente laboral.',
            'tiempoAccidenteLaboral.string' => 'El tiempo del accidente laboral debe ser texto.',
            'tiempoAccidenteLaboral.max' => 'El tiempo del accidente laboral no puede exceder 255 caracteres.',
            'accidenteTransitoCasero.required' => 'Debe indicar si ha tenido accidentes de tránsito o caseros.',
            'accidenteTransitoCasero.in' => 'El valor debe ser "si" o "no".',
            'tipoAccidenteTransito.required_if' => 'Debe especificar el tipo de accidente de tránsito o casero.',
            'tipoAccidenteTransito.string' => 'El tipo de accidente debe ser texto.',
            'tipoAccidenteTransito.max' => 'El tipo de accidente no puede exceder 255 caracteres.',
            'tiempoAccidenteTransito.required_if' => 'Debe especificar el tiempo del accidente de tránsito o casero.',
            'tiempoAccidenteTransito.string' => 'El tiempo del accidente debe ser texto.',
            'tiempoAccidenteTransito.max' => 'El tiempo del accidente no puede exceder 255 caracteres.',
            'vacunadoCovid.required' => 'Debe indicar si está vacunado contra COVID-19.',
            'vacunadoCovid.in' => 'El valor debe ser "si" o "no".',

            // Limitaciones Físicas
            'esfuerzosIntensos.required' => 'Debe indicar cómo le limitan los esfuerzos intensos.',
            'esfuerzosIntensos.string' => 'El campo debe ser texto.',
            'esfuerzosIntensos.in' => 'El valor no es válido. Valores permitidos: limita_mucho, limita_poco, no_limita.',
            'esfuerzosModerados.required' => 'Debe indicar cómo le limitan los esfuerzos moderados.',
            'esfuerzosModerados.string' => 'El campo debe ser texto.',
            'esfuerzosModerados.in' => 'El valor no es válido. Valores permitidos: limita_mucho, limita_poco, no_limita.',
            'subirPisos.required' => 'Debe indicar cómo le limita subir pisos.',
            'subirPisos.string' => 'El campo debe ser texto.',
            'subirPisos.in' => 'El valor no es válido. Valores permitidos: limita_mucho, limita_poco, no_limita.',
            'agacharseArrodillarse.required' => 'Debe indicar cómo le limita agacharse o arrodillarse.',
            'agacharseArrodillarse.string' => 'El campo debe ser texto.',
            'agacharseArrodillarse.in' => 'El valor no es válido. Valores permitidos: limita_mucho, limita_poco, no_limita.',

            // Recomendaciones Laborales
            'recomendacionRestriccionLaboral.required' => 'Debe indicar si hay recomendación de restricción laboral.',
            'recomendacionRestriccionLaboral.string' => 'El campo debe ser texto.',
            'recomendacionRestriccionLaboral.in' => 'El valor debe ser "si" o "no".',
            'detalleRecomendacionLaboral.required_if' => 'Debe especificar el detalle de la recomendación laboral.',
            'detalleRecomendacionLaboral.string' => 'El detalle de la recomendación laboral debe ser texto.',
            'detalleRecomendacionLaboral.max' => 'El detalle de la recomendación laboral no puede exceder 1000 caracteres.',

            // Firma Digital
            'firma.string' => 'La firma digital debe ser una cadena de texto base64.',
            'numeroDocumentoFirma.required' => 'El número de documento de la firma es obligatorio.',
            'numeroDocumentoFirma.string' => 'El número de documento de la firma debe ser texto.',
            'numeroDocumentoFirma.max' => 'El número de documento de la firma no puede exceder 255 caracteres.',
            'files.firma.file' => 'El archivo de firma debe ser un archivo válido.',
            'files.firma.mimes' => 'El archivo de firma debe ser una imagen PNG.',
            'files.firma.max' => 'El archivo de firma no puede exceder 2MB.',

            // reCAPTCHA
            'recaptcha_token.required' => 'El token de reCAPTCHA es obligatorio.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            // Datos Básicos
            'correo' => 'correo electrónico',
            'tipoDocumento' => 'tipo de documento',
            'numeroDocumento' => 'número de documento',
            'hospital' => 'hospital',
            'profesion' => 'profesión',
            'rh' => 'factor RH',
            'fechaExpedicion' => 'fecha de expedición',
            'lugarNacimiento' => 'lugar de nacimiento',
            'departamento' => 'departamento',
            'celular' => 'celular',
            'direccion' => 'dirección',
            'municipio' => 'municipio',
            'tallaCalzado' => 'talla de calzado',
            'tallaVestimenta' => 'talla de vestimenta',
            'paisNacimiento' => 'país de nacimiento',
            
            // Contacto de Emergencia
            'nombreContactoEmergencia' => 'nombre contacto de emergencia',
            'relacionContactoEmergencia' => 'relación contacto de emergencia',
            'telefonoContactoEmergencia' => 'teléfono contacto de emergencia',
            
            // Información Sociodemográfica
            'tienePersonasACargo' => 'tiene personas a cargo',
            'estadoCivil' => 'estado civil',
            'fechaNacimiento' => 'fecha de nacimiento',
            'estatura' => 'estatura',
            'peso' => 'peso',
            'genero' => 'género',
            'raza' => 'raza',
            'numeroHijos' => 'número de hijos',
            'hijos' => 'hijos',
            'hijos.*.tipoDocumento' => 'tipo de documento del hijo',
            'hijos.*.numeroDocumento' => 'número de documento del hijo',
            'hijos.*.nombre' => 'nombre del hijo',
            'hijos.*.genero' => 'género del hijo',
            'hijos.*.fechaNacimiento' => 'fecha de nacimiento del hijo',
            'numeroPersonasDependientes' => 'número de personas dependientes',
            'vivienda' => 'tipo de vivienda',
            'serviciosPublicos' => 'servicios públicos',
            'serviciosPublicos.agua' => 'servicio de agua',
            'serviciosPublicos.luz' => 'servicio de luz',
            'serviciosPublicos.telefono' => 'servicio de teléfono',
            'serviciosPublicos.internet' => 'servicio de internet',
            'serviciosPublicos.gas' => 'servicio de gas',
            'estratoSocioeconomico' => 'estrato socioeconómico',
            'conviveCon' => 'convive con',
            'transporte' => 'medio de transporte',
            'manejoTiempoLibre' => 'manejo del tiempo libre',
            'manejoTiempoLibre.recreativas' => 'actividades recreativas',
            'manejoTiempoLibre.deportivas' => 'actividades deportivas',
            'manejoTiempoLibre.educativas' => 'actividades educativas',
            'manejoTiempoLibre.descanso' => 'actividades de descanso',
            'manejoTiempoLibre.artisticas' => 'actividades artísticas',
            'manejoTiempoLibre.religiosas' => 'actividades religiosas',
            'manejoTiempoLibre.otras' => 'otras actividades',
            'tiempoLibreCon' => 'tiempo libre con',
            
            // Consumo
            'consumoLicor' => 'consumo de licor',
            'frecuenciaLicor' => 'frecuencia de consumo de licor',
            'consumoCigarrillo' => 'consumo de cigarrillo',
            'frecuenciaCigarrillo' => 'frecuencia de consumo de cigarrillo',
            
            // Condiciones de Salud
            'sobrepesoObesidad' => 'sobrepeso u obesidad',
            'hipertensionArterial' => 'hipertensión arterial',
            'enfermedadesCorazon' => 'enfermedades del corazón',
            'diabetes' => 'diabetes',
            'problemasRenales' => 'problemas renales',
            'depresionBipolaridad' => 'depresión o bipolaridad',
            'antecedentesMedicosMentales' => 'antecedentes médicos mentales',
            'epilepsiaConvulsiones' => 'epilepsia o convulsiones',
            'trasplante' => 'trasplante',
            'tipoTrasplante' => 'tipo de trasplante',
            'cancer' => 'cáncer',
            'problemasPulmonares' => 'problemas pulmonares',
            'tipoProblemaPulmonar' => 'tipo de problema pulmonar',
            'alergias' => 'alergias',
            'tipoAlergia' => 'tipo de alergia',
            'tuberculosis' => 'tuberculosis',
            'problemasVisuales' => 'problemas visuales',
            'tipoProblemaVisual' => 'tipo de problema visual',
            'doloresArticulares' => 'dolores articulares',
            'tipoDolorArticular' => 'tipo de dolor articular',
            'problemasSangre' => 'problemas de sangre',
            'otraEnfermedad' => 'otra enfermedad',
            'tipoOtraEnfermedad' => 'tipo de otra enfermedad',
            'protesisArticular' => 'prótesis articular',
            'medicamentoPermanente' => 'medicamento permanente',
            'tipoMedicamento' => 'tipo de medicamento',
            'tratamientoMedico' => 'tratamiento médico',
            'cirugias' => 'cirugías',
            'tipoCirugia' => 'tipo de cirugía',
            'tiempoCirugia' => 'tiempo de la cirugía',
            'accidenteLaboral' => 'accidente laboral',
            'tipoAccidenteLaboral' => 'tipo de accidente laboral',
            'tiempoAccidenteLaboral' => 'tiempo del accidente laboral',
            'accidenteTransitoCasero' => 'accidente de tránsito o casero',
            'tipoAccidenteTransito' => 'tipo de accidente',
            'tiempoAccidenteTransito' => 'tiempo del accidente',
            'vacunadoCovid' => 'vacunado contra COVID-19',
            
            // Limitaciones Físicas
            'esfuerzosIntensos' => 'esfuerzos intensos',
            'esfuerzosModerados' => 'esfuerzos moderados',
            'subirPisos' => 'subir pisos',
            'agacharseArrodillarse' => 'agacharse o arrodillarse',
            
            // Recomendaciones Laborales
            'recomendacionRestriccionLaboral' => 'recomendación de restricción laboral',
            'detalleRecomendacionLaboral' => 'detalle de la recomendación laboral',
            
            // Firma Digital
            'firma' => 'firma digital',
            'files.firma' => 'archivo de firma',
            'numeroDocumentoFirma' => 'número de documento de la firma',
            
            // reCAPTCHA
            'recaptcha_token' => 'token de reCAPTCHA',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        Log::warning('Validación fallida en encuesta sociodemográfica', [
            'errors' => $validator->errors()->toArray(),
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'timestamp' => now()->toISOString(),
            'request_method' => $this->method(),
            'request_url' => $this->fullUrl(),
            'request_data' => $this->except(['firma', 'files']), // Excluir datos sensibles del log
        ]);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
