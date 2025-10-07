
<?php
session_start();
require_once '../includes/db.php';

// Verificar autenticación y rol de administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$mensaje_exito = '';
$mensaje_error = '';

// Obtener lista de proyectos para asignación
$proyectos_query = "SELECT id, nombre FROM grupos WHERE activo = 1 ORDER BY nombre";
$proyectos = $conn->query($proyectos_query)->fetch_all(MYSQLI_ASSOC);

// Procesar subida de video
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_video'])) {
    $titulo = trim($_POST['titulo']);
    $descripcion = trim($_POST['descripcion']);
    $proyecto_id = intval($_POST['proyecto_id']);
    // Cuando el usuario selecciona "Todos los proyectos" usamos NULL para pasar la restricción FK
    if ($proyecto_id <= 0) { $proyecto_id = null; }
    $url_video = trim($_POST['url_video']);
    $path_video = null;
    
    // Procesar archivo de video si se subió
    if (!empty($_FILES['archivo_video']['name'])) {
        $ext = strtolower(pathinfo($_FILES['archivo_video']['name'], PATHINFO_EXTENSION));
        $permitidos = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'wmv', 'flv'];
        
        if (in_array($ext, $permitidos) && $_FILES['archivo_video']['error'] === UPLOAD_ERR_OK) {
            $nombre_archivo = 'video_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.' . $ext;
            $destino = '../uploads/videos/' . $nombre_archivo;
            
            // Crear directorio si no existe
            if (!is_dir('../uploads/videos/')) {
                mkdir('../uploads/videos/', 0777, true);
            }
            
            if (move_uploaded_file($_FILES['archivo_video']['tmp_name'], $destino)) {
                $path_video = 'uploads/videos/' . $nombre_archivo;
            } else {
                $mensaje_error = 'Error al subir el archivo de video.';
            }
        } else {
            $mensaje_error = 'Formato de archivo no permitido. Use: MP4, WebM, OGG, MOV, AVI, WMV, FLV';
        }
    }
    
    // Validar y guardar video
    if ($titulo && ($url_video || $path_video)) {
    $stmt = $conn->prepare("INSERT INTO videos_capacitacion (titulo, descripcion, path_video, url_video, proyecto_id, subido_por, fecha_subida) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("ssssii", $titulo, $descripcion, $path_video, $url_video, $proyecto_id, $user_id); // $proyecto_id puede ser NULL
        
        if ($stmt->execute()) {
            $mensaje_exito = '✅ Video subido correctamente y asignado al proyecto.';
        } else {
            $mensaje_error = '❌ Error al guardar el video en la base de datos.';
        }
    } else if (!$mensaje_error) {
        $mensaje_error = '❌ Debes ingresar un título y proporcionar un enlace o subir un archivo de video.';
    }
}

// Eliminar video
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    
    // Obtener información del video antes de eliminarlo
    $video_query = "SELECT path_video FROM videos_capacitacion WHERE id = ?";
    $stmt = $conn->prepare($video_query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $video_info = $stmt->get_result()->fetch_assoc();
    
    // Eliminar archivo físico si existe
    if ($video_info && $video_info['path_video'] && file_exists('../' . $video_info['path_video'])) {
        unlink('../' . $video_info['path_video']);
    }
    
    // Eliminar registro de la base de datos
    $stmt = $conn->prepare("DELETE FROM videos_capacitacion WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $mensaje_exito = '✅ Video eliminado correctamente.';
    } else {
        $mensaje_error = '❌ Error al eliminar el video.';
    }
}

// Listar videos con información del proyecto y usuario
$videos_query = "
    SELECT v.*, g.nombre as proyecto_nombre, u.name as subido_por_nombre 
    FROM videos_capacitacion v 
    LEFT JOIN grupos g ON v.proyecto_id = g.id 
    LEFT JOIN users u ON v.subido_por = u.id 
    ORDER BY v.fecha_subida DESC
";
$videos = $conn->query($videos_query)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Videos - Ergo PMS</title>
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
            color: #1a365d;
            line-height: 1.5;
        }

        a {
            text-decoration: none;
        }
        
        .header {
            background: #fff;
            border-radius: 20px;
            padding: 32px;
            margin: 24px 0 32px;
            box-shadow: 0 4px 20px rgba(26,54,93,.08);
            border: 1px solid #f1f5f9;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .header-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg,#ff7a00 0%,#1a365d 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 32px;
            box-shadow: 0 8px 32px rgba(255,122,0,.25);
        }

        .header-info h1 {
            font-size: 32px;
            font-weight: 700;
            color: #1a365d;
            margin: 0 0 6px;
            letter-spacing: -.02em;
        }

        .header-info p {
            font-size: 16px;
            color: #718096;
            font-weight: 400;
            margin: 0;
        }
        
        .header-actions {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .nav-btn {
            background: #fff;
            color: #1a365d;
            border: 2px solid #e2e8f0;
            padding: 14px 24px;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: .2s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
            text-decoration: none;
        }
        
        .nav-btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }
        
        .container {
            max-width: 1050px;
            margin: 0 auto;
            padding: 20px;
        }
        
        
        .main-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .section {
            background: #fff;
            border-radius: 20px;
            padding: 40px 36px;
            box-shadow: 0 4px 20px rgba(26,54,93,.08);
            border: 1px solid #f1f5f9;
            margin-bottom: 32px;
        }
        
        .section h3 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 24px;
            color: #1a365d;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1a365d;
            margin: 0 0 6px;
            letter-spacing: .5px;
            text-transform: uppercase;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            background: #fafafa;
            color: #1e293b;
            transition: .2s;
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #ff7a00;
            box-shadow: 0 0 0 3px rgba(255,122,0,.15);
            background: #fff;
        }
        
        .btn {
            padding: 14px 24px;
            border: none;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: .2s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg,#ff7a00 0%,#ff9500 100%);
            color: #fff;
            box-shadow: 0 6px 20px rgba(255,122,0,.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(255,122,0,.4);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4);
        }
        
        .alert {
            margin-top: 26px;
            padding: 16px 18px;
            border-radius: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            margin-bottom: 24px;
        }
        
        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #bbf7d0;
        }
        
        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        
        .video-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        .video-card {
            background: #fff;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 4px 20px rgba(26,54,93,.08);
            border: 1px solid #f1f5f9;
            transition: all 0.3s ease;
        }
        
        .video-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(26,54,93,.15);
        }
        
        .video-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        
        .video-title {
            font-size: 20px;
            font-weight: 600;
            color: #1a365d;
            margin-bottom: 8px;
        }
        
        .video-project {
            font-size: 14px;
            color: #ff7a00;
            font-weight: 500;
        }
        
        .video-description {
            color: #718096;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .video-player {
            margin-bottom: 16px;
            text-align: center;
        }
        
        .video-player iframe,
        .video-player video {
            max-width: 100%;
            border-radius: 8px;
        }
        
        .video-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #94a3b8;
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
        
        /* Responsive Design */
        @media (max-width: 780px) {
            .header {
                padding: 24px;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-actions {
                width: 100%;
                justify-content: stretch;
            }
            
            .header-actions .nav-btn {
                flex: 1;
                justify-content: center;
            }
            
            .main-content {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .section {
                padding: 32px 26px;
            }
            
            .video-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
        }
        
        @media (max-width: 480px) {
            .header-title h1 {
                font-size: 20px;
            }
            
            .video-player iframe,
            .video-player video {
                width: 100%;
                height: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-icon">
                        <i class="fas fa-video"></i>
                    </div>
                    <div class="header-info">
                        <h1>Gestión de Videos</h1>
                        <p>Videos de Capacitación</p>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="admin.php" class="nav-btn">
                        <i class="fas fa-arrow-left"></i>
                        Volver al Panel
                    </a>
                </div>
            </div>
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

        <div class="main-content">
            <div class="section">
                <h3><i class="fas fa-plus-circle"></i> Subir Nuevo Video</h3>
                
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="subir_video" value="1">
                    
                    <div class="form-group">
                        <label for="titulo">Título del Video *</label>
                        <input type="text" id="titulo" name="titulo" required placeholder="Ingresa el título del video">
                    </div>
                    
                    <div class="form-group">
                        <label for="descripcion">Descripción</label>
                        <textarea id="descripcion" name="descripcion" rows="3" placeholder="Describe el contenido del video"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="proyecto_id">Asignar a Proyecto</label>
                        <select id="proyecto_id" name="proyecto_id">
                            <option value="0">Todos los proyectos</option>
                            <?php foreach ($proyectos as $proyecto): ?>
                                <option value="<?php echo $proyecto['id']; ?>">
                                    <?php echo htmlspecialchars($proyecto['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="url_video">URL del Video (YouTube, Vimeo, etc.)</label>
                        <input type="url" id="url_video" name="url_video" placeholder="https://www.youtube.com/watch?v=...">
                    </div>
                    
                    <div class="form-group">
                        <label for="archivo_video">O subir archivo de video</label>
                        <input type="file" id="archivo_video" name="archivo_video" accept="video/*">
                        <small style="color: #64748b; font-size: 12px;">
                            Formatos permitidos: MP4, WebM, OGG, MOV, AVI, WMV, FLV
                        </small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i>
                        Subir Video
                    </button>
                </form>
            </div>
            
            <div class="section">
                <h3><i class="fas fa-info-circle"></i> Información</h3>
                <div style="color: #64748b; line-height: 1.6;">
                    <p><strong>📹 Subir videos:</strong></p>
                    <ul style="margin: 10px 0 20px 20px;">
                        <li>URL: Pega enlaces de YouTube, Vimeo, etc.</li>
                        <li>Archivo: Sube videos desde tu dispositivo</li>
                        <li>Asigna videos a proyectos específicos</li>
                    </ul>
                    
                    <p><strong>🎯 Gestión:</strong></p>
                    <ul style="margin: 10px 0 20px 20px;">
                        <li>Los videos se muestran por fecha</li>
                        <li>Los trabajadores pueden ver videos asignados</li>
                        <li>Los PM pueden acceder a videos de sus proyectos</li>
                    </ul>
                </div>
            </div>
        </div>

        <div style="grid-column: 1 / -1;">
            <div class="section">
                <h3><i class="fas fa-video"></i> Videos Subidos</h3>
                
                <?php if (empty($videos)): ?>
                    <div class="empty-state">
                        <i class="fas fa-video"></i>
                        <h4>No hay videos disponibles</h4>
                        <p>Sube tu primer video de capacitación</p>
                    </div>
                <?php else: ?>
                    <div class="video-grid">
                        <?php foreach ($videos as $video): ?>
                            <div class="video-card">
                                <div class="video-header">
                                    <div>
                                        <div class="video-title"><?php echo htmlspecialchars($video['titulo']); ?></div>
                                        <div class="video-project">
                                            <i class="fas fa-project-diagram"></i>
                                            <?php echo $video['proyecto_nombre'] ? htmlspecialchars($video['proyecto_nombre']) : 'Todos los proyectos'; ?>
                                        </div>
                                    </div>
                                    <a href="?eliminar=<?php echo $video['id']; ?>" 
                                       onclick="return confirm('¿Estás seguro de eliminar este video?')" 
                                       class="btn btn-danger" style="font-size: 12px; padding: 6px 12px;">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                                
                                <?php if ($video['descripcion']): ?>
                                    <div class="video-description">
                                        <?php echo nl2br(htmlspecialchars($video['descripcion'])); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="video-player">
                                    <?php
                                    if (!empty($video['url_video'])) {
                                        // Embed YouTube
                                        if (strpos($video['url_video'], 'youtube.com') !== false || strpos($video['url_video'], 'youtu.be') !== false) {
                                            if (preg_match('/(?:v=|youtu.be\/)([\w-]+)/', $video['url_video'], $matches)) {
                                                $video_id = $matches[1];
                                                echo '<iframe width="100%" height="300" src="https://www.youtube.com/embed/' . $video_id . '" frameborder="0" allowfullscreen style="border-radius: 8px;"></iframe>';
                                            }
                                        }
                                        // Embed Vimeo
                                        elseif (strpos($video['url_video'], 'vimeo.com') !== false) {
                                            if (preg_match('/vimeo.com\/(\d+)/', $video['url_video'], $matches)) {
                                                $video_id = $matches[1];
                                                echo '<iframe src="https://player.vimeo.com/video/' . $video_id . '" width="100%" height="300" frameborder="0" allowfullscreen style="border-radius: 8px;"></iframe>';
                                            }
                                        }
                                        // Enlace genérico
                                        else {
                                            echo '<div style="padding: 40px; background: #f8fafc; border-radius: 8px; text-align: center;">';
                                            echo '<i class="fas fa-external-link-alt" style="font-size: 24px; color: #6366f1; margin-bottom: 12px;"></i><br>';
                                            echo '<a href="' . htmlspecialchars($video['url_video']) . '" target="_blank" class="btn btn-primary">Ver Video</a>';
                                            echo '</div>';
                                        }
                                    }
                                    // Video subido
                                    elseif (!empty($video['path_video'])) {
                                        echo '<video width="100%" height="300" controls style="border-radius: 8px;">';
                                        echo '<source src="../' . htmlspecialchars($video['path_video']) . '" type="video/mp4">';
                                        echo 'Tu navegador no soporta la reproducción de video.';
                                        echo '</video>';
                                    }
                                    // Sin video
                                    else {
                                        echo '<div style="padding: 40px; background: #fef2f2; border-radius: 8px; text-align: center; color: #991b1b;">';
                                        echo '<i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 12px;"></i><br>';
                                        echo 'Video no disponible';
                                        echo '</div>';
                                    }
                                    ?>
                                </div>
                                
                                <div class="video-footer">
                                    <div>
                                        <i class="fas fa-user"></i>
                                        <?php echo htmlspecialchars($video['subido_por_nombre'] ?? 'Admin'); ?>
                                    </div>
                                    <div>
                                        <i class="fas fa-calendar"></i>
                                        <?php echo date('d/m/Y H:i', strtotime($video['fecha_subida'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
