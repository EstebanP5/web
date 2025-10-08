<?php
require_once '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
  header('Location: ../login.php');
  exit;
}

$grupo_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($grupo_id <= 0) die('Falta ID del grupo');

// Desasignar empleado de este proyecto (no borrar el registro del empleado)
if (isset($_GET['eliminar_empleado'])) {
  $id_empleado = (int)$_GET['eliminar_empleado'];
  if ($id_empleado > 0) {
    $stmt = $conn->prepare("UPDATE empleado_proyecto SET activo=0 WHERE empleado_id=? AND proyecto_id=? AND activo=1");
    $stmt->bind_param('ii', $id_empleado, $grupo_id);
    $stmt->execute();
  }
  header("Location: editar_grupo.php?id=$grupo_id&msg=empleado_desasignado");
  exit;
}

$mensaje = $_GET['msg'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Actualizar datos del grupo y la relación con PM a través de proyectos_pm
  $pm_id = isset($_POST['pm_id']) ? (int)$_POST['pm_id'] : 0; // id en project_managers

  // Obtener datos del PM (incluye user_id para proyectos_pm)
  $stmtPm = $conn->prepare("SELECT user_id, nombre, telefono FROM project_managers WHERE id=? AND activo=1");
  $stmtPm->bind_param('i', $pm_id);
  $stmtPm->execute();
  $pm = $stmtPm->get_result()->fetch_assoc();

  // Normalizar coordenadas
  $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : 0.0;
  $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : 0.0;

  // Fechas del proyecto (permitir actualizar)
  $fecha_inicio_raw = isset($_POST['fecha_inicio']) ? trim((string)$_POST['fecha_inicio']) : '';
  $fecha_fin_raw = isset($_POST['fecha_fin']) ? trim((string)$_POST['fecha_fin']) : '';

  $fecha_inicio_sql = null;
  $fecha_fin_sql = null;

  if ($fecha_inicio_raw !== '') {
    try {
      $fecha_inicio_sql = (new DateTimeImmutable($fecha_inicio_raw))->format('Y-m-d');
    } catch (Throwable $e) {
      $fecha_inicio_sql = null;
    }
  }

  if ($fecha_fin_raw !== '') {
    try {
      $fecha_fin_sql = (new DateTimeImmutable($fecha_fin_raw))->format('Y-m-d');
    } catch (Throwable $e) {
      $fecha_fin_sql = null;
    }
  }

  // Actualizar tabla grupos (nota: no existe columna pm_id en grupos)
  $nombre = isset($_POST['nombre']) ? (string)$_POST['nombre'] : '';
  $localidad = isset($_POST['localidad']) ? (string)$_POST['localidad'] : '';
  $empresa = isset($_POST['empresa']) ? (string)$_POST['empresa'] : '';
  $pm_nombre = $pm && isset($pm['nombre']) ? (string)$pm['nombre'] : '';
  $pm_telefono = $pm && isset($pm['telefono']) ? (string)$pm['telefono'] : '';

  $stmt = $conn->prepare("UPDATE grupos SET nombre=?, localidad=?, fecha_inicio=?, fecha_fin=?, lat=?, lng=?, pm_nombre=?, pm_telefono=?, empresa=? WHERE id=?");
  $stmt->bind_param('ssssddsssi', $nombre, $localidad, $fecha_inicio_sql, $fecha_fin_sql, $lat, $lng, $pm_nombre, $pm_telefono, $empresa, $grupo_id);
  $stmt->execute();

  // Actualizar asignación del PM (limpiar y reinsertar)
  if ($del = $conn->prepare("DELETE FROM proyectos_pm WHERE proyecto_id=?")) {
    $del->bind_param('i', $grupo_id);
    $del->execute();
  }
  if ($pm && !empty($pm['user_id'])) {
    if ($ins = $conn->prepare("INSERT INTO proyectos_pm (user_id, proyecto_id) VALUES (?, ?)")) {
      $uid = (int)$pm['user_id'];
      $ins->bind_param('ii', $uid, $grupo_id);
      $ins->execute();
    }
  }

  header("Location: editar_grupo.php?id=$grupo_id&msg=actualizado");
  exit;
}

// Datos del grupo
$gs = $conn->prepare('SELECT * FROM grupos WHERE id=?');
$gs->bind_param('i', $grupo_id);
$gs->execute();
$grupo = $gs->get_result()->fetch_assoc();
if (!$grupo) die('Grupo no encontrado');

// Empleados asignados actualmente a este proyecto
$empleados = $conn->query("SELECT e.*, u.email FROM empleados e INNER JOIN empleado_proyecto ep ON ep.empleado_id=e.id AND ep.proyecto_id=$grupo_id AND ep.activo=1 LEFT JOIN users u ON u.id = e.id AND u.rol = 'servicio_especializado' WHERE e.activo=1 ORDER BY e.nombre")->fetch_all(MYSQLI_ASSOC);

$empleadoTotal = count($empleados);
$empleadosSinTelefono = array_filter($empleados, fn($e) => empty(trim($e['telefono'] ?? '')));
$empleadosSinDocumentos = array_filter($empleados, fn($e) => empty(trim($e['nss'] ?? '')) || empty(trim($e['curp'] ?? '')));

$latRaw = $grupo['lat'] ?? null;
$lngRaw = $grupo['lng'] ?? null;
$latHasValue = $latRaw !== null && $latRaw !== '';
$lngHasValue = $lngRaw !== null && $lngRaw !== '';
$latNumeric = $latHasValue && is_numeric($latRaw) ? (float)$latRaw : null;
$lngNumeric = $lngHasValue && is_numeric($lngRaw) ? (float)$lngRaw : null;
$latValid = $latNumeric !== null && $latNumeric >= -90 && $latNumeric <= 90;
$lngValid = $lngNumeric !== null && $lngNumeric >= -180 && $lngNumeric <= 180;
$coordsValid = $latValid && $lngValid;
$coordsIncomplete = ($latHasValue xor $lngHasValue);
$coordsOutOfRange = !$coordsIncomplete && ($latHasValue || $lngHasValue) && !$coordsValid;

$currentPm = null;
if ($stmtCurrentPm = $conn->prepare("SELECT pm.id, pm.nombre, pm.telefono, u.email FROM proyectos_pm ppm JOIN project_managers pm ON pm.user_id = ppm.user_id LEFT JOIN users u ON u.id = pm.user_id WHERE ppm.proyecto_id = ? LIMIT 1")) {
  $stmtCurrentPm->bind_param('i', $grupo_id);
  $stmtCurrentPm->execute();
  $currentPm = $stmtCurrentPm->get_result()->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
  <title>Editar Proyecto – <?= htmlspecialchars($grupo['nombre']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --primary: #2563eb;
      --primary-dark: #1d4ed8;
      --success: #16a34a;
      --warning: #f97316;
      --danger: #dc2626;
      --surface: #ffffff;
      --surface-muted: #f8fafc;
      --border: #e2e8f0;
      --text: #0f172a;
      --muted: #64748b;
      --shadow: 0 18px 60px rgba(15, 23, 42, 0.08);
    }
    * { box-sizing: border-box; }
    body {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      padding: 32px 20px 80px;
      margin: 0;
      background: linear-gradient(180deg, #eef2ff 0%, #f8fafc 60%);
      color: var(--text);
    }
    .page-wrapper {
      max-width: 1120px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 24px;
    }
    .page-card {
      background: var(--surface);
      border-radius: 24px;
      padding: 32px;
      box-shadow: var(--shadow);
      border: 1px solid rgba(37, 99, 235, 0.05);
    }
    .page-header {
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    .breadcrumb {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 14px;
      color: var(--muted);
    }
    .breadcrumb a {
      color: inherit;
      text-decoration: none;
    }
    .breadcrumb span {
      color: var(--text);
      font-weight: 600;
    }
    h1 {
      margin: 0;
      font-size: clamp(28px, 3vw, 36px);
      line-height: 1.2;
      color: var(--text);
    }
    .page-subtitle {
      color: var(--muted);
      max-width: 720px;
      margin: 0;
    }
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 18px;
    }
    .summary-card {
      background: var(--surface);
      border-radius: 18px;
      padding: 20px;
      border: 1px solid rgba(15, 23, 42, 0.05);
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .summary-card h3 {
      margin: 0;
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--muted);
    }
    .summary-card strong {
      font-size: 28px;
      color: var(--text);
    }
    .summary-meta {
      color: var(--muted);
      font-size: 13px;
    }
    .alert {
      padding: 16px 18px;
      border-radius: 14px;
      border: 1px solid transparent;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      margin-bottom: 18px;
    }
    .alert-success { background: #dcfce7; border-color: #bbf7d0; color: #14532d; }
    .alert-info { background: #dbeafe; border-color: #bfdbfe; color: #1e3a8a; }
    .alert button {
      background: transparent;
      border: none;
      cursor: pointer;
      color: inherit;
      font-size: 16px;
    }
    fieldset {
      border: none;
      margin: 0;
      padding: 0;
    }
    legend {
      font-weight: 600;
      margin-bottom: 16px;
      font-size: 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .form-section {
      background: var(--surface-muted);
      border-radius: 20px;
      padding: 26px;
      border: 1px solid rgba(148, 163, 184, 0.2);
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    label {
      font-weight: 600;
      color: var(--text);
      display: block;
      margin-bottom: 6px;
    }
    input[type="text"], input[type="number"], input[type="tel"], select {
      width: 100%;
      padding: 12px 14px;
      font-size: 15px;
      border-radius: 12px;
      border: 1.5px solid var(--border);
      background: #fff;
      transition: border-color .2s, box-shadow .2s;
    }
    input:focus, select:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
    }
    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 18px 24px;
    }
    .location-section {
      background: #eff6ff;
      border: 1px solid rgba(37, 99, 235, 0.15);
    }
    .location-input-group {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
    }
    .location-toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .address-display {
      background: #ecfdf3;
      border: 1px solid rgba(22, 163, 74, 0.25);
      border-radius: 16px;
      padding: 16px;
      display: none;
    }
    .address-display h4 { margin: 0 0 6px; color: var(--success); }
    .address-text { margin: 0; color: #166534; font-size: 14px; }
    .map-preview {
      margin-top: 18px;
      border-radius: 18px;
      overflow: hidden;
      border: 1px solid rgba(37, 99, 235, 0.25);
      min-height: 260px;
    }
    .map-preview iframe {
      width: 100%;
      height: 260px;
      border: none;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
    }
    .badge-warning { background: rgba(249, 115, 22, 0.12); color: #c2410c; }
    .badge-success { background: rgba(22, 163, 74, 0.12); color: #166534; }
    .coord-warning {
      display: none;
      background: rgba(220, 38, 38, 0.12);
      color: #b91c1c;
      border: 1px solid rgba(220, 38, 38, 0.25);
      border-radius: 12px;
      padding: 12px 14px;
      font-size: 13px;
      margin-top: 12px;
    }
    button, .btn {
      appearance: none;
      border: none;
      border-radius: 12px;
      padding: 11px 18px;
      font-weight: 600;
      cursor: pointer;
      transition: transform .15s ease, box-shadow .15s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      justify-content: center;
    }
    .btn-primary { background: var(--primary); color: #fff; box-shadow: 0 10px 30px rgba(37, 99, 235, 0.25); }
    .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
    .btn-secondary { background: #e2e8f0; color: #0f172a; }
    .btn-ghost { background: transparent; color: var(--muted); }
    .btn-warning { background: rgba(249, 115, 22, 0.15); color: #c2410c; }
    .btn-success { background: rgba(22, 163, 74, 0.12); color: #166534; }
    .btn-danger { background: rgba(220, 38, 38, 0.12); color: #b91c1c; }
    .btn-sm { padding: 8px 14px; font-size: 13px; }
    .loading { display:none; color: var(--primary); font-style: italic; }
    .helper-text { font-size: 13px; color: var(--muted); }
  .small-warning { font-size: 12px; color: #b91c1c; margin-top: 6px; }
    .assignments-callout {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 18px 20px;
      border-radius: 18px;
      background: linear-gradient(135deg, rgba(59, 130, 246, 0.12), rgba(14, 116, 144, 0.1));
      border: 1.5px solid rgba(37, 99, 235, 0.25);
      margin-bottom: 18px;
    }
    .assignments-callout i {
      font-size: 26px;
      color: #1d4ed8;
    }
    .assignments-callout strong {
      font-size: 15px;
      color: #0f172a;
      display: block;
    }
    .assignments-callout p {
      margin: 4px 0 0;
      font-size: 13px;
      color: #1f2937;
    }
    .empleados-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 18px;
    }
    .empleado-card {
      background: #fff;
      border: 1px solid rgba(148, 163, 184, 0.4);
      border-radius: 18px;
      padding: 18px;
      display: flex;
      flex-direction: column;
      gap: 14px;
      position: relative;
      transition: transform .2s ease, box-shadow .2s ease;
    }
    .empleado-card:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(2, 132, 199, 0.08); }
    .empleado-header { display: flex; align-items: center; justify-content: space-between; }
    .empleado-num { font-weight: 600; color: var(--primary); }
    .remove-empleado {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: rgba(220, 38, 38, 0.12);
      color: #b91c1c;
      text-decoration: none;
      align-items: center;
      justify-content: center;
      display: inline-flex;
    }
    .remove-empleado:hover { background: rgba(220, 38, 38, 0.2); }
    .empleado-inputs {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 12px;
    }
    .actions-bar {
      position: sticky;
      bottom: 20px;
      background: rgba(255,255,255,0.95);
      backdrop-filter: blur(10px);
      border-radius: 18px;
      padding: 14px;
      box-shadow: 0 18px 45px rgba(15, 23, 42, 0.18);
      display: flex;
      gap: 12px;
      justify-content: flex-end;
      align-items: center;
    }
    .divider {
      width: 100%;
      height: 1px;
      background: rgba(148, 163, 184, 0.35);
    }
    @media (max-width: 900px) {
      body { padding: 20px 16px 100px; }
      .page-card { padding: 22px; }
      .actions-bar {
        flex-direction: column;
        align-items: stretch;
        position: sticky;
        bottom: 10px;
      }
    }
  </style>
</head>
<body>
  <div class="page-wrapper">
    <section class="page-card page-header">
      <nav class="breadcrumb">
        <a href="admin.php">Admin</a>
        <span aria-hidden="true">/</span>
        <a href="proyectos.php">Proyectos</a>
        <span aria-hidden="true">/</span>
        <span><?=htmlspecialchars($grupo['nombre'])?></span>
      </nav>

      <div>
        <h1>Editar proyecto «<?=htmlspecialchars($grupo['nombre'])?>»</h1>
        <p class="page-subtitle">
          Ajusta la información clave del sitio, valida coordenadas y coordina al equipo asignado sin salir de esta vista.
        </p>
      </div>

      <?php if ($mensaje==='actualizado'): ?>
        <div class="alert alert-success" role="status">
          <span>✅ Grupo actualizado correctamente.</span>
          <button type="button" data-close="alert" aria-label="Cerrar alerta">✕</button>
        </div>
      <?php elseif ($mensaje==='empleado_desasignado'): ?>
        <div class="alert alert-info" role="status">
          <span>ℹ️ Empleado desasignado del proyecto.</span>
          <button type="button" data-close="alert" aria-label="Cerrar alerta">✕</button>
        </div>
      <?php endif; ?>

      <div class="summary-grid">
        <article class="summary-card">
          <h3>Equipo activo</h3>
          <strong><?=$empleadoTotal?></strong>
          <div class="summary-meta">
            <?= $empleadoTotal === 1 ? 'Colaborador asignado' : 'Colaboradores asignados' ?>
          </div>
          <?php if (count($empleadosSinTelefono) > 0): ?>
            <span class="badge badge-warning">⚠ <?=count($empleadosSinTelefono)?> sin teléfono</span>
          <?php else: ?>
            <span class="badge badge-success">✔ Contacto completo</span>
          <?php endif; ?>
        </article>
        <article class="summary-card">
          <h3>Documentación</h3>
          <strong><?=max($empleadoTotal - count($empleadosSinDocumentos), 0)?> / <?=$empleadoTotal?></strong>
          <div class="summary-meta">Registros con NSS y CURP</div>
          <?php if (count($empleadosSinDocumentos) > 0): ?>
            <span class="badge badge-warning">Completar <?=count($empleadosSinDocumentos)?> ficha(s)</span>
          <?php else: ?>
            <span class="badge badge-success">Todo en regla</span>
          <?php endif; ?>
        </article>
        <article class="summary-card">
          <h3>Ubicación</h3>
          <?php if ($coordsValid): ?>
            <strong><?=number_format($latNumeric, 4) ?><br><?=number_format($lngNumeric, 4)?></strong>
            <div class="summary-meta">Coordenadas GPS válidas</div>
            <span class="badge badge-success">Mapeo confirmado</span>
          <?php elseif ($coordsIncomplete): ?>
            <strong>Incompletas</strong>
            <div class="summary-meta">Captura ambos campos para habilitar el mapa</div>
            <span class="badge badge-warning">Falta latitud o longitud</span>
          <?php elseif ($coordsOutOfRange): ?>
            <strong>Fuera de rango</strong>
            <div class="summary-meta">Lat: -90 a 90 · Lng: -180 a 180</div>
            <span class="badge badge-warning">Revisa las coordenadas</span>
          <?php else: ?>
            <strong>Sin datos</strong>
            <div class="summary-meta">Añade coordenadas para habilitar el mapa</div>
            <span class="badge badge-warning">Pendiente de definir</span>
          <?php endif; ?>
        </article>
        <article class="summary-card">
          <h3>Project Manager</h3>
          <?php if ($currentPm): ?>
            <strong><?=htmlspecialchars($currentPm['nombre'] ?? 'PM asignado')?></strong>
            <div class="summary-meta">
              Tel. <?=htmlspecialchars($currentPm['telefono'] ?? '—')?><?php if (!empty($currentPm['email'])): ?> · <?=htmlspecialchars($currentPm['email'])?><?php endif; ?>
            </div>
          <?php else: ?>
            <strong>Sin asignar</strong>
            <div class="summary-meta">Selecciona un PM para este proyecto</div>
          <?php endif; ?>
        </article>
      </div>

    </section>

    <form method="post" class="page-card" id="editForm">
      <fieldset class="form-section">
        <legend>📋 Información del proyecto</legend>
        <div class="form-grid">
          <div>
            <label>Nombre del proyecto *</label>
            <input type="text" name="nombre" value="<?=htmlspecialchars($grupo['nombre'])?>" required>
          </div>
          <div>
            <label>Localidad *</label>
            <input type="text" name="localidad" id="localidad" value="<?=htmlspecialchars($grupo['localidad'])?>" required>
          </div>
          <div>
            <label>Empresa *</label>
            <input type="text" name="empresa" value="<?=htmlspecialchars($grupo['empresa'])?>" required>
          </div>
          <div>
            <label>Fecha de inicio</label>
            <input type="date" name="fecha_inicio" value="<?=htmlspecialchars($grupo['fecha_inicio'] ?? '')?>">
          </div>
          <div>
            <label>Fecha de fin</label>
            <input type="date" name="fecha_fin" value="<?=htmlspecialchars($grupo['fecha_fin'] ?? '')?>">
            <p class="small-warning">Cuando la fecha de fin es anterior a hoy el proyecto se cerrará automáticamente.</p>
          </div>
        </div>
        <p class="helper-text">Este nombre se usa en reportes, vistas públicas y dashboards del personal.</p>
      </fieldset>

      <fieldset class="form-section">
        <legend>👨‍💼 Project Manager</legend>
        <label>Selecciona el PM *</label>
        <select name="pm_id" required>
          <?php
          $pms = $conn->query("SELECT id, nombre, telefono FROM project_managers WHERE activo=1 ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
          $grupo_pm_id = 0;
          if ($stc = $conn->prepare("SELECT pm.id FROM proyectos_pm ppm JOIN project_managers pm ON pm.user_id = ppm.user_id WHERE ppm.proyecto_id=? LIMIT 1")) {
            $stc->bind_param('i', $grupo_id);
            $stc->execute();
            if ($row = $stc->get_result()->fetch_assoc()) {
              $grupo_pm_id = (int)$row['id'];
            }
          }
          foreach($pms as $pm):
          ?>
            <option value="<?=$pm['id']?>" <?=($grupo_pm_id==$pm['id']?'selected':'')?>><?=htmlspecialchars($pm['nombre'])?> (<?=htmlspecialchars($pm['telefono'])?>)</option>
          <?php endforeach; ?>
        </select>
  <p class="helper-text">Esto actualiza la relación oficial en proyectos_pm y sincroniza el contacto visible para Servicios Especializados.</p>
      </fieldset>

      <fieldset class="form-section location-section">
        <legend>📍 Ubicación GPS y geocodificación</legend>
        <?php if($coordsValid): ?>
          <div class="badge badge-success">Lat <?=number_format($latNumeric, 6)?> · Lng <?=number_format($lngNumeric, 6)?></div>
        <?php elseif($coordsIncomplete): ?>
          <div class="badge badge-warning">Completa ambos campos de latitud y longitud</div>
        <?php elseif($coordsOutOfRange): ?>
          <div class="badge badge-warning">Coordenadas fuera de rango permitido (Lat -90/90 · Lng -180/180)</div>
        <?php else: ?>
          <div class="badge badge-warning">Añade coordenadas para habilitar mapas y filtros geográficos</div>
        <?php endif; ?>

        <div class="location-input-group">
          <div>
            <label>Latitud</label>
            <input type="number" step="any" min="-90" max="90" name="lat" id="lat" value="<?=$grupo['lat']?>" onchange="actualizarDireccion()" oninput="actualizarDireccion()">
          </div>
          <div>
            <label>Longitud</label>
            <input type="number" step="any" min="-180" max="180" name="lng" id="lng" value="<?=$grupo['lng']?>" onchange="actualizarDireccion()" oninput="actualizarDireccion()">
          </div>
        </div>

        <div class="location-toolbar">
          <button type="button" class="btn-warning btn-sm" onclick="obtenerUbicacion()">📍 Obtener ubicación actual</button>
          <button type="button" class="btn-secondary btn-sm" onclick="limpiarCoordenadas()">🗑️ Limpiar coordenadas</button>
          <button type="button" class="btn-success btn-sm" onclick="guardarCoordenadas()">💾 Guardar coordenadas</button>
        </div>

        <div id="coord-warning" class="coord-warning"></div>

        <div class="loading" id="location-loading">🔄 Obteniendo ubicación...</div>
        <div class="loading" id="address-loading">🔄 Obteniendo dirección...</div>

        <div id="address-display" class="address-display">
          <h4>📍 Dirección encontrada</h4>
          <p class="address-text" id="address-text"></p>
        </div>

  <div class="map-preview" id="map-wrapper" style="display:<?=$coordsValid ? 'block' : 'none'?>;">
          <iframe id="map-preview" title="Mapa de la ubicación"></iframe>
        </div>

        <p class="helper-text">La localidad se sincroniza con la dirección estimada de OpenStreetMap. Puedes ajustar manualmente si es necesario.</p>
      </fieldset>

      <fieldset class="form-section">
        <legend>👥 Equipo asignado</legend>
        <div class="assignments-callout">
          <i class="fa-solid fa-people-arrows"></i>
          <div>
            <strong>Gestiona cambios desde la vista dedicada</strong>
            <p>Para agregar o quitar Servicios Especializados utiliza la sección <em>“Gestionar asignaciones”</em>. Aquí solo se muestra el personal actualmente activo en el proyecto.</p>
          </div>
          <a class="btn btn-primary btn-sm" href="proyecto_empleados.php?id=<?=$grupo_id?>">Gestionar asignaciones</a>
        </div>
        <div id="empleados-container" class="empleados-grid">
          <?php foreach($empleados as $i=>$e): ?>
          <div class="empleado-card" data-nombre="<?=strtolower(htmlspecialchars($e['nombre'].' '.$e['puesto'].' '.$e['nss'].' '.$e['curp']))?>">
            <div class="empleado-header">
              <span class="empleado-num">👤 <?=$i+1?></span>
              <a class="remove-empleado" title="Desasignar" href="?id=<?=$grupo_id?>&eliminar_empleado=<?=$e['id']?>" onclick="return confirm('¿Desasignar a <?=htmlspecialchars(addslashes($e['nombre']))?> de este proyecto?')">✕</a>
            </div>
            <input type="hidden" name="empleados[<?=$i?>][id]" value="<?=$e['id']?>">
            <div class="empleado-inputs">
              <div>
                <label>Nombre completo</label>
                <input type="text" value="<?=htmlspecialchars($e['nombre'])?>" readonly>
              </div>
              <div>
                <label>Teléfono</label>
                <input type="text" value="<?=htmlspecialchars($e['telefono'])?>" readonly>
              </div>
              <div>
                <label>Puesto</label>
                <input type="text" value="<?=htmlspecialchars($e['puesto'])?>" readonly>
              </div>
              <div>
                <label>NSS</label>
                <input type="text" value="<?=htmlspecialchars($e['nss'])?>" readonly>
              </div>
              <div>
                <label>CURP</label>
                <input type="text" value="<?=htmlspecialchars($e['curp'])?>" readonly>
              </div>
              <div>
                <label>Correo (credencial)</label>
                <input type="text" value="<?=htmlspecialchars($e['email'] ?? '')?>" readonly>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <div class="actions-bar">
        <a href="admin.php" class="btn btn-secondary">← Volver al panel</a>
        <button type="submit" class="btn btn-primary">💾 Guardar cambios</button>
      </div>
    </form>
  </div>

  <script>
    const mapWrapper = document.getElementById('map-wrapper');
    const mapFrame = document.getElementById('map-preview');
    const addressWrapper = document.getElementById('address-display');
    const addressText = document.getElementById('address-text');
    const coordWarning = document.getElementById('coord-warning');
    const LAT_MIN = -90, LAT_MAX = 90, LNG_MIN = -180, LNG_MAX = 180;
    let geocodingTimeout;

    function showCoordWarning(message = '') {
      if (!coordWarning) return;
      if (message) {
        coordWarning.textContent = message;
        coordWarning.style.display = 'block';
      } else {
        coordWarning.textContent = '';
        coordWarning.style.display = 'none';
      }
    }

    function coordsAreValid(lat, lng) {
      return !isNaN(lat) && !isNaN(lng) && lat >= LAT_MIN && lat <= LAT_MAX && lng >= LNG_MIN && lng <= LNG_MAX;
    }

    function filtrarEmpleados() {
      // Buscador removido por UX: se mantiene la función por compatibilidad en caso de llamadas legacy.
    }

    function renderMap(lat, lng) {
      if (!mapWrapper || !mapFrame) return;
      if (!coordsAreValid(lat, lng)) {
        mapWrapper.style.display = 'none';
        return;
      }
      const delta = 0.004;
      const south = Math.max(lat - delta, LAT_MIN);
      const north = Math.min(lat + delta, LAT_MAX);
      const west = Math.max(lng - delta, LNG_MIN);
      const east = Math.min(lng + delta, LNG_MAX);
      const bbox = `${west.toFixed(6)},${south.toFixed(6)},${east.toFixed(6)},${north.toFixed(6)}`;
      mapFrame.src = `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${lat},${lng}`;
      mapWrapper.style.display = 'block';
    }

    function actualizarDireccion(){
      const latInput = document.getElementById('lat');
      const lngInput = document.getElementById('lng');
      const latTrim = latInput.value.trim();
      const lngTrim = lngInput.value.trim();
      if (latTrim === '' && lngTrim === '') {
        if (addressWrapper) addressWrapper.style.display='none';
        if (mapWrapper) mapWrapper.style.display='none';
        showCoordWarning('');
        return;
      }
      if (latTrim === '' || lngTrim === '') {
        if (addressWrapper) addressWrapper.style.display='none';
        if (mapWrapper) mapWrapper.style.display='none';
        showCoordWarning('Completa ambos campos de latitud y longitud.');
        return;
      }
      const lat = parseFloat(latTrim);
      const lng = parseFloat(lngTrim);
      if(isNaN(lat)||isNaN(lng)) {
        if (addressWrapper) addressWrapper.style.display='none';
        if (mapWrapper) mapWrapper.style.display='none';
        showCoordWarning('');
        return;
      }
      if (!coordsAreValid(lat, lng)) {
        showCoordWarning('Las coordenadas deben estar dentro del rango Lat -90 a 90 y Lng -180 a 180.');
        if (addressWrapper) addressWrapper.style.display='none';
        if (mapWrapper) mapWrapper.style.display='none';
        return;
      }
      showCoordWarning('');
      clearTimeout(geocodingTimeout);
      geocodingTimeout = setTimeout(()=> obtenerDireccionPorCoordenadas(lat,lng),600);
    }

    function guardarCoordenadas(){
      clearTimeout(geocodingTimeout);
      const latInput = document.getElementById('lat');
      const lngInput = document.getElementById('lng');
      const latTrim = latInput.value.trim();
      const lngTrim = lngInput.value.trim();
      if (latTrim === '' && lngTrim === '') {
        showCoordWarning('Ingresa latitud y longitud antes de guardar.');
        latInput.focus();
        return;
      }
      if (latTrim === '' || lngTrim === '') {
        showCoordWarning('Completa ambos campos de latitud y longitud.');
        (latTrim === '' ? latInput : lngInput).focus();
        return;
      }
      const lat = parseFloat(latTrim);
      const lng = parseFloat(lngTrim);
      if (isNaN(lat) || isNaN(lng)) {
        showCoordWarning('Las coordenadas deben ser números válidos.');
        latInput.focus();
        return;
      }
      if(!coordsAreValid(lat,lng)){
        showCoordWarning('Las coordenadas deben estar dentro del rango Lat -90 a 90 y Lng -180 a 180.');
        latInput.focus();
        return;
      }
      showCoordWarning('');
      obtenerDireccionPorCoordenadas(lat,lng);
    }

    function obtenerUbicacion(){
      const load = document.getElementById('location-loading');
      const elat = document.getElementById('lat'),
            elng = document.getElementById('lng');
      if(!navigator.geolocation){
        return alert('Geolocalización no soportada');
      }
      load.style.display='block';
      navigator.geolocation.getCurrentPosition(pos=>{
        load.style.display='none';
        elat.value = pos.coords.latitude.toFixed(6);
        elng.value = pos.coords.longitude.toFixed(6);
        actualizarDireccion();
      },err=>{
        load.style.display='none';
        alert('Error al obtener ubicación: '+err.message);
      },{enableHighAccuracy:true,timeout:10000,maximumAge:0});
    }

    function limpiarCoordenadas(){
      if(!confirm('¿Limpiar coordenadas?')) return;
      document.getElementById('lat').value='';
      document.getElementById('lng').value='';
      if (addressWrapper) addressWrapper.style.display='none';
      if (mapWrapper) mapWrapper.style.display='none';
      showCoordWarning('');
    }

    function obtenerDireccionPorCoordenadas(lat,lng){
      if (!coordsAreValid(lat, lng)) {
        showCoordWarning('Las coordenadas deben estar dentro del rango Lat -90 a 90 y Lng -180 a 180.');
        return;
      }
      const al = document.getElementById('address-loading');
      const locInput = document.getElementById('localidad');
      if (al) al.style.display='block';
      if (addressWrapper) addressWrapper.style.display='none';
      fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`)
        .then(r=>r.json()).then(data=>{
          if (al) al.style.display='none';
          if(data.display_name){
            if (addressText) addressText.textContent = data.display_name;
            if (addressWrapper) addressWrapper.style.display='block';
            let nc = data.address.town||data.address.city||data.address.village||data.address.county||'';
            if(nc) locInput.value = nc;
          } else {
            if (addressText) addressText.textContent = 'No se obtuvo dirección';
            if (addressWrapper) addressWrapper.style.display='block';
          }
          renderMap(lat,lng);
        }).catch(()=>{
          if (al) al.style.display='none';
          if (addressText) addressText.textContent='Error al obtener dirección';
          if (addressWrapper) addressWrapper.style.display='block';
        });
    }

    document.addEventListener('DOMContentLoaded',()=>{
      document.querySelectorAll('[data-close="alert"]').forEach(btn => {
        btn.addEventListener('click', () => btn.closest('.alert')?.remove());
      });

      const latInput = document.getElementById('lat');
      const lngInput = document.getElementById('lng');
      const latVal = parseFloat(latInput.value);
      const lngVal = parseFloat(lngInput.value);
      if(coordsAreValid(latVal, lngVal)) {
        renderMap(latVal, lngVal);
        if (addressText && addressText.textContent === '') {
          obtenerDireccionPorCoordenadas(latVal, lngVal);
        }
      } else if ((latInput.value.trim() !== '' || lngInput.value.trim() !== '') && !isNaN(latVal) && !isNaN(lngVal)) {
        showCoordWarning('Las coordenadas almacenadas están fuera de rango. Actualízalas para activar el mapa.');
      }
    });

    document.getElementById('editForm').addEventListener('submit',e=>{
      const latInput = document.getElementById('lat');
      const lngInput = document.getElementById('lng');
      const latVal = latInput.value.trim();
      const lngVal = lngInput.value.trim();
      if ((latVal === '' && lngVal !== '') || (latVal !== '' && lngVal === '')) {
        e.preventDefault();
        showCoordWarning('Proporciona latitud y longitud; no dejes solo uno de los campos.');
        (latVal === '' ? latInput : lngInput).focus();
        return;
      }
      if (latVal !== '' && lngVal !== '') {
        const latNum = parseFloat(latVal);
        const lngNum = parseFloat(lngVal);
        if (!coordsAreValid(latNum, lngNum)) {
          e.preventDefault();
          showCoordWarning('Las coordenadas deben estar dentro del rango Lat -90 a 90 y Lng -180 a 180.');
          latInput.focus();
          return;
        }
        showCoordWarning('');
      } else {
        showCoordWarning('');
      }

      for(const tel of document.querySelectorAll('input[type="tel"]')){
        if(tel.value && !/^\+?[\d\s\-\(\)]+$/.test(tel.value)){
          alert('Número de teléfono inválido');
          tel.focus();
          return e.preventDefault();
        }
      }
    });
  </script>
</body>
</html>
