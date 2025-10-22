<?php
require_once __DIR__ . '/includes/admin_init.php';

if (!defined('SERVICIO_ESPECIALIZADO_DEFAULT_PASSWORD')) {
    define('SERVICIO_ESPECIALIZADO_DEFAULT_PASSWORD', '123456');
}

$mensaje_exito = $_SESSION['flash_success'] ?? '';
$mensaje_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$estadoFiltro = $_GET['estado'] ?? 'todos';
$proyectoFiltro = $_GET['proyecto'] ?? '';
$puestoFiltro = $_GET['puesto'] ?? '';
$credencialFiltro = $_GET['credencial'] ?? 'todos';

$puestosOpciones = [
    'Servicio Especializado' => 'Servicio Especializado',
];
$puestoPorDefecto = 'Servicio Especializado';

$conn->query("UPDATE empleados SET puesto = 'Servicio Especializado' WHERE puesto IS NULL OR TRIM(puesto) = '' OR LOWER(puesto) IN ('trabajador','trabajadores','empleado','empleados','subcontratista','subcontratistas')");

function admin_servicios_especializados_redirect(): void
{
    $uri = $_SERVER['REQUEST_URI'] ?? 'empleados.php';
    header('Location: ' . $uri);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $empleadoId = isset($_POST['empleado_id']) ? (int)$_POST['empleado_id'] : 0;
    $nombre = trim($_POST['nombre'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $puesto = $puestoPorDefecto;
    $nss = trim($_POST['nss'] ?? '');
    $curp = trim($_POST['curp'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    switch ($accion) {
        case 'editar':
            if ($empleadoId <= 0 || $nombre === '') {
                $_SESSION['flash_error'] = 'Datos incompletos para actualizar al Servicio Especializado.';
                admin_servicios_especializados_redirect();
            }

            $stmt = $conn->prepare('UPDATE empleados SET nombre = ?, telefono = ?, puesto = ?, nss = ?, curp = ? WHERE id = ?');
            if (!$stmt || !$stmt->bind_param('sssssi', $nombre, $telefono, $puesto, $nss, $curp, $empleadoId) || !$stmt->execute()) {
                $_SESSION['flash_error'] = 'No se pudo actualizar al Servicio Especializado.';
                admin_servicios_especializados_redirect();
            }
            $stmt->close();

            if ($stmtNombre = $conn->prepare("UPDATE users SET name = ? WHERE id = ?")) {
                $stmtNombre->bind_param('si', $nombre, $empleadoId);
                $stmtNombre->execute();
                $stmtNombre->close();
            }

            $userRow = null;
            if ($stmtUser = $conn->prepare("SELECT id, email, password_visible, rol FROM users WHERE id = ? LIMIT 1")) {
                $stmtUser->bind_param('i', $empleadoId);
                if ($stmtUser->execute()) {
                    $resultUser = $stmtUser->get_result();
                    if ($resultUser) {
                        $userRow = $resultUser->fetch_assoc();
                    }
                }
                $stmtUser->close();
            }
            $hasUser = $userRow !== null;

            if ($hasUser) {
                if ($email !== '') {
                    if ($stmtEmail = $conn->prepare("UPDATE users SET email = ?, activo = 1 WHERE id = ?")) {
                        $stmtEmail->bind_param('si', $email, $empleadoId);
                        $stmtEmail->execute();
                        $stmtEmail->close();
                    }
                }

                if ($password !== '') {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    if ($stmtPwd = $conn->prepare("UPDATE users SET password = ?, password_visible = ?, activo = 1 WHERE id = ?")) {
                        $stmtPwd->bind_param('ssi', $passwordHash, $password, $empleadoId);
                        $stmtPwd->execute();
                        $stmtPwd->close();
                    }
                }
            } else {
                if ($password !== '' && $email === '') {
                    $_SESSION['flash_error'] = 'Asigna un correo antes de definir una contraseña.';
                    admin_servicios_especializados_redirect();
                }

                if ($email !== '') {
                    $plainPassword = $password !== '' ? $password : SERVICIO_ESPECIALIZADO_DEFAULT_PASSWORD;
                    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
                    if ($stmtInsert = $conn->prepare("INSERT INTO users (id, name, email, password, password_visible, rol, activo) VALUES (?, ?, ?, ?, ?, 'servicio_especializado', 1)")) {
                        $stmtInsert->bind_param('issss', $empleadoId, $nombre, $email, $passwordHash, $plainPassword);
                        $stmtInsert->execute();
                        $stmtInsert->close();
                    }
                }
            }

            $_SESSION['flash_success'] = 'Servicio Especializado actualizado correctamente.';
            admin_servicios_especializados_redirect();
            break;

        case 'baja':
        case 'alta':
            if ($empleadoId <= 0) {
                $_SESSION['flash_error'] = 'Servicio Especializado no válido.';
                admin_servicios_especializados_redirect();
            }

            $activo = $accion === 'alta' ? 1 : 0;

            if ($stmtEmpleado = $conn->prepare('UPDATE empleados SET activo = ? WHERE id = ?')) {
                $stmtEmpleado->bind_param('ii', $activo, $empleadoId);
                $stmtEmpleado->execute();
                $stmtEmpleado->close();
            }

            if ($accion === 'baja') {
                if ($stmtAsignaciones = $conn->prepare('UPDATE empleado_proyecto SET activo = 0 WHERE empleado_id = ? AND activo = 1')) {
                    $stmtAsignaciones->bind_param('i', $empleadoId);
                    $stmtAsignaciones->execute();
                    $stmtAsignaciones->close();
                }
            }

            if ($stmtUser = $conn->prepare("UPDATE users SET activo = ? WHERE id = ?")) {
                $stmtUser->bind_param('ii', $activo, $empleadoId);
                $stmtUser->execute();
                $stmtUser->close();
            }

            $_SESSION['flash_success'] = $accion === 'alta' ? 'Servicio Especializado reactivado.' : 'Servicio Especializado dado de baja.';
            admin_servicios_especializados_redirect();
            break;

        case 'eliminar':
            if ($empleadoId <= 0) {
                $_SESSION['flash_error'] = 'Servicio Especializado no válido.';
                admin_servicios_especializados_redirect();
            }

            if ($stmt = $conn->prepare('DELETE FROM empleado_proyecto WHERE empleado_id = ?')) {
                $stmt->bind_param('i', $empleadoId);
                $stmt->execute();
                $stmt->close();
            }

            if ($stmt = $conn->prepare('DELETE FROM empleados WHERE id = ?')) {
                $stmt->bind_param('i', $empleadoId);
                $stmt->execute();
                $stmt->close();
            }

            if ($stmt = $conn->prepare("DELETE FROM users WHERE id = ?")) {
                $stmt->bind_param('i', $empleadoId);
                $stmt->execute();
                $stmt->close();
            }

            $_SESSION['flash_success'] = 'Servicio Especializado eliminado permanentemente.';
            admin_servicios_especializados_redirect();
            break;

        default:
            admin_servicios_especializados_redirect();
            break;
    }
}

$totales = [
    'total' => 0,
    'activos' => 0,
    'inactivos' => 0,
    'bloqueados' => 0,
    'con_credencial' => 0,
    'sin_credencial' => 0,
];

$resultStats = $conn->query("SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) AS activos,
    SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) AS inactivos,
    SUM(CASE WHEN IFNULL(bloqueado, 0) = 1 THEN 1 ELSE 0 END) AS bloqueados
FROM empleados");
if ($resultStats) {
    $rowStats = $resultStats->fetch_assoc();
    if ($rowStats) {
        $totales['total'] = (int)($rowStats['total'] ?? 0);
        $totales['activos'] = (int)($rowStats['activos'] ?? 0);
        $totales['inactivos'] = (int)($rowStats['inactivos'] ?? 0);
        $totales['bloqueados'] = (int)($rowStats['bloqueados'] ?? 0);
    }
}

$sinAsignacion = 0;
$resultSin = $conn->query('SELECT COUNT(*) AS total FROM empleados e LEFT JOIN empleado_proyecto ep ON ep.empleado_id = e.id AND ep.activo = 1 WHERE ep.empleado_id IS NULL');
if ($resultSin) {
    $rowSin = $resultSin->fetch_assoc();
    if ($rowSin) {
        $sinAsignacion = (int)($rowSin['total'] ?? 0);
    }
}

$proyectos = [];
$resultProyectos = $conn->query('SELECT id, nombre FROM grupos ORDER BY nombre');
if ($resultProyectos) {
    while ($row = $resultProyectos->fetch_assoc()) {
        $proyectos[] = $row;
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS empleado_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT NOT NULL,
    tipo VARCHAR(50) NOT NULL,
    ruta_archivo VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) DEFAULT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_empleado_tipo (empleado_id, tipo),
    CONSTRAINT fk_empleado_documentos_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$empleados = [];
$puestosDisponibles = [];
$conteoProyectos = [];
$conteoPuestos = [];
$sql = "SELECT e.*, e.empresa AS empresa, g.nombre AS proyecto_nombre, g.empresa AS proyecto_empresa, ep.proyecto_id, u.email, u.password_visible,
    EXISTS(SELECT 1 FROM empleado_documentos d WHERE d.empleado_id = e.id AND d.tipo = 'alta_imss') AS tiene_alta_imss
    FROM empleados e
    LEFT JOIN empleado_proyecto ep ON ep.empleado_id = e.id AND ep.activo = 1
    LEFT JOIN grupos g ON g.id = ep.proyecto_id
    LEFT JOIN users u ON u.id = e.id
    ORDER BY e.nombre";
$resultEmpleados = $conn->query($sql);
if ($resultEmpleados) {
    while ($row = $resultEmpleados->fetch_assoc()) {
        $puesto = trim($row['puesto'] ?? '');
        if ($puesto === ''
            || strcasecmp($puesto, 'trabajador') === 0
            || strcasecmp($puesto, 'empleado') === 0
            || strcasecmp($puesto, 'subcontratista') === 0
            || strcasecmp($puesto, 'subcontratistas') === 0) {
            $puesto = 'Servicio Especializado';
        }
        $row['puesto'] = $puesto;
        $puestosDisponibles[$puesto] = true;
        $conteoPuestos[$puesto] = ($conteoPuestos[$puesto] ?? 0) + 1;

        if (!empty($row['email'])) {
            $totales['con_credencial']++;
        } else {
            $totales['sin_credencial']++;
        }

        $proyectoAsignado = isset($row['proyecto_id']) ? (int)$row['proyecto_id'] : 0;
        if ($proyectoAsignado > 0) {
            if (!isset($conteoProyectos[$proyectoAsignado])) {
                $conteoProyectos[$proyectoAsignado] = [
                    'nombre' => $row['proyecto_nombre'] ?? ('Proyecto #' . $proyectoAsignado),
                    'empresa' => $row['empresa'] ?? '',
                    'total' => 0,
                ];
            }
            $conteoProyectos[$proyectoAsignado]['total']++;
        }

        $empleados[] = $row;
    }
}

$puestosLista = array_keys($puestosDisponibles);
natcasesort($puestosLista);
$puestosLista = array_values($puestosLista);

uasort($conteoProyectos, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});
$topProyectos = array_slice($conteoProyectos, 0, 5);

arsort($conteoPuestos, SORT_NUMERIC);
$topPuestos = [];
if (!empty($conteoPuestos)) {
    foreach (array_slice($conteoPuestos, 0, 5, true) as $puestoNombre => $totalPuesto) {
        $topPuestos[] = [
            'nombre' => $puestoNombre,
            'total' => $totalPuesto,
        ];
    }
}

$porcentajeSinProyecto = $totales['total'] > 0 ? round(($sinAsignacion / max($totales['total'], 1)) * 100, 1) : 0;
$porcentajeSinCredencial = $totales['total'] > 0 ? round(($totales['sin_credencial'] / max($totales['total'], 1)) * 100, 1) : 0;

$empleadosFiltrados = array_filter($empleados, function (array $empleado) use ($estadoFiltro, $proyectoFiltro, $puestoFiltro, $credencialFiltro) {
    $proyectoId = isset($empleado['proyecto_id']) ? (int)$empleado['proyecto_id'] : 0;
    $activo = (int)($empleado['activo'] ?? 0);
    $bloqueado = (int)($empleado['bloqueado'] ?? 0);

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
        case 'bloqueados':
            if ($bloqueado !== 1) {
                return false;
            }
            break;
        case 'sin_proyecto':
            if ($proyectoId !== 0) {
                return false;
            }
            break;
    }

    if ($proyectoFiltro !== '' && (int)$proyectoFiltro !== $proyectoId) {
        return false;
    }

    if ($puestoFiltro !== '') {
        $puesto = trim($empleado['puesto'] ?? '');
        if (strcasecmp($puesto, $puestoFiltro) !== 0) {
            return false;
        }
    }

    if ($credencialFiltro === 'con' && empty($empleado['email'])) {
        return false;
    }

    if ($credencialFiltro === 'sin' && !empty($empleado['email'])) {
        return false;
    }

    return true;
});
$empleadosFiltrados = array_values($empleadosFiltrados);
$totalRegistros = count($empleadosFiltrados);

$activeFilters = [];
if ($estadoFiltro !== 'todos') {
    $estadoLabels = [
        'activos' => 'Solo activos',
        'inactivos' => 'Solo inactivos',
        'bloqueados' => 'Bloqueados',
        'sin_proyecto' => 'Sin proyecto asignado',
    ];
    if (isset($estadoLabels[$estadoFiltro])) {
        $activeFilters[] = $estadoLabels[$estadoFiltro];
    }
}

if ($proyectoFiltro !== '') {
    $nombreProyecto = 'Proyecto #' . (int)$proyectoFiltro;
    foreach ($proyectos as $proyecto) {
        if ((int)$proyecto['id'] === (int)$proyectoFiltro) {
            $nombreProyecto = $proyecto['nombre'];
            break;
        }
    }
    $activeFilters[] = 'Proyecto: ' . $nombreProyecto;
}

if ($puestoFiltro !== '') {
    $activeFilters[] = 'Puesto: ' . $puestoFiltro;
}

if ($credencialFiltro === 'con') {
    $activeFilters[] = 'Con credenciales activas';
} elseif ($credencialFiltro === 'sin') {
    $activeFilters[] = 'Sin credenciales';
}

$pageTitle = 'Gestión de Servicios Especializados - ErgoCuida';
$activePage = 'employees';
$pageHeading = 'Gestión de Servicios Especializados';
$pageDescription = 'Administra perfiles, asignaciones y estados del personal externalizado.';
$headerActions = [
    [
        'label' => 'Nuevo Servicio Especializado',
        'icon' => 'fa-user-plus',
        'href' => 'crear_empleado.php',
        'variant' => 'primary'
    ],
    [
        'label' => 'Procesar SUA Automático',
        'icon' => 'fa-shield-halved',
        'href' => 'procesar_sua_auto.php',
        'variant' => 'outline'
    ]
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
                <i class="fas fa-users"></i>
            </div>
        </div>
    <div class="stat-number"><?php echo $totales['total']; ?></div>
    <div class="stat-label">Servicios Especializados registrados</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon projects">
                <i class="fas fa-user-check"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $totales['activos']; ?></div>
        <div class="stat-label">Activos</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon attendance">
                <i class="fas fa-user-clock"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $sinAsignacion; ?></div>
        <div class="stat-label">Sin proyecto asignado</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon pms">
                <i class="fas fa-lock"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $totales['bloqueados']; ?></div>
        <div class="stat-label">Bloqueados por SUA</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon analytics">
                <i class="fas fa-id-card"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $totales['con_credencial']; ?></div>
        <div class="stat-label">Con credenciales (<?php echo $totales['total'] > 0 ? round(($totales['con_credencial'] / max($totales['total'], 1)) * 100) : 0; ?>%)</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon alert">
                <i class="fas fa-user-lock"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $totales['sin_credencial']; ?></div>
        <div class="stat-label">Sin acceso (<?php echo $porcentajeSinCredencial; ?>%)</div>
    </div>
</div>

<div class="section">
    <h3><i class="fas fa-lightbulb"></i> Insights de Servicios Especializados</h3>
    <div class="insights-grid">
        <div class="insight-card">
            <div class="insight-card__header">
                <h4>Proyectos con más personal</h4>
                <span class="metric-pill">Top <?php echo count($topProyectos); ?></span>
            </div>
            <?php if (!empty($topProyectos)): ?>
                <ul class="insight-list">
                    <?php foreach ($topProyectos as $proyecto): ?>
                        <li>
                            <div>
                                <strong><?php echo htmlspecialchars($proyecto['nombre']); ?></strong>
                                <span class="table-note">Colaboradores asignados</span>
                            </div>
                            <span class="metric-value"><?php echo (int)$proyecto['total']; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted">Aún no se registran asignaciones activas.</p>
            <?php endif; ?>
        </div>
        <div class="insight-card">
            <div class="insight-card__header">
                <h4>Puestos predominantes</h4>
                <span class="metric-pill">Top <?php echo count($topPuestos); ?></span>
            </div>
            <?php if (!empty($topPuestos)): ?>
                <ul class="insight-list">
                    <?php foreach ($topPuestos as $puesto): ?>
                        <li>
                            <div>
                                <strong><?php echo htmlspecialchars($puesto['nombre']); ?></strong>
                                <span class="table-note">Colaboradores</span>
                            </div>
                            <span class="metric-value"><?php echo (int)$puesto['total']; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted">Registra más Servicios Especializados para ver tendencias.</p>
            <?php endif; ?>
        </div>
        <div class="insight-card">
            <div class="insight-card__header">
                <h4>Alertas rápidas</h4>
                <span class="metric-pill"><i class="fas fa-bell"></i></span>
            </div>
            <ul class="insight-list insight-list--summary">
                <li>
                    <div>
                        <strong><?php echo $sinAsignacion; ?></strong>
                        <span class="table-note">Sin proyecto activo</span>
                    </div>
                    <span class="metric-value metric-value--warn"><?php echo $porcentajeSinProyecto; ?>%</span>
                </li>
                <li>
                    <div>
                        <strong><?php echo $totales['sin_credencial']; ?></strong>
                        <span class="table-note">Sin credenciales</span>
                    </div>
                    <span class="metric-value metric-value--warn"><?php echo $porcentajeSinCredencial; ?>%</span>
                </li>
                <li>
                    <div>
                        <strong><?php echo $totales['bloqueados']; ?></strong>
                        <span class="table-note">Bloqueados SUA</span>
                    </div>
                    <span class="metric-value"><?php echo $totales['bloqueados'] > 0 ? 'Revisar' : 'Ok'; ?></span>
                </li>
            </ul>
        </div>
    </div>
</div>

<div class="section">
    <h3><i class="fas fa-filter"></i> Filtros</h3>
    <form method="GET" id="filtrosServiciosEspecializados">
        <input type="hidden" name="estado" id="estadoFiltro" value="<?php echo htmlspecialchars($estadoFiltro); ?>">
        <div class="filters-grid">
            <div class="form-group">
                <label for="proyecto">Proyecto</label>
                <select id="proyecto" name="proyecto" class="form-control">
                    <option value="">Todos los proyectos</option>
                    <?php foreach ($proyectos as $proyecto): ?>
                        <option value="<?php echo $proyecto['id']; ?>" <?php echo ($proyectoFiltro !== '' && (int)$proyectoFiltro === (int)$proyecto['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($proyecto['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="puesto">Puesto</label>
                <select id="puesto" name="puesto" class="form-control">
                    <option value="">Todos los puestos</option>
                    <?php foreach ($puestosLista as $puesto): ?>
                        <option value="<?php echo htmlspecialchars($puesto); ?>" <?php echo $puestoFiltro === $puesto ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($puesto); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="credencial">Credenciales</label>
                <select id="credencial" name="credencial" class="form-control">
                    <option value="todos" <?php echo $credencialFiltro === 'todos' ? 'selected' : ''; ?>>Todas</option>
                    <option value="con" <?php echo $credencialFiltro === 'con' ? 'selected' : ''; ?>>Con correo/password</option>
                    <option value="sin" <?php echo $credencialFiltro === 'sin' ? 'selected' : ''; ?>>Sin credenciales</option>
                </select>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <div class="actions-bar__group">
                    <button type="submit" class="btn btn-primary btn-compact"><i class="fas fa-check"></i> Aplicar</button>
                    <a href="empleados.php" class="btn btn-secondary btn-compact"><i class="fas fa-rotate"></i> Limpiar</a>
                </div>
            </div>
        </div>
        <div class="actions-bar">
            <div class="quick-filters">
                <?php
                $estadosQuick = [
                    'todos' => 'Todos',
                    'activos' => 'Activos',
                    'inactivos' => 'Inactivos',
                    'bloqueados' => 'Bloqueados',
                    'sin_proyecto' => 'Sin proyecto',
                ];
                foreach ($estadosQuick as $key => $label): ?>
                    <button type="button" class="quick-filter<?php echo $estadoFiltro === $key ? ' is-active' : ''; ?>" data-estado="<?php echo $key; ?>">
                        <?php echo htmlspecialchars($label); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="actions-bar__group">
                <div class="search-input">
                    <i class="fas fa-search"></i>
                    <input type="search" id="busquedaServiciosEspecializados" placeholder="Buscar por nombre, proyecto o contacto" autocomplete="off">
                </div>
            </div>
        </div>
    </form>

    <?php if (!empty($activeFilters)): ?>
        <div class="filter-chips">
            <?php foreach ($activeFilters as $chip): ?>
                <span class="chip"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($chip); ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div id="resultadoTotal" class="result-count">Mostrando <strong><?php echo $totalRegistros; ?></strong> servicios especializados</div>
</div>

<div class="section">
    <h3><i class="fas fa-users"></i> Listado de Servicios Especializados</h3>
    <?php if ($totalRegistros === 0): ?>
        <div class="empty-state">
            <i class="fas fa-user-slash"></i>
            <h4>No se encontraron servicios especializados</h4>
            <p>Ajusta los filtros o registra un nuevo Servicio Especializado.</p>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="table" id="tablaServiciosEspecializados">
                <thead>
                    <tr>
                        <th>Servicio Especializado</th>
                        <th>Empresa</th>
                        <th>Contacto</th>
                        <th>Proyecto activo</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($empleadosFiltrados as $empleado): ?>
                        <?php
                            $proyectoId = isset($empleado['proyecto_id']) ? (int)$empleado['proyecto_id'] : 0;
                            $searchIndex = strtolower(
                                trim(
                                    implode(' ', array_filter([
                                        $empleado['nombre'] ?? '',
                                        $empleado['telefono'] ?? '',
                                        $empleado['puesto'] ?? '',
                                        $empleado['proyecto_nombre'] ?? '',
                                        $empleado['proyecto_empresa'] ?? '',
                                        $empleado['email'] ?? '',
                                    ]))
                                )
                            );
                            $estaActivo = (int)($empleado['activo'] ?? 0) === 1;
                            $estaBloqueado = (int)($empleado['bloqueado'] ?? 0) === 1;
                            $estadoLabel = $estaActivo ? 'status-activo' : 'status-inactivo';
                            $tieneAltaImss = !empty($empleado['tiene_alta_imss']);
                        ?>
                        <tr data-search="<?php echo htmlspecialchars($searchIndex); ?>">
                            <td data-label="Servicio Especializado">
                                <div class="table-entity">
                                    <strong><?php echo htmlspecialchars($empleado['nombre'] ?? ''); ?></strong>
                                </div>
                            </td>
                            <td data-label="Empresa">
                                <?php echo htmlspecialchars($empleado['empresa'] ?? ''); ?>
                            </td>
                            <td data-label="Contacto">
                                <?php if (!empty($empleado['telefono'])): ?>
                                    <div><?php echo htmlspecialchars($empleado['telefono']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($empleado['email'])): ?>
                                    <div class="table-note">Email: <?php echo htmlspecialchars($empleado['email']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($empleado['nss'])): ?>
                                    <div class="table-note">NSS: <?php echo htmlspecialchars($empleado['nss']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($empleado['curp'])): ?>
                                    <div class="table-note">CURP: <?php echo htmlspecialchars($empleado['curp']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Proyecto activo">
                                <?php if ($proyectoId > 0): ?>
                                    <div><strong><?php echo htmlspecialchars($empleado['proyecto_nombre'] ?? 'Proyecto'); ?></strong></div>
                                    <?php if (!empty($empleado['proyecto_empresa'])): ?>
                                        <div class="table-note"><?php echo htmlspecialchars($empleado['proyecto_empresa']); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status-badge status-sin-proyecto">Sin proyecto</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Estado">
                                <div class="status-stack">
                                    <span class="status-badge <?php echo $estadoLabel; ?>"><?php echo $estaActivo ? 'Activo' : 'Baja'; ?></span>
                                    <?php if ($estaBloqueado): ?>
                                        <span class="status-badge status-bloqueado">Bloqueado SUA</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Acciones" class="actions-cell">
                                <div class="row-actions">
                                    <?php if ($estaBloqueado): ?>
                    <button type="button"
                        class="btn btn-secondary btn-compact btn-disabled"
                        style="cursor:not-allowed; opacity:0.6;"
                                                title="Servicio Especializado bloqueado por SUA"
                                                disabled>
                                            <i class="fas fa-ban"></i> Bloqueado
                                        </button>
                                    <?php elseif (!$estaActivo): ?>
                    <button type="button"
                        class="btn btn-secondary btn-compact btn-disabled"
                        style="cursor:not-allowed; opacity:0.6;"
                                                title="Servicio Especializado dado de baja"
                                                disabled>
                                            <i class="fas fa-user-slash"></i> En baja
                                        </button>
                                    <?php endif; ?>
                    <button type="button"
                                            class="btn btn-primary btn-compact"
                                            data-open="modalEditar"
                                            data-id="<?php echo (int)$empleado['id']; ?>"
                                            data-nombre="<?php echo htmlspecialchars($empleado['nombre'] ?? '', ENT_QUOTES); ?>"
                                            data-telefono="<?php echo htmlspecialchars($empleado['telefono'] ?? '', ENT_QUOTES); ?>"
                                            data-email="<?php echo htmlspecialchars($empleado['email'] ?? '', ENT_QUOTES); ?>"
                                            data-puesto="<?php echo htmlspecialchars($empleado['puesto'] ?? '', ENT_QUOTES); ?>"
                                            data-nss="<?php echo htmlspecialchars($empleado['nss'] ?? '', ENT_QUOTES); ?>"
                                            data-curp="<?php echo htmlspecialchars($empleado['curp'] ?? '', ENT_QUOTES); ?>">
                                        <i class="fas fa-pen"></i> Editar
                                    </button>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('¿Seguro que deseas <?php echo $estaActivo ? 'dar de baja' : 'reactivar'; ?> a este Servicio Especializado?');">
                                        <input type="hidden" name="accion" value="<?php echo $estaActivo ? 'baja' : 'alta'; ?>">
                                        <input type="hidden" name="empleado_id" value="<?php echo (int)$empleado['id']; ?>">
                                        <button type="submit" class="btn <?php echo $estaActivo ? 'btn-warning' : 'btn-success'; ?> btn-compact">
                                            <i class="fas <?php echo $estaActivo ? 'fa-user-minus' : 'fa-user-check'; ?>"></i>
                                            <?php echo $estaActivo ? 'Dar de baja' : 'Reactivar'; ?>
                                        </button>
                                    </form>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Esta acción eliminará al Servicio Especializado de forma permanente. ¿Continuar?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="empleado_id" value="<?php echo (int)$empleado['id']; ?>">
                                        <?php if ($tieneAltaImss): ?>
                                            <a href="ver_alta_imss.php?empleado_id=<?php echo (int)$empleado['id']; ?>" class="btn btn-secondary btn-compact" target="_blank" rel="noopener" title="Ver alta del IMSS">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        <?php endif; ?>
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

<div id="modalEditar" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Editar Servicio Especializado</h3>
            <button type="button" class="close-btn" onclick="closeModal('modalEditar')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="empleado_id" id="editarServicioEspecializadoId">
            <div class="form-grid">
                <div class="form-group">
                    <label for="editarNombre">Nombre completo *</label>
                    <input type="text" id="editarNombre" name="nombre" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="editarEmail">Correo electrónico</label>
                    <input type="email" id="editarEmail" name="email" class="form-control" placeholder="correo@empresa.com">
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="editarTelefono">Teléfono</label>
                    <input type="tel" id="editarTelefono" name="telefono" class="form-control">
                </div>
                <div class="form-group">
                    <label for="editarPuesto">Puesto</label>
                    <select id="editarPuesto" name="puesto" class="form-control">
                        <?php foreach ($puestosOpciones as $valor => $label): ?>
                            <option value="<?= htmlspecialchars($valor) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="editarNss">NSS</label>
                    <input type="text" id="editarNss" name="nss" class="form-control" placeholder="12-34-56-7890-1">
                </div>
                <div class="form-group">
                    <label for="editarCurp">CURP</label>
                    <input type="text" id="editarCurp" name="curp" class="form-control" maxlength="18" placeholder="PEGJ850101HDFRZN01">
                </div>
            </div>
            <div class="form-group">
                <label for="editarPassword">Contraseña</label>
                <input type="password" id="editarPassword" name="password" class="form-control" placeholder="Dejar vacío para mantener la actual">
                <small class="text-muted">Se recomienda actualizar la contraseña solo cuando sea necesario.</small>
            </div>
            <div class="modal-actions modal-actions--right">
                <div class="modal-actions__group">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalEditar')">Cancelar</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Guardar cambios</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

window.addEventListener('click', (event) => {
    if (event.target.classList && event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
});

(function () {
    const filtrosForm = document.getElementById('filtrosServiciosEspecializados');
    const estadoInput = document.getElementById('estadoFiltro');
    const proyectoSelect = document.getElementById('proyecto');
    const puestoSelect = document.getElementById('puesto');
    const credencialSelect = document.getElementById('credencial');
    const quickButtons = document.querySelectorAll('[data-estado]');
    const searchInput = document.getElementById('busquedaServiciosEspecializados');
    const rows = Array.from(document.querySelectorAll('#tablaServiciosEspecializados tbody tr'));
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

    if (puestoSelect) {
        puestoSelect.addEventListener('change', () => filtrosForm.submit());
    }

    if (credencialSelect) {
        credencialSelect.addEventListener('change', () => filtrosForm.submit());
    }

    const updateCount = () => {
        if (!resultCount) return;
        const visibles = rows.filter((row) => row.style.display !== 'none').length;
        resultCount.innerHTML = `Mostrando <strong>${visibles}</strong> servicios especializados`;
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

    const editarButtons = document.querySelectorAll('[data-open="modalEditar"]');
    const editarCampos = {
    id: document.getElementById('editarServicioEspecializadoId'),
        nombre: document.getElementById('editarNombre'),
        email: document.getElementById('editarEmail'),
        telefono: document.getElementById('editarTelefono'),
        puesto: document.getElementById('editarPuesto'),
        nss: document.getElementById('editarNss'),
        curp: document.getElementById('editarCurp'),
        password: document.getElementById('editarPassword'),
    };
    editarButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (editarCampos.id) editarCampos.id.value = button.dataset.id || '';
            if (editarCampos.nombre) editarCampos.nombre.value = button.dataset.nombre || '';
            if (editarCampos.email) editarCampos.email.value = button.dataset.email || '';
            if (editarCampos.telefono) editarCampos.telefono.value = button.dataset.telefono || '';
            if (editarCampos.puesto) {
                const valorPuesto = button.dataset.puesto || '';
                const opciones = Array.from(editarCampos.puesto.options || []).map((opt) => opt.value);
                const valorValido = opciones.includes(valorPuesto) ? valorPuesto : '<?php echo htmlspecialchars($puestoPorDefecto) ?>';
                editarCampos.puesto.value = valorValido;
            }
            if (editarCampos.nss) editarCampos.nss.value = button.dataset.nss || '';
            if (editarCampos.curp) editarCampos.curp.value = button.dataset.curp || '';
            if (editarCampos.password) editarCampos.password.value = '';
            openModal('modalEditar');
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>