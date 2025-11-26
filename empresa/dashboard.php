<?php
session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar autenticación y rol de responsable_empresa
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'responsable_empresa') {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$userId = (int)$_SESSION['user_id'];
$userName = trim($_SESSION['user_name'] ?? '');
$firstName = $userName ? explode(' ', $userName)[0] : 'Usuario';

// Obtener la empresa asignada al usuario
$empresaUsuario = '';
$stmt = $conn->prepare("SELECT empresa FROM users WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $empresaUsuario = $row['empresa'] ?? '';
    }
    $stmt->close();
}

// Si no tiene empresa asignada, mostrar error
if (empty($empresaUsuario)) {
    die('No tienes una empresa asignada. Contacta al administrador.');
}

// Estadísticas de la empresa
$stats = [
    'empleados_total' => 0,
    'empleados_activos' => 0,
    'proyectos_total' => 0,
    'proyectos_activos' => 0,
    'suas_subidos' => 0,
];

// Contar empleados de la empresa
$stmt = $conn->prepare("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activos
    FROM empleados WHERE empresa = ?");
if ($stmt) {
    $stmt->bind_param('s', $empresaUsuario);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stats['empleados_total'] = (int)($row['total'] ?? 0);
        $stats['empleados_activos'] = (int)($row['activos'] ?? 0);
    }
    $stmt->close();
}

// Contar proyectos donde hay empleados de la empresa
$stmt = $conn->prepare("SELECT 
    COUNT(DISTINCT g.id) as total,
    COUNT(DISTINCT CASE WHEN g.activo = 1 THEN g.id END) as activos
    FROM grupos g
    INNER JOIN empleado_proyecto ep ON ep.proyecto_id = g.id AND ep.activo = 1
    INNER JOIN empleados e ON e.id = ep.empleado_id AND e.empresa = ?");
if ($stmt) {
    $stmt->bind_param('s', $empresaUsuario);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stats['proyectos_total'] = (int)($row['total'] ?? 0);
        $stats['proyectos_activos'] = (int)($row['activos'] ?? 0);
    }
    $stmt->close();
}

// Contar SUA subidos por esta empresa (por empresa en sua_empleados)
$stmt = $conn->prepare("SELECT COUNT(DISTINCT lote_id) as total FROM sua_empleados WHERE empresa = ?");
if ($stmt) {
    $stmt->bind_param('s', $empresaUsuario);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stats['suas_subidos'] = (int)($row['total'] ?? 0);
    }
    $stmt->close();
}

// Obtener empleados de la empresa
$empleados = [];
$stmt = $conn->prepare("SELECT e.*, ep.proyecto_id, g.nombre as proyecto_nombre
    FROM empleados e
    LEFT JOIN empleado_proyecto ep ON ep.empleado_id = e.id AND ep.activo = 1
    LEFT JOIN grupos g ON g.id = ep.proyecto_id
    WHERE e.empresa = ?
    ORDER BY e.nombre");
if ($stmt) {
    $stmt->bind_param('s', $empresaUsuario);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $empleados[] = $row;
    }
    $stmt->close();
}

// Obtener proyectos donde trabajan empleados de esta empresa
$proyectos = [];
$stmt = $conn->prepare("SELECT DISTINCT g.*, COUNT(DISTINCT e.id) as empleados_asignados
    FROM grupos g
    INNER JOIN empleado_proyecto ep ON ep.proyecto_id = g.id AND ep.activo = 1
    INNER JOIN empleados e ON e.id = ep.empleado_id AND e.empresa = ?
    WHERE g.activo = 1
    GROUP BY g.id
    ORDER BY g.nombre");
if ($stmt) {
    $stmt->bind_param('s', $empresaUsuario);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $proyectos[] = $row;
    }
    $stmt->close();
}

// Obtener SUA de la empresa (solo lectura)
$suas = [];
$stmt = $conn->prepare("SELECT DISTINCT sl.*, 
    (SELECT COUNT(*) FROM sua_empleados se WHERE se.lote_id = sl.id AND se.empresa = ?) as total_empresa
    FROM sua_lotes sl
    INNER JOIN sua_empleados se ON se.lote_id = sl.id AND se.empresa = ?
    ORDER BY sl.fecha_proceso DESC
    LIMIT 20");
if ($stmt) {
    $stmt->bind_param('ss', $empresaUsuario, $empresaUsuario);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $suas[] = $row;
    }
    $stmt->close();
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .header {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
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
            gap: 16px;
        }
        
        .header-icon {
            width: 56px;
            height: 56px;
            background: rgba(255,255,255,0.2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .header-title h1 {
            font-size: 24px;
            font-weight: 700;
        }
        
        .header-title p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .header-user {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .empresa-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 28px;
            box-shadow: 0 12px 28px rgba(16, 185, 129, 0.22);
        }
        
        .welcome-banner h2 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px;
        }
        
        .welcome-banner p {
            font-size: 16px;
            opacity: 0.9;
            margin: 0;
        }
        
        .readonly-notice {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #92400e;
            font-weight: 500;
        }
        
        .readonly-notice i {
            font-size: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        
        .stat-card .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 14px;
        }
        
        .stat-icon.employees { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #2563eb; }
        .stat-icon.projects { background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #16a34a; }
        .stat-icon.sua { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #d97706; }
        
        .stat-card .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .stat-card .stat-label {
            font-size: 14px;
            color: #64748b;
        }
        
        .section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        
        .section h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section h3 i {
            color: #059669;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .table th, .table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        
        .table tr:hover {
            background: #f8fafc;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-activo { background: #dcfce7; color: #166534; }
        .status-inactivo { background: #fee2e2; color: #991b1b; }
        .status-bloqueado { background: #fef3c7; color: #92400e; }
        
        .project-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 14px;
        }
        
        .project-card h4 {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 8px;
        }
        
        .project-card .meta {
            font-size: 13px;
            color: #64748b;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .project-card .meta span {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.4;
        }
        
        .sua-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .sua-item:last-child {
            border-bottom: none;
        }
        
        .sua-info {
            flex: 1;
        }
        
        .sua-info strong {
            font-size: 15px;
            color: #1e293b;
        }
        
        .sua-info p {
            font-size: 13px;
            color: #64748b;
            margin: 4px 0 0;
        }
        
        .sua-count {
            background: #dcfce7;
            color: #166534;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .nav-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        
        .nav-tab {
            padding: 12px 20px;
            border-radius: 10px;
            background: #f1f5f9;
            color: #475569;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .nav-tab:hover {
            background: #e2e8f0;
        }
        
        .nav-tab.active {
            background: #059669;
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-tabs {
                flex-direction: column;
            }
            
            .nav-tab {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="header-title">
                <div class="header-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div>
                    <h1>Portal Empresa</h1>
                    <p>Vista de Servicios Especializados</p>
                </div>
            </div>
            <div class="header-user">
                <span class="empresa-badge">
                    <i class="fas fa-building"></i>
                    <?= htmlspecialchars($empresaUsuario); ?>
                </span>
                <span style="font-weight:500;"><?= htmlspecialchars($firstName); ?></span>
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Salir
                </a>
            </div>
        </div>
    </header>
    
    <div class="container">
        <div class="welcome-banner">
            <h2>¡Hola, <?= htmlspecialchars($firstName); ?>!</h2>
            <p>Bienvenido al portal de <?= htmlspecialchars($empresaUsuario); ?>. Aquí puedes consultar la información de tus servicios especializados.</p>
        </div>
        
        <div class="readonly-notice">
            <i class="fas fa-eye"></i>
            <span>Este portal es de <strong>solo lectura</strong>. Puedes consultar información pero no realizar modificaciones (excepto subir documentos SUA).</span>
        </div>
        
        <div style="margin-bottom: 24px;">
            <a href="subir_sua.php" class="btn-upload-sua" style="display: inline-flex; align-items: center; gap: 10px; background: linear-gradient(135deg, #059669, #047857); color: white; padding: 14px 28px; border-radius: 12px; text-decoration: none; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);">
                <i class="fas fa-file-upload"></i>
                Subir Documento SUA
            </a>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon employees">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number"><?= $stats['empleados_activos']; ?></div>
                <div class="stat-label">Servicios Especializados activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon employees">
                    <i class="fas fa-user-friends"></i>
                </div>
                <div class="stat-number"><?= $stats['empleados_total']; ?></div>
                <div class="stat-label">Total registrados</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon projects">
                    <i class="fas fa-diagram-project"></i>
                </div>
                <div class="stat-number"><?= $stats['proyectos_activos']; ?></div>
                <div class="stat-label">Proyectos con personal</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon sua">
                    <i class="fas fa-file-pdf"></i>
                </div>
                <div class="stat-number"><?= $stats['suas_subidos']; ?></div>
                <div class="stat-label">Lotes SUA registrados</div>
            </div>
        </div>
        
        <div class="nav-tabs">
            <button class="nav-tab active" onclick="showTab('empleados')">
                <i class="fas fa-users"></i>
                Servicios Especializados
            </button>
            <button class="nav-tab" onclick="showTab('proyectos')">
                <i class="fas fa-diagram-project"></i>
                Proyectos
            </button>
            <button class="nav-tab" onclick="showTab('sua')">
                <i class="fas fa-file-pdf"></i>
                Registros SUA
            </button>
        </div>
        
        <div id="tab-empleados" class="tab-content active">
            <div class="section">
                <h3><i class="fas fa-users"></i> Servicios Especializados de <?= htmlspecialchars($empresaUsuario); ?></h3>
                <?php if (empty($empleados)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-slash"></i>
                        <h4>Sin registros</h4>
                        <p>No hay servicios especializados registrados para <?= htmlspecialchars($empresaUsuario); ?>.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>NSS</th>
                                    <th>CURP</th>
                                    <th>Proyecto Actual</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($empleados as $emp): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($emp['nombre'] ?? ''); ?></strong></td>
                                        <td><?= htmlspecialchars($emp['nss'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($emp['curp'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($emp['proyecto_nombre'] ?? 'Sin asignar'); ?></td>
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
        </div>
        
        <div id="tab-proyectos" class="tab-content">
            <div class="section">
                <h3><i class="fas fa-diagram-project"></i> Proyectos donde participa <?= htmlspecialchars($empresaUsuario); ?></h3>
                <?php if (empty($proyectos)): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <h4>Sin proyectos</h4>
                        <p>No hay proyectos con personal de <?= htmlspecialchars($empresaUsuario); ?> asignado.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($proyectos as $proyecto): ?>
                        <div class="project-card">
                            <h4><?= htmlspecialchars($proyecto['nombre']); ?></h4>
                            <div class="meta">
                                <span><i class="fas fa-building"></i> <?= htmlspecialchars($proyecto['empresa'] ?? 'Cliente'); ?></span>
                                <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($proyecto['localidad'] ?? 'Sin ubicación'); ?></span>
                                <span><i class="fas fa-users"></i> <?= (int)$proyecto['empleados_asignados']; ?> de <?= htmlspecialchars($empresaUsuario); ?></span>
                                <?php if (!empty($proyecto['fecha_inicio']) && !empty($proyecto['fecha_fin'])): ?>
                                    <span><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($proyecto['fecha_inicio'])); ?> - <?= date('d/m/Y', strtotime($proyecto['fecha_fin'])); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div id="tab-sua" class="tab-content">
            <div class="section">
                <h3><i class="fas fa-file-pdf"></i> Registros SUA de <?= htmlspecialchars($empresaUsuario); ?></h3>
                <?php if (empty($suas)): ?>
                    <div class="empty-state">
                        <i class="fas fa-file-alt"></i>
                        <h4>Sin registros SUA</h4>
                        <p>No hay lotes SUA registrados para <?= htmlspecialchars($empresaUsuario); ?>.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($suas as $sua): ?>
                        <div class="sua-item">
                            <div class="sua-info">
                                <strong>Fecha de proceso: <?= date('d/m/Y', strtotime($sua['fecha_proceso'])); ?></strong>
                                <p>Archivo: <?= htmlspecialchars($sua['archivo']); ?> | Subido: <?= date('d/m/Y H:i', strtotime($sua['created_at'])); ?></p>
                            </div>
                            <span class="sua-count"><?= (int)$sua['total_empresa']; ?> empleados</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function showTab(tabId) {
            // Ocultar todos los contenidos
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remover active de todos los tabs
            document.querySelectorAll('.nav-tab').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Mostrar el contenido seleccionado
            document.getElementById('tab-' + tabId).classList.add('active');
            
            // Marcar el botón como activo
            event.currentTarget.classList.add('active');
        }
    </script>
</body>
</html>
