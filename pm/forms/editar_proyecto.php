<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'pm') {
    header('Location: ../../login.php');
    exit;
}

require_once __DIR__ . '/../../includes/db.php';

$pmUserId = (int)$_SESSION['user_id'];
$projectId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if ($projectId <= 0) {
    header('Location: ../proyectos.php');
    exit;
}

$pmProfile = ['nombre' => trim($_SESSION['user_name'] ?? ''), 'telefono' => ''];
if ($stmtPm = $conn->prepare('SELECT nombre, telefono FROM project_managers WHERE user_id = ? AND activo = 1 LIMIT 1')) {
    $stmtPm->bind_param('i', $pmUserId);
    if ($stmtPm->execute()) {
        $resultPm = $stmtPm->get_result();
        if ($resultPm && ($row = $resultPm->fetch_assoc())) {
            if (trim((string)($row['nombre'] ?? '')) !== '') {
                $pmProfile['nombre'] = trim((string)$row['nombre']);
            }
            $pmProfile['telefono'] = trim((string)($row['telefono'] ?? ''));
        }
    }
    $stmtPm->close();
}

$project = null;
if ($stmtProject = $conn->prepare('SELECT g.id, g.nombre, g.empresa, g.localidad, g.lat, g.lng, g.fecha_inicio, g.fecha_fin, g.contacto_seguro_nombre, g.contacto_seguro_telefono FROM proyectos_pm ppm JOIN grupos g ON g.id = ppm.proyecto_id WHERE ppm.user_id = ? AND g.id = ? LIMIT 1')) {
    $stmtProject->bind_param('ii', $pmUserId, $projectId);
    $stmtProject->execute();
    $resultProject = $stmtProject->get_result();
    if ($resultProject) {
        $project = $resultProject->fetch_assoc();
    }
    $stmtProject->close();
}

if (!$project) {
    $_SESSION['pm_project_error'] = 'No se encontró el proyecto solicitado.';
    header('Location: ../proyectos.php');
    exit;
}

$values = [
    'nombre' => trim((string)$project['nombre']),
    'empresa' => trim((string)$project['empresa']),
    'localidad' => trim((string)($project['localidad'] ?? '')),
    'coordenadas' => ($project['lat'] !== null && $project['lng'] !== null)
        ? number_format((float)$project['lat'], 6, '.', '') . ', ' . number_format((float)$project['lng'], 6, '.', '')
        : '',
    'fecha_inicio' => trim((string)($project['fecha_inicio'] ?? '')),
    'fecha_fin' => trim((string)($project['fecha_fin'] ?? '')),
    'contacto_seguro_nombre' => trim((string)($project['contacto_seguro_nombre'] ?? '')),
    'contacto_seguro_telefono' => trim((string)($project['contacto_seguro_telefono'] ?? '')),
];

$feedback = ['type' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['nombre'] = trim($_POST['nombre'] ?? '');
    $values['empresa'] = trim($_POST['empresa'] ?? '');
    $values['localidad'] = trim($_POST['localidad'] ?? '');
    $values['coordenadas'] = trim($_POST['coordenadas'] ?? '');
    $values['fecha_inicio'] = trim($_POST['fecha_inicio'] ?? '');
    $values['fecha_fin'] = trim($_POST['fecha_fin'] ?? '');
    $values['contacto_seguro_nombre'] = trim($_POST['contacto_seguro_nombre'] ?? '');
    $values['contacto_seguro_telefono'] = trim($_POST['contacto_seguro_telefono'] ?? '');

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
        $errors[] = 'Indica las coordenadas del proyecto.';
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

    $fechaInicioSql = null;
    if ($values['fecha_inicio'] !== '') {
        $inicio = DateTime::createFromFormat('Y-m-d', $values['fecha_inicio']);
        if ($inicio && $inicio->format('Y-m-d') === $values['fecha_inicio']) {
            $fechaInicioSql = $values['fecha_inicio'];
        } else {
            $errors[] = 'La fecha de inicio no tiene un formato válido (AAAA-MM-DD).';
        }
    }

    $fechaFinSql = null;
    if ($values['fecha_fin'] !== '') {
        $fin = DateTime::createFromFormat('Y-m-d', $values['fecha_fin']);
        if ($fin && $fin->format('Y-m-d') === $values['fecha_fin']) {
            $fechaFinSql = $values['fecha_fin'];
        } else {
            $errors[] = 'La fecha de fin no tiene un formato válido (AAAA-MM-DD).';
        }
    }

    if ($fechaInicioSql !== null && $fechaFinSql !== null && $fechaFinSql < $fechaInicioSql) {
        $errors[] = 'La fecha de fin debe ser posterior o igual a la fecha de inicio.';
    }

    if (empty($errors)) {
        $stmtUpdate = $conn->prepare('UPDATE grupos SET nombre = ?, empresa = ?, localidad = ?, lat = ?, lng = ?, fecha_inicio = ?, fecha_fin = ?, contacto_seguro_nombre = ?, contacto_seguro_telefono = ?, pm_nombre = ?, pm_telefono = ? WHERE id = ?');
        if ($stmtUpdate) {
            $pmNombre = $pmProfile['nombre'];
            $pmTelefono = $pmProfile['telefono'];
            $stmtUpdate->bind_param(
                'sssddssssssi',
                $values['nombre'],
                $values['empresa'],
                $values['localidad'],
                $lat,
                $lng,
                $fechaInicioSql,
                $fechaFinSql,
                $values['contacto_seguro_nombre'],
                $values['contacto_seguro_telefono'],
                $pmNombre,
                $pmTelefono,
                $projectId
            );
            if ($stmtUpdate->execute()) {
                $feedback['type'] = 'success';
                $feedback['message'] = 'Proyecto actualizado correctamente.';

                if ($stmtReload = $conn->prepare('SELECT nombre, empresa, localidad, lat, lng, fecha_inicio, fecha_fin, contacto_seguro_nombre, contacto_seguro_telefono FROM grupos WHERE id = ? LIMIT 1')) {
                    $stmtReload->bind_param('i', $projectId);
                    if ($stmtReload->execute()) {
                        $resultReload = $stmtReload->get_result();
                        if ($resultReload && ($fresh = $resultReload->fetch_assoc())) {
                            $project = array_merge($project, $fresh);
                            $values['nombre'] = trim((string)$fresh['nombre']);
                            $values['empresa'] = trim((string)$fresh['empresa']);
                            $values['localidad'] = trim((string)($fresh['localidad'] ?? ''));
                            $values['coordenadas'] = ($fresh['lat'] !== null && $fresh['lng'] !== null)
                                ? number_format((float)$fresh['lat'], 6, '.', '') . ', ' . number_format((float)$fresh['lng'], 6, '.', '')
                                : '';
                            $values['fecha_inicio'] = trim((string)($fresh['fecha_inicio'] ?? ''));
                            $values['fecha_fin'] = trim((string)($fresh['fecha_fin'] ?? ''));
                            $values['contacto_seguro_nombre'] = trim((string)($fresh['contacto_seguro_nombre'] ?? ''));
                            $values['contacto_seguro_telefono'] = trim((string)($fresh['contacto_seguro_telefono'] ?? ''));
                        }
                    }
                    $stmtReload->close();
                }
            } else {
                $feedback['type'] = 'error';
                $feedback['message'] = 'No se pudo guardar el proyecto. Intenta nuevamente.';
            }
            $stmtUpdate->close();
        } else {
            $feedback['type'] = 'error';
            $feedback['message'] = 'No se pudo preparar la actualización del proyecto.';
        }
    } else {
        $feedback['type'] = 'error';
        $feedback['message'] = 'Corrige los siguientes puntos:<ul><li>' . implode('</li><li>', array_map(static function ($msg) {
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
    <title>Editar Proyecto - PM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/pm.css?v=<?= $pmCssVersion; ?>">
    <style>
        body {
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0;
            color: #0f172a;
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
        input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            background: #f8fafc;
            font-size: 14px;
            color: #0f172a;
            transition: all 0.2s ease;
        }
        input:focus {
            outline: none;
            border-color: #3b82f6;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
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
        .pm-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 28px;
        }
        .pm-hint {
            font-size: 12px;
            color: #64748b;
            margin-top: 6px;
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
        <h1><i class="fas fa-pen-to-square"></i> Editar proyecto</h1>
        <a href="../proyectos.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Volver</a>
    </div>
    <p style="color:#475569; margin-bottom:20px;">Actualiza la información clave del proyecto para mantener al equipo alineado.</p>

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
                <label for="localidad">Localidad</label>
                <input type="text" id="localidad" name="localidad" value="<?= htmlspecialchars($values['localidad'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ciudad o zona" />
            </div>
            <div>
                <label for="coordenadas">Coordenadas (latitud, longitud) *</label>
                <input type="text" id="coordenadas" name="coordenadas" required value="<?= htmlspecialchars($values['coordenadas'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="19.4326, -99.1332" />
                <div class="pm-hint">Usa coordenadas decimales para ubicar el proyecto en el mapa.</div>
            </div>
            <div>
                <label for="fecha_inicio">Fecha de inicio</label>
                <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= htmlspecialchars($values['fecha_inicio'], ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div>
                <label for="fecha_fin">Fecha de fin</label>
                <input type="date" id="fecha_fin" name="fecha_fin" value="<?= htmlspecialchars($values['fecha_fin'], ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div>
                <label for="contacto_seguro_nombre">Contacto de emergencia</label>
                <input type="text" id="contacto_seguro_nombre" name="contacto_seguro_nombre" value="<?= htmlspecialchars($values['contacto_seguro_nombre'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nombre del contacto" />
            </div>
            <div>
                <label for="contacto_seguro_telefono">Teléfono del contacto</label>
                <input type="tel" id="contacto_seguro_telefono" name="contacto_seguro_telefono" value="<?= htmlspecialchars($values['contacto_seguro_telefono'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ej. 555-123-4567" />
            </div>
        </div>
        <div class="pm-form-actions">
            <a href="../proyectos.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar cambios</button>
        </div>
    </form>
</div>
</body>
</html>
