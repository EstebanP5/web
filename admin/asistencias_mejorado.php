<?php
require_once __DIR__ . '/includes/admin_init.php';

// Filtros con valores por defecto
$proyecto_filtro = $_GET['proyecto'] ?? '';
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d', strtotime('-7 days'));
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$servicio_especializado_filtro = $_GET['servicio_especializado'] ?? '';
$solo_sin_salida = $_GET['solo_sin_salida'] ?? '';

$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('-6 days'));
$monthStart = date('Y-m-01');

// Obtener proyectos y Servicios Especializados para filtros
$proyectos = $conn->query('SELECT id, nombre FROM grupos WHERE activo = 1 ORDER BY nombre')->fetch_all(MYSQLI_ASSOC);
$servicios_especializados = $conn->query('SELECT id, nombre FROM empleados WHERE activo = 1 ORDER BY nombre')->fetch_all(MYSQLI_ASSOC);

// Construir query con filtros
$where_conditions = ['a.fecha BETWEEN ? AND ?'];
$params = [$fecha_inicio, $fecha_fin];
$param_types = 'ss';

if ($proyecto_filtro) {
    $where_conditions[] = 'a.proyecto_id = ?';
    $params[] = $proyecto_filtro;
    $param_types .= 'i';
}

if ($servicio_especializado_filtro) {
    $where_conditions[] = 'a.empleado_id = ?';
    $params[] = $servicio_especializado_filtro;
    $param_types .= 'i';
}

if ($solo_sin_salida === '1') {
    $where_conditions[] = '(a.hora_entrada IS NOT NULL AND a.hora_salida IS NULL)';
}

$where_clause = implode(' AND ', $where_conditions);

$asistencias_query = "
    SELECT 
        a.*,
    e.nombre as servicio_especializado_nombre,
        g.nombre as proyecto_nombre,
        g.empresa,
        g.localidad,
        CASE 
            WHEN a.hora_entrada IS NOT NULL AND a.hora_salida IS NOT NULL 
            THEN TIMEDIFF(a.hora_salida, a.hora_entrada)
            ELSE NULL
        END as horas_trabajadas,
        (SELECT COUNT(*) FROM descansos d 
            WHERE d.empleado_id=a.empleado_id AND d.proyecto_id=a.proyecto_id AND d.fecha=a.fecha) as descansos_count,
        (SELECT SEC_TO_TIME(SUM(TIMESTAMPDIFF(SECOND, d.inicio, COALESCE(d.fin, NOW())))) FROM descansos d 
            WHERE d.empleado_id=a.empleado_id AND d.proyecto_id=a.proyecto_id AND d.fecha=a.fecha) as descansos_duracion,
        (SELECT GROUP_CONCAT(CONCAT(
                DATE_FORMAT(d.inicio,'%H:%i'),' - ',
                IF(d.fin IS NULL,'Pendiente',DATE_FORMAT(d.fin,'%H:%i')),
                IF(d.motivo IS NULL OR d.motivo='','',CONCAT(' · ',d.motivo))
            ) ORDER BY d.inicio SEPARATOR ' | ')
            FROM descansos d 
            WHERE d.empleado_id=a.empleado_id AND d.proyecto_id=a.proyecto_id AND d.fecha=a.fecha
        ) as descansos_detalle
    FROM asistencia a
    INNER JOIN empleados e ON a.empleado_id = e.id
    INNER JOIN grupos g ON a.proyecto_id = g.id
    WHERE $where_clause
    ORDER BY a.fecha DESC, a.hora_entrada DESC
";

$stmt = $conn->prepare($asistencias_query);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$asistencias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Normalizar detalle de descansos para consumo en la vista
foreach ($asistencias as &$registro) {
    $registro['descansos_parsed'] = [];
    if (!empty($registro['descansos_detalle'])) {
        $segmentos = array_filter(array_map('trim', explode('|', $registro['descansos_detalle'])));
        foreach ($segmentos as $segmento) {
            $partes = array_map('trim', explode('·', $segmento));
            $franja = $partes[0] ?? '';
            $motivo = $partes[1] ?? '';
            $inicio = $franja;
            $fin = '';
            if (strpos($franja, '-') !== false) {
                [$inicio, $fin] = array_map('trim', explode('-', $franja, 2));
            }
            $estado = 'cerrado';
            if (strcasecmp($fin, 'Pendiente') === 0 || $fin === '') {
                $estado = 'abierto';
                $fin = '';
            }
            $registro['descansos_parsed'][] = [
                'inicio' => $inicio,
                'fin' => $fin,
                'motivo' => $motivo,
                'estado' => $estado
            ];
        }
    }
}
unset($registro);

$stats_query = "
    SELECT 
        COUNT(*) as total_registros,
        COUNT(CASE WHEN hora_entrada IS NOT NULL AND hora_salida IS NOT NULL THEN 1 END) as jornadas_completas,
        COUNT(CASE WHEN hora_entrada IS NOT NULL AND hora_salida IS NULL THEN 1 END) as sin_salida,
    COUNT(DISTINCT empleado_id) as servicios_especializados_unicos,
        COUNT(DISTINCT proyecto_id) as proyectos_activos
    FROM asistencia a
    WHERE $where_clause
";

$stmt_stats = $conn->prepare($stats_query);
if (!empty($params)) {
    $stmt_stats->bind_param($param_types, ...$params);
}
$stmt_stats->execute();
$stats = $stmt_stats->get_result()->fetch_assoc();

// Procesar exportación a Excel
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="asistencias_' . date('Y-m-d') . '.xls"');
    echo "<table border='1'>";
    echo '<tr><th>Fecha</th><th>Servicio Especializado</th><th>Proyecto</th><th>Empresa</th><th>Entrada</th><th>Salida</th><th>Horas Trabajadas</th><th>Descansos</th><th>Tiempo Descanso</th><th>Ubicación Entrada</th><th>Ubicación Salida</th></tr>';
    foreach ($asistencias as $asistencia) {
        echo '<tr>';
        echo '<td>' . date('d/m/Y', strtotime($asistencia['fecha'])) . '</td>';
    echo '<td>' . htmlspecialchars($asistencia['servicio_especializado_nombre']) . '</td>';
        echo '<td>' . htmlspecialchars($asistencia['proyecto_nombre']) . '</td>';
        echo '<td>' . htmlspecialchars($asistencia['empresa']) . '</td>';
        echo '<td>' . ($asistencia['hora_entrada'] ? date('H:i', strtotime($asistencia['hora_entrada'])) : '-') . '</td>';
        echo '<td>' . ($asistencia['hora_salida'] ? date('H:i', strtotime($asistencia['hora_salida'])) : '-') . '</td>';
        echo '<td>' . ($asistencia['horas_trabajadas'] ?: '-') . '</td>';
        echo '<td>' . ($asistencia['descansos_count'] ?? 0) . '</td>';
        echo '<td>' . ($asistencia['descansos_duracion'] ?: '-') . '</td>';
        echo '<td>' . ($asistencia['lat_entrada'] ? $asistencia['lat_entrada'] . ', ' . $asistencia['lon_entrada'] : '-') . '</td>';
        echo '<td>' . ($asistencia['lat_salida'] ? $asistencia['lat_salida'] . ', ' . $asistencia['lon_salida'] : '-') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

$exportUrl = '?' . http_build_query(array_merge($_GET, ['export' => 'excel']));

// Filtros activos para chips
$activeFilters = [];
try {
    $fechaInicioObj = new DateTime($fecha_inicio);
    $fechaFinObj = new DateTime($fecha_fin);
    $activeFilters[] = 'Rango: ' . $fechaInicioObj->format('d/m/Y') . ' – ' . $fechaFinObj->format('d/m/Y');
} catch (Exception $e) {
    // Ignorar formato inválido
}

if ($proyecto_filtro) {
    foreach ($proyectos as $proyecto) {
        if ((string)$proyecto['id'] === (string)$proyecto_filtro) {
            $activeFilters[] = 'Proyecto: ' . $proyecto['nombre'];
            break;
        }
    }
}

if ($servicio_especializado_filtro) {
    foreach ($servicios_especializados as $servicio_especializado) {
        if ((string)$servicio_especializado['id'] === (string)$servicio_especializado_filtro) {
            $activeFilters[] = 'Servicio Especializado: ' . $servicio_especializado['nombre'];
            break;
        }
    }
}

if ($solo_sin_salida === '1') {
    $activeFilters[] = 'Solo registros sin salida';
}

$hasResults = !empty($asistencias);
$quickToday = ($fecha_inicio === $today && $fecha_fin === $today);
$quickWeek = ($fecha_inicio === $weekStart && $fecha_fin === $today);
$quickMonth = ($fecha_inicio === $monthStart && $fecha_fin === $today);
$soloSinSalidaActivo = ($solo_sin_salida === '1');

$pageTitle = 'Gestión de Asistencias - Ergo PMS';
$activePage = 'attendance';
$pageHeading = 'Gestión de Asistencias';
$pageDescription = 'Monitorea asistencia, descansos y ubicaciones con filtros inteligentes.';
$headerActions = [];
if ($hasResults) {
    $headerActions[] = [
        'label' => 'Exportar Excel',
        'icon' => 'fa-file-excel',
        'href' => $exportUrl,
        'variant' => 'primary'
    ];
}
include __DIR__ . '/includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon projects">
                <i class="fas fa-database"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['total_registros']; ?></div>
        <div class="stat-label">Total registros</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon employees">
                <i class="fas fa-user-check"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['jornadas_completas']; ?></div>
        <div class="stat-label">Jornadas completas</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon attendance">
                <i class="fas fa-user-clock"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['sin_salida']; ?></div>
        <div class="stat-label">Sin registro de salida</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon employees">
                <i class="fas fa-users"></i>
            </div>
        </div>
    <div class="stat-number"><?php echo $stats['servicios_especializados_unicos']; ?></div>
    <div class="stat-label">Servicios Especializados únicos</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon pms">
                <i class="fas fa-diagram-project"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['proyectos_activos']; ?></div>
        <div class="stat-label">Proyectos activos</div>
    </div>
</div>

<div class="section">
    <h3><i class="fas fa-filter"></i> Filtros inteligentes</h3>
    <div class="quick-filters">
        <button type="button" class="quick-filter<?php echo $quickToday ? ' is-active' : ''; ?>" data-range="today">Hoy</button>
        <button type="button" class="quick-filter<?php echo $quickWeek ? ' is-active' : ''; ?>" data-range="week">Últimos 7 días</button>
        <button type="button" class="quick-filter<?php echo $quickMonth ? ' is-active' : ''; ?>" data-range="month">Desde inicio de mes</button>
        <button type="button" class="quick-filter<?php echo $soloSinSalidaActivo ? ' is-active' : ''; ?>" data-filter="sin_salida">Solo sin salida</button>
    </div>
    <form method="GET" id="filtroAsistencias">
        <div class="filters-grid">
            <div class="form-group">
                <label for="fecha_inicio">Fecha inicio</label>
                <input type="date" id="fecha_inicio" name="fecha_inicio" class="form-control" value="<?php echo htmlspecialchars($fecha_inicio); ?>">
            </div>
            <div class="form-group">
                <label for="fecha_fin">Fecha fin</label>
                <input type="date" id="fecha_fin" name="fecha_fin" class="form-control" value="<?php echo htmlspecialchars($fecha_fin); ?>">
            </div>
            <div class="form-group">
                <label for="proyecto">Proyecto</label>
                <select id="proyecto" name="proyecto" class="form-control">
                    <option value="">Todos los proyectos</option>
                    <?php foreach ($proyectos as $proyecto): ?>
                        <option value="<?php echo $proyecto['id']; ?>" <?php echo ($proyecto_filtro == $proyecto['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($proyecto['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="servicio_especializado">Servicio Especializado</label>
                <select id="servicio_especializado" name="servicio_especializado" class="form-control">
                    <option value="">Todos los Servicios Especializados</option>
                    <?php foreach ($servicios_especializados as $servicio_especializado): ?>
                        <option value="<?php echo $servicio_especializado['id']; ?>" <?php echo ($servicio_especializado_filtro == $servicio_especializado['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($servicio_especializado['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <input type="hidden" id="solo_sin_salida" name="solo_sin_salida" value="<?php echo htmlspecialchars($solo_sin_salida); ?>">
        <div class="actions-bar">
            <div class="search-input">
                <i class="fas fa-search"></i>
                <input type="search" id="buscadorAsistencias" placeholder="Buscar por Servicio Especializado, proyecto o empresa" autocomplete="off">
            </div>
            <div class="actions-bar__group">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Aplicar filtros</button>
                <a href="asistencias_mejorado.php" class="btn btn-secondary"><i class="fas fa-circle-xmark"></i> Limpiar</a>
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
</div>

<div class="section">
    <h3><i class="fas fa-list"></i> Registros de asistencia</h3>
    <?php if (!$hasResults): ?>
        <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <h4>No se encontraron registros</h4>
            <p>No hay asistencias que coincidan con los filtros seleccionados.</p>
        </div>
    <?php else: ?>
        <div class="table-meta">
            <span class="badge"><i class="fas fa-database"></i> <?php echo count($asistencias); ?> registros</span>
            <?php if ($soloSinSalidaActivo): ?>
                <span class="badge"><i class="fas fa-triangle-exclamation"></i> Filtrando sin salida</span>
            <?php endif; ?>
        </div>
        <div class="table-container">
            <table class="table" id="tablaAsistencias">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Servicio Especializado</th>
                        <th>Proyecto</th>
                        <th>Empresa</th>
                        <th>Entrada</th>
                        <th>Salida</th>
                        <th>Horas</th>
                        <th>Descansos</th>
                        <th>Tiempo Descanso</th>
                        <th>Detalle Descansos</th>
                        <th>Estado</th>
                        <th>Ubicación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($asistencias as $asistencia): ?>
                        <?php
                            $searchIndex = strtolower(
                                ($asistencia['servicio_especializado_nombre'] ?? '') . ' ' .
                                ($asistencia['proyecto_nombre'] ?? '') . ' ' .
                                ($asistencia['empresa'] ?? '')
                            );
                        ?>
                        <tr data-search="<?php echo htmlspecialchars($searchIndex); ?>">
                            <td>
                                <strong><?php echo date('d/m/Y', strtotime($asistencia['fecha'])); ?></strong><br>
                                <small><?php echo date('D', strtotime($asistencia['fecha'])); ?></small>
                            </td>
                            <td><strong><?php echo htmlspecialchars($asistencia['servicio_especializado_nombre']); ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($asistencia['proyecto_nombre']); ?></strong><br>
                                <small><?php echo htmlspecialchars($asistencia['localidad']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($asistencia['empresa']); ?></td>
                            <td>
                                <?php if ($asistencia['hora_entrada']): ?>
                                    <span class="time-display time-entrada"><?php echo date('H:i', strtotime($asistencia['hora_entrada'])); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($asistencia['hora_salida']): ?>
                                    <span class="time-display time-salida"><?php echo date('H:i', strtotime($asistencia['hora_salida'])); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($asistencia['horas_trabajadas']): ?>
                                    <span class="time-display"><?php echo $asistencia['horas_trabajadas']; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="time-display" style="color:#f59e0b;font-weight:600;">
                                    <?php echo (int)($asistencia['descansos_count'] ?? 0); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($asistencia['descansos_duracion'])): ?>
                                    <span class="time-display" style="color:#f59e0b;">
                                        <?php echo htmlspecialchars($asistencia['descansos_duracion']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width:260px;white-space:normal;">
                                <?php if (!empty($asistencia['descansos_parsed'])): ?>
                                    <div style="display:flex;flex-direction:column;gap:6px;">
                                        <?php foreach ($asistencia['descansos_parsed'] as $descanso): ?>
                                            <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;padding:6px 8px;font-size:12px;color:#92400e;display:flex;flex-direction:column;gap:4px;">
                                                <div style="display:flex;align-items:center;gap:6px;">
                                                    <span style="font-weight:600;">
                                                        <?php echo htmlspecialchars($descanso['inicio']); ?>
                                                        <?php if ($descanso['fin'] !== ''): ?>
                                                            – <?php echo htmlspecialchars($descanso['fin']); ?>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php if ($descanso['estado'] === 'abierto'): ?>
                                                        <span style="background:#f97316;color:#fff;padding:2px 6px;border-radius:999px;font-size:11px;">Activo</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($descanso['motivo'])): ?>
                                                    <span><?php echo htmlspecialchars($descanso['motivo']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($asistencia['hora_entrada'] && $asistencia['hora_salida']): ?>
                                    <span class="status-badge status-completa">Completa</span>
                                <?php elseif ($asistencia['hora_entrada']): ?>
                                    <span class="status-badge status-sin-salida">Sin salida</span>
                                <?php else: ?>
                                    <span class="status-badge status-incompleta">Incompleta</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($asistencia['lat_entrada'] && $asistencia['lon_entrada']): ?>
                                    <a href="https://maps.google.com/?q=<?php echo $asistencia['lat_entrada']; ?>,<?php echo $asistencia['lon_entrada']; ?>" target="_blank" class="location-link">
                                        <i class="fas fa-map-marker-alt"></i> Entrada
                                    </a>
                                <?php endif; ?>
                                <?php if ($asistencia['lat_salida'] && $asistencia['lon_salida']): ?>
                                    <br>
                                    <a href="https://maps.google.com/?q=<?php echo $asistencia['lat_salida']; ?>,<?php echo $asistencia['lon_salida']; ?>" target="_blank" class="location-link">
                                        <i class="fas fa-map-marker-alt"></i> Salida
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
(function() {
    const form = document.getElementById('filtroAsistencias');
    const startInput = document.getElementById('fecha_inicio');
    const endInput = document.getElementById('fecha_fin');
    const soloSinSalidaInput = document.getElementById('solo_sin_salida');
    const quickButtons = document.querySelectorAll('.quick-filter');
    const busquedaInput = document.getElementById('buscadorAsistencias');
    const rows = Array.from(document.querySelectorAll('#tablaAsistencias tbody tr'));

    function setRange(range) {
        const today = new Date();
        const toISO = (d) => d.toISOString().slice(0, 10);
        if (range === 'today') {
            const fecha = toISO(today);
            startInput.value = fecha;
            endInput.value = fecha;
        }
        if (range === 'week') {
            const past = new Date(today);
            past.setDate(today.getDate() - 6);
            startInput.value = toISO(past);
            endInput.value = toISO(today);
        }
        if (range === 'month') {
            const inicio = new Date(today.getFullYear(), today.getMonth(), 1);
            startInput.value = toISO(inicio);
            endInput.value = toISO(today);
        }
    }

    quickButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const range = btn.getAttribute('data-range');
            const filter = btn.getAttribute('data-filter');
            if (range) {
                setRange(range);
                soloSinSalidaInput.value = '';
                form.submit();
            } else if (filter === 'sin_salida') {
                const isActive = soloSinSalidaInput.value === '1';
                soloSinSalidaInput.value = isActive ? '' : '1';
                form.submit();
            }
        });
    });

    if (busquedaInput) {
        busquedaInput.addEventListener('input', () => {
            const term = busquedaInput.value.trim().toLowerCase();
            rows.forEach(row => {
                const fuente = row.dataset.search || row.textContent.toLowerCase();
                const hayCoincidencia = fuente.includes(term);
                row.style.display = hayCoincidencia ? '' : 'none';
            });
        });
    }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>