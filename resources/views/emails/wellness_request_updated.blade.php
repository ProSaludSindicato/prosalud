<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Actualización de solicitud de bienestar - ProSalud</title>
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
                                        <img src="https://www.prosalud.org.co/images/logo_prosalud_fondo.png" alt="ProSalud" width="108" height="80" style="display:block; border:0; border-radius:12px;" />
                                    </td>
                                    <td class="text-cell" style="vertical-align:middle; padding-left:16px;">
                                        <h1 style="margin:0; font-size:24px; color:#00529B; font-weight:700; letter-spacing:-0.5px;">ProSalud</h1>
                                        <p style="margin:6px 0 0; color:#64748b; font-size:14px; font-weight:500;">Actualización de solicitud de bienestar</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td class="content" style="padding:32px;">
                            <p style="margin:0 0 24px; color:#1e293b; font-size:16px; line-height:24px;">
                                Se ha actualizado la solicitud de actividad de bienestar <strong>#{{ $wellnessRequest->id }}</strong>.<br> A continuación se detallan los cambios realizados:
                            </p>

                            <!-- Changes Table -->
                            <table class="info-table" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f8f9fa; border-radius:12px; overflow:hidden; margin-bottom:24px;">
                                <tr style="background:#00529B;">
                                    <td colspan="2" style="padding:16px; color:#ffffff; font-weight:700; font-size:16px;">
                                        Campos Actualizados
                                    </td>
                                </tr>
                                @foreach($changes as $field => $change)
                                    @php
                                        $oldValue = $change['old'] ?? null;
                                        $newValue = $change['new'] ?? null;
                                        $fieldLabel = \App\Support\WellnessRequestChangeLabels::fieldLabel($field);
                                    @endphp
                                    <tr>
                                        <td style="padding:12px 16px; color:#64748b; font-weight:600; font-size:14px; width:40%; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                                            {{ $fieldLabel }}:
                                        </td>
                                        <td style="padding:12px 16px; color:#1e293b; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                            <div style="margin-bottom:8px;">
                                                <div style="font-size:12px; color:#64748b; margin-bottom:4px;">❌ Valor anterior:</div>
                                                <div style="background:#fee2e2; padding:6px 10px; border-radius:6px; border-left:3px solid #dc2626;">
                                                    {!! \App\Support\WellnessRequestChangeLabels::formatFieldValue($field, $oldValue) !!}
                                                </div>
                                            </div>
                                            <div>
                                                <div style="font-size:12px; color:#64748b; margin-bottom:4px;">✅ Valor nuevo:</div>
                                                <div style="background:#d1fae5; padding:6px 10px; border-radius:6px; border-left:3px solid #10b981;">
                                                    {!! \App\Support\WellnessRequestChangeLabels::formatFieldValue($field, $newValue) !!}
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </table>

                            @if(($oldDetails !== null && count($oldDetails) > 0) || ($newDetails !== null && count($newDetails) > 0))
                            <!-- Details Changes Table -->
                            <table class="info-table" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f8f9fa; border-radius:12px; overflow:hidden; margin-bottom:24px;">
                                <tr style="background:#00529B;">
                                    <td colspan="2" style="padding:16px; color:#ffffff; font-weight:700; font-size:16px;">
                                        🎁 Cambios en Detalles / Souvenirs
                                    </td>
                                </tr>

                                @if($oldDetails !== null && count($oldDetails) > 0)
                                <tr>
                                    <td colspan="2" style="padding:12px 16px; background:#fee2e2; border-bottom:1px solid #e5e7eb;">
                                        <div style="font-size:13px; color:#92400e; font-weight:600; margin-bottom:8px;">❌ Detalles anteriores:</div>
                                        <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                            <tr style="background:#e5e7eb;">
                                                <td style="padding:8px 12px; font-weight:700; font-size:12px; color:#1e293b;">Tipo</td>
                                                <td style="padding:8px 12px; font-weight:700; font-size:12px; color:#1e293b; text-align:right;">Cantidad</td>
                                            </tr>
                                            @foreach($oldDetails as $detail)
                                            <tr>
                                                <td style="padding:8px 12px; font-size:13px; color:#1e293b; border-bottom:1px solid #fca5a5;">{{ $detail['type'] ?? $detail['tipo'] ?? 'N/A' }}</td>
                                                <td style="padding:8px 12px; font-size:13px; color:#1e293b; border-bottom:1px solid #fca5a5; text-align:right;">{{ number_format($detail['quantity'] ?? $detail['cantidad'] ?? 0, 0, ',', '.') }}</td>
                                            </tr>
                                            @endforeach
                                        </table>
                                    </td>
                                </tr>
                                @endif

                                @if($newDetails !== null && count($newDetails) > 0)
                                <tr>
                                    <td colspan="2" style="padding:12px 16px; background:#d1fae5; border-bottom:1px solid #e5e7eb;">
                                        <div style="font-size:13px; color:#065f46; font-weight:600; margin-bottom:8px;">✅ Detalles nuevos:</div>
                                        <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                            <tr style="background:#e5e7eb;">
                                                <td style="padding:8px 12px; font-weight:700; font-size:12px; color:#1e293b;">Tipo</td>
                                                <td style="padding:8px 12px; font-weight:700; font-size:12px; color:#1e293b; text-align:right;">Cantidad</td>
                                            </tr>
                                            @foreach($newDetails as $detail)
                                            <tr>
                                                <td style="padding:8px 12px; font-size:13px; color:#1e293b; border-bottom:1px solid #86efac;">{{ $detail['type'] ?? $detail['tipo'] ?? 'N/A' }}</td>
                                                <td style="padding:8px 12px; font-size:13px; color:#1e293b; border-bottom:1px solid #86efac; text-align:right;">{{ number_format($detail['quantity'] ?? $detail['cantidad'] ?? 0, 0, ',', '.') }}</td>
                                            </tr>
                                            @endforeach
                                        </table>
                                    </td>
                                </tr>
                                @elseif($oldDetails !== null && count($oldDetails) > 0 && ($newDetails === null || count($newDetails) === 0))
                                <tr>
                                    <td colspan="2" style="padding:12px 16px; background:#d1fae5;">
                                        <div style="font-size:13px; color:#065f46; font-weight:600;">✅ Se han eliminado todos los detalles/souvenirs</div>
                                    </td>
                                </tr>
                                @endif
                            </table>
                            @endif

                            <!-- Current Status Info -->
                            <div style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); padding:16px 20px; border-radius:12px; margin:0 0 24px; border-left: 4px solid #00529B;">
                                <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                    <tr>
                                        <td style="font-size:13px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Estado Actual</td>
                                        <td style="text-align:right;">
                                            <span style="background:#fef3c7; color:#92400e; padding:4px 12px; border-radius:6px; font-weight:600; font-size:12px; text-transform:uppercase;">
                                                {{ $wellnessRequest->status_text }}
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <p style="margin:0 0 24px; color:#4b5563; font-size:15px; line-height:1.7;">
                                Los cambios han sido guardados exitosamente en el sistema. Por favor, revisa la información actualizada y realiza las acciones correspondientes si es necesario.
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

