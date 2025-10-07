<?php
require_once __DIR__ . '/includes/admin_init.php';

$proyectoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($proyectoId <= 0) {
    die('Proyecto inválido');
}

function proyecto_servicios_especializados_redirect(int $proyectoId): void
{
    header('Location: proyecto_empleados.php?id=' . $proyectoId);
    exit;
}

function puestoEsPM(?string $puesto): bool
{
    $titulo = trim((string)$puesto);
    if ($titulo === '') {
        return false;
    }

    $lower = function_exists('mb_strtolower') ? mb_strtolower($titulo, 'UTF-8') : strtolower($titulo);
    $keywords = [
        'project manager',
        'pm',
        'gerente de proyecto',
        'coordinador de proyecto',
        'supervisor de proyecto',
    ];

    foreach ($keywords as $keyword) {
        if (strpos($lower, $keyword) !== false) {
            return true;
        }
    }

    return false;
}

function convertirSemanaISOaFecha(string $isoWeek): ?string
{
    if (!preg_match('/^(\d{4})-W(\d{2})$/', trim($isoWeek), $coincidencias)) {
        return null;
    }

    $anio = (int)$coincidencias[1];
    $semana = (int)$coincidencias[2];

    try {
        $fecha = new DateTime();
        $fecha->setISODate($anio, $semana);
        return $fecha->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function etiquetarSemana(string $fechaInicio): string
{
    try {
        $inicio = new DateTime($fechaInicio);
    } catch (Throwable $e) {
        return $fechaInicio;
    }

    $fin = (clone $inicio)->modify('+6 days');
    $isoSemana = $inicio->format('W');
    $anioIso = $inicio->format('o');

    return sprintf('Semana %s (%s - %s)',
        $isoSemana,
        $inicio->format('d/m/Y'),
        $fin->format('d/m/Y')
    );
}

if (!function_exists('obtenerInicioSemanaActual')) {
    function obtenerInicioSemanaActual(): string
    {
        $hoy = new DateTimeImmutable('today');
        $inicio = $hoy->modify('monday this week');
        return $inicio->format('Y-m-d');
    }
}

if (!function_exists('sincronizarProgramacionAutomatica')) {
    function sincronizarProgramacionAutomatica(mysqli $conn): void
    {
        $inicioSemana = obtenerInicioSemanaActual();

        if ($stmt = $conn->prepare('SELECT ep.empleado_id, ep.proyecto_id, e.bloqueado, e.activo AS empleado_activo, g.activo AS proyecto_activo
                                      FROM empleado_programacion ep
                                      JOIN empleados e ON e.id = ep.empleado_id
                                      JOIN grupos g ON g.id = ep.proyecto_id
                                     WHERE ep.semana_inicio = ?')) {
            $stmt->bind_param('s', $inicioSemana);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $empleadoId = (int)($row['empleado_id'] ?? 0);
                        $proyectoDestino = (int)($row['proyecto_id'] ?? 0);
                        if ($empleadoId <= 0 || $proyectoDestino <= 0) {
                            continue;
                        }

                        $bloqueado = (int)($row['bloqueado'] ?? 0) === 1;
                        $empleadoActivo = (int)($row['empleado_activo'] ?? 0) === 1;
                        $proyectoActivo = (int)($row['proyecto_activo'] ?? 0) === 1;

                        try {
                            $conn->begin_transaction();

                            if ($stmtClear = $conn->prepare('UPDATE empleado_proyecto SET activo = 0 WHERE empleado_id = ?')) {
                                $stmtClear->bind_param('i', $empleadoId);
                                $stmtClear->execute();
                                $stmtClear->close();
                            }

                            if (!$bloqueado && $empleadoActivo && $proyectoActivo) {
                                $updated = 0;
                                if ($stmtUpdate = $conn->prepare('UPDATE empleado_proyecto SET activo = 1, fecha_asignacion = NOW() WHERE empleado_id = ? AND proyecto_id = ?')) {
                                    $stmtUpdate->bind_param('ii', $empleadoId, $proyectoDestino);
                                    $stmtUpdate->execute();
                                    $updated = $stmtUpdate->affected_rows;
                                    $stmtUpdate->close();
                                }

                                if ($updated === 0) {
                                    if ($stmtInsert = $conn->prepare('INSERT INTO empleado_proyecto (empleado_id, proyecto_id, activo, fecha_asignacion) VALUES (?, ?, 1, NOW())')) {
                                        $stmtInsert->bind_param('ii', $empleadoId, $proyectoDestino);
                                        $stmtInsert->execute();
                                        $stmtInsert->close();
                                    }
                                }
                            }

                            $conn->commit();
                        } catch (Throwable $e) {
                            $conn->rollback();
                        }
                    }
                }
            }
            $stmt->close();
        }

        // Evitar personal bloqueado o proyectos inactivos en asignaciones activas.
        try {
            $conn->query('UPDATE empleado_proyecto ep
                           JOIN empleados e ON e.id = ep.empleado_id
                          SET ep.activo = 0
                        WHERE IFNULL(e.bloqueado, 0) = 1 AND ep.activo = 1');
        } catch (Throwable $e) {
            // Ignorar errores silenciados
        }

        try {
            $conn->query('UPDATE empleado_proyecto ep
                           JOIN grupos g ON g.id = ep.proyecto_id
                          SET ep.activo = 0
                        WHERE IFNULL(g.activo, 0) = 0 AND ep.activo = 1');
        } catch (Throwable $e) {
            // Ignorar errores silenciados
        }
    }
}

$proyecto = null;
if ($stmtProyecto = $conn->prepare('SELECT * FROM grupos WHERE id = ? LIMIT 1')) {
    $stmtProyecto->bind_param('i', $proyectoId);
    $stmtProyecto->execute();
    $resultProyecto = $stmtProyecto->get_result();
    $proyecto = $resultProyecto ? $resultProyecto->fetch_assoc() : null;
    $stmtProyecto->close();
}

if (!$proyecto) {
    die('Proyecto no encontrado');
}

$sqlProgramacion = "CREATE TABLE IF NOT EXISTS empleado_programacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT NOT NULL,
    proyecto_id INT NOT NULL,
    semana_inicio DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_empleado_semana (empleado_id, semana_inicio),
    KEY idx_proyecto_semana (proyecto_id, semana_inicio),
    CONSTRAINT fk_prog_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_prog_proyecto FOREIGN KEY (proyecto_id) REFERENCES grupos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

try {
    $conn->query($sqlProgramacion);
} catch (Throwable $e) {
    // Si la tabla ya existe o no se puede crear, continuar sin interrumpir la página.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $empleadoId = isset($_POST['empleado_id']) ? (int)$_POST['empleado_id'] : 0;

    if ($accion === 'programar_semana') {
        $semanaIso = trim($_POST['semana_iso'] ?? '');

        if ($empleadoId <= 0 || $semanaIso === '') {
            $_SESSION['flash_error'] = 'Selecciona un colaborador y una semana válida.';
            proyecto_servicios_especializados_redirect($proyectoId);
        }

        $fechaSemana = convertirSemanaISOaFecha($semanaIso);
        if ($fechaSemana === null) {
            $_SESSION['flash_error'] = 'La semana seleccionada no es válida.';
            proyecto_servicios_especializados_redirect($proyectoId);
        }

        $conflicto = null;
        if ($stmtConflicto = $conn->prepare('SELECT ep.id, ep.proyecto_id, g.nombre AS proyecto_nombre
                                              FROM empleado_programacion ep
                                              JOIN grupos g ON g.id = ep.proyecto_id
                                             WHERE ep.empleado_id = ? AND ep.semana_inicio = ?
                                             LIMIT 1')) {
            $stmtConflicto->bind_param('is', $empleadoId, $fechaSemana);
            $stmtConflicto->execute();
            $resultadoConflicto = $stmtConflicto->get_result();
            if ($resultadoConflicto) {
                $conflicto = $resultadoConflicto->fetch_assoc();
            }
            $stmtConflicto->close();
        }

        if ($conflicto) {
            if ((int)($conflicto['proyecto_id'] ?? 0) === $proyectoId) {
                $_SESSION['flash_error'] = 'El colaborador ya está programado en este proyecto para la semana seleccionada.';
            } else {
                $proyectoConflicto = trim((string)($conflicto['proyecto_nombre'] ?? 'otro proyecto'));
                $_SESSION['flash_error'] = 'El colaborador ya está apartado en esa semana para ' . $proyectoConflicto . '.';
            }
            proyecto_servicios_especializados_redirect($proyectoId);
        }

        if ($stmtProg = $conn->prepare('INSERT INTO empleado_programacion (empleado_id, proyecto_id, semana_inicio) VALUES (?, ?, ?)')) {
            $stmtProg->bind_param('iis', $empleadoId, $proyectoId, $fechaSemana);
            if ($stmtProg->execute()) {
                $_SESSION['flash_success'] = 'Semana programada correctamente.';
            } else {
                $_SESSION['flash_error'] = 'No fue posible programar la semana. Inténtalo nuevamente.';
            }
            $stmtProg->close();
        } else {
            $_SESSION['flash_error'] = 'No fue posible programar la semana. Inténtalo nuevamente.';
        }

    proyecto_servicios_especializados_redirect($proyectoId);
    }

    if ($accion === 'eliminar_programacion') {
        $programacionId = isset($_POST['programacion_id']) ? (int)$_POST['programacion_id'] : 0;
        if ($programacionId <= 0) {
            $_SESSION['flash_error'] = 'Programación no válida.';
            proyecto_servicios_especializados_redirect($proyectoId);
        }

        if ($stmtDelete = $conn->prepare('DELETE FROM empleado_programacion WHERE id = ? AND proyecto_id = ?')) {
            $stmtDelete->bind_param('ii', $programacionId, $proyectoId);
            if ($stmtDelete->execute() && $stmtDelete->affected_rows > 0) {
                $_SESSION['flash_success'] = 'Programación eliminada correctamente.';
            } else {
                $_SESSION['flash_error'] = 'No se pudo eliminar la programación seleccionada.';
            }
            $stmtDelete->close();
        } else {
            $_SESSION['flash_error'] = 'No se pudo eliminar la programación seleccionada.';
        }

    proyecto_servicios_especializados_redirect($proyectoId);
    }

    if (!in_array($accion, ['asignar', 'desasignar'], true) || $empleadoId <= 0) {
        $_SESSION['flash_error'] = 'Acción o empleado no válido.';
    proyecto_servicios_especializados_redirect($proyectoId);
    }

    $transactionStarted = false;

    try {
        if ($accion === 'desasignar') {
            if ($stmt = $conn->prepare('UPDATE empleado_proyecto SET activo = 0 WHERE empleado_id = ? AND proyecto_id = ? AND activo = 1')) {
                $stmt->bind_param('ii', $empleadoId, $proyectoId);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['flash_success'] = 'Empleado desasignado del proyecto.';
        } else {
            $transactionStarted = $conn->begin_transaction();

            if ($stmt = $conn->prepare('UPDATE empleado_proyecto SET activo = 0 WHERE empleado_id = ? AND activo = 1')) {
                $stmt->bind_param('i', $empleadoId);
                $stmt->execute();
                $stmt->close();
            }

            $updated = 0;
            if ($stmt = $conn->prepare('UPDATE empleado_proyecto SET activo = 1, fecha_asignacion = NOW() WHERE empleado_id = ? AND proyecto_id = ?')) {
                $stmt->bind_param('ii', $empleadoId, $proyectoId);
                $stmt->execute();
                $updated = $stmt->affected_rows;
                $stmt->close();
            }

            if ($updated === 0) {
                if ($stmt = $conn->prepare('INSERT INTO empleado_proyecto (empleado_id, proyecto_id, activo, fecha_asignacion) VALUES (?, ?, 1, NOW())')) {
                    $stmt->bind_param('ii', $empleadoId, $proyectoId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if ($transactionStarted) {
                $conn->commit();
            }
            $_SESSION['flash_success'] = 'Empleado asignado correctamente.';
        }
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        $_SESSION['flash_error'] = 'No se pudo actualizar la asignación. Inténtalo nuevamente.';
    }

    proyecto_servicios_especializados_redirect($proyectoId);
}

sincronizarProgramacionAutomatica($conn);

$msg = $_SESSION['flash_success'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$empresaProyecto = trim((string)($proyecto['empresa'] ?? ''));
$localidadProyecto = trim((string)($proyecto['localidad'] ?? ''));

$currentPm = null;
$pmVisible = '';
if ($stmtPm = $conn->prepare('SELECT pm.nombre, pm.telefono, u.email
    FROM proyectos_pm ppm
    JOIN project_managers pm ON pm.user_id = ppm.user_id
    LEFT JOIN users u ON u.id = pm.user_id
    WHERE ppm.proyecto_id = ? AND ppm.activo = 1
    ORDER BY pm.nombre
    LIMIT 1')) {
    $stmtPm->bind_param('i', $proyectoId);
    $stmtPm->execute();
    $resultPm = $stmtPm->get_result();
    if ($resultPm) {
        $currentPm = $resultPm->fetch_assoc();
        if ($currentPm) {
            $pmVisible = trim((string)($currentPm['nombre'] ?? ''));
        }
    }
    $stmtPm->close();
}

$totalPmAsignados = 0;
if ($stmtPmCount = $conn->prepare('SELECT COUNT(*) AS total FROM proyectos_pm WHERE proyecto_id = ? AND activo = 1')) {
    $stmtPmCount->bind_param('i', $proyectoId);
    $stmtPmCount->execute();
    $stmtPmCount->bind_result($totalPmAsignados);
    $stmtPmCount->fetch();
    $stmtPmCount->close();
}

$totalPmActivos = 0;
if ($resultPmActivos = $conn->query('SELECT COUNT(*) AS total FROM project_managers WHERE activo = 1')) {
    $row = $resultPmActivos->fetch_assoc();
    $totalPmActivos = (int)($row['total'] ?? 0);
}
$totalPmDisponibles = max($totalPmActivos - $totalPmAsignados, 0);

$alertasDuplicados = [];
$totalDuplicados = 0;
$dupQuery = "SELECT e.id, e.nombre, COUNT(*) AS total_asignaciones,
                    GROUP_CONCAT(g.nombre ORDER BY g.nombre SEPARATOR '||') AS proyectos_list
             FROM empleado_proyecto ep
             JOIN empleados e ON e.id = ep.empleado_id
             JOIN grupos g ON g.id = ep.proyecto_id
             WHERE ep.activo = 1
             GROUP BY e.id
             HAVING total_asignaciones > 1
             ORDER BY e.nombre";
if ($resultDup = $conn->query($dupQuery)) {
    while ($row = $resultDup->fetch_assoc()) {
        $proyectosList = !empty($row['proyectos_list'])
            ? array_filter(array_map('trim', explode('||', $row['proyectos_list'])))
            : [];
        $alertasDuplicados[] = [
            'nombre' => $row['nombre'] ?? 'Servicio Especializado',
            'total_asignaciones' => (int)($row['total_asignaciones'] ?? 0),
            'proyectos_activos' => $proyectosList,
        ];
    }
}
$totalDuplicados = count($alertasDuplicados);

$asignados = [];
$sqlAsignados = "SELECT e.id, e.nombre, e.telefono, e.puesto, e.nss, e.curp,
                        u.email,
                        EXISTS (SELECT 1 FROM project_managers pm WHERE pm.user_id = e.id AND pm.activo = 1) AS es_pm,
                        (SELECT COUNT(*) FROM empleado_proyecto ep2 WHERE ep2.empleado_id = e.id AND ep2.activo = 1 AND ep2.proyecto_id <> ?) AS otros_activos,
                        (SELECT GROUP_CONCAT(g2.nombre ORDER BY g2.nombre SEPARATOR '||')
                           FROM empleado_proyecto ep2
                           JOIN grupos g2 ON g2.id = ep2.proyecto_id
                          WHERE ep2.empleado_id = e.id AND ep2.activo = 1) AS proyectos_activos,
                        (SELECT GROUP_CONCAT(g2.nombre ORDER BY g2.nombre SEPARATOR '||')
                           FROM empleado_proyecto ep2
                           JOIN grupos g2 ON g2.id = ep2.proyecto_id
                          WHERE ep2.empleado_id = e.id AND ep2.activo = 1 AND ep2.proyecto_id <> ?) AS otros_proyectos
                 FROM empleado_proyecto ep
                 JOIN empleados e ON e.id = ep.empleado_id
                 LEFT JOIN users u ON u.id = e.id AND u.rol = 'servicio_especializado'
                 WHERE ep.proyecto_id = ? AND ep.activo = 1 AND IFNULL(e.bloqueado, 0) = 0 AND e.activo = 1
                 ORDER BY e.nombre";
if ($stmtAsignados = $conn->prepare($sqlAsignados)) {
    $stmtAsignados->bind_param('iii', $proyectoId, $proyectoId, $proyectoId);
    $stmtAsignados->execute();
    if ($resultAsignados = $stmtAsignados->get_result()) {
        while ($row = $resultAsignados->fetch_assoc()) {
            $row['otros_activos'] = max(0, (int)($row['otros_activos'] ?? 0));
            $row['es_pm'] = (int)($row['es_pm'] ?? 0);
            if (!$row['es_pm'] && puestoEsPM($row['puesto'] ?? null)) {
                $row['es_pm'] = 1;
            }
            $row['otros_proyectos'] = !empty($row['otros_proyectos'])
                ? array_filter(array_map('trim', explode('||', $row['otros_proyectos'])))
                : [];
            $row['proyectos_activos'] = !empty($row['proyectos_activos'])
                ? array_filter(array_map('trim', explode('||', $row['proyectos_activos'])))
                : [];
            $asignados[] = $row;
        }
    }
    $stmtAsignados->close();
}
$totalAsignados = count($asignados);

$noAsignados = [];
$sqlDisponibles = "SELECT e.id, e.nombre, e.telefono, e.puesto, e.nss, e.curp,
                          u.email,
                          EXISTS (SELECT 1 FROM project_managers pm WHERE pm.user_id = e.id AND pm.activo = 1) AS es_pm,
                          (SELECT COUNT(*) FROM empleado_proyecto ep2 WHERE ep2.empleado_id = e.id AND ep2.activo = 1) AS otros_activos,
                          (SELECT GROUP_CONCAT(g2.nombre ORDER BY g2.nombre SEPARATOR '||')
                             FROM empleado_proyecto ep2
                             JOIN grupos g2 ON g2.id = ep2.proyecto_id
                            WHERE ep2.empleado_id = e.id AND ep2.activo = 1) AS proyectos_activos
                   FROM empleados e
                   LEFT JOIN empleado_proyecto epActual ON epActual.empleado_id = e.id AND epActual.proyecto_id = ? AND epActual.activo = 1
                   LEFT JOIN users u ON u.id = e.id AND u.rol = 'servicio_especializado'
                   WHERE e.activo = 1 AND IFNULL(e.bloqueado, 0) = 0 AND epActual.empleado_id IS NULL
                   ORDER BY e.nombre";
if ($stmtDisponibles = $conn->prepare($sqlDisponibles)) {
    $stmtDisponibles->bind_param('i', $proyectoId);
    $stmtDisponibles->execute();
    if ($resultDisponibles = $stmtDisponibles->get_result()) {
        while ($row = $resultDisponibles->fetch_assoc()) {
            $row['otros_activos'] = max(0, (int)($row['otros_activos'] ?? 0));
            $row['es_pm'] = (int)($row['es_pm'] ?? 0);
            if (!$row['es_pm'] && puestoEsPM($row['puesto'] ?? null)) {
                $row['es_pm'] = 1;
            }
            $row['proyectos_activos'] = !empty($row['proyectos_activos'])
                ? array_filter(array_map('trim', explode('||', $row['proyectos_activos'])))
                : [];
            $row['otros_proyectos'] = $row['proyectos_activos'];
            $noAsignados[] = $row;
        }
    }
    $stmtDisponibles->close();
}
$totalDisponibles = count($noAsignados);

$totalCatalogo = 0;
if ($resultCatalogo = $conn->query('SELECT COUNT(*) AS total FROM empleados WHERE activo = 1')) {
    $row = $resultCatalogo->fetch_assoc();
    $totalCatalogo = (int)($row['total'] ?? 0);
}
$porcentajeDisponibles = $totalCatalogo > 0 ? round(($totalDisponibles / max($totalCatalogo, 1)) * 100, 1) : 0.0;

$tieneMbLower = function_exists('mb_strtolower');

$programaciones = [];
if ($stmtProg = $conn->prepare("SELECT ep.id, ep.empleado_id, ep.semana_inicio,
                                       e.nombre, e.puesto, e.telefono, e.bloqueado,
                                       u.email,
                                       g.activo AS proyecto_activo,
                                       EXISTS (SELECT 1 FROM empleado_proyecto epv WHERE epv.empleado_id = ep.empleado_id AND epv.proyecto_id = ep.proyecto_id AND epv.activo = 1) AS esta_asignado
                                  FROM empleado_programacion ep
                                  JOIN empleados e ON e.id = ep.empleado_id
                                  JOIN grupos g ON g.id = ep.proyecto_id
                                  LEFT JOIN users u ON u.id = e.id AND u.rol = 'servicio_especializado'
                                 WHERE ep.proyecto_id = ?
                                 ORDER BY ep.semana_inicio ASC, e.nombre ASC")) {
    $stmtProg->bind_param('i', $proyectoId);
    $stmtProg->execute();
    if ($resProg = $stmtProg->get_result()) {
        while ($row = $resProg->fetch_assoc()) {
            $fechaInicio = $row['semana_inicio'];
            $fechaFin = $fechaInicio;
            try {
                $inicio = new DateTime($fechaInicio);
                $fechaFin = $inicio->format('Y-m-d');
                $fin = (clone $inicio)->modify('+6 days');
                $fechaFin = $fin->format('Y-m-d');
            } catch (Throwable $e) {
                // Mantener valores por defecto si falla la conversión
            }

            $programaciones[] = [
                'id' => (int)($row['id'] ?? 0),
                'empleado_id' => (int)($row['empleado_id'] ?? 0),
                'nombre' => $row['nombre'] ?? '',
                'puesto' => $row['puesto'] ?? '',
                'telefono' => $row['telefono'] ?? '',
                'email' => $row['email'] ?? '',
                'semana_inicio' => $fechaInicio,
                'semana_fin' => $fechaFin,
                'etiqueta' => etiquetarSemana($fechaInicio),
                'asignado_actualmente' => (int)($row['esta_asignado'] ?? 0) === 1,
                'bloqueado' => (int)($row['bloqueado'] ?? 0) === 1,
                'proyecto_activo' => (int)($row['proyecto_activo'] ?? 0) === 1,
            ];
        }
    }
    $stmtProg->close();
}

$catalogoProgramacion = [];
foreach ($asignados as $fila) {
    $id = (int)($fila['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $catalogoProgramacion[$id] = [
        'id' => $id,
        'nombre' => $fila['nombre'] ?? 'Colaborador',
        'puesto' => $fila['puesto'] ?? '',
        'otros_activos' => (int)($fila['otros_activos'] ?? 0),
        'esta_asignado' => true,
    ];
}

foreach ($noAsignados as $fila) {
    $id = (int)($fila['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    if (!isset($catalogoProgramacion[$id])) {
        $catalogoProgramacion[$id] = [
            'id' => $id,
            'nombre' => $fila['nombre'] ?? 'Colaborador',
            'puesto' => $fila['puesto'] ?? '',
            'otros_activos' => (int)($fila['otros_activos'] ?? 0),
            'esta_asignado' => false,
        ];
    }
}

usort($catalogoProgramacion, static function (array $a, array $b): int {
    return strcasecmp($a['nombre'] ?? '', $b['nombre'] ?? '');
});

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asignaciones – <?= htmlspecialchars($proyecto['nombre'] ?? 'Proyecto') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #16a34a;
            --warning: #f97316;
            --danger: #dc2626;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --muted: #64748b;
            --text: #0f172a;
            --border: #e2e8f0;
            --shadow-card: 0 18px 50px rgba(15, 23, 42, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background: linear-gradient(180deg, #eef2ff 0%, #f8fafc 65%);
            color: var(--text);
            min-height: 100vh;
        }

        a {
            color: inherit;
        }

        .page-wrapper {
            max-width: 1180px;
            margin: 0 auto;
            padding: 32px 20px 64px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .page-card {
            background: var(--surface);
            border-radius: 24px;
            padding: 28px;
            box-shadow: var(--shadow-card);
            border: 1px solid rgba(37, 99, 235, 0.06);
        }

        .page-header {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--muted);
        }

        .breadcrumb a {
            text-decoration: none;
            color: inherit;
        }

        .header-main {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
        }

        .header-info h1 {
            margin: 0 0 6px;
            font-size: clamp(28px, 4vw, 36px);
        }

        .header-info p {
            margin: 0;
            color: var(--muted);
            max-width: 620px;
        }

        .header-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .meta-pill {
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.08);
            color: var(--primary-dark);
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .header-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn {
            appearance: none;
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
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            box-shadow: 0 12px 32px rgba(37, 99, 235, 0.2);
        }

        .btn-secondary {
            background: rgba(226, 232, 240, 0.9);
            color: var(--text);
        }

        .btn-ghost {
            background: rgba(148, 163, 184, 0.18);
            color: var(--primary-dark);
        }

        .btn:hover { transform: translateY(-1px); }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
        }

        .summary-card {
            background: var(--surface);
            border-radius: 18px;
            padding: 18px;
            border: 1px solid rgba(15, 23, 42, 0.06);
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .summary-card strong {
            font-size: 28px;
            color: var(--text);
        }

        .summary-card span {
            font-size: 13px;
            color: var(--muted);
        }

        .programacion-card {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .programacion-header {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: baseline;
            justify-content: space-between;
        }

        .programacion-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .programacion-header span {
            color: var(--muted);
            font-size: 14px;
        }

        .schedule-form {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
            background: var(--surface-soft);
            padding: 16px;
            border-radius: 16px;
            border: 1px dashed rgba(37, 99, 235, 0.3);
        }

        .schedule-form .form-control {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 220px;
        }

        .schedule-form label {
            font-size: 13px;
            color: var(--muted);
            font-weight: 600;
        }

        .schedule-form select,
        .schedule-form input[type="week"] {
            border-radius: 12px;
            border: 1px solid var(--border);
            padding: 10px 12px;
            font-size: 14px;
            background: white;
            min-height: 44px;
        }

        .schedule-form select:focus,
        .schedule-form input[type="week"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .schedule-form .btn {
            min-width: 180px;
        }

        .programacion-table-wrapper {
            overflow-x: auto;
        }

        .programacion-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 16px;
            overflow: hidden;
        }

        .programacion-table thead {
            background: rgba(37, 99, 235, 0.08);
        }

        .programacion-table th,
        .programacion-table td {
            padding: 12px 16px;
            text-align: left;
            font-size: 14px;
        }

        .programacion-table tbody tr:nth-child(even) {
            background: rgba(15, 23, 42, 0.03);
        }

        .programacion-badge {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            background: rgba(22, 163, 74, 0.12);
            color: var(--success);
        }

        .programacion-badge.warning {
            background: rgba(249, 115, 22, 0.16);
            color: var(--warning);
        }

        .programacion-badge.danger {
            background: rgba(220, 38, 38, 0.16);
            color: var(--danger);
        }

        .programacion-actions {
            display: flex;
            gap: 10px;
        }

        .stat-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .stat-badge.success { background: rgba(22, 163, 74, 0.12); color: var(--success); }
        .stat-badge.warning { background: rgba(249, 115, 22, 0.16); color: var(--warning); }
        .stat-badge.info { background: rgba(37, 99, 235, 0.12); color: var(--primary-dark); }

        .notice-card {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            background: rgba(22, 163, 74, 0.12);
            border: 1px solid rgba(22, 163, 74, 0.22);
            color: #14532d;
            border-radius: 18px;
            padding: 16px 18px;
        }

        .notice-card.error {
            background: rgba(220, 38, 38, 0.12);
            border-color: rgba(220, 38, 38, 0.25);
            color: #991b1b;
        }

        .notice-card.alert {
            background: rgba(249, 115, 22, 0.12);
            border-color: rgba(249, 115, 22, 0.25);
            color: #b45309;
        }

        .notice-card strong { display: block; margin-bottom: 4px; }

        .board-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
        }

        .search-bar {
            position: relative;
            flex: 1 1 280px;
            max-width: 420px;
        }

        .search-bar input {
            width: 100%;
            padding: 12px 16px 12px 40px;
            border-radius: 14px;
            border: 1.5px solid var(--border);
            font-size: 15px;
            background: var(--surface);
            transition: border-color .2s, box-shadow .2s;
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .search-bar i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 15px;
        }

        .filter-pills {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .filter-pill {
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.4);
            padding: 6px 14px;
            font-size: 13px;
            color: var(--muted);
            background: rgba(248, 250, 252, 0.8);
            cursor: pointer;
            transition: all .2s ease;
        }

        .filter-pill:hover { border-color: var(--primary); color: var(--primary-dark); }

        .filter-pill.is-active {
            background: var(--primary);
            border-color: transparent;
            color: #fff;
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.18);
        }

        .assignment-board {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
        }

        .board-column {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .column-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .column-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .counter-pill {
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.08);
            font-size: 12px;
            color: var(--text);
        }

        .board-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-height: 520px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .person-card {
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 18px;
            padding: 18px;
            background: var(--surface);
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }

        .person-card:hover {
            transform: translateY(-2px);
            border-color: rgba(37, 99, 235, 0.35);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.12);
        }

        .person-header {
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

        .person-name strong {
            font-size: 16px;
        }

        .person-name span {
            font-size: 13px;
            color: var(--muted);
        }

        .badge-stack {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge.pm { background: rgba(99, 102, 241, 0.18); color: #4338ca; }
        .badge.multi { background: rgba(14, 165, 233, 0.16); color: #0ea5e9; }
        .badge.free { background: rgba(34, 197, 94, 0.16); color: #15803d; }

        .person-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px 16px;
            font-size: 13px;
            color: var(--muted);
        }

        .meta-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .person-footer {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-outline {
            border: 1px solid rgba(148, 163, 184, 0.4);
            background: transparent;
            color: var(--text);
            padding: 8px 16px;
        }

        .btn-success {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: #fff;
            padding: 9px 18px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-success:hover { transform: translateY(-1px); }

        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 26px;
            border: 1.5px dashed rgba(148, 163, 184, 0.4);
            border-radius: 18px;
            background: rgba(248, 250, 252, 0.8);
            color: var(--muted);
            text-align: center;
            font-size: 14px;
        }

        .empty-state strong { color: var(--text); }

        .duplicates-list {
            margin: 10px 0 0;
            padding-left: 20px;
            color: inherit;
        }

        .duplicates-list li { margin-bottom: 4px; }

        @media (max-width: 960px) {
            .page-card { padding: 24px; }
            .person-meta { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
        }

        @media (max-width: 640px) {
            .page-wrapper { padding: 24px 16px 56px; }
            .header-main { flex-direction: column; align-items: flex-start; }
            .header-actions { width: 100%; }
            .header-actions .btn { flex: 1; justify-content: center; }
            .board-toolbar { flex-direction: column; align-items: stretch; }
            .filter-pills { justify-content: flex-start; }
        }
    </style>
</head>
<body>
        <div class="page-wrapper">
            <section class="page-card page-header">
                <nav class="breadcrumb">
                    <a href="admin.php">Admin</a>
                    <span aria-hidden="true">/</span>
                    <a href="proyectos.php">Proyectos</a>
                    <span aria-hidden="true">/</span>
                    <span><?= htmlspecialchars($proyecto['nombre']) ?></span>
                </nav>

                <div class="header-main">
                    <div class="header-info">
                        <h1><i class="fa-solid fa-people-arrows"></i> Asignaciones de <?= htmlspecialchars($proyecto['nombre']) ?></h1>
                        <p>Organiza quién puede trabajar en este proyecto, identifica Project Managers disponibles y controla los traslados entre sitios sin dejar la vista.</p>
                        <div class="header-meta">
                            <?php if ($empresaProyecto !== ''): ?>
                            <span class="meta-pill"><i class="fa-solid fa-building"></i> <?= htmlspecialchars($empresaProyecto) ?></span>
                            <?php endif; ?>
                            <?php if ($localidadProyecto !== ''): ?>
                            <span class="meta-pill"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($localidadProyecto) ?></span>
                            <?php endif; ?>
                            <?php if ($pmVisible !== ''): ?>
                            <span class="meta-pill"><i class="fa-solid fa-user-tie"></i> PM: <?= htmlspecialchars($pmVisible) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="header-actions">
                        <a href="editar_grupo.php?id=<?= (int)$proyectoId ?>" class="btn btn-ghost"><i class="fa-solid fa-pen"></i> Editar proyecto</a>
                        <a href="proyectos.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Volver al listado</a>
                    </div>
                </div>
            </section>

            <?php if ($msg): ?>
            <section class="page-card notice-card">
                <span><i class="fa-solid fa-circle-check"></i></span>
                <div>
                    <strong>Acción completada</strong>
                    <span><?= htmlspecialchars($msg) ?></span>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($error): ?>
            <section class="page-card notice-card error">
                <span><i class="fa-solid fa-circle-exclamation"></i></span>
                <div>
                    <strong>No se pudo completar la acción</strong>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            </section>
            <?php endif; ?>

            <?php if (!empty($alertasDuplicados)): ?>
            <section class="page-card notice-card alert">
                <span><i class="fa-solid fa-triangle-exclamation"></i></span>
                <div>
                    <strong>Colaboradores con más de un proyecto activo</strong>
                    <span>Revisa si necesitan desasignarse antes de moverlos. Esto puede impactar reportes de asistencia.</span>
                    <ul class="duplicates-list">
                        <?php foreach ($alertasDuplicados as $dup): ?>
                            <?php $listaProyectos = implode(', ', $dup['proyectos_activos'] ?? []); ?>
                            <li>
                                <?= htmlspecialchars($dup['nombre'] ?? 'Servicio Especializado') ?> – <?= (int)($dup['total_asignaciones'] ?? 0) ?> proyecto(s)
                                <?php if ($listaProyectos !== ''): ?> (<?= htmlspecialchars($listaProyectos) ?>)<?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
            <?php endif; ?>

            <section class="summary-grid">
                <article class="summary-card">
                    <strong><?= $totalAsignados ?></strong>
                    <span>Colaboradores asignados</span>
                    <span class="stat-badge info"><i class="fa-solid fa-users"></i> <?= $totalCatalogo ?> en catálogo</span>
                </article>
                <article class="summary-card">
                    <strong><?= $totalDisponibles ?></strong>
                    <span>Disponibles sin conflicto</span>
                    <span class="stat-badge success"><i class="fa-solid fa-arrow-trend-up"></i> <?= $porcentajeDisponibles ?>% del total</span>
                </article>
                <article class="summary-card">
                    <strong><?= $totalPmAsignados ?> / <?= $totalPmDisponibles ?></strong>
                    <span>PM asignados / libres</span>
                    <span class="stat-badge info"><i class="fa-solid fa-user-tie"></i> Gestión de responsables</span>
                </article>
                <article class="summary-card">
                    <strong><?= $totalDuplicados ?></strong>
                    <span>Alertas por duplicidad</span>
                    <span class="stat-badge warning"><i class="fa-solid fa-bell"></i> Monitorea movimientos</span>
                </article>
            </section>

            <section class="page-card programacion-card">
                <div class="programacion-header">
                    <h2><i class="fa-solid fa-calendar-week"></i> Programación semanal</h2>
                    <span>Selecciona la semana en la que necesitas reservar personal para este proyecto.</span>
                </div>
                <form class="schedule-form" method="post">
                    <input type="hidden" name="accion" value="programar_semana">
                    <div class="form-control">
                        <label for="programacionEmpleado">Colaborador</label>
                        <select id="programacionEmpleado" name="empleado_id" required>
                            <option value="">Selecciona un colaborador</option>
                            <?php foreach ($catalogoProgramacion as $persona): ?>
                                <?php
                                    $etiquetas = [];
                                    if (!empty($persona['puesto'])) {
                                        $etiquetas[] = $persona['puesto'];
                                    }
                                    if (!empty($persona['esta_asignado'])) {
                                        $etiquetas[] = 'Asignado a este proyecto';
                                    }
                                    if (!empty($persona['otros_activos'])) {
                                        $conteoOtros = (int)$persona['otros_activos'];
                                        $etiquetas[] = $conteoOtros > 1 ? "$conteoOtros proyectos activos" : "1 proyecto activo";
                                    } elseif (empty($persona['esta_asignado'])) {
                                        $etiquetas[] = 'Disponible';
                                    }
                                    $opcionLabel = trim($persona['nombre'] . ' · ' . implode(' · ', $etiquetas));
                                ?>
                                <option value="<?= (int)$persona['id'] ?>"><?= htmlspecialchars($opcionLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-control">
                        <label for="programacionSemana">Semana</label>
                        <input type="week" id="programacionSemana" name="semana_iso" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-calendar-plus"></i> Programar semana</button>
                </form>

                <?php if (empty($programaciones)): ?>
                    <div class="empty-state">
                        <i class="fa-regular fa-calendar"></i>
                        <strong>Aún no hay colaboradores programados</strong>
                        <span>Cuando apartes personal se mostrará aquí el calendario de reservas.</span>
                    </div>
                <?php else: ?>
                    <div class="programacion-table-wrapper">
                        <table class="programacion-table">
                            <thead>
                                <tr>
                                    <th>Semana</th>
                                    <th>Colaborador</th>
                                    <th>Estado actual</th>
                                    <th style="width: 130px;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($programaciones as $programa): ?>
                                    <?php
                                        $badgeClass = '';
                                        $badgeTexto = 'Asignado al proyecto';
                                        if (!empty($programa['bloqueado'])) {
                                            $badgeClass = 'danger';
                                            $badgeTexto = 'Bloqueado por SUA';
                                        } elseif (empty($programa['proyecto_activo'])) {
                                            $badgeClass = 'danger';
                                            $badgeTexto = 'Proyecto inactivo';
                                        } elseif (empty($programa['asignado_actualmente'])) {
                                            $badgeClass = 'warning';
                                            $badgeTexto = 'Disponible actualmente';
                                        }
                                        $contacto = [];
                                        if (!empty($programa['telefono'])) {
                                            $contacto[] = '<i class="fa-solid fa-phone"></i> ' . htmlspecialchars($programa['telefono'], ENT_QUOTES, 'UTF-8');
                                        }
                                        if (!empty($programa['email'])) {
                                            $contacto[] = '<i class="fa-solid fa-at"></i> ' . htmlspecialchars($programa['email'], ENT_QUOTES, 'UTF-8');
                                        }
                                        $contactoHtml = !empty($contacto) ? implode(' · ', $contacto) : '<span class="meta-item"><i class="fa-solid fa-circle-info"></i> Sin contacto registrado</span>';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($programa['etiqueta']) ?></strong><br>
                        <small><?= htmlspecialchars($programa['semana_inicio']) ?> al <?= htmlspecialchars($programa['semana_fin']) ?></small>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;"><?= htmlspecialchars($programa['nombre']) ?></div>
                                            <?php if (!empty($programa['puesto'])): ?><div class="table-note"><?= htmlspecialchars($programa['puesto']) ?></div><?php endif; ?>
                                            <div class="table-note" style="margin-top:4px;"><?= $contactoHtml ?></div>
                                        </td>
                                        <td>
                                            <span class="programacion-badge <?= $badgeClass ?>">
                                                <i class="fa-solid fa-user-clock"></i> <?= htmlspecialchars($badgeTexto) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="programacion-actions">
                                                <form method="post" onsubmit="return confirm('¿Cancelar la reserva de <?= htmlspecialchars($programa['nombre'], ENT_QUOTES, 'UTF-8') ?> para esta semana?');">
                                                    <input type="hidden" name="accion" value="eliminar_programacion">
                                                    <input type="hidden" name="programacion_id" value="<?= (int)$programa['id'] ?>">
                                                    <button type="submit" class="btn btn-ghost"><i class="fa-solid fa-trash"></i> Cancelar</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="page-card" style="display:flex;flex-direction:column;gap:24px;">
                <div class="board-toolbar">
                    <div class="search-bar">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="search" id="globalSearch" placeholder="Buscar por nombre, puesto o proyecto activo..." autocomplete="off">
                    </div>
                    <div class="filter-pills">
                        <button type="button" class="filter-pill is-active" data-filter="todos">Todos</button>
                        <button type="button" class="filter-pill" data-filter="asignados">Solo asignados</button>
                        <button type="button" class="filter-pill" data-filter="disponibles">Solo disponibles</button>
                        <button type="button" class="filter-pill" data-filter="pm">Project Managers</button>
                        <button type="button" class="filter-pill" data-filter="multiasignado">Con otros proyectos</button>
                    </div>
                </div>

                <div class="assignment-board">
                    <div class="board-column" data-board="asignados">
                        <div class="column-head">
                            <h2 class="column-title"><i class="fa-solid fa-clipboard-check"></i> Asignados al proyecto</h2>
                            <span class="counter-pill" data-role="counter"><?= $totalAsignados ?></span>
                        </div>
                        <div class="board-list" id="assignedList">
                            <?php if (empty($asignados)): ?>
                            <div class="empty-state" data-role="empty">
                                <i class="fa-regular fa-circle"></i>
                                <strong>Aún no hay personas asignadas</strong>
                                <span>Usa el panel de la derecha para sumar personal a este proyecto.</span>
                            </div>
                            <?php else: ?>
                            <div class="empty-state" data-role="empty" style="display:none;">
                                <i class="fa-regular fa-circle"></i>
                                <strong>No hay coincidencias</strong>
                                <span>Ajusta la búsqueda o filtros para ver el personal asignado.</span>
                            </div>
                            <?php endif; ?>
                            <?php foreach ($asignados as $e): ?>
                            <?php
                                $textoBase = trim($e['nombre'] . ' ' . ($e['puesto'] ?? '') . ' ' . implode(' ', $e['proyectos_activos'] ?? []));
                                $textoBusqueda = $tieneMbLower ? mb_strtolower($textoBase, 'UTF-8') : strtolower($textoBase);
                                $otrosProyectos = $e['otros_proyectos'] ?? [];
                                $telefono = trim($e['telefono'] ?? '');
                                $email = trim($e['email'] ?? '');
                            ?>
                            <article class="person-card" data-search="<?= htmlspecialchars($textoBusqueda, ENT_QUOTES, 'UTF-8') ?>" data-pm="<?= !empty($e['es_pm']) ? '1' : '0' ?>" data-otros="<?= (int)($e['otros_activos'] ?? 0) ?>" data-type="asignado">
                                <div class="person-header">
                                    <div class="person-name">
                                        <strong><?= htmlspecialchars($e['nombre']) ?></strong>
                                        <span><?= htmlspecialchars($e['puesto'] ?: 'Trabajador') ?></span>
                                    </div>
                                    <div class="badge-stack">
                                        <?php if (!empty($e['es_pm'])): ?><span class="badge pm"><i class="fa-solid fa-user-tie"></i> PM</span><?php endif; ?>
                                        <?php if (($e['otros_activos'] ?? 0) > 0): ?><span class="badge multi"><i class="fa-solid fa-layer-group"></i> <?= (int)$e['otros_activos'] ?> proyecto(s)</span><?php else: ?><span class="badge free"><i class="fa-solid fa-circle-check"></i> Exclusivo</span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="person-meta">
                                    <?php if ($telefono !== ''): ?><span class="meta-item"><i class="fa-solid fa-phone"></i><?= htmlspecialchars($telefono) ?></span><?php endif; ?>
                                    <?php if ($email !== ''): ?><span class="meta-item"><i class="fa-solid fa-at"></i><?= htmlspecialchars($email) ?></span><?php endif; ?>
                                    <?php if (!empty($otrosProyectos)): ?><span class="meta-item"><i class="fa-solid fa-diagram-project"></i><?= htmlspecialchars(implode(', ', $otrosProyectos)) ?></span><?php endif; ?>
                                </div>
                                <div class="person-footer">
                                    <form method="post" onsubmit="return confirm('¿Quitar a <?= htmlspecialchars($e['nombre'] ?? 'este empleado', ENT_QUOTES, 'UTF-8') ?> del proyecto?');">
                                        <input type="hidden" name="empleado_id" value="<?= (int)$e['id'] ?>">
                                        <input type="hidden" name="accion" value="desasignar">
                                        <button class="btn btn-outline" type="submit"><i class="fa-solid fa-xmark"></i> Quitar del proyecto</button>
                                    </form>
                                </div>
                            </article>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="board-column" data-board="disponibles">
                        <div class="column-head">
                            <h2 class="column-title"><i class="fa-solid fa-user-plus"></i> Disponibles para asignar</h2>
                            <span class="counter-pill" data-role="counter"><?= $totalDisponibles ?></span>
                        </div>
                        <div class="board-list" id="availableList">
                            <?php if (empty($noAsignados)): ?>
                            <div class="empty-state" data-role="empty">
                                <i class="fa-regular fa-face-smile"></i>
                                <strong>Todo el personal está ocupado</strong>
                                <span>Cuando liberes a alguien o registres un nuevo Servicio Especializado aparecerá aquí.</span>
                            </div>
                            <?php else: ?>
                            <div class="empty-state" data-role="empty" style="display:none;">
                                <i class="fa-regular fa-face-smile"></i>
                                <strong>No hay coincidencias</strong>
                                <span>Prueba limpiar los filtros o buscar por otro término.</span>
                            </div>
                            <?php endif; ?>
                            <?php foreach ($noAsignados as $e): ?>
                            <?php
                                $textoBase = trim($e['nombre'] . ' ' . ($e['puesto'] ?? '') . ' ' . implode(' ', $e['proyectos_activos'] ?? []));
                                $textoBusqueda = $tieneMbLower ? mb_strtolower($textoBase, 'UTF-8') : strtolower($textoBase);
                                $otrosProyectos = $e['otros_proyectos'] ?? [];
                                $telefono = trim($e['telefono'] ?? '');
                                $email = trim($e['email'] ?? '');
                            ?>
                            <article class="person-card" data-search="<?= htmlspecialchars($textoBusqueda, ENT_QUOTES, 'UTF-8') ?>" data-pm="<?= !empty($e['es_pm']) ? '1' : '0' ?>" data-otros="<?= (int)($e['otros_activos'] ?? 0) ?>" data-type="disponible">
                                <div class="person-header">
                                    <div class="person-name">
                                        <strong><?= htmlspecialchars($e['nombre']) ?></strong>
                                        <span><?= htmlspecialchars($e['puesto'] ?: 'Trabajador') ?></span>
                                    </div>
                                    <div class="badge-stack">
                                        <?php if (!empty($e['es_pm'])): ?><span class="badge pm"><i class="fa-solid fa-user-tie"></i> PM</span><?php endif; ?>
                                        <?php if (($e['otros_activos'] ?? 0) > 0): ?><span class="badge multi"><i class="fa-solid fa-layer-group"></i> <?= (int)$e['otros_activos'] ?> activo(s)</span><?php else: ?><span class="badge free"><i class="fa-solid fa-circle-check"></i> Libre</span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="person-meta">
                                    <?php if ($telefono !== ''): ?><span class="meta-item"><i class="fa-solid fa-phone"></i><?= htmlspecialchars($telefono) ?></span><?php endif; ?>
                                    <?php if ($email !== ''): ?><span class="meta-item"><i class="fa-solid fa-at"></i><?= htmlspecialchars($email) ?></span><?php endif; ?>
                                    <?php if (!empty($otrosProyectos)): ?><span class="meta-item"><i class="fa-solid fa-diagram-project"></i><?= htmlspecialchars(implode(', ', $otrosProyectos)) ?></span><?php else: ?><span class="meta-item"><i class="fa-solid fa-sun"></i> Sin asignaciones activas</span><?php endif; ?>
                                </div>
                                <div class="person-footer">
                                    <form method="post">
                                        <input type="hidden" name="empleado_id" value="<?= (int)$e['id'] ?>">
                                        <input type="hidden" name="accion" value="asignar">
                                        <button class="btn btn-success" type="submit"><i class="fa-solid fa-plus"></i> Asignar a este proyecto</button>
                                    </form>
                                </div>
                            </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('globalSearch');
            const filterButtons = document.querySelectorAll('.filter-pill');
            const cards = Array.from(document.querySelectorAll('.person-card'));
            const boardColumns = document.querySelectorAll('.board-column');
            let activeFilter = 'todos';

            function applyFilters() {
                const term = (searchInput?.value || '').trim().toLowerCase();
                cards.forEach(card => {
                    const index = (card.dataset.search || '').toLowerCase();
                    const isPm = card.dataset.pm === '1';
                    const otros = parseInt(card.dataset.otros || '0', 10);
                    const tipo = card.dataset.type || 'asignado';
                    let matchesFilter = true;

                    switch (activeFilter) {
                        case 'pm':
                            matchesFilter = isPm;
                            break;
                        case 'multiasignado':
                            matchesFilter = otros > 0;
                            break;
                        case 'asignados':
                            matchesFilter = tipo === 'asignado';
                            break;
                        case 'disponibles':
                            matchesFilter = tipo === 'disponible';
                            break;
                        default:
                            matchesFilter = true;
                    }

                    const matchesSearch = term === '' || index.includes(term);
                    card.style.display = matchesFilter && matchesSearch ? '' : 'none';
                });

                boardColumns.forEach(column => {
                    const list = column.querySelector('.board-list');
                    if (!list) return;
                    const visibleCards = Array.from(list.querySelectorAll('.person-card')).filter(card => card.style.display !== 'none').length;
                    const emptyState = column.querySelector('[data-role="empty"]');
                    if (emptyState) {
                        emptyState.style.display = visibleCards === 0 ? 'flex' : 'none';
                    }
                    const counter = column.querySelector('[data-role="counter"]');
                    if (counter) {
                        counter.textContent = visibleCards;
                    }
                });
            }

            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    window.requestAnimationFrame(applyFilters);
                });
            }

            filterButtons.forEach(button => {
                button.addEventListener('click', () => {
                    filterButtons.forEach(btn => btn.classList.remove('is-active'));
                    button.classList.add('is-active');
                    activeFilter = button.dataset.filter || 'todos';
                    applyFilters();
                });
            });

            applyFilters();
        });
        </script>
    </body>
    </html>
