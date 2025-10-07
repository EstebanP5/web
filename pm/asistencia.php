
<?php
require_once '../common/auth.php';
require_once '../common/db.php';
$usuario = $_SESSION['usuario'];
if ($usuario['rol'] !== 'pm') { header('Location: /web/login.php'); exit; }
$user_id = $usuario['id'];

// Obtener proyectos asignados al PM
$proyectos = $conn->query("SELECT p.* FROM proyectos_pm pp JOIN proyectos p ON pp.proyecto_id = p.id WHERE pp.pm_id = $user_id AND p.activo=1");
$proyecto = $proyectos->fetch_assoc();
if (!$proyecto) { die('No tienes proyectos asignados.'); }
$proyecto_id = $proyecto['id'];

// Obtener empleados del proyecto
$empleados = $conn->query("SELECT * FROM empleados WHERE proyecto_id = $proyecto_id AND activo=1")->fetch_all(MYSQLI_ASSOC);

// Rango de fechas del proyecto
$fecha_inicio = $proyecto['fecha_inicio'];
$fecha_fin = $proyecto['fecha_fin'];

// Registrar asistencia
$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_asistencia'])) {
    $fecha = date('Y-m-d');
    foreach ($_POST['asistencia'] as $empleado_id => $tipo) {
        // Evitar duplicados (solo una entrada/salida por día)
        $existe = $conn->query("SELECT id FROM asistencia WHERE empleado_id=$empleado_id AND proyecto_id=$proyecto_id AND fecha='$fecha' AND tipo='$tipo'")->fetch_assoc();
        if (!$existe) {
            $conn->query("INSERT INTO asistencia (empleado_id, proyecto_id, tipo, fecha, hora) VALUES ($empleado_id, $proyecto_id, '$tipo', '$fecha', NOW())");
        }
    }
    $mensaje = 'Asistencia registrada.';
}

// Consultar asistencias del día seleccionado
$fecha_consulta = $_GET['fecha'] ?? date('Y-m-d');
$asistencias = [];
$res = $conn->query("SELECT * FROM asistencia WHERE proyecto_id=$proyecto_id AND fecha='$fecha_consulta'");
while ($a = $res->fetch_assoc()) {
    $asistencias[$a['empleado_id']][$a['tipo']] = $a;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asistencia Proyecto</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
    .calendar { margin: 1em 0; }
    .calendar-day { display:inline-block; width:2.5em; text-align:center; margin:2px; padding:4px; border-radius:4px; background:#eee; cursor:pointer; }
    .calendar-day.selected { background:#3498db; color:#fff; font-weight:bold; }
    .calendar-day.today { border:2px solid #27ae60; }
    </style>
</head>
<body>
<div class="card">
    <h2>Asistencia de Proyecto: <?= htmlspecialchars($proyecto['nombre']) ?></h2>
    <?php if ($mensaje): ?><div class="success"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
    <div class="calendar">
        <?php
        $inicio = strtotime($fecha_inicio);
        $fin = strtotime($fecha_fin);
        $hoy = date('Y-m-d');
        for ($d = $inicio; $d <= $fin; $d += 86400) {
            $f = date('Y-m-d', $d);
            $cl = 'calendar-day';
            if ($f == $fecha_consulta) $cl .= ' selected';
            if ($f == $hoy) $cl .= ' today';
            echo "<a href='?fecha=$f' class='$cl'>".date('d', $d)."</a> ";
        }
        ?>
    </div>
    <form method="post" enctype="multipart/form-data" id="asistenciaForm">
        <input type="hidden" name="registrar_asistencia" value="1">
        <table>
            <tr><th>Empleado</th><th>Tipo</th><th>Foto</th><th>GPS</th><th>Dirección</th></tr>
            <?php foreach ($empleados as $e): ?>
            <tr>
                <td><?= htmlspecialchars($e['nombre']) ?></td>
                <td>
                    <select name="asistencia[<?= $e['id'] ?>][tipo]" <?= isset($asistencias[$e['id']]['entrada']) && isset($asistencias[$e['id']]['salida']) ? 'disabled' : '' ?>>
                        <option value="">--Seleccionar--</option>
                        <option value="entrada" <?= isset($asistencias[$e['id']]['entrada']) ? 'disabled' : '' ?>>Entrada</option>
                        <option value="salida" <?= isset($asistencias[$e['id']]['salida']) ? 'disabled' : '' ?>>Salida</option>
                    </select>
                </td>
                <td>
                    <input type="file" name="asistencia[<?= $e['id'] ?>][foto]" accept="image/*" capture="environment">
                </td>
                <td>
                    <input type="text" name="asistencia[<?= $e['id'] ?>][lat]" placeholder="Lat" readonly style="width:80px;">
                    <input type="text" name="asistencia[<?= $e['id'] ?>][lng]" placeholder="Lng" readonly style="width:80px;">
                    <button type="button" onclick="getLocation(this)">GPS</button>
                </td>
                <td>
                    <input type="text" name="asistencia[<?= $e['id'] ?>][direccion]" placeholder="Dirección" style="width:180px;">
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <button type="submit">Registrar Asistencia del Día</button>
    </form>
    <script>
    function getLocation(btn) {
        if (navigator.geolocation) {
            btn.disabled = true;
            btn.textContent = 'Obteniendo...';
            navigator.geolocation.getCurrentPosition(function(pos) {
                var tr = btn.closest('tr');
                tr.querySelector('input[name$="[lat]"]').value = pos.coords.latitude;
                tr.querySelector('input[name$="[lng]"]').value = pos.coords.longitude;
                btn.textContent = 'GPS';
                btn.disabled = false;
            }, function() {
                alert('No se pudo obtener la ubicación');
                btn.textContent = 'GPS';
                btn.disabled = false;
            });
        } else {
            alert('Geolocalización no soportada');
        }
    }
    </script>
</div>
</body>
</html>
