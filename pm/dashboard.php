<?php
session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'pm') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';

$pmId = (int)$_SESSION['user_id'];
$userName = trim($_SESSION['user_name'] ?? '');
$firstName = $userName ? explode(' ', $userName)[0] : 'PM';

$stats = [
    'proyectos_total' => 0,
    'servicios_especializados_total' => 0,
    'asistencias_hoy' => 0,
    'jornadas_abiertas' => 0,
    'proyectos_sin_asistencia' => 0,
];

$proyectos = [];
$alerts = [];
$activityFeed = [];

// Obtener proyectos asignados al PM
$projectIds = [];
$stProyectos = $conn->prepare("SELECT g.id, g.nombre, g.empresa, g.fecha_inicio, g.fecha_fin, g.localidad, g.lat, g.lng, g.token
    FROM proyectos_pm ppm
    JOIN grupos g ON ppm.proyecto_id = g.id
    WHERE ppm.user_id = ? AND g.activo = 1
    ORDER BY g.nombre");
if ($stProyectos) {
    $stProyectos->bind_param('i', $pmId);
    $stProyectos->execute();
    $rsProyectos = $stProyectos->get_result();
    while ($proyecto = $rsProyectos->fetch_assoc()) {
    $proyecto['metric_servicios_especializados'] = 0;
        $proyecto['metric_asistencias_hoy'] = 0;
        $proyecto['metric_jornadas_abiertas'] = 0;
        $proyecto['metric_ultima_asistencia'] = null;
        $proyectos[] = $proyecto;
        $projectIds[] = (int)$proyecto['id'];
    }
    $stProyectos->close();
}

$stats['proyectos_total'] = count($projectIds);

if (!empty($projectIds)) {
    $inList = implode(',', array_map('intval', $projectIds));

    // Servicios Especializados por proyecto
    $serviciosEspecializadosProyecto = [];
    $sqlServiciosEspecializadosProyecto = "SELECT ep.proyecto_id, COUNT(DISTINCT ep.empleado_id) servicios_especializados
        FROM empleado_proyecto ep
        JOIN empleados e ON e.id = ep.empleado_id AND e.activo = 1
        WHERE ep.proyecto_id IN ($inList) AND ep.activo = 1
        GROUP BY ep.proyecto_id";
    if ($res = $conn->query($sqlServiciosEspecializadosProyecto)) {
        while ($row = $res->fetch_assoc()) {
            $serviciosEspecializadosProyecto[(int)$row['proyecto_id']] = (int)$row['servicios_especializados'];
        }
        $res->free();
    }

    // Total de Servicios Especializados únicos
    $sqlServiciosEspecializadosTotal = "SELECT COUNT(DISTINCT ep.empleado_id) total
        FROM empleado_proyecto ep
        JOIN empleados e ON e.id = ep.empleado_id AND e.activo = 1
        WHERE ep.proyecto_id IN ($inList) AND ep.activo = 1";
    if ($res = $conn->query($sqlServiciosEspecializadosTotal)) {
        $stats['servicios_especializados_total'] = (int)($res->fetch_assoc()['total'] ?? 0);
        $res->free();
    }

    // Asistencias de hoy por proyecto
    $asistenciasHoyProyecto = [];
    $sqlAsistenciasHoy = "SELECT a.proyecto_id, COUNT(*) total
        FROM asistencia a
        WHERE a.proyecto_id IN ($inList) AND a.fecha = CURDATE()
        GROUP BY a.proyecto_id";
    if ($res = $conn->query($sqlAsistenciasHoy)) {
        while ($row = $res->fetch_assoc()) {
            $pid = (int)$row['proyecto_id'];
            $total = (int)$row['total'];
            $asistenciasHoyProyecto[$pid] = $total;
            $stats['asistencias_hoy'] += $total;
        }
        $res->free();
    }

    // Jornadas abiertas por proyecto
    $jornadasAbiertasProyecto = [];
    $sqlJornadasAbiertas = "SELECT a.proyecto_id, COUNT(*) total
        FROM asistencia a
        WHERE a.proyecto_id IN ($inList)
          AND a.fecha = CURDATE()
          AND a.hora_entrada IS NOT NULL
          AND a.hora_salida IS NULL
        GROUP BY a.proyecto_id";
    if ($res = $conn->query($sqlJornadasAbiertas)) {
        while ($row = $res->fetch_assoc()) {
            $pid = (int)$row['proyecto_id'];
            $total = (int)$row['total'];
            $jornadasAbiertasProyecto[$pid] = $total;
            $stats['jornadas_abiertas'] += $total;
        }
        $res->free();
    }

    // Última asistencia registrada por proyecto
    $ultimaAsistenciaProyecto = [];
    $sqlUltimaAsistencia = "SELECT a.proyecto_id,
            MAX(CONCAT(a.fecha, ' ', COALESCE(a.hora_salida, a.hora_entrada))) ultima
        FROM asistencia a
        WHERE a.proyecto_id IN ($inList)
        GROUP BY a.proyecto_id";
    if ($res = $conn->query($sqlUltimaAsistencia)) {
        while ($row = $res->fetch_assoc()) {
            $ultimaAsistenciaProyecto[(int)$row['proyecto_id']] = $row['ultima'];
        }
        $res->free();
    }

    // Calcular proyectos sin asistencia hoy
    $projectsWithAttendance = array_keys($asistenciasHoyProyecto);
    $stats['proyectos_sin_asistencia'] = count(array_diff($projectIds, $projectsWithAttendance));

    // Mapear métricas a cada proyecto
    foreach ($proyectos as &$proyecto) {
        $pid = (int)$proyecto['id'];
    $proyecto['metric_servicios_especializados'] = $serviciosEspecializadosProyecto[$pid] ?? 0;
        $proyecto['metric_asistencias_hoy'] = $asistenciasHoyProyecto[$pid] ?? 0;
        $proyecto['metric_jornadas_abiertas'] = $jornadasAbiertasProyecto[$pid] ?? 0;
        $proyecto['metric_ultima_asistencia'] = $ultimaAsistenciaProyecto[$pid] ?? null;
    }
    unset($proyecto);

    // Generar alertas
    if ($stats['jornadas_abiertas'] > 0) {
        $alerts[] = [
            'icon' => 'fa-clock',
            'message' => $stats['jornadas_abiertas'] === 1
                ? 'Hay 1 jornada abierta sin hora de salida registrada.'
                : 'Hay ' . $stats['jornadas_abiertas'] . ' jornadas abiertas sin hora de salida registrada.'
        ];
    }

    if ($stats['proyectos_sin_asistencia'] > 0) {
        $alerts[] = [
            'icon' => 'fa-bell',
            'message' => $stats['proyectos_sin_asistencia'] === 1
                ? 'Un proyecto aún no tiene asistencia registrada hoy.'
                : $stats['proyectos_sin_asistencia'] . ' proyectos no tienen asistencia registrada hoy.'
        ];
    }

    foreach ($proyectos as $proyecto) {
        if (!empty($proyecto['fecha_fin'])) {
            $fechaFin = DateTime::createFromFormat('Y-m-d', $proyecto['fecha_fin']);
            if ($fechaFin) {
                $hoy = new DateTime('today');
                $diff = (int)$hoy->diff($fechaFin)->format('%r%a');
                if ($diff >= 0 && $diff <= 7) {
                    $alerts[] = [
                        'icon' => 'fa-flag-checkered',
                        'message' => 'El proyecto "' . $proyecto['nombre'] . '" finaliza en ' . ($diff === 0 ? 'menos de 24 horas' : $diff . ' días.')
                    ];
                }
            }
        }
    }

    if (empty($alerts)) {
        // Mostrar alerta positiva si no hay pendientes
        $alerts[] = [
            'icon' => 'fa-circle-check',
            'message' => 'Todo está en orden. No hay alertas pendientes por ahora.'
        ];
    }

    // Construir feed de actividad reciente
    $sqlFeed = "SELECT a.proyecto_id, a.empleado_id, a.fecha, a.hora_entrada, a.hora_salida,
    g.nombre AS proyecto_nombre, g.empresa,
    e.nombre AS servicio_especializado_nombre
        FROM asistencia a
        JOIN empleados e ON e.id = a.empleado_id
        JOIN grupos g ON g.id = a.proyecto_id
        WHERE a.proyecto_id IN ($inList)
        ORDER BY a.fecha DESC, COALESCE(a.hora_salida, a.hora_entrada) DESC
        LIMIT 20";
    $descansoStmt = $conn->prepare("SELECT inicio, fin FROM descansos WHERE empleado_id = ? AND proyecto_id = ? AND fecha = ? ORDER BY inicio ASC");
    $descEmpleadoId = $descProyectoId = 0;
    $descFecha = '';
    if ($descansoStmt) {
        $descansoStmt->bind_param('iis', $descEmpleadoId, $descProyectoId, $descFecha);
    }

    $diasSemana = [
        'Sunday' => 'Domingo',
        'Monday' => 'Lunes',
        'Tuesday' => 'Martes',
        'Wednesday' => 'Miércoles',
        'Thursday' => 'Jueves',
        'Friday' => 'Viernes',
        'Saturday' => 'Sábado',
    ];

    if ($res = $conn->query($sqlFeed)) {
        while ($row = $res->fetch_assoc()) {
            $fechaObj = DateTime::createFromFormat('Y-m-d', $row['fecha'], new DateTimeZone('America/Mexico_City'));
            $fechaLegible = $fechaObj ? $fechaObj->format('d/m/Y') : $row['fecha'];
            $diaSemana = $fechaObj ? ($diasSemana[$fechaObj->format('l')] ?? '') : '';

            $events = [];
            if (!empty($row['hora_entrada'])) {
                $events[] = [
                    'type' => 'entrada',
                    'label' => 'Entrada',
                    'time' => date('H:i', strtotime($row['hora_entrada']))
                ];
            }

            if ($descansoStmt) {
                $descEmpleadoId = (int)$row['empleado_id'];
                $descProyectoId = (int)$row['proyecto_id'];
                $descFecha = $row['fecha'];
                if ($descansoStmt->execute()) {
                    $descRes = $descansoStmt->get_result();
                    while ($desc = $descRes->fetch_assoc()) {
                        $inicio = $desc['inicio'] ? date('H:i', strtotime($desc['inicio'])) : '';
                        $fin = $desc['fin'] ? date('H:i', strtotime($desc['fin'])) : 'Pendiente';
                        $events[] = [
                            'type' => 'descanso',
                            'label' => 'Descanso',
                            'time' => trim($inicio . ($fin ? ' - ' . $fin : ''))
                        ];
                    }
                    $descRes->free();
                }
            }

            if (!empty($row['hora_salida'])) {
                $events[] = [
                    'type' => 'salida',
                    'label' => 'Salida',
                    'time' => date('H:i', strtotime($row['hora_salida']))
                ];
            } else {
                $events[] = [
                    'type' => 'salida',
                    'label' => 'Salida pendiente',
                    'time' => 'Pendiente'
                ];
            }

            $activityFeed[] = [
                'servicio_especializado' => $row['servicio_especializado_nombre'],
                'proyecto' => $row['proyecto_nombre'],
                'empresa' => $row['empresa'],
                'fecha_legible' => $fechaLegible,
                'dia_semana' => $diaSemana,
                'events' => $events
            ];
        }
        $res->free();
    }

    if ($descansoStmt) {
        $descansoStmt->close();
    }
} else {
    // Sin proyectos asignados -> mensaje positivo
    $alerts[] = [
        'icon' => 'fa-info-circle',
        'message' => 'Aún no tienes proyectos asignados. Solicita acceso a un administrador.'
    ];
}

$pmCssPath = __DIR__ . '/../assets/pm.css';
$pmCssVersion = file_exists($pmCssPath) ? filemtime($pmCssPath) : time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard PM - ErgoCuida</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/pm.css?v=<?= $pmCssVersion; ?>">
</head>
<body>
<?php require_once 'common/navigation.php'; ?>

<div class="pm-page">
    <header class="pm-header">
        <div>
            <h1 class="pm-header__title"><i class="fas fa-compass"></i> Hola <?= htmlspecialchars($firstName); ?></h1>
            <p class="pm-header__subtitle">Aquí tienes un resumen de cómo van tus proyectos y tus Servicios Especializados hoy.</p>
            <div class="filter-chips">
                <span class="chip"><i class="fas fa-diagram-project"></i> <?= (int)$stats['proyectos_total']; ?> proyectos activos</span>
                <span class="chip"><i class="fas fa-users"></i> <?= (int)$stats['servicios_especializados_total']; ?> Servicios Especializados</span>
            </div>
        </div>
        <div class="pm-header__actions">
            <a href="forms/crear_proyecto.php" class="btn btn-primary"><i class="fas fa-plus"></i> Nuevo proyecto</a>
            <a href="forms/crear_servicio.php" class="btn btn-ghost"><i class="fas fa-user-plus"></i> Nuevo Servicio</a>
            <a href="asistencias.php" class="btn btn-ghost"><i class="fas fa-calendar-check"></i> Revisar asistencias</a>
            <a href="fotos_asistencia.php" class="btn btn-ghost"><i class="fas fa-camera"></i> Fotos</a>
            <a href="empleados.php" class="btn btn-ghost"><i class="fas fa-users"></i> Servicios Especializados</a>
        </div>
    </header>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon projects"><i class="fas fa-diagram-project"></i></div>
            </div>
            <div class="stat-number"><?= (int)$stats['proyectos_total']; ?></div>
            <div class="stat-label">Proyectos activos</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon employees"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-number"><?= (int)$stats['servicios_especializados_total']; ?></div>
            <div class="stat-label">Servicios Especializados asignados</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon attendance"><i class="fas fa-user-check"></i></div>
                <span class="badge"><i class="fas fa-calendar-day"></i> Hoy</span>
            </div>
            <div class="stat-number"><?= (int)$stats['asistencias_hoy']; ?></div>
            <div class="stat-label">Asistencias registradas</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon alert"><i class="fas fa-hourglass-half"></i></div>
            </div>
            <div class="stat-number"><?= (int)$stats['jornadas_abiertas']; ?></div>
            <div class="stat-label">Jornadas en seguimiento</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon neutral"><i class="fas fa-bell-exclamation"></i></div>
            </div>
            <div class="stat-number"><?= (int)$stats['proyectos_sin_asistencia']; ?></div>
            <div class="stat-label">Proyectos sin asistencia hoy</div>
        </div>
    </div>

    <div class="pm-layout pm-layout--split">
        <section class="pm-section">
            <h3 class="pm-section__title"><i class="fas fa-diagram-project"></i> Proyectos asignados</h3>
            <p class="section-subtitle">Visualiza la salud de cada proyecto y las acciones necesarias.</p>

            <?php if (empty($proyectos)): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h3>Sin proyectos asignados</h3>
                    <p>Solicita al administrador que asigne proyectos a tu cuenta.</p>
                </div>
            <?php else: ?>
                <div class="projects-grid">
                    <?php foreach ($proyectos as $proyecto): ?>
                        <?php
                            $pid = (int)($proyecto['id'] ?? 0);
                            $serviciosEspecializadosActivos = (int)($proyecto['metric_servicios_especializados'] ?? 0);
                            $asistenciasHoy = (int)($proyecto['metric_asistencias_hoy'] ?? 0);
                            $jornadasActivas = (int)($proyecto['metric_jornadas_abiertas'] ?? 0);
                            $ultimaMarca = $proyecto['metric_ultima_asistencia'] ?? null;
                            $ultimaLegible = $ultimaMarca ? date('d/m/Y H:i', strtotime($ultimaMarca)) : 'Sin registros recientes';
                            $emergencyUrl = !empty($proyecto['token'])
                                ? '../public/emergency.php?token=' . urlencode($proyecto['token'])
                                : '';
                        ?>
                        <div class="project-card">
                            <div class="project-name"><?= htmlspecialchars($proyecto['nombre'] ?? 'Proyecto'); ?></div>
                            <?php if (!empty($proyecto['empresa'])): ?>
                                <div class="project-company"><i class="fas fa-building"></i> <?= htmlspecialchars($proyecto['empresa']); ?></div>
                            <?php endif; ?>

                            <div class="project-tags">
                                <span class="token-pill"><i class="fas fa-users"></i> <?= $serviciosEspecializadosActivos; ?> Servicios Especializados</span>
                                <span class="token-pill"><i class="fas fa-clipboard-check"></i> <?= $asistenciasHoy; ?> asistencias hoy</span>
                                <?php if ($jornadasActivas > 0): ?>
                                    <span class="token-pill token-pill--warning"><i class="fas fa-clock"></i> <?= $jornadasActivas; ?> abiertas</span>
                                <?php endif; ?>
                            </div>

                            <div class="project-meta">
                                <div class="project-meta__item">
                                    <i class="fas fa-calendar-day"></i>
                                    <span>Último registro: <?= htmlspecialchars($ultimaLegible); ?></span>
                                </div>
                                <?php if (!empty($proyecto['fecha_fin'])): ?>
                                    <div class="project-meta__item">
                                        <i class="fas fa-flag"></i>
                                        <span>Finaliza: <?= date('d/m/Y', strtotime($proyecto['fecha_fin'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($emergencyUrl): ?>
                                <div class="project-actions">
                                    <a class="btn btn-emergency" href="<?= htmlspecialchars($emergencyUrl); ?>" target="_blank" rel="noopener">
                                        <i class="fas fa-life-ring"></i> Botón de emergencia
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="pm-section">
            <h3 class="pm-section__title"><i class="fas fa-bell"></i> Alertas clave</h3>
            <p class="section-subtitle">Señales tempranas para que actúes antes de que surjan problemas.</p>

            <?php if (empty($alerts)): ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <h3>Sin alertas</h3>
                    <p>Todo está en orden por ahora. ¡Buen trabajo!</p>
                </div>
            <?php else: ?>
                <ul class="alert-list">
                    <?php foreach ($alerts as $alerta): ?>
                        <li class="alert-item">
                            <i class="fas <?= htmlspecialchars($alerta['icon']); ?>"></i>
                            <span><?= htmlspecialchars($alerta['message']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="section-subtitle" style="margin-top:24px;">Acciones rápidas</div>
            <div class="quick-actions">
                <a href="asistencias.php" class="quick-action">
                    <div class="quick-action__icon"><i class="fas fa-calendar-check"></i></div>
                    <div class="quick-action__content">
                        <strong>Ver asistencias</strong>
                        <span>Consulta jornadas abiertas y exporta reportes.</span>
                    </div>
                </a>
                <a href="fotos_asistencia.php" class="quick-action">
                    <div class="quick-action__icon" style="background: linear-gradient(135deg, #f97316, #ea580c);"><i class="fas fa-camera"></i></div>
                    <div class="quick-action__content">
                        <strong>Evidencia fotográfica</strong>
                        <span>Revisa entradas, salidas y descansos con geolocalización.</span>
                    </div>
                </a>
                <a href="empleados.php" class="quick-action">
                    <div class="quick-action__icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);"><i class="fas fa-users"></i></div>
                    <div class="quick-action__content">
                        <strong>Gestionar Servicios Especializados</strong>
                        <span>Actualiza datos, accesos y asignaciones del personal externo.</span>
                    </div>
                </a>
                <a href="documentos.php" class="quick-action">
                    <div class="quick-action__icon" style="background: linear-gradient(135deg, #2563eb, #1d4ed8);"><i class="fas fa-folder-open"></i></div>
                    <div class="quick-action__content">
                        <strong>Mis Documentos</strong>
                        <span>Sube y gestiona PDFs, contratos y archivos de trabajo.</span>
                    </div>
                </a>
            </div>
        </section>
    </div>

    <section class="pm-section">
        <h3 class="pm-section__title"><i class="fas fa-history"></i> Actividad reciente</h3>
    <p class="section-subtitle">Los últimos movimientos de tus Servicios Especializados en los últimos días.</p>

        <?php if (empty($activityFeed)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>Sin registros recientes</h3>
                <p>Aún no hay asistencias en la última semana para tus proyectos.</p>
            </div>
        <?php else: ?>
            <div class="activity-feed">
                <?php foreach ($activityFeed as $registro): ?>
                    <div class="activity-item">
                        <div class="activity-item__header">
                            <div class="activity-item__title">
                                <i class="fas fa-user-circle"></i>
                                <span><?= htmlspecialchars($registro['servicio_especializado']); ?></span>
                            </div>
                            <div class="activity-badges">
                                <span class="badge"><i class="fas fa-calendar-day"></i> <?= htmlspecialchars($registro['fecha_legible']); ?></span>
                                <?php if (!empty($registro['dia_semana'])): ?>
                                    <span class="badge"><i class="fas fa-clock"></i> <?= htmlspecialchars($registro['dia_semana']); ?></span>
                                <?php endif; ?>
                                <span class="badge"><i class="fas fa-diagram-project"></i> <?= htmlspecialchars($registro['proyecto']); ?></span>
                            </div>
                        </div>

                        <?php if (!empty($registro['empresa'])): ?>
                            <div class="activity-item__meta">
                                <i class="fas fa-building"></i>
                                <span><?= htmlspecialchars($registro['empresa']); ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="event-timeline">
                            <?php foreach ($registro['events'] as $evento): ?>
                                <?php
                                    $icon = 'clock';
                                    if ($evento['type'] === 'entrada') {
                                        $icon = 'sign-in-alt';
                                    } elseif ($evento['type'] === 'salida') {
                                        $icon = 'sign-out-alt';
                                    } elseif ($evento['type'] === 'descanso') {
                                        $icon = 'coffee';
                                    }
                                ?>
                                <div class="event-node <?= htmlspecialchars($evento['type']); ?>">
                                    <i class="fas fa-<?= $icon; ?>"></i>
                                    <div class="event-meta">
                                        <span class="event-label"><?= htmlspecialchars($evento['label']); ?></span>
                                        <span class="event-time">
                                            <?= htmlspecialchars($evento['time'] ?: 'Pendiente'); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
    function toggleMobileMenu() {
        const menu = document.querySelector('.nav-menu');
        if (menu) {
            menu.classList.toggle('active');
        }
    }

    document.addEventListener('click', function (event) {
        const nav = document.querySelector('.pm-navigation');
        const menu = document.querySelector('.nav-menu');
        const toggle = document.querySelector('.nav-toggle');
        if (!nav || !menu || !toggle) return;

        const clickedToggle = toggle.contains(event.target);
        if (clickedToggle) {
            menu.classList.toggle('active');
            return;
        }

        if (!nav.contains(event.target)) {
            menu.classList.remove('active');
        }
    });
</script>

</body>
</html>
