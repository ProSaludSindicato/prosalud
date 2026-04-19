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

                            @if(!empty($signingUrl))
                            <p style="margin:0 0 24px; font-size:15px; color:#4b5563; line-height:1.75;">
                                Tienes un convenio pendiente de <strong style="color:#1f2937;">firma digital</strong>: para revisarlo y firmarlo, haz clic en el siguiente botón. No necesitas instalar ningún programa y puedes hacerlo desde tu celular o computador en pocos minutos.
                            </p>
                            <div style="margin:0 0 20px; text-align:center;">
                                <a href="{{ $signingUrl }}" style="display:inline-block; background:#00529B; color:#ffffff; text-decoration:none; font-weight:600; font-size:16px; padding:14px 28px; border-radius:8px;">
                                    Firmar convenio ahora
                                </a>
                            </div>
                            <p style="margin:0 0 20px; font-size:14px; color:#64748b; line-height:1.75;">
                                Si el botón no funciona, copia y pega este enlace en tu navegador:
                                <a href="{{ $signingUrl }}" style="word-break:break-all; color:#00529B; font-weight:600; text-decoration:underline;">{{ $signingUrl }}</a>
                            </p>
                            <p style="margin:0 0 28px; font-size:14px; color:#4b5563; line-height:1.75; padding:14px 16px; background:#f8fafc; border-radius:8px; border-left:3px solid #00529B;">
                                <strong style="color:#1f2937;">Importante:</strong> al completar la firma y el envío (tanto si usas el botón como si entras por el enlace), el trámite queda concluido en nuestro sistema. <strong>No</strong> debes responder a este correo ni adjuntar el PDF de nuevo (no es necesario realizar el envío del convenio manualmente).
                            </p>

                            <hr style="margin:28px 0; border:0; border-top:1px solid #e5e7eb;" />

                            <p style="margin:0 0 14px; font-size:16px; font-weight:700; color:#1f2937; line-height:1.4;">
                                Antes de firmar, ten en cuenta:
                            </p>
                            <ul style="margin:0 0 28px; padding-left:20px; color:#4b5563; font-size:15px; line-height:1.8;">
                                <li style="margin-bottom:10px;">Este enlace es personal. No lo compartas con otras personas.</li>
                                <li style="margin-bottom:10px;">Verifica la información del convenio antes de confirmar tu firma.</li>
                                <li style="margin-bottom:0;">La firma digital del convenio es obligatoria.</li>
                            </ul>

                            <hr style="margin:28px 0; border:0; border-top:1px solid #e5e7eb;" />

                            <p style="margin:0 0 12px; font-size:16px; font-weight:700; color:#1f2937; line-height:1.4;">
                                ¿Necesitas ayuda?
                            </p>
                            <p style="margin:0 0 28px; font-size:15px; color:#4b5563; line-height:1.75;">
                                Si tienes alguna duda o inconveniente, puedes contactarnos a través de nuestros canales oficiales o visitar nuestro sitio web:
                                <a href="https://www.prosalud.org.co/" style="color:#00529B; text-decoration:none; font-weight:600;">prosalud.org.co</a>
                            </p>
                            @else
                            <p style="margin:0 0 28px; font-size:15px; color:#4b5563; line-height:1.7;">
                                Te informamos que tienes un convenio pendiente de firma. Se adjunta el documento PDF para que puedas proceder con el proceso de firma manual. Por favor, sigue las siguientes instrucciones:
                            </p>

                            <!-- Instructions -->
                            <ol style="margin:0 0 32px; padding-left:24px; color:#1f2937; font-size:15px; line-height:1.8;">
                                <li style="margin-bottom:20px; padding-left:8px;">
                                    <strong style="color:#00529B;">Imprimir el convenio</strong> - Por favor imprima el convenio a doble cara (por amabilidad con el medio ambiente haga uso de ambos lados del papel).
                                </li>
                                <li style="margin-bottom:20px; padding-left:8px;">
                                    <strong style="color:#00529B;">Leer el convenio</strong>
                                </li>
                                <li style="margin-bottom:20px; padding-left:8px;">
                                    <strong style="color:#00529B;">Diligenciar campos:</strong> lugar y fecha de nacimiento, ciudad donde reside, lugar de expedición de su CC, dirección, teléfono y celular, y al finalizar su firma.
                                </li>
                                <li style="margin-bottom:20px; padding-left:8px;">
                                    <strong style="color:#00529B;">Escanear en formato PDF</strong>
                                </li>
                                <li style="margin-bottom:0; padding-left:8px;">
                                    <strong style="color:#00529B;">Enviar al correo</strong> <a href="mailto:auxiliar.talento@sindicatoprosalud.com" style="color:#00529B; text-decoration:underline; font-weight:600;">auxiliar.talento@sindicatoprosalud.com</a> el archivo PDF y en el asunto escriba así: <strong style="color:#00529B;">Convenio {{ $documento }} ({{ $nombreConvenio }}) {{ preg_replace('/\s*-\s*' . preg_quote($documento, '/') . '$/', '', $nombreAfiliado) }}</strong>. <strong style="color:#d32f2f;">*El envío Digital del Convenio Sindical es de Carácter Obligatorio.*</strong>
                                </li>
                            </ol>

                            <p style="margin:0 0 12px; font-size:15px; font-weight:600; color:#1f2937; line-height:1.6;">
                                Información Importante:
                            </p>
                            <ul style="margin:0 0 28px; padding-left:20px; color:#4b5563; font-size:14px; line-height:1.8;">
                                <li style="margin-bottom:8px;">Es importante que completes todos los campos solicitados antes de firmar</li>
                                <li style="margin-bottom:8px;">Asegúrate de tener a mano tu documento de identidad y la información requerida</li>
                                <li style="margin-bottom:0;">El envío digital del convenio sindical es de carácter obligatorio</li>
                            </ul>
                            @endif

                            @empty($signingUrl)
                            <!-- Ayuda (solo flujo firma manual / PDF adjunto) -->
                            <div style="background:#f9fafb; padding:20px; border-radius:12px; margin:0; border:1px solid #e5e7eb;">
                                <p style="margin:0 0 12px; font-size:15px; color:#1f2937; line-height:1.6;">
                                    <strong style="color:#00529B;">¿Necesitas ayuda?</strong>
                                </p>
                                <p style="margin:0; font-size:14px; color:#4b5563; line-height:1.7;">
                                    Si tienes alguna duda o inconveniente, puedes contactarnos a través de nuestros canales oficiales o visitar nuestro sitio web:
                                    <a href="https://www.prosalud.org.co/" style="color:#00529B; text-decoration:none; font-weight:600;">prosalud.org.co</a>
                                </p>
                            </div>
                            @endempty
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 32px; background: linear-gradient(135deg, #f8f9fa 0%, #f1f3f5 100%); border-top:1px solid #e5e7eb;">
                            <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="padding:0 0 12px; text-align:center;">
                                        <div style="height:3px; width:60px; background:#00529B; border-radius:3px; margin:0 auto;"></div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="text-align:center;">
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

