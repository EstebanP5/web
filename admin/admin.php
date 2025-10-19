<?php
require_once __DIR__ . '/includes/admin_init.php';

// Variables para mensajes
$mensaje_exito = '';
$mensaje_error = '';

// PROCESAR ACCIONES
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CREAR PROYECTO (actualizado con selección de PM de lista)
    if (isset($_POST['crear_proyecto'])) {
        $nombre = trim($_POST['nombre'] ?? '');
        $empresa = trim($_POST['empresa'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $localidad = trim($_POST['localidad'] ?? '');
        $coordenadas = trim($_POST['coordenadas'] ?? '');
        $fecha_inicio = trim($_POST['fecha_inicio'] ?? '');
        $fecha_fin = trim($_POST['fecha_fin'] ?? '');
        $pm_id = isset($_POST['pm_id']) ? (int)$_POST['pm_id'] : 0;
        $pm_nombre = '';
        $pm_telefono = '';
        $errores = [];

        $lat = null;
        $lng = null;
        if ($nombre === '') {
            $errores[] = 'El nombre del proyecto es obligatorio.';
        }
        if ($empresa === '') {
            $errores[] = 'La empresa o cliente es obligatoria.';
        }
        if ($coordenadas === '') {
            $errores[] = 'Ingresa las coordenadas del proyecto (latitud, longitud).';
        } else {
            $normalizado = str_replace([';', '|'], ' ', $coordenadas);
            if (preg_match('/(-?\d+(?:\.\d+)?)[,\s]+(-?\d+(?:\.\d+)?)/', $normalizado, $matches)) {
                $lat = (float)$matches[1];
                $lng = (float)$matches[2];
                if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                    $errores[] = 'Las coordenadas están fuera de rango permitido.';
                }
            } else {
                $errores[] = 'Formato de coordenadas inválido. Usa "latitud, longitud" (ej. 19.4326, -99.1332).';
            }
        }

        $pmSeleccionado = null;
        if ($pm_id > 0) {
            $stmtPm = $conn->prepare("SELECT user_id, nombre, telefono FROM project_managers WHERE id = ? AND activo = 1");
            if ($stmtPm) {
                $stmtPm->bind_param('i', $pm_id);
                $stmtPm->execute();
                $pmSeleccionado = $stmtPm->get_result()->fetch_assoc();
            }
        }
        if (!$pmSeleccionado) {
            $errores[] = 'Selecciona un Project Manager válido.';
        }

        $pmUserId = $pmSeleccionado ? (int)($pmSeleccionado['user_id'] ?? 0) : 0;
        if ($pmSeleccionado && $pmUserId <= 0) {
            $errores[] = 'El Project Manager seleccionado no tiene usuario vinculado.';
        }

        if ($pmSeleccionado) {
            $pm_nombre = (string)($pmSeleccionado['nombre'] ?? '');
            $pm_telefono = (string)($pmSeleccionado['telefono'] ?? '');
        }

        $fecha_inicio = $fecha_inicio !== '' ? $fecha_inicio : null;
        $fecha_fin = $fecha_fin !== '' ? $fecha_fin : null;

        if (empty($errores)) {
            $transactionStarted = false;
            try {
                if (!$conn->begin_transaction()) {
                    throw new RuntimeException('No se pudo iniciar la transacción.');
                }
                $transactionStarted = true;

                $token = bin2hex(random_bytes(16));
                $stmt = $conn->prepare("INSERT INTO grupos (token, nombre, empresa, localidad, lat, lng, fecha_inicio, fecha_fin, pm_nombre, pm_telefono, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                if (!$stmt) {
                    throw new RuntimeException('No se pudo preparar la inserción del proyecto.');
                }
                $stmt->bind_param('ssssddssss', $token, $nombre, $empresa, $localidad, $lat, $lng, $fecha_inicio, $fecha_fin, $pm_nombre, $pm_telefono);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Error al guardar el proyecto.');
                }

                $proyecto_id = $conn->insert_id;
                if ($pmUserId > 0) {
                    $stmtAsign = $conn->prepare("INSERT INTO proyectos_pm (user_id, proyecto_id, activo) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE proyecto_id = VALUES(proyecto_id), activo = VALUES(activo)");
                    if (!$stmtAsign) {
                        throw new RuntimeException('No se pudo vincular el Project Manager.');
                    }
                    $stmtAsign->bind_param('ii', $pmUserId, $proyecto_id);
                    if (!$stmtAsign->execute()) {
                        throw new RuntimeException('Error al asignar el Project Manager.');
                    }
                }

                $conn->commit();
                $transactionStarted = false;

                $descripcion_link = $descripcion ? ' – ' . htmlspecialchars($descripcion) : '';
                $mensaje_exito = "✅ Proyecto '" . htmlspecialchars($nombre) . "' creado{$descripcion_link}. <a href=\"../public/emergency.php?token={$token}\" target=\"_blank\">Ver página de emergencia</a>";
            } catch (Throwable $e) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                $mensaje_error = '❌ No se pudo crear el proyecto: ' . htmlspecialchars($e->getMessage());
            }
        } else {
            $mensaje_error = '❌ Corrige los siguientes puntos:<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $errores)) . '</li></ul>';
        }
    }

    // ASIGNAR SERVICIO ESPECIALIZADO A PROYECTO (solo uno activo a la vez)
    if (isset($_POST['asignar_trabajador'])) {
        $empleado_id = intval($_POST['empleado_id']);
        $proyecto_id = intval($_POST['proyecto_id']);

        if ($empleado_id && $proyecto_id) {
            $chkBloq = $conn->prepare('SELECT bloqueado FROM empleados WHERE id=? LIMIT 1');
            $chkBloq->bind_param('i', $empleado_id);
            $chkBloq->execute();
            $esBloq = $chkBloq->get_result()->fetch_assoc();
            if ($esBloq && (int)$esBloq['bloqueado'] === 1) {
                $mensaje_error = "❌ El Servicio Especializado está bloqueado (SUA no vigente). Desbloquéalo en 'Procesar SUA Automático'.";
            } else {
                $stmt0 = $conn->prepare('UPDATE empleado_proyecto SET activo=0 WHERE empleado_id=? AND activo=1');
                $stmt0->bind_param('i', $empleado_id);
                $stmt0->execute();

                $stmt1 = $conn->prepare('UPDATE empleado_proyecto SET activo=1, fecha_asignacion=NOW() WHERE empleado_id=? AND proyecto_id=?');
                $stmt1->bind_param('ii', $empleado_id, $proyecto_id);
                $stmt1->execute();
                if ($stmt1->affected_rows === 0) {
                    $stmt2 = $conn->prepare('INSERT INTO empleado_proyecto (empleado_id, proyecto_id, activo, fecha_asignacion) VALUES (?, ?, 1, NOW())');
                    $stmt2->bind_param('ii', $empleado_id, $proyecto_id);
                    if (!$stmt2->execute()) {
                        $mensaje_error = '❌ Error al asignar Servicio Especializado.';
                    } else {
                        $mensaje_exito = '✅ Servicio Especializado asignado al proyecto.';
                    }
                } else {
                    $mensaje_exito = '✅ Servicio Especializado asignado al proyecto.';
                }
            }
        }
    }

    // ASIGNAR PM A PROYECTO
    if (isset($_POST['asignar_pm'])) {
        $user_id = intval($_POST['user_id']);
        $proyecto_id = intval($_POST['proyecto_id']);

        if ($user_id && $proyecto_id) {
            $check = $conn->prepare('SELECT id FROM proyectos_pm WHERE user_id = ? AND proyecto_id = ?');
            $check->bind_param('ii', $user_id, $proyecto_id);
            $check->execute();

            if ($check->get_result()->num_rows == 0) {
                $stmt = $conn->prepare('INSERT INTO proyectos_pm (user_id, proyecto_id) VALUES (?, ?)');
                $stmt->bind_param('ii', $user_id, $proyecto_id);

                if ($stmt->execute()) {
                    $mensaje_exito = '✅ Project Manager asignado correctamente.';
                } else {
                    $mensaje_error = '❌ Error al asignar Project Manager.';
                }
            } else {
                $mensaje_error = '❌ El PM ya está asignado a este proyecto.';
            }
        }
    }

    // CREAR SERVICIO ESPECIALIZADO
    if (isset($_POST['crear_empleado'])) {
        $nombre = trim($_POST['nombre'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $nss = trim($_POST['nss'] ?? '');
        $curp = trim($_POST['curp'] ?? '');
        $puesto = 'Servicio Especializado';
        $salario = isset($_POST['salario']) ? floatval($_POST['salario']) : 0;
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');

        $altaImssFile = $_FILES['alta_imss'] ?? null;
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
        $archivoValido = false;
        $docInfo = null;
        $docError = '';

        if (!$nombre || !$telefono || !$email || !$password) {
            $mensaje_error = '❌ Nombre, teléfono, correo, contraseña y alta IMSS son obligatorios.';
        } elseif (!$altaImssFile || ($altaImssFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $mensaje_error = '❌ Debes adjuntar el alta del IMSS en formato PDF o imagen.';
        } elseif (($altaImssFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $mensaje_error = '❌ No se pudo recibir el archivo del IMSS (código ' . (int)$altaImssFile['error'] . ').';
        } elseif (($altaImssFile['size'] ?? 0) <= 0) {
            $mensaje_error = '❌ El archivo del IMSS está vacío. Verifica la selección.';
        } else {
            $extension = strtolower(pathinfo($altaImssFile['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions, true)) {
                $mensaje_error = '❌ Formato de archivo no permitido. Sube un PDF, JPG o PNG.';
            } elseif (($altaImssFile['size'] ?? 0) > 10 * 1024 * 1024) {
                $mensaje_error = '❌ El archivo del IMSS no debe superar los 10MB.';
            } else {
                $detectedMime = null;
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo) {
                        $detectedMime = finfo_file($finfo, $altaImssFile['tmp_name']);
                        finfo_close($finfo);
                    }
                }
                if ($detectedMime && !in_array($detectedMime, $allowedMimes, true)) {
                    $mensaje_error = '❌ El archivo del IMSS debe ser PDF o imagen (JPG/PNG).';
                } else {
                    $archivoValido = true;
                    $docInfo = [
                        'extension' => $extension,
                        'mime' => $detectedMime ?: ($altaImssFile['type'] ?? ''),
                        'original' => $altaImssFile['name'] ?? 'alta_imss',
                        'tmp_name' => $altaImssFile['tmp_name']
                    ];
                }
            }
        }

        if (empty($mensaje_error) && $archivoValido && $docInfo) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $u = $conn->prepare("INSERT INTO users (name, email, password, rol, activo) VALUES (?, ?, ?, 'servicio_especializado', 1)");
            if (!$u) {
                $mensaje_error = '❌ Error interno preparando inserción de usuario.';
            } else {
                $u->bind_param('sss', $nombre, $email, $hash);
                if ($u->execute()) {
                    $userId = $conn->insert_id;
                    $stmt = $conn->prepare('INSERT INTO empleados (id, nombre, telefono, nss, curp, puesto, activo, fecha_registro) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())');
                    if ($stmt) {
                        $stmt->bind_param('isssss', $userId, $nombre, $telefono, $nss, $curp, $puesto);
                        if ($stmt->execute()) {
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
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                            $uploadsDir = dirname(__DIR__) . '/uploads/altas_imss';
                            if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
                                $docError = 'No se pudo crear el directorio para guardar el alta del IMSS.';
                            } else {
                                try {
                                    $randomSegment = bin2hex(random_bytes(4));
                                } catch (Exception $e) {
                                    if (function_exists('openssl_random_pseudo_bytes')) {
                                        $fallback = openssl_random_pseudo_bytes(4);
                                        $randomSegment = $fallback !== false ? bin2hex($fallback) : substr(sha1(uniqid('', true)), 0, 8);
                                    } else {
                                        $randomSegment = substr(sha1(uniqid('', true)), 0, 8);
                                    }
                                }

                                $filename = 'alta_imss_' . $userId . '_' . date('Ymd_His') . '_' . $randomSegment . '.' . $docInfo['extension'];
                                $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $filename;

                                if (!is_uploaded_file($docInfo['tmp_name']) || !move_uploaded_file($docInfo['tmp_name'], $destPath)) {
                                    $docError = 'No se pudo guardar el archivo del IMSS en el servidor.';
                                } else {
                                    $relativePath = 'uploads/altas_imss/' . $filename;
                                    $docStmt = $conn->prepare('INSERT INTO empleado_documentos (empleado_id, tipo, ruta_archivo, nombre_original, mime_type, created_at) VALUES (?, \'alta_imss\', ?, ?, ?, NOW())');
                                    if ($docStmt) {
                                        $docStmt->bind_param('isss', $userId, $relativePath, $docInfo['original'], $docInfo['mime']);
                                        if ($docStmt->execute()) {
                                            $mensaje_exito = "✅ Servicio Especializado '" . htmlspecialchars($nombre) . "' creado y alta IMSS registrada.";
                                        } else {
                                            $docError = 'No se pudo registrar el alta del IMSS en la base de datos.';
                                        }
                                        $docStmt->close();
                                    } else {
                                        $docError = 'No se pudo preparar la inserción del alta del IMSS.';
                                    }

                                    if (!empty($docError) && file_exists($destPath)) {
                                        unlink($destPath);
                                    }
                                }
                            }

                            if (!empty($docError)) {
                                $conn->query('DELETE FROM empleado_documentos WHERE empleado_id = ' . (int)$userId);
                                $conn->query('DELETE FROM empleados WHERE id = ' . (int)$userId);
                                $conn->query('DELETE FROM users WHERE id = ' . (int)$userId);
                                $mensaje_error = '❌ ' . $docError;
                                $mensaje_exito = '';
                            }
                        } else {
                            $conn->query('DELETE FROM users WHERE id = ' . $userId);
                            $mensaje_error = '❌ Error al crear empleado (FK).';
                        }
                    } else {
                        $conn->query('DELETE FROM users WHERE id = ' . $userId);
                        $mensaje_error = '❌ Error preparando inserción de empleado.';
                    }
                } else {
                    $mensaje_error = '❌ Correo en uso o error al crear usuario.';
                }
            }
        }
    }
}

// OBTENER DATOS PARA LA INTERFAZ
$proyectos = $conn->query('SELECT * FROM grupos ORDER BY id DESC')->fetch_all(MYSQLI_ASSOC);
$empleados = $conn->query("SELECT * FROM empleados WHERE activo = 1 ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$pms = $conn->query("SELECT pm.id, pm.user_id, pm.nombre, pm.telefono, u.email FROM project_managers pm LEFT JOIN users u ON u.id = pm.user_id WHERE pm.activo = 1 ORDER BY pm.nombre")->fetch_all(MYSQLI_ASSOC);

$stats = [
    'proyectos_activos' => $conn->query("SELECT COUNT(*) as count FROM grupos WHERE activo = 1")->fetch_assoc()['count'],
    'empleados_total' => $conn->query("SELECT COUNT(*) as count FROM empleados WHERE activo = 1")->fetch_assoc()['count'],
    'pms_total' => $conn->query("SELECT COUNT(*) as count FROM users WHERE rol = 'pm' AND activo = 1")->fetch_assoc()['count'],
    'asistencias_hoy' => $conn->query("SELECT COUNT(*) as count FROM asistencia WHERE fecha = CURDATE()")
        ->fetch_assoc()['count']
];

$pageTitle = 'Panel de Administración - ErgoCuida';
$activePage = 'dashboard';
$pageHeading = 'Panel de Administración';
$pageDescription = 'Visión general de proyectos, personal y asistencias clave.';
$headerActions = [
    [
        'label' => 'Menú Principal',
        'icon' => 'fa-home',
        'href' => '../index.php',
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
            <div class="stat-icon projects">
                <i class="fas fa-project-diagram"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['proyectos_activos']; ?></div>
        <div class="stat-label">Proyectos Activos</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon employees">
                <i class="fas fa-users"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['empleados_total']; ?></div>
        <div class="stat-label">Servicios Especializados Activos</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon pms">
                <i class="fas fa-user-tie"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['pms_total']; ?></div>
        <div class="stat-label">Project Managers</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon attendance">
                <i class="fas fa-calendar-check"></i>
            </div>
        </div>
        <div class="stat-number"><?php echo $stats['asistencias_hoy']; ?></div>
        <div class="stat-label">Asistencias Hoy</div>
    </div>
</div>

<div class="main-nav">
    <div class="nav-card">
        <h3><i class="fas fa-project-diagram"></i> Gestión de Proyectos</h3>
        <p>Crear, editar y administrar proyectos. Asignar fechas y equipos de trabajo.</p>
        <div class="nav-actions">
            <button class="btn btn-primary" type="button" onclick="openModal('proyectoModal')">
                <i class="fas fa-plus"></i> Nuevo Proyecto
            </button>
            <a href="proyectos.php" class="btn btn-secondary">
                <i class="fas fa-list"></i> Ver Todos
            </a>
        </div>
    </div>

    <div class="nav-card">
        <h3><i class="fas fa-users"></i> Gestión de Servicios Especializados</h3>
        <p>Administrar Servicios Especializados, crear perfiles y asignar a proyectos.</p>
        <div class="nav-actions">
            <button class="btn btn-success" type="button" onclick="openModal('empleadoModal')">
                <i class="fas fa-user-plus"></i> Nuevo Servicio Especializado
            </button>
            <a href="empleados.php" class="btn btn-secondary">
                <i class="fas fa-list"></i> Ver Todos
            </a>
        </div>
    </div>

    <div class="nav-card">
        <h3><i class="fas fa-user-tie"></i> Project Managers</h3>
        <p>Gestionar PMs y sus asignaciones a proyectos específicos.</p>
        <div class="nav-actions">
            <a href="crear_pm.php" class="btn btn-warning">
                <i class="fas fa-user-plus"></i> Crear PM
            </a>
            <a href="project_managers.php" class="btn btn-secondary">
                <i class="fas fa-list"></i> Ver PMs
            </a>
        </div>
    </div>

    <div class="nav-card">
        <h3><i class="fas fa-calendar-check"></i> Control de Asistencia</h3>
        <p>Monitorear asistencias, ubicaciones y horarios de trabajo.</p>
        <div class="nav-actions">
            <a href="asistencias_mejorado.php" class="btn btn-primary">
                <i class="fas fa-eye"></i> Gestión Asistencias
            </a>
            <a href="fotos_asistencia.php" class="btn btn-secondary">
                <i class="fas fa-camera"></i> Ver Fotos
            </a>
        </div>
    </div>

    <div class="nav-card">
        <h3><i class="fas fa-shield-alt"></i> SUA Automático</h3>
        <p>Procesar SUA, actualizar autorizados del mes y bloquear/desbloquear Servicios Especializados.</p>
        <div class="nav-actions">
            <a href="procesar_sua_auto.php" class="btn btn-primary">
                <i class="fas fa-sync"></i> Procesar SUA Automático
            </a>
        </div>
    </div>

    <div class="nav-card">
        <h3><i class="fas fa-video"></i> Videos de Capacitación</h3>
        <p>Gestionar contenido educativo y videos informativos para Servicios Especializados.</p>
        <div class="nav-actions">
            <a href="videos.php" class="btn btn-success">
                <i class="fas fa-plus"></i> Subir Video
            </a>
            <a href="../common/videos.php" class="btn btn-secondary">
                <i class="fas fa-play"></i> Ver Videos
            </a>
        </div>
    </div>
</div>

<div class="section">
    <h2><i class="fas fa-chart-line"></i> Resumen operativo</h2>
    <div class="summary-grid">
        <div class="summary-card">
            <h4><i class="fas fa-building"></i> Proyectos recientes</h4>
            <?php if (!empty($proyectos)): ?>
                <ul>
                    <?php foreach (array_slice($proyectos, 0, 5) as $proyecto): ?>
                        <li class="summary-item">
                            <div class="summary-item__info">
                                <strong><?php echo htmlspecialchars($proyecto['nombre']); ?></strong>
                                <span class="summary-item__meta"><?php echo htmlspecialchars($proyecto['empresa'] ?? ''); ?></span>
                            </div>
                            <span class="chip"><?php echo ((int)$proyecto['activo'] === 1) ? 'Activo' : 'Inactivo'; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="summary-empty">Aún no hay proyectos registrados.</p>
            <?php endif; ?>
        </div>

        <div class="summary-card">
            <h4><i class="fas fa-user-friends"></i> Servicios Especializados activos</h4>
            <?php if (!empty($empleados)): ?>
                <ul>
                    <?php foreach (array_slice($empleados, 0, 5) as $empleado): ?>
                        <li class="summary-item">
                            <div class="summary-item__info">
                                <strong><?php echo htmlspecialchars($empleado['nombre']); ?></strong>
                                <span class="summary-item__meta"><?php echo htmlspecialchars($empleado['telefono'] ?? ''); ?></span>
                            </div>
                               <!-- ID chip removed -->
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="summary-empty">No hay Servicios Especializados activos registrados.</p>
            <?php endif; ?>
        </div>

        <div class="summary-card">
            <h4><i class="fas fa-user-tie"></i> Project Managers</h4>
            <?php if (!empty($pms)): ?>
                <ul>
                    <?php foreach (array_slice($pms, 0, 5) as $pm): ?>
                        <li class="summary-item">
                            <div class="summary-item__info">
                                <strong><?php echo htmlspecialchars($pm['nombre']); ?></strong>
                                <span class="summary-item__meta"><?php echo htmlspecialchars($pm['telefono'] ?? ''); ?></span>
                            </div>
                            <span class="chip">ID <?php echo (int)$pm['id']; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="summary-empty">No hay PM activos registrados.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

    <style>
        .modal .hint {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 13px;
            color: #475569;
            margin-top: 8px;
        }
        .modal .hint--warning {
            background: #fef2f2;
            border-style: solid;
            border-color: #fecaca;
            color: #b91c1c;
        }
        .modal .coord-tags {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .modal .coord-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 999px;
            background: #fff4ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            font-weight: 600;
            font-size: 13px;
        }
        .modal .coord-chip::before {
            content: '📍';
            font-size: 14px;
        }
        .modal .map-card {
            margin: 18px 0 6px;
            display: none;
            background: #ffffff;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 15px 40px rgba(15, 23, 42, 0.08);
        }
        .modal .map-card iframe {
            width: 100%;
            height: 260px;
            border: 0;
        }
        @media (max-width: 640px) {
            .modal .map-card iframe {
                height: 220px;
            }
        }
    </style>

<!-- Modal Crear Proyecto -->
<div id="proyectoModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Crear Nuevo Proyecto</h3>
            <button class="close-btn" type="button" onclick="closeModal('proyectoModal')">&times;</button>
        </div>
        <form method="POST" id="proyectoForm">
            <input type="hidden" name="crear_proyecto" value="1">

            <div class="form-grid">
                <div class="form-group">
                    <label>Nombre del Proyecto *</label>
                    <input type="text" name="nombre" class="form-control" required placeholder="Ej: Torre Corporativa Central">
                </div>
                <div class="form-group">
                    <label>Empresa/Cliente *</label>
                    <input type="text" name="empresa" class="form-control" required placeholder="Ej: Constructora ABC">
                </div>
            </div>

            

            <div class="form-grid">
                <div class="form-group">
                    <label>Fecha de Inicio</label>
                    <input type="date" name="fecha_inicio" class="form-control">
                </div>
                <div class="form-group">
                    <label>Fecha de Fin</label>
                    <input type="date" name="fecha_fin" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label>Ubicación *</label>
                <input type="text" id="localidad_modal" name="localidad" class="form-control" required placeholder="Dirección completa del proyecto">
                <div class="hint" id="dirHintModal" style="display:none"></div>
                <div class="hint" id="geocodeLoadingModal" style="display:none">🔄 Buscando dirección recomendada…</div>
            </div>

            <div class="form-group">
                <label>Coordenadas (Latitud, Longitud) *</label>
                <input type="text" name="coordenadas" id="coordenadas_modal" class="form-control" required placeholder="19.4326,-99.1332" oninput="procesarCoordenadasModalDebounced()" onblur="verificarDireccion()">
                <div class="hint">Pega las coordenadas separadas por coma o espacio. Ejemplo: <kbd>19.4326 -99.1332</kbd></div>
                <div class="coord-tags" id="coordTagsModal" style="display:none">
                    <span class="coord-chip" id="latChipModal">Lat: 0.000000</span>
                    <span class="coord-chip" id="lngChipModal">Lng: 0.000000</span>
                </div>
                <div class="hint hint--warning" id="coordWarningModal" style="display:none"></div>
            </div>

            <div class="map-card" id="mapWrapperModal">
                <iframe id="mapPreviewModal" title="Vista previa del mapa"></iframe>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Selecciona el PM *</label>
                    <select name="pm_id" id="pm_id_modal" class="form-control" required onchange="actualizaPMModal()">
                        <option value="">-- Selecciona --</option>
                        <?php foreach ($pms as $pm): ?>
                            <option value="<?php echo (int)$pm['id']; ?>" data-nombre="<?php echo htmlspecialchars($pm['nombre']); ?>" data-telefono="<?php echo isset($pm['telefono']) ? htmlspecialchars($pm['telefono']) : ''; ?>" data-email="<?php echo isset($pm['email']) ? htmlspecialchars($pm['email']) : ''; ?>" data-user="<?php echo isset($pm['user_id']) ? (int)$pm['user_id'] : 0; ?>">
                                <?php echo htmlspecialchars($pm['nombre']); ?><?php echo isset($pm['telefono']) && $pm['telefono'] ? ' (' . htmlspecialchars($pm['telefono']) . ')' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nombre del PM</label>
                    <input type="text" name="pm_nombre" id="pm_nombre_modal" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label>Teléfono del PM</label>
                    <input type="tel" name="pm_telefono" id="pm_telefono_modal" class="form-control" readonly>
                </div>
            </div>

            <div class="modal-actions">
                <div class="modal-actions__group">
                    <button type="button" class="btn btn-secondary" onclick="usarUbicacion()">📍 Usar mi ubicación</button>
                    <button type="button" class="btn btn-secondary" onclick="verificarDireccion()">🔍 Verificar ubicación</button>
                    <button type="button" class="btn btn-secondary" onclick="limpiarCoordenadasModal()">🧹 Limpiar</button>
                </div>
                <div class="modal-actions__group modal-actions__group--end">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('proyectoModal')">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Crear Proyecto</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal Crear Empleado -->
<div id="empleadoModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Crear Nuevo Servicio Especializado</h3>
            <button class="close-btn" type="button" onclick="closeModal('empleadoModal')">&times;</button>
        </div>
        <form method="POST" id="empleadoForm" enctype="multipart/form-data">
            <input type="hidden" name="crear_empleado" value="1">

            <div class="form-grid">
                <div class="form-group">
                    <label>Nombre Completo *</label>
                    <input type="text" name="nombre" class="form-control" required placeholder="Juan Pérez González">
                </div>
                <div class="form-group">
                    <label>Teléfono *</label>
                    <input type="tel" name="telefono" class="form-control" required placeholder="+52 55 1234 5678">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Correo electrónico *</label>
                    <input type="email" name="email" class="form-control" required placeholder="correo@empresa.com">
                </div>
                <div class="form-group">
                    <label>Contraseña *</label>
                    <input type="password" name="password" class="form-control" required placeholder="Mínimo 8 caracteres" minlength="8">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>NSS</label>
                    <input type="text" name="nss" class="form-control" placeholder="12-34-56-7890-1">
                </div>
                <div class="form-group">
                    <label>CURP</label>
                    <input type="text" name="curp" class="form-control" maxlength="18" placeholder="PEGJ850101HDFRZN01">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group full-width">
                    <label>Alta IMSS (PDF o imagen) *</label>
                    <input type="file" name="alta_imss" class="form-control" required accept=".pdf,image/*">
                    <small class="text-muted">Adjunta el comprobante oficial del alta. Tamaño máximo 10MB.</small>
                </div>
            </div>

            <div class="modal-actions modal-actions--right">
                <div class="modal-actions__group">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('empleadoModal')">Cancelar</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-user-plus"></i> Crear Empleado</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    document.body.style.overflow = 'auto';
}

window.onclick = function(event) {
    if (event.target.classList && event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
};

setTimeout(function() {
    document.querySelectorAll('.alert').forEach(alert => {
        alert.style.opacity = '0';
        alert.style.transform = 'translateY(-10px)';
        setTimeout(() => alert.remove(), 300);
    });
}, 5000);

(function(){
    const msg = <?php echo json_encode($mensaje_exito); ?>;
    if (msg) {
        const lower = msg.toLowerCase();
        if (lower.includes('proyecto')) {
            const f = document.getElementById('proyectoForm');
            if (f) f.reset();
            if (typeof resetProyectoModalUI === 'function') {
                resetProyectoModalUI();
            }
            closeModal('proyectoModal');
        }
    if (lower.includes('servicio especializado')) {
            const f2 = document.getElementById('empleadoForm');
            if (f2) f2.reset();
            closeModal('empleadoModal');
        }
    }
})();

function actualizaPMModal() {
    const sel = document.getElementById('pm_id_modal');
    let nombre = '';
    let telefono = '';
    if (sel.selectedIndex > 0) {
        nombre = sel.options[sel.selectedIndex].getAttribute('data-nombre') || '';
        telefono = sel.options[sel.selectedIndex].getAttribute('data-telefono') || '';
    }
    document.getElementById('pm_nombre_modal').value = nombre;
    document.getElementById('pm_telefono_modal').value = telefono;
}

document.addEventListener('DOMContentLoaded', function () {
    actualizaPMModal();
    procesarCoordenadasModal(false);
});
</script>

<script>
const coordInputModal = document.getElementById('coordenadas_modal');
const coordTagsModal = document.getElementById('coordTagsModal');
const latChipModal = document.getElementById('latChipModal');
const lngChipModal = document.getElementById('lngChipModal');
const coordWarningModal = document.getElementById('coordWarningModal');
const mapWrapperModal = document.getElementById('mapWrapperModal');
const mapFrameModal = document.getElementById('mapPreviewModal');
const dirHintModal = document.getElementById('dirHintModal');
const geocodeLoadingModal = document.getElementById('geocodeLoadingModal');
const localidadModal = document.getElementById('localidad_modal');
const LAT_MIN = -90, LAT_MAX = 90, LNG_MIN = -180, LNG_MAX = 180;
let geocodeTimerModal = null;

function procesarCoordenadasModal(triggerGeocode = false) {
    if (!coordInputModal) return;
    if (triggerGeocode) {
        clearTimeout(geocodeTimerModal);
    }
    const valor = coordInputModal.value.trim();
    if (!valor) {
        ocultarCoordenadasModal();
        ocultarMapaModal();
        mostrarAlertaCoordsModal('');
        ocultarDireccionModal();
        return;
    }

    const coincidencias = valor.replace(/[;|]/g, ' ').match(/-?\d+(?:\.\d+)?/g);
    if (!coincidencias || coincidencias.length < 2) {
        mostrarAlertaCoordsModal('Formato inválido. Usa latitud y longitud separadas por coma o espacio.');
        ocultarMapaModal();
        return;
    }

    const lat = parseFloat(coincidencias[0]);
    const lng = parseFloat(coincidencias[1]);
    if (!esNumeroValidoModal(lat) || !esNumeroValidoModal(lng)) {
        mostrarAlertaCoordsModal('Las coordenadas deben ser numéricas.');
        ocultarMapaModal();
        return;
    }
    if (!estaEnRangoModal(lat, lng)) {
        mostrarAlertaCoordsModal('Las coordenadas deben estar entre Lat -90/90 y Lng -180/180.');
        ocultarMapaModal();
        return;
    }

    const latNorm = lat.toFixed(6);
    const lngNorm = lng.toFixed(6);
    coordInputModal.value = `${latNorm}, ${lngNorm}`;
    actualizarChipsModal(latNorm, lngNorm);
    mostrarAlertaCoordsModal('');
    renderMapaModal(lat, lng);
    if (triggerGeocode) {
        ocultarDireccionModal();
        buscarDireccionModal(lat, lng);
    }
}

function procesarCoordenadasModalDebounced() {
    procesarCoordenadasModal(false);
    clearTimeout(geocodeTimerModal);
    geocodeTimerModal = setTimeout(() => procesarCoordenadasModal(true), 600);
}

function esNumeroValidoModal(valor) {
    return typeof valor === 'number' && !Number.isNaN(valor) && Number.isFinite(valor);
}

function estaEnRangoModal(lat, lng) {
    return lat >= LAT_MIN && lat <= LAT_MAX && lng >= LNG_MIN && lng <= LNG_MAX;
}

function actualizarChipsModal(lat, lng) {
    if (!coordTagsModal || !latChipModal || !lngChipModal) return;
    coordTagsModal.style.display = 'flex';
    latChipModal.textContent = `Lat: ${lat}`;
    lngChipModal.textContent = `Lng: ${lng}`;
}

function ocultarCoordenadasModal() {
    if (coordTagsModal) {
        coordTagsModal.style.display = 'none';
    }
}

function mostrarAlertaCoordsModal(mensaje) {
    if (!coordWarningModal) return;
    if (mensaje) {
        coordWarningModal.textContent = mensaje;
        coordWarningModal.style.display = 'block';
    } else {
        coordWarningModal.textContent = '';
        coordWarningModal.style.display = 'none';
    }
}

function renderMapaModal(lat, lng) {
    if (!mapWrapperModal || !mapFrameModal) return;
    const delta = 0.004;
    const south = Math.max(lat - delta, LAT_MIN);
    const north = Math.min(lat + delta, LAT_MAX);
    const west = Math.max(lng - delta, LNG_MIN);
    const east = Math.min(lng + delta, LNG_MAX);
    const bbox = `${west.toFixed(6)},${south.toFixed(6)},${east.toFixed(6)},${north.toFixed(6)}`;
    mapFrameModal.src = `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${lat},${lng}`;
    mapWrapperModal.style.display = 'block';
}

function ocultarMapaModal() {
    if (!mapWrapperModal) return;
    if (mapFrameModal) {
        mapFrameModal.src = '';
    }
    mapWrapperModal.style.display = 'none';
}

function ocultarDireccionModal() {
    if (dirHintModal) {
        dirHintModal.textContent = '';
        dirHintModal.style.display = 'none';
    }
    if (geocodeLoadingModal) {
        geocodeLoadingModal.style.display = 'none';
    }
}

function verificarDireccion() {
    procesarCoordenadasModal(true);
}

function usarUbicacion() {
    if (!navigator.geolocation) {
        alert('Geolocalización no soportada');
        return;
    }
    navigator.geolocation.getCurrentPosition(pos => {
        if (coordInputModal) {
            coordInputModal.value = `${pos.coords.latitude.toFixed(6)}, ${pos.coords.longitude.toFixed(6)}`;
        }
        procesarCoordenadasModal(true);
    }, err => alert('No se pudo obtener ubicación: ' + err.message), {
        enableHighAccuracy: true,
        timeout: 12000
    });
}

async function buscarDireccionModal(lat, lng) {
    if (!geocodeLoadingModal || !dirHintModal) return;
    geocodeLoadingModal.style.display = 'block';
    dirHintModal.style.display = 'none';
    try {
        const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=17&addressdetails=1&accept-language=es`);
        if (!response.ok) {
            throw new Error('Respuesta inválida');
        }
        const data = await response.json();
        const address = data.address || {};
        const partes = [
            address.road || address.street,
            address.neighbourhood || address.suburb,
            address.city || address.town || address.village,
            address.state
        ].filter(Boolean).join(', ');
        const display = partes || data.display_name || '';
        if (display) {
            dirHintModal.textContent = `Dirección sugerida: ${display}`;
            dirHintModal.style.display = 'block';
            if (localidadModal && localidadModal.value.trim() === '') {
                localidadModal.value = display;
            }
        } else {
            dirHintModal.textContent = 'No se encontró una dirección precisa para estas coordenadas.';
            dirHintModal.style.display = 'block';
        }
    } catch (error) {
        mostrarAlertaCoordsModal('No se pudo verificar la dirección. Intenta nuevamente.');
    } finally {
        geocodeLoadingModal.style.display = 'none';
    }
}

function limpiarCoordenadasModal() {
    clearTimeout(geocodeTimerModal);
    geocodeTimerModal = null;
    if (coordInputModal) {
        coordInputModal.value = '';
        const modal = document.getElementById('proyectoModal');
        if (modal && modal.style.display === 'block') {
            coordInputModal.focus();
        }
    }
    ocultarCoordenadasModal();
    ocultarMapaModal();
    mostrarAlertaCoordsModal('');
    ocultarDireccionModal();
    if (localidadModal) {
        localidadModal.value = '';
    }
}

function resetProyectoModalUI() {
    limpiarCoordenadasModal();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>