<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Actualización solicitud hospitalaria</title>
</head>
<body style="margin:0; padding:0; background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding:40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width: 600px; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow: 0 10px 40px rgba(0,82,155,0.12);">
                    <tr>
                        <td style="background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); padding:32px 32px 24px; border-bottom: 1px solid #e5e7eb;">
                            <table width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate;">
                                <tr>
                                    <td style="vertical-align:middle; width:120px;">
                                        <img src="https://www.prosalud.org.co/images/logo_prosalud_fondo.png" alt="ProSalud" width="108" height="80" style="display:block; border:0; border-radius:12px;" />
                                    </td>
                                    <td style="vertical-align:middle; padding-left:16px;">
                                        <h1 style="margin:0; font-size:24px; color:#00529B; font-weight:700; letter-spacing:-0.5px;">ProSalud</h1>
                                        <p style="margin:6px 0 0; color:#64748b; font-size:14px; font-weight:500;">Actualización de solicitud hospitalaria</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            @php
                                $previousLabel = $statusLabels[$previousStatus] ?? ucfirst($previousStatus);
                                $newLabel = $statusLabels[$newStatus] ?? ucfirst($newStatus);
                                $timeline = $hospitalRequest->timeline ?? collect();
                                $items = $hospitalRequest->items ?? collect();
                                $statusStyles = $statusStyles ?? [];
                                $defaultStyle = ['bg' => '#f8fafc', 'text' => '#1e293b', 'border' => '#e2e8f0'];
                                $previousStyle = $statusStyles[$previousStatus] ?? $defaultStyle;
                                $newStyle = $statusStyles[$newStatus] ?? $defaultStyle;
                            @endphp

                            <p style="margin:0 0 18px; color:#334155; font-size:15px; line-height:1.7;">
                                Te informamos que se registró un cambio en el estado de la solicitud de inventario hospitalario número <strong style="color:#1f2937;">#{{ $hospitalRequest->id }}</strong>.
                            </p>

                            <div style="background:#f8fafc; padding:20px; border-radius:16px; margin:0 0 24px; border:1px solid #e2e8f0;">
                                <table width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate;">
                                    <tr>
                                        <td style="padding-bottom:16px;">
                                            <p style="margin:0 0 8px; color:#475569; font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Estado anterior</p>
                                            <span style="display:inline-block; padding:6px 16px; border-radius:999px; font-weight:600; font-size:12px; text-transform:uppercase; background:{{ $previousStyle['bg'] }}; color:{{ $previousStyle['text'] }}; border:1px solid {{ $previousStyle['border'] }};">
                                                {{ $previousLabel }}
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <p style="margin:0 0 8px; color:#475569; font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Estado actual</p>
                                            <span style="display:inline-block; padding:6px 16px; border-radius:999px; font-weight:600; font-size:12px; text-transform:uppercase; background:{{ $newStyle['bg'] }}; color:{{ $newStyle['text'] }}; border:1px solid {{ $newStyle['border'] }};">
                                                {{ $newLabel }}
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <table width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate; margin-bottom:24px;">
                                <tr>
                                    <td colspan="2" style="background:#00529B; color:#ffffff; font-weight:700; padding:16px 20px; font-size:15px; border-radius:16px 16px 0 0;">
                                        Información de la solicitud
                                    </td>
                                </tr>
                                <tr style="background:#ffffff;">
                                    <td style="width:38%; padding:14px 20px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Hospital
                                    </td>
                                    <td style="padding:14px 20px; color:#1f2937; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ $hospitalRequest->hospital_name ?? 'Sin información' }}
                                    </td>
                                </tr>
                                <tr style="background:#f8fafc;">
                                    <td style="width:38%; padding:14px 20px; color:#64748b; font-weight:600; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        Solicitado por
                                    </td>
                                    <td style="padding:14px 20px; color:#1f2937; font-size:14px; border-bottom:1px solid #e5e7eb;">
                                        {{ $hospitalRequest->requested_by ?? 'Sin información' }}
                                    </td>
                                </tr>
                                <tr style="background:#ffffff;">
                                    <td style="width:38%; padding:14px 20px; color:#64748b; font-weight:600; font-size:14px;">
                                        Observaciones
                                    </td>
                                    <td style="padding:14px 20px; color:#1f2937; font-size:14px;">
                                        {{ $hospitalRequest->observations ?? 'Sin observaciones registradas' }}
                                    </td>
                                </tr>
                            </table>

                            @if($items->count() > 0)
                                <table width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate; margin-bottom:24px;">
                                    <tr>
                                        <td colspan="3" style="background:#00529B; color:#ffffff; font-weight:700; padding:16px 20px; font-size:15px; border-radius:16px 16px 0 0;">
                                            Ítems solicitados
                                        </td>
                                    </tr>
                                    @foreach($items as $item)
                                        <tr style="background:{{ $loop->even ? '#f8fafc' : '#ffffff' }};">
                                            <td style="padding:14px 20px; color:#1f2937; font-size:14px;">
                                                {{ $item->product->name ?? $item->variant_label ?? 'Producto sin nombre' }}
                                            </td>
                                            <td style="padding:14px 20px; color:#475569; font-size:14px; text-align:center; width:80px;">
                                                x{{ $item->quantity }}
                                            </td>
                                            <td style="padding:14px 20px; color:#64748b; font-size:13px; width:180px;">
                                                {{ $item->variant_label ?? '' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            <p style="margin:0 0 24px; color:#64748b; font-size:13px; line-height:1.7;">
                                Si necesitas información adicional, por favor contacta al equipo de Talento humano.
                            </p>

                            <p style="margin:16px 0 0; font-size:15px; color:#4b5563; line-height:1.7;">
                                Saludos cordiales,<br>
                                <strong style="color:#1f2937;">Equipo ProSalud</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f8f9fa; padding:24px 32px; border-top:1px solid #e5e7eb; text-align:center;">
                            <p style="margin:0; color:#64748b; font-size:12px; line-height:18px;">
                                Este es un correo automático generado por el sistema ProSalud. Por favor, no respondas a este mensaje ya que el buzón no es monitoreado.<br />
                                Última actualización: {{ optional($hospitalRequest->updated_at)->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}
                            </p>
                            <p style="margin:16px 0 0; color:#94a3b8; font-size:12px;">
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

