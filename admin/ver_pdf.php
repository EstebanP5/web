<?php
if (isset($_GET['archivo'])) {
    $archivo = basename($_GET['archivo']); // Seguridad: solo nombre de archivo
    $rutaCompleta = '../uploads/' . $archivo;
    
    if (file_exists($rutaCompleta) && pathinfo($archivo, PATHINFO_EXTENSION) === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $archivo . '"');
        header('Content-Length: ' . filesize($rutaCompleta));
        readfile($rutaCompleta);
        exit;
    }
}

http_response_code(404);
echo "Archivo no encontrado";
?>
