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
$mensaje_exito = '';
$mensaje_error = '';

// Obtener empresa del responsable
$stmt = $conn->prepare('SELECT empresa FROM empresas_responsables WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$responsableData = $stmt->get_result()->fetch_assoc();
$stmt->close();

$empresa_responsable = $responsableData['empresa'] ?? '';

$trabajadores = [];
$proyectos_filtro = [];

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

    // Filtros
    $filtro_proyecto = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
    $filtro_busqueda = trim($_GET['busqueda'] ?? '');
    $empresa_lower = strtolower($empresa_responsable);

    // Construir query - busca por campo empresa directo O por relación con proyectos (case-insensitive)
    $sql = 'SELECT DISTINCT e.id, e.nombre, e.nss, e.curp, e.puesto, e.telefono, e.activo, e.fecha_registro, e.empresa,
            (SELECT GROUP_CONCAT(DISTINCT g2.nombre SEPARATOR ", ") 
             FROM empleado_proyecto ep2 
             JOIN grupos g2 ON ep2.proyecto_id = g2.id 
             WHERE ep2.empleado_id = e.id AND ep2.activo = 1 AND g2.activo = 1) as proyectos
            FROM empleados e 
            LEFT JOIN empleado_proyecto ep ON e.id = ep.empleado_id AND ep.activo = 1
            LEFT JOIN grupos g ON ep.proyecto_id = g.id AND g.activo = 1
            WHERE e.activo = 1 AND (LOWER(e.empresa) = ? OR LOWER(g.empresa) = ?)';
    
    $params = [$empresa_lower, $empresa_lower];
    $types = 'ss';

    if ($filtro_proyecto > 0) {
        $sql .= ' AND g.id = ?';
        $params[] = $filtro_proyecto;
        $types .= 'i';
    }

    if ($filtro_busqueda) {
        $sql .= ' AND (e.nombre LIKE ? OR e.nss LIKE ? OR e.curp LIKE ?)';
        $busqueda = '%' . $filtro_busqueda . '%';
        $params[] = $busqueda;
        $params[] = $busqueda;
        $params[] = $busqueda;
        $types .= 'sss';
    }

    $sql .= ' GROUP BY e.id ORDER BY e.nombre';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $trabajadores[] = $row;
    }
    $stmt->close();
}

$pageTitle = 'Trabajadores - Responsable';
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
            min-width: 200px;
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
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
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

        /* Stats */
        .stats-bar {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        .stat-item {
            background: white;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .stat-item i {
            font-size: 24px;
            color: #059669;
        }
        .stat-item .value {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
        }
        .stat-item .label {
            font-size: 13px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <?php include 'common/navigation.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fas fa-users"></i>
                Trabajadores
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

            <!-- Stats Bar -->
            <div class="stats-bar">
                <div class="stat-item">
                    <i class="fas fa-users"></i>
                    <div>
                        <div class="value"><?= count($trabajadores) ?></div>
                        <div class="label">Trabajadores encontrados</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filters-form">
                    <div class="filter-group">
                        <label>Buscar</label>
                        <input type="text" name="busqueda" placeholder="Nombre, NSS o CURP..." value="<?= htmlspecialchars($filtro_busqueda ?? '') ?>">
                    </div>
                    <div class="filter-group">
                        <label>Proyecto</label>
                        <select name="proyecto">
                            <option value="0">Todos los proyectos</option>
                            <?php foreach ($proyectos_filtro as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= ($filtro_proyecto ?? 0) == $p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                    <a href="trabajadores.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpiar
                    </a>
                </form>
            </div>

            <!-- Table -->
            <div class="card">
                <div class="card-header">
                    <h2>Lista de Trabajadores</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($trabajadores)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h3>No hay trabajadores</h3>
                            <p>No se encontraron trabajadores con los filtros aplicados.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>NSS</th>
                                        <th>CURP</th>
                                        <th>Puesto</th>
                                        <th>Teléfono</th>
                                        <th>Proyectos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trabajadores as $t): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($t['nombre']) ?></strong></td>
                                        <td><code><?= htmlspecialchars($t['nss'] ?? '-') ?></code></td>
                                        <td><code style="font-size: 11px;"><?= htmlspecialchars($t['curp'] ?? '-') ?></code></td>
                                        <td><?= htmlspecialchars($t['puesto'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($t['telefono'] ?? '-') ?></td>
                                        <td>
                                            <?php 
                                            $proyectos_arr = explode(', ', $t['proyectos'] ?? '');
                                            foreach (array_slice($proyectos_arr, 0, 2) as $proy): ?>
                                                <span class="badge badge-info"><?= htmlspecialchars($proy) ?></span>
                                            <?php endforeach; 
                                            if (count($proyectos_arr) > 2): ?>
                                                <span class="badge badge-info">+<?= count($proyectos_arr) - 2 ?></span>
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
