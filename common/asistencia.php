<?php
require_once '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['admin','pm','responsable'])) {
    header('Location: ../login.php');
    exit;
}
// Determinar proyecto
$proyecto_id = isset($_GET['proyecto_id']) ? intval($_GET['proyecto_id']) : 0;
if (!$proyecto_id) {
    echo '<div class="card">No se ha seleccionado un proyecto.</div>';
    exit;
}
// Obtener datos del proyecto
$proy = $conn->query("SELECT * FROM proyectos WHERE id=$proyecto_id")->fetch_assoc();
if (!$proy) { echo '<div class="card">Proyecto no encontrado.</div>'; exit; }
// Obtener empleados del proyecto
$empleados = $conn->query("SELECT * FROM empleados WHERE proyecto_id=$proyecto_id AND activo=1");
// Fechas del proyecto
$inicio = $proy['fecha_inicio'];
$fin = $proy['fecha_fin'];
$hoy = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asistencia - <?= htmlspecialchars($proy['nombre']) ?></title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
    .calendar { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 20px; }
    .calendar-day { width: 90px; padding: 8px; background: #eee; border-radius: 5px; text-align: center; cursor: pointer; }
    .calendar-day.hoy { background: #28a745; color: #fff; font-weight: bold; }
    .calendar-day.selected { border: 2px solid #007bff; }
    .asistencia-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .asistencia-table th, .asistencia-table td { border: 1px solid #ccc; padding: 6px; }
    .asistencia-table th { background: #f5f5f5; }
    </style>
</head>
<body>
<div class="card">
    <h2>Asistencia - <?= htmlspecialchars($proy['nombre']) ?></h2>
    <div>
        <strong>Duración del proyecto:</strong> <?= htmlspecialchars($inicio) ?> a <?= htmlspecialchars($fin) ?>
    </div>
    <div class="calendar">
        <?php
        $start = strtotime($inicio);
        $end = strtotime($fin);
        for ($d = $start; $d <= $end; $d += 86400) {
            $fecha = date('Y-m-d', $d);
            $clase = ($fecha == $hoy) ? 'calendar-day hoy selected' : 'calendar-day';
            echo "<div class='$clase' data-fecha='$fecha'>" . date('d/m/Y', $d) . "</div>";
        }
        ?>
    </div>
    <div id="asistencia-dia">
        <!-- Aquí se carga la asistencia del día seleccionado -->
    </div>
    <button id="btn-registrar" style="display:none;">Registrar asistencia del día seleccionado</button>
</div>
<script>
const dias = document.querySelectorAll('.calendar-day');
let fechaSeleccionada = '<?= $hoy ?>';
function cargarAsistencia(fecha) {
    fetch('asistencia_dia.php?proyecto_id=<?= $proyecto_id ?>&fecha='+fecha)
        .then(r=>r.text()).then(html=>{
            document.getElementById('asistencia-dia').innerHTML = html;
            document.getElementById('btn-registrar').style.display = (fecha === '<?= $hoy ?>') ? 'block' : 'none';
        });
}
dias.forEach(d=>{
    d.onclick = function() {
        dias.forEach(x=>x.classList.remove('selected'));
        d.classList.add('selected');
        fechaSeleccionada = d.getAttribute('data-fecha');
        cargarAsistencia(fechaSeleccionada);
    }
});
cargarAsistencia(fechaSeleccionada);
document.getElementById('btn-registrar').onclick = function() {
    window.location.href = 'registrar_asistencia.php?proyecto_id=<?= $proyecto_id ?>&fecha='+fechaSeleccionada;
};
</script>
</body>
</html>
