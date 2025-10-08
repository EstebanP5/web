<?php
require_once __DIR__ . '/includes/admin_init.php';

$mensaje_exito = $_SESSION['flash_success'] ?? '';
$mensaje_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$estadoFiltro = $_GET['estado'] ?? 'todos';
$busqueda = trim($_GET['q'] ?? '');

function admin_proyectos_redirect(): void
{
    $uri = $_SERVER['REQUEST_URI'] ?? 'proyectos.php';
    header('Location: ' . $uri);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $proyectoId = isset($_POST['proyecto_id']) ? (int)$_POST['proyecto_id'] : 0;

    if ($proyectoId <= 0) {
        $_SESSION['flash_error'] = 'Proyecto no válido.';
        admin_proyectos_redirect();
    }

    if ($accion === 'toggle') {
        $activo = isset($_POST['activo']) && (int)$_POST['activo'] === 1 ? 1 : 0;
        $stmt = $conn->prepare('UPDATE grupos SET activo = ? WHERE id = ?');
        if ($stmt && $stmt->bind_param('ii', $activo, $proyectoId) && $stmt->execute()) {
            $_SESSION['flash_success'] = $activo ? 'Proyecto activado correctamente.' : 'Proyecto desactivado correctamente.';
        } else {
            $_SESSION['flash_error'] = 'No se pudo actualizar el estado del proyecto.';
        }
        admin_proyectos_redirect();
    }

    if ($accion === 'eliminar') {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('DELETE FROM proyectos_pm WHERE proyecto_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $proyectoId);
                $stmt->execute();
            }

            $stmt = $conn->prepare('DELETE FROM empleado_proyecto WHERE proyecto_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $proyectoId);
                $stmt->execute();
            }

            $stmt = $conn->prepare('DELETE FROM asistencia WHERE proyecto_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $proyectoId);
                $stmt->execute();
            }

            $stmt = $conn->prepare('DELETE FROM grupos WHERE id = ?');
            if ($stmt && $stmt->bind_param('i', $proyectoId) && $stmt->execute()) {
                $conn->commit();
                $_SESSION['flash_success'] = 'Proyecto eliminado correctamente.';
            } else {
                throw new Exception('No se pudo eliminar el proyecto.');
            }
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['flash_error'] = 'Error al eliminar el proyecto. Revisa los registros.';
        }
        admin_proyectos_redirect();
    }
}

$proyectos = [];
$sql = "SELECT g.*, 
           (SELECT GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ')
              FROM proyectos_pm pm 
              JOIN users u ON u.id = pm.user_id 
             WHERE pm.proyecto_id = g.id AND pm.activo = 1) AS pm_nombres,
           (SELECT COUNT(*) FROM empleado_proyecto ep WHERE ep.proyecto_id = g.id AND ep.activo = 1) AS total_personal
        FROM grupos g
        ORDER BY g.nombre";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $proyectos[] = $row;
    }
}

$stats = [
    'total' => count($proyectos),
    'activos' => 0,
    'inactivos' => 0,
    'sin_pm' => 0,
    'personal' => 0,
];

foreach ($proyectos as $info) {
    if ((int)($info['activo'] ?? 0) === 1) {
        $stats['activos']++;
    } else {
        $stats['inactivos']++;
    }
    if (empty($info['pm_nombres'])) {
        $stats['sin_pm']++;
    }
    $stats['personal'] += (int)($info['total_personal'] ?? 0);
}

$proyectosFiltrados = array_filter($proyectos, function (array $proyecto) use ($estadoFiltro, $busqueda) {
    $estado = (int)($proyecto['activo'] ?? 0);
    $pmAsignados = trim((string)($proyecto['pm_nombres'] ?? '')) !== '';

    switch ($estadoFiltro) {
        case 'activos':
            if ($estado !== 1) {
                return false;
            }
            break;
        case 'inactivos':
            if ($estado !== 0) {
                return false;
            }
            break;
        case 'sin_pm':
            if ($pmAsignados) {
                return false;
            }
            break;
    }

    if ($busqueda !== '') {
        $needle = mb_strtolower($busqueda, 'UTF-8');
        $haystack = mb_strtolower(implode(' ', array_filter([
            $proyecto['nombre'] ?? '',
            $proyecto['empresa'] ?? '',
            $proyecto['localidad'] ?? '',
            $proyecto['pm_nombres'] ?? '',
            $proyecto['token'] ?? '',
        ])), 'UTF-8');
        if (strpos($haystack, $needle) === false) {
            return false;
        }
    }

    return true;
});

$proyectosFiltrados = array_values($proyectosFiltrados);

$hoy = new DateTimeImmutable('today');
$proyectosVigentes = [];
$proyectosAnteriores = [];

foreach ($proyectosFiltrados as $proyecto) {
    $esAnterior = false;
    $fechaFinRaw = $proyecto['fecha_fin'] ?? null;

    if (!empty($fechaFinRaw)) {
        try {
            $fechaFin = new DateTimeImmutable($fechaFinRaw);
            if ($fechaFin < $hoy) {
                $esAnterior = true;
            }
        } catch (Throwable $e) {
            $esAnterior = false;
        }
    }

    if ($esAnterior) {
        $proyecto['es_anterior'] = true;
        $proyectosAnteriores[] = $proyecto;
    } else {
        $proyecto['es_anterior'] = false;
        $proyectosVigentes[] = $proyecto;
    }
}

$totalRegistros = count($proyectosVigentes) + count($proyectosAnteriores);

$activeFilters = [];
if ($estadoFiltro !== 'todos') {
    $map = [
        'activos' => 'Solo activos',
        'inactivos' => 'Solo inactivos',
        'sin_pm' => 'Sin project manager',
    ];
    if (isset($map[$estadoFiltro])) {
        $activeFilters[] = $map[$estadoFiltro];
    }
}
if ($busqueda !== '') {
    $activeFilters[] = 'Búsqueda: "' . htmlspecialchars($busqueda) . '"';
}

$pageTitle = 'Gestión de Proyectos - Ergo PMS';
$activePage = 'projects';
$pageHeading = 'Gestión de Proyectos';
$pageDescription = 'Supervisa la cartera de proyectos, asignaciones y accesos de emergencia.';
$headerActions = [
    [
        'label' => 'Nuevo proyecto',
        'icon' => 'fa-circle-plus',
        'href' => 'crear_proyecto.php',
        'variant' => 'primary'
    ],
    [
        'label' => 'Ver Project Managers',
        'icon' => 'fa-user-tie',
        'href' => 'project_managers.php',
        'variant' => 'outline'
    ],
];
include __DIR__ . '/includes/header.php';
?>

<style>
.section-sub {
    background: #fff;
    border: 1px solid rgba(15, 23, 42, 0.08);
    border-radius: 18px;
    padding: 20px 24px 28px;
    margin-bottom: 26px;
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.04);
}
.section-sub.section-current {
    border-left: 4px solid #2563eb;
}
.section-sub.section-previous {
    border-left: 4px solid #cbd5f5;
    background: linear-gradient(135deg, rgba(226, 232, 240, 0.35), rgba(241, 245, 249, 0.6));
}
.section-sub__header {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 18px;
}
.section-sub__header h4 {
    margin: 0;
    font-size: 18px;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
}
.section-sub__header p {
    margin: 0;
    color: #64748b;
    font-size: 14px;
}
.table-container[data-table-scope="tablaProyectosAnteriores"] table {
    background: rgba(255, 255, 255, 0.96);
}
.table--sin-resultados tbody::before {
    content: 'Sin resultados con el filtro actual';
    display: block;
    text-align: center;
    padding: 16px;
    color: #94a3b8;
    font-size: 14px;
    font-weight: 500;
}
.row-previous {
    background: rgba(226, 232, 240, 0.45);
}
.row-previous:hover {
    background: rgba(226, 232, 240, 0.7);
}
.table-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(59, 130, 246, 0.08);
    color: #1d4ed8;
    margin-top: 8px;
}
.table-chip--previous {
    background: rgba(100, 116, 139, 0.12);
    color: #334155;
}
.status-badge.status-anterior {
    background: rgba(15, 23, 42, 0.08);
    color: #0f172a;
    border: 1px dashed rgba(15, 23, 42, 0.15);
}
.empty-state--compact {
    padding: 18px;
    border-radius: 16px;
    border: 1px dashed rgba(148, 163, 184, 0.45);
    background: rgba(241, 245, 249, 0.45);
    display: flex;
    align-items: center;
    gap: 12px;
}
.empty-state--compact i {
    color: #475569;
}
.result-count__detail {
    color: #64748b;
    font-size: 13px;
    margin-left: 6px;
}
@media (max-width: 768px) {
    .section-sub {
        padding: 18px 16px 24px;
    }
    .section-sub__header h4 {
        font-size: 16px;
    }
}
</style>

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
            <div class="stat-icon projects">
                <i class="fas fa-diagram-project"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['total']; ?></div>
        <div class="stat-label">Proyectos totales</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon employees">
                <i class="fas fa-toggle-on"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['activos']; ?></div>
        <div class="stat-label">Activos</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon attendance">
                <i class="fas fa-users"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['personal']; ?></div>
        <div class="stat-label">Personal asignado</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon pms">
                <i class="fas fa-user-clock"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['sin_pm']; ?></div>
        <div class="stat-label">Sin project manager</div>
    </div>
</div>

<div class="section">
    <h3><i class="fas fa-filter"></i> Filtros</h3>
    <form method="GET" id="filtrosProyectos">
        <input type="hidden" name="estado" id="estadoFiltro" value="<?php echo htmlspecialchars($estadoFiltro); ?>">
        <div class="filters-grid">
            <div class="form-group">
                <label for="busqueda">Buscar</label>
                <div class="search-input">
                    <i class="fas fa-search"></i>
                    <input type="search" id="busqueda" name="q" placeholder="Nombre, empresa, ubicación, PM o token" value="<?php echo htmlspecialchars($busqueda); ?>" autocomplete="off">
                </div>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <div class="actions-bar__group">
                    <button type="submit" class="btn btn-primary btn-compact"><i class="fas fa-check"></i> Aplicar</button>
                    <a href="proyectos.php" class="btn btn-secondary btn-compact"><i class="fas fa-rotate"></i> Limpiar</a>
                </div>
            </div>
        </div>
        <div class="actions-bar">
            <div class="quick-filters">
                <?php $quick = ['todos' => 'Todos', 'activos' => 'Activos', 'inactivos' => 'Inactivos', 'sin_pm' => 'Sin PM']; ?>
                <?php foreach ($quick as $key => $label): ?>
                    <button type="button" class="quick-filter<?php echo $estadoFiltro === $key ? ' is-active' : ''; ?>" data-estado="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></button>
                <?php endforeach; ?>
            </div>
            <div class="actions-bar__group">
                <span class="badge"><i class="fas fa-database"></i> <?php echo count($proyectos); ?> registrados</span>
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

    <div id="resultadoTotal" class="result-count">Mostrando <strong><?php echo $totalRegistros; ?></strong> proyectos <span class="result-count__detail">(<?php echo count($proyectosVigentes); ?> vigentes · <?php echo count($proyectosAnteriores); ?> anteriores)</span></div>
</div>

<div class="section">
    <h3><i class="fas fa-diagram-project"></i> Listado de proyectos</h3>
    <?php if ($totalRegistros === 0): ?>
        <div class="empty-state">
            <i class="fas fa-folder-open"></i>
            <h4>Sin proyectos</h4>
            <p>No encontramos proyectos con los filtros actuales.</p>
            <a href="crear_proyecto.php" class="btn btn-primary"><i class="fas fa-circle-plus"></i> Crear proyecto</a>
        </div>
    <?php else: ?>
        <?php
            $secciones = [
                [
                    'titulo' => 'Proyectos vigentes',
                    'descripcion' => 'Proyectos en curso o sin fecha de cierre.',
                    'items' => $proyectosVigentes,
                    'tableId' => 'tablaProyectosVigentes',
                    'wrapperClass' => 'section-current',
                    'emptyMessage' => 'No hay proyectos vigentes con los filtros actuales.',
                    'esAnterior' => false,
                ],
                [
                    'titulo' => 'Proyectos anteriores',
                    'descripcion' => 'Proyectos cuya fecha fin ya pasó. Se mantienen para consulta histórica.',
                    'items' => $proyectosAnteriores,
                    'tableId' => 'tablaProyectosAnteriores',
                    'wrapperClass' => 'section-previous',
                    'emptyMessage' => 'No hay proyectos anteriores con los filtros aplicados.',
                    'esAnterior' => true,
                ],
            ];
        ?>
        <?php foreach ($secciones as $seccion): ?>
            <div class="section-sub <?php echo htmlspecialchars($seccion['wrapperClass']); ?>" data-section="<?php echo htmlspecialchars($seccion['tableId']); ?>">
                <div class="section-sub__header">
                    <h4>
                        <?php if ($seccion['esAnterior']): ?>
                            <i class="fas fa-clock-rotate-left"></i>
                        <?php else: ?>
                            <i class="fas fa-star"></i>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($seccion['titulo']); ?>
                    </h4>
                    <p><?php echo htmlspecialchars($seccion['descripcion']); ?></p>
                </div>
                <?php if (empty($seccion['items'])): ?>
                    <div class="empty-state empty-state--compact">
                        <i class="fas fa-info-circle"></i>
                        <p><?php echo htmlspecialchars($seccion['emptyMessage']); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-container" data-table-scope="<?php echo htmlspecialchars($seccion['tableId']); ?>">
                        <table class="table" id="<?php echo htmlspecialchars($seccion['tableId']); ?>">
                            <thead>
                                <tr>
                                    <th>Proyecto</th>
                                    <th>Empresa</th>
                                    <th>Project manager</th>
                                    <th>Personal</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($seccion['items'] as $proyecto): ?>
                                    <?php
                                        $activo = (int)($proyecto['activo'] ?? 0) === 1;
                                        $pmAsignados = trim((string)($proyecto['pm_nombres'] ?? '')) !== '';
                                        $searchIndex = strtolower(implode(' ', array_filter([
                                            $proyecto['nombre'] ?? '',
                                            $proyecto['empresa'] ?? '',
                                            $proyecto['localidad'] ?? '',
                                            $proyecto['pm_nombres'] ?? '',
                                            $proyecto['token'] ?? '',
                                        ])));
                                        $esAnterior = !empty($proyecto['es_anterior']);
                                    ?>
                                    <tr data-search="<?php echo htmlspecialchars($searchIndex); ?>" class="<?php echo $esAnterior ? 'row-previous' : ''; ?>">
                                        <td>
                                            <div class="table-entity">
                                                <strong><?php echo htmlspecialchars($proyecto['nombre'] ?? 'Proyecto'); ?></strong>
                                                <?php if (!empty($proyecto['localidad'])): ?>
                                                    <span class="table-note"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($proyecto['localidad']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($proyecto['token'])): ?>
                                                    <span class="table-note">
                                                        <i class="fas fa-link"></i>
                                                        Token: <code class="token-pill"><?php echo htmlspecialchars($proyecto['token']); ?></code>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($proyecto['fecha_inicio']) || !empty($proyecto['fecha_fin'])): ?>
                                                    <span class="table-note">
                                                        <i class="fas fa-calendar"></i>
                                                        <?php echo !empty($proyecto['fecha_inicio']) ? date('d/m/Y', strtotime($proyecto['fecha_inicio'])) : 'Sin inicio'; ?>
                                                        <?php if (!empty($proyecto['fecha_fin'])): ?>
                                                            → <?php echo date('d/m/Y', strtotime($proyecto['fecha_fin'])); ?>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (($proyecto['lat'] ?? '') !== '' && ($proyecto['lng'] ?? '') !== ''): ?>
                                                    <span class="table-note"><i class="fas fa-crosshairs"></i> <?php echo number_format((float)$proyecto['lat'], 6); ?>, <?php echo number_format((float)$proyecto['lng'], 6); ?></span>
                                                <?php endif; ?>
                                                <?php if ($esAnterior && !empty($proyecto['fecha_fin'])): ?>
                                                    <span class="table-chip table-chip--previous"><i class="fas fa-flag-checkered"></i> Cerró el <?php echo date('d/m/Y', strtotime($proyecto['fecha_fin'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($proyecto['empresa'])): ?>
                                                <strong><?php echo htmlspecialchars($proyecto['empresa']); ?></strong>
                                            <?php else: ?>
                                                <span class="table-note">Sin especificar</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($pmAsignados): ?>
                                                <span class="table-note"><?php echo htmlspecialchars($proyecto['pm_nombres']); ?></span>
                                            <?php else: ?>
                                                <span class="status-badge status-sin-pm">Sin asignar</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo (int)($proyecto['total_personal'] ?? 0); ?></strong>
                                            <span class="table-note">Colaboradores</span>
                                        </td>
                                        <td>
                                            <div class="status-stack">
                                                <span class="status-badge <?php echo $activo ? 'status-activo' : 'status-inactivo'; ?>"><?php echo $activo ? 'Activo' : 'Inactivo'; ?></span>
                                                <?php if ($esAnterior): ?>
                                                    <span class="status-badge status-anterior">Anterior</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="row-actions">
                                                <a href="editar_grupo.php?id=<?php echo (int)$proyecto['id']; ?>" class="btn btn-secondary btn-compact"><i class="fas fa-pen"></i> Editar</a>
                                                <a href="proyecto_empleados.php?id=<?php echo (int)$proyecto['id']; ?>" class="btn btn-primary btn-compact"><i class="fas fa-users"></i> Personal</a>
                                                <a href="../public/emergency.php?token=<?php echo urlencode((string)($proyecto['token'] ?? '')); ?>" target="_blank" class="btn btn-warning btn-compact"><i class="fas fa-life-ring"></i> Emergencia</a>
                                                <form class="inline-form" method="POST" onsubmit="return confirm('<?php echo $activo ? '¿Deseas desactivar este proyecto?' : '¿Deseas activar este proyecto?'; ?>');">
                                                    <input type="hidden" name="accion" value="toggle">
                                                    <input type="hidden" name="proyecto_id" value="<?php echo (int)$proyecto['id']; ?>">
                                                    <input type="hidden" name="activo" value="<?php echo $activo ? 0 : 1; ?>">
                                                    <button type="submit" class="btn <?php echo $activo ? 'btn-warning' : 'btn-success'; ?> btn-compact">
                                                        <i class="fas <?php echo $activo ? 'fa-pause' : 'fa-play'; ?>"></i>
                                                        <?php echo $activo ? 'Desactivar' : 'Activar'; ?>
                                                    </button>
                                                </form>
                                                <form class="inline-form" method="POST" onsubmit="return confirm('Esta acción eliminará el proyecto y toda su información relacionada. ¿Continuar?');">
                                                    <input type="hidden" name="accion" value="eliminar">
                                                    <input type="hidden" name="proyecto_id" value="<?php echo (int)$proyecto['id']; ?>">
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
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    const filtrosForm = document.getElementById('filtrosProyectos');
    const estadoInput = document.getElementById('estadoFiltro');
    const quickButtons = document.querySelectorAll('[data-estado]');
    const searchInput = document.getElementById('busqueda');
    const resultCount = document.getElementById('resultadoTotal');
    const tablaIds = ['tablaProyectosVigentes', 'tablaProyectosAnteriores'];
    const tablas = tablaIds
        .map((id) => document.getElementById(id))
        .filter((table) => table !== null);
    const rows = tablas.flatMap((table) => Array.from(table.querySelectorAll('tbody tr')));

    quickButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (estadoInput) {
                estadoInput.value = button.getAttribute('data-estado');
                filtrosForm.submit();
            }
        });
    });

    const updateCount = () => {
        if (!resultCount) return;
        const visibles = rows.filter((row) => row.style.display !== 'none').length;
        const detalles = tablaIds.map((id) => {
            const tabla = document.getElementById(id);
            if (!tabla) {
                return 0;
            }
            const visiblesTabla = Array.from(tabla.querySelectorAll('tbody tr'))
                .filter((row) => row.style.display !== 'none').length;
            return visiblesTabla;
        });
        const [vigentesVisibles, anterioresVisibles] = [detalles[0] ?? 0, detalles[1] ?? 0];
        resultCount.innerHTML = `Mostrando <strong>${visibles}</strong> proyectos <span class="result-count__detail">(${vigentesVisibles} vigentes · ${anterioresVisibles} anteriores)</span>`;
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
            tablas.forEach((table) => {
                const hayVisibles = Array.from(table.querySelectorAll('tbody tr')).some((row) => row.style.display !== 'none');
                table.classList.toggle('table--sin-resultados', !hayVisibles);
            });
            updateCount();
        });
    }

    updateCount();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
