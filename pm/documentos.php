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
$firstName = $userName ? explode(' ', $userName)[0] : 'PM';

$mensaje_exito = '';
$mensaje_error = '';

// Crear tabla de documentos PM si no existe
$conn->query("CREATE TABLE IF NOT EXISTS pm_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pm_user_id INT NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    descripcion TEXT DEFAULT NULL,
    ruta_archivo VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    tamanio INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pm_user (pm_user_id),
    CONSTRAINT fk_pm_documentos_user FOREIGN KEY (pm_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Procesar subida de documento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_documento']) && isset($_FILES['documento'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    
    if (empty($titulo)) {
        $mensaje_error = 'El título del documento es obligatorio.';
    } elseif ($_FILES['documento']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['documento']['tmp_name'];
        $nombre_original = basename($_FILES['documento']['name']);
        $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
        $mime_type = $_FILES['documento']['type'] ?? '';
        $tamanio = $_FILES['documento']['size'] ?? 0;
        
        // Extensiones permitidas
        $extensiones_permitidas = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'csv'];
        
        if (!in_array($ext, $extensiones_permitidas)) {
            $mensaje_error = 'Formato no permitido. Extensiones válidas: ' . implode(', ', $extensiones_permitidas);
        } elseif ($tamanio > 20 * 1024 * 1024) { // 20MB max
            $mensaje_error = 'El archivo excede el tamaño máximo permitido (20MB).';
        } else {
            $uploadDir = __DIR__ . '/../uploads/pm_documentos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $timestamp = time();
            $nombreArchivo = 'pm_' . $pmId . '_' . date('Ymd_His', $timestamp) . '_' . uniqid() . '.' . $ext;
            $rutaDestino = $uploadDir . $nombreArchivo;
            
            if (move_uploaded_file($tmp, $rutaDestino)) {
                $rutaRelativa = 'uploads/pm_documentos/' . $nombreArchivo;
                $stmt = $conn->prepare('INSERT INTO pm_documentos (pm_user_id, titulo, descripcion, ruta_archivo, nombre_original, mime_type, tamanio) VALUES (?, ?, ?, ?, ?, ?, ?)');
                if ($stmt) {
                    $stmt->bind_param('isssssi', $pmId, $titulo, $descripcion, $rutaRelativa, $nombre_original, $mime_type, $tamanio);
                    if ($stmt->execute()) {
                        $mensaje_exito = 'Documento "' . htmlspecialchars($titulo) . '" subido exitosamente.';
                    } else {
                        $mensaje_error = 'Error al registrar el documento en la base de datos.';
                        if (file_exists($rutaDestino)) {
                            unlink($rutaDestino);
                        }
                    }
                    $stmt->close();
                } else {
                    $mensaje_error = 'Error al preparar la consulta.';
                    if (file_exists($rutaDestino)) {
                        unlink($rutaDestino);
                    }
                }
            } else {
                $mensaje_error = 'Error al mover el archivo al directorio de destino.';
            }
        }
    } else {
        $codigoError = $_FILES['documento']['error'];
        $mensaje_error = 'Error al subir el archivo. Código: ' . $codigoError;
    }
}

// Eliminar documento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_documento'])) {
    $documento_id = (int)($_POST['documento_id'] ?? 0);
    
    if ($documento_id > 0) {
        // Verificar que el documento pertenece al PM
        $stmt = $conn->prepare('SELECT ruta_archivo FROM pm_documentos WHERE id = ? AND pm_user_id = ?');
        if ($stmt) {
            $stmt->bind_param('ii', $documento_id, $pmId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $rutaArchivo = __DIR__ . '/../' . $row['ruta_archivo'];
                if (file_exists($rutaArchivo)) {
                    unlink($rutaArchivo);
                }
                
                $stmtDelete = $conn->prepare('DELETE FROM pm_documentos WHERE id = ? AND pm_user_id = ?');
                if ($stmtDelete) {
                    $stmtDelete->bind_param('ii', $documento_id, $pmId);
                    if ($stmtDelete->execute()) {
                        $mensaje_exito = 'Documento eliminado exitosamente.';
                    } else {
                        $mensaje_error = 'Error al eliminar el documento.';
                    }
                    $stmtDelete->close();
                }
            } else {
                $mensaje_error = 'Documento no encontrado o no tienes permiso para eliminarlo.';
            }
            $stmt->close();
        }
    }
}

// Obtener documentos del PM
$documentos = [];
$stmt = $conn->prepare('SELECT * FROM pm_documentos WHERE pm_user_id = ? ORDER BY created_at DESC');
if ($stmt) {
    $stmt->bind_param('i', $pmId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $documentos[] = $row;
    }
    $stmt->close();
}

// Helper para formatear tamaño
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}

// Helper para icono según extensión
function getFileIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'pdf' => 'fa-file-pdf',
        'doc' => 'fa-file-word',
        'docx' => 'fa-file-word',
        'xls' => 'fa-file-excel',
        'xlsx' => 'fa-file-excel',
        'jpg' => 'fa-file-image',
        'jpeg' => 'fa-file-image',
        'png' => 'fa-file-image',
        'gif' => 'fa-file-image',
        'txt' => 'fa-file-alt',
        'csv' => 'fa-file-csv',
    ];
    return $icons[$ext] ?? 'fa-file';
}

$pmCssPath = __DIR__ . '/../assets/pm.css';
$pmCssVersion = file_exists($pmCssPath) ? filemtime($pmCssPath) : time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Documentos - PM - ErgoCuida</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/pm.css?v=<?= $pmCssVersion; ?>">
    <style>
        .docs-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .docs-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 12px 28px rgba(37, 99, 235, 0.22);
        }
        
        .docs-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .docs-header p {
            font-size: 16px;
            opacity: 0.9;
            margin: 0;
        }
        
        .upload-section {
            background: #fff;
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 28px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
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
        .form-group textarea {
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            transition: all 0.2s;
        }
        
        .form-group input[type="text"]:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .file-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .file-input-wrapper input[type="file"] {
            flex: 1;
            padding: 12px;
            border: 2px dashed #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .file-input-wrapper input[type="file"]:hover {
            border-color: #2563eb;
            background: #eff6ff;
        }
        
        .btn-upload {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
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
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.35);
        }
        
        .documents-section {
            background: #fff;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
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
        
        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 18px;
        }
        
        .document-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.2s;
        }
        
        .document-card:hover {
            border-color: #2563eb;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.12);
        }
        
        .document-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 12px;
        }
        
        .document-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4f46e5;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .document-icon.pdf { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #dc2626; }
        .document-icon.word { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #2563eb; }
        .document-icon.excel { background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #16a34a; }
        .document-icon.image { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #d97706; }
        
        .document-info h3 {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 4px;
            line-height: 1.3;
            word-break: break-word;
        }
        
        .document-info p {
            font-size: 13px;
            color: #64748b;
            margin: 0;
        }
        
        .document-meta {
            font-size: 12px;
            color: #94a3b8;
            margin-bottom: 14px;
        }
        
        .document-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-right: 12px;
        }
        
        .document-actions {
            display: flex;
            gap: 10px;
        }
        
        .document-actions a,
        .document-actions button {
            flex: 1;
            padding: 10px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn-view {
            background: #eff6ff;
            color: #2563eb;
            border: none;
        }
        
        .btn-view:hover {
            background: #dbeafe;
        }
        
        .btn-delete {
            background: #fef2f2;
            color: #dc2626;
            border: none;
        }
        
        .btn-delete:hover {
            background: #fee2e2;
        }
        
        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 56px;
            margin-bottom: 16px;
            opacity: 0.4;
        }
        
        .empty-state h3 {
            font-size: 20px;
            font-weight: 600;
            color: #475569;
            margin: 0 0 8px;
        }
        
        .empty-state p {
            margin: 0;
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
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-weight: 500;
            text-decoration: none;
            margin-bottom: 20px;
            transition: color 0.2s;
        }
        
        .back-link:hover {
            color: #2563eb;
        }
        
        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .documents-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php require_once 'common/navigation.php'; ?>

<div class="docs-container">
    <a href="dashboard.php" class="back-link">
        <i class="fas fa-arrow-left"></i>
        Volver al Dashboard
    </a>
    
    <div class="docs-header">
        <h1><i class="fas fa-folder-open"></i> Mis Documentos</h1>
        <p>Sube y gestiona tus documentos de trabajo. Solo tú podrás ver los archivos que subas aquí.</p>
    </div>
    
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
        <h2><i class="fas fa-cloud-upload-alt"></i> Subir Documento</h2>
        <form method="POST" enctype="multipart/form-data" class="upload-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="titulo">Título del documento *</label>
                    <input type="text" id="titulo" name="titulo" required placeholder="Ej: Contrato de proyecto XYZ">
                </div>
                <div class="form-group">
                    <label for="documento">Archivo *</label>
                    <div class="file-input-wrapper">
                        <input type="file" id="documento" name="documento" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt,.csv">
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label for="descripcion">Descripción (opcional)</label>
                <textarea id="descripcion" name="descripcion" placeholder="Agrega una descripción o notas sobre este documento..."></textarea>
            </div>
            <div>
                <button type="submit" name="subir_documento" value="1" class="btn-upload">
                    <i class="fas fa-upload"></i>
                    Subir Documento
                </button>
            </div>
            <p style="font-size:12px; color:#94a3b8; margin:8px 0 0;">
                Formatos permitidos: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, GIF, TXT, CSV. Tamaño máximo: 20MB.
            </p>
        </form>
    </div>
    
    <div class="documents-section">
        <h2><i class="fas fa-file-alt"></i> Mis Documentos (<?= count($documentos); ?>)</h2>
        
        <?php if (empty($documentos)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>Sin documentos</h3>
                <p>Aún no has subido ningún documento. Utiliza el formulario de arriba para agregar tu primer archivo.</p>
            </div>
        <?php else: ?>
            <div class="documents-grid">
                <?php foreach ($documentos as $doc): ?>
                    <?php
                        $ext = strtolower(pathinfo($doc['nombre_original'], PATHINFO_EXTENSION));
                        $iconClass = '';
                        if ($ext === 'pdf') $iconClass = 'pdf';
                        elseif (in_array($ext, ['doc', 'docx'])) $iconClass = 'word';
                        elseif (in_array($ext, ['xls', 'xlsx', 'csv'])) $iconClass = 'excel';
                        elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) $iconClass = 'image';
                    ?>
                    <div class="document-card">
                        <div class="document-header">
                            <div class="document-icon <?= $iconClass; ?>">
                                <i class="fas <?= getFileIcon($doc['nombre_original']); ?>"></i>
                            </div>
                            <div class="document-info">
                                <h3><?= htmlspecialchars($doc['titulo']); ?></h3>
                                <p><?= htmlspecialchars($doc['nombre_original']); ?></p>
                            </div>
                        </div>
                        <?php if (!empty($doc['descripcion'])): ?>
                            <p style="font-size:13px; color:#64748b; margin-bottom:12px;">
                                <?= htmlspecialchars(substr($doc['descripcion'], 0, 100)); ?><?= strlen($doc['descripcion']) > 100 ? '...' : ''; ?>
                            </p>
                        <?php endif; ?>
                        <div class="document-meta">
                            <span><i class="fas fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($doc['created_at'])); ?></span>
                            <span><i class="fas fa-weight-hanging"></i> <?= formatFileSize($doc['tamanio']); ?></span>
                        </div>
                        <div class="document-actions">
                            <a href="../<?= htmlspecialchars($doc['ruta_archivo']); ?>" target="_blank" class="btn-view">
                                <i class="fas fa-eye"></i> Ver
                            </a>
                            <form method="POST" style="flex:1; display:contents;" onsubmit="return confirm('¿Estás seguro de eliminar este documento?');">
                                <input type="hidden" name="documento_id" value="<?= (int)$doc['id']; ?>">
                                <button type="submit" name="eliminar_documento" value="1" class="btn-delete">
                                    <i class="fas fa-trash"></i> Eliminar
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
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
