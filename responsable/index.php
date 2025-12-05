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

// Asegurar tabla de empresas_responsables
$conn->query("CREATE TABLE IF NOT EXISTS empresas_responsables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    empresa VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_empresa (user_id, empresa),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Obtener empresa del responsable
$stmt = $conn->prepare('SELECT empresa FROM empresas_responsables WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$responsableData = $stmt->get_result()->fetch_assoc();
$stmt->close();

$empresa_responsable = $responsableData['empresa'] ?? '';

$stats = [
    'trabajadores' => 0,
    'suas' => 0
];

$trabajadores_recientes = [];
$suas_recientes = [];

if ($empresa_responsable) {
    $empresa_lower = strtolower($empresa_responsable);
    
    // Contar trabajadores de la empresa (case-insensitive)
    $stmt = $conn->prepare('SELECT COUNT(DISTINCT e.id) as total 
        FROM empleados e 
        LEFT JOIN empleado_proyecto ep ON e.id = ep.empleado_id AND ep.activo = 1
        LEFT JOIN grupos g ON ep.proyecto_id = g.id AND g.activo = 1
        WHERE e.activo = 1 AND (LOWER(e.empresa) = ? OR LOWER(g.empresa) = ?)');
    $stmt->bind_param('ss', $empresa_lower, $empresa_lower);
    $stmt->execute();
    $stats['trabajadores'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    // Contar SUAs (lotes subidos por este usuario)
    $stmt = $conn->prepare('SELECT COUNT(*) as total FROM sua_lotes WHERE uploaded_by = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['suas'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    // Obtener trabajadores recientes (case-insensitive)
    $stmt = $conn->prepare('SELECT DISTINCT e.id, e.nombre, e.nss, e.curp, e.puesto, e.fecha_registro, e.empresa,
        (SELECT g2.nombre FROM empleado_proyecto ep2 
         JOIN grupos g2 ON ep2.proyecto_id = g2.id 
         WHERE ep2.empleado_id = e.id AND ep2.activo = 1 LIMIT 1) as proyecto
        FROM empleados e 
        LEFT JOIN empleado_proyecto ep ON e.id = ep.empleado_id AND ep.activo = 1
        LEFT JOIN grupos g ON ep.proyecto_id = g.id AND g.activo = 1
        WHERE e.activo = 1 AND (LOWER(e.empresa) = ? OR LOWER(g.empresa) = ?)
        ORDER BY e.fecha_registro DESC 
        LIMIT 10');
    $stmt->bind_param('ss', $empresa_lower, $empresa_lower);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $trabajadores_recientes[] = $row;
    }
    $stmt->close();

    // Obtener SUAs recientes (lotes subidos por este usuario)
    $stmt = $conn->prepare('SELECT * FROM sua_lotes WHERE uploaded_by = ? ORDER BY created_at DESC LIMIT 5');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $suas_recientes[] = $row;
    }
    $stmt->close();
}

$meses = [1=>'Ene',2=>'Feb',3=>'Mar',4=>'Abr',5=>'May',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dic'];

$pageTitle = 'Dashboard - Responsable';
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
            margin-bottom: 30px;
        }
        .page-header h1 {
            font-size: 28px;
            color: #1e293b;
            margin-bottom: 5px;
        }
        .page-header p {
            color: #64748b;
        }
        .empresa-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #059669;
            color: white;
            padding: 8px 16px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 14px;
            margin-top: 10px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-icon.blue { background: #dbeafe; color: #3b82f6; }
        .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-icon.orange { background: #ffedd5; color: #ea580c; }
        .stat-info h3 {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
        }
        .stat-info p {
            color: #64748b;
            font-size: 14px;
        }

        /* Cards */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
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
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-header h2 i {
            color: #059669;
        }
        .card-body {
            padding: 20px 24px;
        }
        .btn {
            padding: 8px 16px;
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
        }
        .btn-primary {
            background: #059669;
            color: white;
        }
        .btn-primary:hover {
            background: #047857;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
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
            padding: 12px;
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
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        .empty-state i {
            font-size: 48px;
            opacity: 0.3;
            margin-bottom: 15px;
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

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }
        .quick-action {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 24px;
            text-decoration: none;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s;
        }
        .quick-action:hover {
            border-color: #059669;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.15);
        }
        .quick-action i {
            font-size: 20px;
            color: #059669;
        }
        .quick-action span {
            font-weight: 600;
        }

        @media (max-width: 500px) {
            .cards-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'common/navigation.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1>¡Bienvenido, <?= htmlspecialchars(explode(' ', $user_name)[0]) ?>!</h1>
            <p>Panel de control de tu empresa</p>
            <?php if ($empresa_responsable): ?>
                <div class="empresa-badge">
                    <i class="fas fa-building"></i>
                    <?= htmlspecialchars($empresa_responsable) ?>
                </div>
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
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $stats['trabajadores'] ?></h3>
                        <p>Trabajadores</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $stats['suas'] ?></h3>
                        <p>SUAs Registrados</p>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="trabajadores.php" class="quick-action">
                    <i class="fas fa-users"></i>
                    <span>Ver Trabajadores</span>
                </a>
                <a href="suas.php" class="quick-action">
                    <i class="fas fa-upload"></i>
                    <span>Gestionar SUAs</span>
                </a>
            </div>

            <!-- Cards Grid -->
            <div class="cards-grid">
                <!-- Trabajadores Recientes -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-users"></i> Trabajadores Recientes</h2>
                        <a href="trabajadores.php" class="btn btn-primary btn-sm">Ver todos</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($trabajadores_recientes)): ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <p>No hay trabajadores registrados</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Nombre</th>
                                            <th>NSS</th>
                                            <th>Proyecto</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($trabajadores_recientes as $t): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($t['nombre']) ?></strong></td>
                                            <td><code><?= htmlspecialchars($t['nss'] ?? '-') ?></code></td>
                                            <td><?= htmlspecialchars($t['proyecto'] ?? '-') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- SUAs Recientes -->
                <div class="card" style="grid-column: 1 / -1;">
                    <div class="card-header">
                        <h2><i class="fas fa-file-invoice"></i> PDFs SUA Recientes</h2>
                        <a href="suas.php" class="btn btn-primary btn-sm">Gestionar SUAs</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($suas_recientes)): ?>
                            <div class="empty-state">
                                <i class="fas fa-file-invoice"></i>
                                <p>No hay PDFs SUA procesados</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Fecha Proceso</th>
                                            <th>Archivo</th>
                                            <th>Total Empleados</th>
                                            <th>Fecha Carga</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($suas_recientes as $s): ?>
                                        <tr>
                                            <td>
                                                <span class="badge badge-info">
                                                    <?= date('Y-m-d', strtotime($s['fecha_proceso'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (file_exists('../uploads/' . $s['archivo'])): ?>
                                                    <a href="../uploads/<?= htmlspecialchars($s['archivo']) ?>" target="_blank" style="color: #059669;">
                                                        <i class="fas fa-file-pdf"></i> Ver PDF
                                                    </a>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($s['archivo']) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><strong><?= $s['total'] ?></strong></td>
                                            <td><?= date('d/m/Y H:i', strtotime($s['created_at'])) ?></td>
                                            <td>
                                                <a href="descargar_credenciales_lote.php?lote_id=<?= $s['id'] ?>" target="_blank" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-download"></i> CSV
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>
</body>
</html>
