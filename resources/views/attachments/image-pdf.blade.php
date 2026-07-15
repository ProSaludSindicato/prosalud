<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Adjunto</title>
    <style>
        @page {
            margin: 24px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica', 'Arial', 'DejaVu Sans', sans-serif;
            background: #ffffff;
            text-align: center;
        }

        img {
            max-width: 92%;
            max-height: 700px;
            height: auto;
            margin-top: 8px;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <img src="data:{{ $imageMimeType }};base64,{{ $imageBase64 }}" alt="Adjunto">
</body>
</html>
