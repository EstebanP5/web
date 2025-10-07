<?php
require_once '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Acciones: asignar proyecto, editar datos, baja/alta
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Asignar proyecto único activo
    if (isset($_POST['accion']) && $_POST['accion'] === 'asignar') {
        $empleadoId = (int)($_POST['empleado_id'] ?? 0);
        $proyectoId = (int)($_POST['proyecto_id'] ?? 0);
        if ($empleadoId > 0) {
            // Desactivar asignaciones activas
            $stmt = $conn->prepare("UPDATE empleado_proyecto SET activo = 0 WHERE empleado_id = ? AND activo = 1");
            $stmt->bind_param('i', $empleadoId);
            $stmt->execute();
            if ($proyectoId > 0) {
                // Intentar activar existente
                $stmt2 = $conn->prepare("UPDATE empleado_proyecto SET activo=1, fecha_asignacion=NOW() WHERE empleado_id=? AND proyecto_id=?");
                $stmt2->bind_param('ii', $empleadoId, $proyectoId);
                $stmt2->execute();
                if ($stmt2->affected_rows === 0) {
                    $stmt3 = $conn->prepare("INSERT INTO empleado_proyecto (empleado_id, proyecto_id, activo, fecha_asignacion) VALUES (?,?,1,NOW())");
                    $stmt3->bind_param('ii', $empleadoId, $proyectoId);
                    $stmt3->execute();
                }
                $msg = 'Empleado asignado al proyecto.';
            } else {
                $msg = 'Asignaciones activas removidas.';
            }
        }
    }
    // Editar datos rápidos
    if (isset($_POST['accion']) && $_POST['accion'] === 'editar') {
        $empleadoId = (int)($_POST['empleado_id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $puesto = trim($_POST['puesto'] ?? 'Trabajador');
        $nss = trim($_POST['nss'] ?? '');
        $curp = trim($_POST['curp'] ?? '');
        if ($empleadoId > 0 && $nombre !== '') {
            $stmt = $conn->prepare("UPDATE empleados SET nombre=?, telefono=?, puesto=?, nss=?, curp=? WHERE id=?");
            $stmt->bind_param('sssssi', $nombre, $telefono, $puesto, $nss, $curp, $empleadoId);
            $stmt->execute();
            // Mantener sincronizado el nombre en users si existe
            $su = $conn->prepare("UPDATE users SET name=? WHERE id=? AND rol='empleado'");
            $su->bind_param('si', $nombre, $empleadoId);
            $su->execute();
            $msg = 'Empleado actualizado.';
        }
    }
    // Baja / Alta
    if (isset($_POST['accion']) && ($_POST['accion'] === 'baja' || $_POST['accion'] === 'alta')) {
        $empleadoId = (int)($_POST['empleado_id'] ?? 0);
        if ($empleadoId > 0) {
            $activo = $_POST['accion'] === 'alta' ? 1 : 0;
            $stmt = $conn->prepare("UPDATE empleados SET activo=? WHERE id=?");
            $stmt->bind_param('ii', $activo, $empleadoId);
            $stmt->execute();
            if ($activo === 0) {
                // Al dar de baja, desactivar su asignación activa
                $stmt2 = $conn->prepare("UPDATE empleado_proyecto SET activo=0 WHERE empleado_id=? AND activo=1");
                $stmt2->bind_param('i', $empleadoId);
                $stmt2->execute();
            }
            // Reflejar en users por id (FK alineado: empleados.id = users.id)
            $su = $conn->prepare("UPDATE users SET activo=? WHERE id=? AND rol='empleado'");
            $su->bind_param('ii', $activo, $empleadoId);
            $su->execute();
            $msg = $activo ? 'Empleado reactivado.' : 'Empleado dado de baja temporalmente.';
        }
    }
}

// Catálogo de proyectos
$proyectos = $conn->query("SELECT id, nombre FROM grupos ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

// Listar empleados con proyecto actual
$sql = "SELECT e.*, g.nombre AS proyecto_nombre, ep.proyecto_id
        FROM empleados e
        LEFT JOIN empleado_proyecto ep ON ep.empleado_id = e.id AND ep.activo = 1
        LEFT JOIN grupos g ON g.id = ep.proyecto_id
        ORDER BY e.nombre";
$res = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Empleados - Sistema de Gestión</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #fafafa;
            min-height: 100vh;
            color: #1a365d;
            line-height: 1.6;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: white;
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 4px 20px rgba(26, 54, 93, 0.08);
            border: 1px solid #f1f5f9;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .header-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, #ff7a00 0%, #1a365d 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            box-shadow: 0 8px 32px rgba(255, 122, 0, 0.25);
        }

        .header-info h1 {
            font-size: 32px;
            font-weight: 700;
            color: #1a365d;
            margin-bottom: 6px;
            letter-spacing: -0.02em;
        }

        .header-info p {
            font-size: 16px;
            color: #718096;
            font-weight: 400;
        }

        .header-actions {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 14px 24px;
            border: none;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ff7a00 0%, #ff9500 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(255, 122, 0, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(255, 122, 0, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #1a365d;
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }

        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 16px rgba(26, 54, 93, 0.06);
            border: 1px solid #f1f5f9;
            text-align: center;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(26, 54, 93, 0.12);
        }

        .stat-number {
            font-size: 36px;
            font-weight: 700;
            color: #1a365d;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 14px;
            color: #718096;
            font-weight: 500;
        }

        .stat-card.orange .stat-number {
            color: #ff7a00;
        }

        .toolbar {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: 0 2px 16px rgba(26, 54, 93, 0.06);
            border: 1px solid #f1f5f9;
        }

        .search-filter {
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 10px 16px 10px 44px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.9);
            font-size: 14px;
            min-width: 280px;
            transition: all 0.2s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
        }

        .filter-select {
            padding: 10px 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.9);
            font-size: 14px;
            min-width: 160px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            padding: 20px;
            color: white;
            text-align: center;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.8;
        }

        .message {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-left: 4px solid #10b981;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            backdrop-filter: blur(20px);
            color: #065f46;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 24px;
        }

        .employee-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 28px;
            box-shadow: 
                0 20px 25px -5px rgba(0, 0, 0, 0.1),
                0 10px 10px -5px rgba(0, 0, 0, 0.04);
            transition: all 0.3s ease;
            position: relative;
        }

        .employee-card:hover {
            transform: translateY(-4px);
            box-shadow: 
                0 25px 35px -5px rgba(0, 0, 0, 0.15),
                0 15px 15px -5px rgba(0, 0, 0, 0.08);
        }

        .employee-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
        }

        .employee-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .employee-avatar {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
            font-weight: 700;
        }

        .employee-details h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
        }

        .employee-position {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .employee-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            color: #6b7280;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .meta-item i {
            width: 16px;
            text-align: center;
            color: #9ca3af;
        }

        .current-project {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 20px;
        }

        .project-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .project-name {
            font-size: 14px;
            font-weight: 600;
            color: #10b981;
        }

        .employee-actions {
            margin-top: 24px;
        }

        .action-group {
            margin-bottom: 16px;
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .action-group:last-child {
            margin-bottom: 0;
        }

        .action-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .action-form {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .form-input {
            flex: 1;
            min-width: 120px;
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.2s ease;
            outline: none;
        }

        .form-input:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }

        .form-select {
            flex: 1;
            min-width: 180px;
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            background: white;
        }

        .btn-small {
            padding: 10px 16px;
            font-size: 13px;
            min-width: 100px;
        }

        .btn-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .btn-danger:hover {
            background: #fecaca;
            border-color: #f87171;
        }

        .btn-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .btn-success:hover {
            background: #a7f3d0;
            border-color: #6ee7b7;
        }

        .btn-edit {
            background: #ddd6fe;
            color: #5b21b6;
            border: 1px solid #c4b5fd;
        }

        .btn-edit:hover {
            background: #c4b5fd;
            border-color: #a78bfa;
        }

        .btn-assign {
            background: #e0e7ff;
            color: #3730a3;
            border: 1px solid #c7d2fe;
        }

        .btn-assign:hover {
            background: #c7d2fe;
            border-color: #a5b4fc;
        }

        .danger-zone {
            border: 1px solid #fecaca;
            background: #fef2f2;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: white;
        }

        .empty-state i {
            font-size: 64px;
            opacity: 0.3;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 12px;
        }

        .empty-state p {
            font-size: 16px;
            opacity: 0.8;
            margin-bottom: 24px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
                padding: 20px;
            }

            .header-content {
                width: 100%;
            }

            .header-actions {
                width: 100%;
                justify-content: stretch;
            }

            .header-actions .btn {
                flex: 1;
                justify-content: center;
            }

            .toolbar {
                flex-direction: column;
                gap: 16px;
                padding: 16px;
            }

            .search-filter {
                width: 100%;
                flex-direction: column;
            }

            .search-box input {
                min-width: unset;
                width: 100%;
            }

            .filter-select {
                min-width: unset;
                width: 100%;
            }

            .grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .employee-card {
                padding: 20px;
            }

            .employee-meta {
                grid-template-columns: 1fr;
            }

            .action-form {
                flex-direction: column;
            }

            .form-input,
            .form-select {
                min-width: unset;
                width: 100%;
            }

            .btn-small {
                width: 100%;
                min-width: unset;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .header-title h1 {
                font-size: 24px;
            }
        }

        /* Loading states */
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn.loading {
            position: relative;
            color: transparent;
        }

        .btn.loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }

        /* Filtros y búsqueda */
        .employee-card.hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header Section -->
        <div class="header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="header-info">
                        <h1>Gestión de Servicios Especializados</h1>
                        <p>Administra el personal y sus asignaciones de proyecto</p>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="admin.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Volver al Panel
                    </a>
                    <a href="crear_empleado.php" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Nuevo Servicio Especializado
                    </a>
                </div>
            </div>
        </div>

        <?php if($msg): ?>
            <div class="message">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <?php 
        // Obtener estadísticas
        $total_empleados = $conn->query("SELECT COUNT(*) as count FROM empleados")->fetch_assoc()['count'];
        $empleados_activos = $conn->query("SELECT COUNT(*) as count FROM empleados WHERE activo = 1")->fetch_assoc()['count'];
        $con_proyecto = $conn->query("SELECT COUNT(DISTINCT e.id) as count FROM empleados e JOIN empleado_proyecto ep ON e.id = ep.empleado_id WHERE ep.activo = 1 AND e.activo = 1")->fetch_assoc()['count'];
        ?>

        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?= $total_empleados ?></div>
                <div class="stat-label">Total de Servicios Especializados</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $empleados_activos ?></div>
                <div class="stat-label">Activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $con_proyecto ?></div>
                <div class="stat-label">Con Proyecto</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= count($proyectos) ?></div>
                <div class="stat-label">Proyectos</div>
            </div>
        </div>

        <div class="toolbar">
            <div class="search-filter">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Buscar por nombre, NSS, CURP, teléfono...">
                </div>
                <select class="filter-select" id="statusFilter">
                    <option value="">Todos los estados</option>
                    <option value="activo">Solo activos</option>
                    <option value="inactivo">Solo inactivos</option>
                </select>
                <select class="filter-select" id="projectFilter">
                    <option value="">Todos los proyectos</option>
                    <option value="sin-proyecto">Sin proyecto</option>
                    <?php foreach($proyectos as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="color: white; font-weight: 500;">
                <span id="resultCount"><?= $total_empleados ?></span> servicios especializados encontrados
            </div>
        </div>

        <?php if($total_empleados == 0): ?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <h3>No hay servicios especializados registrados</h3>
                <p>Comienza agregando el primer Servicio Especializado al sistema</p>
                <a class="btn btn-primary" href="crear_empleado.php">
                    <i class="fas fa-plus"></i>
                    Crear primer Servicio Especializado
                </a>
            </div>
        <?php else: ?>
            <div class="grid" id="employeeGrid">
                <?php while($e = $res->fetch_assoc()): ?>
                <div class="employee-card" 
                     data-name="<?= strtolower(htmlspecialchars($e['nombre'])) ?>"
                     data-nss="<?= strtolower(htmlspecialchars($e['nss'])) ?>"
                     data-curp="<?= strtolower(htmlspecialchars($e['curp'])) ?>"
                     data-telefono="<?= htmlspecialchars($e['telefono']) ?>"
                     data-status="<?= (int)$e['activo'] === 1 ? 'activo' : 'inactivo' ?>"
                     data-project="<?= (int)$e['proyecto_id'] ?: 'sin-proyecto' ?>">
                    
                    <div class="employee-header">
                        <div class="employee-info">
                            <div class="employee-avatar">
                                <?= strtoupper(substr($e['nombre'], 0, 2)) ?>
                            </div>
                            <div class="employee-details">
                                <h3><?= htmlspecialchars($e['nombre']) ?></h3>
                                <div class="employee-position"><?= htmlspecialchars($e['puesto'] ?: 'Trabajador') ?></div>
                            </div>
                        </div>
                        <div class="status-badge <?= (int)$e['activo'] === 1 ? 'status-active' : 'status-inactive' ?>">
                            <?= (int)$e['activo'] === 1 ? 'Activo' : 'Inactivo' ?>
                        </div>
                    </div>

                    <div class="employee-meta">
                        <div class="meta-item">
                            <i class="fas fa-phone"></i>
                            <span><?= htmlspecialchars($e['telefono'] ?: 'Sin teléfono') ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-id-card"></i>
                            <span>NSS: <?= htmlspecialchars($e['nss'] ?: 'Sin NSS') ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-file-alt"></i>
                            <span>CURP: <?= htmlspecialchars($e['curp'] ?: 'Sin CURP') ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-briefcase"></i>
                            <span><?= htmlspecialchars($e['puesto'] ?: 'Trabajador') ?></span>
                        </div>
                    </div>

                    <?php if($e['proyecto_nombre']): ?>
                        <div class="current-project">
                            <div class="project-label">Proyecto Actual</div>
                            <div class="project-name">
                                <i class="fas fa-project-diagram"></i>
                                <?= htmlspecialchars($e['proyecto_nombre']) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="employee-actions">
                        <div class="action-group">
                            <div class="action-label">Asignar Proyecto</div>
                            <form method="post" class="action-form">
                                <input type="hidden" name="empleado_id" value="<?= (int)$e['id'] ?>">
                                <input type="hidden" name="accion" value="asignar">
                                <select name="proyecto_id" class="form-select">
                                    <option value="0">Sin proyecto</option>
                                    <?php foreach($proyectos as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>" <?= ((int)$e['proyecto_id'] === (int)$p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-assign btn-small" type="submit">
                                    <i class="fas fa-link"></i> Asignar
                                </button>
                            </form>
                        </div>

                        <div class="action-group">
                            <div class="action-label">Datos Personales</div>
                            <form method="post" class="action-form" style="margin-bottom: 12px;">
                                <input type="hidden" name="accion" value="editar">
                                <input type="hidden" name="empleado_id" value="<?= (int)$e['id'] ?>">
                                <input type="text" name="nombre" value="<?= htmlspecialchars($e['nombre']) ?>" 
                                       placeholder="Nombre completo" class="form-input" required>
                                <input type="tel" name="telefono" value="<?= htmlspecialchars($e['telefono']) ?>" 
                                       placeholder="Teléfono" class="form-input">
                                <button class="btn btn-edit btn-small" type="submit">
                                    <i class="fas fa-save"></i> Guardar
                                </button>
                            </form>
                            <form method="post" class="action-form">
                                <input type="hidden" name="accion" value="editar">
                                <input type="hidden" name="empleado_id" value="<?= (int)$e['id'] ?>">
                                <input type="text" name="nombre" value="<?= htmlspecialchars($e['nombre']) ?>" style="display: none;">
                                <input type="text" name="telefono" value="<?= htmlspecialchars($e['telefono']) ?>" style="display: none;">
                                <input type="text" name="puesto" value="<?= htmlspecialchars($e['puesto']) ?>" 
                                       placeholder="Puesto de trabajo" class="form-input">
                                <input type="text" name="nss" value="<?= htmlspecialchars($e['nss']) ?>" 
                                       placeholder="NSS" class="form-input">
                                <input type="text" name="curp" value="<?= htmlspecialchars($e['curp']) ?>" 
                                       placeholder="CURP" class="form-input">
                                <button class="btn btn-edit btn-small" type="submit">
                                    <i class="fas fa-id-card"></i> Actualizar Documentos
                                </button>
                            </form>
                        </div>

                        <div class="action-group danger-zone">
                            <div class="action-label">Estado del Empleado</div>
                            <div class="action-form">
                                <form method="post" style="display: inline-block;">
                                    <input type="hidden" name="empleado_id" value="<?= (int)$e['id'] ?>">
                                    <input type="hidden" name="accion" value="<?= (int)$e['activo'] === 1 ? 'baja' : 'alta' ?>">
                                    <?php if((int)$e['activo'] === 1): ?>
                                        <button class="btn btn-danger btn-small" type="submit"
                                                onclick="return confirm('¿Dar de baja a <?= htmlspecialchars(addslashes($e['nombre'])) ?>?')">
                                            <i class="fas fa-user-slash"></i> Dar de Baja
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-success btn-small" type="submit">
                                            <i class="fas fa-user-check"></i> Reactivar
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Loading states for forms
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.classList.contains('loading')) {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('loading');
                    
                    setTimeout(() => {
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('loading');
                    }, 3000);
                }
            });
        });

        // Phone number formatting
        document.querySelectorAll('input[name="telefono"]').forEach(input => {
            input.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length > 0) {
                    if (value.length <= 3) {
                        value = value;
                    } else if (value.length <= 6) {
                        value = value.slice(0, 3) + ' ' + value.slice(3);
                    } else {
                        value = value.slice(0, 3) + ' ' + value.slice(3, 6) + ' ' + value.slice(6, 10);
                    }
                }
                e.target.value = value;
            });
        });

        // Search and filter functionality
        const searchInput = document.getElementById('searchInput');
        const statusFilter = document.getElementById('statusFilter');
        const projectFilter = document.getElementById('projectFilter');
        const employeeCards = document.querySelectorAll('.employee-card');
        const resultCount = document.getElementById('resultCount');

        function filterEmployees() {
            const searchTerm = searchInput.value.toLowerCase();
            const statusValue = statusFilter.value;
            const projectValue = projectFilter.value;
            let visibleCount = 0;

            employeeCards.forEach(card => {
                const name = card.dataset.name;
                const nss = card.dataset.nss;
                const curp = card.dataset.curp;
                const telefono = card.dataset.telefono;
                const status = card.dataset.status;
                const project = card.dataset.project;

                const matchesSearch = searchTerm === '' || 
                    name.includes(searchTerm) || 
                    nss.includes(searchTerm) || 
                    curp.includes(searchTerm) || 
                    telefono.includes(searchTerm);

                const matchesStatus = statusValue === '' || status === statusValue;
                
                const matchesProject = projectValue === '' || 
                    project === projectValue ||
                    (projectValue === 'sin-proyecto' && project === 'sin-proyecto');

                if (matchesSearch && matchesStatus && matchesProject) {
                    card.classList.remove('hidden');
                    visibleCount++;
                } else {
                    card.classList.add('hidden');
                }
            });

            resultCount.textContent = visibleCount;
        }

        searchInput.addEventListener('input', filterEmployees);
        statusFilter.addEventListener('change', filterEmployees);
        projectFilter.addEventListener('change', filterEmployees);

        // Auto-hide success message
        const message = document.querySelector('.message');
        if (message) {
            setTimeout(() => {
                message.style.opacity = '0';
                message.style.transform = 'translateY(-10px)';
                setTimeout(() => message.remove(), 300);
            }, 4000);
        }

        // NSS and CURP formatting
        document.querySelectorAll('input[name="nss"]').forEach(input => {
            input.addEventListener('input', function(e) {
                let value = e.target.value.replace(/[^\d]/g, '');
                if (value.length > 0) {
                    // Format: XX-XX-XX-XXXX-X
                    if (value.length <= 2) {
                        value = value;
                    } else if (value.length <= 4) {
                        value = value.slice(0, 2) + '-' + value.slice(2);
                    } else if (value.length <= 6) {
                        value = value.slice(0, 2) + '-' + value.slice(2, 4) + '-' + value.slice(4);
                    } else if (value.length <= 10) {
                        value = value.slice(0, 2) + '-' + value.slice(2, 4) + '-' + value.slice(4, 6) + '-' + value.slice(6);
                    } else {
                        value = value.slice(0, 2) + '-' + value.slice(2, 4) + '-' + value.slice(4, 6) + '-' + value.slice(6, 10) + '-' + value.slice(10, 11);
                    }
                }
                e.target.value = value;
            });
        });

        document.querySelectorAll('input[name="curp"]').forEach(input => {
            input.addEventListener('input', function(e) {
                e.target.value = e.target.value.toUpperCase();
            });
        });
    </script>
</body>
</html>
