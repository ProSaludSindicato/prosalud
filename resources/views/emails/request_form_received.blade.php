<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Confirmación de solicitud - ProSalud</title>
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
                                        <p style="margin:6px 0 0; color:#64748b; font-size:14px; font-weight:500;">Confirmación de solicitud recibida</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Success Badge -->
                    <tr>
                        <td style="padding:16px 32px 16px 32px; background:#ffffff;">
                            <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td>
                                        <table width="100%" cellspacing="0" cellpadding="0" border="0" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius:12px; box-shadow: 0 4px 12px rgba(16,185,129,0.25);">
                                            <tr>
                                                <td style="padding:12px 20px; text-align:center;">
                                                    <span style="color:#ffffff; font-size:14px; font-weight:600;">✓ Solicitud registrada exitosamente</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td class="content" style="padding:8px 32px 32px;">
                            <p style="margin:0 0 20px; font-size:16px; color:#1f2937; line-height:1.6;">
                                Hola <strong style="color:#00529B;">{{ $requestForm->full_name }}</strong>,
                            </p>

                            <p style="margin:0 0 24px; font-size:15px; color:#4b5563; line-height:1.7;">
                                Hemos recibido tu solicitud correctamente y ya ha sido registrada en nuestro sistema.
                                A continuación encontrarás un resumen de la información:
                            </p>

                            <!-- Request ID Highlight -->
                            <div style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); padding:16px 20px; border-radius:12px; margin:0 0 24px; border-left: 4px solid #00529B;">
                                <table width="100%" cellspacing="0" cellpadding="0" border="0">
                                    <tr>
                                        <td style="font-size:13px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">ID de Solicitud</td>
                                        <td style="text-align:right; font-size:20px; color:#00529B; font-weight:700;">#{{ $requestForm->id }}</td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Main Info Table -->
                            <h3 style="margin:0 0 16px; font-size:16px; color:#1f2937; font-weight:700;">Información de la solicitud</h3>
                            <table class="info-table" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 28px; background:#ffffff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
                                <tr>
                                    <td style="padding:14px 18px; font-size:13px; color:#6b7280; font-weight:600; width:40%; background:#f9fafb; border-bottom:1px solid #e5e7eb;">Tipo de solicitud</td>
                                    <td style="padding:14px 18px; font-size:14px; color:#1f2937; border-bottom:1px solid #e5e7eb;">{{ $requestForm->request_type }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 18px; font-size:13px; color:#6b7280; font-weight:600; background:#f9fafb; border-bottom:1px solid #e5e7eb;">Documento</td>
                                    <td style="padding:14px 18px; font-size:14px; color:#1f2937; border-bottom:1px solid #e5e7eb;">{{ $requestForm->document_type }} {{ $requestForm->document_number }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 18px; font-size:13px; color:#6b7280; font-weight:600; background:#f9fafb; border-bottom:1px solid #e5e7eb;">Correo electrónico</td>
                                    <td style="padding:14px 18px; font-size:14px; color:#1f2937; border-bottom:1px solid #e5e7eb;">{{ $requestForm->email }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 18px; font-size:13px; color:#6b7280; font-weight:600; background:#f9fafb; border-bottom:1px solid #e5e7eb;">Teléfono</td>
                                    <td style="padding:14px 18px; font-size:14px; color:#1f2937; border-bottom:1px solid #e5e7eb;">{{ $requestForm->phone_number }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 18px; font-size:13px; color:#6b7280; font-weight:600; background:#f9fafb; border-bottom:1px solid #e5e7eb;">Estado</td>
                                    <td style="padding:14px 18px; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        @php
                                            $status = strtolower($requestForm->status);
                                            $statusColors = [
                                                'pending' => ['bg' => '#fef3c7', 'text' => '#92400e'],
                                                'in_review' => ['bg' => '#eff6ff', 'text' => '#1d4ed8'],
                                                'processed' => ['bg' => '#f0fdf4', 'text' => '#15803d'],
                                                'completed' => ['bg' => '#f0fdf4', 'text' => '#15803d'],
                                                'rejected' => ['bg' => '#fef2f2', 'text' => '#dc2626']
                                            ];
                                            $colors = $statusColors[$status] ?? ['bg' => '#f9fafb', 'text' => '#374151'];
                                        @endphp
                                        <span style="background: {{ $colors['bg'] }}; color: {{ $colors['text'] }}; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600;">{{ $requestForm->translated_status }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 18px; font-size:13px; color:#6b7280; font-weight:600; background:#f9fafb;">Fecha de creación</td>
                                    <td style="padding:14px 18px; font-size:14px; color:#1f2937;">{{ $requestForm->formatted_created_at ?? $requestForm->created_at }}</td>
                                </tr>
                            </table>

                            @php
                            $payload = is_array($requestForm->payload ?? null) ? $requestForm->payload : [];
                            $payloadSummary = [];
                            $preferredKeys = [
                                'proceso', 'dondeRealizaProceso', 'motivoSolicitud',
                                'dirigidoAQuien', 'tipoVehiculo', 'placaVehiculo',
                                'infoCertificado', 'otrosDescripcion'
                            ];
                            foreach ($preferredKeys as $key) {
                                if (isset($payload[$key]) && $payload[$key] !== '') {
                                    $payloadSummary[$key] = $payload[$key];
                                }
                            }
                            if (empty($payloadSummary)) {
                                // fallback: take first 5 entries
                                $payloadSummary = array_slice($payload, 0, 5, true);
                            }
                            @endphp

                            @if(!empty($payloadSummary))
                            <!-- Process Details -->
                            <h3 style="margin:0 0 16px; font-size:16px; color:#1f2937; font-weight:700;">Detalles del proceso</h3>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 28px; background:#ffffff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
                                @foreach($payloadSummary as $k => $v)
                                <tr>
                                    <td style="padding:14px 18px; font-size:13px; color:#6b7280; font-weight:600; width:40%; background:#f9fafb; border-bottom:1px solid #e5e7eb;">{{ $requestForm->formatFieldName($k) }}</td>
                                    <td style="padding:14px 18px; font-size:14px; color:#1f2937; border-bottom:1px solid #e5e7eb;">{!! $requestForm->formatPayloadValue($k, $v) !!}</td>
                                </tr>
                                @endforeach
                            </table>
                            @endif

                            <!-- Next Steps -->
                            <p style="margin:0 0 24px; font-size:15px; color:#4b5563; line-height:1.7;">
                                Nuestro equipo de Talento Humano está revisando tu solicitud y se pondrá en contacto contigo si es necesario ampliar o verificar algún dato. Te notificaremos oportunamente cuando el estado de tu solicitud cambie.
                            </p>

                            <!-- Important Notice -->
                            <div style="padding:8px 0 0; margin:0;">
                                <p style="margin:0; font-size:14px; color:#4b5563; line-height:1.6;">
                                    <strong style="font-weight:700; color:#1f2937;">Importante:</strong> Conserva el número de tu solicitud <strong style="color:#00529B;">#{{ $requestForm->id }}</strong> para futuras consultas o seguimientos.
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
                                            Este es un mensaje automático generado por el sistema de gestión de solicitudes.
                                        </p>
                                        <p style="margin:0 0 12px; font-size:13px; color:#64748b; line-height:1.6;">
                                            Si tienes alguna pregunta, comunícate con nuestro equipo de soporte a través de los canales oficiales.
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
