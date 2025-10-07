<?php
require_once '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['admin','pm'])) {
    header('Location: ../login.php');
    exit;
}
// Obtener empleados y su proyecto
$res = $conn->query("SELECT e.*, p.nombre as proyecto_nombre FROM empleados e LEFT JOIN proyectos p ON e.proyecto_id = p.id ORDER BY e.nombre");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Lista de Servicios Especializados</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
    table { width:100%; border-collapse:collapse; margin-top:1em; }
    th, td { border:1px solid #ccc; padding:8px; }
    th { background:#f5f5f5; }
    </style>
</head>
<body>
<div class="card">
    <h2>Servicios Especializados y Proyecto Asignado</h2>
    <table>
        <tr><th>Nombre</th><th>CURP</th><th>NSS</th><th>Teléfono</th><th>Proyecto</th></tr>
        <?php while($e = $res->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($e['nombre']) ?></td>
            <td><?= htmlspecialchars($e['curp']) ?></td>
            <td><?= htmlspecialchars($e['nss']) ?></td>
            <td><?= htmlspecialchars($e['telefono']) ?></td>
            <td><?= htmlspecialchars($e['proyecto_nombre'] ?? '-') ?></td>
        </tr>
        <?php endwhile; ?>
    </table>
    <a href="javascript:history.back()">&larr; Volver</a>
</div>
</body>
</html>
