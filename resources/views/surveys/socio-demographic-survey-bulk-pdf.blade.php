<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Encuestas Sociodemográficas y Diagnóstico de Condiciones de Salud - Reporte Masivo</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-size: 9px;
            line-height: 1.3;
            color: #1e293b;
            background: white;
            padding: 20px;
        }

        body * {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
        }

        strong {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-weight: 600;
        }

        .container {
            max-width: 100%;
            background: white;
            padding: 20px;
        }

        .survey-wrapper {
            page-break-after: always;
        }

        .survey-wrapper:last-child {
            page-break-after: auto;
        }

        .header {
            border-bottom: 2px solid #00529B;
            padding-bottom: 10px;
            margin-bottom: 12px;
            position: relative;
        }

        .header h1 {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            color: #00529B;
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .header-logo {
            position: absolute;
            top: 0;
            right: 0;
            max-width: 80px;
            max-height: 60px;
        }

        .header-generated-date {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-size: 7px;
            color: #64748b;
            margin-top: 4px;
        }

        .header-info {
            display: table;
            width: 100%;
            table-layout: fixed;
            margin-top: 16px;
        }

        .header-info-row {
            display: table-row;
        }

        .header-info-item {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            display: table-cell;
            padding: 2px 8px 2px 0;
            font-size: 8px;
            vertical-align: top;
        }

        .badge {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 8px;
            font-weight: 600;
            border: 1px solid #cbd5e1;
        }

        .badge-new-entry {
            background-color: #fff7ed;
            color: #c2410c;
            border-color: #fed7aa;
        }

        .badge-active {
            background-color: #E3F2FD;
            color: #003A70;
            border-color: #00529B;
        }

        .section {
            margin-bottom: 10px;
            page-break-inside: avoid;
        }

        .section-title {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-size: 11px;
            font-weight: bold;
            color: #00529B;
            margin-bottom: 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e2e8f0;
        }

        .grid {
            display: table;
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
        }

        .grid-row {
            display: table-row;
        }

        .field {
            display: table-cell;
            padding: 4px 8px 4px 0;
            vertical-align: top;
            width: 33.33%;
        }

        .field-4cols {
            width: 25%;
        }

        .field-2cols {
            width: 50%;
        }

        .field-half {
            width: 50%;
        }

        .field-label {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-size: 7px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 2px;
            text-transform: uppercase;
            line-height: 1.2;
        }

        .field-value {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-size: 9px;
            color: #1e293b;
            word-wrap: break-word;
            line-height: 1.3;
        }

        .field-value-bold {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-weight: 600;
        }

        .si-no {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            display: inline-block;
            font-weight: 600;
            font-size: 8px;
        }

        .si-no.si {
            color: #16a34a;
        }

        .si-no.no {
            color: #dc2626;
        }

        .si-no.no-especificado {
            color: #64748b;
        }

        .badge-item {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            display: inline-block;
            padding: 1px 4px;
            margin: 1px;
            background: #E3F2FD;
            color: #003A70;
            border-radius: 2px;
            font-size: 7px;
            border: 1px solid #00529B;
        }

        .signature-container {
            margin-top: 8px;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            background: #f8fafc;
            text-align: center;
        }

        .signature-image {
            max-width: 250px;
            max-height: 100px;
            margin: 5px 0;
        }

        .no-signature {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            color: #64748b;
            font-style: italic;
            padding: 10px;
            font-size: 8px;
        }

        .limitacion-value {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-weight: 600;
            font-size: 9px;
            padding: 2px 6px;
            border-radius: 3px;
            display: inline-block;
        }

        .limitacion-no-limita {
            color: #16a34a;
            background-color: #dcfce7;
            border: 1px solid #86efac;
        }

        .limitacion-limita-poco {
            color: #d97706;
            background-color: #fff7ed;
            border: 1px solid #fed7aa;
        }

        .limitacion-limita-mucho {
            color: #dc2626;
            background-color: #fee2e2;
            border: 1px solid #fca5a5;
        }

        .children-section {
            margin-top: 6px;
            padding: 6px;
            background: #f8fafc;
            border-radius: 3px;
            border: 1px solid #e2e8f0;
        }

        .child-item {
            margin-bottom: 6px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
        }

        .child-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .details-section {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
        }

        .details-title {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif !important;
            font-size: 9px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 6px;
        }

        .two-columns {
            width: 50%;
        }

        .four-columns {
            width: 25%;
        }

        @page {
            margin: 2cm 1.5cm;
        }
    </style>
</head>
<body>
    @foreach($surveysWithSignatures as $index => $surveyData)
        @php
            $survey = $surveyData['survey'];
            $signatureImageBase64 = $surveyData['signatureImageBase64'];
        @endphp
        <div class="survey-wrapper">
            <div class="container">
                @include('surveys._survey-content', [
                    'survey' => $survey,
                    'signatureImageBase64' => $signatureImageBase64,
                    'logoBase64' => $logoBase64,
                    'generatedAt' => $generatedAt
                ])
            </div>
        </div>
    @endforeach
</body>
</html>

