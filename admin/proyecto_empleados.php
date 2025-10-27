<?php
require_once __DIR__ . '/includes/admin_init.php';

$proyectoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($proyectoId <= 0) {
	$_SESSION['flash_error'] = 'Proyecto no válido.';
	header('Location: proyectos.php');
	exit;
}

$proyecto = null;
if ($stmtProyecto = $conn->prepare('SELECT id, nombre, empresa, localidad, fecha_inicio, fecha_fin, activo FROM grupos WHERE id = ? LIMIT 1')) {
	$stmtProyecto->bind_param('i', $proyectoId);
	$stmtProyecto->execute();
	$resultado = $stmtProyecto->get_result();
	$proyecto = $resultado ? $resultado->fetch_assoc() : null;
	$stmtProyecto->close();
}

if (!$proyecto) {
	$_SESSION['flash_error'] = 'No se encontró el proyecto solicitado.';
	header('Location: proyectos.php');
	exit;
}

$selectedEmployeeId = isset($_GET['empleado_id']) ? (int)$_GET['empleado_id'] : 0;
if ($selectedEmployeeId < 0) {
	$selectedEmployeeId = 0;
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
	CONSTRAINT fk_admin_asig_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
	CONSTRAINT fk_admin_asig_proyecto FOREIGN KEY (proyecto_id) REFERENCES grupos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$conn->query($createAssignmentsTable);

function admin_parse_date(?string $value): ?DateTimeImmutable
{
	if ($value === null) {
		return null;
	}
	$trimmed = trim($value);
	if ($trimmed === '') {
		return null;
	}
	$dt = DateTimeImmutable::createFromFormat('Y-m-d', $trimmed);
	return ($dt && $dt->format('Y-m-d') === $trimmed) ? $dt : null;
}

function admin_format_date(string $value): string
{
	try {
		$dt = new DateTimeImmutable($value);
		return $dt->format('d/m/Y');
	} catch (Throwable $e) {
		return $value;
	}
}

function admin_sync_assignments(mysqli $conn): void
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

	$activeAssignments = [];
	if ($stmt = $conn->prepare("SELECT id, empleado_id, proyecto_id, status FROM empleado_asignaciones WHERE fecha_inicio <= ? AND fecha_fin >= ? AND status <> 'finalizado'")) {
		$stmt->bind_param('ss', $today, $today);
		$stmt->execute();
		$result = $stmt->get_result();
		if ($result) {
			while ($row = $result->fetch_assoc()) {
				$activeAssignments[] = $row;
			}
		}
		$stmt->close();
	}

	if (!empty($activeAssignments)) {
		$setActiveStmt = $conn->prepare('UPDATE empleado_asignaciones SET status = "activo" WHERE id = ?');
		$deactivateStmt = $conn->prepare('UPDATE empleado_proyecto SET activo = 0 WHERE empleado_id = ? AND proyecto_id <> ? AND activo = 1');
		$activateStmt = $conn->prepare('UPDATE empleado_proyecto SET activo = 1, fecha_asignacion = IFNULL(fecha_asignacion, NOW()) WHERE empleado_id = ? AND proyecto_id = ?');
		$insertStmt = $conn->prepare('INSERT INTO empleado_proyecto (empleado_id, proyecto_id, activo, fecha_asignacion) VALUES (?, ?, 1, NOW())');

		foreach ($activeAssignments as $assignment) {
			$assignmentId = (int)($assignment['id'] ?? 0);
			$empleadoId = (int)($assignment['empleado_id'] ?? 0);
			$destinoId = (int)($assignment['proyecto_id'] ?? 0);
			if ($assignmentId <= 0 || $empleadoId <= 0 || $destinoId <= 0) {
				continue;
			}

			if ($assignment['status'] !== 'activo') {
				$setActiveStmt->bind_param('i', $assignmentId);
				$setActiveStmt->execute();
			}

			$deactivateStmt->bind_param('ii', $empleadoId, $destinoId);
			$deactivateStmt->execute();

			$activateStmt->bind_param('ii', $empleadoId, $destinoId);
			$activateStmt->execute();
			if ($activateStmt->affected_rows === 0) {
				$insertStmt->bind_param('ii', $empleadoId, $destinoId);
				$insertStmt->execute();
			}
		}

		$setActiveStmt->close();
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

admin_sync_assignments($conn);

$selectedEmployeeName = '';
$selectedLocatedInAssignments = false;
$selectedInAvailable = false;

$mensajeExito = $_SESSION['flash_success'] ?? '';
$mensajeError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$projectStartLimit = null;
$projectEndLimit = null;
if (!empty($proyecto['fecha_inicio'])) {
	try {
		$projectStartLimit = (new DateTimeImmutable($proyecto['fecha_inicio']))->format('Y-m-d');
	} catch (Throwable $e) {
		$projectStartLimit = null;
	}
}
if (!empty($proyecto['fecha_fin'])) {
	try {
		// Usar la fecha completa con hora para comparar correctamente
		$projectEndLimit = (new DateTimeImmutable($proyecto['fecha_fin']))->format('Y-m-d H:i:s');
	} catch (Throwable $e) {
		$projectEndLimit = null;
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';

	if ($action === 'schedule') {
		$empleadoId = isset($_POST['empleado_id']) ? (int)$_POST['empleado_id'] : 0;
		$inicio = admin_parse_date($_POST['fecha_inicio'] ?? null);
		$fin = admin_parse_date($_POST['fecha_fin'] ?? null);

		if ($empleadoId <= 0 || !$inicio || !$fin) {
			$_SESSION['flash_error'] = 'Selecciona un colaborador y un periodo válido.';
			header('Location: proyecto_empleados.php?id=' . $proyectoId);
			exit;
		}

		if ($fin < $inicio) {
			$_SESSION['flash_error'] = 'La fecha de fin debe ser posterior o igual al inicio.';
			header('Location: proyecto_empleados.php?id=' . $proyectoId);
			exit;
		}

		if ($projectStartLimit) {
			$limit = new DateTimeImmutable($projectStartLimit);
			if ($fin < $limit) {
				$_SESSION['flash_error'] = 'El periodo inicia antes de la vigencia del proyecto.';
				header('Location: proyecto_empleados.php?id=' . $proyectoId);
				exit;
			}
		}

		if ($projectEndLimit) {
			$limit = new DateTimeImmutable($projectEndLimit);
			// Permitir asignar trabajadores hasta el último momento de la vigencia (inclusive)
			if ($fin->format('Y-m-d') > $limit->format('Y-m-d')) {
				$_SESSION['flash_error'] = 'La fecha de fin rebasa la vigencia del proyecto.';
				header('Location: proyecto_empleados.php?id=' . $proyectoId);
				exit;
			}
		}

		$empleadoActivo = 0;
		$empleadoBloqueado = 0;
		if ($stmtStatus = $conn->prepare('SELECT activo, COALESCE(bloqueado, 0) FROM empleados WHERE id = ? LIMIT 1')) {
			$stmtStatus->bind_param('i', $empleadoId);
			$stmtStatus->execute();
			$stmtStatus->bind_result($empleadoActivo, $empleadoBloqueado);
			if (!$stmtStatus->fetch()) {
				$_SESSION['flash_error'] = 'El colaborador no existe.';
				$stmtStatus->close();
				header('Location: proyecto_empleados.php?id=' . $proyectoId);
				exit;
			}
			$stmtStatus->close();
		} else {
			$_SESSION['flash_error'] = 'No se pudo validar al colaborador.';
			header('Location: proyecto_empleados.php?id=' . $proyectoId);
			exit;
		}

		if ((int)$empleadoActivo !== 1) {
			$_SESSION['flash_error'] = 'El colaborador está dado de baja.';
			header('Location: proyecto_empleados.php?id=' . $proyectoId);
			exit;
		}

		if ((int)$empleadoBloqueado === 1) {
			$_SESSION['flash_error'] = 'El colaborador está bloqueado por SUA. Solicita el desbloqueo antes de programarlo.';
			header('Location: proyecto_empleados.php?id=' . $proyectoId);
			exit;
		}

	$inicioStr = $inicio->format('Y-m-d 00:00:00');
	$finStr = $fin->format('Y-m-d 00:00:00');

		$traslapes = [];
		if ($stmtOverlap = $conn->prepare('SELECT g.nombre, ea.fecha_inicio, ea.fecha_fin
			FROM empleado_asignaciones ea
			JOIN grupos g ON g.id = ea.proyecto_id
			WHERE ea.empleado_id = ? AND ea.fecha_fin >= ? AND ea.fecha_inicio <= ? AND ea.status <> "finalizado"')) {
			$stmtOverlap->bind_param('iss', $empleadoId, $inicioStr, $finStr);
			$stmtOverlap->execute();
			$resultOverlap = $stmtOverlap->get_result();
			if ($resultOverlap) {
				while ($row = $resultOverlap->fetch_assoc()) {
					$traslapes[] = $row;
				}
			}
			$stmtOverlap->close();
		}

		if (!empty($traslapes)) {
			$conflictName = $traslapes[0]['nombre'] ?? 'otro proyecto';
			$_SESSION['flash_error'] = 'El periodo se traslapa con otra asignación para "' . $conflictName . '".';
			header('Location: proyecto_empleados.php?id=' . $proyectoId);
			exit;
		}

		if ($stmtInsert = $conn->prepare('INSERT INTO empleado_asignaciones (empleado_id, proyecto_id, fecha_inicio, fecha_fin, status, creado_por) VALUES (?, ?, ?, ?, "programado", ?)')) {
			$stmtInsert->bind_param('iissi', $empleadoId, $proyectoId, $inicioStr, $finStr, $currentUserId);
			if ($stmtInsert->execute()) {
				$stmtInsert->close();
				admin_sync_assignments($conn);
				$_SESSION['flash_success'] = 'Colaborador programado del ' . admin_format_date($inicioStr) . ' al ' . admin_format_date($finStr) . '.';
				header('Location: proyecto_empleados.php?id=' . $proyectoId);
				exit;
			}
			$stmtInsert->close();
		}

		$_SESSION['flash_error'] = 'No se pudo registrar la programación. Intenta nuevamente.';
		header('Location: proyecto_empleados.php?id=' . $proyectoId);
		exit;
	}

	if (in_array($action, ['cancel', 'complete'], true)) {
		$assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
		if ($assignmentId <= 0) {
			$_SESSION['flash_error'] = 'Asignación no válida.';
			header('Location: proyecto_empleados.php?id=' . $proyectoId);
			exit;
		}

		if ($action === 'cancel') {
			if ($stmtDelete = $conn->prepare('DELETE FROM empleado_asignaciones WHERE id = ? AND proyecto_id = ? AND status = "programado"')) {
				$stmtDelete->bind_param('ii', $assignmentId, $proyectoId);
				$stmtDelete->execute();
				$deleted = $stmtDelete->affected_rows > 0;
				$stmtDelete->close();
				if ($deleted) {
					admin_sync_assignments($conn);
					$_SESSION['flash_success'] = 'Asignación programada cancelada.';
				} else {
					$_SESSION['flash_error'] = 'Solo se pueden cancelar asignaciones pendientes.';
				}
				header('Location: proyecto_empleados.php?id=' . $proyectoId);
				exit;
			}

			$_SESSION['flash_error'] = 'No se pudo cancelar la asignación.';
			header('Location: proyecto_empleados.php?id=' . $proyectoId);
			exit;
		}

		if ($action === 'complete') {
			$today = (new DateTimeImmutable('today'))->format('Y-m-d');
			if ($stmtUpdate = $conn->prepare("UPDATE empleado_asignaciones SET status = 'finalizado', fecha_fin = CASE WHEN fecha_fin > ? THEN ? ELSE fecha_fin END WHERE id = ? AND proyecto_id = ? AND status <> 'finalizado'")) {
				$stmtUpdate->bind_param('ssii', $today, $today, $assignmentId, $proyectoId);
				$stmtUpdate->execute();
				$updated = $stmtUpdate->affected_rows > 0;
				$stmtUpdate->close();
				if ($updated) {
					admin_sync_assignments($conn);
					$_SESSION['flash_success'] = 'Asignación marcada como finalizada.';
				} else {
					$_SESSION['flash_error'] = 'La asignación ya estaba finalizada o no existe.';
				}
				header('Location: proyecto_empleados.php?id=' . $proyectoId);
				exit;
			}

			$_SESSION['flash_error'] = 'No se pudo actualizar la asignación.';
			header('Location: proyecto_empleados.php?id=' . $proyectoId);
			exit;
		}
	}

	$_SESSION['flash_error'] = 'Acción no válida.';
	header('Location: proyecto_empleados.php?id=' . $proyectoId);
	exit;
}

$assignments = [];
if ($stmtAssignments = $conn->prepare('SELECT ea.id, ea.empleado_id, ea.fecha_inicio, ea.fecha_fin, ea.status,
		e.nombre, e.telefono, e.nss, e.puesto, e.empresa,
		u.email
	FROM empleado_asignaciones ea
	JOIN empleados e ON e.id = ea.empleado_id
	LEFT JOIN users u ON u.id = e.id
	WHERE ea.proyecto_id = ?
	ORDER BY ea.fecha_inicio ASC, ea.id ASC')) {
	$stmtAssignments->bind_param('i', $proyectoId);
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
$activeEmployeeIds = [];
foreach ($assignments as $assignment) {
	$status = $assignment['status'] ?? 'programado';
	$assignmentEmployeeId = (int)($assignment['empleado_id'] ?? 0);
	if (!$selectedLocatedInAssignments && $selectedEmployeeId > 0 && $assignmentEmployeeId === $selectedEmployeeId) {
		$selectedEmployeeName = $assignment['nombre'] ?? '';
		$selectedLocatedInAssignments = true;
	}
	switch ($status) {
		case 'activo':
			$activeAssignments[] = $assignment;
			$activeEmployeeIds[] = $assignmentEmployeeId;
			break;
		case 'programado':
			$upcomingAssignments[] = $assignment;
			break;
		default:
			$finishedAssignments[] = $assignment;
			break;
	}
}

usort($activeAssignments, static fn(array $a, array $b) => strcasecmp($a['nombre'] ?? '', $b['nombre'] ?? ''));
usort($upcomingAssignments, static fn(array $a, array $b) => strcmp($a['fecha_inicio'] ?? '', $b['fecha_inicio'] ?? ''));
usort($finishedAssignments, static fn(array $a, array $b) => strcmp($b['fecha_fin'] ?? '', $a['fecha_fin'] ?? ''));

$availableEmployees = [];
$seenAvailableIds = [];
$sqlAvailable = <<<'SQL'
SELECT e.id,
	e.nombre,
	e.telefono,
	e.nss,
	e.puesto,
	e.empresa,
	GROUP_CONCAT(DISTINCT CASE WHEN ep.activo = 1 THEN g.nombre END ORDER BY g.nombre SEPARATOR ', ') AS proyectos_activos,
	MAX(CASE WHEN ep.activo = 1 THEN ep.proyecto_id END) AS proyecto_activo_id,
	MIN(CASE WHEN ea.status = 'programado' AND ea.fecha_inicio >= CURDATE() THEN CONCAT(ea.fecha_inicio, '|', ea.fecha_fin, '|', g2.nombre) END) AS proximo_periodo,
	u.email
FROM empleados e
LEFT JOIN empleado_proyecto ep ON ep.empleado_id = e.id AND ep.activo = 1
LEFT JOIN grupos g ON g.id = ep.proyecto_id
LEFT JOIN empleado_asignaciones ea ON ea.empleado_id = e.id AND ea.status IN ('programado', 'activo')
LEFT JOIN grupos g2 ON g2.id = ea.proyecto_id
LEFT JOIN users u ON u.id = e.id
WHERE e.activo = 1 AND IFNULL(e.bloqueado, 0) = 0
GROUP BY e.id
ORDER BY e.nombre
SQL;
if ($resultAvailable = $conn->query($sqlAvailable)) {
	while ($row = $resultAvailable->fetch_assoc()) {
		$empleadoId = (int)($row['id'] ?? 0);
		if ($empleadoId <= 0) {
			continue;
		}
		if (isset($seenAvailableIds[$empleadoId])) {
			continue;
		}
		if (in_array($empleadoId, $activeEmployeeIds, true)) {
			continue;
		}
		$currentProjectActive = isset($row['proyecto_activo_id']) ? (int)$row['proyecto_activo_id'] : 0;
		if ($currentProjectActive > 0 && $currentProjectActive !== $proyectoId) {
			continue;
		}
		$seenAvailableIds[$empleadoId] = true;
		$availableEmployees[] = $row;
		if ($selectedEmployeeId > 0 && $empleadoId === $selectedEmployeeId) {
			$selectedInAvailable = true;
			if ($selectedEmployeeName === '') {
				$selectedEmployeeName = $row['nombre'] ?? '';
			}
		}
	}
	$resultAvailable->close();
}

$activeCount = count($activeAssignments);
$upcomingCount = count($upcomingAssignments);
$finishedCount = count($finishedAssignments);
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$minScheduleDate = $projectStartLimit && $projectStartLimit > $today ? $projectStartLimit : $today;
?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Equipo del proyecto – Admin</title>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
	<style>
		:root {
			--surface: #ffffff;
			--surface-soft: #f8fafc;
			--primary: #2563eb;
			--primary-dark: #1d4ed8;
			--danger: #dc2626;
			--muted: #64748b;
			--text: #0f172a;
			--border: #e2e8f0;
		}

		* { box-sizing: border-box; }

		body {
			margin: 0;
			font-family: 'Inter', 'Segoe UI', sans-serif;
			background: linear-gradient(180deg, #eef2ff 0%, #f8fafc 70%);
			color: var(--text);
			min-height: 100vh;
		}

		a { color: inherit; }

		.layout {
			max-width: 1200px;
			margin: 0 auto;
			padding: 32px 20px 48px;
			display: flex;
			flex-direction: column;
			gap: 24px;
		}

		.card {
			background: var(--surface);
			border-radius: 24px;
			padding: 28px;
			box-shadow: 0 18px 48px rgba(15, 23, 42, 0.08);
			border: 1px solid rgba(37, 99, 235, 0.08);
		}

		.header-card {
			display: flex;
			flex-direction: column;
			gap: 18px;
		}

		.header-top {
			display: flex;
			flex-wrap: wrap;
			gap: 16px;
			justify-content: space-between;
			align-items: flex-start;
		}

		.project-title {
			margin: 0;
			font-size: clamp(28px, 4vw, 36px);
			display: flex;
			align-items: center;
			gap: 12px;
		}

		.meta-line {
			display: inline-flex;
			flex-wrap: wrap;
			gap: 10px;
		}

		.meta-pill {
			padding: 6px 12px;
			border-radius: 999px;
			background: rgba(37, 99, 235, 0.1);
			color: var(--primary-dark);
			font-weight: 600;
			font-size: 13px;
			display: inline-flex;
			align-items: center;
			gap: 6px;
		}

		.header-actions {
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
		}

		.btn {
			border: none;
			border-radius: 12px;
			padding: 10px 18px;
			font-weight: 600;
			cursor: pointer;
			display: inline-flex;
			align-items: center;
			gap: 8px;
			transition: transform .15s ease, box-shadow .15s ease, background .2s ease;
			text-decoration: none;
		}

		.btn-primary {
			background: linear-gradient(135deg, var(--primary), var(--primary-dark));
			color: #fff;
			box-shadow: 0 12px 32px rgba(37, 99, 235, 0.18);
		}

		.btn-secondary {
			background: rgba(226, 232, 240, 0.9);
			color: var(--text);
		}

		.btn-danger {
			background: linear-gradient(135deg, #ef4444, #dc2626);
			color: #fff;
		}

		.btn-outline {
			border: 1px solid var(--border);
			background: transparent;
			color: var(--text);
		}

		.btn:hover { transform: translateY(-1px); }

		.summary-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
			gap: 18px;
		}

		.summary-card {
			background: var(--surface);
			border-radius: 18px;
			padding: 18px;
			border: 1px solid rgba(37, 99, 235, 0.08);
			display: flex;
			flex-direction: column;
			gap: 6px;
		}

		.summary-card strong {
			font-size: 30px;
		}

		.summary-card span {
			color: var(--muted);
			font-size: 13px;
		}

		.feedback {
			padding: 16px 18px;
			border-radius: 16px;
			font-size: 14px;
			display: flex;
			gap: 10px;
			align-items: center;
		}

		.feedback.success {
			background: #ecfdf5;
			border: 1px solid #a7f3d0;
			color: #047857;
		}

		.feedback.error {
			background: #fef2f2;
			border: 1px solid #fecaca;
			color: #b91c1c;
		}

		.columns {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
			gap: 24px;
		}

		.section-title {
			margin-top: 0;
			margin-bottom: 18px;
			font-size: 20px;
			font-weight: 700;
			display: flex;
			align-items: center;
			gap: 10px;
		}

		table {
			width: 100%;
			border-collapse: collapse;
			overflow: hidden;
		}

		th, td {
			text-align: left;
			padding: 12px 14px;
			font-size: 14px;
			border-bottom: 1px solid var(--border);
		}

		tr.row-highlight td {
			background: rgba(250, 204, 21, 0.2);
			animation: rowHighlightFade 2s ease-out;
		}

		@keyframes rowHighlightFade {
			0% { background: rgba(250, 204, 21, 0.45); }
			100% { background: rgba(250, 204, 21, 0.2); }
		}

		th {
			background: rgba(15, 23, 42, 0.05);
			text-transform: uppercase;
			font-size: 12px;
			letter-spacing: 0.3px;
			color: var(--muted);
		}

		.empty-state {
			padding: 36px 20px;
			text-align: center;
			color: var(--muted);
			display: flex;
			flex-direction: column;
			gap: 8px;
			align-items: center;
		}

		.status-badge {
			padding: 6px 12px;
			border-radius: 999px;
			font-weight: 600;
			font-size: 12px;
			display: inline-flex;
			align-items: center;
			gap: 6px;
		}

		.status-active { background: rgba(16, 185, 129, 0.14); color: #047857; }
		.status-upcoming { background: rgba(59, 130, 246, 0.16); color: #1d4ed8; }
		.status-finished { background: rgba(107, 114, 128, 0.16); color: #374151; }

		.form-grid {
			display: flex;
			flex-direction: column;
			gap: 16px;
			background: var(--surface-soft);
			border-radius: 18px;
			border: 1px dashed rgba(37, 99, 235, 0.3);
			padding: 18px;
		}

		.form-banner {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			background: rgba(37, 99, 235, 0.12);
			border-radius: 14px;
			padding: 10px 14px;
			font-size: 13px;
			color: var(--primary-dark);
			font-weight: 600;
		}

		.form-row {
			display: flex;
			gap: 14px;
			flex-wrap: wrap;
		}

		.form-field {
			flex: 1;
			min-width: 200px;
			display: flex;
			flex-direction: column;
			gap: 8px;
		}

		label {
			font-weight: 600;
			font-size: 13px;
			color: var(--muted);
		}

		select,
		input[type="date"] {
			border-radius: 12px;
			border: 1px solid var(--border);
			padding: 12px 14px;
			font-size: 14px;
			background: #fff;
		}

		select:focus,
		input[type="date"]:focus {
			outline: none;
			border-color: var(--primary);
			box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
		}

		.helper {
			font-size: 12px;
			color: var(--muted);
		}

		.people-list {
			display: flex;
			flex-direction: column;
			gap: 14px;
			max-height: 460px;
			overflow-y: auto;
			padding-right: 6px;
		}

		.person-card {
			border: 1px solid rgba(148, 163, 184, 0.28);
			border-radius: 18px;
			padding: 16px;
			background: var(--surface);
			display: flex;
			flex-direction: column;
			gap: 10px;
		}

		.person-head {
			display: flex;
			justify-content: space-between;
			gap: 10px;
			align-items: flex-start;
		}

		.person-name {
			display: flex;
			flex-direction: column;
			gap: 4px;
		}

		.person-name span {
			font-size: 13px;
			color: var(--muted);
		}

		.tag-row {
			display: inline-flex;
			gap: 6px;
			flex-wrap: wrap;
		}

		.tag {
			padding: 4px 9px;
			border-radius: 999px;
			font-size: 11px;
			font-weight: 600;
		}

		.tag-info { background: rgba(59, 130, 246, 0.14); color: #1d4ed8; }
		.tag-success { background: rgba(34, 197, 94, 0.16); color: #15803d; }

		.person-meta {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
			gap: 8px 14px;
			font-size: 13px;
			color: var(--muted);
		}

		.person-meta span { display: inline-flex; align-items: center; gap: 6px; }

		.schedule-btn {
			align-self: flex-end;
		}

		@media (max-width: 768px) {
			.header-top { flex-direction: column; align-items: flex-start; }
			.columns { grid-template-columns: 1fr; }
		}
	</style>
</head>
<body>
<div class="layout">
	<div class="card header-card">
		<div class="header-top">
			<div>
				<h1 class="project-title"><i class="fa-solid fa-diagram-project"></i> <?= htmlspecialchars($proyecto['nombre'] ?? 'Proyecto', ENT_QUOTES, 'UTF-8'); ?></h1>
				<div class="meta-line">
					<span class="meta-pill"><i class="fa-solid fa-building"></i> <?= htmlspecialchars($proyecto['empresa'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
					<?php if (!empty($proyecto['localidad'])): ?>
						<span class="meta-pill"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($proyecto['localidad'], ENT_QUOTES, 'UTF-8'); ?></span>
					<?php endif; ?>
					<span class="meta-pill"><i class="fa-solid fa-people-group"></i> <?= $activeCount; ?> activos</span>
					<span class="meta-pill"><i class="fa-solid fa-calendar-alt"></i> <?= $upcomingCount; ?> programados</span>
				</div>
			</div>
			<div class="header-actions">
				<a href="proyectos.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Volver a proyectos</a>
				<a href="crear_empleado.php" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Registrar Servicio</a>
			</div>
		</div>
		<div class="summary-grid">
			<div class="summary-card">
				<strong><?= $activeCount; ?></strong>
				<span>Colaboradores activos hoy</span>
			</div>
			<div class="summary-card">
				<strong><?= $upcomingCount; ?></strong>
				<span>Periodos programados</span>
			</div>
			<div class="summary-card">
				<strong><?= $finishedCount; ?></strong>
				<span>Asignaciones finalizadas</span>
			</div>
		</div>
	</div>

	<?php if ($mensajeExito !== ''): ?>
		<div class="feedback success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8'); ?></div>
	<?php endif; ?>
	<?php if ($mensajeError !== ''): ?>
		<div class="feedback error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8'); ?></div>
	<?php endif; ?>

	<div class="columns">
		<div class="card">
			<h2 class="section-title"><i class="fa-solid fa-user-shield"></i> Asignaciones activas</h2>
			<?php if (empty($activeAssignments)): ?>
				<div class="empty-state">
					<i class="fa-regular fa-calendar"></i>
					<strong>No hay colaboradores activos.</strong>
					<span>Programa un periodo para activar personal en este proyecto.</span>
				</div>
			<?php else: ?>
				<div style="overflow-x:auto;">
					<table>
						<thead>
							<tr>
							<th>Colaborador</th>
							<th>Empresa</th>
							<th>Periodo</th>
							<th>Contacto</th>
							<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($activeAssignments as $assignment): ?>
								<?php
									$assignmentEmployeeId = (int)($assignment['empleado_id'] ?? 0);
									$isHighlighted = $selectedEmployeeId > 0 && $assignmentEmployeeId === $selectedEmployeeId;
									$rowClassAttr = $isHighlighted ? ' class="row-highlight"' : '';
								?>
								<tr data-assignment-employee="<?= $assignmentEmployeeId; ?>"<?= $rowClassAttr; ?>>
								<td>
								<strong><?= htmlspecialchars($assignment['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong><br>
								<span style="font-size:12px;color:var(--muted);">NSS: <?= htmlspecialchars($assignment['nss'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
								</td>
								<td><?= htmlspecialchars($assignment['empresa'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
								<td>Del <?= admin_format_date($assignment['fecha_inicio']); ?> al <?= admin_format_date($assignment['fecha_fin']); ?></td>
								<td>
								<div><?= htmlspecialchars($assignment['telefono'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
								<div style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($assignment['email'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
								</td>
								<td>
								<form method="post" style="margin:0;">
								<input type="hidden" name="action" value="complete">
								<input type="hidden" name="assignment_id" value="<?= (int)$assignment['id']; ?>">
								<button type="submit" class="btn btn-danger" title="Finalizar"><i class="fa-solid fa-flag-checkered"></i></button>
								</form>
								</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<div class="card">
			<h2 class="section-title"><i class="fa-solid fa-calendar-plus"></i> Programar periodo</h2>
			<form method="post" class="form-grid" id="scheduleForm">
				<?php if ($selectedEmployeeId > 0): ?>
					<?php $selectedDisplayName = $selectedEmployeeName !== '' ? $selectedEmployeeName : ('colaborador #' . $selectedEmployeeId); ?>
					<div class="form-banner"><i class="fa-solid fa-user"></i> Gestionando a <?= htmlspecialchars($selectedDisplayName, ENT_QUOTES, 'UTF-8'); ?></div>
					<?php if (!$selectedInAvailable): ?>
						<p class="helper" style="margin:0;">Revisa las tablas de asignaciones activas o programadas para ajustar sus periodos.</p>
					<?php endif; ?>
				<?php endif; ?>
				<div class="form-field">
					<label for="empleado_id">Selecciona un colaborador</label>
					<select name="empleado_id" id="empleadoSelect" required>
						<option value="">-- Selecciona --</option>
						<?php foreach ($availableEmployees as $employee): ?>
							<?php
								$employeeId = (int)($employee['id'] ?? 0);
								$isSelectedEmployee = $selectedEmployeeId > 0 && $employeeId === $selectedEmployeeId;
								$nextInfo = $employee['proximo_periodo'] ?? '';
								$nextLabel = '';
								if ($nextInfo) {
									$parts = explode('|', $nextInfo);
									$nextStart = $parts[0] ?? '';
									$nextEnd = $parts[1] ?? '';
									$nextProject = $parts[2] ?? '';
									if ($nextStart !== '') {
										$nextLabel = 'Próximo: ' . admin_format_date($nextStart);
										if ($nextEnd !== '') {
											$nextLabel .= ' - ' . admin_format_date($nextEnd);
										}
										if ($nextProject !== '') {
											$nextLabel .= ' (' . $nextProject . ')';
										}
									}
								}
								$activeProjects = $employee['proyectos_activos'] ?? '';
								$display = htmlspecialchars($employee['nombre'] ?? '', ENT_QUOTES, 'UTF-8');
								$notes = [];
								if ($activeProjects) {
									$notes[] = 'Activos: ' . $activeProjects;
								}
								if ($nextLabel !== '') {
									$notes[] = $nextLabel;
								}
								$notesText = $notes ? ' (' . htmlspecialchars(implode(' | ', $notes), ENT_QUOTES, 'UTF-8') . ')' : ' (Disponible)';
							?>
															<option value="<?= $employeeId; ?>" data-name="<?= htmlspecialchars($employee['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"<?= $isSelectedEmployee ? ' selected' : ''; ?>>
																<?= $display . ' (' . htmlspecialchars($employee['empresa'] ?? '-', ENT_QUOTES, 'UTF-8') . ')' . $notesText; ?>
															</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="form-row">
					<div class="form-field">
						<label for="fecha_inicio">Fecha de inicio</label>
						<input type="date" name="fecha_inicio" id="fecha_inicio" required min="<?= htmlspecialchars($minScheduleDate, ENT_QUOTES, 'UTF-8'); ?>"<?php if ($projectEndLimit): ?> max="<?= htmlspecialchars($projectEndLimit, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
					</div>
					<div class="form-field">
						<label for="fecha_fin">Fecha de fin</label>
						<input type="date" name="fecha_fin" id="fecha_fin" required min="<?= htmlspecialchars($minScheduleDate, ENT_QUOTES, 'UTF-8'); ?>"<?php if ($projectEndLimit): ?> max="<?= htmlspecialchars(substr($projectEndLimit,0,10), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
					</div>
				</div>
				<input type="hidden" name="action" value="schedule">
				<button type="submit" class="btn btn-primary" style="align-self:flex-start;"><i class="fa-solid fa-calendar-check"></i> Guardar programación</button>
				<p class="helper">Las asignaciones activan y liberan al colaborador automáticamente en las fechas definidas.</p>
			</form>
		</div>
	</div>

	<div class="columns">
		<div class="card">
			   <h2 class="section-title"><i class="fa-solid fa-clock"></i> Asignaciones programadas</h2>
			   <?php if (empty($upcomingAssignments)): ?>
				   <div class="empty-state">
					   <i class="fa-regular fa-calendar-check"></i>
					   <strong>No hay periodos futuros.</strong>
					   <span>Los nuevos periodos aparecerán aquí.</span>
				   </div>
			   <?php else: ?>
				   <div style="overflow-x:auto;">
					   <table>
						   <thead>
							   <tr>
								   <th>Colaborador</th>
								   <th>Empresa</th>
								   <th>Periodo</th>
								   <th>Contacto</th>
								   <th></th>
							   </tr>
						   </thead>
						   <tbody>
							   <?php foreach ($upcomingAssignments as $assignment): ?>
								   <?php
									   $assignmentEmployeeId = (int)($assignment['empleado_id'] ?? 0);
									   $isHighlighted = $selectedEmployeeId > 0 && $assignmentEmployeeId === $selectedEmployeeId;
									   $rowClassAttr = $isHighlighted ? ' class="row-highlight"' : '';
								   ?>
								   <tr data-assignment-employee="<?= $assignmentEmployeeId; ?>"<?= $rowClassAttr; ?>>
									   <td><strong><?= htmlspecialchars($assignment['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></td>
									   <td><?= htmlspecialchars($assignment['empresa'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
									   <td>Del <?= admin_format_date($assignment['fecha_inicio']); ?> al <?= admin_format_date($assignment['fecha_fin']); ?></td>
									   <td><?= htmlspecialchars($assignment['telefono'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
									   <td>
										   <form method="post" style="margin:0;">
											   <input type="hidden" name="action" value="cancel">
											   <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id']; ?>">
											   <button type="submit" class="btn btn-danger" title="Cancelar"><i class="fa-solid fa-xmark"></i></button>
										   </form>
									   </td>
								   </tr>
							   <?php endforeach; ?>
						   </tbody>
					   </table>
				   </div>
			   <?php endif; ?>
		<h2 class="section-title"><i class="fa-solid fa-clipboard-list"></i> Historial reciente</h2>
		<?php if (empty($finishedAssignments)): ?>
			<div class="empty-state">
				<i class="fa-regular fa-file-lines"></i>
				<strong>Aún no hay asignaciones finalizadas.</strong>
				<span>Cuando un periodo termine aparecerá aquí para referencia.</span>
			</div>
		<?php else: ?>
			<div style="overflow-x:auto;">
				<table>
					<thead>
						<tr>
							<th>Colaborador</th>
							<th>Periodo</th>
							<th>Estado</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($finishedAssignments as $assignment): ?>
							<?php
								$assignmentEmployeeId = (int)($assignment['empleado_id'] ?? 0);
								$isHighlighted = $selectedEmployeeId > 0 && $assignmentEmployeeId === $selectedEmployeeId;
								$rowClassAttr = $isHighlighted ? ' class="row-highlight"' : '';
							?>
							<tr data-assignment-employee="<?= $assignmentEmployeeId; ?>"<?= $rowClassAttr; ?>>
								<td>
									<strong><?= htmlspecialchars($assignment['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
									<div style="font-size:12px;color:var(--muted);">Finalizado el <?= admin_format_date($assignment['fecha_fin']); ?></div>
								</td>
								<td>Del <?= admin_format_date($assignment['fecha_inicio']); ?> al <?= admin_format_date($assignment['fecha_fin']); ?></td>
								<td><span class="status-badge status-finished"><i class="fa-regular fa-circle-check"></i> Finalizado</span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>

<script>
	document.querySelectorAll('.schedule-btn').forEach(btn => {
		btn.addEventListener('click', () => {
			const employeeId = btn.getAttribute('data-employee');
			const select = document.getElementById('empleadoSelect');
			if (!select) {
				return;
			}
			select.value = employeeId;
			select.dispatchEvent(new Event('change'));
			const form = document.getElementById('scheduleForm');
			if (form) {
				form.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});
	});

<?php if ($selectedEmployeeId > 0): ?>
	(function () {
		const highlightId = '<?= $selectedEmployeeId; ?>';
		const targetRow = document.querySelector('[data-assignment-employee="' + highlightId + '"]');
		if (targetRow) {
			targetRow.classList.add('row-highlight');
			targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
			return;
		}

		const select = document.getElementById('empleadoSelect');
		if (select) {
			select.value = highlightId;
		}
		const form = document.getElementById('scheduleForm');
		if (form) {
			form.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	})();
<?php endif; ?>
</script>
</body>
</html>

