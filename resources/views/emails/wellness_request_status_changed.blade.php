<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Actualización de estado - ProSalud</title>
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
                                        <img src="https://www.prosalud.org.co/images/logo_prosalud_fondo.png" alt="ProSalud" width="108" height="80" style="display:block; border:0; border-radius:12px;" />
                                    </td>
                                    <td class="text-cell" style="vertical-align:middle; padding-left:16px;">
                                        <h1 style="margin:0; font-size:24px; color:#00529B; font-weight:700; letter-spacing:-0.5px;">ProSalud</h1>
                                        <p style="margin:6px 0 0; color:#64748b; font-size:14px; font-weight:500;">Actualización de estado de solicitud</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td class="content" style="padding:32px;">
                            @php
                                $statusChange = $changes['status'] ?? null;
                                $oldStatus = $statusChange['old'] ?? null;
                                $newStatus = $statusChange['new'] ?? null;

                                $statusTexts = [
                                    'pending' => 'Pendiente',
                                    'in_progress' => 'En revisión',
                                    'resolved' => 'Aprobada',
                                    'rejected' => 'Rechazada',
                                ];

                                $oldStatusText = $statusTexts[$oldStatus] ?? ucfirst($oldStatus);
                                $newStatusText = $statusTexts[$newStatus] ?? ucfirst($newStatus);

                                // Determine badge color based on status
                                $statusConfig = [
                                    'pending' => ['bg' => '#fef3c7', 'text' => '#92400e'],
                                    'in_progress' => ['bg' => '#eff6ff', 'text' => '#1d4ed8'],
                                    'resolved' => ['bg' => '#d1fae5', 'text' => '#065f46'],
                                    'rejected' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
                                ];

                                $oldConfig = $statusConfig[$oldStatus] ?? $statusConfig['pending'];
                                $config = $statusConfig[$newStatus] ?? $statusConfig['pending'];

                                // Determine main message based on status
                                $mainMessages = [
                                    'resolved' => [
                                        'message' => 'Nos complace informarte que tu solicitud de actividad de bienestar ha sido <strong>aprobada</strong>.',
                                        'detail' => 'Tu solicitud ha sido revisada y aprobada por nuestro equipo. Procederemos con la organización de la actividad según lo planificado.'
                                    ],
                                    'rejected' => [
                                        'message' => 'Te informamos que tu solicitud de actividad de bienestar ha sido <strong>rechazada</strong>.',
                                        'detail' => 'Tu solicitud ha sido revisada pero no pudo ser aprobada. Si tienes alguna pregunta, por favor contacta a nuestro equipo de Talento Humano.'
                                    ],
                                    'in_progress' => [
                                        'message' => 'Tu solicitud de actividad de bienestar está siendo <strong>revisada</strong> por nuestro equipo.',
                                        'detail' => 'Estamos evaluando tu solicitud y te notificaremos cuando haya un cambio en el estado.'
                                    ],
                                    'pending' => [
                                        'message' => 'Tu solicitud de actividad de bienestar está <strong>pendiente</strong> de revisión.',
                                        'detail' => 'Tu solicitud ha sido recibida y será revisada próximamente por nuestro equipo.'
                                    ],
                                ];

                                $mainMessage = $mainMessages[$newStatus] ?? $mainMessages['pending'];
                            @endphp

                            <!-- Main Message -->
                            <div style="background:#f8f9fa; padding:20px; border-radius:12px; margin:0 0 24px; border-left: 4px solid #00529B;">
                                <p style="margin:0 0 12px; color:#1e293b; font-size:16px; line-height:24px;">
                                    {!! $mainMessage['message'] !!}
                                </p>
                                <p style="margin:0; color:#64748b; font-size:14px; line-height:20px;">
                                    {!! $mainMessage['detail'] !!}
                                </p>
                            </div>

                            <!-- Request Information -->
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
                                        <strong>#{{ $wellnessRequest->id }}</strong>
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
                                <tr>
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Solicitante:
                                    </td>
                                    <td style="padding:12px 16px; color:#1e293b; font-size:14px;">
                                        {{ $wellnessRequest->requester->name ?? 'N/A' }} (ID: {{ $wellnessRequest->requester_id }})
                                    </td>
                                </tr>
                                <tr style="background:#ffffff;">
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px;">
                                        Estado Anterior:
                                    </td>
                                    <td style="padding:12px 16px;">
                                        <span style="background:{{ $oldConfig['bg'] }}; color:{{ $oldConfig['text'] }}; padding:4px 12px; border-radius:6px; font-weight:600; font-size:12px; text-transform:uppercase;">
                                            {{ $oldStatusText }}
                                        </span>
                                    </td>
                                </tr>
                                <tr style="background:#ffffff;">
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px;">
                                        Estado Actual:
                                    </td>
                                    <td style="padding:12px 16px;">
                                        <span style="background:{{ $config['bg'] }}; color:{{ $config['text'] }}; padding:4px 12px; border-radius:6px; font-weight:600; font-size:12px; text-transform:uppercase;">
                                            {{ $newStatusText }}
                                        </span>
                                    </td>
                                </tr>
                            </table>

                            @if($newStatus === 'resolved')
                            <div style="background: linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%); padding:16px 20px; border-radius:12px; margin:0 0 24px; border-left: 4px solid #10b981;">
                                <p style="margin:0; color:#065f46; font-size:14px; line-height:20px; font-weight:600;">
                                    Tu solicitud ha sido aprobada. Nuestro equipo se pondrá en contacto contigo para coordinar los detalles de la actividad.
                                </p>
                            </div>
                            @elseif($newStatus === 'rejected')
                            <div style="background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); padding:16px 20px; border-radius:12px; margin:0 0 24px; border-left: 4px solid #ef4444;">
                                <p style="margin:0; color:#991b1b; font-size:14px; line-height:20px; font-weight:600;">
                                    Si tienes alguna pregunta sobre esta decisión o deseas más información, por favor contacta a nuestro equipo de Talento Humano.
                                </p>
                            </div>
                            @endif

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
                                Fecha de actualización: {{ $wellnessRequest->updated_at->format('d/m/Y H:i') }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

