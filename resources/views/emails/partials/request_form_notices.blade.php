@php
    $variants = [
        'amber' => [
            'background' => '#fffbeb',
            'border' => '#fde68a',
            'text' => '#92400e',
        ],
        'gray' => [
            'background' => '#f9fafb',
            'border' => '#e5e7eb',
            'text' => '#374151',
        ],
    ];
@endphp

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px;">
    @foreach($notices as $notice)
        @php
            $colors = $variants[$notice['variant']] ?? $variants['gray'];
        @endphp
        <tr>
            <td style="padding:0 0 12px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:{{ $colors['background'] }}; border:1px solid {{ $colors['border'] }}; border-radius:12px;">
                    <tr>
                        <td style="padding:16px 18px; font-size:14px; color:{{ $colors['text'] }}; line-height:1.7;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="width:28px; vertical-align:top; font-size:16px; line-height:1.4;">{{ $notice['icon'] }}</td>
                                    <td style="vertical-align:top;">
                                        <strong style="font-weight:700;">{{ $notice['title'] }}</strong>
                                        {!! str_starts_with($notice['body'], '<') ? ' '.$notice['body'] : ' '.$notice['body'] !!}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    @endforeach
</table>
