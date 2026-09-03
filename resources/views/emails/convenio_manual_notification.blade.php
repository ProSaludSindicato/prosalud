<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Convenio para Firmar - ProSalud</title>
    <style>
        @media (max-width: 600px) {
            .outer {
                padding: 16px 8px !important;
            }
            .container {
                width: 100% !important;
                max-width: 100% !important;
            }
            .content {
                padding: 24px 16px !important;
            }
            .header-pad {
                padding: 18px 16px !important;
            }
            .logo-cell,
            .text-cell {
                display: block !important;
                width: 100% !important;
                text-align: center !important;
                padding-left: 0 !important;
            }
            .logo-cell {
                padding-bottom: 12px !important;
            }
            .logo-cell img {
                margin: 0 auto !important;
            }
        }
    </style>
</head>
<body style="margin:0; padding:0; background:#eef2f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
    <table class="outer" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding:32px 16px;">
        <tr>
            <td align="center">
                <table class="container" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:600px; table-layout:fixed; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow: 0 8px 28px rgba(0,82,155,0.10);">

                    <tr>
                        <td style="background:#00529B; height:6px; font-size:0; line-height:0;">&nbsp;</td>
                    </tr>

                    <tr>
                        <td class="header-pad" style="background:#f8fafc; padding:20px 32px; border-bottom:1px solid #e5e7eb;">
                            <table width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; table-layout:fixed;">
                                <tr>
                                    <td class="logo-cell" style="vertical-align:middle; width:108px;">
                                        @if($logoCid)
                                            <img src="{{ $logoCid }}" alt="ProSalud" width="100" height="75" style="display:block; border:0;" />
                                        @else
                                            <img src="https://www.prosalud.org.co/images/logo_prosalud_fondo.png" alt="ProSalud" width="100" height="75" style="display:block; border:0;" />
                                        @endif
                                    </td>
                                    <td class="text-cell" style="vertical-align:middle; padding-left:16px;">
                                        <p style="margin:0; font-size:11px; letter-spacing:0.08em; text-transform:uppercase; color:#00529B; font-weight:700;">
                                            ProSalud
                                        </p>
                                        @if(!empty($signingUrl))
                                            <h1 style="margin:4px 0 0; font-size:20px; color:#1f2937; font-weight:700; line-height:1.3;">
                                                Firma digital de convenio
                                            </h1>
                                            <p style="margin:4px 0 0; color:#64748b; font-size:13px; line-height:1.5;">
                                                Revisa y firma tu documento en pocos minutos
                                            </p>
                                        @else
                                            <h1 style="margin:4px 0 0; font-size:20px; color:#1f2937; font-weight:700; line-height:1.3;">
                                                Convenio pendiente de firma
                                            </h1>
                                            <p style="margin:4px 0 0; color:#64748b; font-size:13px; line-height:1.5;">
                                                Sigue las instrucciones para firmar el documento
                                            </p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="content" style="padding:32px;">
                            <div style="max-width:100%; overflow-wrap:break-word; word-break:break-word;">
                            <p style="margin:0 0 20px; font-size:16px; color:#1f2937; line-height:1.6;">
                                @if(!empty($nombreAfiliado))
                                    Hola <strong style="color:#00529B;">{{ $nombreAfiliado }}</strong>,
                                @else
                                    Hola <strong style="color:#00529B;">estimado afiliado</strong>,
                                @endif
                            </p>

                            @if(!empty($isTest))
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px;">
                                <tr>
                                    <td style="padding:14px 16px; background:#fff7ed; border-radius:8px; border-left:4px solid #c2410c;">
                                        <p style="margin:0; font-size:14px; color:#9a3412; line-height:1.6;">
                                            <strong>Este es un envío de prueba (TEST).</strong> No corresponde a un convenio real. El documento o enlace se envía con el mismo formato de producción para validar el flujo.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            @endif

                            @if(!empty($signingUrl))
                            <p style="margin:0 0 24px; font-size:15px; color:#4b5563; line-height:1.75;">
                                Tienes un convenio pendiente de <strong style="color:#1f2937;">firma digital</strong>. Ábrelo con el botón; no necesitas instalar ningún programa y puedes hacerlo desde tu celular o computador.
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; table-layout:fixed; margin:0 0 16px;">
                                <tr>
                                    <td align="center">
                                        <table class="btn-wrap" role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td align="center" style="border-radius:8px; background:#00529B;">
                                                    <a href="{{ $signingUrl }}" style="display:inline-block; padding:14px 28px; color:#ffffff; text-decoration:none; font-weight:600; font-size:16px;">
                                                        Abrir convenio para firmar
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 8px; font-size:13px; color:#64748b; line-height:1.6;">
                                Si el botón no abre, usa este
                                <a href="{{ $signingUrl }}" style="color:#00529B; font-weight:600; text-decoration:underline;">enlace de respaldo</a>.
                                También puedes copiar la dirección y pegarla en tu navegador:
                            </p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; table-layout:fixed; margin:0 0 28px;">
                                <tr>
                                    <td style="padding:12px 14px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; word-break:break-all; overflow-wrap:anywhere;">
                                        <a href="{{ $signingUrl }}" style="display:block; word-break:break-all; overflow-wrap:anywhere; color:#334155; font-size:12px; line-height:1.6; text-decoration:none;">{!! preg_replace('/(.{18})/', '$1<wbr>', e($signingUrl)) !!}</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 14px; font-size:16px; font-weight:700; color:#1f2937; line-height:1.4;">
                                Cómo firmar
                            </p>
                            <ol style="margin:0 0 24px; padding-left:22px; color:#4b5563; font-size:15px; line-height:1.75;">
                                <li style="margin-bottom:10px;">Abre el convenio con el botón o con el enlace de respaldo.</li>
                                <li style="margin-bottom:10px;">Revisa la información del documento.</li>
                                <li style="margin-bottom:10px;">En la <strong style="color:#1f2937;">segunda hoja</strong>, haz clic o toca la <strong style="color:#1f2937;">línea de firma</strong> que está sobre tu nombre.</li>
                                <li style="margin-bottom:10px;">Agrega o dibuja tu firma digital.</li>
                                <li style="margin-bottom:0;">Envía el documento para finalizar. Con eso el trámite queda listo: <strong style="color:#1f2937;">no es necesario responder este correo ni adjuntar el PDF</strong>.</li>
                            </ol>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; table-layout:fixed; margin:0 0 28px;">
                                <tr>
                                    <td style="padding:14px 16px; background:#f8fafc; border-radius:8px; border-left:3px solid #00529B;">
                                        <p style="margin:0; font-size:14px; color:#4b5563; line-height:1.75;">
                                            <strong style="color:#1f2937;">Antes de firmar:</strong> este enlace es personal, no lo compartas. Verifica los datos del convenio. La firma digital es obligatoria.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 12px; font-size:16px; font-weight:700; color:#1f2937; line-height:1.4;">
                                ¿Necesitas ayuda?
                            </p>
                            <p style="margin:0; font-size:15px; color:#4b5563; line-height:1.75;">
                                Si tienes alguna duda o inconveniente, escríbenos a
                                <a href="mailto:auxiliartalento.sprosalud@gmail.com" style="color:#00529B; text-decoration:none; font-weight:600;">auxiliartalento.sprosalud@gmail.com</a>
                                o visita
                                <a href="https://www.prosalud.org.co/" style="color:#00529B; text-decoration:none; font-weight:600;">prosalud.org.co</a>.
                            </p>
                            @else
                            <p style="margin:0 0 28px; font-size:15px; color:#4b5563; line-height:1.7;">
                                Te informamos que tienes un convenio pendiente de firma. Se adjunta el documento PDF para que puedas proceder con el proceso de firma manual. Por favor, sigue las siguientes instrucciones:
                            </p>

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
                                    <strong style="color:#00529B;">Enviar al correo</strong> <a href="mailto:sprosalud.auxiliar@gmail.com" style="color:#00529B; text-decoration:underline; font-weight:600;">sprosalud.auxiliar@gmail.com</a> el archivo PDF y en el asunto escriba así: <strong style="color:#00529B;">Convenio {{ $documento }} ({{ $nombreConvenio }})@if(!empty($nombreAfiliado)) {{ preg_replace('/\s*-\s*' . preg_quote($documento, '/') . '$/', '', $nombreAfiliado) }}@endif</strong>. <strong style="color:#d32f2f;">*El envío Digital del Convenio Sindical es de Carácter Obligatorio.*</strong>
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

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="background:#f9fafb; padding:20px; border-radius:12px; border:1px solid #e5e7eb;">
                                        <p style="margin:0 0 12px; font-size:15px; color:#1f2937; line-height:1.6;">
                                            <strong style="color:#00529B;">¿Necesitas ayuda?</strong>
                                        </p>
                                        <p style="margin:0; font-size:14px; color:#4b5563; line-height:1.7;">
                                            Si tienes alguna duda o inconveniente, puedes contactarnos a través de nuestros canales oficiales o visitar nuestro sitio web:
                                            <a href="https://www.prosalud.org.co/" style="color:#00529B; text-decoration:none; font-weight:600;">prosalud.org.co</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            @endif
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 32px; background:#f8fafc; border-top:1px solid #e5e7eb; text-align:center;">
                            <p style="margin:0; font-size:12px; color:#94a3b8;">
                                © {{ date('Y') }} ProSalud. Todos los derechos reservados.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
