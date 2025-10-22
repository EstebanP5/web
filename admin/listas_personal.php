<?php
require_once '../common/auth.php';
require_once '../common/db.php';
$usuario = $_SESSION['usuario'];
if ($usuario['rol'] !== 'admin') { header('Location: /web/login.php'); exit; }

// Listar empleados
$empleados = $conn->query("SELECT e.*, p.nombre as proyecto_nombre FROM empleados e LEFT JOIN proyectos p ON e.proyecto_id = p.id ORDER BY e.nombre")->fetch_all(MYSQLI_ASSOC);
// Listar PMs
$pms = $conn->query("SELECT u.* FROM usuarios u WHERE u.rol = 'pm' ORDER BY u.nombre")->fetch_all(MYSQLI_ASSOC);
// Listar responsables de obra
$responsables = $conn->query("SELECT u.* FROM usuarios u WHERE u.rol = 'responsable' ORDER BY u.nombre")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Listas de Personal</title>
    <style>
        body { background: #f5f5f5; font-family: Arial, sans-serif; }
        .container { max-width: 1100px; margin: 30px auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px #0001; padding: 2em; }
        h1 { color: #2c3e50; text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 2em; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f8f9fa; }
        .section-title { font-size: 1.2em; margin: 1.5em 0 0.5em 0; color: #007bff; }
    </style>
</head>
<body>
<div class="container">
    <h1>Listas de Servicios Especializados, PMs y Responsables de Obra</h1>
    <div class="section-title">Servicios Especializados</div>
    <table>
    <tr><th>Nombre</th><th>Empresa</th><th>CURP</th><th>NSS</th><th>Teléfono</th><th>Proyecto</th></tr>
        <?php foreach ($empleados as $e): ?>
        <tr>
            <td><?= htmlspecialchars($e['nombre']) ?></td>
            <td><?= htmlspecialchars($e['empresa'] ?? '') ?></td>
            <td><?= htmlspecialchars($e['curp'] ?? '') ?></td>
            <td><?= htmlspecialchars($e['nss'] ?? '') ?></td>
            <td><?= htmlspecialchars($e['telefono'] ?? '') ?></td>
            <td><?= htmlspecialchars($e['proyecto_nombre'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <div class="section-title">Project Managers (PMs)</div>
    <table>
        <tr><th>Nombre</th><th>Usuario</th><th>Email</th><th>Teléfono</th></tr>
        <?php foreach ($pms as $pm): ?>
        <tr>
            <td><?= htmlspecialchars($pm['nombre']) ?></td>
            <td><?= htmlspecialchars($pm['usuario']) ?></td>
            <td><?= htmlspecialchars($pm['email'] ?? '') ?></td>
            <td><?= htmlspecialchars($pm['telefono'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <div class="section-title">Responsables de Obra</div>
    <table>
        <tr><th>Nombre</th><th>Usuario</th><th>Email</th><th>Teléfono</th></tr>
        <?php foreach ($responsables as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['nombre']) ?></td>
            <td><?= htmlspecialchars($r['usuario']) ?></td>
            <td><?= htmlspecialchars($r['email'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['telefono'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <a href="dashboard.php">&larr; Volver al panel</a>
</div>
</body>
</html>
