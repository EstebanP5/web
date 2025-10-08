<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$mensaje = '';
$errores = [];

$formValues = [
    'nombre' => '',
    'empresa' => '',
    'localidad' => '',
    'coordenadas' => '',
    'lat' => '',
    'lng' => '',
    'fecha_inicio' => '',
    'fecha_fin' => '',
    'pm_id' => '',
    'pm_nombre' => '',
    'pm_telefono' => '',
    'contacto_seguro_nombre' => '',
    'contacto_seguro_telefono' => ''
];

$pms = [];
$pmIndex = [];
if ($resultadoPm = $conn->query("SELECT pm.id, pm.user_id, pm.nombre, pm.telefono, u.email FROM project_managers pm LEFT JOIN users u ON u.id = pm.user_id WHERE pm.activo = 1 ORDER BY pm.nombre")) {
    $pms = $resultadoPm->fetch_all(MYSQLI_ASSOC);
    foreach ($pms as $pm) {
        $pmIndex[(int)$pm['id']] = $pm;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formValues['nombre'] = trim($_POST['nombre'] ?? '');
    $formValues['empresa'] = trim($_POST['empresa'] ?? '');
    $formValues['localidad'] = trim($_POST['localidad'] ?? '');
    $formValues['coordenadas'] = trim($_POST['coordenadas'] ?? '');
    $formValues['fecha_inicio'] = trim($_POST['fecha_inicio'] ?? '');
    $formValues['fecha_fin'] = trim($_POST['fecha_fin'] ?? '');
    $formValues['pm_id'] = isset($_POST['pm_id']) ? (string)(int)$_POST['pm_id'] : '';
    $formValues['pm_nombre'] = '';
    $formValues['pm_telefono'] = '';
    $formValues['contacto_seguro_nombre'] = trim($_POST['contacto_seguro_nombre'] ?? '');
    $formValues['contacto_seguro_telefono'] = trim($_POST['contacto_seguro_telefono'] ?? '');

    $lat = null;
    $lng = null;
    $latRaw = '';
    $lngRaw = '';
    $formValues['lat'] = '';
    $formValues['lng'] = '';

    if ($formValues['nombre'] === '') {
        $errores[] = 'El nombre del proyecto es obligatorio.';
    }
    if ($formValues['empresa'] === '') {
        $errores[] = 'La empresa o cliente es obligatoria.';
    }

    if ($formValues['coordenadas'] === '') {
        $errores[] = 'Ingresa las coordenadas del proyecto (latitud, longitud).';
    } else {
        $coordsInput = $formValues['coordenadas'];
        $cleanInput = str_replace([';', '|'], ' ', $coordsInput);
        if (preg_match('/(-?\d+(?:\.\d+)?)[,\s]+(-?\d+(?:\.\d+)?)/', $cleanInput, $matches)) {
            $latRaw = $matches[1];
            $lngRaw = $matches[2];

            if (!is_numeric($latRaw) || !is_numeric($lngRaw)) {
                $errores[] = 'Las coordenadas deben ser numéricas (ejemplo: 19.4326 -99.1332).';
            } else {
                $lat = (float)$latRaw;
                $lng = (float)$lngRaw;
                if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                    $errores[] = 'Las coordenadas están fuera de rango permitido.';
                }
            }
        } else {
            $errores[] = 'Formato de coordenadas inválido. Usa "latitud, longitud" (ej. 19.4326, -99.1332).';
        }
    }

    if ($lat !== null && $lng !== null) {
        $formValues['lat'] = number_format($lat, 6, '.', '');
        $formValues['lng'] = number_format($lng, 6, '.', '');
        $formValues['coordenadas'] = $formValues['lat'] . ', ' . $formValues['lng'];
    }

    $selectedPmId = (int)$formValues['pm_id'];
    $pmSeleccionado = $selectedPmId > 0 && isset($pmIndex[$selectedPmId]) ? $pmIndex[$selectedPmId] : null;
    if (!$pmSeleccionado) {
        $errores[] = 'Selecciona un Project Manager válido.';
    }

    $pmUserId = $pmSeleccionado ? (int)($pmSeleccionado['user_id'] ?? 0) : 0;
    if ($pmSeleccionado && $pmUserId <= 0) {
        $errores[] = 'El Project Manager seleccionado no tiene usuario asignado.';
    }

    if ($pmSeleccionado) {
        $formValues['pm_nombre'] = (string)($pmSeleccionado['nombre'] ?? '');
        $formValues['pm_telefono'] = (string)($pmSeleccionado['telefono'] ?? '');
    }

    $pmNombreFinal = $pmSeleccionado['nombre'] ?? '';
    $pmTelefonoFinal = $pmSeleccionado['telefono'] ?? '';

    if (empty($errores)) {
        $transactionStarted = false;
        try {
            if (!$conn->begin_transaction()) {
                throw new RuntimeException('No se pudo iniciar la transacción.');
            }
            $transactionStarted = true;

            $token = bin2hex(random_bytes(16));
            $empresa = $formValues['empresa'];
            $nombreProyecto = $formValues['nombre'];
            $localidad = $formValues['localidad'];
            $fechaInicio = $formValues['fecha_inicio'] !== '' ? $formValues['fecha_inicio'] : null;
            $fechaFin = $formValues['fecha_fin'] !== '' ? $formValues['fecha_fin'] : null;
            $contactoSeguroNombre = $formValues['contacto_seguro_nombre'];
            $contactoSeguroTelefono = $formValues['contacto_seguro_telefono'];

            $stmt = $conn->prepare("INSERT INTO grupos (token, nombre, empresa, localidad, lat, lng, fecha_inicio, fecha_fin, pm_nombre, pm_telefono, contacto_seguro_nombre, contacto_seguro_telefono, activo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)");
            if (!$stmt) {
                throw new RuntimeException('No se pudo preparar la inserción del proyecto.');
            }

            $stmt->bind_param(
                'ssssddssssss',
                $token,
                $nombreProyecto,
                $empresa,
                $localidad,
                $lat,
                $lng,
                $fechaInicio,
                $fechaFin,
                $pmNombreFinal,
                $pmTelefonoFinal,
                $contactoSeguroNombre,
                $contactoSeguroTelefono
            );

            if (!$stmt->execute()) {
                throw new RuntimeException('Error al guardar el proyecto.');
            }

            $proyectoId = $conn->insert_id;

            if ($pmUserId > 0) {
                $stmtAsign = $conn->prepare("INSERT INTO proyectos_pm (user_id, proyecto_id, activo) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE proyecto_id = VALUES(proyecto_id), activo = VALUES(activo)");
                if (!$stmtAsign) {
                    throw new RuntimeException('No se pudo asignar el Project Manager.');
                }
                $stmtAsign->bind_param('ii', $pmUserId, $proyectoId);
                if (!$stmtAsign->execute()) {
                    throw new RuntimeException('Error al vincular el Project Manager.');
                }
            }

            $conn->commit();
            $transactionStarted = false;

            $pmNombreMsg = $pmNombreFinal ?: ($pmSeleccionado['nombre'] ?? '');
            $mensaje = "<div class='alert-success'>✅ Proyecto '" . htmlspecialchars($nombreProyecto) . "' creado y PM asignado: <strong>" . htmlspecialchars($pmNombreMsg) . "</strong>. <a href='../public/emergency.php?token={$token}' target='_blank'>Ver página de emergencias</a></div>";

            $formValues = [
                'nombre' => '',
                'empresa' => '',
                'localidad' => '',
                'coordenadas' => '',
                'lat' => '',
                'lng' => '',
                'fecha_inicio' => '',
                'fecha_fin' => '',
                'pm_id' => '',
                'pm_nombre' => '',
                'pm_telefono' => '',
                'contacto_seguro_nombre' => '',
                'contacto_seguro_telefono' => ''
            ];
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            $mensaje = "<div class='alert-error'>❌ No se pudo crear el proyecto: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $mensaje = "<div class='alert-error'>❌ Corrige los siguientes puntos:<ul><li>" . implode('</li><li>', array_map('htmlspecialchars', $errores)) . "</li></ul></div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Proyecto</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;background:#fafafa;min-height:100vh;color:#1a365d;line-height:1.5;padding:0 0 40px}
        a{text-decoration:none}
        .container{max-width:1200px;margin:0 auto;padding:20px}
        .header{background:#fff;border-radius:20px;padding:32px;margin:24px 0 32px;box-shadow:0 4px 20px rgba(26,54,93,.08);border:1px solid #f1f5f9}
        .header-content{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:24px}
        .header-left{display:flex;align-items:center;gap:20px}
        .header-icon{width:72px;height:72px;background:linear-gradient(135deg,#ff7a00 0%,#1a365d 100%);border-radius:18px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:32px;box-shadow:0 8px 32px rgba(255,122,0,.25)}
        .header-info h1{font-size:32px;font-weight:700;color:#1a365d;margin:0 0 6px;letter-spacing:-.02em}
        .header-info p{font-size:16px;color:#718096;font-weight:400;margin:0}
        .header-actions{display:flex;gap:16px;flex-wrap:wrap}
        .btn{padding:14px 24px;border:none;border-radius:14px;font-size:15px;font-weight:600;cursor:pointer;transition:.2s;display:inline-flex;align-items:center;gap:10px;white-space:nowrap}
        .btn-primary{background:linear-gradient(135deg,#ff7a00 0%,#ff9500 100%);color:#fff;box-shadow:0 6px 20px rgba(255,122,0,.3)}
        .btn-primary:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(255,122,0,.4)}
        .btn-secondary{background:#fff;color:#1a365d;border:2px solid #e2e8f0}
        .btn-secondary:hover{background:#f8fafc;border-color:#cbd5e1;transform:translateY(-1px)}
        .card{background:#fff;border-radius:20px;padding:32px;box-shadow:0 4px 20px rgba(26,54,93,.08);border:1px solid #f1f5f9;margin-bottom:32px}
        h2.section-title{font-size:20px;font-weight:600;margin:0 0 20px;color:#1a365d}
        form#formProyecto{display:flex;flex-direction:column;gap:26px}
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px}
        label{display:block;font-size:13px;font-weight:600;color:#1a365d;margin:0 0 6px;letter-spacing:.5px;text-transform:uppercase}
        input,select{width:100%;padding:14px 16px;border:2px solid #e2e8f0;border-radius:12px;font-size:14px;background:#fafafa;color:#1a365d;transition:.2s;font-family:inherit}
        input:focus,select:focus{outline:none;border-color:#ff7a00;box-shadow:0 0 0 3px rgba(255,122,0,.15);background:#fff}
        .hint{background:#f8fafc;border:1px dashed #cbd5e1;padding:10px 14px;border-radius:12px;font-size:13px;color:#475569;margin-top:8px}
        .actions{display:flex;gap:14px;flex-wrap:wrap;justify-content:flex-end}
        .alert-success,.alert-error{padding:14px 18px;border-radius:14px;font-weight:500;margin:16px 0;display:flex;align-items:center;gap:10px;font-size:14px}
        .alert-success{background:#ecfdf5;color:#065f46;border:1px solid #bbf7d0}
        .alert-error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
        .back-link{display:inline-flex;align-items:center;gap:8px;font-weight:600;color:#1a365d;margin-top:10px}
        .back-link i{color:#ff7a00}
        .small-note{font-size:12px;color:#64748b;margin-top:4px}
    .coord-tags{display:flex;gap:12px;flex-wrap:wrap;margin-top:12px}
    .coord-chip{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:999px;background:#fff4ed;border:1px solid #fed7aa;color:#9a3412;font-weight:600;font-size:13px}
    .coord-chip::before{content:'📍';font-size:14px}
    .hint.warning{background:#fef2f2;border-style:solid;border-color:#fecaca;color:#b91c1c}
    .map-card{margin:18px 0;background:#fff;border-radius:18px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 10px 30px rgba(26,54,93,.08)}
    .map-card iframe{width:100%;height:320px;border:0}
    @media (max-width:680px){.header{padding:24px}.header-left{width:100%}.header-content{flex-direction:column;align-items:flex-start}.actions{justify-content:stretch}.btn{flex:1;justify-content:center}.map-card iframe{height:260px}}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-icon"><i class="fas fa-plus"></i></div>
                    <div class="header-info">
                        <h1>Nuevo Proyecto</h1>
                        <p>Registra un proyecto para gestionar asistencia y personal</p>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="proyectos.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Volver</a>
                </div>
            </div>
        </div>

        <?php if ($mensaje) echo $mensaje; ?>

        <div class="card">
            <h2 class="section-title"><i class="fas fa-info-circle" style="color:#ff7a00"></i> Datos Generales</h2>
            <form method="POST" id="formProyecto">
                <div class="form-grid">
                    <div>
                        <label>Nombre del Proyecto *</label>
                        <input type="text" name="nombre" required placeholder="Ej: Torre Corporativa" value="<?=htmlspecialchars($formValues['nombre'])?>">
                    </div>
                    <div>
                        <label>Empresa / Cliente *</label>
                        <input type="text" name="empresa" required placeholder="Ej: Constructora XYZ" value="<?=htmlspecialchars($formValues['empresa'])?>">
                    </div>
                    <div>
                        <label>Fecha Inicio</label>
                        <input type="date" name="fecha_inicio" value="<?=htmlspecialchars($formValues['fecha_inicio'])?>">
                    </div>
                    <div>
                        <label>Fecha Fin</label>
                        <input type="date" name="fecha_fin" value="<?=htmlspecialchars($formValues['fecha_fin'])?>">
                    </div>
                    <div style="grid-column:1 / -1">
                        <label>Coordenadas (Latitud, Longitud) *</label>
                        <input type="text" name="coordenadas" id="coordenadas" required placeholder="19.4326,-99.1332" value="<?=htmlspecialchars($formValues['coordenadas'])?>" oninput="procesarCoordenadasDebounced()" onblur="verificarDireccion()">
                        <div class="small-note">Pega las coordenadas separadas por coma o espacio. Ejemplo: <kbd>19.4326,-99.1332</kbd></div>
                        <div class="coord-tags" id="coordTags" style="<?=($formValues['lat'] !== '' && $formValues['lng'] !== '') ? '' : 'display:none'?>">
                            <span class="coord-chip" id="latChip">Lat: <?=htmlspecialchars($formValues['lat'])?></span>
                            <span class="coord-chip" id="lngChip">Lng: <?=htmlspecialchars($formValues['lng'])?></span>
                        </div>
                        <div class="hint warning" id="coordWarning" style="display:none"></div>
                    </div>
                    <div style="grid-column:1 / -1">
                        <label>Localidad / Referencia</label>
                        <input type="text" id="localidad" name="localidad" placeholder="Se llenará automáticamente al verificar" value="<?=htmlspecialchars($formValues['localidad'])?>">
                        <div class="hint" id="dirHint" style="display:none"></div>
                        <div class="hint" id="geocodeLoading" style="display:none">🔄 Buscando dirección...</div>
                    </div>
                </div>

                <div class="map-card" id="mapWrapper" style="<?=($formValues['lat'] !== '' && $formValues['lng'] !== '') ? 'display:block' : 'display:none'?>">
                    <iframe id="mapPreview" title="Vista de la ubicación seleccionada"></iframe>
                </div>

                <h2 class="section-title" style="margin-top:10px"><i class="fas fa-user-tie" style="color:#ff7a00"></i> Project Manager</h2>
                <div class="form-grid">
                    <div>
                        <label>Selecciona el PM *</label>
                        <select name="pm_id" id="pm_id" required onchange="actualizaPM()">
                            <option value="">-- Selecciona --</option>
                            <?php foreach ($pms as $pm):
                                $isSelected = ((int)$formValues['pm_id'] === (int)$pm['id']);
                                ?>
                                <option value="<?=$pm['id']?>" <?= $isSelected ? 'selected' : '' ?> data-nombre="<?=htmlspecialchars($pm['nombre'] ?? '')?>" data-telefono="<?=htmlspecialchars($pm['telefono'] ?? '')?>" data-email="<?=htmlspecialchars($pm['email'] ?? '')?>">
                                    <?=htmlspecialchars($pm['nombre'] ?? 'Sin nombre')?><?=isset($pm['telefono']) && $pm['telefono'] !== '' ? ' (' . htmlspecialchars($pm['telefono']) . ')' : ''?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="small-note" id="pmNote">Los datos del contacto se rellenan automáticamente y no se pueden editar desde esta vista.</div>
                    </div>
                    <div>
                        <label>Nombre del PM</label>
                        <input type="text" name="pm_nombre" id="pm_nombre" placeholder="Se llenará al elegir un PM" value="<?=htmlspecialchars($formValues['pm_nombre'])?>" readonly>
                    </div>
                    <div>
                        <label>Teléfono del PM</label>
                        <input type="tel" name="pm_telefono" id="pm_telefono" placeholder="Se llenará al elegir un PM" value="<?=htmlspecialchars($formValues['pm_telefono'])?>" readonly>
                    </div>
                </div>

                <h2 class="section-title" style="margin-top:10px"><i class="fas fa-phone" style="color:#ff7a00"></i> Contacto Adicional</h2>
                <div class="form-grid">
                    <div>
                        <label>Contacto (Seguro / EM)</label>
                        <input type="text" name="contacto_seguro_nombre" placeholder="Nombre del contacto" value="<?=htmlspecialchars($formValues['contacto_seguro_nombre'])?>">
                    </div>
                    <div>
                        <label>Teléfono contacto</label>
                        <input type="tel" name="contacto_seguro_telefono" placeholder="Teléfono del contacto" value="<?=htmlspecialchars($formValues['contacto_seguro_telefono'])?>">
                    </div>
                </div>

                <div class="actions">
                    <button type="button" class="btn btn-secondary" onclick="usarUbicacion()"><i class="fas fa-location-crosshairs"></i> Usar mi ubicación</button>
                    <button type="button" class="btn btn-secondary" onclick="verificarDireccion()"><i class="fas fa-search-location"></i> Verificar ubicación</button>
                    <button type="button" class="btn btn-secondary" onclick="limpiarCoordenadas()"><i class="fas fa-eraser"></i> Limpiar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Proyecto</button>
                </div>
            </form>
            <a class="back-link" href="proyectos.php"><i class="fas fa-arrow-left"></i> Volver a proyectos</a>
        </div>
    </div>

    <script>
    function actualizaPM(autoFocus) {
        if (typeof autoFocus === 'undefined') autoFocus = true;
        const select = document.getElementById('pm_id');
        const nombreInput = document.getElementById('pm_nombre');
        const telefonoInput = document.getElementById('pm_telefono');
        const note = document.getElementById('pmNote');

        if (!select || !nombreInput || !telefonoInput) return;

        const option = select.options[select.selectedIndex];
        const nombre = option ? option.getAttribute('data-nombre') : '';
        const telefono = option ? option.getAttribute('data-telefono') : '';
        const email = option ? option.getAttribute('data-email') : '';

        nombreInput.value = nombre || '';
        telefonoInput.value = telefono || '';

        if (note) {
            if (option && option.value) {
                var partes = [];
                if (telefono) partes.push('Tel: ' + telefono);
                if (email) partes.push('Correo: ' + email);
                note.textContent = partes.length
                    ? 'Contacto del PM seleccionado · ' + partes.join(' · ')
                    : 'Los datos del contacto se rellenan automáticamente.';
            } else {
                note.textContent = 'Los datos del contacto se rellenan automáticamente y no se pueden editar desde esta vista.';
            }
        }
    }

    const coordInput = document.getElementById('coordenadas');
    const coordTags = document.getElementById('coordTags');
    const latChip = document.getElementById('latChip');
    const lngChip = document.getElementById('lngChip');
    const coordWarning = document.getElementById('coordWarning');
    const mapWrapper = document.getElementById('mapWrapper');
    const mapFrame = document.getElementById('mapPreview');
    const dirHint = document.getElementById('dirHint');
    const geocodeLoading = document.getElementById('geocodeLoading');
    const localidadInput = document.getElementById('localidad');
    const LAT_MIN = -90, LAT_MAX = 90, LNG_MIN = -180, LNG_MAX = 180;
    let geocodeTimer = null;

    document.addEventListener('DOMContentLoaded', function () {
        actualizaPM(false);
        procesarCoordenadas(false);
    });

    function procesarCoordenadas(triggerGeocode = false) {
        if (!coordInput) return;
        if (triggerGeocode) {
            clearTimeout(geocodeTimer);
        }
        const valor = coordInput.value.trim();
        if (!valor) {
            ocultarCoordenadas();
            ocultarMapa();
            ocultarDireccion();
            mostrarAlertaCoordenadas('');
            return;
        }

        const coincidencias = valor.replace(/[;|]/g, ' ').match(/-?\d+(?:\.\d+)?/g);
        if (!coincidencias || coincidencias.length < 2) {
            mostrarAlertaCoordenadas('Formato inválido. Usa latitud y longitud separadas por coma o espacio.');
            ocultarMapa();
            return;
        }

        const lat = parseFloat(coincidencias[0]);
        const lng = parseFloat(coincidencias[1]);
        if (!esNumeroValido(lat) || !esNumeroValido(lng)) {
            mostrarAlertaCoordenadas('Las coordenadas deben ser numéricas.');
            ocultarMapa();
            return;
        }
        if (!enRango(lat, lng)) {
            mostrarAlertaCoordenadas('Las coordenadas deben estar entre Lat -90/90 y Lng -180/180.');
            ocultarMapa();
            return;
        }

        actualizarChips(lat, lng);
        mostrarAlertaCoordenadas('');
        renderMapa(lat, lng);
        if (triggerGeocode) {
            ocultarDireccion();
            buscarDireccion(lat, lng);
        }
    }

    function procesarCoordenadasDebounced() {
        procesarCoordenadas(false);
        clearTimeout(geocodeTimer);
        geocodeTimer = setTimeout(() => procesarCoordenadas(true), 600);
    }

    function esNumeroValido(valor) {
        return typeof valor === 'number' && !Number.isNaN(valor) && Number.isFinite(valor);
    }

    function enRango(lat, lng) {
        return lat >= LAT_MIN && lat <= LAT_MAX && lng >= LNG_MIN && lng <= LNG_MAX;
    }

    function actualizarChips(lat, lng) {
        if (!coordTags || !latChip || !lngChip) return;
        coordTags.style.display = 'flex';
        latChip.textContent = `Lat: ${lat.toFixed(6)}`;
        lngChip.textContent = `Lng: ${lng.toFixed(6)}`;
    }

    function ocultarCoordenadas() {
        if (coordTags) coordTags.style.display = 'none';
    }

    function mostrarAlertaCoordenadas(mensaje) {
        if (!coordWarning) return;
        if (mensaje) {
            coordWarning.textContent = mensaje;
            coordWarning.style.display = 'block';
        } else {
            coordWarning.textContent = '';
            coordWarning.style.display = 'none';
        }
    }

    function renderMapa(lat, lng) {
        if (!mapWrapper || !mapFrame) return;
        const delta = 0.004;
        const south = Math.max(lat - delta, LAT_MIN);
        const north = Math.min(lat + delta, LAT_MAX);
        const west = Math.max(lng - delta, LNG_MIN);
        const east = Math.min(lng + delta, LNG_MAX);
        const bbox = `${west.toFixed(6)},${south.toFixed(6)},${east.toFixed(6)},${north.toFixed(6)}`;
        mapFrame.src = `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${lat},${lng}`;
        mapWrapper.style.display = 'block';
    }

    function ocultarMapa() {
        if (!mapWrapper) return;
        if (mapFrame) mapFrame.src = '';
        mapWrapper.style.display = 'none';
    }

    function ocultarDireccion() {
        if (dirHint) {
            dirHint.textContent = '';
            dirHint.style.display = 'none';
        }
        if (geocodeLoading) {
            geocodeLoading.style.display = 'none';
        }
    }

    function verificarDireccion() {
        procesarCoordenadas(true);
    }

    function usarUbicacion() {
        if (!navigator.geolocation) {
            alert('Geolocalización no soportada en este navegador.');
            return;
        }
        navigator.geolocation.getCurrentPosition(pos => {
            const lat = pos.coords.latitude.toFixed(6);
            const lng = pos.coords.longitude.toFixed(6);
            if (coordInput) {
                coordInput.value = `${lat}, ${lng}`;
            }
            procesarCoordenadas(true);
        }, err => {
            alert('No se pudo obtener ubicación: ' + err.message);
        }, { enableHighAccuracy: true, timeout: 12000 });
    }

    async function buscarDireccion(lat, lng) {
        if (!dirHint || !geocodeLoading) return;
        geocodeLoading.style.display = 'block';
        dirHint.style.display = 'none';
        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=17&addressdetails=1&accept-language=es`);
            if (!response.ok) throw new Error('Respuesta no válida');
            const data = await response.json();
            const address = data.address || {};
            const partes = [address.road || address.street, address.neighbourhood || address.suburb, address.city || address.town || address.village, address.state]
                .filter(Boolean)
                .join(', ');
            const display = partes || data.display_name || '';
            if (display) {
                dirHint.textContent = `Dirección sugerida: ${display}`;
                dirHint.style.display = 'block';
                if (localidadInput && localidadInput.value.trim() === '') {
                    localidadInput.value = display;
                }
            } else {
                dirHint.textContent = 'No se encontró una dirección exacta para estas coordenadas.';
                dirHint.style.display = 'block';
            }
        } catch (error) {
            mostrarAlertaCoordenadas('No se pudo verificar la dirección. Intenta de nuevo.');
        } finally {
            geocodeLoading.style.display = 'none';
        }
    }

    function limpiarCoordenadas() {
        if (!coordInput) return;
        clearTimeout(geocodeTimer);
    geocodeTimer = null;
        coordInput.value = '';
        ocultarCoordenadas();
        ocultarMapa();
        mostrarAlertaCoordenadas('');
        ocultarDireccion();
        if (localidadInput) {
            localidadInput.value = '';
        }
        coordInput.focus();
    }
    </script>
</body>
</html>
