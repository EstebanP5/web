<?php
session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'empresa') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';

$userId = (int)$_SESSION['user_id'];
$userName = trim($_SESSION['user_name'] ?? '');
$firstName = $userName ? explode(' ', $userName)[0] : 'Usuario';

// Obtener la empresa del usuario
$empresaUsuario = '';
$stEmpresa = $conn->prepare("SELECT empresa FROM users WHERE id = ?");
if ($stEmpresa) {
    $stEmpresa->bind_param('i', $userId);
    $stEmpresa->execute();
    $resultEmpresa = $stEmpresa->get_result();
    if ($row = $resultEmpresa->fetch_assoc()) {
        $empresaUsuario = $row['empresa'] ?? '';
    }
    $stEmpresa->close();
}

if (empty($empresaUsuario)) {
    foreach (['CEDISA', 'Stone', 'Remedios'] as $emp) {
        if (stripos($userName, $emp) !== false) {
            $empresaUsuario = $emp;
            break;
        }
    }
}

// Crear tabla de documentos SUA de empresa si no existe
$conn->query("CREATE TABLE IF NOT EXISTS empresa_sua_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa VARCHAR(100) NOT NULL,
    user_id INT NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT DEFAULT NULL,
    fecha_documento DATE DEFAULT NULL,
    ruta_archivo VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    tamano INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_empresa (empresa),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mensaje_exito = '';
$mensaje_error = '';

// Procesar subida de documento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_documento'])) {
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $fecha_documento = $_POST['fecha_documento'] ?? '';
    
    if ($nombre === '') {
        $mensaje_error = 'El nombre del documento es obligatorio.';
    } elseif (empty($empresaUsuario)) {
        $mensaje_error = 'No se puede identificar tu empresa. Contacta al administrador.';
    } elseif (!isset($_FILES['documento']) || $_FILES['documento']['error'] === UPLOAD_ERR_NO_FILE) {
        $mensaje_error = 'Selecciona un archivo para subir.';
    } elseif ($_FILES['documento']['error'] !== UPLOAD_ERR_OK) {
        $mensaje_error = 'Error al subir el archivo. Código: ' . $_FILES['documento']['error'];
    } elseif ($_FILES['documento']['size'] > 20 * 1024 * 1024) {
        $mensaje_error = 'El archivo no debe superar los 20MB.';
    } else {
        $extension = strtolower(pathinfo($_FILES['documento']['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
        
        if (!in_array($extension, $allowedExtensions, true)) {
            $mensaje_error = 'Formato no permitido. Usa: PDF, DOC, DOCX, XLS, XLSX, JPG o PNG.';
        } else {
            $detectedMime = null;
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detectedMime = finfo_file($finfo, $_FILES['documento']['tmp_name']);
                    finfo_close($finfo);
                }
            }
            
            $uploadsDir = dirname(__DIR__) . '/uploads/empresa_sua';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0775, true);
            }
            
            $randomSegment = bin2hex(random_bytes(6));
            $filename = 'sua_' . strtolower($empresaUsuario) . '_' . date('Ymd_His') . '_' . $randomSegment . '.' . $extension;
            $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
            
            if (move_uploaded_file($_FILES['documento']['tmp_name'], $destPath)) {
                $relativePath = 'uploads/empresa_sua/' . $filename;
                $stmt = $conn->prepare('INSERT INTO empresa_sua_documentos (empresa, user_id, nombre, descripcion, fecha_documento, ruta_archivo, nombre_original, mime_type, tamano) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                if ($stmt) {
                    $nombreOriginal = $_FILES['documento']['name'];
                    $tamano = $_FILES['documento']['size'];
                    $fechaDoc = $fecha_documento ?: null;
                    $stmt->bind_param('sisssssi', $empresaUsuario, $userId, $nombre, $descripcion, $fechaDoc, $relativePath, $nombreOriginal, $detectedMime, $tamano);
                    if ($stmt->execute()) {
                        $mensaje_exito = 'Documento SUA subido exitosamente.';
                    } else {
                        $mensaje_error = 'Error al registrar el documento.';
                        @unlink($destPath);
                    }
                    $stmt->close();
                } else {
                    $mensaje_error = 'Error al preparar la consulta.';
                    @unlink($destPath);
                }
            } else {
                $mensaje_error = 'Error al guardar el archivo.';
            }
        }
    }
}

// Obtener documentos de la empresa (solo los que puede ver este usuario)
$documentos = [];
if ($empresaUsuario) {
    $stDocs = $conn->prepare("SELECT * FROM empresa_sua_documentos WHERE empresa = ? ORDER BY created_at DESC");
    if ($stDocs) {
        $stDocs->bind_param('s', $empresaUsuario);
        $stDocs->execute();
        $documentos = $stDocs->get_result()->fetch_all(MYSQLI_ASSOC);
        $stDocs->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentos SUA - <?= htmlspecialchars($empresaUsuario); ?> - ErgoCuida</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
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
            min-height: 100vh;
        }
        
        .header {
            background: linear-gradient(135deg, #1a365d 0%, #2d4a7c 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        
        .header-title i {
            font-size: 28px;
            color: #fbbf24;
        }
        
        .header-title h1 {
            font-size: 22px;
            font-weight: 700;
        }
        
        .header-empresa {
            background: rgba(251, 191, 36, 0.2);
            color: #fbbf24;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .header-nav {
            display: flex;
            gap: 12px;
        }
        
        .nav-link {
            color: white;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.15);
        }
        
        .nav-link.active {
            background: rgba(251, 191, 36, 0.3);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        
        .section {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        
        .section h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section h3 i {
            color: #1a365d;
        }
        
        .upload-form {
            display: grid;
            gap: 16px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .form-group label {
            font-size: 14px;
            font-weight: 500;
            color: #475569;
        }
        
        .form-group input,
        .form-group textarea {
            padding: 12px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #1a365d;
            box-shadow: 0 0 0 3px rgba(26, 54, 93, 0.15);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .file-input-wrapper input[type="file"] {
            padding: 14px;
            border: 2px dashed #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            cursor: pointer;
            width: 100%;
        }
        
        .file-input-wrapper input[type="file"]:hover {
            border-color: #1a365d;
            background: #f1f5f9;
        }
        
        .btn-upload {
            background: linear-gradient(135deg, #1a365d, #2d4a7c);
            color: #fff;
            border: none;
            padding: 14px 28px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn-upload:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(26, 54, 93, 0.35);
        }
        
        .documents-grid {
            display: grid;
            gap: 16px;
        }
        
        .document-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        
        .document-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .document-info {
            flex: 1;
            min-width: 0;
        }
        
        .document-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .document-meta {
            font-size: 13px;
            color: #64748b;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .document-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-view {
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            background: #e0f2fe;
            color: #0369a1;
            transition: all 0.2s;
        }
        
        .btn-view:hover {
            background: #bae6fd;
        }
        
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
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
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .header-nav {
                width: 100%;
                justify-content: center;
            }
            
            .document-card {
                flex-direction: column;
                text-align: center;
            }
            
            .document-info {
                text-align: center;
            }
            
            .document-meta {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="header-title">
                <i class="fas fa-file-pdf"></i>
                <div>
                    <h1>Documentos SUA</h1>
                </div>
                <?php if ($empresaUsuario): ?>
                    <span class="header-empresa"><?= htmlspecialchars($empresaUsuario); ?></span>
                <?php endif; ?>
            </div>
            <div class="header-nav">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="sua.php" class="nav-link active">
                    <i class="fas fa-file-pdf"></i> Documentos SUA
                </a>
                <a href="../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Salir
                </a>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($mensaje_exito): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($mensaje_exito); ?>
            </div>
        <?php endif; ?>

        <?php if ($mensaje_error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($mensaje_error); ?>
            </div>
        <?php endif; ?>

        <?php if ($empresaUsuario): ?>
            <div class="section">
                <h3><i class="fas fa-upload"></i> Subir Documento SUA</h3>
                <form method="POST" enctype="multipart/form-data" class="upload-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nombre">Nombre del documento *</label>
                            <input type="text" id="nombre" name="nombre" required placeholder="Ej: SUA Marzo 2024">
                        </div>
                        <div class="form-group">
                            <label for="fecha_documento">Fecha del documento</label>
                            <input type="date" id="fecha_documento" name="fecha_documento">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="descripcion">Descripción (opcional)</label>
                        <textarea id="descripcion" name="descripcion" placeholder="Descripción o notas adicionales"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Archivo *</label>
                        <div class="file-input-wrapper">
                            <input type="file" name="documento" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                        </div>
                        <small style="color: #64748b; font-size: 12px;">Formatos: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG. Máximo 20MB.</small>
                    </div>
                    <div>
                        <button type="submit" name="subir_documento" class="btn-upload">
                            <i class="fas fa-cloud-upload-alt"></i> Subir Documento
                        </button>
                    </div>
                </form>
            </div>

            <div class="section">
                <h3><i class="fas fa-folder-open"></i> Documentos SUA de <?= htmlspecialchars($empresaUsuario); ?></h3>
                
                <?php if (empty($documentos)): ?>
                    <div class="empty-state">
                        <i class="fas fa-file-alt"></i>
                        <h4>No hay documentos</h4>
                        <p>Sube tu primer documento SUA usando el formulario de arriba.</p>
                    </div>
                <?php else: ?>
                    <div class="documents-grid">
                        <?php foreach ($documentos as $doc): 
                            $tamanoFormateado = $doc['tamano'] ? round($doc['tamano'] / 1024, 1) . ' KB' : '';
                            if ($doc['tamano'] > 1024 * 1024) {
                                $tamanoFormateado = round($doc['tamano'] / (1024 * 1024), 2) . ' MB';
                            }
                        ?>
                        <div class="document-card">
                            <div class="document-icon">
                                <i class="fas fa-file-pdf"></i>
                            </div>
                            <div class="document-info">
                                <div class="document-name"><?= htmlspecialchars($doc['nombre']); ?></div>
                                <div class="document-meta">
                                    <?php if (!empty($doc['fecha_documento'])): ?>
                                        <span><i class="fas fa-calendar-day"></i> <?= date('d/m/Y', strtotime($doc['fecha_documento'])); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-clock"></i> <?= date('d/m/Y H:i', strtotime($doc['created_at'])); ?></span>
                                    <?php if ($tamanoFormateado): ?>
                                        <span><i class="fas fa-file"></i> <?= $tamanoFormateado; ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($doc['descripcion'])): ?>
                                    <div style="font-size: 13px; color: #64748b; margin-top: 6px;"><?= htmlspecialchars($doc['descripcion']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="document-actions">
                                <a href="../<?= htmlspecialchars($doc['ruta_archivo']); ?>" target="_blank" class="btn-view">
                                    <i class="fas fa-eye"></i> Ver
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="section">
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h4>Sin empresa asignada</h4>
                    <p>Tu cuenta no tiene una empresa asignada. Contacta al administrador.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
