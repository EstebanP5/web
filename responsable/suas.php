<?php
session_start();
require_once '../includes/db.php';

// Verificar autenticación y rol responsable
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'responsable') {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$mensaje_exito = '';
$mensaje_error = '';

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
    <div class="container">
        <a href="dashboard.php" class="nav-link"><i class="fas fa-arrow-left"></i> Volver al Dashboard</a>
        
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
                <div class="card">
                    <h2><i class="fas fa-cloud-upload-alt"></i> Subir SUA</h2>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="subir_sua" value="1">
                        
                        <div class="form-group">
                            <label>Empleado *</label>
                            <select name="empleado_id" class="form-control" required>
                                <option value="">-- Selecciona un empleado --</option>
                                <?php foreach ($empleados as $emp): ?>
                                    <option value="<?php echo (int)$emp['id']; ?>">
                                        <?php echo htmlspecialchars($emp['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Mes *</label>
                            <select name="mes" class="form-control" required>
                                <option value="">-- Selecciona mes --</option>
                                <?php foreach ($meses as $num => $nombre): ?>
                                    <option value="<?php echo $num; ?>"><?php echo $nombre; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Año *</label>
                            <select name="anio" class="form-control" required>
                                <option value="">-- Selecciona año --</option>
                                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Archivo SUA *</label>
                            <input type="file" name="sua_file" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls">
                            <small style="color: #64748b; display: block; margin-top: 5px;">
                                Formatos: PDF, imagen, Excel. Máximo 10MB
                            </small>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload"></i> Subir SUA
                        </button>
                    </form>
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
