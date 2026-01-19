<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Firma de Convenio de Afiliación - ProSalud</title>
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
                                Te informamos que tienes un convenio de afiliación pendiente de firma. Para completar el proceso, haz clic en el botón a continuación para acceder directamente al documento y realizar tu firma digital.
                            </p>

                            <!-- Call to Action Button -->
                            <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 28px;">
                                <tr>
                                    <td align="center" style="padding:0;">
                                        <a href="{{ $signingUrl }}" style="display:inline-block; background: linear-gradient(135deg, #00529B 0%, #0ea5e9 100%); color:#ffffff; text-decoration:none; padding:16px 32px; border-radius:12px; font-weight:600; font-size:16px; box-shadow: 0 4px 12px rgba(0,82,155,0.3);">
                                            Firmar Convenio Ahora
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Instructions -->
                            <p style="margin:0 0 16px; font-size:15px; font-weight:600; color:#1f2937; line-height:1.6;">
                                <strong style="color:#00529B;">Instrucciones para completar la firma:</strong>
                            </p>
                            <ol style="margin:0 0 28px; padding-left:24px; color:#1f2937; font-size:15px; line-height:1.8;">
                                <li style="margin-bottom:20px; padding-left:8px;">
                                    Haz clic en el botón <strong style="color:#00529B;">"Firmar Convenio Ahora"</strong> o en el enlace directo que aparece más abajo.
                                </li>
                                <li style="margin-bottom:20px; padding-left:8px;">
                                    Serás redirigido a la plataforma de DocuSign para completar el proceso de firma.
                                </li>
                                <li style="margin-bottom:20px; padding-left:8px;">
                                    <strong style="color:#00529B;">Diligenciar los campos indicados:</strong> lugar y fecha de nacimiento, domicilio, lugar de expedición de documento, teléfono y celular, y al finalizar tu firma digital.
                                </li>
                                <li style="margin-bottom:0; padding-left:8px;">
                                    Una vez completados todos los campos, <strong style="color:#00529B;">realiza tu firma digital</strong> y envía el documento.
                                </li>
                            </ol>

                            <!-- Direct Link -->
                            <p style="margin:0 0 8px; font-size:14px; color:#4b5563; line-height:1.6;">
                                <strong style="color:#00529B;">Enlace directo:</strong>
                                <a href="{{ $signingUrl }}" style="color:#00529B; text-decoration:underline; word-break:break-all;">{{ $signingUrl }}</a>
                            </p>
                            <p style="margin:0 0 28px; font-size:13px; color:#64748b; line-height:1.6;">
                                Si el botón no funciona, copia y pega el enlace anterior en tu navegador.
                            </p>

                            <!-- Important Notice -->
                            <p style="margin:0 0 12px; font-size:15px; font-weight:600; color:#1f2937; line-height:1.6;">
                                Información Importante:
                            </p>
                            <ul style="margin:0 0 28px; padding-left:20px; color:#4b5563; font-size:14px; line-height:1.8;">
                                <li style="margin-bottom:8px;">Es importante que completes todos los campos solicitados antes de firmar</li>
                                <li style="margin-bottom:8px;">Asegúrate de tener a mano tu documento de identidad y la información requerida</li>
                                <li style="margin-bottom:8px;">Este enlace es personal e intransferible. No lo compartas con otras personas</li>
                                <li style="margin-bottom:0;"><strong style="color:#d32f2f;">La firma Digital del Convenio Sindical es de Carácter Obligatorio.</strong></li>
                            </ul>
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
                                            Ante cualquier duda o problema para acceder a la firma del convenio comunicarse al correo: <a href="mailto:comunicaciones@sindicatoprosalud.com" style="color:#00529B; text-decoration:none; font-weight:600;">comunicaciones@sindicatoprosalud.com</a>
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

