<?php
session_start();
require_once '../includes/db.php';

// Verificar autenticación y rol SOLO responsable
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'responsable') {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? '';

// Obtener empresa del responsable
$stmt = $conn->prepare('SELECT empresa FROM empresas_responsables WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$responsableData = $stmt->get_result()->fetch_assoc();
$stmt->close();

$empresa_responsable = $responsableData['empresa'] ?? '';

$asistencias = [];
$proyectos_filtro = [];
$stats = [
    'total' => 0,
    'abiertas' => 0,
    'cerradas' => 0
];

// Filtros
$filtro_fecha = $_GET['fecha'] ?? date('Y-m-d');
$filtro_proyecto = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
$filtro_busqueda = trim($_GET['busqueda'] ?? '');

if ($empresa_responsable) {
    // Obtener proyectos para filtro
    $stmt = $conn->prepare('SELECT id, nombre FROM grupos WHERE empresa = ? AND activo = 1 ORDER BY nombre');
    $stmt->bind_param('s', $empresa_responsable);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $proyectos_filtro[] = $row;
    }
    $stmt->close();

    // Construir query de asistencias
    $sql = "SELECT a.*, e.nombre as empleado_nombre, e.nss, g.nombre as proyecto_nombre
            FROM asistencia a
            INNER JOIN empleados e ON a.empleado_id = e.id
            INNER JOIN grupos g ON a.proyecto_id = g.id
            WHERE g.empresa = ? AND a.fecha = ?";
    
    $params = [$empresa_responsable, $filtro_fecha];
    $types = 'ss';

    if ($filtro_proyecto > 0) {
        $sql .= ' AND g.id = ?';
        $params[] = $filtro_proyecto;
        $types .= 'i';
    }

    if ($filtro_busqueda) {
        $sql .= ' AND (e.nombre LIKE ? OR e.nss LIKE ?)';
        $busqueda = '%' . $filtro_busqueda . '%';
        $params[] = $busqueda;
        $params[] = $busqueda;
        $types .= 'ss';
    }

    $sql .= ' ORDER BY a.hora_entrada DESC';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $asistencias[] = $row;
        $stats['total']++;
        if (empty($row['hora_salida'])) {
            $stats['abiertas']++;
        } else {
            $stats['cerradas']++;
        }
    }
    $stmt->close();
}

// Función para calcular horas trabajadas
function calcularHorasTrabajadas($entrada, $salida) {
    if (!$entrada) return '-';
    
    $inicio = strtotime($entrada);
    $fin = $salida ? strtotime($salida) : time();
    
    $diff = $fin - $inicio;
    $horas = floor($diff / 3600);
    $minutos = floor(($diff % 3600) / 60);
    
    return sprintf('%02d:%02d', $horas, $minutos);
}

$pageTitle = 'Asistencias - Responsable';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            line-height: 1.6;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-header h1 {
            font-size: 28px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-header h1 i {
            color: #059669;
        }
        .empresa-badge {
            background: #059669;
            color: white;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-icon.blue { background: #dbeafe; color: #3b82f6; }
        .stat-icon.orange { background: #ffedd5; color: #ea580c; }
        .stat-info h3 {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
        }
        .stat-info p {
            color: #64748b;
            font-size: 13px;
        }

        /* Filters */
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .filters-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: end;
        }
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        .filter-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 13px;
            color: #475569;
        }
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #059669;
            color: white;
        }
        .btn-primary:hover {
            background: #047857;
        }
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        .btn-secondary:hover {
            background: #475569;
        }

        /* Card */
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h2 {
            font-size: 18px;
            color: #1e293b;
        }
        .card-body {
            padding: 0;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        tr:hover {
            background: #f8fafc;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        code {
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
            color: #475569;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        .empty-state i {
            font-size: 64px;
            opacity: 0.3;
            margin-bottom: 20px;
        }

        /* Alert */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        /* Date Navigation */
        .date-nav {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .date-nav a {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            border-radius: 8px;
            color: #475569;
            text-decoration: none;
            transition: all 0.2s;
        }
        .date-nav a:hover {
            background: #059669;
            color: white;
        }
        .date-nav .current-date {
            font-weight: 600;
            color: #1e293b;
            min-width: 140px;
            text-align: center;
        }
    </style>
</head>
<body>
    <?php include 'common/navigation.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fas fa-calendar-check"></i>
                Asistencias
            </h1>
            <?php if ($empresa_responsable): ?>
                <span class="empresa-badge">
                    <i class="fas fa-building"></i> <?= htmlspecialchars($empresa_responsable) ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if (!$empresa_responsable): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>No tienes una empresa asignada.</strong>
                    <p>Contacta al administrador para que te asigne una empresa.</p>
                </div>
            </div>
        <?php else: ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon green">
                        <i class="fas fa-list"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $stats['total'] ?></h3>
                        <p>Total registros</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange">
                        <i class="fas fa-door-open"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $stats['abiertas'] ?></h3>
                        <p>Jornadas abiertas</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $stats['cerradas'] ?></h3>
                        <p>Jornadas cerradas</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filters-form">
                    <div class="filter-group">
                        <label>Fecha</label>
                        <div class="date-nav">
                            <?php 
                            $prev_date = date('Y-m-d', strtotime($filtro_fecha . ' -1 day'));
                            $next_date = date('Y-m-d', strtotime($filtro_fecha . ' +1 day'));
                            ?>
                            <a href="?fecha=<?= $prev_date ?>&proyecto=<?= $filtro_proyecto ?>&busqueda=<?= urlencode($filtro_busqueda) ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <input type="date" name="fecha" value="<?= $filtro_fecha ?>" onchange="this.form.submit()">
                            <a href="?fecha=<?= $next_date ?>&proyecto=<?= $filtro_proyecto ?>&busqueda=<?= urlencode($filtro_busqueda) ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    <div class="filter-group">
                        <label>Proyecto</label>
                        <select name="proyecto" onchange="this.form.submit()">
                            <option value="0">Todos los proyectos</option>
                            <?php foreach ($proyectos_filtro as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $filtro_proyecto == $p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Buscar</label>
                        <input type="text" name="busqueda" placeholder="Nombre o NSS..." value="<?= htmlspecialchars($filtro_busqueda) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                    <a href="asistencias.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpiar
                    </a>
                </form>
            </div>

            <!-- Table -->
            <div class="card">
                <div class="card-header">
                    <h2>Registros de Asistencia - <?= date('d/m/Y', strtotime($filtro_fecha)) ?></h2>
                </div>
                <div class="card-body">
                    <?php if (empty($asistencias)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h3>Sin registros</h3>
                            <p>No hay asistencias registradas para esta fecha.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Empleado</th>
                                        <th>NSS</th>
                                        <th>Proyecto</th>
                                        <th>Entrada</th>
                                        <th>Salida</th>
                                        <th>Tiempo</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($asistencias as $a): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($a['empleado_nombre']) ?></strong></td>
                                        <td><code><?= htmlspecialchars($a['nss'] ?? '-') ?></code></td>
                                        <td>
                                            <span class="badge badge-info">
                                                <?= htmlspecialchars($a['proyecto_nombre']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($a['hora_entrada']): ?>
                                                <i class="fas fa-sign-in-alt" style="color: #059669;"></i>
                                                <?= date('H:i', strtotime($a['hora_entrada'])) ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($a['hora_salida']): ?>
                                                <i class="fas fa-sign-out-alt" style="color: #dc2626;"></i>
                                                <?= date('H:i', strtotime($a['hora_salida'])) ?>
                                            <?php else: ?>
                                                <span style="color: #64748b;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= calcularHorasTrabajadas($a['hora_entrada'], $a['hora_salida']) ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($a['hora_salida']): ?>
                                                <span class="badge badge-success">
                                                    <i class="fas fa-check"></i> Cerrada
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">
                                                    <i class="fas fa-clock"></i> Abierta
                                                </span>
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

        <?php endif; ?>
    </div>
</body>
</html>
