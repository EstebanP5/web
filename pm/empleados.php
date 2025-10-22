<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'pm') { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../includes/db.php';
$pm_id=(int)$_SESSION['user_id'];

// Proyectos del PM
$proyectos=[]; $st=$conn->prepare("SELECT g.id,g.nombre,g.empresa,g.localidad,g.fecha_inicio,g.fecha_fin,g.lat,g.lng FROM proyectos_pm ppm JOIN grupos g ON ppm.proyecto_id=g.id WHERE ppm.user_id=? AND g.activo=1 ORDER BY g.nombre");
$st->bind_param('i',$pm_id); $st->execute(); $r=$st->get_result(); while($p=$r->fetch_assoc()) $proyectos[]=$p;
$permitidos=array_column($proyectos,'id');
$proyecto_id = isset($_GET['proyecto']) && ctype_digit($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
if($proyecto_id && !in_array($proyecto_id,$permitidos)) $proyecto_id=0;

// Consulta de Servicios Especializados
if($proyecto_id){
  $sqlEmp = "SELECT e.id, e.nombre, e.nss, e.empresa, u.email,
                    IFNULL(SUM(CASE WHEN a.hora_entrada IS NOT NULL AND a.hora_salida IS NOT NULL THEN TIMESTAMPDIFF(SECOND,a.hora_entrada,a.hora_salida) ELSE 0 END),0) segs
             FROM (
               SELECT empleado_id FROM empleado_proyecto WHERE proyecto_id = ?
               UNION
               SELECT empleado_id FROM empleado_asignaciones WHERE proyecto_id = ?
             ) rel
             JOIN empleados e ON e.id = rel.empleado_id AND e.activo = 1
             LEFT JOIN users u ON u.id = e.id
             LEFT JOIN asistencia a ON a.empleado_id = e.id AND a.proyecto_id = ? AND a.fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY e.id
             ORDER BY e.nombre";
  if($st2 = $conn->prepare($sqlEmp)){
    $st2->bind_param('iii', $proyecto_id, $proyecto_id, $proyecto_id);
    $st2->execute();
    $empleados = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
    $st2->close();
  } else {
    $empleados = [];
  }
} else {
  $sqlEmp = "SELECT e.id, e.nombre, e.nss, e.empresa, u.email,
                     GROUP_CONCAT(DISTINCT g.nombre ORDER BY g.nombre SEPARATOR ' / ') proyectos
             FROM empleados e
             LEFT JOIN empleado_proyecto ep ON ep.empleado_id = e.id AND ep.activo = 1
             LEFT JOIN grupos g ON g.id = ep.proyecto_id
             LEFT JOIN users u ON u.id = e.id
             WHERE e.activo = 1
             GROUP BY e.id
             ORDER BY e.nombre";
  $result = $conn->query($sqlEmp);
  $empleados = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
$pmCssPath = __DIR__ . '/../assets/pm.css';
$pmCssVersion = file_exists($pmCssPath) ? filemtime($pmCssPath) : time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
  <title>Servicios Especializados - PM Dashboard</title>
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
    --danger-color: #ef4444;
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

.btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  border-radius: 10px;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
  border: none;
  transition: all 0.2s ease;
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

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 24px;
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
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

.filters {
    background: white;
    padding: 24px;
    border-radius: 16px;
    border: 1px solid var(--gray-200);
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    margin-bottom: 24px;
}

.filters-row {
    display: flex;
    gap: 20px;
    align-items: end;
    flex-wrap: wrap;
}

.filter-group {
    flex: 1;
    min-width: 200px;
}

.filter-group label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 8px;
}

.filter-group select {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid var(--gray-200);
    border-radius: 12px;
    font-size: 14px;
    background: white;
    color: var(--gray-900);
    transition: all 0.3s;
}

.filter-group select:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.stat-card {
    background: white;
    padding: 24px;
    border-radius: 16px;
    border: 1px solid var(--gray-200);
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    text-align: center;
    transition: all 0.3s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--primary-color);
    margin-bottom: 8px;
}

.stat-label {
    font-size: 14px;
    color: var(--gray-600);
    font-weight: 500;
}

.employees-table {
    background: white;
    border-radius: 16px;
    border: 1px solid var(--gray-200);
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    overflow: hidden;
}

.table-header {
    padding: 24px;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
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
    padding: 16px 20px;
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
    padding: 16px 20px;
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

.employee-projects {
    font-size: 12px;
    color: var(--gray-600);
    max-width: 300px;
    line-height: 1.4;
}

.hours-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--gray-100);
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    color: var(--gray-700);
}

.hours-badge.high {
    background: #dcfce7;
    color: #166534;
}

.hours-badge.medium {
    background: #fef3c7;
    color: #92400e;
}

.hours-badge.low {
    background: #fee2e2;
    color: #991b1b;
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

.action-cell {
  display: flex;
  gap: 10px;
  align-items: center;
}

.action-btn {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid var(--gray-200);
  background: var(--gray-50);
  color: var(--gray-600);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  transition: all 0.2s ease;
}

.action-btn:hover {
  background: var(--primary-color);
  border-color: var(--primary-color);
  color: #ffffff;
  transform: translateY(-2px);
  box-shadow: 0 10px 20px rgba(59, 130, 246, 0.25);
}

@media (max-width: 768px) {
    .container {
        padding: 20px 16px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filters-row {
        flex-direction: column;
    }
    
    .filter-group {
        min-width: auto;
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
    
    .employee-projects {
        max-width: 200px;
    }
}
</style>

<div class="container">
  <div class="page-header">
    <div class="header-content">
  <h1><i class="fas fa-users"></i> Servicios Especializados<?php if($proyecto_id){ foreach($proyectos as $p){ if($p['id']==$proyecto_id){ echo ' - '.htmlspecialchars($p['nombre']); break; } } } ?></h1>
  <p>Gestiona y supervisa a tus Servicios Especializados activos</p>
    </div>
    <div class="header-actions">
      <a href="forms/crear_servicio.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Nuevo Servicio</a>
    </div>
  </div>

  <div class="filters">
    <form method="get" class="filters-row">
      <div class="filter-group">
        <label for="proyecto">Filtrar por Proyecto</label>
        <select name="proyecto" id="proyecto" onchange="this.form.submit()">
          <option value="0">Todos los Proyectos</option>
          <?php foreach($proyectos as $p){ echo '<option value="'.$p['id'].'"'.($proyecto_id==$p['id']?' selected':'').'>'.htmlspecialchars($p['nombre']).'</option>'; } ?>
        </select>
      </div>
    </form>
  </div>

  <?php if(!empty($empleados)): ?>
  <div class="stats-grid">
    <div class="stat-card">
  <div class="stat-value"><?= count($empleados) ?></div>
  <div class="stat-label">Total Servicios Especializados</div>
    </div>
    <?php if($proyecto_id): ?>
    <div class="stat-card">
      <div class="stat-value"><?= number_format(array_sum(array_column($empleados, 'segs')) / 3600, 1) ?>h</div>
      <div class="stat-label">Horas Totales (7d)</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= count($empleados) > 0 ? number_format(array_sum(array_column($empleados, 'segs')) / 3600 / count($empleados), 1) : 0 ?>h</div>
  <div class="stat-label">Promedio por Servicio Especializado</div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="employees-table">
    <div class="table-header">
      <div class="table-title">
  <i class="fas fa-users"></i>
  Lista de Servicios Especializados
      </div>
    </div>

    <?php if(empty($empleados)): ?>
    <div class="empty-state">
      <i class="fas fa-users-slash"></i>
  <h3>No hay Servicios Especializados</h3>
  <p>No se encontraron Servicios Especializados en el proyecto seleccionado</p>
    </div>
    <?php else: ?>
    <div class="table-container">
      <table>
        <thead>
          <tr>
            <?php if(!$proyecto_id): ?><th>Proyectos Asignados</th><?php endif; ?>
            <th>Nombre</th>
            <th>Empresa</th>
            <th>NSS</th>
            <th>Email</th>
            <?php if($proyecto_id): ?><th>Horas Trabajadas (7 días)</th><?php endif; ?>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($empleados as $e): 
            $horas = $proyecto_id ? floor($e['segs']/3600) : 0;
            $minutos = $proyecto_id ? floor(($e['segs']%3600)/60) : 0;
            $hoursClass = '';
            if($proyecto_id) {
              if($horas >= 35) $hoursClass = 'high';
              elseif($horas >= 20) $hoursClass = 'medium';
              elseif($horas > 0) $hoursClass = 'low';
            }
          ?>
            <tr>
              <?php if(!$proyecto_id): ?>
              <td>
                <div class="employee-projects"><?= htmlspecialchars($e['proyectos'] ?? '') ?></div>
              </td>
              <?php endif; ?>
              <td>
                <div class="employee-name"><?= htmlspecialchars($e['nombre']) ?></div>
              </td>
              <td><?= htmlspecialchars($e['empresa'] ?? '-') ?></td>
              <td><?= htmlspecialchars($e['nss'] ?? '-') ?></td>
              <td><?= htmlspecialchars($e['email'] ?? '-') ?></td>
              <?php if($proyecto_id): ?>
              <td>
                <?php if($horas > 0 || $minutos > 0): ?>
                <div class="hours-badge <?= $hoursClass ?>">
                  <i class="fas fa-clock"></i>
                  <?= sprintf('%02dh %02dm', $horas, $minutos) ?>
                </div>
                <?php else: ?>
                <span style="color: var(--gray-500);">Sin registro</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
              <td class="action-cell">
                <a
                  href="forms/editar_servicio.php?id=<?= $e['id'] ?>"
                  class="action-btn"
                  title="Editar <?= htmlspecialchars($e['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                  aria-label="Editar <?= htmlspecialchars($e['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                >
                  <i class="fas fa-pen"></i>
                </a>
                <button
                  class="action-btn"
                  style="color: var(--danger-color); background: none; border: none; cursor: pointer;"
                  title="Eliminar <?= htmlspecialchars($e['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                  aria-label="Eliminar <?= htmlspecialchars($e['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                  onclick="eliminarEmpleado(<?= $e['id'] ?>, '<?= htmlspecialchars(addslashes($e['nombre'])) ?>')"
                >
                  <i class="fas fa-trash"></i>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<script>
function eliminarEmpleado(id, nombre) {
  if(!confirm('¿Seguro que deseas eliminar el Servicio Especializado "' + nombre + '"? Esta acción no se puede deshacer.')) return;
  fetch('eliminar_empleado.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'id=' + encodeURIComponent(id)
  })
  .then(r => r.json())
  .then(data => {
    if(data.success) {
      alert('Servicio eliminado correctamente.');
      location.reload();
    } else {
      alert('No se pudo eliminar el Servicio.');
    }
  })
  .catch(() => alert('Error al eliminar el Servicio.'));
}
</script>
  </div>
</div>
</body></html>