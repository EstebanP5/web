<?php
session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'pm') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';

$pmId = (int)$_SESSION['user_id'];
$userName = trim($_SESSION['user_name'] ?? '');
$mensaje_exito = '';
$mensaje_error = '';

// Crear tabla de documentos PM si no existe
$conn->query("CREATE TABLE IF NOT EXISTS pm_documentos (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Procesar subida de documentos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_documento'])) {
    $nombre_documento = trim($_POST['nombre_documento'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $archivo = $_FILES['documento'] ?? null;
    
    if (empty($nombre_documento)) {
        $mensaje_error = '❌ El nombre del documento es obligatorio.';
    } elseif (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $mensaje_error = '❌ Debes seleccionar un archivo.';
    } elseif (($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $mensaje_error = '❌ Error al subir el archivo (código ' . (int)$archivo['error'] . ').';
    } elseif (($archivo['size'] ?? 0) <= 0) {
        $mensaje_error = '❌ El archivo está vacío.';
    } elseif (($archivo['size'] ?? 0) > 20 * 1024 * 1024) { // 20MB máximo
        $mensaje_error = '❌ El archivo no debe superar los 20MB.';
    } else {
        $extension = strtolower(pathinfo($archivo['name'] ?? '', PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip', 'rar'];
        
        if (!in_array($extension, $allowedExtensions, true)) {
            $mensaje_error = '❌ Formato de archivo no permitido. Formatos válidos: ' . implode(', ', $allowedExtensions);
        } else {
            $uploadsDir = dirname(__DIR__) . '/uploads/pm_documentos';
            if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
                $mensaje_error = '❌ No se pudo crear el directorio para guardar el documento.';
            } else {
                try {
                    $randomSegment = bin2hex(random_bytes(8));
                } catch (Exception $e) {
                    $randomSegment = substr(sha1(uniqid('', true)), 0, 16);
                }
                
                $filename = 'pm_' . $pmId . '_' . date('Ymd_His') . '_' . $randomSegment . '.' . $extension;
                $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
                
                if (!move_uploaded_file($archivo['tmp_name'], $destPath)) {
                    $mensaje_error = '❌ No se pudo guardar el archivo en el servidor.';
                } else {
                    $relativePath = 'uploads/pm_documentos/' . $filename;
                    $stmt = $conn->prepare('INSERT INTO pm_documentos (pm_id, nombre_documento, descripcion, ruta_archivo, nombre_original, mime_type, tamanio, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
                    if ($stmt) {
                        $tamanio = (int)$archivo['size'];
                        $mimeType = $archivo['type'] ?? 'application/octet-stream';
                        $stmt->bind_param('isssssi', $pmId, $nombre_documento, $descripcion, $relativePath, $archivo['name'], $mimeType, $tamanio);
                        if ($stmt->execute()) {
                            $mensaje_exito = "✅ Documento '" . htmlspecialchars($nombre_documento) . "' subido correctamente.";
                        } else {
                            $mensaje_error = '❌ Error al registrar el documento en la base de datos.';
                            if (file_exists($destPath)) {
                                unlink($destPath);
                            }
                        }
                        $stmt->close();
                    } else {
                        $mensaje_error = '❌ Error al preparar el registro del documento.';
                        if (file_exists($destPath)) {
                            unlink($destPath);
                        }
                    }
                }
            }
        }
    }
}

// Procesar eliminación de documentos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_documento'])) {
    $doc_id = (int)($_POST['documento_id'] ?? 0);
    if ($doc_id > 0) {
        $stmt = $conn->prepare('SELECT ruta_archivo FROM pm_documentos WHERE id = ? AND pm_id = ?');
        $stmt->bind_param('ii', $doc_id, $pmId);
        $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($doc) {
            $stmt = $conn->prepare('DELETE FROM pm_documentos WHERE id = ? AND pm_id = ?');
            $stmt->bind_param('ii', $doc_id, $pmId);
            if ($stmt->execute()) {
                $filepath = dirname(__DIR__) . '/' . $doc['ruta_archivo'];
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
                $mensaje_exito = '✅ Documento eliminado correctamente.';
            } else {
                $mensaje_error = '❌ Error al eliminar el documento.';
            }
            $stmt->close();
        } else {
            $mensaje_error = '❌ Documento no encontrado.';
        }
    }
}

// Obtener documentos del PM
$documentos = [];
$stmt = $conn->prepare('SELECT id, nombre_documento, descripcion, ruta_archivo, nombre_original, mime_type, tamanio, created_at FROM pm_documentos WHERE pm_id = ? ORDER BY created_at DESC');
$stmt->bind_param('i', $pmId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $documentos[] = $row;
}
$stmt->close();

$pageTitle = 'Mis Documentos';
$activePage = 'documentos';
$pageHeading = 'Mis Documentos';
$pageDescription = 'Gestiona tus documentos y archivos personales.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css">
    <style>
        .documentos-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .upload-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .documentos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .documento-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .documento-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        .documento-icon {
            font-size: 48px;
            color: #3b82f6;
            margin-bottom: 15px;
        }
        .documento-nombre {
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 8px;
            color: #1e293b;
        }
        .documento-descripcion {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 12px;
            min-height: 40px;
        }
        .documento-meta {
            font-size: 12px;
            color: #94a3b8;
            margin-bottom: 15px;
        }
        .documento-actions {
            display: flex;
            gap: 10px;
        }
        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-download {
            background: #3b82f6;
            color: white;
        }
        .btn-download:hover {
            background: #2563eb;
        }
        .btn-delete {
            background: #ef4444;
            color: white;
        }
        .btn-delete:hover {
            background: #dc2626;
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
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e293b;
        }
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }
        .btn-primary {
            background: #3b82f6;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .btn-primary:hover {
            background: #2563eb;
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
        .header-nav {
            background: white;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header-nav a {
            color: #3b82f6;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }
        .header-nav a:hover {
            color: #2563eb;
        }
    </style>
</head>
<body>
    <div class="documentos-container">
        <div class="header-nav">
            <a href="dashboard.php"><i class="fas fa-arrow-left"></i> Volver al Dashboard</a>
        </div>

        <h1><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($pageHeading); ?></h1>
        <p style="color: #64748b; margin-bottom: 30px;"><?php echo htmlspecialchars($pageDescription); ?></p>

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

        <div class="upload-card">
            <h2><i class="fas fa-cloud-upload-alt"></i> Subir Nuevo Documento</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="subir_documento" value="1">
                
                <div class="form-group">
                    <label>Nombre del Documento *</label>
                    <input type="text" name="nombre_documento" class="form-control" required placeholder="Ej: Reporte mensual de actividades">
                </div>
                
                <div class="form-group">
                    <label>Descripción (opcional)</label>
                    <textarea name="descripcion" class="form-control" placeholder="Describe brevemente el contenido del documento"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Archivo *</label>
                    <input type="file" name="documento" class="form-control" required>
                    <small style="color: #64748b; display: block; margin-top: 5px;">
                        Formatos permitidos: PDF, Word, Excel, PowerPoint, imágenes, ZIP. Tamaño máximo: 20MB
                    </small>
                </div>
                
                <button type="submit" class="btn-primary">
                    <i class="fas fa-upload"></i> Subir Documento
                </button>
            </form>
        </div>

        <h2><i class="fas fa-folder-open"></i> Mis Documentos (<?php echo count($documentos); ?>)</h2>
        
        <?php if (empty($documentos)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No tienes documentos</h3>
                <p>Sube tu primer documento usando el formulario de arriba.</p>
            </div>
        <?php else: ?>
            <div class="documentos-grid">
                <?php foreach ($documentos as $doc): ?>
                    <div class="documento-card">
                        <div class="documento-icon">
                            <?php
                            $ext = strtolower(pathinfo($doc['nombre_original'], PATHINFO_EXTENSION));
                            $icon = 'fa-file';
                            if (in_array($ext, ['pdf'])) $icon = 'fa-file-pdf';
                            elseif (in_array($ext, ['doc', 'docx'])) $icon = 'fa-file-word';
                            elseif (in_array($ext, ['xls', 'xlsx'])) $icon = 'fa-file-excel';
                            elseif (in_array($ext, ['ppt', 'pptx'])) $icon = 'fa-file-powerpoint';
                            elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) $icon = 'fa-file-image';
                            elseif (in_array($ext, ['zip', 'rar'])) $icon = 'fa-file-archive';
                            ?>
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <div class="documento-nombre"><?php echo htmlspecialchars($doc['nombre_documento']); ?></div>
                        <div class="documento-descripcion">
                            <?php echo htmlspecialchars($doc['descripcion'] ?: 'Sin descripción'); ?>
                        </div>
                        <div class="documento-meta">
                            <div><i class="fas fa-calendar"></i> <?php echo date('d/m/Y H:i', strtotime($doc['created_at'])); ?></div>
                            <div><i class="fas fa-hdd"></i> <?php echo number_format($doc['tamanio'] / 1024, 2); ?> KB</div>
                        </div>
                        <div class="documento-actions">
                            <a href="../<?php echo htmlspecialchars($doc['ruta_archivo']); ?>" target="_blank" class="btn-small btn-download">
                                <i class="fas fa-download"></i> Descargar
                            </a>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('¿Estás seguro de eliminar este documento?');">
                                <input type="hidden" name="eliminar_documento" value="1">
                                <input type="hidden" name="documento_id" value="<?php echo (int)$doc['id']; ?>">
                                <button type="submit" class="btn-small btn-delete">
                                    <i class="fas fa-trash"></i> Eliminar
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-hide alerts after 5 seconds
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
