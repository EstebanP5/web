<?php
require_once '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['admin','pm'])) {
    header('Location: ../login.php');
    exit;
}
// Obtener PMs y sus proyectos
$res = $conn->query("SELECT u.id, u.nombre, u.email, GROUP_CONCAT(p.nombre SEPARATOR ', ') as proyectos FROM usuarios u LEFT JOIN proyectos_pm pp ON u.id = pp.pm_id LEFT JOIN proyectos p ON pp.proyecto_id = p.id WHERE u.rol = 'pm' GROUP BY u.id ORDER BY u.nombre");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Lista de Project Managers</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
    table { width:100%; border-collapse:collapse; margin-top:1em; }
    th, td { border:1px solid #ccc; padding:8px; }
    th { background:#f5f5f5; }
    </style>
</head>
<body>
<div class="card">
    <h2>Project Managers y Proyectos Asignados</h2>
    <table>
        <tr><th>Nombre</th><th>Email</th><th>Proyectos a Cargo</th></tr>
        <?php while($pm = $res->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($pm['nombre']) ?></td>
            <td><?= htmlspecialchars($pm['email']) ?></td>
            <td><?= htmlspecialchars($pm['proyectos'] ?: '-') ?></td>
        </tr>
        <?php endwhile; ?>
    </table>
    <a href="javascript:history.back()">&larr; Volver</a>
</div>
</body>
</html>
