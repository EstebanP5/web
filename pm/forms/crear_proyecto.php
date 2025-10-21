<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'pm') {
    header('Location: ../../login.php');
    exit;
}

require_once __DIR__ . '/../../includes/db.php';

$pmUserId = (int)$_SESSION['user_id'];
$pmDisplayName = trim($_SESSION['user_name'] ?? '');
$pmPhone = '';

if ($stmtPm = $conn->prepare('SELECT nombre, telefono FROM project_managers WHERE user_id = ? AND activo = 1 LIMIT 1')) {
    $stmtPm->bind_param('i', $pmUserId);
    if ($stmtPm->execute()) {
        $resultPm = $stmtPm->get_result();
        if ($resultPm) {
            $pmRow = $resultPm->fetch_assoc();
            if ($pmRow) {
                $nombreRegistro = trim((string)($pmRow['nombre'] ?? ''));
                if ($nombreRegistro !== '') {
                    $pmDisplayName = $nombreRegistro;
                }
                $pmPhone = trim((string)($pmRow['telefono'] ?? ''));
            }
        }
    }
    $stmtPm->close();
}

$values = [
    'nombre' => '',
    'empresa' => '',
    'localidad' => '',
    'coordenadas' => '',
    'fecha_inicio' => '',
    'fecha_fin' => '',
    'contacto_nombre' => '',
    'contacto_telefono' => '',
];

$feedback = ['type' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['nombre'] = trim($_POST['nombre'] ?? '');
    $values['empresa'] = trim($_POST['empresa'] ?? '');
    $values['localidad'] = trim($_POST['localidad'] ?? '');
    $values['coordenadas'] = trim($_POST['coordenadas'] ?? '');
    $values['fecha_inicio'] = trim($_POST['fecha_inicio'] ?? '');
    $values['fecha_fin'] = trim($_POST['fecha_fin'] ?? '');
    $values['contacto_nombre'] = trim($_POST['contacto_nombre'] ?? '');
    $values['contacto_telefono'] = trim($_POST['contacto_telefono'] ?? '');

    $errors = [];
    $lat = null;
    $lng = null;

    if ($values['nombre'] === '') {
        $errors[] = 'El nombre del proyecto es obligatorio.';
    }
    if ($values['empresa'] === '') {
        $errors[] = 'La empresa o cliente es obligatoria.';
    }

    if ($values['coordenadas'] === '') {
        $errors[] = 'Proporciona las coordenadas del proyecto (latitud, longitud).';
    } else {
        $cleanCoords = str_replace([';', '|'], ' ', $values['coordenadas']);
        if (preg_match('/(-?\d+(?:\.\d+)?)[,\s]+(-?\d+(?:\.\d+)?)/', $cleanCoords, $matches)) {
            $lat = (float)$matches[1];
            $lng = (float)$matches[2];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                $errors[] = 'Las coordenadas están fuera del rango permitido.';
            } else {
                $values['coordenadas'] = number_format($lat, 6, '.', '') . ', ' . number_format($lng, 6, '.', '');
            }
        } else {
            $errors[] = 'No se pudieron interpretar las coordenadas. Usa el formato "19.4326, -99.1332".';
        }
    }

    $fechaInicio = '';
    if ($values['fecha_inicio'] !== '') {
        $inicio = DateTime::createFromFormat('Y-m-d', $values['fecha_inicio']);
        if ($inicio && $inicio->format('Y-m-d') === $values['fecha_inicio']) {
            $fechaInicio = $values['fecha_inicio'];
        } else {
            $errors[] = 'La fecha de inicio no tiene un formato válido (AAAA-MM-DD).';
        }
    }

    $fechaFin = '';
    if ($values['fecha_fin'] !== '') {
        $fin = DateTime::createFromFormat('Y-m-d', $values['fecha_fin']);
        if ($fin && $fin->format('Y-m-d') === $values['fecha_fin']) {
            $fechaFin = $values['fecha_fin'];
        } else {
            $errors[] = 'La fecha de fin no tiene un formato válido (AAAA-MM-DD).';
        }
    }

    if ($fechaInicio !== '' && $fechaFin !== '') {
        if ($fechaFin < $fechaInicio) {
            $errors[] = 'La fecha de fin debe ser posterior o igual a la fecha de inicio.';
        }
    }

    if (empty($errors)) {
        $transactionStarted = false;
        try {
            if (!$conn->begin_transaction()) {
                throw new RuntimeException('No se pudo iniciar la transacción.');
            }
            $transactionStarted = true;

            $token = bin2hex(random_bytes(16));
            $stmtInsert = $conn->prepare("INSERT INTO grupos (token, nombre, empresa, localidad, lat, lng, fecha_inicio, fecha_fin, pm_nombre, pm_telefono, contacto_seguro_nombre, contacto_seguro_telefono, activo) VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, ?, 1)");
            if (!$stmtInsert) {
                throw new RuntimeException('No se pudo preparar el registro del proyecto.');
            }

            $fechaInicioParam = $fechaInicio;
            $fechaFinParam = $fechaFin;

            $stmtInsert->bind_param(
                'ssssddssssss',
                $token,
                $values['nombre'],
                $values['empresa'],
                $values['localidad'],
                $lat,
                $lng,
                $fechaInicioParam,
                $fechaFinParam,
                $pmDisplayName,
                $pmPhone,
                $values['contacto_nombre'],
                $values['contacto_telefono']
            );

            if (!$stmtInsert->execute()) {
                throw new RuntimeException('No se pudo guardar el proyecto.');
            }

            $projectId = $conn->insert_id;
            $stmtInsert->close();

            $stmtLink = $conn->prepare('INSERT INTO proyectos_pm (user_id, proyecto_id, activo) VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE activo = VALUES(activo)');
            if (!$stmtLink) {
                throw new RuntimeException('No se pudo vincular el proyecto al PM.');
            }
            $stmtLink->bind_param('ii', $pmUserId, $projectId);
            if (!$stmtLink->execute()) {
                throw new RuntimeException('No se pudo vincular el proyecto al PM.');
            }
            $stmtLink->close();

            $conn->commit();
            $transactionStarted = false;

            $feedback['type'] = 'success';
            $feedback['message'] = 'Proyecto creado correctamente. Puedes <a href="../proyecto_equipo.php?id=' . (int)$projectId . '">asignar Servicios Especializados</a> de inmediato.';

            foreach ($values as $key => $default) {
                $values[$key] = '';
            }
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            $feedback['type'] = 'error';
            $feedback['message'] = 'No se pudo crear el proyecto: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    } else {
        $feedback['type'] = 'error';
        $feedback['message'] = 'Corrige los siguientes puntos:<ul><li>' . implode('</li><li>', array_map(function ($msg) {
            return htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        }, $errors)) . '</li></ul>';
    }
}

$pmCssPath = __DIR__ . '/../../assets/pm.css';
$pmCssVersion = file_exists($pmCssPath) ? filemtime($pmCssPath) : time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo Proyecto - PM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/pm.css?v=<?= $pmCssVersion; ?>">
    <style>
        body {
            background: #f8fafc;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }
        .btn i {
            font-size: 14px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: #ffffff;
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.25);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 32px rgba(37, 99, 235, 0.35);
        }
        .btn-secondary {
            background: #ffffff;
            color: #1f2937;
            border: 1px solid #cbd5f5;
        }
        .btn-secondary:hover {
            background: #f8fafc;
            transform: translateY(-1px);
        }
        .pm-form-wrapper {
            max-width: 880px;
            margin: 32px auto;
            background: #ffffff;
            border-radius: 20px;
            padding: 36px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
            border: 1px solid #e2e8f0;
        }
        .pm-form-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
        }
        .pm-form-header h1 {
            font-size: 28px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #0f172a;
        }
        .pm-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
        }
        .pm-form-grid .full-row {
            grid-column: 1 / -1;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        input, textarea {
            width: 100%;
            padding: 14px 16px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            background: #f8fafc;
            font-size: 14px;
            color: #0f172a;
            transition: all 0.2s ease;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: #3b82f6;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .pm-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 28px;
        }
        .pm-feedback {
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 24px;
            font-size: 14px;
        }
        .pm-feedback.success {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .pm-feedback.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .pm-hint {
            font-size: 12px;
            color: #64748b;
            margin-top: 6px;
        }
        .pm-coord-feedback {
            margin-top: 8px;
            font-size: 13px;
            color: #dc2626;
            display: none;
        }
        .pm-map-wrapper {
            margin-top: 16px;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.1);
            display: none;
        }
        .pm-map-wrapper iframe {
            width: 100%;
            height: 300px;
            border: 0;
        }
        .pm-coord-tags {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .pm-chip-soft {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-weight: 600;
            font-size: 13px;
        }
        .pm-back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #334155;
            font-weight: 600;
            text-decoration: none;
            margin-top: 18px;
        }
        .pm-back-link i {
            color: #3b82f6;
        }
        @media (max-width: 640px) {
            .pm-form-wrapper {
                padding: 24px;
                margin: 16px;
            }
            .pm-form-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
<?php require_once dirname(__DIR__) . '/common/navigation.php'; ?>
<div class="pm-form-wrapper">
    <div class="pm-form-header">
        <h1><i class="fas fa-briefcase"></i> Nuevo proyecto</h1>
        <a href="../proyectos.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Volver</a>
    </div>

    <p style="color:#475569; margin-bottom:20px;">Completa la información para registrar un nuevo proyecto bajo tu administración y habilitar su seguimiento de asistencia.</p>

    <?php if ($feedback['type'] === 'success'): ?>
        <div class="pm-feedback success"><?= $feedback['message']; ?></div>
    <?php elseif ($feedback['type'] === 'error'): ?>
        <div class="pm-feedback error"><?= $feedback['message']; ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <div class="pm-form-grid">
            <div>
                <label for="nombre">Nombre del proyecto *</label>
                <input type="text" id="nombre" name="nombre" required value="<?= htmlspecialchars($values['nombre'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ej. Planta Norte" />
            </div>
            <div>
                <label for="empresa">Empresa / Cliente *</label>
                <input type="text" id="empresa" name="empresa" required value="<?= htmlspecialchars($values['empresa'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ej. Construcciones MX" />
            </div>
            <div>
                <label for="fecha_inicio">Fecha de inicio</label>
                <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= htmlspecialchars($values['fecha_inicio'], ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div>
                <label for="fecha_fin">Fecha de fin</label>
                <input type="date" id="fecha_fin" name="fecha_fin" value="<?= htmlspecialchars($values['fecha_fin'], ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div class="full-row">
                <label for="coordenadas">Coordenadas (latitud, longitud) *</label>
                <input type="text" id="coordenadas" name="coordenadas" required value="<?= htmlspecialchars($values['coordenadas'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="19.4326, -99.1332" />
                <div class="pm-hint">Puedes obtenerlas desde Google Maps. Mantén el formato decimal separado por coma.</div>
                <div class="pm-coord-tags" id="coordTags" style="display:none;">
                    <span class="pm-chip-soft" id="latChip">Lat:</span>
                    <span class="pm-chip-soft" id="lngChip">Lng:</span>
                </div>
                <div class="pm-coord-feedback" id="coordFeedback"></div>
            </div>
            <div class="full-row">
                <div class="pm-map-wrapper" id="mapWrapper">
                    <iframe id="mapPreview" title="Vista del proyecto"></iframe>
                </div>
            </div>
            <div class="full-row">
                <label for="localidad">Localidad / Referencia</label>
                <input type="text" id="localidad" name="localidad" value="<?= htmlspecialchars($values['localidad'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Colonia, ciudad o punto de referencia" />
            </div>
            <div>
                <label for="contacto_nombre">Contacto de seguridad</label>
                <input type="text" id="contacto_nombre" name="contacto_nombre" value="<?= htmlspecialchars($values['contacto_nombre'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nombre del contacto" />
            </div>
            <div>
                <label for="contacto_telefono">Teléfono del contacto</label>
                <input type="tel" id="contacto_telefono" name="contacto_telefono" value="<?= htmlspecialchars($values['contacto_telefono'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="10 dígitos" />
            </div>
        </div>

        <div class="pm-form-actions">
            <a href="../proyectos.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar proyecto</button>
        </div>
    </form>

    <a class="pm-back-link" href="../proyectos.php"><i class="fas fa-arrow-left"></i> Regresar al listado</a>
</div>
<script>
(function() {
    const coordInput = document.getElementById('coordenadas');
    const coordFeedback = document.getElementById('coordFeedback');
    const mapWrapper = document.getElementById('mapWrapper');
    const mapFrame = document.getElementById('mapPreview');
    const coordTags = document.getElementById('coordTags');
    const latChip = document.getElementById('latChip');
    const lngChip = document.getElementById('lngChip');
    const localidadInput = document.getElementById('localidad');
    let reverseGeocodeAbort = null;

    function parseCoords(value) {
        if (!value) {
            return null;
        }
        const cleaned = value.replace(/[;|]/g, ' ').trim();
        const match = cleaned.match(/(-?\d+(?:\.\d+)?)[,\s]+(-?\d+(?:\.\d+)?)/);
        if (!match) {
            return null;
        }
        const lat = parseFloat(match[1]);
        const lng = parseFloat(match[2]);
        if (Number.isNaN(lat) || Number.isNaN(lng)) {
            return null;
        }
        if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
            return null;
        }
        return {
            lat: parseFloat(lat.toFixed(6)),
            lng: parseFloat(lng.toFixed(6)),
        };
    }

    function setFeedback(message) {
        if (!coordFeedback) return;
        if (!message) {
            coordFeedback.style.display = 'none';
            coordFeedback.textContent = '';
            return;
        }
        coordFeedback.textContent = message;
        coordFeedback.style.display = 'block';
    }

    function updateMap(coords) {
        if (!mapWrapper || !mapFrame) return;
        if (!coords) {
            mapWrapper.style.display = 'none';
            mapFrame.removeAttribute('src');
            return;
        }
        const { lat, lng } = coords;
        const url = 'https://maps.google.com/maps?q=' + encodeURIComponent(lat + ',' + lng) + '&z=16&output=embed';
        mapFrame.setAttribute('src', url);
        mapWrapper.style.display = 'block';
    }

    function updateCoordTags(coords) {
        if (!coordTags || !latChip || !lngChip) return;
        if (!coords) {
            coordTags.style.display = 'none';
            return;
        }
        latChip.textContent = 'Lat: ' + coords.lat;
        lngChip.textContent = 'Lng: ' + coords.lng;
        coordTags.style.display = 'flex';
    }

    async function reverseGeocode(coords) {
        if (!coords) return;
        if (reverseGeocodeAbort) {
            reverseGeocodeAbort.abort();
        }
        reverseGeocodeAbort = new AbortController();
        const { signal } = reverseGeocodeAbort;
        setFeedback('Consultando dirección, espera...');
        try {
            const url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=16&lat=' + coords.lat + '&lon=' + coords.lng;
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json'
                },
                signal
            });
            if (!response.ok) {
                throw new Error('Error al obtener la dirección');
            }
            const data = await response.json();
            const displayName = data.display_name || '';
            if (displayName && localidadInput) {
                localidadInput.value = displayName;
            }
            setFeedback('');
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            setFeedback('No se pudo obtener la dirección automáticamente.');
        }
    }

    const handleCoords = debounce(function() {
        const coords = parseCoords(coordInput.value);
        if (!coords) {
            updateMap(null);
            updateCoordTags(null);
            setFeedback('Verifica el formato: usa "latitud, longitud" con valores válidos.');
            return;
        }
        const formatted = coords.lat + ', ' + coords.lng;
        coordInput.value = formatted;
        updateCoordTags(coords);
        updateMap(coords);
        setFeedback('');
        reverseGeocode(coords);
    }, 400);

    function debounce(fn, delay) {
        let timeout;
        return function() {
            const ctx = this;
            const args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                fn.apply(ctx, args);
            }, delay);
        };
    }

    if (coordInput) {
        coordInput.addEventListener('input', handleCoords);
        coordInput.addEventListener('blur', handleCoords);

        const initial = parseCoords(coordInput.value);
        if (initial) {
            updateCoordTags(initial);
            updateMap(initial);
            reverseGeocode(initial);
        }
    }
})();
</script>
</body>
</html>
