<?php
session_start();
require_once '../includes/db.php';
require_once '../vendor/autoload.php';

use Smalot\PdfParser\Parser;

// Verificar autenticación y rol responsable
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'responsable') {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$mensaje_exito = '';
$mensaje_error = '';

// Obtener empresa del responsable AL INICIO
$stmt = $conn->prepare('SELECT empresa FROM empresas_responsables WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$responsableData = $stmt->get_result()->fetch_assoc();
$stmt->close();

$empresa_responsable = $responsableData ? $responsableData['empresa'] : '';

// Función para limpiar nombre
function suaCleanNombre($nombre){
    $nombre = trim(preg_replace('/\s+/', ' ', $nombre));
    if ($nombre === '') return $nombre;
    $tokens = explode(' ', $nombre);
    $i = 0;
    while (count($tokens) > 2 && $i < 3) {
        $last = end($tokens);
        if (strlen($last) === 1 && in_array($last, ['R', 'A'])) {
            array_pop($tokens);
            $i++;
        } else {
            break;
        }
    }
    return implode(' ', $tokens);
}

// Función para extraer fecha del PDF
function extraerFechaProceso($texto) {
    if (preg_match('/Fecha\s+de\s+Proceso\s*[:\-]?\s*(\d{2})\/(\d{2})\/(\d{4})/i', $texto, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $texto, $m)) {
        return $m[0];
    }
    if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $texto, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    return date('Y-m-d');
}

// Función para extraer empleados del texto del PDF (igual que admin)
function extraerEmpleadosDePDF($texto) {
    $originalTexto = $texto;
    $resultadoLineas = [];
    
    $lineas = preg_split('/\r?\n|\f/', $texto);
    $patNSS = '/^(\s*)(\d{2}-\d{2}-\d{2}-\d{4}-\d)(.*)$/';
    $patCURP = '/[A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d/';
    
    foreach ($lineas as $ln) {
        if (preg_match($patNSS, $ln, $mN)) {
            $nss = $mN[2];
            if (preg_match($patCURP, $ln, $mC)) {
                $curp = $mC[0];
                $entre = substr($ln, strpos($ln, $nss) + strlen($nss));
                $entre = substr($entre, 0, strpos($entre, $curp));
                $entre = str_replace(['Reing', 'Reing.', 'Baja', 'Alta'], ' ', $entre);
                $entre = preg_replace('/\b[0-9]{1,4}(?:\.[0-9]{1,2})?\b/', ' ', $entre);
                $entre = preg_replace('/[^A-ZÁÉÍÓÚÑ ]/iu', ' ', $entre);
                $entre = strtoupper(trim(preg_replace('/\s+/', ' ', $entre)));
                if (strlen($entre) >= 5 && strlen($curp) === 18) {
                    $resultadoLineas[$nss] = ['nss' => $nss, 'nombre' => $entre, 'curp' => strtoupper($curp)];
                }
            }
        }
    }
    
    $t = preg_replace('/[\r\n\t]+/', ' ', $texto);
    $t = preg_replace('/\s+/', ' ', $t);
    $t = preg_replace('/\b(Reing|Reing\.|Baja|Alta)\b/iu', ' ', $t);
    
    $resultado = [];
    $add = function($nss, $nombre, $curp, &$resultado) {
        $nss = trim($nss);
        $curp = trim($curp);
        $nombre = trim(preg_replace('/\s+/', ' ', $nombre));
        $nombre = strtoupper(preg_replace('/[^A-ZÁÉÍÓÚÑ\s]/u', '', $nombre));
        $nombre = trim(preg_replace('/\b(REING|REING\.|BAJA|ALTA)\b/u', '', $nombre));
        if (strlen($curp) !== 18) return;
        if (strlen($nombre) < 5 || strlen($nombre) > 90) return;
        if (!preg_match('/^\d{2}-\d{2}-\d{2}-\d{4}-\d$/', $nss)) return;
        $resultado[$nss] = ['nss' => $nss, 'nombre' => suaCleanNombre($nombre), 'curp' => $curp];
    };
    
    // Patrón principal
    $patPrincipal = '/([0-9]{2}-[0-9]{2}-[0-9]{2}-[0-9]{4}-[0-9])\s+([A-ZÁÉÍÓÚÑ ]+?)\s+([A-Z]{4}[0-9]{6}[A-Z0-9]{6}[A-Z0-9][0-9])/iu';
    if (preg_match_all($patPrincipal, $t, $m, PREG_SET_ORDER)) {
        foreach ($m as $coinc) {
            $add($coinc[1], $coinc[2], $coinc[3], $resultado);
        }
    }
    
    // Fallback
    if (empty($resultado)) {
        $patNSS2 = '/\d{2}-\d{2}-\d{2}-\d{4}-\d/';
        $patCURP2 = '/[A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d/i';
        preg_match_all($patNSS2, $t, $nssLista);
        $partes = preg_split($patNSS2, $t, -1, PREG_SPLIT_DELIM_CAPTURE);
        $nsss = $nssLista[0];
        for ($i = 1; $i < count($partes); $i++) {
            if (!isset($nsss[$i - 1])) continue;
            $nss = $nsss[$i - 1];
            $segmento = $partes[$i];
            if (preg_match($patCURP2, $segmento, $cMatch)) {
                $curp = $cMatch[0];
                $nombreParte = preg_replace('/' . preg_quote($curp, '/') . '/', '', $segmento, 1);
                $add($nss, $nombreParte, $curp, $resultado);
            }
        }
    }
    
    // Pasada universal
    $patNSSAll = '/\d{2}-\d{2}-\d{2}-\d{4}-\d/';
    $patCURPAll = '/[A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d/';
    if (preg_match_all($patNSSAll, $t, $nsssAll, PREG_OFFSET_CAPTURE)) {
        foreach ($nsssAll[0] as $data) {
            $nss = $data[0];
            if (isset($resultado[$nss])) continue;
            $offset = $data[1];
            $segmento = substr($t, $offset, 320);
            if (preg_match($patCURPAll, $segmento, $curpMatch, PREG_OFFSET_CAPTURE)) {
                $curp = $curpMatch[0][0];
                $nombreBruto = substr($segmento, strlen($nss), $curpMatch[0][1] - strlen($nss));
                $nombreBruto = preg_replace('/\b(REING|REING\.|BAJA|ALTA)\b/', ' ', $nombreBruto);
                $nombreBruto = preg_replace('/[0-9.,]+/', ' ', $nombreBruto);
                $nombreBruto = strtoupper(preg_replace('/[^A-ZÁÉÍÓÚÑ\s]/u', ' ', $nombreBruto));
                $nombre = trim(preg_replace('/\s+/', ' ', $nombreBruto));
                if ($nombre && strlen($curp) === 18 && !isset($resultado[$nss])) {
                    $resultado[$nss] = ['nss' => $nss, 'nombre' => suaCleanNombre($nombre), 'curp' => $curp];
                }
            }
        }
    }
    
    foreach ($resultadoLineas as $k => $v) {
        $resultado[$k] = $v;
    }
    
    return array_values($resultado);
}

// Asegurar tablas necesarias (sincronizado con admin)
$conn->query("CREATE TABLE IF NOT EXISTS empresas_responsables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    empresa VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_empresa (user_id, empresa),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Asegurar que sua_lotes tenga columnas empresa y uploaded_by
$conn->query("CREATE TABLE IF NOT EXISTS sua_lotes (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    fecha_proceso DATE NOT NULL, 
    archivo VARCHAR(255) NOT NULL, 
    total INT NOT NULL DEFAULT 0, 
    empresa VARCHAR(100) DEFAULT NULL,
    uploaded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
    INDEX idx_fecha_proceso (fecha_proceso),
    INDEX idx_empresa (empresa),
    INDEX idx_uploaded_by (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Agregar columnas si no existen (para tablas existentes)
$result = $conn->query("SHOW COLUMNS FROM sua_lotes LIKE 'empresa'");
if ($result->num_rows === 0) {
    $conn->query("ALTER TABLE sua_lotes ADD COLUMN empresa VARCHAR(100) DEFAULT NULL");
}
$result = $conn->query("SHOW COLUMNS FROM sua_lotes LIKE 'uploaded_by'");
if ($result->num_rows === 0) {
    $conn->query("ALTER TABLE sua_lotes ADD COLUMN uploaded_by INT DEFAULT NULL");
}

$conn->query("CREATE TABLE IF NOT EXISTS sua_empleados (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    lote_id INT NOT NULL, 
    nss VARCHAR(25) NOT NULL, 
    nombre VARCHAR(150) NOT NULL, 
    curp VARCHAR(25) NOT NULL,
    empresa VARCHAR(100) DEFAULT NULL,
    UNIQUE KEY uniq_lote_nss (lote_id, nss)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Verificar empresa asignada
if (empty($empresa_responsable)) {
    $mensaje_error = '❌ No tienes una empresa asignada. Contacta al administrador.';
}

// Procesar eliminación de lotes seleccionados
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_lotes']) && !empty($_POST['lotes_sel'])) {
    $lotes_sel = $_POST['lotes_sel'];
    $eliminados = 0;
    foreach ($lotes_sel as $lote_id) {
        $lote_id = (int)$lote_id;
        // Verificar que el lote pertenece a este usuario
        $stmt = $conn->prepare('SELECT id, archivo FROM sua_lotes WHERE id = ? AND uploaded_by = ?');
        $stmt->bind_param('ii', $lote_id, $user_id);
        $stmt->execute();
        $lote = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($lote) {
            // Eliminar archivo físico si existe
            $archivo_path = '../uploads/' . $lote['archivo'];
            if (file_exists($archivo_path)) {
                @unlink($archivo_path);
            }
            // Eliminar de BD
            $conn->query("DELETE FROM sua_empleados WHERE lote_id = " . $lote_id);
            $conn->query("DELETE FROM sua_lotes WHERE id = " . $lote_id);
            $eliminados++;
        }
    }
    if ($eliminados > 0) {
        $mensaje_exito = "✅ Se eliminaron $eliminados lote(s) correctamente.";
    }
}

// Procesar subida de PDF SUA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sua_pdf']) && $empresa_responsable) {
    if ($_FILES['sua_pdf']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['sua_pdf']['tmp_name'];
        $orig = $_FILES['sua_pdf']['name'];
        
        if (strtolower(pathinfo($orig, PATHINFO_EXTENSION)) !== 'pdf') {
            $mensaje_error = '❌ Solo se permiten archivos PDF.';
        } else {
            $dir = '../uploads/';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $dest = $dir . 'sua_' . date('Y-m-d_H-i-s') . '_' . $user_id . '.pdf';
            
            if (move_uploaded_file($tmp, $dest)) {
                try {
                    $parser = new Parser();
                    $pdf = $parser->parseFile($dest);
                    $texto = $pdf->getText();
                    
                    $fecha_proceso = extraerFechaProceso($texto);
                    $empleados_extraidos = extraerEmpleadosDePDF($texto);
                    
                    if (empty($empleados_extraidos)) {
                        $mensaje_error = '❌ No se detectaron empleados en el PDF.';
                        @unlink($dest);
                    } else {
                        // Registrar lote con empresa y uploaded_by
                        $stmt = $conn->prepare('INSERT INTO sua_lotes (fecha_proceso, archivo, total, empresa, uploaded_by) VALUES (?, ?, ?, ?, ?)');
                        $total = count($empleados_extraidos);
                        $archivo = basename($dest);
                        $stmt->bind_param('ssisi', $fecha_proceso, $archivo, $total, $empresa_responsable, $user_id);
                        $stmt->execute();
                        $lote_id = $stmt->insert_id;
                        $stmt->close();
                        
                        // Insertar empleados del lote
                        $insertados = 0;
                        $actualizados = 0;
                        
                        foreach ($empleados_extraidos as $emp) {
                            // Insertar en sua_empleados
                            $stmtE = $conn->prepare('INSERT IGNORE INTO sua_empleados (lote_id, nss, nombre, curp, empresa) VALUES (?, ?, ?, ?, ?)');
                            $stmtE->bind_param('issss', $lote_id, $emp['nss'], $emp['nombre'], $emp['curp'], $empresa_responsable);
                            $stmtE->execute();
                            $stmtE->close();
                            
                            // Verificar si el empleado ya existe en tabla empleados
                            $stmtCheck = $conn->prepare('SELECT id FROM empleados WHERE nss = ? LIMIT 1');
                            $stmtCheck->bind_param('s', $emp['nss']);
                            $stmtCheck->execute();
                            $existente = $stmtCheck->get_result()->fetch_assoc();
                            $stmtCheck->close();
                            
                            if ($existente) {
                                // Actualizar empleado existente
                                $stmtUp = $conn->prepare('UPDATE empleados SET nombre = ?, curp = ?, empresa = ?, puesto = "Servicio Especializado", activo = 1, bloqueado = 0 WHERE id = ?');
                                $stmtUp->bind_param('sssi', $emp['nombre'], $emp['curp'], $empresa_responsable, $existente['id']);
                                $stmtUp->execute();
                                $stmtUp->close();
                                $actualizados++;
                            } else {
                                // Crear nuevo usuario y empleado
                                $nss_clean = str_replace('-', '', $emp['nss']);
                                $email = $nss_clean . '@trabajador.local';
                                $pwdPlain = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
                                $pwdHash = password_hash($pwdPlain, PASSWORD_DEFAULT);
                                
                                $stmtUser = $conn->prepare("INSERT INTO users (name, email, password, password_visible, rol, activo) VALUES (?, ?, ?, ?, 'servicio_especializado', 1)");
                                $stmtUser->bind_param('ssss', $emp['nombre'], $email, $pwdHash, $pwdPlain);
                                
                                if ($stmtUser->execute()) {
                                    $userId = $stmtUser->insert_id;
                                    $stmtUser->close();
                                    
                                    $stmtEmp = $conn->prepare('INSERT INTO empleados (id, nombre, nss, curp, empresa, puesto, activo, bloqueado, fecha_registro) VALUES (?, ?, ?, ?, ?, "Servicio Especializado", 1, 0, NOW())');
                                    $stmtEmp->bind_param('issss', $userId, $emp['nombre'], $emp['nss'], $emp['curp'], $empresa_responsable);
                                    $stmtEmp->execute();
                                    $stmtEmp->close();
                                    $insertados++;
                                } else {
                                    $stmtUser->close();
                                }
                            }
                        }
                        
                        // ========================================
                        // BLOQUEO AUTOMÁTICO DE NO RENOVADOS
                        // ========================================
                        // Obtener lista de NSS del SUA procesado
                        $nssSUA = array_column($empleados_extraidos, 'nss');
                        $totalBloqueados = 0;
                        
                        if (!empty($nssSUA)) {
                            // Buscar empleados activos de esta empresa que NO están en el SUA actual
                            $placeholders = implode(',', array_fill(0, count($nssSUA), '?'));
                            $empresa_lower = strtolower($empresa_responsable);
                            
                            $sqlBloquear = "SELECT id, nombre FROM empleados 
                                WHERE LOWER(empresa) = ? 
                                AND activo = 1 
                                AND bloqueado = 0 
                                AND nss IS NOT NULL 
                                AND nss <> '' 
                                AND nss NOT IN ($placeholders)";
                            
                            $params = array_merge([$empresa_lower], $nssSUA);
                            $types = str_repeat('s', count($params));
                            
                            $stmtBloquear = $conn->prepare($sqlBloquear);
                            if ($stmtBloquear) {
                                $stmtBloquear->bind_param($types, ...$params);
                                $stmtBloquear->execute();
                                $resultBloquear = $stmtBloquear->get_result();
                                
                                $idsBloquear = [];
                                while ($row = $resultBloquear->fetch_assoc()) {
                                    $idsBloquear[] = (int)$row['id'];
                                }
                                $stmtBloquear->close();
                                
                                // Bloquear y desasignar en lote
                                if (!empty($idsBloquear)) {
                                    $placeholdersIds = implode(',', array_fill(0, count($idsBloquear), '?'));
                                    $typesIds = str_repeat('i', count($idsBloquear));
                                    
                                    // 1. Bloquear empleados y marcarlos como inactivos
                                    $stmtBloquearEmpleados = $conn->prepare("UPDATE empleados SET bloqueado = 1, activo = 0 WHERE id IN ($placeholdersIds)");
                                    if ($stmtBloquearEmpleados) {
                                        $stmtBloquearEmpleados->bind_param($typesIds, ...$idsBloquear);
                                        $stmtBloquearEmpleados->execute();
                                        $totalBloqueados = $stmtBloquearEmpleados->affected_rows;
                                        $stmtBloquearEmpleados->close();
                                    }
                                    
                                    // 2. Desasignar de todos los proyectos
                                    $stmtDesasignar = $conn->prepare("UPDATE empleado_proyecto SET activo = 0 WHERE empleado_id IN ($placeholdersIds)");
                                    if ($stmtDesasignar) {
                                        $stmtDesasignar->bind_param($typesIds, ...$idsBloquear);
                                        $stmtDesasignar->execute();
                                        $stmtDesasignar->close();
                                    }
                                }
                            }
                        }
                        
                        $mensajeBase = "✅ PDF procesado: $total empleados detectados ($insertados nuevos, $actualizados actualizados).";
                        if ($totalBloqueados > 0) {
                            $mensajeBase .= " <br>⚠️ Bloqueados automáticamente: $totalBloqueados empleados que no aparecen en este SUA.";
                        }
                        $mensaje_exito = $mensajeBase;
                    }
                } catch (Exception $ex) {
                    $mensaje_error = '❌ Error procesando PDF: ' . $ex->getMessage();
                    @unlink($dest);
                }
            } else {
                $mensaje_error = '❌ No se pudo guardar el archivo.';
            }
        }
    } else {
        $mensaje_error = '❌ Error al subir el archivo.';
    }
}

// Obtener lotes del responsable (solo los que él subió)
$lotes = [];
if ($empresa_responsable) {
    $stmt = $conn->prepare('SELECT * FROM sua_lotes WHERE uploaded_by = ? ORDER BY id DESC LIMIT 50');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $lotes[] = $row;
    }
    $stmt->close();
}

// Contar empleados de la empresa
$total_empleados = 0;
if ($empresa_responsable) {
    $empresa_lower = strtolower($empresa_responsable);
    $stmt = $conn->prepare('SELECT COUNT(DISTINCT e.id) as total FROM empleados e 
        LEFT JOIN empleado_proyecto ep ON e.id = ep.empleado_id 
        LEFT JOIN grupos g ON ep.proyecto_id = g.id 
        WHERE e.activo = 1 AND (LOWER(e.empresa) = ? OR LOWER(g.empresa) = ?)');
    $stmt->bind_param('ss', $empresa_lower, $empresa_lower);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $total_empleados = $row['total'] ?? 0;
    $stmt->close();
}

// Obtener empleados bloqueados de la empresa
$bloqueados = [];
if ($empresa_responsable) {
    $empresa_lower = strtolower($empresa_responsable);
    $stmt = $conn->prepare('SELECT e.id, e.nombre, e.nss, e.curp, e.fecha_registro, u.email 
        FROM empleados e 
        LEFT JOIN users u ON e.id = u.id
        WHERE e.bloqueado = 1 AND LOWER(e.empresa) = ?
        ORDER BY e.nombre');
    $stmt->bind_param('s', $empresa_lower);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $bloqueados[] = $row;
    }
    $stmt->close();
}

$pageTitle = 'Gestión de SUAs';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            line-height: 1.6;
        }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .header {
            background: white;
            padding: 20px 30px;
            margin-bottom: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 28px; color: #1e293b; }
        .header .empresa-badge {
            background: #3b82f6;
            color: white;
            padding: 8px 20px;
            border-radius: 999px;
            font-weight: 600;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .stat-number { font-size: 32px; font-weight: 700; color: #3b82f6; }
        .stat-label { font-size: 14px; color: #64748b; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        .card h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
        }
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }
        .form-control:focus { outline: none; border-color: #3b82f6; }
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-secondary { background: #64748b; color: white; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; color: #475569; font-size: 13px; text-transform: uppercase; }
        tr:hover { background: #f8fafc; }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        .empty-state i { font-size: 64px; margin-bottom: 20px; opacity: 0.3; }
        .upload-form {
            background: #f0fdf4;
            border: 2px dashed #86efac;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
        }
    </style>
</head>
<body>
    <?php include 'common/navigation.php'; ?>
    
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-file-invoice"></i> <?php echo $pageTitle; ?></h1>
            <?php if ($empresa_responsable): ?>
                <div class="empresa-badge">
                    <i class="fas fa-building"></i> <?php echo htmlspecialchars(strtoupper($empresa_responsable)); ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($mensaje_exito): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $mensaje_exito; ?>
            </div>
        <?php endif; ?>

        <?php if ($mensaje_error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $mensaje_error; ?>
            </div>
        <?php endif; ?>

        <?php if (!$empresa_responsable): ?>
            <div class="card">
                <div class="empty-state">
                    <i class="fas fa-building"></i>
                    <h3>No tienes una empresa asignada</h3>
                    <p>Contacta al administrador para que te asigne una empresa.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_empleados; ?></div>
                    <div class="stat-label">Empleados Activos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($lotes); ?></div>
                    <div class="stat-label">PDFs SUA Procesados</div>
                </div>
                <div class="stat-card" style="<?php echo count($bloqueados) > 0 ? 'border: 2px solid #fca5a5;' : ''; ?>">
                    <div class="stat-number" style="color: <?php echo count($bloqueados) > 0 ? '#dc2626' : '#10b981'; ?>;">
                        <?php echo count($bloqueados); ?>
                    </div>
                    <div class="stat-label"><?php echo count($bloqueados) > 0 ? 'Bloqueados ⚠️' : 'Bloqueados ✓'; ?></div>
                </div>
            </div>

            <!-- Formulario de subida -->
            <div class="card">
                <h2><i class="fas fa-upload"></i> Subir PDF SUA</h2>
                <form method="POST" enctype="multipart/form-data">
                    <div class="upload-form">
                        <i class="fas fa-file-pdf" style="font-size: 48px; color: #059669; margin-bottom: 15px;"></i>
                        <p style="margin-bottom: 20px; color: #065f46;">
                            Sube un archivo PDF del SUA para extraer automáticamente los datos de los empleados (NSS, CURP, Nombre).
                        </p>
                        <div class="form-group" style="max-width: 400px; margin: 0 auto 20px;">
                            <input type="file" name="sua_pdf" class="form-control" accept=".pdf" required>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-cogs"></i> Procesar PDF
                        </button>
                    </div>
                </form>
            </div>

            <!-- Lista de PDFs procesados -->
            <div class="card">
                <h2><i class="fas fa-history"></i> PDFs SUA Procesados (por ti)</h2>
                
                <?php if (empty($lotes)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No hay PDFs procesados</h3>
                        <p>Sube tu primer PDF SUA usando el formulario de arriba.</p>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">
                                            <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
                                        </th>
                                        <th>Fecha Proceso</th>
                                        <th>Archivo</th>
                                        <th>Total</th>
                                        <th>Fecha Carga</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lotes as $lote): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="lotes_sel[]" value="<?php echo $lote['id']; ?>" class="lote-checkbox">
                                            </td>
                                            <td>
                                                <span class="badge badge-info">
                                                    <?php echo date('Y-m-d', strtotime($lote['fecha_proceso'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (file_exists('../uploads/' . $lote['archivo'])): ?>
                                                    <a href="../uploads/<?php echo htmlspecialchars($lote['archivo']); ?>" target="_blank" style="color: #3b82f6;">
                                                        <i class="fas fa-file-pdf"></i> Ver PDF
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8;"><?php echo htmlspecialchars($lote['archivo']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-success"><?php echo $lote['total']; ?></span>
                                            </td>
                                            <td><?php echo date('d/m/Y H:i', strtotime($lote['created_at'])); ?></td>
                                            <td>
                                                <a href="descargar_credenciales_lote.php?lote_id=<?php echo $lote['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;" target="_blank">
                                                    <i class="fas fa-download"></i> CSV
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div style="margin-top: 20px;">
                            <button type="submit" name="eliminar_lotes" value="1" class="btn btn-danger" onclick="return confirm('¿Eliminar los lotes seleccionados?')">
                                <i class="fas fa-trash"></i> Eliminar Seleccionados
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Sección de Empleados Bloqueados -->
            <div class="card" style="margin-top: 25px;">
                <h2 style="color: #dc2626;"><i class="fas fa-user-lock"></i> Empleados Bloqueados (No Renovados)</h2>
                <p style="color: #64748b; margin-bottom: 20px; font-size: 14px;">
                    Estos empleados fueron bloqueados automáticamente porque no aparecieron en el último SUA procesado.
                </p>
                
                <?php if (empty($bloqueados)): ?>
                    <div class="empty-state" style="padding: 40px;">
                        <i class="fas fa-check-circle" style="color: #10b981;"></i>
                        <h3 style="color: #10b981;">Sin empleados bloqueados</h3>
                        <p>Todos los empleados de tu empresa están activos y renovados.</p>
                    </div>
                <?php else: ?>
                    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <p style="color: #991b1b; margin: 0;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong><?php echo count($bloqueados); ?> empleado(s) bloqueado(s)</strong> - 
                            Para desbloquearlos, sube un nuevo SUA donde aparezcan o contacta al administrador.
                        </p>
                    </div>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>NSS</th>
                                    <th>CURP</th>
                                    <th>Email</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bloqueados as $emp): ?>
                                    <tr style="background: #fef2f2;">
                                        <td><strong><?php echo htmlspecialchars($emp['nombre']); ?></strong></td>
                                        <td><code><?php echo htmlspecialchars($emp['nss'] ?? '-'); ?></code></td>
                                        <td><code style="font-size: 11px;"><?php echo htmlspecialchars($emp['curp'] ?? '-'); ?></code></td>
                                        <td><?php echo htmlspecialchars($emp['email'] ?? '-'); ?></td>
                                        <td>
                                            <span class="badge" style="background: #fee2e2; color: #991b1b;">
                                                <i class="fas fa-lock"></i> Bloqueado
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleAll(source) {
            document.querySelectorAll('.lote-checkbox').forEach(cb => cb.checked = source.checked);
        }
        
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.3s';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>
</body>
</html>
