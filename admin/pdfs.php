<?php
require_once '../common/auth.php';
require_once '../common/db.php';
$usuario = $_SESSION['usuario'];
if ($usuario['rol'] !== 'admin') { header('Location: /web/login.php'); exit; }

$mensaje = '';
// Subir PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_pdf'])) {
    $titulo = trim($_POST['titulo']);
    $descripcion = trim($_POST['descripcion']);
    if (!empty($_FILES['archivo_pdf']['name'])) {
        $ext = strtolower(pathinfo($_FILES['archivo_pdf']['name'], PATHINFO_EXTENSION));
        if ($ext === 'pdf' && $_FILES['archivo_pdf']['error'] === UPLOAD_ERR_OK) {
            $nombre_archivo = uniqid('pdf_') . '.pdf';
            $destino = '../uploads/' . $nombre_archivo;
            if (!is_dir('../uploads/')) mkdir('../uploads/', 0777, true);
            if (move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $destino)) {
                $stmt = $conn->prepare("INSERT INTO pdfs (titulo, descripcion, archivo, subido_por) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $titulo, $descripcion, $nombre_archivo, $usuario['id']);
                $stmt->execute();
                $mensaje = 'PDF subido correctamente.';
            } else {
                $mensaje = 'Error al guardar el archivo PDF.';
            }
        } else {
            $mensaje = 'Solo se permiten archivos PDF.';
        }
    } else {
        $mensaje = 'Debes seleccionar un archivo PDF.';
    }
}
// Eliminar PDF
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $res = $conn->query("SELECT archivo FROM pdfs WHERE id = $id");
    if ($row = $res->fetch_assoc()) {
        $archivo = '../uploads/' . $row['archivo'];
        if (file_exists($archivo)) unlink($archivo);
    }
    $conn->query("DELETE FROM pdfs WHERE id = $id");
    $mensaje = 'PDF eliminado.';
}
// Listar PDFs
$pdfs = $conn->query("SELECT p.*, u.name as subido_por_nombre FROM pdfs p LEFT JOIN users u ON p.subido_por = u.id ORDER BY p.fecha_subida DESC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de PDFs</title>
    <style>
        body { background: #f5f5f5; font-family: Arial, sans-serif; }
        .container { max-width: 900px; margin: 30px auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px #0001; padding: 2em; }
        h1 { color: #2c3e50; text-align: center; }
        .form-section { background: #f8f9fa; padding: 1em; border-radius: 6px; margin-bottom: 2em; }
        input, textarea { width: 100%; padding: 8px; margin: 5px 0 15px 0; border: 1px solid #ccc; border-radius: 4px; }
        button { background: #3498db; color: #fff; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }
        button.danger { background: #e74c3c; }
        .card { border: 1px solid #ddd; border-radius: 8px; margin-bottom: 1.5em; padding: 1em; background: #fafafa; }
        .card-title { font-size: 1.2em; font-weight: bold; color: #2c3e50; }
        .card-desc { color: #555; margin: 0.5em 0; }
        .card-footer { color: #888; font-size: 0.9em; }
    </style>
</head>
<body>
<div class="container">
    <h1>Gestión de PDFs</h1>
    <?php if ($mensaje): ?>
        <div class="<?= strpos($mensaje, 'correctamente') !== false ? 'success' : 'error' ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>
    <div class="form-section">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="subir_pdf" value="1">
            <label>Título *</label>
            <input name="titulo" required>
            <label>Descripción</label>
            <textarea name="descripcion" rows="2"></textarea>
            <label>Archivo PDF *</label>
            <input type="file" name="archivo_pdf" accept="application/pdf" required>
            <button type="submit">Subir PDF</button>
        </form>
    </div>
    <?php foreach ($pdfs as $p): ?>
        <div class="card">
            <div class="card-title">📄 <?= htmlspecialchars($p['titulo']) ?></div>
            <div class="card-desc"> <?= nl2br(htmlspecialchars($p['descripcion'])) ?> </div>
            <div style="margin:1em 0;">
                <a href="../uploads/<?= htmlspecialchars($p['archivo']) ?>" target="_blank">Ver/Descargar PDF</a>
            </div>
            <div class="card-footer">
                Subido por: <?= htmlspecialchars($p['subido_por_nombre'] ?? 'Admin') ?> | <?= htmlspecialchars($p['fecha_subida']) ?>
                <a href="?eliminar=<?= $p['id'] ?>" onclick="return confirm('¿Eliminar este PDF?')"><button class="danger" style="float:right;">Eliminar</button></a>
            </div>
        </div>
    <?php endforeach; ?>
    <a href="dashboard.php">&larr; Volver al panel</a>
</div>
</body>
</html>
