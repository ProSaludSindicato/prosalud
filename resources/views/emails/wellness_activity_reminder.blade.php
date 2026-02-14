<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Recordatorio: Cargar información de actividad de bienestar - ProSalud</title>
    <style>
        @media (max-width: 600px) {
            .container {
                width: 100% !important;
                padding: 0 !important;
            }
            .content {
                padding: 24px 20px !important;
            }
            .header-content {
                display: block !important;
            }
            .logo-cell {
                display: block !important;
                margin: 0 auto 12px !important;
                text-align: center !important;
            }
            .text-cell {
                display: block !important;
                text-align: center !important;
                padding-left: 0 !important;
            }
            .info-table td {
                display: block !important;
                width: 100% !important;
                padding: 8px 16px !important;
                border-bottom: none !important;
            }
            .info-table tr {
                border-bottom: 1px solid #e5e7eb !important;
            }
        }
    </style>
</head>
<body style="margin:0; padding:0; background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding:40px 20px;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table class="container" role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width: 600px; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow: 0 10px 40px rgba(0,82,155,0.12);">

                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); padding:32px 32px 24px; border-bottom: 1px solid #e5e7eb;">
                            <table width="100%" cellspacing="0" cellpadding="0" border="0" class="header-content">
                                <tr>
                                    <td class="logo-cell" style="vertical-align:middle; width:120px;">
                                        <img src="https://www.prosalud.org.co/images/logo_prosalud_fondo.png" alt="ProSalud" width="108" height="80" style="display:block; border:0; border-radius:12px;" />
                                    </td>
                                    <td class="text-cell" style="vertical-align:middle; padding-left:16px;">
                                        <h1 style="margin:0; font-size:24px; color:#00529B; font-weight:700; letter-spacing:-0.5px;">ProSalud</h1>
                                        <p style="margin:6px 0 0; color:#64748b; font-size:14px; font-weight:500;">Recordatorio de Actividad de Bienestar</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td class="content" style="padding:32px;">
                            <p style="margin:0 0 24px; color:#1e293b; font-size:16px; line-height:24px;">
                                Estimado/a <strong>{{ $requesterName }}</strong>,
                            </p>

                            <p style="margin:0 0 24px; color:#4b5563; font-size:15px; line-height:1.7;">
                                Te escribimos para recordarte que ayer ({{ $proposedDate }}) estaba programada tu actividad de bienestar <strong>"{{ $activityName }}"</strong>. Damos por hecho que la actividad ya fue realizada y te solicitamos amablemente que cargues la información correspondiente en el sistema.
                            </p>

                            <!-- Information Table -->
                            <table class="info-table" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f8f9fa; border-radius:12px; overflow:hidden; margin-bottom:24px; border: 1px solid #e5e7eb;">
                                <tr style="background:#ffffff;">
                                    <td colspan="2" style="padding:16px; color:#1e293b; font-weight:700; font-size:16px; border-bottom: 1px solid #e5e7eb;">
                                        📋 Información de la Actividad
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; width:40%; border-bottom:1px solid #e5e7eb;">
                                        Actividad:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        <strong>{{ $activityName }}</strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; width:40%; border-bottom:1px solid #e5e7eb;">
                                        Centro de Costos:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ $wellnessRequest->cost_center }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; width:40%; border-bottom:1px solid #e5e7eb;">
                                        Fecha programada:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ $proposedDate }}
                                    </td>
                                </tr>
                                @if($locations)
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; width:40%; border-bottom:1px solid #e5e7eb;">
                                        Sede(s):
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ $locations }}
                                    </td>
                                </tr>
                                @endif
                                @if($participantCount)
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; width:40%; border-bottom:1px solid #e5e7eb;">
                                        Número de participantes:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ number_format($participantCount, 0, ',', '.') }}
                                    </td>
                                </tr>
                                @endif
                            </table>

                            <!-- Steps Box -->
                            <table width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#ecfdf5; border-radius:12px; overflow:hidden; margin-bottom:24px; border: 1px solid #a7f3d0;">
                                <tr>
                                    <td style="padding:20px; color:#065f46; font-size:14px; line-height:1.6;">
                                        <strong style="color:#047857; font-size:16px; display:block; margin-bottom:12px;">📝 Pasos para registrar tu actividad:</strong>
                                        <ol style="margin:12px 0; padding-left:20px;">
                                            <li style="margin:8px 0;">Ingresa al módulo de <strong>"Solicitudes Bienestar"</strong> en el sistema</li>
                                            <li style="margin:8px 0;">Busca tu solicitud <strong>"{{ $activityName }}"</strong></li>
                                            <li style="margin:8px 0;">Carga las <strong>evidencias fotográficas</strong> de la actividad realizada</li>
                                            <li style="margin:8px 0;">Sube el <strong>listado de asistencia</strong> de los participantes</li>
                                            <li style="margin:8px 0;">Completa la información solicitada sobre la actividad realizada</li>
                                            <li style="margin:8px 0;">Guarda los cambios para finalizar el registro</li>
                                        </ol>
                                    </td>
                                </tr>
                            </table>

                            <!-- CTA Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:24px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ config('app.frontend_url', config('app.url')) }}/admin/solicitudes-bienestar" 
                                           style="display:inline-block; background:#00529B; color:#ffffff; padding:14px 28px; text-decoration:none; border-radius:8px; font-weight:600; font-size:15px; text-align:center;">
                                            Ir a Solicitudes de Bienestar
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:16px 0 0; color:#4b5563; font-size:15px; line-height:1.7;">
                                Si ya registraste la información, por favor ignora este correo. Si tienes alguna duda o necesitas asistencia, no dudes en contactarnos.
                            </p>

                            <p style="margin:16px 0 0; font-size:15px; color:#4b5563; line-height:1.7;">
                                Gracias por tu colaboración en el seguimiento de las actividades de bienestar.<br><br>
                                Saludos cordiales,<br>
                                <strong style="color:#1f2937;">Equipo ProSalud</strong>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background:#f8f9fa; padding:24px 32px; border-top:1px solid #e5e7eb; text-align:center;">
                            <p style="margin:0; color:#64748b; font-size:12px; line-height:18px;">
                                Este es un correo automático de notificación del sistema ProSalud.<br />
                                © {{ date('Y') }} Sindicato ProSalud. Todos los derechos reservados.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
