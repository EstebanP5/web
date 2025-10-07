<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_rol'] ?? '') !== 'empleado') {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? '';

// Obtener proyecto activo asignado (si existe)
$proyecto = null;
$stmt = $conn->prepare("SELECT g.* FROM empleado_proyecto ep JOIN grupos g ON g.id = ep.proyecto_id WHERE ep.empleado_id = ? AND ep.activo = 1 LIMIT 1");
$stmt->bind_param('i', $user_id);
if ($stmt->execute()) {
    $proyecto = $stmt->get_result()->fetch_assoc();
}
$stmt->close();

// Link de asistencia pública (si hay proyecto con token)
$asistencia_url = '';
$emergencia_url = '';
if ($proyecto && !empty($proyecto['token'])) {
    $asistencia_url = "../public/asistencia.php?token=" . urlencode($proyecto['token']);
    $emergencia_url = "../public/emergency.php?token=" . urlencode($proyecto['token']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard Servicio Especializado</title>
  <link rel="stylesheet" href="../assets/style.css">
  <style>
    body { font-family: Inter, system-ui, sans-serif; background:#f7f8fb; }
    .container { max-width:900px; margin:40px auto; background:#fff; border-radius:16px; padding:24px; box-shadow:0 10px 30px rgba(0,0,0,.08); }
    h1 { margin:0 0 8px; color:#2c3e50; }
    .muted { color:#6c757d; margin-bottom:16px; }
    .cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px; }
    .card { border:1px solid #e9ecef; border-radius:12px; padding:16px; }
    .btn { display:inline-block; background:#2ecc71; color:#fff; padding:12px 16px; border-radius:10px; text-decoration:none; font-weight:600; }
    .btn.secondary { background:#3498db; }
    .btn.emergency { background:linear-gradient(135deg,#ef4444,#b91c1c); color:#fff; box-shadow:0 8px 20px -12px rgba(239,68,68,.6); }
    .btn.emergency:hover { filter:brightness(.95); }
  </style>
</head>
<body>
  <div class="container">
    <h1>Hola, <?= htmlspecialchars($user_name) ?></h1>
  <p class="muted">Bienvenido al panel de Servicio Especializado.</p>

    <div class="cards">
      <div class="card">
        <h3>Proyecto activo</h3>
        <?php if ($proyecto): ?>
          <p><strong><?= htmlspecialchars($proyecto['nombre']) ?></strong></p>
          <p class="muted">Empresa: <?= htmlspecialchars($proyecto['empresa'] ?? '') ?></p>
          <?php if ($asistencia_url): ?>
            <a class="btn" href="<?= htmlspecialchars($asistencia_url) ?>">Registrar asistencia</a>
          <?php endif; ?>
          <?php if ($emergencia_url): ?>
            <div style="margin-top:12px;">
              <a class="btn emergency" href="<?= htmlspecialchars($emergencia_url) ?>" target="_blank" rel="noopener">Botón de emergencia</a>
            </div>
          <?php endif; ?>
          <div style="margin-top:12px;">
            <a class="btn secondary" style="background:#6366f1" href="../common/videos.php">Ver videos capacitación</a>
          </div>
        <?php else: ?>
          <p class="muted">Aún no tienes un proyecto activo asignado.</p>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3>Mi cuenta</h3>
        <p class="muted">Puedes cerrar sesión o contactar a tu PM si hay algún problema con tu acceso.</p>
        <a class="btn secondary" href="../logout.php">Cerrar sesión</a>
      </div>
    </div>
  </div>
</body>
</html>
