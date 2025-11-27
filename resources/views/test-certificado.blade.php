<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Certificado de Convenio - Prueba</title>
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
            max-width: 500px;
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
        <h1>Generar Certificado de Convenio</h1>
        <p class="subtitle">Ingresa el número de documento del afiliado</p>

        <form id="certificadoForm">
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

            <button type="submit" id="submitBtn">
                Generar Certificado Word
            </button>
        </form>

        <div class="loading" id="loading">
            <div class="spinner"></div>
            <p>Generando certificado...</p>
        </div>

        <div class="error" id="error"></div>
        <div class="success" id="success"></div>

        <div class="note">
            <strong>Nota:</strong> Esta es una página temporal para pruebas. El certificado Word se descargará automáticamente cuando esté listo.
        </div>
    </div>

    <script>
        const form = document.getElementById('certificadoForm');
        const submitBtn = document.getElementById('submitBtn');
        const loading = document.getElementById('loading');
        const error = document.getElementById('error');
        const success = document.getElementById('success');
        const documentoInput = document.getElementById('documento');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Limpiar mensajes anteriores
            error.classList.remove('active');
            success.classList.remove('active');
            error.textContent = '';
            success.textContent = '';

            // Validar documento
            const documento = documentoInput.value.trim();
            if (!documento) {
                showError('Por favor ingresa un número de documento');
                return;
            }

            // Deshabilitar botón y mostrar loading
            submitBtn.disabled = true;
            loading.classList.add('active');

            try {
                const response = await fetch('/api/certificados/convenio/generar', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    },
                    body: JSON.stringify({
                        documento: documento
                    })
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({ message: 'Error desconocido' }));
                    throw new Error(errorData.message || `Error ${response.status}: ${response.statusText}`);
                }

                // Obtener el blob del Word
                const blob = await response.blob();
                
                // Crear URL temporal y descargar
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `certificado_convenio_${documento}_${new Date().toISOString().split('T')[0]}.docx`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);

                // Mostrar éxito
                success.textContent = '¡Certificado Word generado y descargado exitosamente!';
                success.classList.add('active');

                // Limpiar formulario después de 2 segundos
                setTimeout(() => {
                    documentoInput.value = '';
                    success.classList.remove('active');
                }, 3000);

            } catch (err) {
                console.error('Error:', err);
                showError(err.message || 'Error al generar el certificado. Por favor intenta nuevamente.');
            } finally {
                submitBtn.disabled = false;
                loading.classList.remove('active');
            }
        });

        function showError(message) {
            error.textContent = message;
            error.classList.add('active');
        }

        // Permitir Enter para enviar
        documentoInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                form.dispatchEvent(new Event('submit'));
            }
        });
    </script>
</body>
</html>

