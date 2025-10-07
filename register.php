<?php
require_once '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = $_POST['nombre'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $rol = $_POST['rol']; // 'admin', 'pm', 'responsable'

    if (empty($nombre) || empty($email) || empty($password) || empty($rol)) {
        $error = "Por favor, complete todos los campos.";
    } else {
        $conn = conectarDB();
        
        // Verificar si el email ya existe
        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "El correo electrónico ya está registrado.";
        } else {
            // Hashear la contraseña
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $nombre, $email, $hashed_password, $rol);

            if ($stmt->execute()) {
                $success = "Usuario registrado correctamente. Puede iniciar sesión.";
            } else {
                $error = "Error al registrar el usuario: " . $conn->error;
            }
        }
        $stmt->close();
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro - Sistema de Asistencia</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="card">
        <h2>Registrar Nuevo Usuario</h2>
        <p style="color:gray;">Nota: Esta página debe ser accesible solo para administradores en producción.</p>
        <?php if (isset($error)): ?>
            <p style="color: red;"><?php echo $error; ?></p>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <p style="color: green;"><?php echo $success; ?></p>
        <?php endif; ?>
        <form action="register.php" method="POST">
            <label for="nombre">Nombre Completo:</label>
            <input type="text" id="nombre" name="nombre" required>

            <label for="email">Email (será el usuario de login):</label>
            <input type="email" id="email" name="email" required>
            
            <label for="password">Contraseña:</label>
            <input type="password" id="password" name="password" required>

            <label for="rol">Rol:</label>
            <select id="rol" name="rol" required>
                <option value="admin">Administrador</option>
                <option value="pm">Project Manager</option>
                <option value="responsable">Responsable de Obra</option>
            </select>
            
            <button type="submit">Registrar</button>
        </form>
        <p>¿Ya tiene una cuenta? <a href="login.php">Inicie sesión aquí</a>.</p>
    </div>
</body>
</html>
