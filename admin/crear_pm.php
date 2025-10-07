<?php
require_once '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($nombre && $email && $password) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (name, email, password, rol, activo) VALUES (?, ?, ?, 'pm', 1)");
        $stmt->bind_param("sss", $nombre, $email, $hash);
        if ($stmt->execute()) {
            $userId = $stmt->insert_id;
            // Crear registro en project_managers (si existe la tabla)
            $conn->query("CREATE TABLE IF NOT EXISTS project_managers (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT UNIQUE, nombre VARCHAR(150) NOT NULL, telefono VARCHAR(30) DEFAULT NULL, activo TINYINT(1) DEFAULT 1, creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
            $pm = $conn->prepare("INSERT INTO project_managers (user_id, nombre, telefono, activo) VALUES (?, ?, ?, 1)");
            $pm->bind_param('iss', $userId, $nombre, $telefono);
            $pm->execute();
            $mensaje = '<span style="color:green">PM creado correctamente.</span>';
        } else {
            $mensaje = '<span style="color:red">Error: El correo ya existe o hubo un problema.</span>';
        }
    } else {
        $mensaje = '<span style="color:red">Nombre, email y contraseña son obligatorios.</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Project Manager</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;background:#fafafa;min-height:100vh;color:#1a365d;line-height:1.5}
        .container{max-width:1050px;margin:0 auto;padding:20px}
        a{text-decoration:none}
        .header{background:#fff;border-radius:20px;padding:32px;margin:24px 0 32px;box-shadow:0 4px 20px rgba(26,54,93,.08);border:1px solid #f1f5f9}
        .header-content{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:24px}
        .header-left{display:flex;align-items:center;gap:20px}
        .header-icon{width:72px;height:72px;background:linear-gradient(135deg,#ff7a00 0%,#1a365d 100%);border-radius:18px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:32px;box-shadow:0 8px 32px rgba(255,122,0,.25)}
        .header-info h1{font-size:32px;font-weight:700;color:#1a365d;margin:0 0 6px;letter-spacing:-.02em}
        .header-info p{font-size:16px;color:#718096;font-weight:400;margin:0}
        .header-actions{display:flex;gap:16px;flex-wrap:wrap}
        .btn{padding:14px 24px;border:none;border-radius:14px;font-size:15px;font-weight:600;cursor:pointer;transition:.2s;display:inline-flex;align-items:center;gap:10px;white-space:nowrap}
        .btn-primary{background:linear-gradient(135deg,#ff7a00 0%,#ff9500 100%);color:#fff;box-shadow:0 6px 20px rgba(255,122,0,.3)}
        .btn-primary:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(255,122,0,.4)}
        .btn-secondary{background:#fff;color:#1a365d;border:2px solid #e2e8f0}
        .btn-secondary:hover{background:#f8fafc;border-color:#cbd5e1;transform:translateY(-1px)}
        .card{background:#fff;border-radius:20px;padding:40px 36px;box-shadow:0 4px 20px rgba(26,54,93,.08);border:1px solid #f1f5f9;margin-bottom:32px}
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px}
        label{display:block;font-size:13px;font-weight:600;color:#1a365d;margin:0 0 6px;letter-spacing:.5px;text-transform:uppercase}
        .required::after{content:' *';color:#dc2626}
        .input-wrapper{position:relative}
        .input-wrapper i{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:18px;z-index:2}
        input{width:100%;padding:16px 16px 16px 52px;border:2px solid #e2e8f0;border-radius:12px;font-size:15px;background:#fafafa;color:#1e293b;transition:.2s;font-family:inherit}
        input:focus{outline:none;border-color:#ff7a00;box-shadow:0 0 0 3px rgba(255,122,0,.15);background:#fff}
        input::placeholder{color:#94a3b8}
        .actions{display:flex;gap:14px;flex-wrap:wrap;justify-content:center;margin-top:10px}
        .message{margin-top:26px;padding:16px 18px;border-radius:14px;font-weight:500;display:flex;align-items:center;gap:12px;font-size:14px}
        .message.success{background:#ecfdf5;color:#065f46;border:1px solid #bbf7d0}
        .message.error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
        .password-strength{margin-top:8px;display:flex;gap:6px}
        .strength-bar{height:5px;flex:1;background:#e2e8f0;border-radius:3px;transition:.3s}
        @media (max-width:780px){.header{padding:24px}.header-content{flex-direction:column;align-items:flex-start}.actions{justify-content:stretch}.btn{flex:1;justify-content:center}.card{padding:32px 26px}}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-icon"><i class="fas fa-user-plus"></i></div>
                    <div class="header-info">
                        <h1>Crear Project Manager</h1>
                        <p>Agregar un nuevo gestor de proyectos al sistema</p>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="project_managers.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Volver a PMs</a>
                    <a href="proyectos.php" class="btn btn-secondary"><i class="fas fa-diagram-project"></i> Proyectos</a>
                </div>
            </div>
        </div>

        <div class="card">
            <form method="POST" id="pmForm">
                <div class="form-grid">
                    <div>
                        <label class="required">Nombre completo</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" name="nombre" required placeholder="Ej: Juan Pérez López" value="<?php echo isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : ''; ?>">
                        </div>
                    </div>
                    <div>
                        <label>Teléfono</label>
                        <div class="input-wrapper">
                            <i class="fas fa-phone"></i>
                            <input type="tel" name="telefono" placeholder="Ej: +52 555 123 4567" value="<?php echo isset($_POST['telefono']) ? htmlspecialchars($_POST['telefono']) : ''; ?>">
                        </div>
                    </div>
                    <div>
                        <label class="required">Email</label>
                        <div class="input-wrapper">
                            <i class="fas fa-envelope"></i>
                            <input type="email" name="email" required placeholder="Ej: juan.perez@empresa.com" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>
                    <div>
                        <label class="required">Contraseña</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="password" required placeholder="Mínimo 8 caracteres" id="passwordInput" minlength="8">
                        </div>
                        <div class="password-strength" id="passwordStrength">
                            <div class="strength-bar"></div>
                            <div class="strength-bar"></div>
                            <div class="strength-bar"></div>
                            <div class="strength-bar"></div>
                        </div>
                    </div>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary" id="submitBtn"><i class="fas fa-plus"></i> Crear PM</button>
                    <a href="project_managers.php" class="btn btn-secondary"><i class="fas fa-list"></i> Ver Lista</a>
                </div>
                <?php if($mensaje): ?>
                    <div class="message <?php echo strpos($mensaje, 'correctamente') !== false ? 'success' : 'error'; ?>">
                        <i class="fas <?php echo strpos($mensaje, 'correctamente') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                        <span><?php echo strip_tags($mensaje); ?></span>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
    
    <script>
        // Password strength indicator
        document.getElementById('passwordInput').addEventListener('input', function() {
            const password = this.value;
            const bars = document.querySelectorAll('.strength-bar');
            const strength = calculatePasswordStrength(password);
            
            bars.forEach((bar, index) => {
                if (index < strength) {
                    bar.style.background = getStrengthColor(strength);
                } else {
                    bar.style.background = '#e5e7eb';
                }
            });
        });
        
        function calculatePasswordStrength(password) {
            let strength = 0;
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            return strength;
        }
        
        function getStrengthColor(strength) {
            const colors = ['#dc2626', '#ea580c', '#ca8a04', '#16a34a'];
            return colors[strength - 1] || '#dc2626';
        }
        
        // Form submission loading state
        document.getElementById('pmForm').addEventListener('submit', function() {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creando...';
        });
        
        // Auto-focus on name field
        document.addEventListener('DOMContentLoaded', function() {
            const nameInput = document.querySelector('input[name="nombre"]');
            if (nameInput && !nameInput.value) {
                nameInput.focus();
            }
        });
        
        // Phone number formatting
        document.querySelector('input[name="telefono"]').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 0) {
                if (value.length <= 3) {
                    value = value;
                } else if (value.length <= 6) {
                    value = value.slice(0, 3) + ' ' + value.slice(3);
                } else {
                    value = value.slice(0, 3) + ' ' + value.slice(3, 6) + ' ' + value.slice(6, 10);
                }
            }
            e.target.value = value;
        });
    </script>
</body>
</html>
