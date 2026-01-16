<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Convenio para Firmar - ProSalud</title>
    <style>
        @media (max-width: 600px) {
            .container {
                width: 100% !important;
                padding: 0 !important;
            }
            .content {
                padding: 24px 20px !important;
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
                        <td style="background: #ffffff; padding:40px 32px 32px; border-bottom: 1px solid #e5e7eb;">
                            <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center" style="padding:0;">
                                        @if($logoCid)
                                            <img src="{{ $logoCid }}" alt="ProSalud" width="120" height="90" style="display:block; border:0; margin:0 auto;" />
                                        @else
                                            <img src="https://www.prosalud.org.co/images/logo_prosalud_fondo.png" alt="ProSalud" width="120" height="90" style="display:block; border:0; margin:0 auto;" />
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td class="content" style="padding:32px;">
                            <p style="margin:0 0 20px; font-size:16px; color:#1f2937; line-height:1.6;">
                                Hola <strong style="color:#00529B;">{{ $nombreAfiliado }}</strong>,
                            </p>

                            <p style="margin:0 0 28px; font-size:15px; color:#4b5563; line-height:1.7;">
                                Te informamos que tienes un convenio pendiente de firma. Para completar el proceso, sigue las siguientes instrucciones:
                            </p>

                            <!-- Instructions -->
                            <ol style="margin:0 0 32px; padding-left:24px; color:#1f2937; font-size:15px; line-height:1.8;">
                                <li style="margin-bottom:20px; padding-left:8px;">
                                    <strong style="color:#00529B;">Ingresar al siguiente enlace:</strong><br>
                                    <a href="https://192.168.1.52:8080/servicios/firma-convenio" style="color:#00529B; text-decoration:none; font-weight:500; word-break:break-all; margin-top:4px; display:inline-block;">https://192.168.1.52:8080/servicios/firma-convenio</a>
                                </li>
                                <li style="margin-bottom:20px; padding-left:8px;">
                                    <strong style="color:#00529B;">Autenticarse</strong> con número de documento y fecha de expedición.
                                </li>
                                <li style="margin-bottom:20px; padding-left:8px;">
                                    Serás <strong>redirigido al sitio de DocuSign</strong> para la firma del contrato.
                                </li>
                                <li style="margin-bottom:20px; padding-left:8px;">
                                    <strong style="color:#00529B;">Diligenciar los campos indicados:</strong>
                                    <ul style="margin:8px 0 0 24px; padding-left:4px; font-weight:normal; color:#4b5563; font-size:14px; line-height:1.7;">
                                        <li style="margin-bottom:6px;">Lugar y Fecha de nacimiento</li>
                                        <li style="margin-bottom:6px;">Ciudad donde reside</li>
                                        <li style="margin-bottom:6px;">Lugar de expedición de documento</li>
                                        <li style="margin-bottom:6px;">Dirección</li>
                                        <li style="margin-bottom:6px;">Teléfono</li>
                                        <li style="margin-bottom:6px;">Celular</li>
                                        <li style="margin-bottom:0;">Al finalizar, realizar tu firma</li>
                                    </ul>
                                </li>
                                <li style="margin-bottom:0; padding-left:8px;">
                                    <strong style="color:#00529B;">Realizar el envío del contrato</strong> una vez completados todos los campos.
                                </li>
                            </ol>

                            <!-- Important Notice -->
                            <p style="margin:0 0 12px; font-size:15px; font-weight:600; color:#1f2937; line-height:1.6;">
                                Información Importante:
                            </p>
                            <ul style="margin:0 0 28px; padding-left:20px; color:#4b5563; font-size:14px; line-height:1.8;">
                                <li style="margin-bottom:8px;">Es importante que completes todos los campos solicitados antes de firmar</li>
                                <li style="margin-bottom:8px;">Asegúrate de tener a mano tu documento de identidad y la información requerida</li>
                                <li style="margin-bottom:0;">Si tienes alguna duda, puedes contactarnos a través de nuestros canales oficiales</li>
                            </ul>

                            <!-- Call to Action Button -->
                            <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 28px;">
                                <tr>
                                    <td align="center" style="padding:0;">
                                        <a href="https://192.168.1.52:8080/servicios/firma-convenio" style="display:inline-block; background: linear-gradient(135deg, #00529B 0%, #0ea5e9 100%); color:#ffffff; text-decoration:none; padding:16px 32px; border-radius:12px; font-weight:600; font-size:16px; box-shadow: 0 4px 12px rgba(0,82,155,0.3);">
                                            Ir a Firmar Convenio
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Help Section -->
                            <div style="background:#f9fafb; padding:20px; border-radius:12px; margin:0 0 0; border:1px solid #e5e7eb;">
                                <p style="margin:0 0 12px; font-size:15px; color:#1f2937; line-height:1.6;">
                                    <strong style="color:#00529B;">¿Necesitas ayuda?</strong>
                                </p>
                                <p style="margin:0; font-size:14px; color:#4b5563; line-height:1.7;">
                                    Si tienes alguna pregunta o necesitas asistencia, no dudes en contactarnos a través de nuestros <strong>canales de atención oficiales</strong>. Visita nuestro sitio web en <a href="https://www.prosalud.org.co/" style="color:#00529B; text-decoration:none; font-weight:600;">prosalud.org.co/</a> para más información.
                                </p>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 32px; background: linear-gradient(135deg, #f8f9fa 0%, #f1f3f5 100%); border-top:1px solid #e5e7eb;">
                            <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="padding:0 0 16px; text-align:center;">
                                        <div style="height:3px; width:60px; background: linear-gradient(90deg, #00529B 0%, #0ea5e9 100%); border-radius:3px; margin:0 auto;"></div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="text-align:center;">
                                        <p style="margin:0 0 8px; font-size:13px; color:#64748b; line-height:1.6;">
                                            Este es un mensaje automático generado por el sistema de ProSalud.
                                        </p>
                                        <p style="margin:0 0 12px; font-size:13px; color:#64748b; line-height:1.6;">
                                            Si tienes alguna pregunta, comunícate con nuestro equipo de soporte a través de los canales oficiales o visita <a href="https://www.prosalud.org.co/" style="color:#00529B; text-decoration:none; font-weight:600;">nuestro sitio web</a>.
                                        </p>
                                        <p style="margin:0; font-size:12px; color:#94a3b8;">
                                            © {{ date('Y') }} ProSalud. Todos los derechos reservados.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

