<?php
/*
    Visor de videos de capacitación compartido para roles: admin, pm, responsable, servicio_especializado, empleado.
  Funcionalidades clave:
  - Admin puede subir (URL o archivo) y borrar (borrado sólo vía módulo admin/videos.php).
  - Otros roles solo visualizan videos globales (proyecto_id = 0 o NULL) y los de sus proyectos asignados.
  - Búsqueda y filtrado por proyecto.
*/

require_once '../includes/db.php';
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['admin','pm','responsable','servicio_especializado','empleado'])) {
    header('Location: ../login.php');
    exit;
}

$rol = $_SESSION['user_rol'];
$user_id = (int)$_SESSION['user_id'];
$es_admin = ($rol === 'admin');
$mensaje = '';

// Subida rápida (solo URL) opcional desde este visor – mantenemos subida de archivos sólo en admin/videos.php
if ($es_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion']==='subir_video') {
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $url_video = trim($_POST['url_video'] ?? '');
    $proyecto_id = (int)($_POST['proyecto_id'] ?? 0); // 0 = global
    $path_video = null;

    // Si se sube archivo tratarlo con prioridad frente a URL
    if (!empty($_FILES['archivo_video']['name'])) {
        $ext = strtolower(pathinfo($_FILES['archivo_video']['name'], PATHINFO_EXTENSION));
        $permitidos = ['mp4','webm','ogg','mov','avi','wmv','flv'];
        if (in_array($ext,$permitidos) && $_FILES['archivo_video']['error']===UPLOAD_ERR_OK) {
            $dir = '../uploads/videos/';
            if (!is_dir($dir)) { mkdir($dir,0777,true); }
            $nombre_archivo = 'vid_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
            $destino = $dir.$nombre_archivo;
            if (move_uploaded_file($_FILES['archivo_video']['tmp_name'],$destino)) {
                $path_video = 'uploads/videos/'.$nombre_archivo;
                // Si hay archivo, podemos ignorar URL vacía
                if (!$url_video) { $url_video = null; }
            } else {
                $mensaje = '<span style="color:red">Error al mover archivo.</span>';
            }
        } else {
            $mensaje = '<span style="color:red">Formato inválido o error de subida.</span>';
        }
    }

    if (!$mensaje) {
        if ($titulo && ($url_video || $path_video)) {
            $stmt = $conn->prepare("INSERT INTO videos_capacitacion (titulo, descripcion, path_video, url_video, proyecto_id, subido_por, fecha_subida) VALUES (?,?,?,?,?, ?, NOW())");
            $stmt->bind_param("ssssii", $titulo, $descripcion, $path_video, $url_video, $proyecto_id, $user_id);
            if ($stmt->execute()) {
                $mensaje = '<span style="color:green">Video guardado.</span>';
            } else {
                $mensaje = '<span style="color:red">Error BD al guardar video.</span>';
            }
        } else {
            $mensaje = '<span style="color:red">Título y (archivo o URL) requeridos.</span>';
        }
    }
}

// Determinar proyectos accesibles según rol
// Determinar proyectos accesibles (evitar usar columna inexistente empleados.user_id)
$proyectos_accesibles = [];
if ($rol === 'admin') {
    $res = $conn->query("SELECT id, nombre FROM grupos WHERE activo=1 ORDER BY nombre");
    while($row = $res->fetch_assoc()) { $proyectos_accesibles[(int)$row['id']] = $row['nombre']; }
} elseif ($rol === 'pm') {
    if ($stmtP = $conn->prepare("SELECT g.id, g.nombre FROM proyectos_pm pp JOIN grupos g ON g.id=pp.proyecto_id WHERE pp.user_id=? AND g.activo=1 ORDER BY g.nombre")) {
        $stmtP->bind_param('i', $user_id);
        if ($stmtP->execute()) {
            $r = $stmtP->get_result();
            while($row = $r->fetch_assoc()) { $proyectos_accesibles[(int)$row['id']] = $row['nombre']; }
        }
    }
} else { // empleado, responsable, servicio especializado
    if ($stmtP = $conn->prepare("SELECT g.id, g.nombre FROM empleado_proyecto ep JOIN grupos g ON g.id=ep.proyecto_id WHERE ep.empleado_id=? AND ep.activo=1 AND g.activo=1 ORDER BY g.nombre")) {
        // En este sistema se usa user_id directamente como empleado_id
        $stmtP->bind_param('i', $user_id);
        if ($stmtP->execute()) {
            $r = $stmtP->get_result();
            while($row = $r->fetch_assoc()) { $proyectos_accesibles[(int)$row['id']] = $row['nombre']; }
        }
    }
}

// Parámetros de filtrado
$filtro_proyecto = isset($_GET['proyecto_id']) ? (int)$_GET['proyecto_id'] : -1; // -1 = todos
$q = trim($_GET['q'] ?? '');

// Construir consulta de videos accesibles
$where = [];
$types = '';
$params = [];

if (!$es_admin) {
    $ids = array_keys($proyectos_accesibles);
    if ($ids) {
        $placeholders = implode(',', array_fill(0,count($ids),'?'));
        $where[] = '(v.proyecto_id IS NULL OR v.proyecto_id IN ('.$placeholders.'))';
        $types .= str_repeat('i', count($ids));
        $params = array_merge($params, $ids);
    } else {
        $where[] = 'v.proyecto_id IS NULL';
    }
} else {
    $where[] = '1=1';
}

if ($filtro_proyecto >= 0) {
    if ($filtro_proyecto === 0) {
        $where[] = 'v.proyecto_id IS NULL';
    } elseif ($filtro_proyecto > 0) {
        $where[] = 'v.proyecto_id = ?';
        $types .= 'i';
        $params[] = $filtro_proyecto;
    }
}

if ($q !== '') {
    $where[] = '(v.titulo LIKE CONCAT("%", ?, "%") OR v.descripcion LIKE CONCAT("%", ?, "%"))';
    $types .= 'ss';
    $params[] = $q; $params[] = $q;
}

$sql = "SELECT v.*, g.nombre AS proyecto_nombre, u.name AS autor FROM videos_capacitacion v
        LEFT JOIN grupos g ON g.id = v.proyecto_id
        LEFT JOIN users u ON u.id = v.subido_por
        WHERE ".implode(' AND ', $where)." ORDER BY v.fecha_subida DESC";

$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$videos = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Videos de Capacitación - Sistema de Gestión</title>
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

        .container {
            max-width: 1050px;
            margin: 0 auto;
            padding: 20px;
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

        .back-button {
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

        .back-button:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }

        .toolbar {
            background: #fff;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 4px 20px rgba(26,54,93,.08);
            border: 1px solid #f1f5f9;
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .search-filter {
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 16px 16px 16px 44px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            background: #fafafa;
            color: #1e293b;
            font-size: 15px;
            min-width: 280px;
            transition: .2s;
            font-family: inherit;
        }

        .search-box input:focus {
            outline: none;
            border-color: #ff7a00;
            box-shadow: 0 0 0 3px rgba(255,122,0,.15);
            background: #fff;
        }

        .search-box i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #ff7a00;
        }

        .filter-select {
            padding: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            background: #fafafa;
            color: #1e293b;
            font-size: 15px;
            min-width: 180px;
            transition: .2s;
            font-family: inherit;
        }

        .filter-select:focus {
            outline: none;
            border-color: #ff7a00;
            box-shadow: 0 0 0 3px rgba(255,122,0,.15);
            background: #fff;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: #fff;
            border-radius: 20px;
            padding: 28px 24px;
            box-shadow: 0 4px 20px rgba(26,54,93,.08);
            border: 1px solid #f1f5f9;
            text-align: center;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #1a365d;
        }

        .stat-label {
            font-size: 14px;
            color: #718096;
            font-weight: 500;
        }

        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-top: 32px;
        }

        .video-card {
            background: #fff;
            border-radius: 20px;
            padding: 0;
            box-shadow: 0 4px 20px rgba(26,54,93,.08);
            border: 1px solid #f1f5f9;
            transition: all 0.3s ease;
            cursor: pointer;
            overflow: hidden;
            position: relative;
        }

        .video-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 50px rgba(26,54,93,.15);
        }

        .video-thumbnail {
            width: 100%;
            height: 180px;
            background: linear-gradient(135deg,#ff7a00 0%,#1a365d 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .video-thumbnail::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(255,255,255,0.1) 0%, transparent 100%);
        }

        .play-button {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ff7a00;
            font-size: 24px;
            transition: all 0.3s ease;
        }

        .video-card:hover .play-button {
            transform: scale(1.1);
            background: white;
        }

        .video-content {
            padding: 20px;
        }

        .video-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .video-title {
            font-size: 16px;
            font-weight: 600;
            color: #1a365d;
            line-height: 1.4;
            margin-bottom: 8px;
        }

        .video-project {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: #ff7a00;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .badge-global {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .video-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #6b7280;
            border-top: 1px solid #e5e7eb;
            padding-top: 12px;
        }

        .video-date {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .video-author {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(26,54,93,.08);
            border: 1px solid #f1f5f9;
            color: #1a365d;
        }

        .empty-state i {
            font-size: 64px;
            color: #ff7a00;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 12px;
            font-weight: 600;
        }

        .empty-state p {
            font-size: 16px;
            color: #718096;
            margin-bottom: 24px;
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
        }

        .modal-content {
            background: white;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            border-radius: 20px;
            overflow: hidden;
            position: relative;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .modal-header {
            background: linear-gradient(135deg,#ff7a00 0%,#1a365d 100%);
            color: white;
            padding: 24px;
            position: relative;
        }

        .modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        .modal-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
            padding-right: 50px;
        }

        .modal-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 14px;
            opacity: 0.9;
        }

        .modal-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .modal-body {
            padding: 0;
        }

        .modal-player {
            width: 100%;
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 300px;
        }

        .modal-description {
            padding: 24px;
            font-size: 16px;
            line-height: 1.6;
            color: #374151;
            max-height: 200px;
            overflow-y: auto;
        }

        .video-iframe {
            width: 100%;
            height: 450px;
            border: none;
            background: #000;
        }

        .video-native {
            width: 100%;
            height: 450px;
            background: #000;
        }

        .no-video {
            padding: 40px;
            text-align: center;
            color: #6b7280;
        }

        .external-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg,#ff7a00 0%,#ff9500 100%);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .external-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255,122,0,.4);
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
            
            .header-actions .back-button {
                flex: 1;
                justify-content: center;
            }

            .toolbar {
                flex-direction: column;
                gap: 16px;
                padding: 24px;
            }

            .search-filter {
                width: 100%;
                flex-direction: column;
            }

            .search-box input {
                min-width: unset;
                width: 100%;
            }

            .filter-select {
                min-width: unset;
                width: 100%;
            }

            .video-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .video-iframe {
                height: 250px;
            }

            .modal-content {
                margin: 20px;
                max-height: calc(100vh - 40px);
            }

            .modal-title {
                font-size: 20px;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .header-title h1 {
                font-size: 24px;
            }

            .modal-header {
                padding: 20px;
            }

            .modal-description {
                padding: 20px;
            }
        }

        /* Hidden class for filtering */
        .video-card.hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-icon">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="header-info">
                        <h1>Videos de Capacitación</h1>
                        <p>Biblioteca de contenido educativo</p>
                    </div>
                </div>
                <div class="header-actions">
                    <?php
                    $return_url = '';
                    if ($rol === 'admin') $return_url = '../admin/admin.php';
                    elseif ($rol === 'pm') $return_url = '../pm/dashboard.php';
                    elseif (in_array($rol, ['responsable','servicio_especializado','empleado'], true)) $return_url = '../responsable/dashboard.php';
                    ?>
                    <a href="<?= $return_url ?>" class="back-button">
                        <i class="fas fa-arrow-left"></i>
                        Volver al Dashboard
                    </a>
                </div>
            </div>
        </div>

        <?php if($mensaje): ?>
            <div style="background: #fff; border-radius: 16px; padding: 16px 20px; margin-bottom: 24px; box-shadow: 0 4px 20px rgba(26,54,93,.08); border: 1px solid #f1f5f9;">
                <?= $mensaje ?>
            </div>
        <?php endif; ?>

        <?php
        // Obtener estadísticas
        $total_videos = $videos->num_rows;
        $videos->data_seek(0); // Reset pointer
        
        $videos_globales = 0;
        $videos_proyecto = 0;
        $temp_videos = [];
        while($v = $videos->fetch_assoc()) {
            $temp_videos[] = $v;
            if (empty($v['proyecto_id'])) {
                $videos_globales++;
            } else {
                $videos_proyecto++;
            }
        }
        ?>

        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?= $total_videos ?></div>
                <div class="stat-label">Total Videos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $videos_globales ?></div>
                <div class="stat-label">Videos Globales</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $videos_proyecto ?></div>
                <div class="stat-label">Videos de Proyecto</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= count($proyectos_accesibles) ?></div>
                <div class="stat-label">Proyectos Accesibles</div>
            </div>
        </div>

        <div class="toolbar">
            <div class="search-filter">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Buscar videos por título o descripción..." value="<?= htmlspecialchars($q) ?>">
                </div>
                <select class="filter-select" id="projectFilter">
                    <option value="-1" <?= $filtro_proyecto === -1 ? 'selected' : '' ?>>Todos los videos</option>
                    <option value="0" <?= $filtro_proyecto === 0 ? 'selected' : '' ?>>Videos globales</option>
                    <?php foreach($proyectos_accesibles as $pid => $pname): ?>
                        <option value="<?= $pid ?>" <?= $filtro_proyecto === $pid ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="color: #1a365d; font-weight: 500;">
                <span id="resultCount"><?= $total_videos ?></span> videos encontrados
            </div>
        </div>

        <?php if(empty($temp_videos)): ?>
            <div class="empty-state">
                <i class="fas fa-video"></i>
                <h3>No hay videos disponibles</h3>
                <p>No se encontraron videos que coincidan con los filtros seleccionados</p>
                <?php if($es_admin): ?>
                    <a href="../admin/videos.php" style="color: white; text-decoration: underline;">
                        <i class="fas fa-plus"></i>
                        Subir primer video
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="video-grid" id="videoGrid">
                <?php foreach($temp_videos as $v): ?>
                    <?php
                        $id = (int)$v['id'];
                        $titulo = htmlspecialchars($v['titulo']);
                        $descripcion = htmlspecialchars($v['descripcion'] ?? '');
                        $proy = !empty($v['proyecto_id']) ? htmlspecialchars($v['proyecto_nombre'] ?? ('Proyecto #'.$v['proyecto_id'])) : 'GLOBAL';
                        $isGlobal = empty($v['proyecto_id']);
                        $fecha = date('d/m/Y', strtotime($v['fecha_subida']));
                        $autor = htmlspecialchars($v['autor'] ?? 'Desconocido');
                    ?>
                    <div class="video-card" 
                         data-title="<?= strtolower($titulo) ?>"
                         data-description="<?= strtolower($descripcion) ?>"
                         data-project="<?= $isGlobal ? '0' : $v['proyecto_id'] ?>"
                         data-video='<?= json_encode([
                            "id" => $id,
                            "titulo" => $v['titulo'],
                            "descripcion" => $v['descripcion'],
                            "url" => $v['url_video'],
                            "archivo" => $v['path_video'],
                            "fecha" => $fecha,
                            "proyecto" => $proy,
                            "autor" => $autor
                        ], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>'>
                        
                        <div class="video-thumbnail">
                            <div class="play-button">
                                <i class="fas fa-play"></i>
                            </div>
                        </div>
                        
                        <div class="video-content">
                            <div class="video-project">
                                <?php if($isGlobal): ?>
                                    <span class="badge-global">Global</span>
                                <?php else: ?>
                                    <i class="fas fa-project-diagram"></i>
                                    <?= $proy ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="video-title"><?= $titulo ?></div>
                            
                            <div class="video-meta">
                                <div class="video-date">
                                    <i class="fas fa-calendar"></i>
                                    <?= $fecha ?>
                                </div>
                                <div class="video-author">
                                    <i class="fas fa-user"></i>
                                    <?= $autor ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Video Modal -->
    <div id="videoModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <button id="closeModal" class="modal-close">
                    <i class="fas fa-times"></i>
                </button>
                <div id="modalTitle" class="modal-title"></div>
                <div id="modalMeta" class="modal-meta"></div>
            </div>
            <div class="modal-body">
                <div id="modalPlayer" class="modal-player">
                    <!-- Player will be injected here -->
                </div>
                <div id="modalDescription" class="modal-description"></div>
            </div>
        </div>
    </div>

    <script>
        // Video card click handlers
        document.querySelectorAll('.video-card').forEach(card => {
            card.addEventListener('click', () => {
                const data = JSON.parse(card.getAttribute('data-video'));
                openVideoModal(data);
            });
        });

        function openVideoModal(data) {
            const modal = document.getElementById('videoModal');
            
            // Set title and meta
            document.getElementById('modalTitle').textContent = data.titulo;
            document.getElementById('modalMeta').innerHTML = `
                <div class="modal-meta-item">
                    <i class="fas fa-project-diagram"></i>
                    <span>${data.proyecto}</span>
                </div>
                <div class="modal-meta-item">
                    <i class="fas fa-calendar"></i>
                    <span>${data.fecha}</span>
                </div>
                <div class="modal-meta-item">
                    <i class="fas fa-user"></i>
                    <span>${data.autor}</span>
                </div>
            `;
            
            // Set description
            document.getElementById('modalDescription').innerHTML = data.descripcion 
                ? data.descripcion.replace(/\n/g, '<br>') 
                : '<em style="color: #9ca3af;">Sin descripción disponible</em>';
            
            // Set up player
            const player = document.getElementById('modalPlayer');
            player.innerHTML = '';
            
            if (data.url) {
                if (/youtube\.com|youtu\.be/.test(data.url)) {
                    const match = data.url.match(/(?:v=|youtu\.be\/)([\w-]+)/);
                    if (match) {
                        player.innerHTML = `<iframe class="video-iframe" src="https://www.youtube.com/embed/${match[1]}" allowfullscreen></iframe>`;
                    } else {
                        player.innerHTML = `
                            <div class="no-video">
                                <a href="${data.url}" target="_blank" class="external-link">
                                    <i class="fas fa-external-link-alt"></i>
                                    Abrir video en YouTube
                                </a>
                            </div>
                        `;
                    }
                } else if (/vimeo\.com/.test(data.url)) {
                    const match = data.url.match(/vimeo.com\/(\d+)/);
                    if (match) {
                        player.innerHTML = `<iframe class="video-iframe" src="https://player.vimeo.com/video/${match[1]}" allowfullscreen></iframe>`;
                    } else {
                        player.innerHTML = `
                            <div class="no-video">
                                <a href="${data.url}" target="_blank" class="external-link">
                                    <i class="fas fa-external-link-alt"></i>
                                    Abrir video en Vimeo
                                </a>
                            </div>
                        `;
                    }
                } else {
                    player.innerHTML = `
                        <div class="no-video">
                            <a href="${data.url}" target="_blank" class="external-link">
                                <i class="fas fa-external-link-alt"></i>
                                Abrir enlace externo
                            </a>
                        </div>
                    `;
                }
            } else if (data.archivo) {
                player.innerHTML = `
                    <video class="video-native" controls preload="metadata">
                        <source src="../${data.archivo}" type="video/mp4">
                        Tu navegador no soporta la reproducción de video.
                    </video>
                `;
            } else {
                player.innerHTML = `
                    <div class="no-video">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #dc2626; margin-bottom: 16px;"></i>
                        <p>No hay fuente de video disponible</p>
                    </div>
                `;
            }
            
            modal.style.display = 'flex';
        }

        // Modal close handlers
        document.getElementById('closeModal').addEventListener('click', () => {
            document.getElementById('videoModal').style.display = 'none';
        });

        document.getElementById('videoModal').addEventListener('click', (e) => {
            if (e.target.id === 'videoModal') {
                e.currentTarget.style.display = 'none';
            }
        });

        // Search and filter functionality
        const searchInput = document.getElementById('searchInput');
        const projectFilter = document.getElementById('projectFilter');
        const videoCards = document.querySelectorAll('.video-card');
        const resultCount = document.getElementById('resultCount');

        function filterVideos() {
            const searchTerm = searchInput.value.toLowerCase();
            const projectValue = parseInt(projectFilter.value);
            let visibleCount = 0;

            videoCards.forEach(card => {
                const title = card.dataset.title;
                const description = card.dataset.description;
                const project = parseInt(card.dataset.project) || 0;

                const matchesSearch = searchTerm === '' || 
                    title.includes(searchTerm) || 
                    description.includes(searchTerm);

                const matchesProject = projectValue === -1 || 
                    (projectValue === 0 && project === 0) ||
                    (projectValue > 0 && project === projectValue);

                if (matchesSearch && matchesProject) {
                    card.classList.remove('hidden');
                    visibleCount++;
                } else {
                    card.classList.add('hidden');
                }
            });

            resultCount.textContent = visibleCount;
        }

        searchInput.addEventListener('input', filterVideos);
        projectFilter.addEventListener('change', filterVideos);

        // Initialize filter based on URL parameters
        filterVideos();

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.getElementById('videoModal').style.display = 'none';
            }
        });
    </script>
</body>
</html>
