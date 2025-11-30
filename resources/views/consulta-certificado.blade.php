<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultar Certificado de Convenio - ProSalud</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 24px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }

        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .loading {
            display: none;
            text-align: center;
            margin-top: 20px;
        }

        .loading.active {
            display: block;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .error {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-top: 20px;
            display: none;
            font-size: 14px;
        }

        .error.active {
            display: block;
        }

        .success {
            background: #efe;
            color: #3c3;
            padding: 12px;
            border-radius: 8px;
            margin-top: 20px;
            display: none;
            font-size: 14px;
        }

        .success.active {
            display: block;
        }

        .certificate-info {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            display: none;
            font-size: 14px;
            border-left: 4px solid #667eea;
        }

        .certificate-info.active {
            display: block;
        }

        .certificate-info h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .certificate-info p {
            margin-bottom: 5px;
            color: #666;
        }

        .certificate-info strong {
            color: #333;
        }

        .view-pdf-btn {
            margin-top: 15px;
            background: #28a745;
            padding: 10px 20px;
            font-size: 14px;
        }

        .view-pdf-btn:hover:not(:disabled) {
            background: #218838;
        }

        .note {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 12px;
            color: #666;
            border-left: 4px solid #667eea;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Consultar Certificado de Convenio</h1>
        <p class="subtitle">Ingrese el número de documento del afiliado y el consecutivo del certificado para validar su autenticidad</p>

        <form id="consultaForm">
            <div class="form-group">
                <label for="documento">Número de Documento</label>
                <input 
                    type="text" 
                    id="documento" 
                    name="documento" 
                    placeholder="Ej: 1036965209"
                    required
                    autocomplete="off"
                >
            </div>

            <div class="form-group">
                <label for="consecutivo">Número Consecutivo del Certificado</label>
                <input 
                    type="text" 
                    id="consecutivo" 
                    name="consecutivo" 
                    placeholder="Ej: 202411290001"
                    required
                    autocomplete="off"
                >
            </div>

            <button type="submit" id="submitBtn">
                Consultar Certificado
            </button>
        </form>

        <div class="loading" id="loading">
            <div class="spinner"></div>
            <p>Consultando certificado...</p>
        </div>

        <div class="error" id="error"></div>
        <div class="success" id="success"></div>

        <div class="certificate-info" id="certificateInfo">
            <h3>Información del Certificado</h3>
            <p><strong>Documento:</strong> <span id="infoDocumento"></span></p>
            <p><strong>Consecutivo:</strong> <span id="infoConsecutivo"></span></p>
            <p><strong>Fecha de Generación:</strong> <span id="infoFecha"></span></p>
            <button type="button" class="view-pdf-btn" id="viewPdfBtn" onclick="viewPDF()">
                Ver PDF en Nueva Pestaña
            </button>
        </div>

        <div class="note">
            <strong>Nota:</strong> Esta es una página temporal para pruebas. Ingrese el número de documento del afiliado y el consecutivo del certificado para validar su autenticidad y visualizar el PDF.
        </div>
    </div>

    <script>
        let pdfUrl = null;

        const form = document.getElementById('consultaForm');
        const submitBtn = document.getElementById('submitBtn');
        const loading = document.getElementById('loading');
        const error = document.getElementById('error');
        const success = document.getElementById('success');
        const certificateInfo = document.getElementById('certificateInfo');
        const documentoInput = document.getElementById('documento');
        const consecutivoInput = document.getElementById('consecutivo');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Limpiar mensajes anteriores
            error.classList.remove('active');
            success.classList.remove('active');
            certificateInfo.classList.remove('active');
            error.textContent = '';
            success.textContent = '';
            pdfUrl = null;

            // Validar campos
            const documento = documentoInput.value.trim();
            const consecutivo = consecutivoInput.value.trim();

            if (!documento) {
                showError('Por favor ingrese un número de documento');
                return;
            }

            if (!consecutivo) {
                showError('Por favor ingrese un número consecutivo');
                return;
            }

            // Deshabilitar botón y mostrar loading
            submitBtn.disabled = true;
            loading.classList.add('active');

            try {
                const response = await fetch('/api/certificados/convenio/consultar', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        documento: documento,
                        consecutivo: consecutivo
                    })
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || `Error ${response.status}: ${response.statusText}`);
                }

                // Mostrar información del certificado
                pdfUrl = data.data.pdf_url;
                document.getElementById('infoDocumento').textContent = data.data.document_number;
                document.getElementById('infoConsecutivo').textContent = data.data.consecutivo;
                
                // Formatear fecha
                const fecha = new Date(data.data.generated_at);
                document.getElementById('infoFecha').textContent = fecha.toLocaleDateString('es-ES', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });

                certificateInfo.classList.add('active');
                success.textContent = '¡Certificado encontrado y verificado exitosamente!';
                success.classList.add('active');

            } catch (err) {
                console.error('Error:', err);
                showError(err.message || 'Error al consultar el certificado. Por favor intenta nuevamente.');
            } finally {
                submitBtn.disabled = false;
                loading.classList.remove('active');
            }
        });

        function showError(message) {
            error.textContent = message;
            error.classList.add('active');
        }

        function viewPDF() {
            if (pdfUrl) {
                window.open(pdfUrl, '_blank');
            } else {
                showError('No hay URL disponible para visualizar el PDF');
            }
        }

        // Permitir Enter para enviar
        document.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                form.dispatchEvent(new Event('submit'));
            }
        });
    </script>
</body>
</html>

