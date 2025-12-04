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

$proyectos = [];

if ($empresa_responsable) {
    // Obtener proyectos con estadísticas
    $sql = "SELECT g.*, 
            (SELECT COUNT(DISTINCT ep.empleado_id) 
             FROM empleado_proyecto ep 
             JOIN empleados e ON ep.empleado_id = e.id AND e.activo = 1 
             WHERE ep.proyecto_id = g.id AND ep.activo = 1) as num_trabajadores,
            (SELECT COUNT(*) 
             FROM asistencia a 
             WHERE a.proyecto_id = g.id AND a.fecha = CURDATE()) as asistencias_hoy,
            (SELECT COUNT(*) 
             FROM asistencia a 
             WHERE a.proyecto_id = g.id AND a.fecha = CURDATE() AND a.hora_salida IS NULL) as jornadas_abiertas
            FROM grupos g 
            WHERE g.empresa = ? AND g.activo = 1 
            ORDER BY g.nombre";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $empresa_responsable);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $proyectos[] = $row;
    }
    $stmt->close();
}

$pageTitle = 'Proyectos - Responsable';
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
        
        /* Projects Grid */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }
        .project-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: all 0.2s;
        }
        .project-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        .project-header {
            padding: 20px;
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white;
        }
        .project-header h3 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        .project-header .localidad {
            font-size: 13px;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .project-body {
            padding: 20px;
        }
        .project-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .project-stat {
            text-align: center;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
        }
        .project-stat .value {
            font-size: 24px;
            font-weight: 700;
            color: #059669;
        }
        .project-stat .label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
        }
        .project-info {
            font-size: 13px;
            color: #64748b;
        }
        .project-info p {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        .project-info i {
            width: 16px;
            color: #059669;
        }
        .project-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }
        .btn {
            padding: 10px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            flex: 1;
            justify-content: center;
        }
        .btn-primary {
            background: #059669;
            color: white;
        }
        .btn-primary:hover {
            background: #047857;
        }
        .btn-outline {
            background: white;
            color: #059669;
            border: 1px solid #059669;
        }
        .btn-outline:hover {
            background: #ecfdf5;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #64748b;
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .empty-state i {
            font-size: 72px;
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

        /* Stats Bar */
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
    </style>
</head>
<body>
    <?php include 'common/navigation.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fas fa-project-diagram"></i>
                Proyectos
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
        <?php elseif (empty($proyectos)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>No hay proyectos</h3>
                <p>Tu empresa no tiene proyectos activos registrados.</p>
            </div>
        <?php else: ?>

            <!-- Stats Bar -->
            <div class="stats-bar">
                <div class="stat-item">
                    <i class="fas fa-project-diagram"></i>
                    <div>
                        <div class="value"><?= count($proyectos) ?></div>
                        <div class="label">Proyectos activos</div>
                    </div>
                </div>
                <div class="stat-item">
                    <i class="fas fa-users"></i>
                    <div>
                        <div class="value"><?= array_sum(array_column($proyectos, 'num_trabajadores')) ?></div>
                        <div class="label">Total trabajadores</div>
                    </div>
                </div>
                <div class="stat-item">
                    <i class="fas fa-clock"></i>
                    <div>
                        <div class="value"><?= array_sum(array_column($proyectos, 'jornadas_abiertas')) ?></div>
                        <div class="label">Jornadas abiertas hoy</div>
                    </div>
                </div>
            </div>

            <!-- Projects Grid -->
            <div class="projects-grid">
                <?php foreach ($proyectos as $p): ?>
                <div class="project-card">
                    <div class="project-header">
                        <h3><?= htmlspecialchars($p['nombre']) ?></h3>
                        <div class="localidad">
                            <i class="fas fa-map-marker-alt"></i>
                            <?= htmlspecialchars($p['localidad'] ?? 'Sin localidad') ?>
                        </div>
                    </div>
                    <div class="project-body">
                        <div class="project-stats">
                            <div class="project-stat">
                                <div class="value"><?= (int)$p['num_trabajadores'] ?></div>
                                <div class="label">Trabajadores</div>
                            </div>
                            <div class="project-stat">
                                <div class="value"><?= (int)$p['asistencias_hoy'] ?></div>
                                <div class="label">Asist. Hoy</div>
                            </div>
                            <div class="project-stat">
                                <div class="value"><?= (int)$p['jornadas_abiertas'] ?></div>
                                <div class="label">Abiertas</div>
                            </div>
                        </div>
                        
                        <div class="project-info">
                            <?php if ($p['fecha_inicio'] || $p['fecha_fin']): ?>
                            <p>
                                <i class="fas fa-calendar"></i>
                                <?php if ($p['fecha_inicio']): ?>
                                    <?= date('d/m/Y', strtotime($p['fecha_inicio'])) ?>
                                <?php endif; ?>
                                <?php if ($p['fecha_fin']): ?>
                                    - <?= date('d/m/Y', strtotime($p['fecha_fin'])) ?>
                                <?php endif; ?>
                            </p>
                            <?php endif; ?>
                            
                            <?php if ($p['lat'] && $p['lng']): ?>
                            <p>
                                <i class="fas fa-location-dot"></i>
                                Coordenadas: <?= number_format($p['lat'], 4) ?>, <?= number_format($p['lng'], 4) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="project-actions">
                            <a href="trabajadores.php?proyecto=<?= $p['id'] ?>" class="btn btn-outline">
                                <i class="fas fa-users"></i> Trabajadores
                            </a>
                            <a href="asistencias.php?proyecto=<?= $p['id'] ?>" class="btn btn-primary">
                                <i class="fas fa-calendar-check"></i> Asistencias
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>
</body>
</html>
