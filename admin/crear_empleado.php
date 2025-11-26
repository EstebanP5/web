<?php
require_once '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Obtener proyectos para dropdown (tabla grupos)
$proyectos = [];
$resP = $conn->query("SELECT id, nombre FROM grupos ORDER BY nombre");
if ($resP) { $proyectos = $resP->fetch_all(MYSQLI_ASSOC); }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $nss      = trim($_POST['nss'] ?? '');
    $curp     = trim($_POST['curp'] ?? '');
    $empresa  = trim($_POST['empresa'] ?? '');
    $proyId   = isset($_POST['proyecto_id']) ? (int)$_POST['proyecto_id'] : 0;
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($nombre === '' || $telefono === '' || $email === '' || $password === '') {
        $msg = 'Nombre, teléfono, correo y contraseña son obligatorios.';
    } elseif ($empresa === '' || !in_array($empresa, ['CEDISA', 'Stone', 'Remedios'], true)) {
        $msg = 'Selecciona una empresa válida.';
    } else {
        $altaImssFile = $_FILES['alta_imss'] ?? null;
        $altaImssInfo = null;
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
    $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];

        if (!$altaImssFile || ($altaImssFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $msg = 'Debes adjuntar el alta del IMSS en formato PDF o imagen.';
        } elseif (($altaImssFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $msg = 'No se pudo recibir el archivo del IMSS. Código de error: ' . (int)($altaImssFile['error']);
        } elseif (($altaImssFile['size'] ?? 0) <= 0) {
            $msg = 'El archivo del IMSS está vacío. Verifica la selección e inténtalo nuevamente.';
        } else {
            $extension = strtolower(pathinfo($altaImssFile['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions, true)) {
                $msg = 'Formato de archivo no permitido. Sube un PDF, JPG o PNG.';
            } elseif (($altaImssFile['size'] ?? 0) > 10 * 1024 * 1024) {
                $msg = 'El archivo del IMSS no debe superar los 10MB.';
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
                    $msg = 'El archivo del IMSS debe ser PDF o imagen (JPG/PNG).';
                } else {
                    $altaImssInfo = [
                        'extension' => $extension,
                        'mime' => $detectedMime ?: ($altaImssFile['type'] ?? ''),
                        'original' => $altaImssFile['name'] ?? 'alta_imss',
                        'tmp_name' => $altaImssFile['tmp_name']
                    ];
                }
            }
        }

        if ($msg === '') {
            // 1) Crear usuario primero
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $u = $conn->prepare("INSERT INTO users (name, email, password, rol, activo) VALUES (?, ?, ?, 'servicio_especializado', 1)");
        if (!$u) {
            $msg = 'Error interno preparando inserción de usuario.';
        } else {
            $u->bind_param('sss', $nombre, $email, $hash);
            if ($u->execute()) {
                $userId = $conn->insert_id;
                // 2) Insertar empleado con el mismo id (FK -> users.id)
                $stmt = $conn->prepare("INSERT INTO empleados (id, nombre, telefono, nss, curp, empresa, activo, fecha_registro) VALUES (?,?,?,?,?, ?,1,NOW())");
                if ($stmt) {
                    $stmt->bind_param('isssss', $userId, $nombre, $telefono, $nss, $curp, $empresa);
                    if ($stmt->execute()) {
                        $empleadoId = $userId;
                        // Asignación opcional a proyecto (un solo proyecto activo)
                        if ($proyId > 0) {
                            // Desactivar asignaciones previas
                            $stmt2 = $conn->prepare("UPDATE empleado_proyecto SET activo = 0 WHERE empleado_id = ? AND activo = 1");
                            $stmt2->bind_param('i', $empleadoId);
                            $stmt2->execute();
                            // Activar o insertar relación
                            $stmt3 = $conn->prepare("UPDATE empleado_proyecto SET activo=1, fecha_asignacion=NOW() WHERE empleado_id=? AND proyecto_id=?");
                            $stmt3->bind_param('ii', $empleadoId, $proyId);
                            $stmt3->execute();
                            if ($stmt3->affected_rows === 0) {
                                $stmt4 = $conn->prepare("INSERT INTO empleado_proyecto (empleado_id, proyecto_id, activo, fecha_asignacion) VALUES (?,?,1,NOW())");
                                $stmt4->bind_param('ii', $empleadoId, $proyId);
                                $stmt4->execute();
                            }
                        }
                        // Manejar documento de alta IMSS
                        $docOk = false;
                        $docMessage = '';
                        $destPath = null;
                        if ($altaImssInfo) {
                            $uploadsDir = dirname(__DIR__) . '/uploads/altas_imss';
                            if (!is_dir($uploadsDir)) {
                                if (!mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
                                    $docMessage = 'No se pudo crear el directorio para guardar el alta del IMSS.';
                                }
                            }

                            if ($docMessage === '') {
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
                                $filename = 'alta_imss_' . $empleadoId . '_' . date('Ymd_His') . '_' . $randomSegment . '.' . $altaImssInfo['extension'];
                                $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
                                if (!is_uploaded_file($altaImssInfo['tmp_name']) || !move_uploaded_file($altaImssInfo['tmp_name'], $destPath)) {
                                    $docMessage = 'No se pudo guardar el archivo del IMSS en el servidor.';
                                } else {
                                    $relativePath = 'uploads/altas_imss/' . $filename;
                                    $createTableSql = "CREATE TABLE IF NOT EXISTS empleado_documentos (
                                        id INT AUTO_INCREMENT PRIMARY KEY,
                                        empleado_id INT NOT NULL,
                                        tipo VARCHAR(50) NOT NULL,
                                        ruta_archivo VARCHAR(255) NOT NULL,
                                        nombre_original VARCHAR(255) DEFAULT NULL,
                                        mime_type VARCHAR(100) DEFAULT NULL,
                                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                                        INDEX idx_empleado_tipo (empleado_id, tipo),
                                        CONSTRAINT fk_empleado_documentos_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
                                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
                                    if (!$conn->query($createTableSql)) {
                                        $docMessage = 'No se pudo preparar el registro del alta del IMSS.';
                                    } else {
                                        $docStmt = $conn->prepare("INSERT INTO empleado_documentos (empleado_id, tipo, ruta_archivo, nombre_original, mime_type, created_at) VALUES (?, 'alta_imss', ?, ?, ?, NOW())");
                                        if ($docStmt) {
                                            $docStmt->bind_param('isss', $empleadoId, $relativePath, $altaImssInfo['original'], $altaImssInfo['mime']);
                                            if ($docStmt->execute()) {
                                                $docOk = true;
                                            } else {
                                                $docMessage = 'No se pudo registrar el alta del IMSS en la base de datos.';
                                            }
                                            $docStmt->close();
                                        } else {
                                            $docMessage = 'No se pudo preparar la inserción del alta del IMSS.';
                                        }

                                        if (!$docOk && $destPath && file_exists($destPath)) {
                                            unlink($destPath);
                                        }
                                    }
                                }
                            }
                        }

                        if (!$docOk) {
                            if ($destPath && file_exists($destPath)) {
                                unlink($destPath);
                            }
                            $conn->query("DELETE FROM empleado_proyecto WHERE empleado_id = " . (int)$empleadoId);
                            $conn->query("DELETE FROM empleados WHERE id = " . (int)$empleadoId);
                            $conn->query("DELETE FROM users WHERE id = " . (int)$empleadoId);
                            $msg = $docMessage !== '' ? $docMessage : 'Ocurrió un problema al guardar el alta del IMSS. Intenta nuevamente.';
                        } else {
                            $msg = 'Empleado creado, credenciales registradas y alta IMSS guardada.';
                        }
                    } else {
                        // rollback usuario si falla empleado
                        $conn->query("DELETE FROM users WHERE id = " . (int)$userId);
                        $msg = 'Error al crear empleado (FK).';
                    }
                } else {
                    $conn->query("DELETE FROM users WHERE id = " . (int)$userId);
                    $msg = 'Error preparando inserción de empleado.';
                }
            } else {
                $msg = 'Correo en uso o error al crear usuario.';
            }
        }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Nuevo Servicio Especializado - Sistema de Gestión</title>
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
            background: #fafafa;
            min-height: 100vh;
            padding: 20px;
            color: #1a365d;
            line-height: 1.6;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #1a365d;
            text-decoration: none;
            margin-bottom: 20px;
            padding: 12px 20px;
            background: white;
            border-radius: 12px;
            transition: all 0.2s ease;
            box-shadow: 0 2px 12px rgba(26, 54, 93, 0.08);
            border: 2px solid #e2e8f0;
            font-weight: 500;
        }
        
        .back-button:hover {
            background: #f8fafc;
            transform: translateY(-1px);
            border-color: #cbd5e1;
        }
        
        .card {
            background: white;
            border-radius: 24px;
            padding: 48px 40px;
            box-shadow: 0 8px 32px rgba(26, 54, 93, 0.12);
            border: 1px solid #f1f5f9;
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #ff7a00 0%, #1a365d 100%);
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .header-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #ff7a00, #1a365d);
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(255, 122, 0, 0.3);
        }
        
        .header-icon i {
            font-size: 36px;
            color: white;
        }
        
        h1 {
            font-size: 32px;
            font-weight: 700;
            color: #1a365d;
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }
        
        .subtitle {
            font-size: 16px;
            color: #718096;
            font-weight: 400;
        }
        
        .section {
            margin-bottom: 32px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #1a365d;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .section-title i {
            color: #ff7a00;
            font-size: 20px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        
        .form-group {
            position: relative;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #1a365d;
            margin-bottom: 8px;
        }
        
        .required::after {
            content: " *";
            color: #dc2626;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
            font-size: 18px;
            z-index: 2;
        }
        
        input,
        select {
            width: 100%;
            padding: 16px 16px 16px 52px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 400;
            color: #1a365d;
            background: white;
            transition: all 0.2s ease;
            outline: none;
        }

        .file-input-wrapper {
            position: relative;
        }

        .file-input-wrapper input[type="file"] {
            width: 100%;
            padding: 18px;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            background: #f8fafc;
            color: #1a365d;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .file-input-wrapper input[type="file"]:hover {
            border-color: #ff7a00;
            background: #fff7ed;
        }

        .file-input-wrapper input[type="file"]:focus {
            outline: none;
            border-color: #ff7a00;
            box-shadow: 0 0 0 3px rgba(255, 122, 0, 0.1);
            background: #ffffff;
        }
        
        input:focus,
        select:focus {
            border-color: #ff7a00;
            box-shadow: 0 0 0 3px rgba(255, 122, 0, 0.1);
        }
        
        input::placeholder {
            color: #a0aec0;
            font-weight: 400;
        }
        
        select {
            cursor: pointer;
        }
        
        .actions {
            display: flex;
            gap: 16px;
            justify-content: center;
            margin-top: 40px;
        }
        
        .btn {
            padding: 16px 32px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 180px;
            justify-content: center;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #ff7a00 0%, #ff9500 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(255, 122, 0, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(255, 122, 0, 0.4);
        }
        
        .btn-secondary {
            background: white;
            color: #1a365d;
            border: 2px solid #e2e8f0;
        }
        
        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }
        
        .message {
            margin-bottom: 32px;
            padding: 16px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .message.success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-left: 4px solid #22c55e;
            color: #1a365d;
        }
        
        .message.success i {
            color: #22c55e;
        }
        
        .message.error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-left: 4px solid #ef4444;
            color: #1a365d;
        }

        .message.error i {
            color: #ef4444;
        }
        
        .form-help {
            font-size: 13px;
            color: #718096;
            margin-top: 4px;
        }
        
        .password-strength {
            margin-top: 8px;
            display: flex;
            gap: 4px;
        }
        
        .strength-bar {
            height: 4px;
            flex: 1;
            background: #e5e7eb;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        
        .info-card {
            background: rgba(255, 122, 0, 0.05);
            border: 1px solid rgba(255, 122, 0, 0.2);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 32px;
        }
        
        .info-card h3 {
            color: #1a365d;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-card h3 i {
            color: #ff7a00;
        }
        
        .info-card ul {
            color: #1a365d;
            font-size: 14px;
            margin-left: 20px;
            line-height: 1.5;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }
            
            .card {
                padding: 32px 24px;
                border-radius: 20px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .actions {
                flex-direction: column;
                gap: 12px;
            }
            
            .btn {
                width: 100%;
                min-width: unset;
            }
            
            h1 {
                font-size: 28px;
            }
            
            .header-icon {
                width: 64px;
                height: 64px;
            }
            
            .header-icon i {
                font-size: 28px;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 12px;
            }

            input,
            select {
                padding: 14px 14px 14px 48px;
            }
            
            .card {
                padding: 24px 20px;
            }
        }
        
        /* Loading states */
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .btn.loading {
            position: relative;
            color: transparent;
        }
        
        .btn.loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }

        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="empleados.php" class="back-button">
            <i class="fas fa-arrow-left"></i>
            Volver a Servicios Especializados
        </a>
        
        <div class="card fade-in">
            <div class="header">
                <div class="header-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <h1>Nuevo Servicio Especializado</h1>
                <p class="subtitle">Registrar un nuevo miembro del equipo</p>
            </div>
            
            <?php if($msg): ?>
                <div class="message <?php echo (strpos($msg, 'creado') !== false || strpos($msg, 'registrada') !== false) ? 'success' : 'error'; ?>">
                    <i class="fas <?php echo (strpos($msg, 'creado') !== false || strpos($msg, 'registrada') !== false) ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <span><?php echo htmlspecialchars($msg); ?></span>
                </div>
            <?php endif; ?>
            
            <div class="info-card">
                <h3>
                    <i class="fas fa-info-circle"></i>
                    Información importante
                </h3>
                <ul>
                    <li>Los campos marcados con (*) son obligatorios</li>
                    <li>Debes adjuntar el documento de alta del IMSS en PDF o imagen (JPG/PNG)</li>
                    <li>Se crearán credenciales de acceso automáticamente</li>
                    <li>El empleado podrá iniciar sesión con su email y contraseña</li>
                    <li>La asignación de proyecto es opcional y puede cambiarse después</li>
                </ul>
            </div>
            
            <form method="post" id="employeeForm" enctype="multipart/form-data">
                <div class="section">
                    <div class="section-title">
                        <i class="fas fa-user"></i>
                        Información Personal
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="required">Nombre completo</label>
                            <div class="input-wrapper">
                                <i class="fas fa-user"></i>
                                <input type="text" name="nombre" required placeholder="Ej: Juan Pérez López" 
                                       value="<?php echo isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Teléfono</label>
                            <div class="input-wrapper">
                                <i class="fas fa-phone"></i>
                                <input type="tel" name="telefono" required placeholder="Ej: 555 123 4567" 
                                       value="<?php echo isset($_POST['telefono']) ? htmlspecialchars($_POST['telefono']) : ''; ?>">
                            </div>
                            <div class="form-help">Formato: XXX XXX XXXX</div>
                        </div>
                        
                    </div>
                </div>
                
                <div class="section">
                    <div class="section-title">
                        <i class="fas fa-id-card"></i>
                        Documentos Oficiales
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Número de Seguridad Social (NSS)</label>
                            <div class="input-wrapper">
                                <i class="fas fa-id-badge"></i>
                                <input type="text" name="nss" placeholder="XX-XX-XX-XXXX-X" 
                                       value="<?php echo isset($_POST['nss']) ? htmlspecialchars($_POST['nss']) : ''; ?>">
                            </div>
                            <div class="form-help">Formato: XX-XX-XX-XXXX-X</div>
                        </div>
                        
                        <div class="form-group">
                            <label>CURP</label>
                            <div class="input-wrapper">
                                <i class="fas fa-file-alt"></i>
                                <input type="text" name="curp" placeholder="18 caracteres" maxlength="18"
                                       value="<?php echo isset($_POST['curp']) ? htmlspecialchars($_POST['curp']) : ''; ?>">
                            </div>
                            <div class="form-help">18 caracteres alfanuméricos</div>
                        </div>

                        <div class="form-group full-width">
                            <label class="required">Empresa</label>
                            <div class="input-wrapper">
                                <i class="fas fa-building"></i>
                                <select name="empresa" required>
                                    <option value="">Selecciona una empresa</option>
                                    <option value="CEDISA" <?php echo (isset($_POST['empresa']) && $_POST['empresa'] === 'CEDISA') ? 'selected' : ''; ?>>CEDISA</option>
                                    <option value="Stone" <?php echo (isset($_POST['empresa']) && $_POST['empresa'] === 'Stone') ? 'selected' : ''; ?>>Stone</option>
                                    <option value="Remedios" <?php echo (isset($_POST['empresa']) && $_POST['empresa'] === 'Remedios') ? 'selected' : ''; ?>>Remedios</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label class="required">Alta IMSS (PDF o imagen)</label>
                            <div class="file-input-wrapper">
                                <input type="file" name="alta_imss" id="altaImssInput" required accept=".pdf,image/*">
                            </div>
                            <div class="form-help" id="altaImssHelp">Adjunta el comprobante oficial de alta ante el IMSS. Peso máximo 10MB.</div>
                        </div>
                    </div>
                </div>
                
                <div class="section">
                    <div class="section-title">
                        <i class="fas fa-key"></i>
                        Credenciales de Acceso
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="required">Correo electrónico</label>
                            <div class="input-wrapper">
                                <i class="fas fa-envelope"></i>
                                <input type="email" name="email" required placeholder="empleado@empresa.com" 
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                            </div>
                            <div class="form-help">Se usará para iniciar sesión</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Contraseña</label>
                            <div class="input-wrapper">
                                <i class="fas fa-lock"></i>
                                <input type="password" name="password" required placeholder="Mínimo 8 caracteres" 
                                       id="passwordInput" minlength="8">
                            </div>
                            <div class="password-strength" id="passwordStrength">
                                <div class="strength-bar"></div>
                                <div class="strength-bar"></div>
                                <div class="strength-bar"></div>
                                <div class="strength-bar"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="section">
                    <div class="section-title">
                        <i class="fas fa-project-diagram"></i>
                        Asignación de Proyecto
                    </div>
                    
                    <div class="form-group">
                        <label>Proyecto inicial (opcional)</label>
                        <div class="input-wrapper">
                            <i class="fas fa-tasks"></i>
                            <select name="proyecto_id">
                                <option value="0">Sin asignar por ahora</option>
                                <?php foreach($proyectos as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" 
                                            <?php echo (isset($_POST['proyecto_id']) && $_POST['proyecto_id'] == $p['id']) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($p['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-help">Podrás cambiar la asignación después en la lista de servicios especializados</div>
                    </div>
                </div>
                
                <div class="actions">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-plus"></i>
                        Crear Servicio Especializado
                    </button>
                    <a href="empleados.php" class="btn btn-secondary">
                        <i class="fas fa-list"></i>
                        Ver Lista de Servicios Especializados
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Reset form if successfully created
        function resetFormIfOk() {
            const msg = '<?= isset($msg) ? addslashes($msg) : '' ?>';
            if (msg && (msg.toLowerCase().includes('creado') || msg.toLowerCase().includes('registrada'))) {
                const form = document.getElementById('employeeForm');
                if (form) {
                    setTimeout(() => {
                        form.reset();
                        // Reset password strength
                        const bars = document.querySelectorAll('.strength-bar');
                        bars.forEach(bar => bar.style.background = '#e5e7eb');
                        const altaHelp = document.getElementById('altaImssHelp');
                        if (altaHelp) {
                            altaHelp.textContent = 'Adjunta el comprobante oficial de alta ante el IMSS. Peso máximo 10MB.';
                        }
                    }, 2000);
                }
            }
        }
        
        // Password strength indicator
        document.getElementById('passwordInput').addEventListener('input', function() {
            const password = this.value;
            const bars = document.querySelectorAll('.strength-bar');
            const strength = calculatePasswordStrength(password);
            
            bars.forEach((bar, index) => {
                if (index < strength) {
                    bar.style.background = getStrengthColor(strength);
                } else {
                    bar.style.background = '#e5e7eb';
                }
            });
        });
        
        function calculatePasswordStrength(password) {
            let strength = 0;
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            return strength;
        }
        
        function getStrengthColor(strength) {
            const colors = ['#dc2626', '#ea580c', '#ca8a04', '#16a34a'];
            return colors[strength - 1] || '#dc2626';
        }
        
        // Form submission loading state
        document.getElementById('employeeForm').addEventListener('submit', function() {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.classList.add('loading');
        });
        
        // Phone number formatting
        document.querySelector('input[name="telefono"]').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 0) {
                if (value.length <= 3) {
                    value = value;
                } else if (value.length <= 6) {
                    value = value.slice(0, 3) + ' ' + value.slice(3);
                } else {
                    value = value.slice(0, 3) + ' ' + value.slice(3, 6) + ' ' + value.slice(6, 10);
                }
            }
            e.target.value = value;
        });
        
        // NSS formatting
        document.querySelector('input[name="nss"]').addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^\d]/g, '');
            if (value.length > 0) {
                // Format: XX-XX-XX-XXXX-X
                if (value.length <= 2) {
                    value = value;
                } else if (value.length <= 4) {
                    value = value.slice(0, 2) + '-' + value.slice(2);
                } else if (value.length <= 6) {
                    value = value.slice(0, 2) + '-' + value.slice(2, 4) + '-' + value.slice(4);
                } else if (value.length <= 10) {
                    value = value.slice(0, 2) + '-' + value.slice(2, 4) + '-' + value.slice(4, 6) + '-' + value.slice(6);
                } else {
                    value = value.slice(0, 2) + '-' + value.slice(2, 4) + '-' + value.slice(4, 6) + '-' + value.slice(6, 10) + '-' + value.slice(10, 11);
                }
            }
            e.target.value = value;
        });

        const altaImssInput = document.getElementById('altaImssInput');
        const altaImssHelp = document.getElementById('altaImssHelp');
        if (altaImssInput && altaImssHelp) {
            const defaultHelp = altaImssHelp.textContent;
            altaImssInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    const file = this.files[0];
                    const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
                    altaImssHelp.textContent = `Archivo seleccionado: ${file.name} (${sizeMb} MB)`;
                } else {
                    altaImssHelp.textContent = defaultHelp;
                }
            });
        }
        
        // CURP formatting
        document.querySelector('input[name="curp"]').addEventListener('input', function(e) {
            e.target.value = e.target.value.toUpperCase();
        });
        
        // Auto-focus on name field
        document.addEventListener('DOMContentLoaded', function() {
            const nameInput = document.querySelector('input[name="nombre"]');
            if (nameInput && !nameInput.value) {
                nameInput.focus();
            }
        });
        
        // Initialize form reset check
        resetFormIfOk();
        
        // Auto-hide success message
        const message = document.querySelector('.message.success');
        if (message) {
            setTimeout(() => {
                message.style.opacity = '0';
                message.style.transform = 'translateY(-10px)';
                setTimeout(() => message.remove(), 300);
            }, 5000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to clear form (with confirmation)
            if (e.key === 'Escape') {
                if (confirm('¿Deseas limpiar el formulario?')) {
                    document.getElementById('employeeForm').reset();
                    const bars = document.querySelectorAll('.strength-bar');
                    bars.forEach(bar => bar.style.background = '#e5e7eb');
                    document.querySelector('input[name="nombre"]').focus();
                }
            }
        });
    </script>
</body>
</html>