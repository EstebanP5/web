<?php
session_start();
require_once '../includes/db.php';

// Verificar autenticación y rol (permitir responsable y Servicio Especializado)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'] ?? '', ['responsable','servicio_especializado'])) {
    header('Location: ../login.php');
    exit;
}

// Asegurar tabla de descansos
$conn->query("CREATE TABLE IF NOT EXISTS descansos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empleado_id INT NOT NULL,
  proyecto_id INT NOT NULL,
  fecha DATE NOT NULL,
  inicio DATETIME NOT NULL,
  fin DATETIME NULL,
  motivo VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_emp_fecha (empleado_id, proyecto_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$mensaje_exito = '';
$mensaje_error = '';

if (!function_exists('responsable_parse_datetime')) {
    function responsable_parse_datetime(?string $fecha, ?string $hora): ?int {
        if (empty($hora)) {
            return null;
        }

        $hora = trim($hora);
        $fecha = $fecha ? trim($fecha) : null;

        // Normalizar fracciones de segundo conservando solo HH:MM:SS
        if (preg_match('/^\d{2}:\d{2}:\d{2}(\.\d+)?$/', $hora)) {
            $hora = substr($hora, 0, 8);
            $valor = ($fecha ?: date('Y-m-d')) . ' ' . $hora;
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?$/', $hora)) {
            // Hora ya viene como timestamp completo
            $valor = substr($hora, 0, 19);
        } else {
            // Cadenas ISO u otros formatos (ej. 2024-01-01T08:00:00.000Z)
            $valor = $hora;
            if ($fecha && strpos($hora, 'T') === false) {
                $valor = $fecha . ' ' . $hora;
            }
        }

        try {
            $dt = new DateTimeImmutable($valor);
            return $dt->getTimestamp();
        } catch (Exception $e) {
            $timestamp = strtotime($valor);
            return $timestamp !== false ? $timestamp : null;
        }
    }
}

if (!function_exists('responsable_calcular_trabajado')) {
    function responsable_calcular_trabajado(?array $asistencia, array $descansos, string $diaOperativo): int {
        if (!$asistencia || empty($asistencia['hora_entrada'])) {
            return 0;
        }

        $entrada = responsable_parse_datetime($asistencia['fecha'] ?? $diaOperativo, $asistencia['hora_entrada']);
        if (!$entrada) {
            return 0;
        }

        $salida = !empty($asistencia['hora_salida'])
            ? responsable_parse_datetime($asistencia['fecha'] ?? $diaOperativo, $asistencia['hora_salida'])
            : time();

        if (!$salida || $salida < $entrada) {
            $salida = time();
        }

        $trabajado = max(0, $salida - $entrada);

        foreach ($descansos as $descanso) {
            if (empty($descanso['inicio'])) {
                continue;
            }

            $inicioDescanso = responsable_parse_datetime($diaOperativo, $descanso['inicio']);
            if (!$inicioDescanso) {
                continue;
            }

            $finDescanso = !empty($descanso['fin'])
                ? responsable_parse_datetime($diaOperativo, $descanso['fin'])
                : time();

            if ($finDescanso && $finDescanso > $inicioDescanso) {
                $trabajado -= ($finDescanso - $inicioDescanso);
            }
        }

        return (int) max(0, $trabajado);
    }
}


// Obtener información del Servicio Especializado y sus proyectos
$empleado_query = "SELECT * FROM empleados WHERE id = ? AND activo = 1";
$stmt = $conn->prepare($empleado_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$empleado = $stmt->get_result()->fetch_assoc();

if (!$empleado) {
    // Si no existe como Servicio Especializado, crear registro básico
    $stmt = $conn->prepare("INSERT INTO empleados (id, nombre, activo, puesto) VALUES (?, ?, 1, 'Servicio Especializado')");
    $stmt->bind_param("is", $user_id, $user_name);
    $stmt->execute();
    
    $empleado = ['id' => $user_id, 'nombre' => $user_name, 'telefono' => '', 'puesto' => 'Servicio Especializado'];
} elseif (empty($empleado['puesto']) || strcasecmp($empleado['puesto'], 'servicio especializado') !== 0) {
    $stmt = $conn->prepare("UPDATE empleados SET puesto = 'Servicio Especializado' WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $empleado['puesto'] = 'Servicio Especializado';
}
// Obtener proyectos asignados al Servicio Especializado
// Obtener proyectos asignados al trabajador
$proyectos_query = "
    SELECT g.*, ep.fecha_asignacion 
    FROM grupos g 
    INNER JOIN empleado_proyecto ep ON g.id = ep.proyecto_id 
    WHERE ep.empleado_id = ? AND ep.activo = 1 AND g.activo = 1
    ORDER BY g.nombre
";
$stmt = $conn->prepare($proyectos_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$proyectos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Obtener asistencia de hoy
// Definir fecha operativa (12 horas tras cierre)
$dia_operativo = date('Y-m-d');
$asistencia_hoy = null; $ultimo_registro = null; $turno_cerrado_reciente = false; $siguiente_inicio_permitido_ts = null; $bloqueo_nuevo_turno_msg = '';
if (!empty($proyectos)) {
    $proyecto_ids = array_column($proyectos, 'id');
    $ph = str_repeat('?,', count($proyecto_ids) - 1) . '?';
    // Último registro del usuario (en proyectos asignados)
    $sqlUlt = "SELECT * FROM asistencia WHERE empleado_id = ? AND proyecto_id IN ($ph) ORDER BY COALESCE(hora_salida, hora_entrada) DESC LIMIT 1";
    $stmt = $conn->prepare($sqlUlt);
    $typesUlt = 'i' . str_repeat('i', count($proyecto_ids));
    $paramsUlt = array_merge([$user_id], $proyecto_ids);
    $stmt->bind_param($typesUlt, ...$paramsUlt);
    $stmt->execute();
    $ultimo_registro = $stmt->get_result()->fetch_assoc();
    if ($ultimo_registro && $ultimo_registro['hora_salida']) {
        $ultima_salida_ts = responsable_parse_datetime($ultimo_registro['fecha'] ?? $dia_operativo, $ultimo_registro['hora_salida']);
        if ($ultima_salida_ts !== null) {
            $diff = time() - $ultima_salida_ts;
            if ($diff < 12 * 3600) { // dentro de 12 horas del cierre
                $dia_operativo = $ultimo_registro['fecha'] ?? $dia_operativo;
                $turno_cerrado_reciente = true;
            }
            $siguiente_inicio_permitido_ts = strtotime('tomorrow', $ultima_salida_ts);
            if ($siguiente_inicio_permitido_ts && time() < $siguiente_inicio_permitido_ts) {
                $bloqueo_nuevo_turno_msg = 'No podrás abrir un nuevo turno hasta las ' . date('d/m/Y H:i', $siguiente_inicio_permitido_ts) . '.';
            }
        }
    }
    // Cargar asistencia del día operativo
    $sqlDia = "SELECT * FROM asistencia WHERE empleado_id = ? AND fecha = ? AND proyecto_id IN ($ph) ORDER BY hora_entrada DESC LIMIT 1";
    $stmt = $conn->prepare($sqlDia);
    $typesDia = 'is' . str_repeat('i', count($proyecto_ids));
    $paramsDia = array_merge([$user_id, $dia_operativo], $proyecto_ids);
    $stmt->bind_param($typesDia, ...$paramsDia);
    $stmt->execute();
    $asistencia_hoy = $stmt->get_result()->fetch_assoc();
}

// Procesar registro de asistencia y descansos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_asistencia'])) {
    $proyecto_id = intval($_POST['proyecto_id'] ?? 0);
    $tipo = $_POST['accion_asistencia']; // entrada | salida | descanso | reanudar
    $lat = isset($_POST['lat']) ? floatval($_POST['lat']) : 0;
    $lng = isset($_POST['lng']) ? floatval($_POST['lng']) : 0;
    $motivo = trim($_POST['motivo'] ?? '');

    if ($proyecto_id && in_array($proyecto_id, array_column($proyectos, 'id'))) {
        if ($tipo === 'entrada') {
            // Si el último turno se cerró hace <12h y pertenece a ese día, no permitir nueva entrada
            if ($turno_cerrado_reciente) {
                $mensaje_error = '❌ Ya registraste tu salida hoy. Podrás iniciar un nuevo turno a partir de las ' . ($siguiente_inicio_permitido_ts ? date('d/m/Y H:i', $siguiente_inicio_permitido_ts) : '00:00 del siguiente día') . '.';
            } elseif ($siguiente_inicio_permitido_ts && time() < $siguiente_inicio_permitido_ts) {
                $mensaje_error = '⏳ No puedes abrir un nuevo turno hasta las ' . date('d/m/Y H:i', $siguiente_inicio_permitido_ts) . '.';
            } else {
            // Registrar entrada (si ya existe, actualizar la hora)
                $stmt = $conn->prepare("INSERT INTO asistencia (empleado_id, proyecto_id, fecha, hora_entrada, lat_entrada, lon_entrada)
                                        VALUES (?, ?, ?, NOW(), ?, ?)
                                        ON DUPLICATE KEY UPDATE hora_entrada = IFNULL(hora_entrada, NOW()), lat_entrada = VALUES(lat_entrada), lon_entrada = VALUES(lon_entrada)");
                $stmt->bind_param('iisdd', $user_id, $proyecto_id, $dia_operativo, $lat, $lng);
                if ($stmt->execute()) { $mensaje_exito = '✅ Entrada registrada correctamente'; } else { $mensaje_error = '❌ Error al registrar entrada'; }
            }
        } elseif ($tipo === 'salida') {
            $stmt = $conn->prepare("UPDATE asistencia SET hora_salida = NOW(), lat_salida = ?, lon_salida = ? WHERE empleado_id = ? AND proyecto_id = ? AND fecha = ?");
            $stmt->bind_param('ddiis', $lat, $lng, $user_id, $proyecto_id, $dia_operativo);
            if ($stmt->execute() && $stmt->affected_rows > 0) { $mensaje_exito = '✅ Salida registrada correctamente'; } else { $mensaje_error = '❌ Error al registrar salida'; }
        } elseif ($tipo === 'descanso') {
            if ($motivo === '') { $mensaje_error = '❌ Indica el motivo del descanso.'; }
            else {
                $stmt = $conn->prepare("SELECT id, inicio, fin FROM descansos WHERE empleado_id = ? AND proyecto_id = ? AND fecha = ? ORDER BY inicio DESC LIMIT 1");
                $stmt->bind_param('iis', $user_id, $proyecto_id, $dia_operativo);
                $stmt->execute();
                $existing_descanso = $stmt->get_result()->fetch_assoc();
                if ($existing_descanso && $existing_descanso['fin'] === null) {
                    $mensaje_exito = '⏸️ Descanso ya se encontraba activo.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO descansos (empleado_id, proyecto_id, fecha, inicio, motivo) VALUES (?, ?, ?, NOW(), ?)" );
                    $stmt->bind_param('iiss', $user_id, $proyecto_id, $dia_operativo, $motivo);
                    if ($stmt->execute()) { $mensaje_exito = '⏸️ Descanso iniciado'; } else { $mensaje_error = '❌ Error al iniciar descanso'; }
                }
            }
        } elseif ($tipo === 'reanudar') {
            // Cerrar último descanso abierto
            $stmt = $conn->prepare("UPDATE descansos SET fin = NOW() WHERE empleado_id = ? AND proyecto_id = ? AND fecha = ? AND fin IS NULL ORDER BY inicio DESC LIMIT 1");
            $stmt->bind_param('iis', $user_id, $proyecto_id, $dia_operativo);
            if ($stmt->execute() && $stmt->affected_rows > 0) { $mensaje_exito = '▶️ Trabajo reanudado'; } else { $mensaje_error = '❌ No hay descanso activo'; }
        }

    // Refrescar estado del día operativo (antes usaba $hoy que no existe -> no actualizaba la entrada)
    $stmt = $conn->prepare("SELECT * FROM asistencia WHERE empleado_id = ? AND fecha = ? AND proyecto_id = ?");
    $stmt->bind_param('isi', $user_id, $dia_operativo, $proyecto_id);
    $stmt->execute();
    $asistencia_hoy = $stmt->get_result()->fetch_assoc();

    $turno_cerrado_reciente = ($asistencia_hoy && !empty($asistencia_hoy['hora_salida']));
    }
}

// Estado de descanso actual y sumatoria de descansos de hoy
$descanso_activo = null;
$descansos_hoy = [];
$tiempo_trabajado_inicial = 0;
if (!empty($proyectos)) {
    $pid = $asistencia_hoy && isset($asistencia_hoy['proyecto_id']) ? (int)$asistencia_hoy['proyecto_id'] : (int)$proyectos[0]['id'];
    $rs = $conn->prepare("SELECT * FROM descansos WHERE empleado_id = ? AND proyecto_id = ? AND fecha = ? ORDER BY inicio ASC");
    $rs->bind_param('iis', $user_id, $pid, $dia_operativo);
    $rs->execute();
    $descansos_hoy = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($descansos_hoy as $d) { if ($d['fin'] === null) { $descanso_activo = $d; } }

    $tiempo_trabajado_inicial = responsable_calcular_trabajado($asistencia_hoy, $descansos_hoy, $dia_operativo);
}

// Historial reciente (últimos 10 registros del usuario en proyectos activos)
$historial = [];
if (!empty($proyectos)) {
    $proyecto_ids = array_column($proyectos, 'id');
    $placeholders = implode(',', array_fill(0, count($proyecto_ids), '?'));
    $types = str_repeat('i', count($proyecto_ids) + 1);
    $sql = "SELECT a.*, g.nombre AS proyecto_nombre FROM asistencia a JOIN grupos g ON a.proyecto_id = g.id WHERE a.empleado_id = ? AND a.proyecto_id IN ($placeholders) ORDER BY a.fecha DESC, a.hora_entrada DESC LIMIT 10";
    $stmt = $conn->prepare($sql);
    $params = array_merge([$user_id], $proyecto_ids);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $historial = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$proyecto_actual_id = $asistencia_hoy['proyecto_id'] ?? (!empty($proyectos) ? (int)$proyectos[0]['id'] : null);
$proyecto_actual = null;
if ($proyecto_actual_id) {
    foreach ($proyectos as $proyecto_item) {
        if ((int)$proyecto_item['id'] === (int)$proyecto_actual_id) {
            $proyecto_actual = $proyecto_item;
            break;
        }
    }
}

$emergency_url = '';
if ($proyecto_actual && !empty($proyecto_actual['token'])) {
    $emergency_url = '../public/emergency.php?token=' . urlencode($proyecto_actual['token']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Servicio Especializado - ErgoCuida</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .header-title i {
            font-size: 28px;
        }
        
        .header-title h1 {
            font-size: 24px;
            font-weight: 700;
        }
        
        .header-user {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .user-info {
            text-align: right;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 16px;
        }
        
        .user-role {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .welcome-banner h2 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .welcome-banner p {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .main-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
        }
        
        .section h3 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .attendance-card {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .attendance-status {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        
        .attendance-time {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .attendance-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .emergency-actions {
            margin-top: 20px;
            text-align: center;
        }

        .btn-emergency {
            background: linear-gradient(135deg, #ef4444, #b91c1c);
            color: #fff;
            box-shadow: 0 12px 28px rgba(239, 68, 68, 0.28);
        }

        .btn-emergency:hover {
            background: linear-gradient(135deg, #dc2626, #991b1b);
        }

        .emergency-note {
            margin-top: 8px;
            font-size: 13px;
            color: #b91c1c;
        }
        
        .btn-entrada {
            background: #22c55e;
            color: white;
        }
        
        .btn-salida {
            background: #ef4444;
            color: white;
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .proyecto-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            background: #f8fafc;
        }
        
        .proyecto-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
        }
        
        .proyecto-details {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            font-size: 14px;
            color: #64748b;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .detail-item i {
            width: 16px;
            color: #f59e0b;
        }
        
        .historial-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .historial-item:last-child {
            border-bottom: none;
        }
        
        .historial-info {
            flex: 1;
        }
        
        .historial-fecha {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .historial-proyecto {
            font-size: 14px;
            color: #64748b;
        }
        
        .historial-horas {
            text-align: right;
            font-size: 14px;
        }
        
        .hora-entrada {
            color: #10b981;
            margin-bottom: 2px;
        }
        
        .hora-salida {
            color: #ef4444;
        }
        
        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #bbf7d0;
        }
        
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #64748b;
        }
        
        .loading i {
            font-size: 24px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .container {
                padding: 16px;
            }
            
            .main-content {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .attendance-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .historial-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .historial-horas {
                text-align: left;
            }
        }
        
        @media (max-width: 480px) {
            .welcome-banner h2 {
                font-size: 24px;
            }
            
            .header-title h1 {
                font-size: 20px;
            }
            
            .section {
                padding: 16px;
            }
        }

        .camera-wrapper{background:#0b1b2a;color:#fff;border-radius:12px;padding:16px;margin:12px 0;display:none}
        #video,#photo-preview{width:100%;max-width:420px;border-radius:10px;box-shadow:0 6px 16px rgba(0,0,0,.25)}
        .camera-actions{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
        .btn-cam{background:#e74c3c;color:#fff;border:none;padding:10px 14px;border-radius:10px;font-weight:600;cursor:pointer}
        .btn-confirm{background:#27ae60}
        .btn-cancel{background:#7f8c8d}
        .motivo-box{display:none;margin-top:10px}
        .motivo-box input{width:100%;padding:10px;border:2px solid #ddd;border-radius:8px}
        .time-chip{background:#eef6ff;color:#1f4b99;border-radius:12px;padding:6px 10px;font-size:13px;display:inline-block}
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="header-title">
                <i class="fas fa-hard-hat"></i>
                <div>
                    <h1>Panel de Servicio Especializado</h1>
                    <div class="user-role">Control de Asistencia</div>
                </div>
            </div>
            <div class="header-user">
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="user-role"><?php echo $_SESSION['user_rol']==='responsable' ? 'Responsable' : 'Servicio Especializado'; ?></div>
                </div>
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Cerrar Sesión
                </a>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="welcome-banner">
            <h2>¡Hola, <?php echo htmlspecialchars(explode(' ', $user_name)[0]); ?>!</h2>
            <p>Registra tu asistencia diaria y mantente al día con tu proyecto</p>
        </div>

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

        <?php if (empty($proyectos)): ?>
            <div class="section">
                <div class="empty-state">
                    <i class="fas fa-briefcase"></i>
                    <h3>No tienes proyectos asignados</h3>
                    <p>Contacta a tu supervisor para que te asigne a un proyecto</p>
                </div>
            </div>
        <?php else: ?>
            <div class="main-content">
                <div class="section">
                    <h3><i class="fas fa-clock"></i> Registro de Asistencia - <?php echo date('d/m/Y', strtotime($dia_operativo)); ?></h3>
                    
                    <div class="attendance-card">
                        <div class="attendance-status" id="estadoJornada">
                            <?php if ($asistencia_hoy && $asistencia_hoy['hora_salida']): ?>
                                ⏳ Turno cerrado — próximo turno disponible a partir de <?= $siguiente_inicio_permitido_ts ? date('d/m/Y H:i', $siguiente_inicio_permitido_ts) : 'las 00:00 del siguiente día'; ?>
                            <?php elseif ($descanso_activo): ?>⏸️ En descanso
                            <?php elseif ($asistencia_hoy && $asistencia_hoy['hora_entrada']): ?>🟡 En el Trabajo
                            <?php else: ?>Sin Registro Hoy
                            <?php endif; ?>
                        </div>
                        <div class="attendance-time">
                            <?php if ($asistencia_hoy && $asistencia_hoy['hora_entrada']): ?>
                                Entrada: <?= date('H:i', strtotime($asistencia_hoy['hora_entrada'])); ?>
                            <?php endif; ?>
                            <?php if ($asistencia_hoy && $asistencia_hoy['hora_salida']): ?>
                                <br>Salida: <?= date('H:i', strtotime($asistencia_hoy['hora_salida'])); ?>
                            <?php endif; ?>
                            <div style="margin-top:8px"><span class="time-chip">Tiempo trabajado hoy: <span id="tiempoTrabajado">00:00:00</span></span></div>
                        </div>

                        <div class="attendance-buttons" id="acciones">
                            <?php if (!$asistencia_hoy || !$asistencia_hoy['hora_entrada']): ?>
                                <?php if ($bloqueo_nuevo_turno_msg): ?>
                                    <div class="alert alert-info" style="margin-bottom:8px; font-size:14px;">
                                        <i class="fas fa-clock"></i> <?= htmlspecialchars($bloqueo_nuevo_turno_msg); ?>
                                    </div>
                                    <button class="btn btn-entrada" type="button" disabled style="opacity:.6; cursor:not-allowed;"><i class="fas fa-sign-in-alt"></i> Registrar Entrada</button>
                                <?php else: ?>
                                    <button class="btn btn-entrada" onclick="accionConFoto('entrada')"><i class="fas fa-sign-in-alt"></i> Registrar Entrada</button>
                                <?php endif; ?>
                            <?php elseif ($asistencia_hoy && !$asistencia_hoy['hora_salida']): ?>
                                <?php if ($descanso_activo): ?>
                                    <button class="btn btn-entrada" onclick="accionConFoto('reanudar')"><i class="fas fa-play"></i> Reanudar</button>
                                <?php else: ?>
                                    <button class="btn btn-secondary" onclick="mostrarMotivo()"><i class="fas fa-pause"></i> Tomar Descanso</button>
                                <?php endif; ?>
                                <button class="btn btn-salida" onclick="accionConFoto('salida')"><i class="fas fa-sign-out-alt"></i> Registrar Salida</button>
                            <?php else: ?>
                                <div class="alert alert-info" style="margin: 0; font-size:14px;">
                                    <i class="fas fa-moon"></i> Turno finalizado. El siguiente podrá iniciar a partir de <?= $siguiente_inicio_permitido_ts ? date('d/m/Y H:i', $siguiente_inicio_permitido_ts) : 'las 00:00 del siguiente día'; ?>.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="motivo-box" id="motivoBox">
                            <input type="text" id="motivoDescanso" placeholder="Motivo del descanso">
                            <div class="camera-actions" style="margin-top:8px">
                                <button class="btn-cam btn-confirm" type="button" onclick="accionConFoto('descanso')">Iniciar descanso</button>
                                <button class="btn-cam btn-cancel" type="button" onclick="ocultarMotivo()">Cancelar</button>
                            </div>
                        </div>

                        <div class="camera-wrapper" id="cameraWrapper">
                            <video id="video" autoplay playsinline></video>
                            <canvas id="canvas" style="display:none"></canvas>
                            <img id="photo-preview" style="display:none" alt="Vista previa"/>
                            <div class="camera-actions">
                                <button class="btn-cam" type="button" id="btnCambiarCamara" onclick="cambiarCamara()" style="display:none">🔄 Cambiar cámara</button>
                                <button class="btn-cam" type="button" id="btnCapturar" onclick="capturarFoto()">📸 Capturar</button>
                                <button class="btn-cam btn-confirm" type="button" id="btnEnviar" style="display:none" onclick="enviarFoto()">✅ Enviar</button>
                                <button class="btn-cam btn-cancel" type="button" onclick="cerrarCamara()">❌ Cerrar</button>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($emergency_url): ?>
                    <div class="emergency-actions">
                        <a href="<?= htmlspecialchars($emergency_url); ?>" class="btn btn-emergency" target="_blank" rel="noopener">
                            <i class="fas fa-life-ring"></i> Botón de emergencia
                        </a>
                        <p class="emergency-note">Accede al protocolo con contacto 911 y tu Jefe de Proyecto.</p>
                    </div>
                    <?php endif; ?>

                    <div class="loading" id="loading" style="display:none">
                        <i class="fas fa-spinner"></i>
                        <p>Obteniendo ubicación...</p>
                    </div>
                </div>
                
                <div>
                    <div class="section" style="margin-bottom: 20px;">
                        <h3><i class="fas fa-project-diagram"></i> Mis Proyectos</h3>
                        
                        <?php foreach ($proyectos as $proyecto): ?>
                            <div class="proyecto-card">
                                <div class="proyecto-title"><?php echo htmlspecialchars($proyecto['nombre']); ?></div>
                                <div class="proyecto-details">
                                    <div class="detail-item">
                                        <i class="fas fa-building"></i>
                                        <span><?php echo htmlspecialchars($proyecto['empresa']); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?php echo htmlspecialchars($proyecto['localidad']); ?></span>
                                    </div>
                                    <?php if ($proyecto['fecha_inicio'] && $proyecto['fecha_fin']): ?>
                                        <div class="detail-item">
                                            <i class="fas fa-calendar"></i>
                                            <span>
                                                <?php echo date('d/m/Y', strtotime($proyecto['fecha_inicio'])); ?> - 
                                                <?php echo date('d/m/Y', strtotime($proyecto['fecha_fin'])); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="section" style="margin-bottom:20px;">
                        <h3><i class="fas fa-video"></i> Videos de Capacitación</h3>
                        <p style="color:#64748b; font-size:14px; margin:4px 0 12px;">Accede a los videos globales y de tus proyectos asignados.</p>
                        <a href="../common/videos.php" class="btn" style="display:inline-flex; align-items:center; gap:8px; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; padding:10px 18px; border-radius:10px; text-decoration:none; font-weight:600;">
                            <i class="fas fa-play-circle"></i> Ver Videos
                        </a>
                    </div>

                    <div class="section">
                        <h3><i class="fas fa-history"></i> Historial Reciente</h3>
                        
                        <?php if (empty($historial)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <p>No hay registros recientes</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($historial as $registro): ?>
                                <div class="historial-item">
                                    <div class="historial-info">
                                        <div class="historial-fecha"><?php echo date('d/m/Y', strtotime($registro['fecha'])); ?></div>
                                        <div class="historial-proyecto"><?php echo htmlspecialchars($registro['proyecto_nombre']); ?></div>
                                    </div>
                                    <div class="historial-horas">
                                        <?php if ($registro['hora_entrada']): ?>
                                            <div class="hora-entrada">
                                                <i class="fas fa-sign-in-alt"></i> 
                                                <?php echo date('H:i', strtotime($registro['hora_entrada'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($registro['hora_salida']): ?>
                                            <div class="hora-salida">
                                                <i class="fas fa-sign-out-alt"></i> 
                                                <?php echo date('H:i', strtotime($registro['hora_salida'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Formulario oculto para registro de asistencia -->
    <form id="formAccion" method="POST" style="display:none">
      <input type="hidden" name="accion_asistencia" id="accion_asistencia"/>
    <input type="hidden" name="proyecto_id" value="<?= $asistencia_hoy && isset($asistencia_hoy['proyecto_id']) ? (int)$asistencia_hoy['proyecto_id'] : (!empty($proyectos)? (int)$proyectos[0]['id'] : '') ?>"/>
      <input type="hidden" name="lat" id="lat"/>
      <input type="hidden" name="lng" id="lng"/>
      <input type="hidden" name="motivo" id="motivoInput"/>
    </form>

    <script src="../public/js/pwa.js"></script>
    <script>
    let stream=null, fotoBlob=null, accionActual=null; 
    let accionEnCurso=false, enviandoFoto=false;
    let availableCameras = [];
    let currentCameraIndex = 0;
    let currentDeviceId = null;
    let currentFacingMode = 'environment';
        const userId = <?= (int)$user_id ?>;
    let turnoCerradoReciente = <?= $turno_cerrado_reciente ? 'true' : 'false' ?>;
    const bloqueoNuevoTurnoMsg = <?= json_encode($bloqueo_nuevo_turno_msg); ?>;

        document.addEventListener('DOMContentLoaded', () => {
            if (window.asistenciaPWA) {
                asistenciaPWA.init();
            }
        });

        function mostrarMotivo(){ document.getElementById('motivoBox').style.display='block'; }
        function ocultarMotivo(){ document.getElementById('motivoBox').style.display='none'; document.getElementById('motivoDescanso').value=''; }

                                function accionConFoto(tipo){
                    if(accionEnCurso){
                        if(tipo === accionActual){
                            alert('Ya estamos procesando esta acción, espera un momento.');
                        }
                        return;
                    }
                    accionEnCurso = true;
                    accionActual = tipo;
                    if(tipo==='entrada' && turnoCerradoReciente){
                        alert(bloqueoNuevoTurnoMsg || 'Tu turno ya fue cerrado hoy. Podrás iniciar un nuevo turno a partir de las 00:00 del siguiente día.');
                        accionEnCurso = false;
                        return;
                    }
          if(tipo==='descanso'){
            const m = document.getElementById('motivoDescanso').value.trim();
                        if(!m){ alert('Indica el motivo del descanso'); accionEnCurso = false; return; }
            document.getElementById('motivoInput').value = m;
                    } else { document.getElementById('motivoInput').value=''; }
                    obtenerUbicacion().then(()=>iniciarCamara(true)).catch(err=>{
                        accionEnCurso = false;
                        alert(err||'No se pudo obtener ubicación');
                    });
        }

        function obtenerUbicacion(){
          return new Promise((resolve,reject)=>{
            const loading=document.getElementById('loading'); loading.style.display='block';
            if(!navigator.geolocation){ loading.style.display='none'; return reject('Tu dispositivo no soporta GPS'); }
            navigator.geolocation.getCurrentPosition(pos=>{
              document.getElementById('lat').value=pos.coords.latitude;
              document.getElementById('lng').value=pos.coords.longitude;
              loading.style.display='none'; resolve();
            }, err=>{ loading.style.display='none'; reject('Error obteniendo ubicación'); }, {enableHighAccuracy:true, timeout:15000});
          });
        }

                async function iniciarCamara(autoCapture = true){
                    try{
                        if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
                            throw new Error('Tu dispositivo no permite usar la cámara desde el navegador.');
                        }

                        if(stream){
                            stream.getTracks().forEach(t=>t.stop());
                            stream = null;
                        }

                        const constraints = obtenerRestriccionesCamara();
                        stream = await navigator.mediaDevices.getUserMedia(constraints);

                        const v=document.getElementById('video');
                        v.srcObject=stream;

                        const track = stream.getVideoTracks()[0];
                        if(track){
                            const settings = track.getSettings ? track.getSettings() : {};
                            if(settings.deviceId){ currentDeviceId = settings.deviceId; }
                            if(settings.facingMode){ currentFacingMode = settings.facingMode; }
                        }

                        await actualizarListaCamaras();

                        const wrapper = document.getElementById('cameraWrapper');
                        const preview = document.getElementById('photo-preview');
                        const btnEnviar = document.getElementById('btnEnviar');
                        const btnCapturar = document.getElementById('btnCapturar');
                        const btnCambiar = document.getElementById('btnCambiarCamara');

                        fotoBlob = null;
                        enviandoFoto = false;

                        if(wrapper){ wrapper.style.display='block'; }
                        if(preview){ preview.style.display='none'; preview.removeAttribute('src'); }
                        if(btnEnviar){
                            btnEnviar.style.display='none';
                            btnEnviar.disabled = false;
                            btnEnviar.textContent = '✅ Enviar';
                        }
                        if(btnCapturar){ btnCapturar.disabled = false; }
                        if(btnCambiar){
                            btnCambiar.style.display = 'inline-flex';
                            btnCambiar.disabled = false;
                        }

                        actualizarBotonCambiarCamara();

                        if(autoCapture){
                            setTimeout(()=>capturarFoto(),1200);
                        }
                    }catch(e){
                        console.error('No se pudo abrir la cámara', e);
                        alert('No se pudo abrir la cámara: ' + (e.message || e));
                        accionEnCurso = false;
                        stream = null;
                    }
                }

                            function obtenerRestriccionesCamara(){
                                const videoConstraints = {
                                    width: { ideal: 1280 },
                                    height: { ideal: 720 }
                                };

                                if(currentDeviceId){
                                    videoConstraints.deviceId = { exact: currentDeviceId };
                                } else if(currentFacingMode){
                                    videoConstraints.facingMode = { ideal: currentFacingMode };
                                }

                                return {
                                    audio: false,
                                    video: videoConstraints
                                };
                            }

                            async function actualizarListaCamaras(){
                                if(!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function'){
                                    availableCameras = [];
                                    return;
                                }

                                try{
                                    const devices = await navigator.mediaDevices.enumerateDevices();
                                    const videoInputs = devices.filter(device => device.kind === 'videoinput');

                                    if(videoInputs.length){
                                        availableCameras = videoInputs;
                                        if(currentDeviceId){
                                            const posicionActual = videoInputs.findIndex(device => device.deviceId === currentDeviceId);
                                            if(posicionActual >= 0){
                                                currentCameraIndex = posicionActual;
                                            } else if(currentCameraIndex >= videoInputs.length){
                                                currentCameraIndex = 0;
                                            }
                                        } else if(currentCameraIndex >= videoInputs.length){
                                            currentCameraIndex = 0;
                                        }
                                    } else {
                                        availableCameras = [];
                                    }
                                } catch(error){
                                    console.warn('No se pudieron enumerar las cámaras disponibles', error);
                                }
                            }

                            function obtenerEtiquetaCamara(dispositivo){
                                if(!dispositivo || !dispositivo.label){
                                    return '';
                                }
                                const lower = dispositivo.label.toLowerCase();
                                if(lower.includes('front') || lower.includes('frontal')){
                                    return 'cámara frontal';
                                }
                                if(lower.includes('back') || lower.includes('rear') || lower.includes('trasera') || lower.includes('environment')){
                                    return 'cámara trasera';
                                }
                                return dispositivo.label;
                            }

                            function actualizarBotonCambiarCamara(){
                                const btn = document.getElementById('btnCambiarCamara');
                                if(!btn){ return; }

                                if(!stream){
                                    btn.style.display = 'none';
                                    return;
                                }

                                btn.style.display = 'inline-flex';

                                if(!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function'){
                                    btn.disabled = false;
                                    btn.textContent = '🔄 Voltear cámara';
                                    return;
                                }

                                const total = availableCameras.length;
                                if(total <= 1){
                                    btn.disabled = false;
                                    btn.textContent = '🔄 Voltear cámara';
                                    return;
                                }

                                const siguiente = availableCameras[(currentCameraIndex + 1) % total];
                                const etiqueta = obtenerEtiquetaCamara(siguiente);
                                btn.disabled = false;
                                btn.textContent = etiqueta ? `🔄 Cambiar a ${etiqueta}` : '🔄 Cambiar cámara';
                            }

                            async function cambiarCamara(){
                                const btn = document.getElementById('btnCambiarCamara');
                                if(btn){
                                    btn.disabled = true;
                                    btn.textContent = '⏳ Cambiando...';
                                }

                                try{
                                    if(navigator.mediaDevices && typeof navigator.mediaDevices.enumerateDevices === 'function'){
                                        await actualizarListaCamaras();
                                    }

                                    if(availableCameras.length > 1){
                                        currentCameraIndex = (currentCameraIndex + 1) % availableCameras.length;
                                        currentDeviceId = availableCameras[currentCameraIndex].deviceId;
                                        const etiqueta = (obtenerEtiquetaCamara(availableCameras[currentCameraIndex]) || '').toLowerCase();
                                        if(etiqueta.includes('frontal')){
                                            currentFacingMode = 'user';
                                        } else if(etiqueta.includes('trasera') || etiqueta.includes('rear') || etiqueta.includes('back')){
                                            currentFacingMode = 'environment';
                                        }
                                    } else {
                                        currentDeviceId = null;
                                        currentFacingMode = currentFacingMode === 'environment' ? 'user' : 'environment';
                                    }

                                    await iniciarCamara(false);
                                } catch(error){
                                    console.error('No se pudo cambiar de cámara', error);
                                    alert('No se pudo cambiar de cámara. Intenta nuevamente.');
                                } finally {
                                    if(btn){
                                        btn.disabled = false;
                                        actualizarBotonCambiarCamara();
                                    }
                                }
                            }

        function capturarFoto(){
          if(!stream) return;
          const v=document.getElementById('video'); const c=document.getElementById('canvas'); const ctx=c.getContext('2d');
          c.width=v.videoWidth; c.height=v.videoHeight; ctx.drawImage(v,0,0);
          c.toBlob(b=>{ fotoBlob=b; document.getElementById('photo-preview').src=c.toDataURL('image/jpeg'); document.getElementById('photo-preview').style.display='block'; document.getElementById('btnEnviar').style.display='inline-block'; }, 'image/jpeg', 0.9);
        }

                function cerrarCamara(){
                    if(stream){ stream.getTracks().forEach(t=>t.stop()); stream=null; }
                    const wrapper = document.getElementById('cameraWrapper');
                    if(wrapper){ wrapper.style.display='none'; }
                    accionEnCurso = false;
                    fotoBlob = null;
                    enviandoFoto = false;
                    const btnEnviar = document.getElementById('btnEnviar');
                    if(btnEnviar){ btnEnviar.disabled=false; btnEnviar.textContent='✅ Enviar'; }
                    const btnCapturar = document.getElementById('btnCapturar');
                    if(btnCapturar){ btnCapturar.disabled=false; }
                    const btnCambiar = document.getElementById('btnCambiarCamara');
                    if(btnCambiar){
                        btnCambiar.style.display='none';
                        btnCambiar.disabled=false;
                        btnCambiar.textContent='🔄 Cambiar cámara';
                    }
                    const preview = document.getElementById('photo-preview');
                    if(preview){
                        preview.style.display='none';
                        preview.removeAttribute('src');
                    }
                }

        async function enviarFoto(){
                    if(enviandoFoto){ return; }
                    if(!fotoBlob){ alert('Primero captura la foto'); return; }
                    enviandoFoto = true;
                    const btnEnviar = document.getElementById('btnEnviar');
                    if(btnEnviar){ btnEnviar.disabled = true; btnEnviar.textContent = 'Enviando…'; }
          const lat=document.getElementById('lat').value; const lng=document.getElementById('lng').value;
          const proyectoId = document.querySelector('input[name="proyecto_id"]').value;
          // Opcional: obtener dirección
          let direccion='';
          try{
            const r=await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1&accept-language=es`);
            const d=await r.json(); direccion=d && d.display_name ? d.display_name : '';
          }catch{}

          // Enviar a procesador de foto
          const fd = new FormData();
          fd.append('empleado_id', userId);
          fd.append('grupo_id', proyectoId);
          fd.append('lat', lat); fd.append('lng', lng); fd.append('direccion', direccion);
          // Normalizar tipos para almacenamiento de fotos:
          // reanudar -> Reanudar (se mantiene) pero podría mapearse a Descanso fin si luego se desea
          let tipoFoto = accionActual.toLowerCase();
          // Capitalizar primera letra
          tipoFoto = tipoFoto.charAt(0).toUpperCase()+tipoFoto.slice(1);
          fd.append('tipo_asistencia', tipoFoto);
          fd.append('motivo', document.getElementById('motivoInput').value || '');
          fd.append('foto', fotoBlob, 'asistencia.jpg');

          try{
                        const res = await fetch('../public/procesar_foto_asistencia.php', { method:'POST', body: fd });
                        const json = await res.json();
                        if(!res.ok || !json.success){ console.error(json); alert('Error guardando foto de asistencia'); enviandoFoto=false; if(btnEnviar){ btnEnviar.disabled=false; btnEnviar.textContent='✅ Enviar'; } return; }
                    }catch(e){
                        console.error(e);
                        if(!navigator.onLine && window.asistenciaPWA){
                            try{
                                const motivoValor = document.getElementById('motivoInput').value || '';
                                await asistenciaPWA.queueSubmissionFromData({
                                    url: '../public/procesar_foto_asistencia.php',
                                    method: 'POST',
                                    fields: {
                                        empleado_id: userId,
                                        grupo_id: proyectoId,
                                        lat,
                                        lng,
                                        direccion,
                                        tipo_asistencia: tipoFoto,
                                        motivo: motivoValor
                                    },
                                    foto: fotoBlob,
                                    filename: `asistencia-${userId}-${Date.now()}.jpg`
                                });

                                await asistenciaPWA.queueSubmissionFromData({
                                    url: window.location.href,
                                    method: 'POST',
                                    fields: {
                                        accion_asistencia: accionActual,
                                        proyecto_id: proyectoId,
                                        lat,
                                        lng,
                                        motivo: motivoValor
                                    }
                                });

                                alert('📦 Registro guardado sin conexión. Lo enviaremos automáticamente cuando vuelva la red.');
                                cerrarCamara();
                                accionEnCurso = false;
                                enviandoFoto = false;
                                return;
                            }catch(queueErr){
                                console.error('No se pudo guardar la acción offline', queueErr);
                            }
                        }
                        alert('Error conectando con el procesador de fotos');
                        enviandoFoto=false;
                        if(btnEnviar){ btnEnviar.disabled=false; btnEnviar.textContent='✅ Enviar'; }
                        return;
                    }

          // Registrar evento en BD de asistencia/descansos
          cerrarCamara();
          document.getElementById('accion_asistencia').value = accionActual;
          document.getElementById('formAccion').submit();
        }

        // Timer de tiempo trabajado (robusto)
        (function initTimer(){
            let entradaMs = <?= $asistencia_hoy && $asistencia_hoy['hora_entrada'] ? (responsable_parse_datetime($asistencia_hoy['fecha'] ?? $dia_operativo, $asistencia_hoy['hora_entrada']) * 1000) : 'null' ?>;
            let salidaMs = <?= $asistencia_hoy && $asistencia_hoy['hora_salida'] ? (responsable_parse_datetime($asistencia_hoy['fecha'] ?? $dia_operativo, $asistencia_hoy['hora_salida']) * 1000) : 'null' ?>;
            const descansos = <?= json_encode(array_map(function($d){ return [ 'inicio_ms'=> $d['inicio']? (strtotime($d['inicio'])*1000) : null, 'fin_ms'=> $d['fin']? (strtotime($d['fin'])*1000) : null ]; }, $descansos_hoy)); ?>;
            const target = document.getElementById('tiempoTrabajado');
            const workedBootstrap = <?= (int)$tiempo_trabajado_inicial ?>;
            const pageLoadMs = Date.now();
            function fmt(s){ if(!isFinite(s)||s<0) s=0; const h=String(Math.floor(s/3600)).padStart(2,'0');const m=String(Math.floor((s%3600)/60)).padStart(2,'0');const sec=String(s%60).padStart(2,'0');return `${h}:${m}:${sec}` }
            if(!target) return;
            if(workedBootstrap>0){ target.textContent = fmt(workedBootstrap); }
            if(!entradaMs){ target.textContent = fmt(workedBootstrap); return; }
            function calc(){
                try {
                    const nowMs = Date.now();
                    // Corrección si la entrada está en el futuro (desfase horario guardado en BD)
                    if(entradaMs && entradaMs - nowMs > 3600*1000 && entradaMs - nowMs < 13*3600*1000){
                        const diffH = Math.round((entradaMs - nowMs)/3600000);
                        entradaMs -= diffH*3600000; // desplazar hacia atrás
                        if(salidaMs) salidaMs -= diffH*3600000;
                        // Ajustar también descansos capturados en ese rango
                        if(Array.isArray(descansos)){
                            descansos.forEach(d=>{ if(d.inicio_ms) d.inicio_ms -= diffH*3600000; if(d.fin_ms) d.fin_ms -= diffH*3600000; });
                        }
                    }
                    const endMs = salidaMs || nowMs;
                    if(endMs < entradaMs){ target.textContent='00:00:00'; return; }
                    let total = Math.floor((endMs - entradaMs)/1000);
                    let resta = 0;
                    if(Array.isArray(descansos)){
                        descansos.forEach(d=>{
                            const di = d && d.inicio_ms; if(!di) return;
                            const df = d.fin_ms ? d.fin_ms : (salidaMs || nowMs);
                            if(df>di) resta += Math.floor((df-di)/1000);
                        });
                    }
                    let worked = total - resta; if(!isFinite(worked)||worked<0) worked=0;
                    if(!salidaMs && worked < workedBootstrap){
                        // Evita retrocesos por desfases de servidor; añadimos progreso desde la carga
                        const delta = Math.max(0, Math.floor((nowMs - pageLoadMs)/1000));
                        worked = workedBootstrap + delta;
                    }
                    target.textContent = fmt(worked);
                } catch(err){ target.textContent='00:00:00'; }
            }
            calc(); setInterval(calc,1000);
        })();
    </script>
</body>
</html>
