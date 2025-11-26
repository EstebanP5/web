<?php
session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'empresa') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';

$userId = (int)$_SESSION['user_id'];
$userName = trim($_SESSION['user_name'] ?? '');
$firstName = $userName ? explode(' ', $userName)[0] : 'Usuario';

// Obtener la empresa del usuario desde la tabla users o una tabla de configuración
$empresaUsuario = '';
$stEmpresa = $conn->prepare("SELECT empresa FROM users WHERE id = ?");
if ($stEmpresa) {
    $stEmpresa->bind_param('i', $userId);
    $stEmpresa->execute();
    $resultEmpresa = $stEmpresa->get_result();
    if ($row = $resultEmpresa->fetch_assoc()) {
        $empresaUsuario = $row['empresa'] ?? '';
    }
    $stEmpresa->close();
}

if (empty($empresaUsuario)) {
    // Intento alternativo: buscar en name si contiene el nombre de empresa
    $userName = $_SESSION['user_name'] ?? '';
    foreach (['CEDISA', 'Stone', 'Remedios'] as $emp) {
        if (stripos($userName, $emp) !== false) {
            $empresaUsuario = $emp;
            break;
        }
    }
}

// Estadísticas
$stats = [
    'empleados_activos' => 0,
    'empleados_bloqueados' => 0,
    'proyectos_asignados' => 0,
    'asistencias_hoy' => 0,
];

// Contar empleados activos de la empresa
if ($empresaUsuario) {
    $stEmpleados = $conn->prepare("SELECT 
        SUM(CASE WHEN activo = 1 AND bloqueado = 0 THEN 1 ELSE 0 END) AS activos,
        SUM(CASE WHEN bloqueado = 1 THEN 1 ELSE 0 END) AS bloqueados
        FROM empleados WHERE empresa = ?");
    if ($stEmpleados) {
        $stEmpleados->bind_param('s', $empresaUsuario);
        $stEmpleados->execute();
        $resultEmpleados = $stEmpleados->get_result();
        if ($row = $resultEmpleados->fetch_assoc()) {
            $stats['empleados_activos'] = (int)($row['activos'] ?? 0);
            $stats['empleados_bloqueados'] = (int)($row['bloqueados'] ?? 0);
        }
        $stEmpleados->close();
    }
    
    // Contar proyectos con empleados de esta empresa
    $stProyectos = $conn->prepare("SELECT COUNT(DISTINCT ep.proyecto_id) AS total 
        FROM empleado_proyecto ep 
        JOIN empleados e ON ep.empleado_id = e.id 
        WHERE e.empresa = ? AND ep.activo = 1 AND e.activo = 1");
    if ($stProyectos) {
        $stProyectos->bind_param('s', $empresaUsuario);
        $stProyectos->execute();
        $resultProyectos = $stProyectos->get_result();
        if ($row = $resultProyectos->fetch_assoc()) {
            $stats['proyectos_asignados'] = (int)($row['total'] ?? 0);
        }
        $stProyectos->close();
    }
    
    // Asistencias de hoy
    $stAsistencias = $conn->prepare("SELECT COUNT(*) AS total 
        FROM asistencia a 
        JOIN empleados e ON a.empleado_id = e.id 
        WHERE e.empresa = ? AND a.fecha = CURDATE()");
    if ($stAsistencias) {
        $stAsistencias->bind_param('s', $empresaUsuario);
        $stAsistencias->execute();
        $resultAsistencias = $stAsistencias->get_result();
        if ($row = $resultAsistencias->fetch_assoc()) {
            $stats['asistencias_hoy'] = (int)($row['total'] ?? 0);
        }
        $stAsistencias->close();
    }
}

// Obtener empleados de la empresa
$empleados = [];
if ($empresaUsuario) {
    $stList = $conn->prepare("SELECT e.*, u.email 
        FROM empleados e 
        LEFT JOIN users u ON e.id = u.id 
        WHERE e.empresa = ? 
        ORDER BY e.nombre ASC");
    if ($stList) {
        $stList->bind_param('s', $empresaUsuario);
        $stList->execute();
        $empleados = $stList->get_result()->fetch_all(MYSQLI_ASSOC);
        $stList->close();
    }
}

// Obtener proyectos donde participan empleados de la empresa
$proyectos = [];
if ($empresaUsuario) {
    $stProyList = $conn->prepare("SELECT DISTINCT g.id, g.nombre, g.empresa AS empresa_proyecto, g.localidad, g.fecha_inicio, g.fecha_fin,
        (SELECT COUNT(*) FROM empleado_proyecto ep2 JOIN empleados e2 ON ep2.empleado_id = e2.id WHERE ep2.proyecto_id = g.id AND e2.empresa = ? AND ep2.activo = 1) AS empleados_asignados
        FROM grupos g 
        JOIN empleado_proyecto ep ON g.id = ep.proyecto_id 
        JOIN empleados e ON ep.empleado_id = e.id 
        WHERE e.empresa = ? AND ep.activo = 1 AND g.activo = 1 
        ORDER BY g.nombre");
    if ($stProyList) {
        $stProyList->bind_param('ss', $empresaUsuario, $empresaUsuario);
        $stProyList->execute();
        $proyectos = $stProyList->get_result()->fetch_all(MYSQLI_ASSOC);
        $stProyList->close();
    }
}

// Obtener últimos lotes SUA de la empresa
$lotesRecientes = [];
if ($empresaUsuario) {
    $stLotes = $conn->prepare("SELECT DISTINCT sl.id, sl.fecha_proceso, sl.archivo, sl.total, sl.created_at
        FROM sua_lotes sl
        JOIN sua_empleados se ON sl.id = se.lote_id
        WHERE se.empresa = ?
        ORDER BY sl.created_at DESC
        LIMIT 5");
    if ($stLotes) {
        $stLotes->bind_param('s', $empresaUsuario);
        $stLotes->execute();
        $lotesRecientes = $stLotes->get_result()->fetch_all(MYSQLI_ASSOC);
        $stLotes->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Empresa - <?= htmlspecialchars($empresaUsuario); ?> - ErgoCuida</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .header {
            background: linear-gradient(135deg, #1a365d 0%, #2d4a7c 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        
        .header-title i {
            font-size: 28px;
            color: #fbbf24;
        }
        
        .header-title h1 {
            font-size: 24px;
            font-weight: 700;
        }
        
        .header-subtitle {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .header-empresa {
            background: rgba(251, 191, 36, 0.2);
            color: #fbbf24;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .header-user {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .user-info {
            text-align: right;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 16px;
        }
        
        .user-role {
            font-size: 13px;
            opacity: 0.9;
        }
        
        .logout-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.25);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }
        
        .notice-banner {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 1px solid #f59e0b;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            color: #92400e;
        }
        
        .notice-banner i {
            font-size: 20px;
            color: #f59e0b;
        }
        
        .notice-banner strong {
            font-weight: 600;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 16px;
        }
        
        .stat-icon.employees { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; }
        .stat-icon.blocked { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; }
        .stat-icon.projects { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: #fff; }
        .stat-icon.attendance { background: linear-gradient(135deg, #10b981, #059669); color: #fff; }
        
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #64748b;
        }
        
        .section {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        
        .section h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section h3 i {
            color: #1a365d;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
        }
        
        tr:hover {
            background: #f8fafc;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-activo {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-bloqueado {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-inactivo {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .project-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
        }
        
        .project-name {
            font-weight: 600;
            color: #1e293b;
            font-size: 16px;
            margin-bottom: 8px;
        }
        
        .project-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 13px;
            color: #64748b;
        }
        
        .project-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .project-meta i {
            color: #1a365d;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        .layout-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 24px;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .user-info {
                text-align: center;
            }
            
            .layout-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="header-title">
                <i class="fas fa-building"></i>
                <div>
                    <h1>Portal Empresa</h1>
                    <div class="header-subtitle">Vista de solo lectura</div>
                </div>
                <?php if ($empresaUsuario): ?>
                    <span class="header-empresa"><?= htmlspecialchars($empresaUsuario); ?></span>
                <?php endif; ?>
            </div>
            <div class="header-user">
                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($firstName); ?></div>
                    <div class="user-role">Representante de Empresa</div>
                </div>
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Cerrar Sesión
                </a>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="notice-banner">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Modo de solo lectura</strong> — Este portal permite visualizar información de empleados, proyectos y documentos SUA de tu empresa. No es posible realizar modificaciones desde aquí.
            </div>
        </div>

        <?php if (!$empresaUsuario): ?>
            <div class="section">
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h4>Sin empresa asignada</h4>
                    <p>Tu cuenta no tiene una empresa asignada. Contacta al administrador para configurar tu acceso.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon employees">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number"><?= $stats['empleados_activos']; ?></div>
                    <div class="stat-label">Empleados activos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blocked">
                        <i class="fas fa-user-lock"></i>
                    </div>
                    <div class="stat-number"><?= $stats['empleados_bloqueados']; ?></div>
                    <div class="stat-label">Empleados bloqueados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon projects">
                        <i class="fas fa-project-diagram"></i>
                    </div>
                    <div class="stat-number"><?= $stats['proyectos_asignados']; ?></div>
                    <div class="stat-label">Proyectos asignados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon attendance">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-number"><?= $stats['asistencias_hoy']; ?></div>
                    <div class="stat-label">Asistencias hoy</div>
                </div>
            </div>

            <div class="layout-grid">
                <div class="section">
                    <h3><i class="fas fa-users"></i> Empleados de <?= htmlspecialchars($empresaUsuario); ?></h3>
                    
                    <?php if (empty($empleados)): ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <p>No hay empleados registrados para esta empresa.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>NSS</th>
                                        <th>CURP</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($empleados as $emp): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($emp['nombre'] ?? ''); ?></strong>
                                                <?php if (!empty($emp['email'])): ?>
                                                    <br><small style="color: #64748b;"><?= htmlspecialchars($emp['email']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($emp['nss'] ?? '-'); ?></td>
                                            <td><?= htmlspecialchars($emp['curp'] ?? '-'); ?></td>
                                            <td>
                                                <?php if ((int)($emp['bloqueado'] ?? 0) === 1): ?>
                                                    <span class="status-badge status-bloqueado">Bloqueado</span>
                                                <?php elseif ((int)($emp['activo'] ?? 0) === 1): ?>
                                                    <span class="status-badge status-activo">Activo</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-inactivo">Inactivo</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="section">
                    <h3><i class="fas fa-project-diagram"></i> Proyectos con participación</h3>
                    
                    <?php if (empty($proyectos)): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>No hay proyectos con empleados de esta empresa asignados.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($proyectos as $proy): ?>
                            <div class="project-card">
                                <div class="project-name"><?= htmlspecialchars($proy['nombre'] ?? ''); ?></div>
                                <div class="project-meta">
                                    <?php if (!empty($proy['localidad'])): ?>
                                        <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($proy['localidad']); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-users"></i> <?= (int)($proy['empleados_asignados'] ?? 0); ?> empleados</span>
                                    <?php if (!empty($proy['fecha_inicio']) && !empty($proy['fecha_fin'])): ?>
                                        <span><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($proy['fecha_inicio'])); ?> - <?= date('d/m/Y', strtotime($proy['fecha_fin'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="section">
                <h3><i class="fas fa-file-pdf"></i> Últimos lotes SUA procesados</h3>
                
                <?php if (empty($lotesRecientes)): ?>
                    <div class="empty-state">
                        <i class="fas fa-file-alt"></i>
                        <p>No hay lotes SUA recientes para esta empresa.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha de proceso</th>
                                    <th>Archivo</th>
                                    <th>Total empleados</th>
                                    <th>Fecha de carga</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lotesRecientes as $lote): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($lote['fecha_proceso']))); ?></td>
                                        <td><?= htmlspecialchars($lote['archivo'] ?? '-'); ?></td>
                                        <td><?= (int)($lote['total'] ?? 0); ?></td>
                                        <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($lote['created_at']))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
