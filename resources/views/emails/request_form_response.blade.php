<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Respuesta a su solicitud - ProSalud</title>
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
<body style="margin:0; padding:0; background-color: #f5f7fa; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding:40px 20px;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table class="container" role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width: 600px; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">

                    <!-- Content -->
                    <tr>
                        <td class="content" style="padding:32px;">
                            <!-- Logo at top (subtle) -->
                            <div style="text-align:center; margin-bottom:24px;">
                                <img src="https://www.prosalud.org.co/images/logo_prosalud_fondo.png" alt="ProSalud" width="120" height="90" style="display:inline-block; border:0; border-radius:8px;" />
                            </div>

                            <!-- Greeting -->
                            <p style="margin:0 0 16px; font-size:16px; color:#1f2937; line-height:1.6;">
                                Hola <strong>{{ $requestForm->full_name }}</strong>,
                            </p>

                            @php
                                // Get status message based on status
                                $statusMessages = [
                                    'COMPLETED' => [
                                        'title' => 'Su solicitud ha sido completada',
                                        'message' => 'Nos complace informarle que su solicitud #' . $requestForm->id . ' ha sido procesada y completada exitosamente.',
                                        'color' => '#10b981',
                                        'bg_color' => '#f0fdf4'
                                    ],
                                    'REJECTED' => [
                                        'title' => 'Sobre su solicitud',
                                        'message' => 'Lamentamos informarle que su solicitud #' . $requestForm->id . ' no ha podido ser procesada.',
                                        'color' => '#dc2626',
                                        'bg_color' => '#fef2f2'
                                    ],
                                    'IN_REVIEW' => [
                                        'title' => 'Su solicitud está en revisión',
                                        'message' => 'Le informamos que su solicitud #' . $requestForm->id . ' está siendo revisada por nuestro equipo.',
                                        'color' => '#2563eb',
                                        'bg_color' => '#eff6ff'
                                    ],
                                    'PENDING' => [
                                        'title' => 'Actualización de su solicitud',
                                        'message' => 'Le informamos sobre una actualización relacionada con su solicitud #' . $requestForm->id . '.',
                                        'color' => '#f59e0b',
                                        'bg_color' => '#fffbeb'
                                    ]
                                ];

                                $statusInfo = $statusMessages[$status] ?? $statusMessages['PENDING'];
                            @endphp

                            <!-- Status Banner -->
                            <div style="background-color: {{ $statusInfo['bg_color'] }}; border-left: 4px solid {{ $statusInfo['color'] }}; padding: 16px 20px; margin: 0 0 24px; border-radius: 4px;">
                                <p style="margin:0 0 4px; font-size:16px; color:#1f2937; font-weight:600;">
                                    {{ $statusInfo['title'] }}
                                </p>
                                <p style="margin:0; font-size:14px; color:#4b5563; line-height:1.6;">
                                    {{ $statusInfo['message'] }}
                                </p>
                            </div>

                            <!-- Main Message Body - Fixed line breaks -->
                            <div style="margin:0 0 24px;">
                                <div style="font-size:15px; color:#1f2937; line-height:1.7;">{!! nl2br(e($emailBody)) !!}</div>
                            </div>

                            <!-- Request Reference (subtle, not in a card) -->
                            <div style="padding:16px 0; margin:24px 0; border-top:1px solid #e5e7eb; border-bottom:1px solid #e5e7eb;">
                                <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                    <tr>
                                        <td style="font-size:12px; color:#64748b; padding-bottom:4px;">Referencia de Solicitud</td>
                                    </tr>
                                    <tr>
                                        <td style="font-size:14px; color:#1f2937; font-weight:500;">
                                            #{{ $requestForm->id }} - {{ $requestForm->request_type }}
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Closing -->
                            <p style="margin:24px 0 0; font-size:15px; color:#4b5563; line-height:1.7;">
                                Si tiene alguna pregunta o necesita aclaraciones adicionales, no dude en comunicarse con nosotros.
                            </p>

                            <p style="margin:16px 0 0; font-size:15px; color:#4b5563; line-height:1.7;">
                                Saludos cordiales,<br>
                                <strong style="color:#1f2937;">Equipo ProSalud</strong>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 32px; background-color: #f9fafb; border-top:1px solid #e5e7eb;">
                            <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="padding:0 0 16px; text-align:center;">
                                        <div style="height:2px; width:60px; background-color: #00529B; border-radius:2px; margin:0 auto;"></div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="text-align:center;">
                                        <p style="margin:0 0 8px; font-size:12px; color:#64748b; line-height:1.6;">
                                            Este es un mensaje automático generado por el sistema de gestión de solicitudes.
                                        </p>
                                        <p style="margin:0 0 12px; font-size:12px; color:#64748b; line-height:1.6;">
                                            Si tiene alguna pregunta, comuníquese con nuestro equipo de soporte a través de los canales oficiales.
                                        </p>
                                        <p style="margin:0; font-size:11px; color:#94a3b8;">
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
