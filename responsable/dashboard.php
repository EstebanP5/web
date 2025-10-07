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
        $valor = $hora;
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $hora)) {
            $valor = trim(($fecha ?: date('Y-m-d')) . ' ' . $hora);
        }
        $timestamp = strtotime($valor);
        return $timestamp !== false ? $timestamp : null;
    }
}

if (!function_exists('responsable_puede_reabrir')) {
    function responsable_puede_reabrir(?string $fecha, ?string $hora): bool {
        return responsable_parse_datetime($fecha, $hora) !== null;
    }
}

if (!function_exists('responsable_estado_reapertura')) {
    function responsable_estado_reapertura(?array $asistencia, string $diaOperativo): bool {
        if ($asistencia && !empty($asistencia['hora_salida'])) {
            return responsable_parse_datetime($asistencia['fecha'] ?? $diaOperativo, $asistencia['hora_salida']) !== null;
        }
        return false;
    }
}
$reapertura_disponible = false;

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
$asistencia_hoy = null; $ultimo_registro = null; $turno_cerrado_reciente = false;
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

$reapertura_disponible = responsable_estado_reapertura($asistencia_hoy, $dia_operativo);

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
                $mensaje_error = '❌ Tu último turno se cerró hace menos de 12 horas. Reabre el turno para continuar.';
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
        } elseif ($tipo === 'reabrir') {
            // Reabrir turno: cubrir la pausa entre salida previa y ahora con un descanso, luego limpiar hora_salida
            $stmt = $conn->prepare("SELECT fecha, hora_salida FROM asistencia WHERE empleado_id = ? AND proyecto_id = ? AND fecha = ? AND hora_salida IS NOT NULL ORDER BY hora_salida DESC LIMIT 1");
            $stmt->bind_param('iis', $user_id, $proyecto_id, $dia_operativo);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            if ($row && !empty($row['hora_salida'])) {
                if (!responsable_puede_reabrir($row['fecha'] ?? $dia_operativo, $row['hora_salida'])) {
                    $mensaje_error = '❌ No se pudo reabrir el turno. Falta información de la salida.';
                } else {
                $hora_salida_prev = $row['hora_salida'];
                $fecha_registro = $row['fecha'] ?? $dia_operativo;
                $inicio_descanso = $hora_salida_prev;
                // Si hora_salida es solo tiempo, completar con la fecha
                if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $hora_salida_prev)) {
                    $inicio_descanso = $fecha_registro . ' ' . $hora_salida_prev;
                }
                $inicio_timestamp = strtotime($inicio_descanso);
                if ($inicio_timestamp === false) {
                    $inicio_descanso = $fecha_registro . ' ' . date('H:i:s');
                    $inicio_timestamp = strtotime($inicio_descanso);
                }
                $inicio_descanso = date('Y-m-d H:i:s', $inicio_timestamp);

                // Evitar duplicar descansos por reapertura seguidos
                $motivo_gap = 'Reapertura de turno';
                $stmtCheck = $conn->prepare("SELECT id FROM descansos WHERE empleado_id = ? AND proyecto_id = ? AND fecha = ? AND motivo = ? ORDER BY inicio DESC LIMIT 1");
                $stmtCheck->bind_param('iiss', $user_id, $proyecto_id, $dia_operativo, $motivo_gap);
                $stmtCheck->execute();
                $existing = $stmtCheck->get_result()->fetch_assoc();

                if ($existing) {
                    $stmtUpdateBreak = $conn->prepare("UPDATE descansos SET inicio = ?, fin = NOW() WHERE id = ?");
                    $stmtUpdateBreak->bind_param('si', $inicio_descanso, $existing['id']);
                    $stmtUpdateBreak->execute();
                } else {
                    $stmtD = $conn->prepare("INSERT INTO descansos (empleado_id, proyecto_id, fecha, inicio, fin, motivo) VALUES (?, ?, ?, ?, NOW(), ?)");
                    $stmtD->bind_param('iisss', $user_id, $proyecto_id, $dia_operativo, $inicio_descanso, $motivo_gap);
                    $stmtD->execute();
                }

                $stmtU = $conn->prepare("UPDATE asistencia SET hora_salida = NULL, lat_salida = NULL, lon_salida = NULL WHERE empleado_id = ? AND proyecto_id = ? AND fecha = ?");
                $stmtU->bind_param('iis', $user_id, $proyecto_id, $dia_operativo);
                if ($stmtU->execute()) { $mensaje_exito = '🔓 Turno reabierto. Puedes continuar con tu jornada.'; } else { $mensaje_error = '❌ No se pudo reabrir el turno.'; }
                }
            } else {
                $mensaje_error = '❌ No hay turno cerrado para reabrir.';
            }
        }

    // Refrescar estado del día operativo (antes usaba $hoy que no existe -> no actualizaba la entrada)
    $stmt = $conn->prepare("SELECT * FROM asistencia WHERE empleado_id = ? AND fecha = ? AND proyecto_id = ?");
    $stmt->bind_param('isi', $user_id, $dia_operativo, $proyecto_id);
    $stmt->execute();
    $asistencia_hoy = $stmt->get_result()->fetch_assoc();

    $reapertura_disponible = responsable_estado_reapertura($asistencia_hoy, $dia_operativo);
    $turno_cerrado_reciente = ($asistencia_hoy && !empty($asistencia_hoy['hora_salida']));
    }
}

// Estado de descanso actual y sumatoria de descansos de hoy
$descanso_activo = null;
$descansos_hoy = [];
if (!empty($proyectos)) {
    $pid = $asistencia_hoy && isset($asistencia_hoy['proyecto_id']) ? (int)$asistencia_hoy['proyecto_id'] : (int)$proyectos[0]['id'];
    $rs = $conn->prepare("SELECT * FROM descansos WHERE empleado_id = ? AND proyecto_id = ? AND fecha = ? ORDER BY inicio ASC");
    $rs->bind_param('iis', $user_id, $pid, $dia_operativo);
    $rs->execute();
    $descansos_hoy = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($descansos_hoy as $d) { if ($d['fin'] === null) { $descanso_activo = $d; } }
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
    <title>Panel de Servicio Especializado - Ergo PMS</title>
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

        .reopen-info {
            margin-top: 16px;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.18);
            padding: 8px 12px;
            border-radius: 999px;
        }

        .reopen-info.locked {
            background: rgba(239, 68, 68, 0.18);
            color: #fee2e2;
        }

        .reopen-countdown {
            font-weight: 600;
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
                                ⏳ Turno cerrado — puedes reabrir cuando lo necesites
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
                                <button class="btn btn-entrada" onclick="accionConFoto('entrada')"><i class="fas fa-sign-in-alt"></i> Registrar Entrada</button>
                            <?php elseif ($asistencia_hoy && !$asistencia_hoy['hora_salida']): ?>
                                <?php if ($descanso_activo): ?>
                                    <button class="btn btn-entrada" onclick="accionConFoto('reanudar')"><i class="fas fa-play"></i> Reanudar</button>
                                <?php else: ?>
                                    <button class="btn btn-secondary" onclick="mostrarMotivo()"><i class="fas fa-pause"></i> Tomar Descanso</button>
                                <?php endif; ?>
                                <button class="btn btn-salida" onclick="accionConFoto('salida')"><i class="fas fa-sign-out-alt"></i> Registrar Salida</button>
                            <?php else: ?>
                                        <button class="btn btn-secondary" onclick="accionConFoto('reabrir')"><i class="fas fa-unlock"></i> Reabrir turno</button>
                            <?php endif; ?>
                        </div>

                        <?php if ($asistencia_hoy && $asistencia_hoy['hora_salida']): ?>
                            <div class="reopen-info">
                                <i class="fas fa-clock"></i>
                                Puedes reabrir tu turno en cualquier momento si lo cerraste por error.
                            </div>
                        <?php endif; ?>

                        <div class="motivo-box" id="motivoBox">
                            <input type="text" id="motivoDescanso" placeholder="Motivo del descanso">
                            <div class="camera-actions" style="margin-top:8px">
                                <button class="btn-cam btn-confirm" onclick="accionConFoto('descanso')">Iniciar descanso</button>
                                <button class="btn-cam btn-cancel" onclick="ocultarMotivo()">Cancelar</button>
                            </div>
                        </div>

                        <div class="camera-wrapper" id="cameraWrapper">
                            <video id="video" autoplay playsinline></video>
                            <canvas id="canvas" style="display:none"></canvas>
                            <img id="photo-preview" style="display:none" alt="Vista previa"/>
                            <div class="camera-actions">
                                <button class="btn-cam" id="btnCapturar" onclick="capturarFoto()">📸 Capturar</button>
                                <button class="btn-cam btn-confirm" id="btnEnviar" style="display:none" onclick="enviarFoto()">✅ Enviar</button>
                                <button class="btn-cam btn-cancel" onclick="cerrarCamara()">❌ Cerrar</button>
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

    <script>
    let stream=null, fotoBlob=null, accionActual=null; 
    let accionEnCurso=false, enviandoFoto=false;
        const userId = <?= (int)$user_id ?>;
    let turnoCerradoReciente = <?= $turno_cerrado_reciente ? 'true' : 'false' ?>;
    let reaperturaDisponibleJs = <?= $reapertura_disponible ? 'true' : 'false' ?>;

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
                        alert('Tu último turno se cerró. Reabre el turno para continuar.');
                        accionEnCurso = false;
                        return;
                    }
                    if(tipo==='reabrir' && !reaperturaDisponibleJs){
                        alert('No hay un turno cerrado disponible para reabrir.');
                        accionEnCurso = false;
                        return;
                    }
          if(tipo==='descanso'){
            const m = document.getElementById('motivoDescanso').value.trim();
                        if(!m){ alert('Indica el motivo del descanso'); accionEnCurso = false; return; }
            document.getElementById('motivoInput').value = m;
                    } else { document.getElementById('motivoInput').value=''; }
                    obtenerUbicacion().then(()=>iniciarCamara()).catch(err=>{
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

        async function iniciarCamara(){
          try{
            stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});
            const v=document.getElementById('video'); v.srcObject=stream;
            document.getElementById('cameraWrapper').style.display='block';
            document.getElementById('photo-preview').style.display='none';
            document.getElementById('btnEnviar').style.display='none';
                        const btnEnviar = document.getElementById('btnEnviar');
                        if(btnEnviar){ btnEnviar.disabled=false; btnEnviar.textContent='✅ Enviar'; }
            // Auto-captura a los 1.2s
            setTimeout(()=>capturarFoto(),1200);
          }catch(e){ alert('No se pudo abrir la cámara: '+e.message); accionEnCurso = false; }
        }

        function capturarFoto(){
          if(!stream) return;
          const v=document.getElementById('video'); const c=document.getElementById('canvas'); const ctx=c.getContext('2d');
          c.width=v.videoWidth; c.height=v.videoHeight; ctx.drawImage(v,0,0);
          c.toBlob(b=>{ fotoBlob=b; document.getElementById('photo-preview').src=c.toDataURL('image/jpeg'); document.getElementById('photo-preview').style.display='block'; document.getElementById('btnEnviar').style.display='inline-block'; }, 'image/jpeg', 0.9);
        }

                function cerrarCamara(){
                    if(stream){ stream.getTracks().forEach(t=>t.stop()); stream=null; }
                    document.getElementById('cameraWrapper').style.display='none';
                    accionEnCurso = false;
                    fotoBlob = null;
                    enviandoFoto = false;
                    const btnEnviar = document.getElementById('btnEnviar');
                    if(btnEnviar){ btnEnviar.disabled=false; btnEnviar.textContent='✅ Enviar'; }
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
          // reabrir -> Entrada (se considera nueva entrada visual)
          // reanudar -> Reanudar (se mantiene) pero podría mapearse a Descanso fin si luego se desea
          let tipoFoto = accionActual.toLowerCase();
          if(tipoFoto==='reabrir') tipoFoto='entrada';
          // Capitalizar primera letra
          tipoFoto = tipoFoto.charAt(0).toUpperCase()+tipoFoto.slice(1);
          fd.append('tipo_asistencia', tipoFoto);
          fd.append('motivo', document.getElementById('motivoInput').value || '');
          fd.append('foto', fotoBlob, 'asistencia.jpg');

          try{
                        const res = await fetch('../public/procesar_foto_asistencia.php', { method:'POST', body: fd });
                        const json = await res.json();
                        if(!res.ok || !json.success){ console.error(json); alert('Error guardando foto de asistencia'); enviandoFoto=false; if(btnEnviar){ btnEnviar.disabled=false; btnEnviar.textContent='✅ Enviar'; } return; }
                    }catch(e){ console.error(e); alert('Error conectando con el procesador de fotos'); enviandoFoto=false; if(btnEnviar){ btnEnviar.disabled=false; btnEnviar.textContent='✅ Enviar'; } return; }

          // Registrar evento en BD de asistencia/descansos
          cerrarCamara();
          document.getElementById('accion_asistencia').value = accionActual;
          document.getElementById('formAccion').submit();
        }

            // Reabrir ahora usa la misma captura con foto mediante accionConFoto('reabrir')

        // Timer de tiempo trabajado (robusto)
        (function initTimer(){
            let entradaMs = <?= $asistencia_hoy && $asistencia_hoy['hora_entrada'] ? (responsable_parse_datetime($asistencia_hoy['fecha'] ?? $dia_operativo, $asistencia_hoy['hora_entrada']) * 1000) : 'null' ?>;
            let salidaMs = <?= $asistencia_hoy && $asistencia_hoy['hora_salida'] ? (responsable_parse_datetime($asistencia_hoy['fecha'] ?? $dia_operativo, $asistencia_hoy['hora_salida']) * 1000) : 'null' ?>;
            const descansos = <?= json_encode(array_map(function($d){ return [ 'inicio_ms'=> $d['inicio']? (strtotime($d['inicio'])*1000) : null, 'fin_ms'=> $d['fin']? (strtotime($d['fin'])*1000) : null ]; }, $descansos_hoy)); ?>;
            const target = document.getElementById('tiempoTrabajado');
            function fmt(s){ if(!isFinite(s)||s<0) s=0; const h=String(Math.floor(s/3600)).padStart(2,'0');const m=String(Math.floor((s%3600)/60)).padStart(2,'0');const sec=String(s%60).padStart(2,'0');return `${h}:${m}:${sec}` }
            if(!target) return;
            if(!entradaMs){ target.textContent='00:00:00'; return; }
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
                    target.textContent = fmt(worked);
                } catch(err){ target.textContent='00:00:00'; }
            }
            calc(); setInterval(calc,1000);
        })();
    </script>
</body>
</html>
