<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nueva solicitud de bienestar - ProSalud</title>
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
                                        <img src="https://prosalud-spa.lovable.app/images/logo_prosalud_fondo.png" alt="ProSalud" width="108" height="80" style="display:block; border:0; border-radius:12px;" />
                                    </td>
                                    <td class="text-cell" style="vertical-align:middle; padding-left:16px;">
                                        <h1 style="margin:0; font-size:24px; color:#00529B; font-weight:700; letter-spacing:-0.5px;">ProSalud</h1>
                                        <p style="margin:6px 0 0; color:#64748b; font-size:14px; font-weight:500;">Nueva solicitud de bienestar recibida</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td class="content" style="padding:32px;">
                            <p style="margin:0 0 24px; color:#1e293b; font-size:16px; line-height:24px;">
                                Se ha recibido una nueva solicitud de actividad de bienestar que requiere su revisión.
                            </p>

                            <!-- Information Table -->
                            <table class="info-table" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f8f9fa; border-radius:12px; overflow:hidden; margin-bottom:24px;">
                                <tr style="background:#00529B;">
                                    <td colspan="2" style="padding:16px; color:#ffffff; font-weight:700; font-size:16px;">
                                        Información de la Solicitud
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; width:40%; border-bottom:1px solid #e5e7eb;">
                                        ID de Solicitud:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        <strong>{{ $wellnessRequest->id }}</strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Nombre de la Actividad:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ $wellnessRequest->activity_name }}
                                    </td>
                                </tr>
                                @if($wellnessRequest->activity_description)
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Descripción:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ $wellnessRequest->activity_description }}
                                    </td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Centro de Costos:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ $wellnessRequest->cost_center }}
                                    </td>
                                </tr>
                                @if($wellnessRequest->locations && count($wellnessRequest->locations) > 0)
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Sedes:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ implode(', ', $wellnessRequest->locations) }}
                                    </td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Fecha Propuesta:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ $wellnessRequest->proposed_date->format('d/m/Y') }}
                                    </td>
                                </tr>
                                @if($wellnessRequest->start_time)
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Hora Inicio:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ strlen($wellnessRequest->start_time) >= 5 ? substr($wellnessRequest->start_time, 0, 5) : $wellnessRequest->start_time }}
                                    </td>
                                </tr>
                                @endif
                                @if($wellnessRequest->end_time)
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Hora Fin:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ strlen($wellnessRequest->end_time) >= 5 ? substr($wellnessRequest->end_time, 0, 5) : $wellnessRequest->end_time }}
                                    </td>
                                </tr>
                                @endif
                                @if($wellnessRequest->participant_count)
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Número de Participantes:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ number_format($wellnessRequest->participant_count, 0, ',', '.') }}
                                    </td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Requiere Detalles/Souvenirs:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ $wellnessRequest->requires_details ? 'Sí' : 'No' }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Solicitante:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px;">
                                        {{ $wellnessRequest->requester->name ?? 'N/A' }} (ID: {{ $wellnessRequest->requester_id }})
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Estado:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px;">
                                        <span style="background:#fef3c7; color:#92400e; padding:4px 12px; border-radius:6px; font-weight:600; font-size:12px; text-transform:uppercase;">
                                            {{ $wellnessRequest->status_text }}
                                        </span>
                                    </td>
                                </tr>
                            </table>

                            @if($wellnessRequest->requires_details && $wellnessRequest->details->count() > 0)
                            <!-- Details Table -->
                            <table class="info-table" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f8f9fa; border-radius:12px; overflow:hidden; margin-bottom:24px;">
                                <tr style="background:#00529B;">
                                    <td colspan="3" style="padding:16px; color:#ffffff; font-weight:700; font-size:16px;">
                                        Detalles / Souvenirs
                                    </td>
                                </tr>
                                <tr style="background:#e5e7eb;">
                                    <td style="padding:12px 16px; color:#1e293b; font-weight:700; font-size:14px; border-bottom:1px solid #d1d5db;">
                                        Tipo
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-weight:700; font-size:14px; border-bottom:1px solid #d1d5db; text-align:right;">
                                        Cantidad
                                    </td>
                                </tr>
                                @foreach($wellnessRequest->details as $detail)
                                <tr>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ $detail->type }}
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb; text-align:right;">
                                        {{ number_format($detail->quantity, 0, ',', '.') }}
                                    </td>
                                </tr>
                                @endforeach
                            </table>
                            @endif

                            <p style="margin:0 0 24px; color:#4b5563; font-size:15px; line-height:1.7;">
                                Por favor, revisa los detalles de la solicitud y realiza las acciones correspondientes según corresponda. Te notificaremos cuando se actualice el estado de esta solicitud.
                            </p>

                            <p style="margin:16px 0 0; font-size:15px; color:#4b5563; line-height:1.7;">
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
                                Fecha de recepción: {{ $wellnessRequest->created_at->format('d/m/Y H:i') }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

