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
$mensaje_extraccion = '';
$empleados_extraidos = [];

// Función para limpiar nombre (eliminar sufijos sueltos R / A derivados de columnas)
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

// Función para extraer empleados del texto del PDF
function extraerEmpleadosDePDF($texto) {
    $resultado = [];
    $stopWords = ['REING', 'REING.', 'BAJA', 'ALTA'];
    
    // Normalizar texto
    $t = preg_replace('/[\r\n\t]+/', ' ', $texto);
    $t = preg_replace('/\s+/', ' ', $t);
    $t = preg_replace('/\b(Reing|Reing\.|Baja|Alta)\b/iu', ' ', $t);
    
    $add = function($nss, $nombre, $curp) use (&$resultado, $stopWords) {
        $nss = trim($nss);
        $curp = trim($curp);
        $nombre = trim(preg_replace('/\s+/', ' ', $nombre));
        $nombre = strtoupper(preg_replace('/[^A-ZÁÉÍÓÚÑ\s]/u', '', $nombre));
        $nombre = trim(preg_replace('/\b(REING|REING\.|BAJA|ALTA)\b/u', '', $nombre));
        
        if (strlen($curp) !== 18) return;
        if (strlen($nombre) < 5 || strlen($nombre) > 90) return;
        if (!preg_match('/^\d{2}-\d{2}-\d{2}-\d{4}-\d$/', $nss)) return;
        
        $resultado[$nss] = [
            'nss' => $nss,
            'nombre' => suaCleanNombre($nombre),
            'curp' => $curp
        ];
    };
    
    // Patrón principal
    $patPrincipal = '/([0-9]{2}-[0-9]{2}-[0-9]{2}-[0-9]{4}-[0-9])\s+([A-ZÁÉÍÓÚÑ ]+?)\s+([A-Z]{4}[0-9]{6}[A-Z0-9]{6}[A-Z0-9][0-9])/iu';
    if (preg_match_all($patPrincipal, $t, $m, PREG_SET_ORDER)) {
        foreach ($m as $coinc) {
            $add($coinc[1], $coinc[2], $coinc[3]);
        }
    }
    
    // Fallback: dividir por NSS y buscar CURP
    if (empty($resultado)) {
        $patNSS = '/\d{2}-\d{2}-\d{2}-\d{4}-\d/';
        $patCURP = '/[A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d/i';
        preg_match_all($patNSS, $t, $nssLista);
        $partes = preg_split($patNSS, $t, -1, PREG_SPLIT_DELIM_CAPTURE);
        $nsss = $nssLista[0];
        for ($i = 1; $i < count($partes); $i++) {
            if (!isset($nsss[$i - 1])) continue;
            $nss = $nsss[$i - 1];
            $segmento = $partes[$i];
            if (preg_match($patCURP, $segmento, $cMatch)) {
                $curp = $cMatch[0];
                $nombreParte = preg_replace('/' . preg_quote($curp, '/') . '/', '', $segmento, 1);
                $add($nss, $nombreParte, $curp);
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
                if ($nombre && strlen($curp) === 18) {
                    $resultado[$nss] = ['nss' => $nss, 'nombre' => suaCleanNombre($nombre), 'curp' => $curp];
                }
            }
        }
    }
    
    return array_values($resultado);
}

// Procesar extracción de PDF SUA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extraer_pdf']) && isset($_FILES['pdf_sua'])) {
    $archivo = $_FILES['pdf_sua'];
    
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $mensaje_error = '❌ Debes seleccionar un archivo PDF.';
    } elseif (($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $mensaje_error = '❌ Error al subir el archivo.';
    } else {
        $extension = strtolower(pathinfo($archivo['name'] ?? '', PATHINFO_EXTENSION));
        if ($extension !== 'pdf') {
            $mensaje_error = '❌ Solo se permiten archivos PDF.';
        } else {
            try {
                $parser = new Parser();
                $pdf = $parser->parseFile($archivo['tmp_name']);
                $texto = $pdf->getText();
                
                $empleados_extraidos = extraerEmpleadosDePDF($texto);
                
                if (empty($empleados_extraidos)) {
                    $mensaje_error = '❌ No se encontraron empleados en el PDF. Verifica que sea un archivo SUA válido.';
                } else {
                    $mensaje_extraccion = '✅ Se extrajeron ' . count($empleados_extraidos) . ' empleados del PDF.';
                    $_SESSION['empleados_extraidos'] = $empleados_extraidos;
                }
            } catch (Exception $e) {
                $mensaje_error = '❌ Error al procesar el PDF: ' . $e->getMessage();
            }
        }
    }
}

// Recuperar empleados extraídos de la sesión si existen
if (empty($empleados_extraidos) && isset($_SESSION['empleados_extraidos'])) {
    $empleados_extraidos = $_SESSION['empleados_extraidos'];
}

// Procesar inserción de empleados extraídos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['insertar_empleados'])) {
    $empleados_seleccionados = $_POST['empleados_sel'] ?? [];
    $insertados = 0;
    $actualizados = 0;
    $errores_insercion = [];
    
    if (!empty($empleados_seleccionados) && isset($_SESSION['empleados_extraidos'])) {
        $empleados_data = $_SESSION['empleados_extraidos'];
        
        foreach ($empleados_seleccionados as $idx) {
            if (!isset($empleados_data[$idx])) continue;
            
            $emp = $empleados_data[$idx];
            $nss = $emp['nss'];
            $nombre = $emp['nombre'];
            $curp = $emp['curp'];
            
            // Verificar si ya existe por NSS
            $stmt = $conn->prepare('SELECT id FROM empleados WHERE nss = ? LIMIT 1');
            $stmt->bind_param('s', $nss);
            $stmt->execute();
            $existente = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($existente) {
                // Actualizar CURP y empresa si no tiene
                $stmt = $conn->prepare('UPDATE empleados SET curp = COALESCE(NULLIF(curp, ""), ?), empresa = COALESCE(NULLIF(empresa, ""), ?) WHERE id = ?');
                $stmt->bind_param('ssi', $curp, $empresa_responsable, $existente['id']);
                $stmt->execute();
                $stmt->close();
                $actualizados++;
            } else {
                // Primero crear usuario en la tabla users
                // Generar email único basado en NSS
                $nss_clean = str_replace('-', '', $nss);
                $email = $nss_clean . '@trabajador.local';
                $pwdPlain = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
                $pwdHash = password_hash($pwdPlain, PASSWORD_DEFAULT);
                
                $userId = 0;
                
                // Intentar insertar usuario
                $stmtUser = $conn->prepare("INSERT INTO users (name, email, password, password_visible, rol, activo) VALUES (?, ?, ?, ?, 'servicio_especializado', 1)");
                if ($stmtUser) {
                    $stmtUser->bind_param('ssss', $nombre, $email, $pwdHash, $pwdPlain);
                    if ($stmtUser->execute()) {
                        $userId = (int)$stmtUser->insert_id;
                    }
                    $stmtUser->close();
                }
                
                // Si no se pudo crear, buscar si ya existe
                if ($userId === 0) {
                    $stmtFind = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    if ($stmtFind) {
                        $stmtFind->bind_param('s', $email);
                        $stmtFind->execute();
                        $rowUser = $stmtFind->get_result()->fetch_assoc();
                        $userId = $rowUser ? (int)$rowUser['id'] : 0;
                        $stmtFind->close();
                    }
                }
                
                // Insertar empleado con el ID del usuario Y la empresa del responsable
                if ($userId > 0) {
                    $stmt = $conn->prepare('INSERT INTO empleados (id, nombre, nss, curp, empresa, puesto, activo, fecha_registro) VALUES (?, ?, ?, ?, ?, "Servicio Especializado", 1, NOW()) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), nss=VALUES(nss), curp=VALUES(curp), empresa=VALUES(empresa), activo=1');
                    $stmt->bind_param('issss', $userId, $nombre, $nss, $curp, $empresa_responsable);
                    if ($stmt->execute()) {
                        $insertados++;
                    } else {
                        $errores_insercion[] = $nombre;
                    }
                    $stmt->close();
                } else {
                    $errores_insercion[] = $nombre . ' (no se pudo crear usuario)';
                }
            }
        }
        
        unset($_SESSION['empleados_extraidos']);
        $empleados_extraidos = [];
        
        $msg_parts = [];
        if ($insertados > 0) $msg_parts[] = "$insertados insertados";
        if ($actualizados > 0) $msg_parts[] = "$actualizados actualizados";
        if (!empty($errores_insercion)) $msg_parts[] = count($errores_insercion) . " con error";
        
        $mensaje_exito = '✅ Empleados procesados: ' . implode(', ', $msg_parts) . '.';
    } else {
        $mensaje_error = '❌ No hay empleados seleccionados para insertar.';
    }
}

// Limpiar sesión de extracción
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['limpiar_extraccion'])) {
    unset($_SESSION['empleados_extraidos']);
    $empleados_extraidos = [];
}

// Asegurar tabla de empresas_responsables si no existe
$conn->query("CREATE TABLE IF NOT EXISTS empresas_responsables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    empresa VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_empresa (user_id, empresa),
    INDEX idx_user (user_id),
    CONSTRAINT fk_empresa_responsable_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Asegurar tabla de SUAs si no existe
$conn->query("CREATE TABLE IF NOT EXISTS suas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT NOT NULL,
    empresa VARCHAR(100) NOT NULL,
    mes INT NOT NULL,
    anio INT NOT NULL,
    ruta_archivo VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100),
    uploaded_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_empleado (empleado_id),
    INDEX idx_empresa_fecha (empresa, anio, mes),
    CONSTRAINT fk_sua_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_sua_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Obtener empresa del responsable
$stmt = $conn->prepare('SELECT empresa FROM empresas_responsables WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$responsableData = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$responsableData) {
    $mensaje_error = '❌ No tienes una empresa asignada. Contacta al administrador.';
    $empresa_responsable = '';
} else {
    $empresa_responsable = $responsableData['empresa'];
}

// Procesar subida de SUA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_sua']) && $empresa_responsable) {
    $empleado_id = (int)($_POST['empleado_id'] ?? 0);
    $mes = (int)($_POST['mes'] ?? 0);
    $anio = (int)($_POST['anio'] ?? 0);
    $archivo = $_FILES['sua_file'] ?? null;
    
    if ($empleado_id <= 0) {
        $mensaje_error = '❌ Debes seleccionar un empleado.';
    } elseif ($mes < 1 || $mes > 12) {
        $mensaje_error = '❌ Mes inválido.';
    } elseif ($anio < 2020 || $anio > 2100) {
        $mensaje_error = '❌ Año inválido.';
    } elseif (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $mensaje_error = '❌ Debes seleccionar un archivo SUA.';
    } elseif (($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $mensaje_error = '❌ Error al subir el archivo (código ' . (int)$archivo['error'] . ').';
    } elseif (($archivo['size'] ?? 0) <= 0) {
        $mensaje_error = '❌ El archivo está vacío.';
    } elseif (($archivo['size'] ?? 0) > 10 * 1024 * 1024) {
        $mensaje_error = '❌ El archivo no debe superar los 10MB.';
    } else {
        // Verificar que el empleado pertenece a la empresa del responsable
        $stmt = $conn->prepare('SELECT e.id, e.nombre FROM empleados e 
            INNER JOIN empleado_proyecto ep ON e.id = ep.empleado_id 
            INNER JOIN grupos g ON ep.proyecto_id = g.id 
            WHERE e.id = ? AND g.empresa = ? AND e.activo = 1 LIMIT 1');
        $stmt->bind_param('is', $empleado_id, $empresa_responsable);
        $stmt->execute();
        $empleado = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$empleado) {
            $mensaje_error = '❌ El empleado no pertenece a tu empresa.';
        } else {
            $extension = strtolower(pathinfo($archivo['name'] ?? '', PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'xls'];
            
            if (!in_array($extension, $allowedExtensions, true)) {
                $mensaje_error = '❌ Formato de archivo no permitido. Formatos válidos: PDF, imagen, Excel';
            } else {
                $uploadsDir = dirname(__DIR__) . '/uploads/suas';
                if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
                    $mensaje_error = '❌ No se pudo crear el directorio para guardar el SUA.';
                } else {
                    try {
                        $randomSegment = bin2hex(random_bytes(8));
                    } catch (Exception $e) {
                        $randomSegment = substr(sha1(uniqid('', true)), 0, 16);
                    }
                    
                    $filename = 'sua_' . $empleado_id . '_' . $anio . '_' . str_pad($mes, 2, '0', STR_PAD_LEFT) . '_' . $randomSegment . '.' . $extension;
                    $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
                    
                    if (!move_uploaded_file($archivo['tmp_name'], $destPath)) {
                        $mensaje_error = '❌ No se pudo guardar el archivo en el servidor.';
                    } else {
                        $relativePath = 'uploads/suas/' . $filename;
                        
                        // Verificar si ya existe un SUA para este empleado/mes/año
                        $stmt = $conn->prepare('SELECT id, ruta_archivo FROM suas WHERE empleado_id = ? AND mes = ? AND anio = ?');
                        $stmt->bind_param('iii', $empleado_id, $mes, $anio);
                        $stmt->execute();
                        $suaExistente = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        
                        if ($suaExistente) {
                            // Actualizar registro existente
                            $stmt = $conn->prepare('UPDATE suas SET ruta_archivo = ?, nombre_original = ?, mime_type = ?, uploaded_by = ?, created_at = NOW() WHERE id = ?');
                            $mimeType = $archivo['type'] ?? 'application/octet-stream';
                            $suaId = (int)$suaExistente['id'];
                            $stmt->bind_param('sssii', $relativePath, $archivo['name'], $mimeType, $user_id, $suaId);
                            if ($stmt->execute()) {
                                // Eliminar archivo anterior
                                $oldPath = dirname(__DIR__) . '/' . $suaExistente['ruta_archivo'];
                                if (file_exists($oldPath)) {
                                    unlink($oldPath);
                                }
                                $mensaje_exito = "✅ SUA actualizado para {$empleado['nombre']} - " . date('m/Y', mktime(0, 0, 0, $mes, 1, $anio));
                            } else {
                                $mensaje_error = '❌ Error al actualizar el SUA en la base de datos.';
                                if (file_exists($destPath)) {
                                    unlink($destPath);
                                }
                            }
                            $stmt->close();
                        } else {
                            // Crear nuevo registro
                            $stmt = $conn->prepare('INSERT INTO suas (empleado_id, empresa, mes, anio, ruta_archivo, nombre_original, mime_type, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                            $mimeType = $archivo['type'] ?? 'application/octet-stream';
                            $stmt->bind_param('isiisssi', $empleado_id, $empresa_responsable, $mes, $anio, $relativePath, $archivo['name'], $mimeType, $user_id);
                            if ($stmt->execute()) {
                                $mensaje_exito = "✅ SUA registrado para {$empleado['nombre']} - " . date('m/Y', mktime(0, 0, 0, $mes, 1, $anio));
                            } else {
                                $mensaje_error = '❌ Error al registrar el SUA en la base de datos.';
                                if (file_exists($destPath)) {
                                    unlink($destPath);
                                }
                            }
                            $stmt->close();
                        }
                    }
                }
            }
        }
    }
}

// Obtener empleados de la empresa del responsable
$empleados = [];
if ($empresa_responsable) {
    $stmt = $conn->prepare('SELECT DISTINCT e.id, e.nombre, e.telefono 
        FROM empleados e 
        INNER JOIN empleado_proyecto ep ON e.id = ep.empleado_id 
        INNER JOIN grupos g ON ep.proyecto_id = g.id 
        WHERE g.empresa = ? AND e.activo = 1 
        ORDER BY e.nombre');
    $stmt->bind_param('s', $empresa_responsable);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $empleados[] = $row;
    }
    $stmt->close();
}

// Obtener proyectos de la empresa del responsable
$proyectos = [];
if ($empresa_responsable) {
    $stmt = $conn->prepare('SELECT id, nombre, localidad, fecha_inicio, fecha_fin 
        FROM grupos 
        WHERE empresa = ? AND activo = 1 
        ORDER BY nombre');
    $stmt->bind_param('s', $empresa_responsable);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $proyectos[] = $row;
    }
    $stmt->close();
}

// Obtener SUAs de la empresa del responsable
$suas = [];
if ($empresa_responsable) {
    $stmt = $conn->prepare('SELECT s.id, s.empleado_id, s.mes, s.anio, s.ruta_archivo, s.nombre_original, s.created_at, e.nombre as empleado_nombre 
        FROM suas s 
        INNER JOIN empleados e ON s.empleado_id = e.id 
        WHERE s.empresa = ? 
        ORDER BY s.anio DESC, s.mes DESC, e.nombre');
    $stmt->bind_param('s', $empresa_responsable);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $suas[] = $row;
    }
    $stmt->close();
}

$meses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

$pageTitle = 'Gestión de SUAs  ';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            line-height: 1.6;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
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
        .header h1 {
            font-size: 28px;
            color: #1e293b;
        }
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
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .card h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e293b;
            font-size: 14px;
        }
        .form-control, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .form-control:focus, select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
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
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        .btn-primary:hover {
            background: #2563eb;
        }
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        .btn-secondary:hover {
            background: #475569;
        }
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        td {
            color: #1e293b;
        }
        tr:hover {
            background: #f8fafc;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        .nav-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
        }
        .nav-link:hover {
            color: #2563eb;
        }
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
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #3b82f6;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 14px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <?php include 'common/navigation.php'; ?>
    
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-file-invoice"></i> Gestión de SUAs</h1>
            <?php if ($empresa_responsable): ?>
                <div class="empresa-badge">
                    <i class="fas fa-building"></i> <?php echo htmlspecialchars($empresa_responsable); ?>
                </div>
            <?php endif; ?>
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

        <?php if ($mensaje_extraccion): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $mensaje_extraccion; ?>
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
                    <div class="stat-number"><?php echo count($empleados); ?></div>
                    <div class="stat-label">Empleados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($proyectos); ?></div>
                    <div class="stat-label">Proyectos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($suas); ?></div>
                    <div class="stat-label">SUAs Registrados</div>
                </div>
            </div>

            <div class="cards-grid">
                <!-- Sección de Extracción Automática de PDF SUA -->
                <div class="card" style="grid-column: 1 / -1;">
                    <h2><i class="fas fa-magic"></i> Extracción Automática de Empleados desde PDF SUA</h2>
                    <p style="color: #64748b; margin-bottom: 20px;">
                        Sube un archivo PDF del SUA para extraer automáticamente los datos de los empleados (NSS, CURP, Nombre).
                    </p>
                    
                    <form method="POST" enctype="multipart/form-data" style="margin-bottom: 20px;">
                        <input type="hidden" name="extraer_pdf" value="1">
                        <div style="display: flex; gap: 15px; align-items: end; flex-wrap: wrap;">
                            <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 250px;">
                                <label>Archivo PDF del SUA</label>
                                <input type="file" name="pdf_sua" class="form-control" accept=".pdf" required>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Extraer Empleados
                            </button>
                        </div>
                    </form>

                    <?php if (!empty($empleados_extraidos)): ?>
                        <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 20px; margin-top: 20px;">
                            <h3 style="color: #166534; margin-bottom: 15px;">
                                <i class="fas fa-users"></i> Empleados Detectados (<?php echo count($empleados_extraidos); ?>)
                            </h3>
                            
                            <form method="POST">
                                <input type="hidden" name="insertar_empleados" value="1">
                                
                                <div class="table-container">
                                    <table style="margin-bottom: 20px;">
                                        <thead>
                                            <tr>
                                                <th style="width: 40px;">
                                                    <input type="checkbox" id="selectAll" onchange="toggleAll(this)" checked>
                                                </th>
                                                <th>NSS</th>
                                                <th>Nombre</th>
                                                <th>CURP</th>
                                                <th>Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($empleados_extraidos as $idx => $emp): 
                                                // Verificar si ya existe
                                                $stmtCheck = $conn->prepare('SELECT id, nombre FROM empleados WHERE nss = ? LIMIT 1');
                                                $stmtCheck->bind_param('s', $emp['nss']);
                                                $stmtCheck->execute();
                                                $existente = $stmtCheck->get_result()->fetch_assoc();
                                                $stmtCheck->close();
                                            ?>
                                                <tr>
                                                    <td>
                                                        <input type="checkbox" name="empleados_sel[]" value="<?php echo $idx; ?>" class="emp-checkbox" checked>
                                                    </td>
                                                    <td><code><?php echo htmlspecialchars($emp['nss']); ?></code></td>
                                                    <td><?php echo htmlspecialchars($emp['nombre']); ?></td>
                                                    <td><code style="font-size: 12px;"><?php echo htmlspecialchars($emp['curp']); ?></code></td>
                                                    <td>
                                                        <?php if ($existente): ?>
                                                            <span class="badge" style="background: #fef3c7; color: #92400e;">
                                                                <i class="fas fa-exclamation-triangle"></i> Ya existe
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge" style="background: #d1fae5; color: #065f46;">
                                                                <i class="fas fa-plus-circle"></i> Nuevo
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Insertar Empleados Seleccionados
                                    </button>
                                    <button type="submit" name="limpiar_extraccion" value="1" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Cancelar / Limpiar
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <script>
                            function toggleAll(source) {
                                document.querySelectorAll('.emp-checkbox').forEach(checkbox => {
                                    checkbox.checked = source.checked;
                                });
                            }
                        </script>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h2><i class="fas fa-list"></i> Resumen</h2>
                    <div style="padding: 10px 0;">
                        <p style="margin-bottom: 15px;">
                            <strong>Empleados activos:</strong> <?php echo count($empleados); ?>
                        </p>
                        <p style="margin-bottom: 15px;">
                            <strong>Proyectos activos:</strong> <?php echo count($proyectos); ?>
                        </p>
                        <p style="margin-bottom: 15px;">
                            <strong>SUAs registrados:</strong> <?php echo count($suas); ?>
                        </p>
                        <hr style="margin: 20px 0; border: none; border-top: 1px solid #e2e8f0;">
                        <p style="font-size: 14px; color: #64748b;">
                            Solo puedes ver y gestionar los SUAs de empleados y proyectos de tu empresa (<?php echo htmlspecialchars($empresa_responsable); ?>).
                        </p>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><i class="fas fa-table"></i> SUAs Registrados</h2>
                <?php if (empty($suas)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No hay SUAs registrados</h3>
                        <p>Sube el primer SUA usando el formulario de arriba.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Empleado</th>
                                    <th>Período</th>
                                    <th>Archivo</th>
                                    <th>Fecha de Carga</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suas as $sua): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($sua['empleado_nombre']); ?></td>
                                        <td>
                                            <span class="badge badge-info">
                                                <?php echo $meses[(int)$sua['mes']] . ' ' . $sua['anio']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($sua['nombre_original']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($sua['created_at'])); ?></td>
                                        <td>
                                            <a href="../<?php echo htmlspecialchars($sua['ruta_archivo']); ?>" target="_blank" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;">
                                                <i class="fas fa-download"></i> Ver
                                            </a>
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
