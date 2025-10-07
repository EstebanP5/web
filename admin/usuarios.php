<?php
require_once '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
$mensaje = '';
// Eliminar PM
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $conn->query("DELETE FROM usuarios WHERE id=$id AND rol='pm'");
    $mensaje = '<span style="color:green">PM eliminado.</span>';
}
// Editar PM
if (isset($_POST['editar_id'])) {
    $id = intval($_POST['editar_id']);
    $nombre = trim($_POST['editar_nombre']);
    $email = trim($_POST['editar_email']);
    $pass = $_POST['editar_password'];
    if ($nombre && $email) {
        $sql = "UPDATE usuarios SET nombre=?, email=?";
        $params = [$nombre, $email];
        $types = "ss";
        if ($pass) {
            $sql .= ", password=?";
            $params[] = password_hash($pass, PASSWORD_DEFAULT);
            $types .= "s";
        }
        $sql .= " WHERE id=? AND rol='pm'";
        $params[] = $id;
        $types .= "i";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $mensaje = '<span style="color:green">PM actualizado.</span>';
    }
}
$pms = $conn->query("SELECT * FROM users WHERE rol='pm' ORDER BY name");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Project Managers</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
    .pm-table { width:100%; border-collapse:collapse; margin-top:1em; }
    .pm-table th, .pm-table td { border:1px solid #ccc; padding:8px; }
    .pm-table th { background:#f5f5f5; }
    </style>
</head>
<body>
<div class="card">
    <h2>Project Managers</h2>
    <?php if($mensaje) echo '<div>'.$mensaje.'</div>'; ?>
    <table class="pm-table">
        <tr><th>Nombre</th><th>Email</th><th>Acciones</th></tr>
        <?php while($pm = $pms->fetch_assoc()): ?>
        <tr>
            <form method="POST">
            <td><input type="text" name="editar_nombre" value="<?= htmlspecialchars($pm['nombre']) ?>" required></td>
            <td><input type="email" name="editar_email" value="<?= htmlspecialchars($pm['email']) ?>" required></td>
            <td>
                <input type="hidden" name="editar_id" value="<?= $pm['id'] ?>">
                <input type="password" name="editar_password" placeholder="Nueva contraseña (opcional)">
                <button type="submit">Guardar</button>
                <a href="usuarios.php?eliminar=<?= $pm['id'] ?>" onclick="return confirm('¿Eliminar este PM?')">Eliminar</a>
            </td>
            </form>
        </tr>
        <?php endwhile; ?>
    </table>
    <a href="dashboard.php">&larr; Volver al panel admin</a>
</div>
</body>
</html>
