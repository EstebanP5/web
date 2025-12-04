<?php
/**
 * Script de configuración para el sistema de responsables por empresa
 * Este script debe ejecutarse una sola vez para configurar las tablas necesarias
 * y asignar empresas a los responsables existentes
 */

session_start();
require_once __DIR__ . '/../includes/db.php';

// Verificar que solo admin pueda ejecutar este script
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    die('Acceso denegado. Solo administradores pueden ejecutar este script.');
}

$mensaje = '';
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ejecutar_setup'])) {
    try {
        // 1. Crear tabla empresas_responsables
        $sql1 = "CREATE TABLE IF NOT EXISTS empresas_responsables (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            empresa VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_empresa (user_id, empresa),
            INDEX idx_user (user_id),
            INDEX idx_empresa (empresa),
            CONSTRAINT fk_empresa_responsable_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if ($conn->query($sql1)) {
            $mensaje .= "✅ Tabla empresas_responsables creada correctamente.<br>";
        } else {
            $errores[] = "Error al crear tabla empresas_responsables: " . $conn->error;
        }
        
        // 2. Crear tabla suas
        $sql2 = "CREATE TABLE IF NOT EXISTS suas (
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
            INDEX idx_uploader (uploaded_by),
            CONSTRAINT fk_sua_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
            CONSTRAINT fk_sua_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if ($conn->query($sql2)) {
            $mensaje .= "✅ Tabla suas creada correctamente.<br>";
        } else {
            $errores[] = "Error al crear tabla suas: " . $conn->error;
        }
        
        // 3. Crear tabla pm_documentos
        $sql3 = "CREATE TABLE IF NOT EXISTS pm_documentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pm_id INT NOT NULL,
            nombre_documento VARCHAR(255) NOT NULL,
            descripcion TEXT,
            ruta_archivo VARCHAR(255) NOT NULL,
            nombre_original VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100),
            tamanio INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pm (pm_id),
            CONSTRAINT fk_pm_documentos_user FOREIGN KEY (pm_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if ($conn->query($sql3)) {
            $mensaje .= "✅ Tabla pm_documentos creada correctamente.<br>";
        } else {
            $errores[] = "Error al crear tabla pm_documentos: " . $conn->error;
        }
        
        // 4. Crear directorios para uploads
        $dirs = [
            dirname(__DIR__) . '/uploads/pm_documentos',
            dirname(__DIR__) . '/uploads/suas'
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (mkdir($dir, 0775, true)) {
                    $mensaje .= "✅ Directorio " . basename($dir) . " creado correctamente.<br>";
                } else {
                    $errores[] = "Error al crear directorio: " . basename($dir);
                }
            } else {
                $mensaje .= "ℹ️ Directorio " . basename($dir) . " ya existe.<br>";
            }
        }
        
        // 5. Obtener empresas únicas de la tabla grupos
        $empresas = [];
        $resultEmpresas = $conn->query("SELECT DISTINCT empresa FROM grupos WHERE empresa IS NOT NULL AND empresa != '' ORDER BY empresa");
        if ($resultEmpresas) {
            while ($row = $resultEmpresas->fetch_assoc()) {
                $empresas[] = $row['empresa'];
            }
            $mensaje .= "✅ Se encontraron " . count($empresas) . " empresas en el sistema: " . implode(', ', $empresas) . "<br>";
        }
        
        if (empty($errores)) {
            $mensaje .= "<br><strong>✅ Configuración completada exitosamente.</strong><br>";
            $mensaje .= "<br><h3>Siguiente paso:</h3>";
            $mensaje .= "<p>Usa el formulario de abajo para asignar empresas a los responsables.</p>";
        }
        
    } catch (Exception $e) {
        $errores[] = "Error general: " . $e->getMessage();
    }
}

// Procesar asignación de empresa a responsable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asignar_empresa'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $empresa = trim($_POST['empresa'] ?? '');
    
    if ($user_id <= 0 || empty($empresa)) {
        $errores[] = "Usuario o empresa inválidos.";
    } else {
        // Verificar que el usuario existe y es responsable
        $stmt = $conn->prepare("SELECT id, name, rol FROM users WHERE id = ? AND rol = 'responsable' AND activo = 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$user) {
            $errores[] = "Usuario no encontrado o no es responsable.";
        } else {
            // Insertar o actualizar la asignación
            $stmt = $conn->prepare("INSERT INTO empresas_responsables (user_id, empresa) VALUES (?, ?) ON DUPLICATE KEY UPDATE empresa = VALUES(empresa)");
            $stmt->bind_param('is', $user_id, $empresa);
            if ($stmt->execute()) {
                $mensaje = "✅ Empresa '{$empresa}' asignada correctamente a " . htmlspecialchars($user['name']) . ".";
            } else {
                $errores[] = "Error al asignar empresa: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Crear tabla empresas_responsables si no existe (para evitar errores en la consulta)
$conn->query("CREATE TABLE IF NOT EXISTS empresas_responsables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    empresa VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_empresa (user_id, empresa),
    INDEX idx_user (user_id),
    INDEX idx_empresa (empresa),
    CONSTRAINT fk_empresa_responsable_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Obtener responsables
$responsables = [];
$resultResponsables = $conn->query("SELECT u.id, u.name, u.email, er.empresa 
    FROM users u 
    LEFT JOIN empresas_responsables er ON u.id = er.user_id 
    WHERE u.rol = 'responsable' AND u.activo = 1 
    ORDER BY u.name");
if ($resultResponsables) {
    while ($row = $resultResponsables->fetch_assoc()) {
        $responsables[] = $row;
    }
}

// Obtener empresas únicas
$empresas = [];
$resultEmpresas = $conn->query("SELECT DISTINCT empresa FROM grupos WHERE empresa IS NOT NULL AND empresa != '' ORDER BY empresa");
if ($resultEmpresas) {
    while ($row = $resultEmpresas->fetch_assoc()) {
        $empresas[] = $row['empresa'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Responsables </title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f1f5f9;
            padding: 20px;
            color: #1e293b;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 28px; margin-bottom: 10px; color: #1e293b; }
        .header p { color: #64748b; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
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
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
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
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        .btn-primary:hover {
            background: #2563eb;
        }
        .btn-success {
            background: #22c55e;
            color: white;
        }
        .btn-success:hover {
            background: #16a34a;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e293b;
        }
        .form-control, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }
        .form-control:focus, select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
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
        .warning-box {
            background: #fff7ed;
            border: 2px solid #fb923c;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .warning-box h3 {
            color: #c2410c;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="admin.php" class="nav-link"><i class="fas fa-arrow-left"></i> Volver al Panel de Administración</a>
        
        <div class="header">
            <h1><i class="fas fa-cog"></i> Configuración del Sistema de Responsables</h1>
            <p>Configurar tablas necesarias y asignar empresas a responsables</p>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errores)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <strong>Errores:</strong>
                <ul style="margin-top: 10px; margin-left: 20px;">
                    <?php foreach ($errores as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="warning-box">
            <h3><i class="fas fa-exclamation-triangle"></i> Importante</h3>
            <p>Este script configurará las tablas necesarias para el sistema de responsables por empresa. Solo necesitas ejecutarlo una vez.</p>
            <p style="margin-top: 10px;"><strong>Funcionalidades que se configurarán:</strong></p>
            <ul style="margin-left: 20px; margin-top: 10px;">
                <li>Tabla de empresas_responsables (relación usuario-empresa)</li>
                <li>Tabla de SUAs (Sistema Único de Autodeterminación)</li>
                <li>Tabla de documentos de PM</li>
                <li>Directorios de almacenamiento necesarios</li>
            </ul>
        </div>

        <div class="card">
            <h2><i class="fas fa-play-circle"></i> Paso 1: Ejecutar Configuración</h2>
            <form method="POST">
                <input type="hidden" name="ejecutar_setup" value="1">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-cog"></i> Ejecutar Configuración
                </button>
            </form>
        </div>

        <?php if (!empty($empresas)): ?>
            <div class="card">
                <h2><i class="fas fa-building"></i> Paso 2: Asignar Empresas a Responsables</h2>
                <form method="POST">
                    <input type="hidden" name="asignar_empresa" value="1">
                    
                    <div class="form-group">
                        <label>Responsable</label>
                        <select name="user_id" class="form-control" required>
                            <option value="">-- Selecciona un responsable --</option>
                            <?php foreach ($responsables as $resp): ?>
                                <option value="<?php echo (int)$resp['id']; ?>">
                                    <?php echo htmlspecialchars($resp['name']); ?> (<?php echo htmlspecialchars($resp['email']); ?>)
                                    <?php if ($resp['empresa']): ?>
                                        - Actual: <?php echo htmlspecialchars($resp['empresa']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Empresa</label>
                        <select name="empresa" class="form-control" required>
                            <option value="">-- Selecciona una empresa --</option>
                            <?php foreach ($empresas as $emp): ?>
                                <option value="<?php echo htmlspecialchars($emp); ?>">
                                    <?php echo htmlspecialchars($emp); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #64748b; display: block; margin-top: 5px;">
                            Las empresas se obtienen de los proyectos registrados en el sistema
                        </small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Asignar Empresa
                    </button>
                </form>
            </div>

            <div class="card">
                <h2><i class="fas fa-list"></i> Responsables y sus Empresas Asignadas</h2>
                <?php if (empty($responsables)): ?>
                    <p style="color: #64748b;">No hay responsables registrados en el sistema.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Email</th>
                                <th>Empresa Asignada</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($responsables as $resp): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($resp['name']); ?></td>
                                    <td><?php echo htmlspecialchars($resp['email']); ?></td>
                                    <td>
                                        <?php if ($resp['empresa']): ?>
                                            <strong><?php echo htmlspecialchars($resp['empresa']); ?></strong>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">Sin asignar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($resp['empresa']): ?>
                                            <span class="badge badge-success">Configurado</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
