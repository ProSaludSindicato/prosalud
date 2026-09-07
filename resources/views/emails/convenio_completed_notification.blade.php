<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Convenio completado - ProSalud</title>
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
                                        <h1 style="margin:4px 0 0; font-size:20px; color:#1f2937; font-weight:700; line-height:1.3;">
                                            Convenio firmado y completado
                                        </h1>
                                        <p style="margin:4px 0 0; color:#64748b; font-size:13px; line-height:1.5;">
                                            Tu documento ya cuenta con todas las firmas requeridas
                                        </p>
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
                                            <strong>Este es un envío de prueba (TEST).</strong> No corresponde a un convenio real. El documento se envía con el mismo formato de producción para validar el flujo.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <p style="margin:0 0 28px; font-size:15px; color:#4b5563; line-height:1.75;">
                                Te confirmamos que el proceso de firma de tu convenio sindical ha finalizado correctamente. Adjunto a este correo encontrarás el PDF con las firmas del afiliado y del presidente de ProSalud.
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; table-layout:fixed; margin:0 0 28px;">
                                <tr>
                                    <td style="padding:14px 16px; background:#f8fafc; border-radius:8px; border-left:3px solid #00529B;">
                                        <p style="margin:0; font-size:14px; color:#4b5563; line-height:1.75;">
                                            <strong style="color:#1f2937;">Importante:</strong> este documento es la versión final del convenio. No es necesario responder este correo ni volver a enviar el PDF.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 12px; font-size:16px; font-weight:700; color:#1f2937; line-height:1.4;">
                                ¿Necesitas ayuda?
                            </p>
                            <p style="margin:0; font-size:15px; color:#4b5563; line-height:1.75;">
                                Si tienes alguna duda sobre tu convenio o no puedes abrir el adjunto, escríbenos a
                                <a href="mailto:auxiliartalento.sprosalud@gmail.com" style="color:#00529B; text-decoration:none; font-weight:600;">auxiliartalento.sprosalud@gmail.com</a>
                                o visita
                                <a href="https://www.prosalud.org.co/" style="color:#00529B; text-decoration:none; font-weight:600;">prosalud.org.co</a>.
                            </p>
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
