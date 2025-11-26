<?php
session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar autenticación y rol de responsable_empresa
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'responsable_empresa') {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$userId = (int)$_SESSION['user_id'];
$userName = trim($_SESSION['user_name'] ?? '');
$firstName = $userName ? explode(' ', $userName)[0] : 'Usuario';

// Obtener la empresa asignada al usuario
$empresaUsuario = '';
$stmt = $conn->prepare("SELECT empresa FROM users WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $empresaUsuario = $row['empresa'] ?? '';
    }
    $stmt->close();
}

if (empty($empresaUsuario)) {
    die('No tienes una empresa asignada. Contacta al administrador.');
}

$mensaje_exito = '';
$mensaje_error = '';

// Crear tabla para documentos SUA de empresa si no existe
$conn->query("CREATE TABLE IF NOT EXISTS empresa_sua_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa VARCHAR(100) NOT NULL,
    user_id INT NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    descripcion TEXT DEFAULT NULL,
    fecha_documento DATE NOT NULL,
    ruta_archivo VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    tamanio INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_empresa (empresa),
    INDEX idx_fecha (fecha_documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Procesar subida de documento SUA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_sua']) && isset($_FILES['documento_sua'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $fecha_documento = trim($_POST['fecha_documento'] ?? '');
    
    if (empty($titulo)) {
        $mensaje_error = 'El título del documento es obligatorio.';
    } elseif (empty($fecha_documento)) {
        $mensaje_error = 'La fecha del documento es obligatoria.';
    } elseif ($_FILES['documento_sua']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['documento_sua']['tmp_name'];
        $nombre_original = basename($_FILES['documento_sua']['name']);
        $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
        $mime_type = $_FILES['documento_sua']['type'] ?? '';
        $tamanio = $_FILES['documento_sua']['size'] ?? 0;
        
        // Solo permitir PDF para SUA
        if ($ext !== 'pdf') {
            $mensaje_error = 'Solo se permiten archivos PDF para documentos SUA.';
        } elseif ($tamanio > 25 * 1024 * 1024) { // 25MB max
            $mensaje_error = 'El archivo excede el tamaño máximo permitido (25MB).';
        } else {
            $uploadDir = __DIR__ . '/../uploads/empresa_sua/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $timestamp = time();
            $empresaSafe = preg_replace('/[^a-zA-Z0-9]/', '_', $empresaUsuario);
            $nombreArchivo = 'sua_' . $empresaSafe . '_' . date('Ymd_His', $timestamp) . '_' . uniqid() . '.pdf';
            $rutaDestino = $uploadDir . $nombreArchivo;
            
            if (move_uploaded_file($tmp, $rutaDestino)) {
                $rutaRelativa = 'uploads/empresa_sua/' . $nombreArchivo;
                $stmt = $conn->prepare('INSERT INTO empresa_sua_documentos (empresa, user_id, titulo, descripcion, fecha_documento, ruta_archivo, nombre_original, mime_type, tamanio) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                if ($stmt) {
                    $stmt->bind_param('sissssssi', $empresaUsuario, $userId, $titulo, $descripcion, $fecha_documento, $rutaRelativa, $nombre_original, $mime_type, $tamanio);
                    if ($stmt->execute()) {
                        $mensaje_exito = 'Documento SUA "' . htmlspecialchars($titulo) . '" subido exitosamente.';
                    } else {
                        $mensaje_error = 'Error al registrar el documento en la base de datos.';
                        @unlink($rutaDestino);
                    }
                    $stmt->close();
                } else {
                    $mensaje_error = 'Error al preparar la consulta.';
                    @unlink($rutaDestino);
                }
            } else {
                $mensaje_error = 'Error al mover el archivo al directorio de destino.';
            }
        }
    } else {
        $codigoError = $_FILES['documento_sua']['error'];
        $mensaje_error = 'Error al subir el archivo. Código: ' . $codigoError;
    }
}

// Obtener documentos SUA de la empresa
$documentos = [];
$stmt = $conn->prepare('SELECT * FROM empresa_sua_documentos WHERE empresa = ? ORDER BY fecha_documento DESC, created_at DESC');
if ($stmt) {
    $stmt->bind_param('s', $empresaUsuario);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $documentos[] = $row;
    }
    $stmt->close();
}

// Helper para formatear tamaño
function formatFileSizeEmpresa($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subir SUA - <?= htmlspecialchars($empresaUsuario); ?> - ErgoCuida</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .header {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .header-content {
            max-width: 1000px;
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
            gap: 16px;
        }
        
        .header-icon {
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        
        .header-title h1 {
            font-size: 22px;
            font-weight: 700;
        }
        
        .back-link {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 24px;
        }
        
        .upload-section {
            background: white;
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        
        .upload-section h2 {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .upload-section h2 i {
            color: #059669;
        }
        
        .upload-form {
            display: grid;
            gap: 18px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #1a365d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-group input[type="text"],
        .form-group input[type="date"],
        .form-group textarea {
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            transition: all 0.2s;
        }
        
        .form-group input[type="text"]:focus,
        .form-group input[type="date"]:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-group input[type="file"] {
            padding: 12px;
            border: 2px dashed #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .form-group input[type="file"]:hover {
            border-color: #059669;
            background: #ecfdf5;
        }
        
        .btn-upload {
            background: linear-gradient(135deg, #059669, #047857);
            color: #fff;
            border: none;
            padding: 14px 28px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        
        .btn-upload:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(5, 150, 105, 0.35);
        }
        
        .documents-section {
            background: white;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        
        .documents-section h2 {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .documents-section h2 i {
            color: #059669;
        }
        
        .document-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 18px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 12px;
            transition: all 0.2s;
        }
        
        .document-item:hover {
            border-color: #059669;
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.1);
        }
        
        .document-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #dc2626;
            font-size: 24px;
            flex-shrink: 0;
        }
        
        .document-info {
            flex: 1;
            min-width: 0;
        }
        
        .document-info h4 {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .document-info p {
            font-size: 13px;
            color: #64748b;
            margin: 0;
        }
        
        .document-meta {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 6px;
        }
        
        .document-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-right: 14px;
        }
        
        .btn-view {
            background: #ecfdf5;
            color: #059669;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-view:hover {
            background: #d1fae5;
        }
        
        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.4;
        }
        
        .alert {
            padding: 16px 20px;
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
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .document-item {
                flex-direction: column;
                text-align: center;
            }
            
            .document-info {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="header-title">
                <div class="header-icon">
                    <i class="fas fa-file-pdf"></i>
                </div>
                <div>
                    <h1>Subir Documentos SUA</h1>
                </div>
            </div>
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i>
                Volver al Dashboard
            </a>
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
        
        <div class="upload-section">
            <h2><i class="fas fa-cloud-upload-alt"></i> Subir Documento SUA</h2>
            <form method="POST" enctype="multipart/form-data" class="upload-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="titulo">Título del documento *</label>
                        <input type="text" id="titulo" name="titulo" required placeholder="Ej: SUA Noviembre 2024">
                    </div>
                    <div class="form-group">
                        <label for="fecha_documento">Fecha del documento *</label>
                        <input type="date" id="fecha_documento" name="fecha_documento" required value="<?= date('Y-m-d'); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="descripcion">Descripción (opcional)</label>
                    <textarea id="descripcion" name="descripcion" placeholder="Notas adicionales sobre este documento..."></textarea>
                </div>
                <div class="form-group">
                    <label for="documento_sua">Archivo PDF *</label>
                    <input type="file" id="documento_sua" name="documento_sua" required accept=".pdf">
                </div>
                <div>
                    <button type="submit" name="subir_sua" value="1" class="btn-upload">
                        <i class="fas fa-upload"></i>
                        Subir Documento SUA
                    </button>
                </div>
                <p style="font-size:12px; color:#94a3b8; margin:8px 0 0;">
                    Solo se permiten archivos PDF. Tamaño máximo: 25MB.
                </p>
            </form>
        </div>
        
        <div class="documents-section">
            <h2><i class="fas fa-folder-open"></i> Documentos SUA de <?= htmlspecialchars($empresaUsuario); ?> (<?= count($documentos); ?>)</h2>
            
            <?php if (empty($documentos)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-pdf"></i>
                    <h4>Sin documentos</h4>
                    <p>Aún no has subido ningún documento SUA. Utiliza el formulario de arriba para agregar tu primer archivo.</p>
                </div>
            <?php else: ?>
                <?php foreach ($documentos as $doc): ?>
                    <div class="document-item">
                        <div class="document-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="document-info">
                            <h4><?= htmlspecialchars($doc['titulo']); ?></h4>
                            <p><?= htmlspecialchars($doc['nombre_original']); ?></p>
                            <?php if (!empty($doc['descripcion'])): ?>
                                <p style="margin-top:4px; font-size:12px;"><?= htmlspecialchars(substr($doc['descripcion'], 0, 100)); ?><?= strlen($doc['descripcion']) > 100 ? '...' : ''; ?></p>
                            <?php endif; ?>
                            <div class="document-meta">
                                <span><i class="fas fa-calendar-alt"></i> Fecha: <?= date('d/m/Y', strtotime($doc['fecha_documento'])); ?></span>
                                <span><i class="fas fa-clock"></i> Subido: <?= date('d/m/Y H:i', strtotime($doc['created_at'])); ?></span>
                                <span><i class="fas fa-weight-hanging"></i> <?= formatFileSizeEmpresa($doc['tamanio']); ?></span>
                            </div>
                        </div>
                        <a href="../<?= htmlspecialchars($doc['ruta_archivo']); ?>" target="_blank" class="btn-view">
                            <i class="fas fa-eye"></i> Ver PDF
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Auto-dismiss alerts
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                alert.style.transition = 'all 0.3s';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>
</body>
</html>
