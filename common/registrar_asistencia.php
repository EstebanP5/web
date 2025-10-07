<?php
require_once '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['admin','pm','responsable'])) {
    header('Location: ../login.php');
    exit;
}
$proyecto_id = intval($_GET['proyecto_id'] ?? 0);
$fecha = $_GET['fecha'] ?? date('Y-m-d');
if (!$proyecto_id) { echo 'Proyecto no válido.'; exit; }
// Obtener empleados
$empleados = $conn->query("SELECT * FROM empleados WHERE proyecto_id=$proyecto_id AND activo=1");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registrar Asistencia</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
    .asistencia-table th, .asistencia-table td { text-align:center; }
    .foto-preview { width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #ccc; margin:2px; }
    .btn-foto { background:#007bff; color:#fff; border:none; border-radius:4px; padding:4px 10px; cursor:pointer; margin:2px; }
    .btn-foto:hover { background:#0056b3; }
    .ubicacion-info { font-size:0.9em; color:#888; }
    </style>
</head>
<body>
<div class="card">
    <h2>Registrar Asistencia - <?= htmlspecialchars($fecha) ?></h2>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="proyecto_id" value="<?= $proyecto_id ?>">
        <input type="hidden" name="fecha" value="<?= $fecha ?>">
        <table class="asistencia-table">
            <tr><th>Empleado</th><th>Entrada<br><span style='font-weight:normal'>(foto, hora, ubicación)</span></th><th>Salida<br><span style='font-weight:normal'>(foto, hora, ubicación)</span></th></tr>
            <?php while($e = $empleados->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($e['nombre']) ?></td>
                <td>
                    <input type="file" name="entrada_<?= $e['id'] ?>" accept="image/*" capture="environment" class="input-foto" data-emp="<?= $e['id'] ?>" data-tipo="entrada">
                    <div id="preview_entrada_<?= $e['id'] ?>"></div>
                    <input type="hidden" name="entrada_lat_<?= $e['id'] ?>">
                    <input type="hidden" name="entrada_lng_<?= $e['id'] ?>">
                    <input type="hidden" name="entrada_hora_<?= $e['id'] ?>">
                    <div class="ubicacion-info" id="ubicacion_entrada_<?= $e['id'] ?>"></div>
                </td>
                <td>
                    <input type="file" name="salida_<?= $e['id'] ?>" accept="image/*" capture="environment" class="input-foto" data-emp="<?= $e['id'] ?>" data-tipo="salida">
                    <div id="preview_salida_<?= $e['id'] ?>"></div>
                    <input type="hidden" name="salida_lat_<?= $e['id'] ?>">
                    <input type="hidden" name="salida_lng_<?= $e['id'] ?>">
                    <input type="hidden" name="salida_hora_<?= $e['id'] ?>">
                    <div class="ubicacion-info" id="ubicacion_salida_<?= $e['id'] ?>"></div>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
        <button type="submit">Guardar Asistencia</button>
    </form>
    <a href="javascript:history.back()" style="display:inline-block;margin-top:1em;">&larr; Volver</a>
</div>
<script>
// Al seleccionar foto, mostrar preview y capturar hora/ubicación
const inputs = document.querySelectorAll('.input-foto');
inputs.forEach(input => {
    input.addEventListener('change', function(e) {
        const tipo = this.dataset.tipo;
        const emp = this.dataset.emp;
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(ev) {
                document.getElementById('preview_' + tipo + '_' + emp).innerHTML = '<img src="' + ev.target.result + '" class="foto-preview">';
            };
            reader.readAsDataURL(file);
        }
        // Guardar hora
        const now = new Date();
        document.querySelector('input[name="' + tipo + '_hora_' + emp + '"]').value = now.toLocaleTimeString();
        // Obtener ubicación
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(pos) {
                document.querySelector('input[name="' + tipo + '_lat_' + emp + '"]').value = pos.coords.latitude;
                document.querySelector('input[name="' + tipo + '_lng_' + emp + '"]').value = pos.coords.longitude;
                document.getElementById('ubicacion_' + tipo + '_' + emp).textContent = 'Lat: ' + pos.coords.latitude.toFixed(5) + ', Lng: ' + pos.coords.longitude.toFixed(5);
            }, function() {
                document.getElementById('ubicacion_' + tipo + '_' + emp).textContent = 'Ubicación no disponible';
            });
        } else {
            document.getElementById('ubicacion_' + tipo + '_' + emp).textContent = 'Ubicación no soportada';
        }
    });
});
</script>
</body>
</html>
