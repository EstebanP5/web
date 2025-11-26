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

// Crear tabla de documentos de PM si no existe
$conn->query("CREATE TABLE IF NOT EXISTS pm_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pm_user_id INT NOT NULL,
    proyecto_id INT DEFAULT NULL,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT DEFAULT NULL,
    ruta_archivo VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    tamano INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pm_user (pm_user_id),
    INDEX idx_proyecto (proyecto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mensaje_exito = '';
$mensaje_error = '';

// Obtener proyectos asignados al PM
$proyectos = [];
$stProyectos = $conn->prepare("SELECT g.id, g.nombre, g.empresa
    FROM proyectos_pm ppm
    JOIN grupos g ON ppm.proyecto_id = g.id
    WHERE ppm.user_id = ? AND g.activo = 1
    ORDER BY g.nombre");
if ($stProyectos) {
    $stProyectos->bind_param('i', $pmId);
    $stProyectos->execute();
    $rsProyectos = $stProyectos->get_result();
    while ($proyecto = $rsProyectos->fetch_assoc()) {
        $proyectos[] = $proyecto;
    }
    $stProyectos->close();
}

// Procesar subida de documento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_documento'])) {
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $proyecto_id = isset($_POST['proyecto_id']) && $_POST['proyecto_id'] !== '' ? (int)$_POST['proyecto_id'] : null;
    
    $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif'];
    $allowedMimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg',
        'image/png',
        'image/gif'
    ];
    $maxSize = 20 * 1024 * 1024; // 20MB
    
    if ($nombre === '') {
        $mensaje_error = 'El nombre del documento es obligatorio.';
    } elseif (!isset($_FILES['documento']) || $_FILES['documento']['error'] === UPLOAD_ERR_NO_FILE) {
        $mensaje_error = 'Selecciona un archivo para subir.';
    } elseif ($_FILES['documento']['error'] !== UPLOAD_ERR_OK) {
        $mensaje_error = 'Error al subir el archivo. Código: ' . $_FILES['documento']['error'];
    } elseif ($_FILES['documento']['size'] > $maxSize) {
        $mensaje_error = 'El archivo no debe superar los 20MB.';
    } else {
        $extension = strtolower(pathinfo($_FILES['documento']['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            $mensaje_error = 'Formato no permitido. Usa: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG o GIF.';
        } else {
            $detectedMime = null;
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detectedMime = finfo_file($finfo, $_FILES['documento']['tmp_name']);
                    finfo_close($finfo);
                }
            }
            
            // Verificar proyecto asignado si se seleccionó uno
            if ($proyecto_id !== null) {
                $proyectoValido = false;
                foreach ($proyectos as $p) {
                    if ((int)$p['id'] === $proyecto_id) {
                        $proyectoValido = true;
                        break;
                    }
                }
                if (!$proyectoValido) {
                    $mensaje_error = 'Proyecto no válido.';
                }
            }
            
            if ($mensaje_error === '') {
                $uploadsDir = dirname(__DIR__) . '/uploads/pm_documentos';
                if (!is_dir($uploadsDir)) {
                    mkdir($uploadsDir, 0775, true);
                }
                
                $randomSegment = bin2hex(random_bytes(6));
                $filename = 'pm_' . $pmId . '_' . date('Ymd_His') . '_' . $randomSegment . '.' . $extension;
                $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
                
                if (move_uploaded_file($_FILES['documento']['tmp_name'], $destPath)) {
                    $relativePath = 'uploads/pm_documentos/' . $filename;
                    $stmt = $conn->prepare('INSERT INTO pm_documentos (pm_user_id, proyecto_id, nombre, descripcion, ruta_archivo, nombre_original, mime_type, tamano) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    if ($stmt) {
                        $nombreOriginal = $_FILES['documento']['name'];
                        $tamano = $_FILES['documento']['size'];
                        $stmt->bind_param('iisssssi', $pmId, $proyecto_id, $nombre, $descripcion, $relativePath, $nombreOriginal, $detectedMime, $tamano);
                        if ($stmt->execute()) {
                            $mensaje_exito = 'Documento subido exitosamente.';
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
}

// Eliminar documento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_documento'])) {
    $docId = (int)($_POST['documento_id'] ?? 0);
    if ($docId > 0) {
        // Verificar que el documento pertenezca al PM
        $stmt = $conn->prepare('SELECT ruta_archivo FROM pm_documentos WHERE id = ? AND pm_user_id = ?');
        if ($stmt) {
            $stmt->bind_param('ii', $docId, $pmId);
            $stmt->execute();
            $result = $stmt->get_result();
            $doc = $result->fetch_assoc();
            $stmt->close();
            
            if ($doc) {
                $rutaCompleta = dirname(__DIR__) . '/' . $doc['ruta_archivo'];
                if (file_exists($rutaCompleta)) {
                    @unlink($rutaCompleta);
                }
                
                $stmt = $conn->prepare('DELETE FROM pm_documentos WHERE id = ? AND pm_user_id = ?');
                if ($stmt) {
                    $stmt->bind_param('ii', $docId, $pmId);
                    if ($stmt->execute()) {
                        $mensaje_exito = 'Documento eliminado.';
                    } else {
                        $mensaje_error = 'Error al eliminar el documento.';
                    }
                    $stmt->close();
                }
            } else {
                $mensaje_error = 'Documento no encontrado.';
            }
        }
    }
}

// Obtener documentos del PM
$documentos = [];
$stDocs = $conn->prepare("SELECT d.*, g.nombre AS proyecto_nombre 
    FROM pm_documentos d 
    LEFT JOIN grupos g ON d.proyecto_id = g.id 
    WHERE d.pm_user_id = ? 
    ORDER BY d.created_at DESC");
if ($stDocs) {
    $stDocs->bind_param('i', $pmId);
    $stDocs->execute();
    $documentos = $stDocs->get_result()->fetch_all(MYSQLI_ASSOC);
    $stDocs->close();
}

$pmCssPath = __DIR__ . '/../assets/pm.css';
$pmCssVersion = file_exists($pmCssPath) ? filemtime($pmCssPath) : time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentos - PM - ErgoCuida</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/pm.css?v=<?= $pmCssVersion; ?>">
    <style>
        .documents-section {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .documents-section h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1a365d;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .upload-form {
            display: grid;
            gap: 16px;
            margin-bottom: 24px;
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
        .form-group select,
        .form-group textarea {
            padding: 12px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .file-input-wrapper {
            position: relative;
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
            border-color: #f59e0b;
            background: #fffbeb;
        }
        .btn-upload {
            background: linear-gradient(135deg, #f59e0b, #d97706);
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
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.35);
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
            transition: box-shadow 0.2s;
        }
        .document-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .document-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 20px;
            flex-shrink: 0;
        }
        .document-icon.pdf { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .document-icon.doc { background: linear-gradient(135deg, #2563eb, #1d4ed8); }
        .document-icon.xls { background: linear-gradient(135deg, #22c55e, #16a34a); }
        .document-icon.img { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        .document-info {
            flex: 1;
            min-width: 0;
        }
        .document-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
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
        .btn-view, .btn-delete {
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
            transition: all 0.2s;
        }
        .btn-view {
            background: #e0f2fe;
            color: #0369a1;
        }
        .btn-view:hover {
            background: #bae6fd;
        }
        .btn-delete {
            background: #fee2e2;
            color: #dc2626;
        }
        .btn-delete:hover {
            background: #fecaca;
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
            .document-actions {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<?php require_once 'common/navigation.php'; ?>

<div class="pm-page">
    <header class="pm-header">
        <div>
            <h1 class="pm-header__title"><i class="fas fa-file-alt"></i> Documentos</h1>
            <p class="pm-header__subtitle">Sube y gestiona documentos relacionados con tus proyectos</p>
        </div>
        <div class="pm-header__actions">
            <a href="dashboard.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Volver al Dashboard</a>
        </div>
    </header>

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

    <div class="documents-section">
        <h3><i class="fas fa-upload"></i> Subir Nuevo Documento</h3>
        <form method="POST" enctype="multipart/form-data" class="upload-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="nombre">Nombre del documento *</label>
                    <input type="text" id="nombre" name="nombre" required placeholder="Ej: Contrato de proyecto">
                </div>
                <div class="form-group">
                    <label for="proyecto_id">Proyecto (opcional)</label>
                    <select id="proyecto_id" name="proyecto_id">
                        <option value="">Sin proyecto específico</option>
                        <?php foreach ($proyectos as $p): ?>
                            <option value="<?= (int)$p['id']; ?>"><?= htmlspecialchars($p['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="descripcion">Descripción (opcional)</label>
                <textarea id="descripcion" name="descripcion" placeholder="Breve descripción del contenido del documento"></textarea>
            </div>
            <div class="form-group">
                <label>Archivo *</label>
                <div class="file-input-wrapper">
                    <input type="file" name="documento" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif">
                </div>
                <small style="color: #64748b; font-size: 12px;">Formatos: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, GIF. Máximo 20MB.</small>
            </div>
            <div>
                <button type="submit" name="subir_documento" class="btn-upload">
                    <i class="fas fa-cloud-upload-alt"></i> Subir Documento
                </button>
            </div>
        </form>
    </div>

    <div class="documents-section">
        <h3><i class="fas fa-folder-open"></i> Mis Documentos</h3>
        
        <?php if (empty($documentos)): ?>
            <div class="empty-state">
                <i class="fas fa-file-alt"></i>
                <h4>No hay documentos</h4>
                <p>Sube tu primer documento usando el formulario de arriba.</p>
            </div>
        <?php else: ?>
            <div class="documents-grid">
                <?php foreach ($documentos as $doc): 
                    $ext = strtolower(pathinfo($doc['nombre_original'] ?? '', PATHINFO_EXTENSION));
                    $iconClass = 'fas fa-file';
                    $typeClass = '';
                    if ($ext === 'pdf') {
                        $iconClass = 'fas fa-file-pdf';
                        $typeClass = 'pdf';
                    } elseif (in_array($ext, ['doc', 'docx'])) {
                        $iconClass = 'fas fa-file-word';
                        $typeClass = 'doc';
                    } elseif (in_array($ext, ['xls', 'xlsx'])) {
                        $iconClass = 'fas fa-file-excel';
                        $typeClass = 'xls';
                    } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                        $iconClass = 'fas fa-file-image';
                        $typeClass = 'img';
                    }
                    $tamanoFormateado = $doc['tamano'] ? round($doc['tamano'] / 1024, 1) . ' KB' : '';
                    if ($doc['tamano'] > 1024 * 1024) {
                        $tamanoFormateado = round($doc['tamano'] / (1024 * 1024), 2) . ' MB';
                    }
                ?>
                <div class="document-card">
                    <div class="document-icon <?= $typeClass; ?>">
                        <i class="<?= $iconClass; ?>"></i>
                    </div>
                    <div class="document-info">
                        <div class="document-name"><?= htmlspecialchars($doc['nombre']); ?></div>
                        <div class="document-meta">
                            <?php if (!empty($doc['proyecto_nombre'])): ?>
                                <span><i class="fas fa-project-diagram"></i> <?= htmlspecialchars($doc['proyecto_nombre']); ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($doc['created_at'])); ?></span>
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
                        <form method="POST" style="display: inline;" onsubmit="return confirm('¿Eliminar este documento?');">
                            <input type="hidden" name="documento_id" value="<?= (int)$doc['id']; ?>">
                            <button type="submit" name="eliminar_documento" class="btn-delete">
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
    // Auto-hide alerts
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);
</script>
</body>
</html>
