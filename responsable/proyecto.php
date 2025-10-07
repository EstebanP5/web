<?php
require_once '../common/auth.php';
require_once '../common/db.php';
$usuario = $_SESSION['usuario'];
if ($usuario['rol'] !== 'responsable') { header('Location: /web/login.php'); exit; }
// Aquí irá la ficha del proyecto asignado
?><!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Mi Proyecto</title></head>
<body>
<h1>Mi Proyecto</h1>
<!-- Aquí irá la ficha del proyecto -->
</body>
</html>
