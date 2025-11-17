<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Restablece tu contraseña - ProSalud</title>
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
<body style="margin:0; padding:0; background: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding:40px 20px;">
    <tr>
        <td align="center">
            <table class="container" role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width: 600px; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow: 0 10px 40px rgba(0,82,155,0.12);">
                <tr>
                    <td style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding:24px 32px; border-bottom: 1px solid #e5e7eb;">
                        <table width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td style="vertical-align:middle; width:120px;">
                                    @if(!empty($logoCid))
                                        <img src="{{ $logoCid }}" alt="ProSalud" width="108" height="80" style="display:block; border:0; border-radius:12px;" />
                                    @else
                                        <img src="{{ url('assets/logo.png') }}" alt="ProSalud" width="108" height="80" style="display:block; border:0; border-radius:12px;" />
                                    @endif
                                </td>
                                <td style="vertical-align:middle; padding-left:16px;">
                                    <h1 style="margin:0; font-size:22px; color:#00529B; font-weight:700; letter-spacing:-0.5px;">
                                        Restablece tu contraseña
                                    </h1>
                                    <p style="margin:6px 0 0; color:#64748b; font-size:14px;">
                                        Solicitud de restablecimiento de contraseña.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="content" style="padding:32px;">
                        <p style="margin:0 0 16px; color:#1f2933; font-size:16px;">
                            Hola <strong>{{ $user->name }}</strong>,
                        </p>
                        <p style="margin:0 0 16px; color:#4b5563; font-size:15px; line-height:1.7;">
                            Recibimos una solicitud para restablecer la contraseña de tu cuenta en <strong>ProSalud</strong>. 
                            Si no realizaste esta solicitud, puedes ignorar este correo de forma segura.
                        </p>

                        <p style="margin:0 0 16px; color:#4b5563; font-size:15px; line-height:1.7;">
                            Para restablecer tu contraseña, sigue estos pasos:
                        </p>

                        <ol style="margin:0 0 24px 20px; padding:0; color:#4b5563; font-size:15px; line-height:1.7;">
                            <li>Haz clic en el siguiente botón para ir a la página de restablecimiento de contraseña.</li>
                            <li>Ingresa una nueva contraseña segura y confírmala.</li>
                            <li>Guarda los cambios para completar el restablecimiento.</li>
                        </ol>

                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px;">
                            <tr>
                                <td align="center" style="border-radius:999px; background:#00529B;">
                                    <a href="{{ $frontendUrl }}" style="display:inline-block; padding:12px 28px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px;">
                                        Restablecer mi contraseña
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 16px; color:#6b7280; font-size:13px; line-height:1.7;">
                            Si el botón no funciona, copia y pega el siguiente enlace en tu navegador:
                            <br>
                            <span style="word-break:break-all; color:#00529B;">
                                {{ $frontendUrl }}
                            </span>
                        </p>

                        <p style="margin:16px 0 0; color:#9ca3af; font-size:12px; line-height:1.7;">
                            Por motivos de seguridad, este enlace tiene una validez limitada en el tiempo. 
                            Si el enlace ha expirado o tienes problemas para acceder, puedes solicitar uno nuevo desde la página de inicio de sesión.
                        </p>

                        <p style="margin:24px 0 0; font-size:14px; color:#4b5563;">
                            Saludos,<br>
                            <strong>Equipo ProSalud</strong>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="background:#f9fafb; padding:18px 32px; border-top:1px solid #e5e7eb; text-align:center;">
                        <p style="margin:0; color:#9ca3af; font-size:11px; line-height:18px;">
                            Este es un correo automático enviado por la plataforma interna de ProSalud.<br />
                            Por favor, no respondas a este mensaje.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

