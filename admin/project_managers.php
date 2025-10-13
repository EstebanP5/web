<?php
require_once __DIR__ . '/includes/admin_init.php';

$conn->query("CREATE TABLE IF NOT EXISTS project_managers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    telefono VARCHAR(30) DEFAULT NULL,
    activo TINYINT(1) DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$mensaje_exito = $_SESSION['flash_success'] ?? '';
$mensaje_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$estadoFiltro = $_GET['estado'] ?? 'todos';
$proyectoFiltro = $_GET['proyecto'] ?? '';
$busqueda = trim($_GET['q'] ?? '');

function admin_pm_redirect(): void
{
    $uri = $_SERVER['REQUEST_URI'] ?? 'project_managers.php';
    header('Location: ' . $uri);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $pmId = isset($_POST['pm_id']) ? (int)$_POST['pm_id'] : 0;
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

    try {
        switch ($accion) {
            case 'editar':
                if ($pmId <= 0) {
                    throw new RuntimeException('Project manager no válido.');
                }
                $nombre = trim($_POST['nombre'] ?? '');
                $telefono = trim($_POST['telefono'] ?? '');
                if ($nombre === '') {
                    throw new RuntimeException('El nombre es obligatorio.');
                }
                $stmt = $conn->prepare('UPDATE project_managers SET nombre = ?, telefono = ? WHERE id = ?');
                if (!$stmt || !$stmt->bind_param('ssi', $nombre, $telefono, $pmId) || !$stmt->execute()) {
                    throw new RuntimeException('No se pudo actualizar la información.');
                }
                $_SESSION['flash_success'] = 'Información actualizada correctamente.';
                break;
            case 'correo':
                if ($pmId <= 0 || $userId <= 0) {
                    throw new RuntimeException('Project manager no válido.');
                }
                $email = trim($_POST['email'] ?? '');
                if ($email === '') {
                    throw new RuntimeException('Ingresa un correo electrónico.');
                }
                $stmt = $conn->prepare('UPDATE users SET email = ? WHERE id = ?');
                if (!$stmt || !$stmt->bind_param('si', $email, $userId) || !$stmt->execute()) {
                    throw new RuntimeException('No se pudo actualizar el correo.');
                }
                $_SESSION['flash_success'] = 'Correo actualizado correctamente.';
                break;
            case 'password':
                if ($pmId <= 0 || $userId <= 0) {
                    throw new RuntimeException('Project manager no válido.');
                }
                $password = $_POST['password'] ?? '';
                if ($password === '') {
                    throw new RuntimeException('La contraseña no puede estar vacía.');
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                if (!$stmt || !$stmt->bind_param('si', $hash, $userId) || !$stmt->execute()) {
                    throw new RuntimeException('No se pudo actualizar la contraseña.');
                }
                $_SESSION['flash_success'] = 'Contraseña actualizada exitosamente.';
                break;
            case 'estado':
                if ($pmId <= 0) {
                    throw new RuntimeException('Project manager no válido.');
                }
                $activo = isset($_POST['activo']) && (int)$_POST['activo'] === 1 ? 1 : 0;
                $stmt = $conn->prepare('UPDATE project_managers SET activo = ? WHERE id = ?');
                if ($stmt && $stmt->bind_param('ii', $activo, $pmId)) {
                    $stmt->execute();
                }
                if ($userId > 0) {
                    $stmt = $conn->prepare('UPDATE users SET activo = ? WHERE id = ?');
                    if ($stmt && $stmt->bind_param('ii', $activo, $userId)) {
                        $stmt->execute();
                    }
                }
                $_SESSION['flash_success'] = $activo ? 'Project manager reactivado.' : 'Project manager dado de baja.';
                break;
            case 'eliminar':
                if ($pmId <= 0) {
                    throw new RuntimeException('Project manager no válido.');
                }
                $conn->begin_transaction();
                $stmt = $conn->prepare('SELECT user_id FROM project_managers WHERE id = ?');
                $uid = 0;
                if ($stmt && $stmt->bind_param('i', $pmId) && $stmt->execute()) {
                    $res = $stmt->get_result();
                    if ($res && ($row = $res->fetch_assoc())) {
                        $uid = (int)($row['user_id'] ?? 0);
                    }
                }
                $stmt = $conn->prepare('DELETE FROM proyectos_pm WHERE user_id = ?');
                if ($stmt && $stmt->bind_param('i', $uid)) {
                    $stmt->execute();
                }
                $stmt = $conn->prepare('DELETE FROM project_managers WHERE id = ?');
                if (!$stmt || !$stmt->bind_param('i', $pmId) || !$stmt->execute()) {
                    throw new RuntimeException('No se pudo eliminar el project manager.');
                }
                if ($uid > 0) {
                    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND rol = 'pm'");
                    if ($stmt && $stmt->bind_param('i', $uid)) {
                        $stmt->execute();
                    }
                }
                $conn->commit();
                $_SESSION['flash_success'] = 'Project manager eliminado correctamente.';
                break;
            case 'asignar':
                if ($userId <= 0) {
                    throw new RuntimeException('Project manager no válido.');
                }
                $proyectoId = isset($_POST['proyecto_id']) ? (int)$_POST['proyecto_id'] : 0;
                if ($proyectoId <= 0) {
                    throw new RuntimeException('Selecciona un proyecto.');
                }
                $stmt = $conn->prepare('INSERT INTO proyectos_pm (user_id, proyecto_id, activo) VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE proyecto_id = VALUES(proyecto_id), activo = VALUES(activo)');
                if (!$stmt || !$stmt->bind_param('ii', $userId, $proyectoId) || !$stmt->execute()) {
                    throw new RuntimeException('No se pudo asignar el proyecto.');
                }
                $_SESSION['flash_success'] = 'Proyecto asignado al PM.';
                break;
            case 'desasignar':
                if ($userId <= 0) {
                    throw new RuntimeException('Project manager no válido.');
                }
                $proyectoId = isset($_POST['proyecto_id']) ? (int)$_POST['proyecto_id'] : 0;
                if ($proyectoId <= 0) {
                    throw new RuntimeException('Proyecto no válido.');
                }
                $stmt = $conn->prepare('UPDATE proyectos_pm SET activo = 0 WHERE user_id = ? AND proyecto_id = ?');
                if ($stmt && $stmt->bind_param('ii', $userId, $proyectoId)) {
                    $stmt->execute();
                }
                $_SESSION['flash_success'] = 'Proyecto desasignado.';
                break;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }

    admin_pm_redirect();
}

$pms = [];
$sql = "SELECT pm.*, u.email, u.id AS user_id, u.activo AS user_activo,
           COUNT(DISTINCT IF(ppm.activo = 1, g.id, NULL)) AS total_proyectos,
           GROUP_CONCAT(DISTINCT IF(ppm.activo = 1, g.nombre, NULL) ORDER BY g.nombre SEPARATOR '||') AS proyectos_list
        FROM project_managers pm
        LEFT JOIN users u ON pm.user_id = u.id
        LEFT JOIN proyectos_pm ppm ON ppm.user_id = pm.user_id
        LEFT JOIN grupos g ON g.id = ppm.proyecto_id
        GROUP BY pm.id
        ORDER BY pm.nombre";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pms[] = $row;
    }
}

$asignaciones = [];
$assignResult = $conn->query('SELECT ppm.user_id, ppm.proyecto_id, g.nombre
    FROM proyectos_pm ppm
    JOIN grupos g ON g.id = ppm.proyecto_id
    WHERE ppm.activo = 1
    ORDER BY g.nombre');
if ($assignResult) {
    while ($row = $assignResult->fetch_assoc()) {
        $userKey = (int)$row['user_id'];
        if (!isset($asignaciones[$userKey])) {
            $asignaciones[$userKey] = [];
        }
        $asignaciones[$userKey][] = $row;
    }
}

$proyectosDisponibles = [];
$resProyectos = $conn->query('SELECT id, nombre FROM grupos WHERE activo = 1 ORDER BY nombre');
if ($resProyectos) {
    while ($row = $resProyectos->fetch_assoc()) {
        $proyectosDisponibles[] = $row;
    }
}

$stats = [
    'total' => count($pms),
    'activos' => 0,
    'inactivos' => 0,
    'sin_proyecto' => 0,
    'total_asignaciones' => 0,
    'sobrecargados' => 0,
    'sin_correo' => 0,
];

$proyectosConteo = [];
$thresholdSobrecarga = 3;
$mayoresCargas = [];

foreach ($pms as $pm) {
    if ((int)($pm['activo'] ?? 0) === 1) {
        $stats['activos']++;
    } else {
        $stats['inactivos']++;
    }
    $userAssignments = $asignaciones[(int)($pm['user_id'] ?? 0)] ?? [];
    if (empty($userAssignments)) {
        $stats['sin_proyecto']++;
    }
    $assignCount = count($userAssignments);
    $stats['total_asignaciones'] += $assignCount;

    if (empty($pm['email'])) {
        $stats['sin_correo']++;
    }

    if ($assignCount >= $thresholdSobrecarga) {
        $stats['sobrecargados']++;
    }

    foreach ($userAssignments as $asignacion) {
        $projectId = (int)($asignacion['proyecto_id'] ?? 0);
        if ($projectId === 0) {
            continue;
        }
        if (!isset($proyectosConteo[$projectId])) {
            $proyectosConteo[$projectId] = [
                'nombre' => $asignacion['nombre'] ?? ('Proyecto #' . $projectId),
                'total' => 0,
            ];
        }
        $proyectosConteo[$projectId]['total']++;
    }

    $mayoresCargas[] = [
        'nombre' => $pm['nombre'] ?? 'PM',
        'total' => $assignCount,
    ];
}

$promedioAsignaciones = $stats['total'] > 0 ? round($stats['total_asignaciones'] / max($stats['total'], 1), 1) : 0;

usort($mayoresCargas, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});
$topSobrecarga = array_slice(array_filter($mayoresCargas, fn($pm) => $pm['total'] > 0), 0, 3);

uasort($proyectosConteo, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});
$topProyectos = array_slice($proyectosConteo, 0, 3);

$pmsFiltrados = array_filter($pms, function (array $pm) use ($estadoFiltro, $busqueda, $proyectoFiltro, $asignaciones) {
    $activo = (int)($pm['activo'] ?? 0);
    $userAssignments = $asignaciones[(int)($pm['user_id'] ?? 0)] ?? [];

    switch ($estadoFiltro) {
        case 'activos':
            if ($activo !== 1) {
                return false;
            }
            break;
        case 'inactivos':
            if ($activo !== 0) {
                return false;
            }
            break;
        case 'sin_proyecto':
            if (!empty($userAssignments)) {
                return false;
            }
            break;
    }

    if ($proyectoFiltro !== '') {
        $targetProject = (int)$proyectoFiltro;
        $asignacionesActivas = array_filter($userAssignments, fn($asignacion) => (int)($asignacion['proyecto_id'] ?? 0) === $targetProject);
        if (empty($asignacionesActivas)) {
            return false;
        }
    }

    if ($busqueda !== '') {
        $needle = mb_strtolower($busqueda, 'UTF-8');
        $haystack = mb_strtolower(implode(' ', array_filter([
            $pm['nombre'] ?? '',
            $pm['telefono'] ?? '',
            $pm['email'] ?? '',
            str_replace('||', ' ', $pm['proyectos_list'] ?? ''),
        ])), 'UTF-8');
        if (strpos($haystack, $needle) === false) {
            return false;
        }
    }

    return true;
});

$pmsFiltrados = array_values($pmsFiltrados);
$totalRegistros = count($pmsFiltrados);

$activeFilters = [];
if ($estadoFiltro !== 'todos') {
    $map = [
        'activos' => 'Solo activos',
        'inactivos' => 'Solo inactivos',
        'sin_proyecto' => 'Sin proyectos asignados',
    ];
    if (isset($map[$estadoFiltro])) {
        $activeFilters[] = $map[$estadoFiltro];
    }
}
if ($proyectoFiltro !== '') {
    $projectLabel = 'Proyecto #' . (int)$proyectoFiltro;
    foreach ($proyectosDisponibles as $proyecto) {
        if ((int)$proyecto['id'] === (int)$proyectoFiltro) {
            $projectLabel = 'Proyecto: ' . $proyecto['nombre'];
            break;
        }
    }
    $activeFilters[] = $projectLabel;
}
if ($busqueda !== '') {
    $activeFilters[] = 'Búsqueda: "' . htmlspecialchars($busqueda) . '"';
}

$pageTitle = 'Project Managers - ErgoCuida';
$activePage = 'pm';
$pageHeading = 'Project Managers';
$pageDescription = 'Administra credenciales, asignaciones y disponibilidad del equipo coordinador.';
$headerActions = [
    [
        'label' => 'Nuevo Project Manager',
        'icon' => 'fa-user-plus',
        'href' => 'crear_pm.php',
        'variant' => 'primary'
    ],
    [
        'label' => 'Ver proyectos',
        'icon' => 'fa-diagram-project',
        'href' => 'proyectos.php',
        'variant' => 'outline'
    ],
];
include __DIR__ . '/includes/header.php';
?>

<?php if ($mensaje_exito): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo $mensaje_exito; ?>
    </div>
<?php endif; ?>

<?php if ($mensaje_error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo $mensaje_error; ?>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon employees">
                <i class="fas fa-user-tie"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['total']; ?></div>
        <div class="stat-label">PMs registrados</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon projects">
                <i class="fas fa-toggle-on"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['activos']; ?></div>
        <div class="stat-label">Activos</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon attendance">
                <i class="fas fa-diagram-project"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['total_asignaciones']; ?></div>
        <div class="stat-label">Asignaciones activas</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon pms">
                <i class="fas fa-user-clock"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['sin_proyecto']; ?></div>
        <div class="stat-label">Sin proyectos asignados</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon alert">
                <i class="fas fa-fire"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['sobrecargados']; ?></div>
        <div class="stat-label">Sobrecarga (&ge; <?php echo $thresholdSobrecarga; ?>)</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon neutral">
                <i class="fas fa-at"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['sin_correo']; ?></div>
        <div class="stat-label">Sin correo registrado</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon analytics">
                <i class="fas fa-chart-line"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo number_format($promedioAsignaciones, 1); ?></div>
        <div class="stat-label">Promedio proyectos / PM</div>
    </div>
</div>

<div class="section">
    <h3><i class="fas fa-lightbulb"></i> Insights operativos</h3>
    <div class="insights-grid">
        <div class="insight-card">
            <div class="insight-card__header">
                <h4>Proyectos con más PMs</h4>
                <span class="metric-pill">Top <?php echo count($topProyectos); ?></span>
            </div>
            <?php if (!empty($topProyectos)): ?>
                <ul class="insight-list">
                    <?php foreach ($topProyectos as $proyecto): ?>
                        <li>
                            <div>
                                <strong><?php echo htmlspecialchars($proyecto['nombre']); ?></strong>
                                <span class="table-note">Asignaciones activas</span>
                            </div>
                            <span class="metric-value"><?php echo (int)$proyecto['total']; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted">Aún no hay proyectos asignados.</p>
            <?php endif; ?>
        </div>
        <div class="insight-card">
            <div class="insight-card__header">
                <h4>PMs con mayor carga</h4>
                <span class="metric-pill">Top <?php echo count($topSobrecarga); ?></span>
            </div>
            <?php if (!empty($topSobrecarga)): ?>
                <ul class="insight-list">
                    <?php foreach ($topSobrecarga as $pmCarga): ?>
                        <li>
                            <div>
                                <strong><?php echo htmlspecialchars($pmCarga['nombre']); ?></strong>
                                <span class="table-note">Proyectos activos</span>
                            </div>
                            <span class="metric-value"><?php echo (int)$pmCarga['total']; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted">Todos los PMs tienen carga equilibrada.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="section">
    <h3><i class="fas fa-filter"></i> Filtros</h3>
    <form method="GET" id="filtrosPm">
        <input type="hidden" name="estado" id="estadoFiltro" value="<?php echo htmlspecialchars($estadoFiltro); ?>">
        <div class="filters-grid">
            <div class="form-group">
                <label for="proyectoFiltro">Proyecto</label>
                <select id="proyectoFiltro" name="proyecto" class="form-control">
                    <option value="">Todos los proyectos</option>
                    <?php foreach ($proyectosDisponibles as $proyecto): ?>
                        <option value="<?php echo (int)$proyecto['id']; ?>" <?php echo ($proyectoFiltro !== '' && (int)$proyectoFiltro === (int)$proyecto['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($proyecto['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="busqueda">Buscar</label>
                <div class="search-input">
                    <i class="fas fa-search"></i>
                    <input type="search" id="busqueda" name="q" placeholder="Nombre, contacto, proyecto..." value="<?php echo htmlspecialchars($busqueda); ?>" autocomplete="off">
                </div>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <div class="actions-bar__group">
                    <button type="submit" class="btn btn-primary btn-compact"><i class="fas fa-check"></i> Aplicar</button>
                    <a href="project_managers.php" class="btn btn-secondary btn-compact"><i class="fas fa-rotate"></i> Limpiar</a>
                </div>
            </div>
        </div>
        <div class="actions-bar">
            <div class="quick-filters">
                <?php $quick = ['todos' => 'Todos', 'activos' => 'Activos', 'inactivos' => 'Inactivos', 'sin_proyecto' => 'Sin proyecto']; ?>
                <?php foreach ($quick as $key => $label): ?>
                    <button type="button" class="quick-filter<?php echo $estadoFiltro === $key ? ' is-active' : ''; ?>" data-estado="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></button>
                <?php endforeach; ?>
            </div>
            <div class="actions-bar__group">
                <span class="badge"><i class="fas fa-database"></i> <?php echo $stats['total']; ?> PMs totales</span>
            </div>
        </div>
    </form>

    <?php if (!empty($activeFilters)): ?>
        <div class="filter-chips">
            <?php foreach ($activeFilters as $chip): ?>
                <span class="chip"><i class="fas fa-tag"></i> <?php echo $chip; ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div id="resultadoTotal" class="result-count">Mostrando <strong><?php echo $totalRegistros; ?></strong> project managers</div>
</div>

<div class="section">
    <h3><i class="fas fa-users"></i> Listado de Project Managers</h3>
    <?php if ($totalRegistros === 0): ?>
        <div class="empty-state">
            <i class="fas fa-user-slash"></i>
            <h4>No hay registros</h4>
            <p>No encontramos PMs con los criterios actuales.</p>
            <a href="crear_pm.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Registrar PM</a>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="table" id="tablaPm">
                <thead>
                    <tr>
                        <th>Project manager</th>
                        <th>Contacto</th>
                        <th>Proyectos asignados</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pmsFiltrados as $pm): ?>
                        <?php
                            $activo = (int)($pm['activo'] ?? 0) === 1;
                            $userId = (int)($pm['user_id'] ?? 0);
                            $listaAsignaciones = $asignaciones[$userId] ?? [];
                            $asignacionesCount = count($listaAsignaciones);
                            $searchIndex = mb_strtolower(implode(' ', array_filter([
                                $pm['nombre'] ?? '',
                                $pm['telefono'] ?? '',
                                $pm['email'] ?? '',
                                str_replace('||', ' ', $pm['proyectos_list'] ?? ''),
                            ])), 'UTF-8');
                        ?>
                        <tr data-search="<?php echo htmlspecialchars($searchIndex); ?>">
                            <td>
                                <div class="table-entity">
                                    <strong><?php echo htmlspecialchars($pm['nombre'] ?? 'Project manager'); ?></strong>
                                    <span class="table-note">ID #<?php echo (int)$pm['id']; ?></span>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($pm['email'])): ?>
                                    <div><?php echo htmlspecialchars($pm['email']); ?></div>
                                <?php else: ?>
                                    <div class="table-note">Sin correo</div>
                                <?php endif; ?>
                                <?php if (!empty($pm['telefono'])): ?>
                                    <div class="table-note"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($pm['telefono']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($listaAsignaciones)): ?>
                                    <div class="chip-list">
                                        <?php foreach ($listaAsignaciones as $asignacion): ?>
                                            <form method="POST" class="chip chip--dismiss" onsubmit="return confirm('¿Quitar este proyecto del PM?');">
                                                <input type="hidden" name="accion" value="desasignar">
                                                <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
                                                <input type="hidden" name="proyecto_id" value="<?php echo (int)$asignacion['proyecto_id']; ?>">
                                                <span><i class="fas fa-diagram-project"></i> <?php echo htmlspecialchars($asignacion['nombre']); ?></span>
                                                <button type="submit" title="Quitar"><i class="fas fa-xmark"></i></button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="status-badge status-sin-pm">Sin proyectos</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="status-stack">
                                    <span class="status-badge <?php echo $activo ? 'status-activo' : 'status-inactivo'; ?>"><?php echo $activo ? 'Activo' : 'Inactivo'; ?></span>
                                    <?php if ($asignacionesCount >= $thresholdSobrecarga): ?>
                                        <span class="status-badge status-warning">Sobrecarga</span>
                                    <?php endif; ?>
                                    <?php if (empty($pm['email'])): ?>
                                        <span class="status-badge status-sin-credencial">Sin correo</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="btn btn-secondary btn-compact"
                                            data-open="modalPmEditar"
                                            data-id="<?php echo (int)$pm['id']; ?>"
                                            data-nombre="<?php echo htmlspecialchars($pm['nombre'] ?? '', ENT_QUOTES); ?>"
                                            data-telefono="<?php echo htmlspecialchars($pm['telefono'] ?? '', ENT_QUOTES); ?>">
                                        <i class="fas fa-pen"></i> Editar
                                    </button>
                                    <button type="button" class="btn btn-primary btn-compact"
                                            data-open="modalPmCredenciales"
                                            data-id="<?php echo (int)$pm['id']; ?>"
                                            data-user="<?php echo $userId; ?>"
                                            data-email="<?php echo htmlspecialchars($pm['email'] ?? '', ENT_QUOTES); ?>">
                                        <i class="fas fa-key"></i> Credenciales
                                    </button>
                                    <button type="button" class="btn btn-success btn-compact"
                                            data-open="modalPmAsignar"
                                            data-id="<?php echo (int)$pm['id']; ?>"
                                            data-user="<?php echo $userId; ?>"
                                            data-nombre="<?php echo htmlspecialchars($pm['nombre'] ?? '', ENT_QUOTES); ?>">
                                        <i class="fas fa-diagram-project"></i> Asignar
                                    </button>
                                    <form class="inline-form" method="POST" onsubmit="return confirm('<?php echo $activo ? '¿Dar de baja a este PM?' : '¿Reactivar a este PM?'; ?>');">
                                        <input type="hidden" name="accion" value="estado">
                                        <input type="hidden" name="pm_id" value="<?php echo (int)$pm['id']; ?>">
                                        <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
                                        <input type="hidden" name="activo" value="<?php echo $activo ? 0 : 1; ?>">
                                        <button type="submit" class="btn <?php echo $activo ? 'btn-warning' : 'btn-success'; ?> btn-compact">
                                            <i class="fas <?php echo $activo ? 'fa-user-slash' : 'fa-user-check'; ?>"></i>
                                            <?php echo $activo ? 'Dar de baja' : 'Reactivar'; ?>
                                        </button>
                                    </form>
                                    <form class="inline-form" method="POST" onsubmit="return confirm('Esta acción eliminará al PM y sus credenciales. ¿Continuar?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="pm_id" value="<?php echo (int)$pm['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-compact">
                                            <i class="fas fa-trash"></i> Eliminar
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div id="modalPmEditar" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Editar datos del PM</h3>
            <button type="button" class="close-btn" onclick="closeModal('modalPmEditar')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="pm_id" id="pmEditarId">
            <div class="form-grid">
                <div class="form-group">
                    <label for="pmEditarNombre">Nombre completo *</label>
                    <input type="text" id="pmEditarNombre" name="nombre" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="pmEditarTelefono">Teléfono</label>
                    <input type="tel" id="pmEditarTelefono" name="telefono" class="form-control" placeholder="10 dígitos">
                </div>
            </div>
            <div class="modal-actions modal-actions--right">
                <div class="modal-actions__group">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalPmEditar')">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="modalPmCredenciales" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Actualizar credenciales</h3>
            <button type="button" class="close-btn" onclick="closeModal('modalPmCredenciales')">&times;</button>
        </div>
        <form method="POST" class="form-stack" style="gap:18px; display:flex; flex-direction:column;">
            <input type="hidden" name="pm_id" id="pmCredencialesId">
            <input type="hidden" name="user_id" id="pmCredencialesUser">
            <div class="form-group">
                <label for="pmCredencialesEmail">Correo electrónico</label>
                <input type="email" id="pmCredencialesEmail" name="email" class="form-control" placeholder="correo@empresa.com">
                <small class="text-muted">El PM recibirá sus accesos en este correo.</small>
            </div>
            <div class="modal-actions modal-actions--right">
                <div class="modal-actions__group">
                    <button type="submit" name="accion" value="correo" class="btn btn-primary"><i class="fas fa-envelope"></i> Guardar correo</button>
                </div>
            </div>
        </form>
        <form method="POST" class="form-stack" style="gap:18px; display:flex; flex-direction:column; margin-top:12px;">
            <input type="hidden" name="pm_id" id="pmCredencialesIdPwd">
            <input type="hidden" name="user_id" id="pmCredencialesUserPwd">
            <div class="form-group">
                <label for="pmCredencialesPassword">Contraseña</label>
                <input type="password" id="pmCredencialesPassword" name="password" class="form-control" placeholder="Nueva contraseña">
                <small class="text-muted">Puedes regenerar el acceso del PM en cualquier momento.</small>
            </div>
            <div class="modal-actions modal-actions--right">
                <div class="modal-actions__group">
                    <button type="submit" name="accion" value="password" class="btn btn-success"><i class="fas fa-key"></i> Actualizar contraseña</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="modalPmAsignar" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Asignar proyecto</h3>
            <button type="button" class="close-btn" onclick="closeModal('modalPmAsignar')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="accion" value="asignar">
            <input type="hidden" name="pm_id" id="pmAsignarId">
            <input type="hidden" name="user_id" id="pmAsignarUser">
            <p>Selecciona el proyecto que atenderá <strong id="pmAsignarNombre">PM</strong>.</p>
            <div class="form-group">
                <label for="pmAsignarProyecto">Proyecto</label>
                <select id="pmAsignarProyecto" name="proyecto_id" class="form-control" required>
                    <option value="">Seleccionar...</option>
                    <?php foreach ($proyectosDisponibles as $proyecto): ?>
                        <option value="<?php echo (int)$proyecto['id']; ?>"><?php echo htmlspecialchars($proyecto['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-actions modal-actions--right">
                <div class="modal-actions__group">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalPmAsignar')">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-link"></i> Asignar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    window.openModal = function (modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'block';
            modal.setAttribute('aria-hidden', 'false');
        }
    };

    window.closeModal = function (modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
    };

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.modal-content') && event.target.classList.contains('modal')) {
            closeModal(event.target.id);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal').forEach((modal) => {
                if (modal.style.display === 'block') {
                    closeModal(modal.id);
                }
            });
        }
    });

    const filtrosForm = document.getElementById('filtrosPm');
    const estadoInput = document.getElementById('estadoFiltro');
    const quickButtons = document.querySelectorAll('[data-estado]');
    const proyectoSelect = document.getElementById('proyectoFiltro');
    const searchInput = document.getElementById('busqueda');
    const rows = Array.from(document.querySelectorAll('#tablaPm tbody tr'));
    const resultCount = document.getElementById('resultadoTotal');

    quickButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (estadoInput) {
                estadoInput.value = button.getAttribute('data-estado');
                filtrosForm.submit();
            }
        });
    });

    if (proyectoSelect) {
        proyectoSelect.addEventListener('change', () => filtrosForm.submit());
    }

    const updateCount = () => {
        if (!resultCount) return;
        const visibles = rows.filter((row) => row.style.display !== 'none').length;
        resultCount.innerHTML = `Mostrando <strong>${visibles}</strong> project managers`;
    };

    if (resultCount) {
        resultCount.dataset.total = rows.length;
    }

    if (searchInput) {
        searchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
        searchInput.addEventListener('input', () => {
            const term = searchInput.value.trim().toLowerCase();
            rows.forEach((row) => {
                const fuente = (row.dataset.search || row.textContent.toLowerCase());
                const visible = fuente.includes(term);
                row.style.display = visible ? '' : 'none';
            });
            updateCount();
        });
    }

    updateCount();

    const modalEditable = document.getElementById('modalPmEditar');
    const modalCredenciales = document.getElementById('modalPmCredenciales');
    const modalAsignar = document.getElementById('modalPmAsignar');

    document.querySelectorAll('[data-open="modalPmEditar"]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('pmEditarId').value = button.dataset.id || '';
            document.getElementById('pmEditarNombre').value = button.dataset.nombre || '';
            document.getElementById('pmEditarTelefono').value = button.dataset.telefono || '';
            openModal('modalPmEditar');
        });
    });

    document.querySelectorAll('[data-open="modalPmCredenciales"]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('pmCredencialesId').value = button.dataset.id || '';
            document.getElementById('pmCredencialesUser').value = button.dataset.user || '';
            document.getElementById('pmCredencialesIdPwd').value = button.dataset.id || '';
            document.getElementById('pmCredencialesUserPwd').value = button.dataset.user || '';
            document.getElementById('pmCredencialesEmail').value = button.dataset.email || '';
            document.getElementById('pmCredencialesPassword').value = '';
            openModal('modalPmCredenciales');
        });
    });

    document.querySelectorAll('[data-open="modalPmAsignar"]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('pmAsignarId').value = button.dataset.id || '';
            document.getElementById('pmAsignarUser').value = button.dataset.user || '';
            document.getElementById('pmAsignarNombre').textContent = button.dataset.nombre || 'el PM';
            document.getElementById('pmAsignarProyecto').selectedIndex = 0;
            openModal('modalPmAsignar');
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

