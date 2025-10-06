<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Confirmación de solicitud - ProSalud</title>
    <style>
        /* Client-safe styles (mostly inline below). Keep minimal here */
        .footer {
            margin: 0 auto;
            padding-bottom: 25px;
            text-align: center;
            width: 570px;
        }

        .footer p {
            color: #64748b;
            font-size: 12px;
            text-align: left;
            margin: 0;
        }

        @media (max-width: 600px) {
            .container {
                width: 100% !important;
                padding: 16px !important;
            }

            .content {
                padding: 16px !important;
            }

            .stack {
                display: block !important;
                width: 100% !important;
            }

            .footer {
                width: 100% !important;
            }
        }
    </style>
</head>

<body style="margin:0; padding:0; background:#F8F9FA; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Helvetica Neue', Arial, sans-serif; color:#333333;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F8F9FA; padding:24px 0;">
        <tr>
            <td align="center">
                <table class="container" role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:600px; max-width: 100%; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow: 0 6px 18px rgba(0,0,0,0.06); table-layout: fixed;">
                    <tr>
                        <td style="background:#00529B; padding:20px 24px;">
                            <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="vertical-align:middle;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td class="stack" style="vertical-align:middle; width:96px;">
                                                    <img src="https://prosalud-spa.lovable.app/images/logo_prosalud_fondo.png" alt="ProSalud" width="80" height="80" style="display:block; border:0; border-radius:8px; max-width:80px; height:auto;" />
                                                </td>
                                                <td class="stack" style="vertical-align:middle; padding-left:12px;">
                                                    <h1 style="margin:0; font-size:20px; color:#ffffff; font-weight:700;">ProSalud</h1>
                                                    <p style="margin:4px 0 0; color:#E6F0FA; font-size:13px;">Confirmación de recepción de solicitud</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="content" style="padding:24px;">
                            <p style="margin:0 0 12px; font-size:16px;">Hola <strong>{{ $requestForm->full_name }}</strong>,</p>

                            <p style="margin:0 0 12px; line-height:1.6;">
                                Hemos recibido tu solicitud correctamente y ya ha sido registrada en nuestro sistema.
                                A continuación encontrarás un resumen de la información que nos compartiste:
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:12px 0 16px; background:#ffffff; border:1px solid #DDDDDD; border-radius:8px;">
                                <tr>
                                    <td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #e5e7eb;"><strong>ID de solicitud:</strong></td>
                                    <td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #e5e7eb;">#{{ $requestForm->id }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #e5e7eb;"><strong>Tipo de solicitud:</strong></td>
                                    <td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #e5e7eb;">{{ $requestForm->request_type }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #e5e7eb;"><strong>Documento:</strong></td>
                                    <td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #e5e7eb;">{{ $requestForm->document_type }} {{ $requestForm->document_number }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #e5e7eb;"><strong>Correo electrónico:</strong></td>
                                    <td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #e5e7eb;">{{ $requestForm->email }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #e5e7eb;"><strong>Teléfono:</strong></td>
                                    <td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #e5e7eb;">{{ $requestForm->phone_number }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #e5e7eb;"><strong>Estado:</strong></td>
                                    <td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #e5e7eb;">{{ strtoupper($requestForm->status) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; font-size:14px;"><strong>Fecha de creación:</strong></td>
                                    <td style="padding:12px 16px; font-size:14px;">{{ $requestForm->formatted_created_at ?? $requestForm->created_at }}</td>
                                </tr>
                            </table>

                            @php
                            $payload = is_array($requestForm->payload ?? null) ? $requestForm->payload : [];
                            $payloadSummary = [];
                            $preferredKeys = [
                            'proceso', 'dondeRealizaProceso', 'motivoSolicitud',
                            'dirigidoAQuien', 'tipoVehiculo', 'placaVehiculo',
                            'infoCertificado', 'otrosDescripcion'
                            ];
                            foreach ($preferredKeys as $key) {
                            if (isset($payload[$key]) && $payload[$key] !== '') {
                            $payloadSummary[$key] = $payload[$key];
                            }
                            }
                            if (empty($payloadSummary)) {
                            // fallback: take first 5 entries
                            $payloadSummary = array_slice($payload, 0, 5, true);
                            }
                            @endphp

                            @if(!empty($payloadSummary))
                            <h3 style="margin:18px 0 8px; font-size:15px; color:#333333;">Detalles principales diligenciados</h3>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:8px 0 16px; background:#ffffff; border:1px solid #DDDDDD; border-radius:8px;">
                                @foreach($payloadSummary as $k => $v)
                                <tr>
                                    <td style="padding:10px 14px; font-size:13px; width:40%; border-bottom:1px solid #F0F0F0; color:#555555;"><strong>{{ ucwords(str_replace('_',' ', preg_replace('/([a-z])([A-Z])/', '$1 $2', $k))) }}:</strong></td>
                                    <td style="padding:10px 14px; font-size:13px; border-bottom:1px solid #F0F0F0; color:#333333;">{{ is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v }}</td>
                                </tr>
                                @endforeach
                            </table>
                            @endif

                            <p style="margin:0 0 12px; line-height:1.6;">
                                Nuestro equipo de Talento Humano está revisando tu solicitud y se pondrá en contacto contigo si es necesario ampliar o verificar algún dato.
                                Te notificaremos oportunamente cuando el estado de tu solicitud cambie.
                            </p>

                            <div style="margin:20px 0; padding:16px; background:#E3F2FD; border:1px solid #BBDEFB; border-radius:8px; color:#0D47A1;">
                                <strong>Importante:</strong>
                                conserva el número de tu solicitud (#{{ $requestForm->id }}) para futuras consultas o seguimientos.
                            </div>

                        </td>
                    </tr>
                    <tr>
                        <td class="footer" style="padding: 20px 24px; background: #f8f9fa; border-top: 1px solid #e9ecef; text-align: center;">
                            <p style="color: #64748b; font-size: 12px; margin: 0; line-height: 1.5;">
                                Este es un mensaje automático generado por el sistema de gestión de solicitudes de ProSalud.
                                Si tienes alguna pregunta o requieres asistencia, por favor comunícate con nuestro equipo de soporte a través de los canales oficiales.
                            </p>
                            <p style="color: #64748b; font-size: 11px; margin: 8px 0 0;">
                                © {{ date('Y') }} ProSalud. Todos los derechos reservados.
                            </p>
                        </td>
                    </tr>
                </table>