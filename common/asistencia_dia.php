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
// Obtener asistencias del día
$asis = [];
$res = $conn->query("SELECT * FROM asistencia WHERE proyecto_id=$proyecto_id AND fecha='$fecha'");
while($a = $res->fetch_assoc()) $asis[$a['empleado_id']][$a['tipo']] = $a;
?>
<table class="asistencia-table">
<tr><th>Empleado</th><th>Entrada</th><th>Salida</th></tr>
<?php while($e = $empleados->fetch_assoc()): ?>
<tr>
    <td><?= htmlspecialchars($e['nombre']) ?></td>
    <td>
        <?php if(isset($asis[$e['id']]['entrada'])): ?>
            <div><strong><?= htmlspecialchars($asis[$e['id']]['entrada']['hora']) ?></strong></div>
            <?php if(!empty($asis[$e['id']]['entrada']['foto'])): ?>
                <img src="../uploads/asistencia/<?= htmlspecialchars($asis[$e['id']]['entrada']['foto']) ?>" style="width:60px;height:60px;object-fit:cover;border-radius:6px;">
            <?php endif; ?>
            <div style="font-size:0.9em;color:#888;">
                <?= htmlspecialchars($asis[$e['id']]['entrada']['lat']) ?>, <?= htmlspecialchars($asis[$e['id']]['entrada']['lng']) ?>
            </div>
        <?php else: ?>
            -
        <?php endif; ?>
    </td>
    <td>
        <?php if(isset($asis[$e['id']]['salida'])): ?>
            <div><strong><?= htmlspecialchars($asis[$e['id']]['salida']['hora']) ?></strong></div>
            <?php if(!empty($asis[$e['id']]['salida']['foto'])): ?>
                <img src="../uploads/asistencia/<?= htmlspecialchars($asis[$e['id']]['salida']['foto']) ?>" style="width:60px;height:60px;object-fit:cover;border-radius:6px;">
            <?php endif; ?>
            <div style="font-size:0.9em;color:#888;">
                <?= htmlspecialchars($asis[$e['id']]['salida']['lat']) ?>, <?= htmlspecialchars($asis[$e['id']]['salida']['lng']) ?>
            </div>
        <?php else: ?>
            -
        <?php endif; ?>
    </td>
</tr>
<?php endwhile; ?>
</table>
