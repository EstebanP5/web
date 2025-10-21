<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'pm') {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$pmUserId = (int)$_SESSION['user_id'];

$projectId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if ($projectId <= 0) {
    header('Location: proyectos.php');
    exit;
}

$project = null;
if ($stmt = $conn->prepare('SELECT g.id, g.nombre, g.empresa, g.localidad, g.fecha_inicio, g.fecha_fin
    FROM proyectos_pm ppm
    JOIN grupos g ON g.id = ppm.proyecto_id
    WHERE ppm.user_id = ? AND g.id = ?
    LIMIT 1')) {
    $stmt->bind_param('ii', $pmUserId, $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    $project = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

if (!$project) {
    $_SESSION['pm_equipo_error'] = 'No se encontró el proyecto solicitado.';
    header('Location: proyectos.php');
    exit;
}

$projectIds = [];
if ($stmtProjects = $conn->prepare('SELECT proyecto_id FROM proyectos_pm WHERE user_id = ?')) {
    $stmtProjects->bind_param('i', $pmUserId);
    $stmtProjects->execute();
    $resultProjects = $stmtProjects->get_result();
    if ($resultProjects) {
        while ($row = $resultProjects->fetch_assoc()) {
            $projectIds[] = (int)$row['proyecto_id'];
        }
    }
    $stmtProjects->close();
}
if (!in_array($projectId, $projectIds, true)) {
    $_SESSION['pm_equipo_error'] = 'No se encontró el proyecto solicitado.';
    header('Location: proyectos.php');
    exit;
}

$createAssignmentsTable = "CREATE TABLE IF NOT EXISTS empleado_asignaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT NOT NULL,
    proyecto_id INT NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    status ENUM('programado','activo','finalizado') DEFAULT 'programado',
    creado_por INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_empleado (empleado_id),
    KEY idx_proyecto_fecha (proyecto_id, fecha_inicio),
    CONSTRAINT fk_asignacion_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_asignacion_proyecto FOREIGN KEY (proyecto_id) REFERENCES grupos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$conn->query($createAssignmentsTable);

function pm_parse_date(?string $value): ?DateTimeImmutable
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $dt : null;
}

function pm_format_date(string $value): string
{
    try {
        $dt = new DateTimeImmutable($value);
        return $dt->format('d/m/Y');
    } catch (Throwable $e) {
        return $value;
    }
}

function pm_sync_assignments(mysqli $conn): void
{
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    if ($stmt = $conn->prepare("UPDATE empleado_asignaciones SET status = 'finalizado' WHERE fecha_fin < ? AND status <> 'finalizado'")) {
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $stmt->close();
    }

    if ($stmt = $conn->prepare("UPDATE empleado_asignaciones SET status = 'programado' WHERE fecha_inicio > ? AND status = 'activo'")) {
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $stmt->close();
    }

    $activeRows = [];
    if ($stmt = $conn->prepare("SELECT id, empleado_id, proyecto_id, status FROM empleado_asignaciones WHERE fecha_inicio <= ? AND fecha_fin >= ? AND status <> 'finalizado'")) {
        $stmt->bind_param('ss', $today, $today);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $activeRows[] = $row;
            }
        }
        $stmt->close();
    }

    if (!empty($activeRows)) {
        $updateStatusStmt = $conn->prepare('UPDATE empleado_asignaciones SET status = "activo" WHERE id = ?');
        $deactivateStmt = $conn->prepare('UPDATE empleado_proyecto SET activo = 0 WHERE empleado_id = ? AND proyecto_id <> ? AND activo = 1');
        $activateStmt = $conn->prepare('UPDATE empleado_proyecto SET activo = 1, fecha_asignacion = IFNULL(fecha_asignacion, NOW()) WHERE empleado_id = ? AND proyecto_id = ?');
        $insertStmt = $conn->prepare('INSERT INTO empleado_proyecto (empleado_id, proyecto_id, activo, fecha_asignacion) VALUES (?, ?, 1, NOW())');

        foreach ($activeRows as $assignment) {
            $assignmentId = (int)$assignment['id'];
            $employeeId = (int)$assignment['empleado_id'];
            $activeProjectId = (int)$assignment['proyecto_id'];

            if ($assignment['status'] !== 'activo') {
                $updateStatusStmt->bind_param('i', $assignmentId);
                $updateStatusStmt->execute();
            }

            $deactivateStmt->bind_param('ii', $employeeId, $activeProjectId);
            $deactivateStmt->execute();

            $activateStmt->bind_param('ii', $employeeId, $activeProjectId);
            $activateStmt->execute();
            if ($activateStmt->affected_rows === 0) {
                $insertStmt->bind_param('ii', $employeeId, $activeProjectId);
                $insertStmt->execute();
            }
        }

        $updateStatusStmt->close();
        $deactivateStmt->close();
        $activateStmt->close();
        $insertStmt->close();
    }

    if ($stmt = $conn->prepare("UPDATE empleado_proyecto ep LEFT JOIN empleado_asignaciones ea ON ea.empleado_id = ep.empleado_id AND ea.status = 'activo' SET ep.activo = 0 WHERE ea.id IS NULL AND ep.activo = 1")) {
        $stmt->execute();
        $stmt->close();
    }

    $conn->query('DELETE ep FROM empleado_proyecto ep JOIN empleado_proyecto dup ON ep.empleado_id = dup.empleado_id AND ep.proyecto_id = dup.proyecto_id AND ep.id > dup.id');
}

pm_sync_assignments($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'schedule') {
        $empleadoId = isset($_POST['empleado_id']) ? (int)$_POST['empleado_id'] : 0;
        $startDate = pm_parse_date($_POST['fecha_inicio'] ?? null);
        $endDate = pm_parse_date($_POST['fecha_fin'] ?? null);

        if ($empleadoId <= 0 || !$startDate || !$endDate) {
            $_SESSION['pm_equipo_error'] = 'Debes elegir un Servicio Especializado y un periodo válido.';
            header('Location: proyecto_equipo.php?id=' . $projectId);
            exit;
        }

        if ($endDate < $startDate) {
            $_SESSION['pm_equipo_error'] = 'La fecha de fin debe ser posterior o igual a la fecha de inicio.';
            header('Location: proyecto_equipo.php?id=' . $projectId);
            exit;
        }

        $projectStartConstraint = null;
        if (!empty($project['fecha_inicio'])) {
            try {
                $projectStartConstraint = new DateTimeImmutable($project['fecha_inicio']);
            } catch (Throwable $e) {
                $projectStartConstraint = null;
            }
            if ($projectStartConstraint && $endDate < $projectStartConstraint) {
                $_SESSION['pm_equipo_error'] = 'El periodo seleccionado comienza antes de la vigencia del proyecto.';
                header('Location: proyecto_equipo.php?id=' . $projectId);
                exit;
            }
        }

        $projectEndConstraint = null;
        if (!empty($project['fecha_fin'])) {
            try {
                $projectEndConstraint = new DateTimeImmutable($project['fecha_fin']);
            } catch (Throwable $e) {
                $projectEndConstraint = null;
            }
            if ($projectEndConstraint) {
                if ($startDate > $projectEndConstraint) {
                    $_SESSION['pm_equipo_error'] = 'El proyecto termina antes del inicio del periodo indicado.';
                    header('Location: proyecto_equipo.php?id=' . $projectId);
                    exit;
                }
                if ($endDate > $projectEndConstraint) {
                    $_SESSION['pm_equipo_error'] = 'La fecha de fin supera la vigencia del proyecto.';
                    header('Location: proyecto_equipo.php?id=' . $projectId);
                    exit;
                }
            }
        }

        $empleadoActivo = 0;
        $empleadoBloqueado = 0;
        if ($stmtStatus = $conn->prepare('SELECT activo, COALESCE(bloqueado, 0) AS bloqueado FROM empleados WHERE id = ? LIMIT 1')) {
            $stmtStatus->bind_param('i', $empleadoId);
            $stmtStatus->execute();
            $stmtStatus->bind_result($empleadoActivo, $empleadoBloqueado);
            if (!$stmtStatus->fetch()) {
                $_SESSION['pm_equipo_error'] = 'El Servicio Especializado no existe.';
                $stmtStatus->close();
                header('Location: proyecto_equipo.php?id=' . $projectId);
                exit;
            }
            $stmtStatus->close();
        } else {
            $_SESSION['pm_equipo_error'] = 'No se pudo validar al Servicio Especializado.';
            header('Location: proyecto_equipo.php?id=' . $projectId);
            exit;
        }

        if ((int)$empleadoActivo !== 1) {
            $_SESSION['pm_equipo_error'] = 'El Servicio Especializado está dado de baja.';
            header('Location: proyecto_equipo.php?id=' . $projectId);
            exit;
        }

        if ((int)$empleadoBloqueado === 1) {
            $_SESSION['pm_equipo_error'] = 'El Servicio Especializado está bloqueado por SUA. Solicita al administrador el desbloqueo.';
            header('Location: proyecto_equipo.php?id=' . $projectId);
            exit;
        }

        $startStr = $startDate->format('Y-m-d');
        $endStr = $endDate->format('Y-m-d');

        $conflicts = [];
        if ($stmtOverlap = $conn->prepare('SELECT g.nombre, ea.fecha_inicio, ea.fecha_fin
            FROM empleado_asignaciones ea
            JOIN grupos g ON g.id = ea.proyecto_id
            WHERE ea.empleado_id = ? AND ea.fecha_fin >= ? AND ea.fecha_inicio <= ? AND ea.status <> "finalizado"')) {
            $stmtOverlap->bind_param('iss', $empleadoId, $startStr, $endStr);
            $stmtOverlap->execute();
            $resultOverlap = $stmtOverlap->get_result();
            if ($resultOverlap) {
                while ($row = $resultOverlap->fetch_assoc()) {
                    $conflicts[] = $row;
                }
            }
            $stmtOverlap->close();
        }

        if (!empty($conflicts)) {
            $conflictName = $conflicts[0]['nombre'] ?? 'otro proyecto';
            $_SESSION['pm_equipo_error'] = 'El periodo se traslapa con otra asignación para "' . $conflictName . '".';
            header('Location: proyecto_equipo.php?id=' . $projectId);
            exit;
        }

        if ($stmtInsert = $conn->prepare('INSERT INTO empleado_asignaciones (empleado_id, proyecto_id, fecha_inicio, fecha_fin, status, creado_por) VALUES (?, ?, ?, ?, "programado", ?)')) {
            $stmtInsert->bind_param('iissi', $empleadoId, $projectId, $startStr, $endStr, $pmUserId);
            if ($stmtInsert->execute()) {
                $stmtInsert->close();
                pm_sync_assignments($conn);
                $_SESSION['pm_equipo_success'] = 'Servicio Especializado programado del ' . pm_format_date($startStr) . ' al ' . pm_format_date($endStr) . '.';
                header('Location: proyecto_equipo.php?id=' . $projectId);
                exit;
            }
            $stmtInsert->close();
        }

        $_SESSION['pm_equipo_error'] = 'No se pudo registrar la asignación. Intenta de nuevo.';
        header('Location: proyecto_equipo.php?id=' . $projectId);
        exit;
    }

    if (in_array($action, ['cancel', 'complete'], true)) {
        $assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
        if ($assignmentId <= 0) {
            $_SESSION['pm_equipo_error'] = 'Asignación no válida.';
            header('Location: proyecto_equipo.php?id=' . $projectId);
            exit;
        }

        if ($action === 'cancel') {
            if ($stmtDelete = $conn->prepare('DELETE FROM empleado_asignaciones WHERE id = ? AND proyecto_id = ? AND status = "programado"')) {
                $stmtDelete->bind_param('ii', $assignmentId, $projectId);
                $stmtDelete->execute();
                $deleted = $stmtDelete->affected_rows > 0;
                $stmtDelete->close();
                if ($deleted) {
                    pm_sync_assignments($conn);
                    $_SESSION['pm_equipo_success'] = 'Asignación programada cancelada.';
                } else {
                    $_SESSION['pm_equipo_error'] = 'Solo se pueden cancelar asignaciones pendientes.';
                }
                header('Location: proyecto_equipo.php?id=' . $projectId);
                exit;
            }

            $_SESSION['pm_equipo_error'] = 'No se pudo cancelar la asignación.';
            header('Location: proyecto_equipo.php?id=' . $projectId);
            exit;
        }

        if ($action === 'complete') {
            $today = (new DateTimeImmutable('today'))->format('Y-m-d');
            if ($stmtUpdate = $conn->prepare("UPDATE empleado_asignaciones SET status = 'finalizado', fecha_fin = CASE WHEN fecha_fin > ? THEN ? ELSE fecha_fin END WHERE id = ? AND proyecto_id = ? AND status <> 'finalizado'")) {
                $stmtUpdate->bind_param('ssii', $today, $today, $assignmentId, $projectId);
                $stmtUpdate->execute();
                $updated = $stmtUpdate->affected_rows > 0;
                $stmtUpdate->close();
                if ($updated) {
                    pm_sync_assignments($conn);
                    $_SESSION['pm_equipo_success'] = 'Se marcó la asignación como finalizada.';
                } else {
                    $_SESSION['pm_equipo_error'] = 'La asignación ya estaba finalizada o no existe.';
                }
                header('Location: proyecto_equipo.php?id=' . $projectId);
                exit;
            }

            $_SESSION['pm_equipo_error'] = 'No se pudo finalizar la asignación.';
            header('Location: proyecto_equipo.php?id=' . $projectId);
            exit;
        }
    }

    $_SESSION['pm_equipo_error'] = 'Acción no válida.';
    header('Location: proyecto_equipo.php?id=' . $projectId);
    exit;
}

$successMessage = $_SESSION['pm_equipo_success'] ?? '';
$errorMessage = $_SESSION['pm_equipo_error'] ?? '';
unset($_SESSION['pm_equipo_success'], $_SESSION['pm_equipo_error']);

$assignments = [];
if ($stmtAssignments = $conn->prepare('SELECT ea.id, ea.empleado_id, ea.fecha_inicio, ea.fecha_fin, ea.status,
    e.nombre, e.telefono, e.nss, u.email
    FROM empleado_asignaciones ea
    JOIN empleados e ON e.id = ea.empleado_id
    LEFT JOIN users u ON u.id = e.id
    WHERE ea.proyecto_id = ?
    ORDER BY ea.fecha_inicio ASC, ea.id ASC')) {
    $stmtAssignments->bind_param('i', $projectId);
    if ($stmtAssignments->execute()) {
        $resultAssignments = $stmtAssignments->get_result();
        if ($resultAssignments) {
            while ($row = $resultAssignments->fetch_assoc()) {
                $assignments[] = $row;
            }
        }
    }
    $stmtAssignments->close();
}

$activeAssignments = [];
$upcomingAssignments = [];
$finishedAssignments = [];
foreach ($assignments as $assignment) {
    switch ($assignment['status']) {
        case 'activo':
            $activeAssignments[] = $assignment;
            break;
        case 'programado':
            $upcomingAssignments[] = $assignment;
            break;
        case 'finalizado':
        default:
            $finishedAssignments[] = $assignment;
            break;
    }
}

usort($activeAssignments, fn(array $a, array $b) => strcasecmp($a['nombre'] ?? '', $b['nombre'] ?? ''));
usort($upcomingAssignments, fn(array $a, array $b) => strcmp($a['fecha_inicio'] ?? '', $b['fecha_inicio'] ?? ''));
usort($finishedAssignments, fn(array $a, array $b) => strcmp($b['fecha_fin'] ?? '', $a['fecha_fin'] ?? ''));

$availableEmployees = [];
$sqlAvailable = 'SELECT e.id, e.nombre, e.telefono, e.nss,
    GROUP_CONCAT(DISTINCT CASE WHEN ep.activo = 1 THEN g.nombre END ORDER BY g.nombre SEPARATOR ", ") AS proyectos_activos,
    MAX(CASE WHEN ep.activo = 1 THEN ep.proyecto_id ELSE NULL END) AS proyecto_activo_id,
    MIN(CASE WHEN ea.status = "programado" THEN CONCAT(ea.fecha_inicio, "|", ea.fecha_fin) END) AS proximo_periodo
    FROM empleados e
    LEFT JOIN empleado_proyecto ep ON ep.empleado_id = e.id AND ep.activo = 1
    LEFT JOIN grupos g ON g.id = ep.proyecto_id
    LEFT JOIN empleado_asignaciones ea ON ea.empleado_id = e.id AND ea.status = "programado"
    WHERE e.activo = 1 AND IFNULL(e.bloqueado, 0) = 0
    GROUP BY e.id
    ORDER BY e.nombre';
if ($resultAvailable = $conn->query($sqlAvailable)) {
    while ($row = $resultAvailable->fetch_assoc()) {
        $currentProjectActive = $row['proyecto_activo_id'] !== null ? (int)$row['proyecto_activo_id'] : null;
        if ($currentProjectActive !== null && !in_array($currentProjectActive, $projectIds, true)) {
            continue;
        }
        $availableEmployees[] = $row;
    }
    $resultAvailable->close();
}

$statusBadges = [
    'activo' => ['label' => 'Activo', 'class' => 'status-active'],
    'programado' => ['label' => 'Programado', 'class' => 'status-upcoming'],
    'finalizado' => ['label' => 'Finalizado', 'class' => 'status-finished'],
];

$todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$projectStartLimit = null;
if (!empty($project['fecha_inicio'])) {
    try {
        $projectStartLimit = (new DateTimeImmutable($project['fecha_inicio']))->format('Y-m-d');
    } catch (Throwable $e) {
        $projectStartLimit = null;
    }
}
$projectEndLimit = null;
if (!empty($project['fecha_fin'])) {
    try {
        $projectEndLimit = (new DateTimeImmutable($project['fecha_fin']))->format('Y-m-d');
    } catch (Throwable $e) {
        $projectEndLimit = null;
    }
}
$minScheduleDate = $todayDate;
if ($projectStartLimit && $projectStartLimit > $todayDate) {
    $minScheduleDate = $projectStartLimit;
}

$activeCount = count($activeAssignments);
$upcomingCount = count($upcomingAssignments);
$pmCssPath = __DIR__ . '/../assets/pm.css';
$pmCssVersion = file_exists($pmCssPath) ? filemtime($pmCssPath) : time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipo del Proyecto - PM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/pm.css?v=<?= $pmCssVersion; ?>">
    <style>
        body {
            background: #f8fafc;
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
        .btn i {
            font-size: 14px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: #ffffff;
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.25);
        }
        .btn-secondary {
            background: #ffffff;
            color: #1f2937;
            border: 1px solid #cbd5f5;
        }
        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #ffffff;
        }
        .btn-link {
            background: none;
            border: none;
            color: #1d4ed8;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
        }
        .pm-layout {
            max-width: 1200px;
            margin: 32px auto;
            padding: 0 20px 40px;
        }
        .pm-header-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
        }
        .pm-header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .pm-header-top h1 {
            font-size: 28px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #0f172a;
        }
        .pm-project-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 12px;
            color: #475569;
            font-size: 14px;
        }
        .pm-chip {
            background: #eef2ff;
            color: #4338ca;
            padding: 6px 12px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .pm-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 360px);
            gap: 24px;
        }
        .pm-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            border: 1px solid #e2e8f0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            text-align: left;
            padding: 12px 14px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
            color: #334155;
        }
        th {
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.4px;
            color: #64748b;
            background: #f8fafc;
        }
        .pm-empty {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        .pm-feedback {
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .pm-feedback.success {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .pm-feedback.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .pm-form-group {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .pm-form-group select,
        .pm-form-group input[type="date"] {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            background: #f8fafc;
            font-size: 14px;
            color: #0f172a;
            transition: all 0.2s ease;
        }
        .pm-form-group select:focus,
        .pm-form-group input[type="date"]:focus {
            outline: none;
            border-color: #3b82f6;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .pm-form-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .pm-form-field {
            flex: 1;
            min-width: 160px;
        }
        .status-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-active {
            background: rgba(16, 185, 129, 0.12);
            color: #047857;
        }
        .status-upcoming {
            background: rgba(59, 130, 246, 0.12);
            color: #1d4ed8;
        }
        .status-finished {
            background: rgba(107, 114, 128, 0.12);
            color: #374151;
        }
        .pm-section-title {
            margin-top: 0;
            margin-bottom: 16px;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .pm-section-title i {
            color: #3b82f6;
        }
        .pm-period {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .pm-period span {
            font-size: 13px;
            color: #475569;
        }
        .pm-subtext {
            font-size: 13px;
            color: #64748b;
        }
        .pm-hint {
            font-size: 12px;
            color: #94a3b8;
        }
        @media (max-width: 1024px) {
            .pm-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/common/navigation.php'; ?>
<div class="pm-layout">
    <div class="pm-header-card">
        <div class="pm-header-top">
            <div>
                <h1><i class="fas fa-diagram-project"></i> Equipo de <?= htmlspecialchars($project['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h1>
                <div class="pm-project-meta">
                    <span class="pm-chip"><i class="fas fa-building"></i> <?= htmlspecialchars($project['empresa'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if (!empty($project['localidad'])): ?>
                        <span class="pm-chip"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($project['localidad'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <span class="pm-chip"><i class="fas fa-users"></i> <?= $activeCount; ?> activos</span>
                    <span class="pm-chip"><i class="fas fa-calendar-alt"></i> <?= $upcomingCount; ?> programados</span>
                </div>
            </div>
            <div class="pm-cta">
                <a class="btn btn-secondary" href="proyectos.php"><i class="fas fa-arrow-left"></i> Volver a proyectos</a>
                <a class="btn btn-primary" href="forms/crear_servicio.php"><i class="fas fa-user-plus"></i> Nuevo Servicio Especializado</a>
            </div>
        </div>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="pm-feedback success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
        <div class="pm-feedback error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="pm-grid">
        <div class="pm-card" style="overflow:hidden;">
            <h2 class="pm-section-title"><i class="fas fa-user-shield"></i> Asignaciones activas</h2>
            <?php if (empty($activeAssignments)): ?>
                <div class="pm-empty">
                    <i class="fas fa-calendar-times" style="font-size:36px; margin-bottom:12px;"></i>
                    <p>No hay Servicios Especializados activos en este proyecto.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto; max-height: 420px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Servicio</th>
                                <th>Periodo</th>
                                <th>Contacto</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeAssignments as $assignment): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($assignment['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <div class="pm-subtext">NSS: <?= htmlspecialchars($assignment['nss'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                    </td>
                                    <td>
                                        <div class="pm-period">
                                            <span>Del <?= pm_format_date($assignment['fecha_inicio']); ?> al <?= pm_format_date($assignment['fecha_fin']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($assignment['telefono'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="pm-subtext"><?= htmlspecialchars($assignment['email'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                    </td>
                                    <td>
                                        <?php $badge = $statusBadges[$assignment['status']] ?? $statusBadges['activo']; ?>
                                        <span class="status-tag <?= $badge['class']; ?>"><?= $badge['label']; ?></span>
                                    </td>
                                    <td>
                                        <form method="post" style="margin:0;">
                                            <input type="hidden" name="action" value="complete" />
                                            <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id']; ?>" />
                                            <button type="submit" class="btn btn-danger" title="Finalizar"><i class="fas fa-flag-checkered"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="pm-card">
            <h2 class="pm-section-title"><i class="fas fa-calendar-plus"></i> Programar Servicio</h2>
            <?php if (empty($availableEmployees)): ?>
                <div class="pm-empty">
                    <i class="fas fa-users-slash" style="font-size:32px; margin-bottom:12px;"></i>
                    <p>No hay Servicios Especializados disponibles ahora. Crea uno nuevo o libera desde otro de tus proyectos.</p>
                </div>
            <?php else: ?>
                <form method="post" class="pm-form-group">
                    <div class="pm-form-field">
                        <label for="empleado_id">Selecciona un Servicio Especializado</label>
                        <select name="empleado_id" id="empleado_id" required>
                            <option value="">-- Selecciona --</option>
                            <?php foreach ($availableEmployees as $employee): ?>
                                <?php
                                    $noteParts = [];
                                    $activeProjects = $employee['proyectos_activos'] ?? '';
                                    if ($activeProjects) {
                                        $noteParts[] = 'Activo en: ' . $activeProjects;
                                    }
                                    $nextStart = null;
                                    $nextEnd = null;
                                    if (!empty($employee['proximo_periodo'])) {
                                        $parts = explode('|', $employee['proximo_periodo']);
                                        $nextStart = $parts[0] ?? null;
                                        $nextEnd = $parts[1] ?? null;
                                        if (!empty($nextStart)) {
                                            $nextLabel = 'Próximo: ' . pm_format_date($nextStart);
                                            if (!empty($nextEnd)) {
                                                $nextLabel .= ' - ' . pm_format_date($nextEnd);
                                            }
                                            $noteParts[] = $nextLabel;
                                        }
                                    }
                                    $noteText = implode(' | ', $noteParts);
                                ?>
                                <option value="<?= (int)$employee['id']; ?>">
                                    <?= htmlspecialchars($employee['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?><?= $noteText !== '' ? ' (' . htmlspecialchars($noteText, ENT_QUOTES, 'UTF-8') . ')' : ' (Sin asignación activa)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pm-form-row">
                        <div class="pm-form-field">
                            <label for="fecha_inicio">Fecha de inicio</label>
                            <input type="date" name="fecha_inicio" id="fecha_inicio" required min="<?= htmlspecialchars($minScheduleDate, ENT_QUOTES, 'UTF-8'); ?>"<?php if ($projectEndLimit): ?> max="<?= htmlspecialchars($projectEndLimit, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                        </div>
                        <div class="pm-form-field">
                            <label for="fecha_fin">Fecha de fin</label>
                            <input type="date" name="fecha_fin" id="fecha_fin" required min="<?= htmlspecialchars($minScheduleDate, ENT_QUOTES, 'UTF-8'); ?>"<?php if ($projectEndLimit): ?> max="<?= htmlspecialchars($projectEndLimit, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                        </div>
                    </div>
                    <input type="hidden" name="action" value="schedule" />
                    <button type="submit" class="btn btn-primary" style="justify-content:center;"><i class="fas fa-calendar-check"></i> Guardar programación</button>
                    <p class="pm-hint">Los cambios se aplican en automático en las fechas indicadas y liberan al Servicio de otros proyectos que administres.</p>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="pm-grid" style="margin-top:24px;">
        <div class="pm-card" style="overflow:hidden;">
            <h2 class="pm-section-title"><i class="fas fa-clock"></i> Asignaciones programadas</h2>
            <?php if (empty($upcomingAssignments)): ?>
                <div class="pm-empty">
                    <i class="fas fa-calendar-check" style="font-size:32px; margin-bottom:12px;"></i>
                    <p>No hay periodos futuros registrados.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto; max-height: 360px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Servicio</th>
                                <th>Periodo</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingAssignments as $assignment): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($assignment['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <div class="pm-subtext">Contacto: <?= htmlspecialchars($assignment['telefono'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                    </td>
                                    <td>
                                        <div class="pm-period">
                                            <span>Del <?= pm_format_date($assignment['fecha_inicio']); ?> al <?= pm_format_date($assignment['fecha_fin']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php $badge = $statusBadges[$assignment['status']] ?? $statusBadges['programado']; ?>
                                        <span class="status-tag <?= $badge['class']; ?>"><?= $badge['label']; ?></span>
                                    </td>
                                    <td>
                                        <form method="post" style="margin:0;">
                                            <input type="hidden" name="action" value="cancel" />
                                            <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id']; ?>" />
                                            <button type="submit" class="btn btn-danger" title="Cancelar"><i class="fas fa-times"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="pm-card" style="overflow:hidden;">
            <h2 class="pm-section-title"><i class="fas fa-history"></i> Historial reciente</h2>
            <?php if (empty($finishedAssignments)): ?>
                <div class="pm-empty">
                    <i class="fas fa-clipboard-check" style="font-size:32px; margin-bottom:12px;"></i>
                    <p>Aún no hay asignaciones finalizadas.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto; max-height: 360px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Servicio</th>
                                <th>Periodo</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($finishedAssignments as $assignment): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($assignment['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <div class="pm-subtext">Finalizado el <?= pm_format_date($assignment['fecha_fin']); ?></div>
                                    </td>
                                    <td>
                                        <div class="pm-period">
                                            <span>Del <?= pm_format_date($assignment['fecha_inicio']); ?> al <?= pm_format_date($assignment['fecha_fin']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php $badge = $statusBadges[$assignment['status']] ?? $statusBadges['finalizado']; ?>
                                        <span class="status-tag <?= $badge['class']; ?>"><?= $badge['label']; ?></span>
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
</body>
</html>
