<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'] ?? '', ['responsable', 'servicio_especializado'])) {
    http_response_code(403);
    echo 'Acceso no autorizado.';
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$conn->query("CREATE TABLE IF NOT EXISTS empleado_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT NOT NULL,
    tipo VARCHAR(50) NOT NULL,
    ruta_archivo VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) DEFAULT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_empleado_tipo (empleado_id, tipo),
    CONSTRAINT fk_empleado_documentos_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$docStmt = $conn->prepare("SELECT ruta_archivo, nombre_original, mime_type FROM empleado_documentos WHERE empleado_id = ? AND tipo = 'alta_imss' ORDER BY created_at DESC LIMIT 1");
if (!$docStmt) {
    http_response_code(500);
    echo 'No se pudo preparar la consulta del documento.';
    exit;
}

$docStmt->bind_param('i', $user_id);
if (!$docStmt->execute()) {
    $docStmt->close();
    http_response_code(500);
    echo 'Error al obtener el documento.';
    exit;
}

$result = $docStmt->get_result();
$documento = $result ? $result->fetch_assoc() : null;
$docStmt->close();

if (!$documento || empty($documento['ruta_archivo'])) {
    http_response_code(404);
    echo 'No hay un documento de alta del IMSS disponible.';
    exit;
}

$relativePath = $documento['ruta_archivo'];
$baseDir = realpath(dirname(__DIR__));
$uploadsDir = realpath($baseDir . '/uploads/altas_imss');
$fullPath = realpath($baseDir . '/' . ltrim($relativePath, '/\\'));

if (!$fullPath || !$uploadsDir || strpos($fullPath, $uploadsDir) !== 0 || !is_readable($fullPath)) {
    http_response_code(404);
    echo 'El archivo del IMSS no está disponible en el servidor.';
    exit;
}

$mime = $documento['mime_type'];
if (!$mime) {
    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    if ($extension === 'pdf') {
        $mime = 'application/pdf';
    } elseif (in_array($extension, ['jpg', 'jpeg'])) {
        $mime = 'image/jpeg';
    } elseif ($extension === 'png') {
        $mime = 'image/png';
    } else {
        $mime = 'application/octet-stream';
    }
}

$download = isset($_GET['download']);
$filename = $documento['nombre_original'] ?: basename($fullPath);
$filename = preg_replace('/[^A-Za-z0-9 _\.\-áéíóúñÁÉÍÓÚÑ]/u', '_', $filename);
if ($filename === '' || $filename === null) {
    $filename = 'alta_imss_' . $user_id;
}
$encodedFilename = rawurlencode($filename);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');
$disposition = $download ? 'attachment' : 'inline';
header("Content-Disposition: {$disposition}; filename=\"{$filename}\"; filename*=UTF-8''{$encodedFilename}");

readfile($fullPath);
exit;
