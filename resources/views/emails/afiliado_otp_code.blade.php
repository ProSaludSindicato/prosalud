<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Código de Verificación - ProSalud</title>
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
                                        <img src="https://prosalud-spa.lovable.app/images/logo_prosalud_fondo.png" alt="ProSalud" width="108" height="80" style="display:block; border:0; border-radius:12px;" />
                                    </td>
                                    <td class="text-cell" style="vertical-align:middle; padding-left:16px;">
                                        <h1 style="margin:0; font-size:24px; color:#00529B; font-weight:700; letter-spacing:-0.5px;">ProSalud</h1>
                                        <p style="margin:6px 0 0; color:#64748b; font-size:14px; font-weight:500;">Código de Verificación</p>
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
                                Hemos recibido una solicitud de autenticación para tu cuenta. Utiliza el siguiente código de verificación de 6 dígitos para completar el proceso de inicio de sesión:
                            </p>
                            
                            <!-- OTP Code Box -->
                            <table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 32px;">
                                <tr>
                                    <td align="center" style="padding:0;">
                                        <div style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 2px solid #00529B; border-radius:16px; padding:28px 32px; display: inline-block; box-shadow: 0 4px 12px rgba(0,82,155,0.15);">
                                            <span style="font-size: 36px; font-weight: 700; color: #00529B; letter-spacing: 12px; font-family: 'Courier New', monospace; display: inline-block;">
                                                {{ $otpCode }}
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Important Notice -->
                            <div style="background:#f9fafb; padding:20px; border-radius:12px; margin:0 0 28px; border:1px solid #e5e7eb; border-left: 3px solid #00529B;">
                                <p style="margin:0 0 16px; font-size:15px; font-weight:600; color:#1f2937;">
                                    Información Importante:
                                </p>
                                <ul style="margin:0; padding-left:20px; color:#4b5563; font-size:14px; line-height:1.8;">
                                    <li style="margin-bottom:10px;">Este código tiene una validez de <strong>10 minutos</strong> desde el momento de su envío</li>
                                    <li style="margin-bottom:10px;">Por tu seguridad, <strong>no compartas este código</strong> con ninguna persona, incluso si dicen ser de ProSalud</li>
                                    <li style="margin-bottom:10px;">Si <strong>no solicitaste este código</strong>, puedes ignorar este correo de forma segura</li>
                                    <li style="margin-bottom:0;">Solo puedes usar este código <strong>una vez</strong> para completar tu autenticación</li>
                                </ul>
                            </div>
                            
                            <p style="margin:0 0 24px; font-size:15px; color:#4b5563; line-height:1.7;">
                                Este código es válido únicamente para esta sesión de autenticación. Una vez utilizado, expirará automáticamente.
                            </p>

                            <!-- Help Section -->
                            <div style="background:#f9fafb; padding:20px; border-radius:12px; margin:0 0 0; border:1px solid #e5e7eb;">
                                <p style="margin:0 0 12px; font-size:15px; color:#1f2937; line-height:1.6;">
                                    <strong style="color:#00529B;">¿Necesitas ayuda?</strong>
                                </p>
                                <p style="margin:0; font-size:14px; color:#4b5563; line-height:1.7;">
                                    Si tienes alguna pregunta o necesitas asistencia, no dudes en contactarnos a través de nuestros <strong>canales de atención oficiales</strong>. Visita nuestro sitio web en <a href="https://prosalud-spa.lovable.app/" style="color:#00529B; text-decoration:none; font-weight:600;">prosalud-spa.lovable.app</a> para más información.
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
                                            Este es un mensaje automático generado por el sistema de autenticación de ProSalud.
                                        </p>
                                        <p style="margin:0 0 12px; font-size:13px; color:#64748b; line-height:1.6;">
                                            Si tienes alguna pregunta, comunícate con nuestro equipo de soporte a través de los canales oficiales o visita <a href="https://prosalud-spa.lovable.app/" style="color:#00529B; text-decoration:none; font-weight:600;">nuestro sitio web</a>.
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
