<?php
require_once __DIR__ . '/../includes/db.php';

// Token desde GET y sanitizado
$token = filter_input(INPUT_GET, 'token', FILTER_SANITIZE_STRING);
if (!$token || strlen($token) < 10) {
    die("<p class=\"error\">Token inválido</p>");
}

// Obtener grupo
$stmt = $conn->prepare("
    SELECT id, nombre, empresa, lat, lng, localidad 
    FROM grupos 
    WHERE token = ? AND activo = 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$grupo = $result->fetch_assoc();
$stmt->close();

if (!$grupo) {
    die("<p class=\"error\">Grupo no encontrado o inactivo</p>");
}

// Obtener empleados activos del grupo
$stmt_emp = $conn->prepare("
    SELECT nombre, telefono, nss, curp
    FROM empleados 
    WHERE grupo_id = ? AND activo = 1 
    ORDER BY nombre
");
$stmt_emp->bind_param("i", $grupo['id']);
$stmt_emp->execute();
$empleados = $stmt_emp->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_emp->close();

$mensaje = '';
$tipo_mensaje = '';

// Procesar foto si se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['foto'])) {
    $empleado_nombre = trim($_POST['empleado_nombre'] ?? '');
    $empleado_telefono = trim($_POST['empleado_telefono'] ?? '');
    $lat = floatval($_POST['lat'] ?? 0);
    $lng = floatval($_POST['lng'] ?? 0);
    $direccion = trim($_POST['direccion'] ?? '');
    $tipo_asistencia = trim($_POST['tipo_asistencia'] ?? '');
    $motivo = trim($_POST['motivo'] ?? '');

    if ($empleado_nombre && $_FILES['foto']['error'] === UPLOAD_ERR_OK && $tipo_asistencia) {
        // Verificar que el empleado pertenece al grupo
        $stmt_verify = $conn->prepare("SELECT id FROM empleados WHERE grupo_id = ? AND nombre = ? AND activo = 1");
        $stmt_verify->bind_param("is", $grupo['id'], $empleado_nombre);
        $stmt_verify->execute();
        $empleado_exists = $stmt_verify->get_result()->fetch_assoc();
        $stmt_verify->close();

        if ($empleado_exists) {
            // Procesar foto y guardar asistencia
            require_once 'procesar_foto_asistencia.php';
            $resultado = procesarFotoAsistencia(
                $_FILES['foto'], $empleado_nombre, $empleado_telefono, $grupo['id'], $lat, $lng, $direccion,
                $tipo_asistencia, $motivo
            );

            if ($resultado['exito']) {
                $mensaje = "✅ {$tipo_asistencia} registrada correctamente para {$empleado_nombre}";
                $tipo_mensaje = 'success';
            } else {
                $mensaje = "❌ Error: " . $resultado['error'];
                $tipo_mensaje = 'error';
            }
        } else {
            $mensaje = "❌ Empleado no encontrado en este proyecto";
            $tipo_mensaje = 'error';
        }
    } else {
        $mensaje = "❌ Faltan datos obligatorios o error en la foto";
        $tipo_mensaje = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#667eea">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="../recursos/logo.png">
    <title>📸 Asistencia - <?= htmlspecialchars($grupo['nombre']) ?></title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 1rem;
        }
        
        .container {
            max-width: 500px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .offline-banner {
            display: none;
            gap: 12px;
            align-items: center;
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
            border-radius: 14px;
            font-size: 0.9rem;
            font-weight: 600;
            background: rgba(255, 193, 7, 0.18);
            color: #8a6d00;
        }

        .offline-banner.online {
            background: rgba(40, 167, 69, 0.12);
            color: #1c7430;
        }

        .offline-banner .queue-count {
            margin-left: auto;
            font-size: 0.8rem;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            background: rgba(0,0,0,0.08);
            font-weight: 700;
        }
        
        .header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 2rem 1.5rem;
            text-align: center;
        }
        
        .header h1 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .content {
            padding: 2rem 1.5rem;
        }
        
        .mensaje {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            text-align: center;
        }
        
        .mensaje.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .mensaje.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .form-group select {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            font-size: 1rem;
            background: white;
            transition: border-color 0.3s;
        }
        
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .camera-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            text-align: center;
            border: 2px dashed #dee2e6;
            transition: all 0.3s;
        }
        
        .camera-section:hover {
            border-color: #667eea;
            background: #f0f3ff;
        }
        
        .camera-input {
            display: none;
        }
        
        .camera-label {
            display: inline-block;
            padding: 1.5rem 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            cursor: pointer;
            font-size: 1.1rem;
            font-weight: 600;
            transition: transform 0.2s;
            border: none;
            width: 100%;
        }
        
        .camera-label:hover {
            transform: translateY(-2px);
        }
        
        .camera-label:active {
            transform: translateY(0);
        }
        
        .preview-container {
            margin-top: 1rem;
            display: none;
        }
        
        .preview-image {
            width: 100%;
            max-width: 300px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .location-info {
            background: #e8f5e8;
            padding: 1rem;
            border-radius: 10px;
            margin: 1rem 0;
            font-size: 0.9rem;
            color: #2d5a2d;
        }
        
        .location-info.error {
            background: #ffe6e6;
            color: #cc0000;
        }
        
        .location-info strong {
            display: block;
            margin-bottom: 0.5rem;
        }
        
        .submit-btn {
            width: 100%;
            padding: 1.5rem;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            border-radius: 15px;
            font-size: 1.2rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 1rem;
        }
        
        .submit-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.3);
        }
        
        .submit-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 1rem;
            color: #667eea;
        }
        
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 0.5rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .info-text {
            font-size: 0.85rem;
            color: #6c757d;
            text-align: center;
            margin-top: 1rem;
            line-height: 1.4;
        }
        
        .emergency-link {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            padding: 1rem;
            background: #dc3545;
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .emergency-link:hover {
            background: #c82333;
            color: white;
            text-decoration: none;
        }
        
        @media (max-width: 480px) {
            .container {
                margin: 0;
                border-radius: 0;
                min-height: 100vh;
            }
            
            .content {
                padding: 1.5rem 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📸 Registro de Asistencia</h1>
            <p><?= htmlspecialchars($grupo['nombre']) ?></p>
            <p style="font-size: 0.8rem; margin-top: 0.5rem;"><?= htmlspecialchars($grupo['empresa']) ?></p>
        </div>
        
        <div class="content">
            <?php if ($mensaje): ?>
                <div class="mensaje <?= $tipo_mensaje ?>"><?= $mensaje ?></div>
            <?php endif; ?>

            <div id="offlineBanner" class="offline-banner">
                <span id="offlineStatusText">📡 Verificando conexión…</span>
                <span id="offlineQueueText" class="queue-count" style="display:none;">0</span>
            </div>
            
            <!-- FORMULARIO HTML -->
            <form method="POST" enctype="multipart/form-data" id="asistenciaForm">
                <div class="form-group">
                    <label for="empleado_nombre">👤 Selecciona tu nombre:</label>
                    <select name="empleado_nombre" id="empleado_nombre" required>
                        <option value="">-- Seleccionar empleado --</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?= htmlspecialchars($emp['nombre']) ?>" data-telefono="<?= htmlspecialchars($emp['telefono']) ?>">
                                <?= htmlspecialchars($emp['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="tipo_asistencia">⏰ Tipo de asistencia:</label>
                    <select name="tipo_asistencia" id="tipo_asistencia" required>
                        <option value="">-- Seleccionar tipo --</option>
                        <option value="Entrada">Entrada</option>
                        <option value="Salida">Salida</option>
                        <option value="Descanso">Descanso</option>
                        <option value="Reanudar">Reanudar</option>
                    </select>
                </div>
                <div class="form-group" id="motivoGroup" style="display:none;">
                    <label for="motivo">📝 Motivo del descanso:</label>
                    <input type="text" name="motivo" id="motivo" maxlength="100" placeholder="Motivo del descanso">
                </div>
                <div class="camera-section">
                    <input type="file" accept="image/*" capture="environment" name="foto" id="foto" class="camera-input" required>
                    <label for="foto" class="camera-label">
                        📷 Tomar Foto de Asistencia
                    </label>
                    <div class="preview-container" id="previewContainer">
                        <img id="previewImage" class="preview-image" alt="Vista previa">
                    </div>
                </div>
                <div id="locationInfo" class="location-info" style="display: none;">
                    <strong>📍 Obteniendo ubicación...</strong>
                    <div class="loading">
                        <span class="spinner"></span>
                        Detectando GPS...
                    </div>
                </div>
                <input type="hidden" name="lat" id="lat">
                <input type="hidden" name="lng" id="lng">
                <input type="hidden" name="direccion" id="direccion">
                <input type="hidden" name="empleado_telefono" id="empleado_telefono">
                <button type="submit" class="submit-btn" id="submitBtn" disabled>
                    ✅ Registrar Asistencia
                </button>
            </form>
            
            <div class="info-text">
                📱 Toma tu foto de asistencia con ubicación GPS.<br>
                🗺️ Se generará automáticamente un mapa en la imagen.<br>
                ⏰ Fecha y hora se agregarán automáticamente.
            </div>
            
            <a href="emergency.php?token=<?= htmlspecialchars($token) ?>" class="emergency-link">
                🚨 Ir a Emergencias
            </a>
        </div>
    </div>

    <script src="js/pwa.js"></script>
    <script>
        let ubicacionObtenida = false;
        let fotoSeleccionada = false;
        let pendientesGuardados = 0;
        let offlineBanner, offlineStatusText, offlineQueueText;

        // Obtener ubicación al cargar la página y preparar PWA
        document.addEventListener('DOMContentLoaded', function() {
            offlineBanner = document.getElementById('offlineBanner');
            offlineStatusText = document.getElementById('offlineStatusText');
            offlineQueueText = document.getElementById('offlineQueueText');

            if (window.asistenciaPWA) {
                asistenciaPWA.init({
                    onPendingChange: actualizarPendientes
                });
            }

            actualizarEstadoConexion();
            obtenerUbicacion();
        });

        window.addEventListener('online', actualizarEstadoConexion);
        window.addEventListener('offline', actualizarEstadoConexion);

        function actualizarPendientes(count) {
            pendientesGuardados = count;
            if (!offlineBanner || !offlineQueueText) return;
            if (count > 0) {
                offlineQueueText.style.display = 'inline-flex';
                offlineQueueText.textContent = `${count} pendiente${count !== 1 ? 's' : ''}`;
            } else {
                offlineQueueText.style.display = 'none';
            }
            actualizarEstadoConexion();
        }

        function actualizarEstadoConexion() {
            if (!offlineBanner || !offlineStatusText) return;
            const online = navigator.onLine;
            const mostrar = !online || pendientesGuardados > 0;
            offlineBanner.style.display = mostrar ? 'flex' : 'none';
            offlineBanner.classList.toggle('online', online);
            if (!online) {
                offlineStatusText.textContent = '📡 Sin conexión. Guardaremos tus registros y los enviaremos automáticamente al volver la red.';
            } else if (pendientesGuardados > 0) {
                offlineStatusText.textContent = '🟢 Conexión restaurada. Sincronizando registros pendientes…';
            } else {
                offlineStatusText.textContent = '🟢 Conexión disponible.';
            }
        }

        function mostrarAvisoOffline(mensaje) {
            if (!offlineBanner || !offlineStatusText) return;
            offlineBanner.style.display = 'flex';
            offlineBanner.classList.remove('online');
            offlineStatusText.textContent = mensaje;
        }
        
        // Manejar selección de empleado
        document.getElementById('empleado_nombre').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const telefono = selectedOption.getAttribute('data-telefono') || '';
            document.getElementById('empleado_telefono').value = telefono;
            verificarFormulario();
        });
        
        // Manejar selección de foto
        document.getElementById('foto').addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const file = e.target.files[0];
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const preview = document.getElementById('previewImage');
                    const container = document.getElementById('previewContainer');
                    preview.src = e.target.result;
                    container.style.display = 'block';
                    fotoSeleccionada = true;
                    verificarFormulario();
                };
                
                reader.readAsDataURL(file);
            }
        });
        
        // Mostrar/ocultar motivo según tipo de asistencia
        document.getElementById('tipo_asistencia').addEventListener('change', function() {
            const tipo = this.value;
            const motivoGroup = document.getElementById('motivoGroup');
            if (tipo === 'Descanso') {
                motivoGroup.style.display = 'block';
            } else {
                motivoGroup.style.display = 'none';
                document.getElementById('motivo').value = '';
            }
            verificarFormulario();
        });
        
        function obtenerUbicacion() {
            const locationInfo = document.getElementById('locationInfo');
            locationInfo.style.display = 'block';
            
            if (!navigator.geolocation) {
                mostrarErrorUbicacion('Tu navegador no soporta geolocalización');
                return;
            }
            
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    document.getElementById('lat').value = lat;
                    document.getElementById('lng').value = lng;
                    
                    // Obtener dirección aproximada
                    obtenerDireccion(lat, lng);
                },
                function(error) {
                    let mensaje = 'Error obteniendo ubicación: ';
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            mensaje += 'Permiso denegado. Permite el acceso a la ubicación.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            mensaje += 'Ubicación no disponible.';
                            break;
                        case error.TIMEOUT:
                            mensaje += 'Tiempo de espera agotado.';
                            break;
                        default:
                            mensaje += 'Error desconocido.';
                            break;
                    }
                    mostrarErrorUbicacion(mensaje);
                },
                {
                    enableHighAccuracy: true,
                    timeout: 15000,
                    maximumAge: 300000
                }
            );
        }
        
        async function obtenerDireccion(lat, lng) {
            try {
                const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1&accept-language=es`);
                const data = await response.json();
                
                let direccion = 'Ubicación detectada';
                if (data && data.display_name) {
                    // Construir dirección más limpia
                    const address = data.address;
                    if (address) {
                        const partes = [];
                        if (address.road) partes.push(address.road);
                        if (address.neighbourhood) partes.push(address.neighbourhood);
                        if (address.city || address.town) partes.push(address.city || address.town);
                        if (address.state) partes.push(address.state);
                        direccion = partes.length > 0 ? partes.join(', ') : data.display_name;
                    } else {
                        direccion = data.display_name;
                    }
                }
                
                document.getElementById('direccion').value = direccion;
                mostrarUbicacionExitosa(lat, lng, direccion);
                
            } catch (error) {
                console.error('Error obteniendo dirección:', error);
                mostrarUbicacionExitosa(lat, lng, 'Dirección no disponible');
            }
        }
        
        function mostrarUbicacionExitosa(lat, lng, direccion) {
            const locationInfo = document.getElementById('locationInfo');
            locationInfo.className = 'location-info';
            locationInfo.innerHTML = `
                <strong>📍 Ubicación detectada correctamente</strong>
                <div>📌 ${direccion}</div>
                <div style="font-size: 0.8rem; margin-top: 0.5rem; opacity: 0.7;">
                    ${lat.toFixed(6)}, ${lng.toFixed(6)}
                </div>
            `;
            ubicacionObtenida = true;
            verificarFormulario();
        }
        
        function mostrarErrorUbicacion(mensaje) {
            const locationInfo = document.getElementById('locationInfo');
            locationInfo.className = 'location-info error';
            locationInfo.innerHTML = `
                <strong>❌ Error de ubicación</strong>
                <div>${mensaje}</div>
                <button type="button" onclick="obtenerUbicacion()" style="margin-top: 0.5rem; padding: 0.5rem 1rem; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    🔄 Reintentar
                </button>
            `;
        }
        
        function verificarFormulario() {
            const submitBtn = document.getElementById('submitBtn');
            const empleadoSeleccionado = document.getElementById('empleado_nombre').value !== '';
            const tipoSeleccionado = document.getElementById('tipo_asistencia').value !== '';
            const motivoGroup = document.getElementById('motivoGroup');
            const motivoValido = (document.getElementById('tipo_asistencia').value !== 'Descanso') || (document.getElementById('motivo').value.trim() !== '');
            if (empleadoSeleccionado && tipoSeleccionado && motivoValido && fotoSeleccionada && ubicacionObtenida) {
                submitBtn.disabled = false;
                submitBtn.textContent = '✅ Registrar Asistencia';
            } else {
                submitBtn.disabled = true;
                if (!empleadoSeleccionado) {
                    submitBtn.textContent = '👤 Selecciona empleado';
                } else if (!tipoSeleccionado) {
                    submitBtn.textContent = '⏰ Selecciona tipo';
                } else if (document.getElementById('tipo_asistencia').value === 'Descanso' && document.getElementById('motivo').value.trim() === '') {
                    submitBtn.textContent = '📝 Motivo requerido';
                } else if (!fotoSeleccionada) {
                    submitBtn.textContent = '📷 Toma la foto';
                } else if (!ubicacionObtenida) {
                    submitBtn.textContent = '📍 Esperando ubicación';
                } else {
                    submitBtn.textContent = '✅ Registrar Asistencia';
                }
            }
        }

        function limpiarFormularioOffline() {
            const empleadoSelect = document.getElementById('empleado_nombre');
            if (empleadoSelect) {
                empleadoSelect.value = '';
            }

            const tipoSelect = document.getElementById('tipo_asistencia');
            if (tipoSelect) {
                tipoSelect.value = '';
            }

            const motivoInput = document.getElementById('motivo');
            if (motivoInput) {
                motivoInput.value = '';
            }

            const motivoGroup = document.getElementById('motivoGroup');
            if (motivoGroup) {
                motivoGroup.style.display = 'none';
            }

            const fotoInput = document.getElementById('foto');
            if (fotoInput) {
                fotoInput.value = '';
            }

            const telefonoInput = document.getElementById('empleado_telefono');
            if (telefonoInput) {
                telefonoInput.value = '';
            }

            const preview = document.getElementById('previewContainer');
            if (preview) {
                preview.style.display = 'none';
            }
            const previewImage = document.getElementById('previewImage');
            if (previewImage) {
                previewImage.src = '';
            }

            fotoSeleccionada = false;
        }
        
        // Prevenir envío si faltan datos y manejar modo offline
        document.getElementById('asistenciaForm').addEventListener('submit', async function(e) {
            if (!ubicacionObtenida || !fotoSeleccionada) {
                e.preventDefault();
                alert('❌ Faltan datos:\n' + 
                      (!fotoSeleccionada ? '• Foto requerida\n' : '') +
                      (!ubicacionObtenida ? '• Ubicación requerida' : ''));
                return false;
            }

            const submitBtn = document.getElementById('submitBtn');

            if (!navigator.onLine && window.asistenciaPWA) {
                e.preventDefault();
                submitBtn.disabled = true;
                submitBtn.textContent = '📦 Guardando registro offline…';
                try {
                    await asistenciaPWA.queueSubmission(e.target);
                    mostrarAvisoOffline('📦 Registro guardado sin conexión. Lo enviaremos automáticamente cuando vuelva la red.');
                    limpiarFormularioOffline();
                } catch (error) {
                    console.error('Error guardando offline', error);
                    alert('❌ No se pudo guardar el registro en modo offline. Intenta nuevamente.');
                } finally {
                    submitBtn.disabled = true;
                    submitBtn.textContent = '✅ Registrar Asistencia';
                    verificarFormulario();
                }
                return false;
            }
            
            // Mostrar loading en envío en línea (dejar que el formulario continúe)
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Procesando foto...';
        });
    </script>
</body>
</html>
