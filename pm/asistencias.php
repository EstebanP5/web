<?php
// Listado de asistencias (todas) limitado a proyectos del PM con filtros y exportación
session_start();
date_default_timezone_set('America/Mexico_City');
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'pm') { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../includes/db.php';

$pm_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? '';

// Proyectos asignados
$proyectos = [];
$st = $conn->prepare("SELECT g.id,g.nombre FROM proyectos_pm ppm JOIN grupos g ON ppm.proyecto_id=g.id WHERE ppm.user_id=? AND g.activo=1 ORDER BY g.nombre");
$st->bind_param('i',$pm_id); $st->execute(); $rs=$st->get_result();
while($p=$rs->fetch_assoc()){ $proyectos[]=$p; }
$proyecto_ids = array_column($proyectos,'id');
if(empty($proyecto_ids)) { $proyecto_ids = [0]; }

// Filtros
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d', strtotime('-7 days'));
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$proyecto_filtro = isset($_GET['proyecto']) && ctype_digit($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
if($proyecto_filtro && !in_array($proyecto_filtro,$proyecto_ids)) $proyecto_filtro = 0; // seguridad

$servicio_especializado_filtro = 0;
if (isset($_GET['servicio_especializado']) && ctype_digit($_GET['servicio_especializado'])) {
  $servicio_especializado_filtro = (int)$_GET['servicio_especializado'];
} elseif (isset($_GET['subcontratista']) && ctype_digit($_GET['subcontratista'])) {
  // Compatibilidad retro con enlaces o marcadores antiguos
  $servicio_especializado_filtro = (int)$_GET['subcontratista'];
} elseif (isset($_GET['empleado']) && ctype_digit($_GET['empleado'])) {
  // Compatibilidad adicional con parámetros legacy
  $servicio_especializado_filtro = (int)$_GET['empleado'];
}

// Servicios Especializados para filtro (solo de proyectos del PM)
$in = implode(',', array_map('intval',$proyecto_ids));
$servicios_especializados = [];
$empRs = $conn->query("SELECT DISTINCT e.id,e.nombre FROM empleados e JOIN empleado_proyecto ep ON ep.empleado_id=e.id WHERE ep.proyecto_id IN ($in) AND ep.activo=1 AND e.activo=1 ORDER BY e.nombre");
while($e=$empRs->fetch_assoc()) $servicios_especializados[]=$e;

// Construir consulta
$where = ["a.fecha BETWEEN ? AND ?", "a.proyecto_id IN ($in)"];
$params = [$fecha_inicio,$fecha_fin];
$types = 'ss';
if($proyecto_filtro){ $where[]='a.proyecto_id=?'; $params[]=$proyecto_filtro; $types.='i'; }
if($servicio_especializado_filtro){ $where[]='a.empleado_id=?'; $params[]=$servicio_especializado_filtro; $types.='i'; }
$where_sql = implode(' AND ',$where);

$sql = "SELECT a.*, e.nombre servicio_especializado_nombre, g.nombre proyecto_nombre, g.empresa,
 CASE WHEN a.hora_entrada IS NOT NULL AND a.hora_salida IS NOT NULL THEN TIMEDIFF(a.hora_salida,a.hora_entrada) END horas_trabajadas,
 (SELECT COUNT(*) FROM descansos d WHERE d.empleado_id=a.empleado_id AND d.proyecto_id=a.proyecto_id AND d.fecha=a.fecha) descansos_count,
 (SELECT SEC_TO_TIME(SUM(TIMESTAMPDIFF(SECOND,d.inicio, COALESCE(d.fin,NOW())))) FROM descansos d WHERE d.empleado_id=a.empleado_id AND d.proyecto_id=a.proyecto_id AND d.fecha=a.fecha) descansos_duracion,
 (SELECT GROUP_CONCAT(CONCAT(
    DATE_FORMAT(d.inicio,'%H:%i'),' - ',
    IF(d.fin IS NULL,'Pendiente',DATE_FORMAT(d.fin,'%H:%i')),
    IF(d.motivo IS NULL OR d.motivo='', '', CONCAT(' · ', d.motivo))
  ) ORDER BY d.inicio SEPARATOR ' | ')
  FROM descansos d WHERE d.empleado_id=a.empleado_id AND d.proyecto_id=a.proyecto_id AND d.fecha=a.fecha
 ) descansos_detalle
 FROM asistencia a JOIN empleados e ON e.id=a.empleado_id JOIN grupos g ON g.id=a.proyecto_id
 WHERE $where_sql
 ORDER BY a.fecha DESC, a.hora_entrada DESC LIMIT 800";
$st = $conn->prepare($sql);
if($params){ $st->bind_param($types, ...$params); }
$st->execute(); $asistencias = $st->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($asistencias as &$registro) {
  $registro['descansos_parsed'] = [];
  if (!empty($registro['descansos_detalle'])) {
    $segmentos = array_filter(array_map('trim', explode('|', $registro['descansos_detalle'])));
    foreach ($segmentos as $segmento) {
      $partes = array_map('trim', explode('·', $segmento));
      $franja = $partes[0] ?? '';
      $motivo = $partes[1] ?? '';
      $inicio = $franja;
      $fin = '';
      if (strpos($franja, '-') !== false) {
        [$inicio, $fin] = array_map('trim', explode('-', $franja, 2));
      }
      $estado = 'cerrado';
      if (strcasecmp($fin, 'Pendiente') === 0 || $fin === '') {
        $estado = 'abierto';
        $fin = '';
      }
      $registro['descansos_parsed'][] = [
        'inicio' => $inicio,
        'fin' => $fin,
        'motivo' => $motivo,
        'estado' => $estado
      ];
    }
  }
}
unset($registro);

// Stats
$sqlS = "SELECT COUNT(*) total,
 SUM(CASE WHEN a.hora_entrada IS NOT NULL AND a.hora_salida IS NOT NULL THEN 1 END) completas,
 SUM(CASE WHEN a.hora_entrada IS NOT NULL AND a.hora_salida IS NULL THEN 1 END) abiertas,
 COUNT(DISTINCT a.empleado_id) servicios_especializados
 FROM asistencia a WHERE $where_sql";
$st2=$conn->prepare($sqlS); if($params){ $st2->bind_param($types, ...$params);} $st2->execute(); $stats=$st2->get_result()->fetch_assoc();

// Exportar
if(isset($_GET['export']) && $_GET['export']==='excel'){
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="asistencias_pm_'.date('Ymd').'.xls"');
  echo "<table border='1'><tr><th>Fecha</th><th>Servicio Especializado</th><th>Proyecto</th><th>Entrada</th><th>Salida</th><th>Horas</th><th>Descansos</th><th>Tiempo Descanso</th><th>Detalle Descansos</th></tr>";
    foreach($asistencias as $a){
        echo '<tr>'; 
        echo '<td>'.date('d/m/Y',strtotime($a['fecha'])).'</td>';
  echo '<td>'.htmlspecialchars($a['servicio_especializado_nombre']).'</td>';
        echo '<td>'.htmlspecialchars($a['proyecto_nombre']).'</td>';
        echo '<td>'.($a['hora_entrada']?date('H:i',strtotime($a['hora_entrada'])):'-').'</td>';
        echo '<td>'.($a['hora_salida']?date('H:i',strtotime($a['hora_salida'])):'-').'</td>';
        echo '<td>'.($a['horas_trabajadas']?:'-').'</td>';
        echo '<td>'.($a['descansos_count']??0).'</td>';
        echo '<td>'.($a['descansos_duracion']?:'-').'</td>';
    echo '<td>'.htmlspecialchars($a['descansos_detalle'] ?? '').'</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}
$pmCssPath = __DIR__ . '/../assets/pm.css';
$pmCssVersion = file_exists($pmCssPath) ? filemtime($pmCssPath) : time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asistencias - PM Dashboard</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/pm.css?v=<?= $pmCssVersion; ?>">
</head>
<body>
<?php require_once 'common/navigation.php'; ?>

<style>
:root {
    --primary-color: #3b82f6;
    --primary-dark: #1d4ed8;
    --success-color: #10b981;
    --success-dark: #059669;
    --warning-color: #f59e0b;
    --warning-dark: #d97706;
    --danger-color: #ef4444;
    --danger-dark: #dc2626;
    --gray-50: #f8fafc;
    --gray-100: #f1f5f9;
    --gray-200: #e2e8f0;
    --gray-300: #cbd5e1;
    --gray-400: #94a3b8;
    --gray-500: #64748b;
    --gray-600: #475569;
    --gray-700: #334155;
    --gray-800: #1e293b;
    --gray-900: #0f172a;
}


body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--gray-50);
    margin: 0;
    color: var(--gray-900);
    transition: all 0.3s ease;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 32px 24px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 24px;
}

.header-content h1 {
    font-size: 32px;
    font-weight: 700;
    margin: 0;
    color: var(--gray-900);
    display: flex;
    align-items: center;
    gap: 16px;
}

.header-content p {
    margin: 8px 0 0;
    color: var(--gray-600);
    font-size: 16px;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.btn {
    padding: 12px 20px;
    border: none;
    border-radius: 12px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 48px;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
}

.btn-success {
    background: linear-gradient(135deg, var(--success-color), var(--success-dark));
    color: white;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.filters-panel {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    padding: 28px;
    margin-bottom: 32px;
}

.filters-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    font-size: 14px;
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 8px;
}

.filter-group input,
.filter-group select {
    padding: 12px 16px;
    border: 2px solid var(--gray-200);
    border-radius: 12px;
    font-size: 14px;
    background: white;
    color: var(--gray-900);
    transition: all 0.3s;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.filter-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    flex-wrap: wrap;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.stat-card {
    background: white;
    padding: 28px;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    text-align: center;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary-color), var(--success-color));
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.12);
}

.stat-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 16px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: white;
}

.stat-icon.total { background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); }
.stat-icon.complete { background: linear-gradient(135deg, var(--success-color), var(--success-dark)); }
.stat-icon.open { background: linear-gradient(135deg, var(--warning-color), var(--warning-dark)); }
.stat-icon.employees { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }

.stat-value {
    font-size: 36px;
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: 8px;
}

.stat-label {
    font-size: 14px;
    color: var(--gray-600);
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.attendance-table {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    overflow: hidden;
}

.table-header {
    padding: 28px;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.table-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--gray-900);
    display: flex;
    align-items: center;
    gap: 12px;
}

.table-container {
    overflow-x: auto;
    max-height: 70vh;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    background: var(--gray-50);
    padding: 18px 20px;
    text-align: left;
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
    position: sticky;
    top: 0;
    z-index: 10;
}

td {
    padding: 18px 20px;
    border-bottom: 1px solid var(--gray-100);
    font-size: 14px;
    color: var(--gray-700);
    vertical-align: middle;
}

tr:hover {
    background: var(--gray-50);
}

.employee-name {
    font-weight: 600;
    color: var(--gray-900);
}

.project-name {
    font-size: 13px;
    color: var(--gray-600);
}

.time-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.time-badge.entrada {
    background: #dcfce7;
    color: #166534;
}

.time-badge.salida {
    background: #fee2e2;
    color: #991b1b;
}

.hours-display {
    font-weight: 600;
    color: var(--primary-color);
}

.break-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
}

.break-count {
    background: var(--gray-100);
    color: var(--gray-700);
    padding: 4px 8px;
    border-radius: 12px;
    font-weight: 600;
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    color: var(--gray-600);
}

.empty-state i {
    font-size: 64px;
    color: var(--gray-400);
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 20px;
    margin-bottom: 8px;
    color: var(--gray-700);
}

@media (max-width: 768px) {
    .container {
        padding: 20px 16px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filters-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-actions {
        justify-content: stretch;
    }
    
    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
    
    .table-container {
        max-height: 60vh;
    }
    
    th, td {
        padding: 12px 16px;
        font-size: 13px;
    }
    
    .stat-card {
        padding: 20px;
    }
    
    .stat-icon {
        width: 48px;
        height: 48px;
        font-size: 20px;
    }
    
    .stat-value {
        font-size: 28px;
    }
}
</style>

<div class="container">
  <div class="page-header">
    <div class="header-content">
      <h1><i class="fas fa-calendar-check"></i> Control de Asistencias</h1>
      <p>Supervisa la asistencia de tu equipo en todos los proyectos</p>
    </div>
    <div class="header-actions">
      <a href="?<?php echo http_build_query(array_merge($_GET,['export'=>'excel'])); ?>" class="btn btn-success">
        <i class="fas fa-file-excel"></i>
        Exportar Excel
      </a>
    </div>
  </div>

  <div class="filters-panel">
    <div class="filters-title">
      <i class="fas fa-filter"></i>
      Filtros de Búsqueda
    </div>
    
    <form method="get">
      <div class="filters-grid">
        <div class="filter-group">
          <label for="fecha_inicio">Fecha de Inicio</label>
          <input type="date" name="fecha_inicio" id="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>">
        </div>
        
        <div class="filter-group">
          <label for="fecha_fin">Fecha Final</label>
          <input type="date" name="fecha_fin" id="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>">
        </div>
        
        <div class="filter-group">
          <label for="proyecto">Proyecto</label>
          <select name="proyecto" id="proyecto">
            <option value="0">Todos los Proyectos</option>
            <?php foreach($proyectos as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $proyecto_filtro == $p['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['nombre']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="filter-group">
          <label for="servicio_especializado">Servicio Especializado</label>
          <select name="servicio_especializado" id="servicio_especializado">
            <option value="0">Todos los Servicios Especializados</option>
            <?php foreach($servicios_especializados as $se): ?>
            <option value="<?= $se['id'] ?>" <?= $servicio_especializado_filtro == $se['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($se['nombre']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      
      <div class="filter-actions">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-search"></i>
          Aplicar Filtros
        </button>
        <a href="asistencias.php" class="btn" style="background: var(--gray-200); color: var(--gray-700);">
          <i class="fas fa-times"></i>
          Limpiar
        </a>
      </div>
    </form>
  </div>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon total">
        <i class="fas fa-clipboard-list"></i>
      </div>
      <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
      <div class="stat-label">Total Registros</div>
    </div>
    
    <div class="stat-card">
      <div class="stat-icon complete">
        <i class="fas fa-check-circle"></i>
      </div>
      <div class="stat-value"><?php echo (int)$stats['completas']; ?></div>
      <div class="stat-label">Jornadas Completas</div>
    </div>
    
    <div class="stat-card">
      <div class="stat-icon open">
        <i class="fas fa-clock"></i>
      </div>
      <div class="stat-value"><?php echo (int)$stats['abiertas']; ?></div>
      <div class="stat-label">Jornadas Abiertas</div>
    </div>
    
    <div class="stat-card">
      <div class="stat-icon employees">
        <i class="fas fa-users"></i>
      </div>
  <div class="stat-value"><?php echo (int)$stats['servicios_especializados']; ?></div>
  <div class="stat-label">Servicios Especializados Únicos</div>
    </div>
  </div>

  <div class="attendance-table">
    <div class="table-header">
      <div class="table-title">
        <i class="fas fa-table"></i>
        Registro de Asistencias
      </div>
      <div style="font-size: 14px; color: var(--gray-600);">
        Mostrando <?= count($asistencias) ?> registros
      </div>
    </div>

    <?php if(empty($asistencias)): ?>
    <div class="empty-state">
      <i class="fas fa-calendar-times"></i>
      <h3>No hay registros</h3>
      <p>No se encontraron asistencias en el rango de fechas seleccionado</p>
    </div>
    <?php else: ?>
    <div class="table-container">
      <table>
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Servicio Especializado</th>
            <th>Proyecto</th>
            <th>Entrada</th>
            <th>Salida</th>
            <th>Horas Trabajadas</th>
            <th>Descansos</th>
            <th>Tiempo Descanso</th>
            <th>Detalle Descansos</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($asistencias as $a): ?>
          <tr>
            <td>
              <div style="font-weight: 600;"><?= date('d/m/Y', strtotime($a['fecha'])) ?></div>
              <div style="font-size: 12px; color: var(--gray-500);"><?= date('l', strtotime($a['fecha'])) ?></div>
            </td>
            <td>
              <div class="employee-name"><?= htmlspecialchars($a['servicio_especializado_nombre']) ?></div>
            </td>
            <td>
              <div class="employee-name"><?= htmlspecialchars($a['proyecto_nombre']) ?></div>
              <div class="project-name"><?= htmlspecialchars($a['empresa']) ?></div>
            </td>
            <td>
              <?php if($a['hora_entrada']): ?>
              <div class="time-badge entrada">
                <i class="fas fa-sign-in-alt"></i>
                <?= date('H:i', strtotime($a['hora_entrada'])) ?>
              </div>
              <?php else: ?>
              <span style="color: var(--gray-500);">-</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if($a['hora_salida']): ?>
              <div class="time-badge salida">
                <i class="fas fa-sign-out-alt"></i>
                <?= date('H:i', strtotime($a['hora_salida'])) ?>
              </div>
              <?php else: ?>
              <span style="color: var(--warning-color); font-weight: 600;">En curso</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if($a['horas_trabajadas']): ?>
              <div class="hours-display"><?= $a['horas_trabajadas'] ?></div>
              <?php else: ?>
              <span style="color: var(--gray-500);">-</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="break-info">
                <div class="break-count"><?= $a['descansos_count'] ?? 0 ?></div>
                <span style="font-size: 11px; color: var(--gray-500);">descansos</span>
              </div>
            </td>
            <td>
              <?php if($a['descansos_duracion']): ?>
              <div style="color: var(--warning-color); font-weight: 600;"><?= $a['descansos_duracion'] ?></div>
              <?php else: ?>
              <span style="color: var(--gray-500);">00:00:00</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($a['descansos_parsed'])): ?>
                <div style="display:flex;flex-direction:column;gap:6px;">
                  <?php foreach ($a['descansos_parsed'] as $descanso): ?>
                    <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;padding:6px 8px;font-size:12px;color:#92400e;display:flex;flex-direction:column;gap:4px;">
                      <div style="display:flex;align-items:center;gap:6px;">
                        <span style="font-weight:600;">
                          <?= htmlspecialchars($descanso['inicio']) ?>
                          <?php if ($descanso['fin'] !== ''): ?>
                            – <?= htmlspecialchars($descanso['fin']) ?>
                          <?php endif; ?>
                        </span>
                        <?php if ($descanso['estado'] === 'abierto'): ?>
                          <span style="background:#f97316;color:#fff;padding:2px 6px;border-radius:999px;font-size:11px;">Activo</span>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($descanso['motivo'])): ?>
                        <span><?= htmlspecialchars($descanso['motivo']) ?></span>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span style="color: var(--gray-500);">-</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
</body></html>