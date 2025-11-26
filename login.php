<?php
session_start();
require_once 'includes/db.php';

$error = $error ?? null;
$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST') && (
    (isset($_POST['ajax']) && $_POST['ajax'] === '1') ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($usuario) || empty($password)) {
        $error = "Por favor, ingrese usuario y contraseña.";
    } else {
        $stmt = $conn->prepare("SELECT id, name, password, rol FROM users WHERE email = ? AND activo = 1 ORDER BY updated_at DESC, id ASC LIMIT 1");
        $stmt->bind_param("s", $usuario);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows >= 1) {
            $user = $result->fetch_assoc();
            $rol_app = strtolower(trim($user['rol'] ?? ''));

            if (password_verify($password, $user['password']) || $password === $user['password']) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_rol'] = $rol_app;
                $_SESSION['usuario'] = [
                    'id' => $user['id'],
                    'nombre' => $user['name'],
                    'rol' => $rol_app,
                    'rol_original' => $user['rol']
                ];

                $redirect = null;
                switch ($rol_app) {
                    case 'admin':
                        $redirect = 'admin/admin.php';
                        break;
                    case 'pm':
                        $redirect = 'pm/dashboard.php';
                        break;
                    case 'empresa':
                        $redirect = 'empresa/dashboard.php';
                        break;
                    case 'responsable':
                    case 'servicio_especializado':
                        $redirect = 'responsable/dashboard.php';
                        break;
                    default:
                        if (in_array($rol_app, ['trabajador','worker','operario','operador','servicio_especializado'])) {
                            $_SESSION['user_rol'] = 'servicio_especializado';
                            $_SESSION['usuario']['rol'] = 'servicio_especializado';
                            $redirect = 'responsable/dashboard.php';
                        } else {
                            $stmt2 = $conn->prepare("SELECT id FROM empleados WHERE id = ? AND activo = 1");
                            $stmt2->bind_param("i", $user['id']);
                            $stmt2->execute();
                            $res2 = $stmt2->get_result();
                            if ($res2 && $res2->num_rows === 1) {
                                $_SESSION['user_rol'] = 'servicio_especializado';
                                $_SESSION['usuario']['rol'] = 'servicio_especializado';
                                $redirect = 'responsable/dashboard.php';
                            } else {
                                $error = 'Tu rol no está autorizado para este acceso.';
                            }
                            $stmt2->close();
                        }
                        break;
                }

                if (!$redirect) {
                    if (!isset($error)) {
                        $error = 'No se encontró un panel disponible para tu rol.';
                    }
                    session_unset();
                    session_destroy();
                } else {
                    $payload = [
                        'success' => true,
                        'redirect' => $redirect,
                        'rol' => $_SESSION['user_rol'],
                        'user' => [
                            'id' => $user['id'],
                            'name' => $user['name'],
                            'email' => $usuario
                        ]
                    ];

                    $stmt->close();
                    $conn->close();

                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode($payload);
                        exit();
                    }

                    header("Location: {$redirect}");
                    exit();
                }
            } else {
                $error = "Contraseña incorrecta.";
            }
        } else {
            $error = "Usuario no encontrado o inactivo.";
        }

        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        $conn->close();
    }

    if ($isAjax) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $error ?: 'No se pudo iniciar sesión. Inténtalo de nuevo.'
        ]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a2332">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="manifest" href="public/manifest.webmanifest">
    <link rel="apple-touch-icon" href="recursos/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preload" href="recursos/fondo3.jpg" as="image" fetchpriority="high">
    <link rel="preload" href="recursos/logo.png" as="image" fetchpriority="high">
    <title>Iniciar Sesión - ErgoCuida</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --navy-blue: #1a2332;
            --navy-blue-light: #2d3748;
            --navy-blue-dark: #0f1419;
            --orange: #ff6b35;
            --orange-light: #ff8a65;
            --orange-dark: #e55100;
            --white: #ffffff;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --shadow-light: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-medium: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-large: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--navy-blue-dark) url('recursos/fondo3.jpg') no-repeat center/cover fixed;
            min-height: 100vh;
            min-height: 100dvh; /* Mejora para altura dinámica en móviles */
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto; /* Permitir scroll vertical cuando sea necesario */
            isolation: isolate;
        }

        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(15, 20, 25, 0.72);
            backdrop-filter: blur(2px);
            z-index: -1;
        }

        .app-shell {
            width: 100%;
            max-width: 500px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 28px;
            margin: auto; /* Centrar verticalmente de forma más confiable */
        }

        .brand-header {
            width: 100%;
            display: flex;
            justify-content: center;
        }

        .brand-header img {
            height: 54px;
            filter: drop-shadow(0 6px 12px rgba(0,0,0,0.35));
        }

        .login-container {
            background: var(--white);
            border-radius: 28px;
            padding: 44px 40px 36px;
            width: 100%;
            box-shadow: 0 28px 48px rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .offline-status {
            display: none;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 18px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 600;
            background: rgba(255, 193, 7, 0.16);
            color: #8a6d00;
        }

        .offline-status.online {
            background: rgba(34, 197, 94, 0.12);
            color: #166534;
        }

        .offline-status .badge {
            margin-left: auto;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(0, 0, 0, 0.08);
            font-size: 12px;
            font-weight: 700;
        }

        .header {
            text-align: center;
            margin-bottom: 36px;
        }

        .brand-lockup {
            display: inline-flex;
            align-items: center;
            gap: 18px;
            padding: 16px 28px;
            border-radius: 24px;
            border: 1px solid rgba(255, 107, 53, 0.18);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(255, 239, 226, 0.85));
            box-shadow: 0 18px 30px rgba(255, 107, 53, 0.18);
            margin-bottom: 24px;
            min-height: 96px;
        }

        .brand-icon {
            width: 64px;
            height: 64px;
            border-radius: 22px;
            background: linear-gradient(135deg, var(--orange) 0%, var(--orange-dark) 100%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 28px;
            box-shadow: 0 18px 32px rgba(229, 81, 0, 0.3);
            position: relative;
        }

        .brand-icon::after {
            content: '';
            position: absolute;
            inset: -6px;
            border-radius: 26px;
            border: 2px solid rgba(255, 255, 255, 0.45);
            opacity: 0.9;
        }

        .brand-text {
            text-align: left;
        }

        .brand-eyebrow {
            display: block;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.38em;
            color: var(--gray-400);
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .brand-name {
            display: block;
            font-size: 30px;
            line-height: 1.1;
            font-weight: 800;
            color: var(--navy-blue);
            letter-spacing: 0.015em;
        }

        .brand-name strong {
            color: var(--orange-dark);
            text-shadow: 0 12px 28px rgba(229, 81, 0, 0.28);
        }

        .subtitle {
            font-size: 15px;
            color: var(--gray-500);
            font-weight: 500;
            line-height: 1.4;
            letter-spacing: 0.015em;
        }

        .form-container {
            margin-bottom: 32px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--navy-blue);
            margin-bottom: 10px;
            letter-spacing: 0.01em;
        }

        .input-container {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            font-size: 18px;
            z-index: 2;
            transition: color 0.3s ease;
        }

        .form-input {
            width: 100%;
            padding: 18px 20px 18px 56px;
            border: 2px solid var(--gray-200);
            border-radius: 16px;
            font-size: 16px;
            font-weight: 400;
            color: var(--navy-blue);
            background: var(--white);
            transition: all 0.3s ease;
            outline: none;
            font-family: inherit;
        }

        .form-input:focus {
            border-color: var(--orange);
            box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.1);
        }

        .form-input:focus + .input-icon {
            color: var(--orange);
        }

        .form-input::placeholder {
            color: var(--gray-400);
            font-weight: 400;
        }

        .password-toggle {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            font-size: 18px;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
            z-index: 2;
        }

        .password-toggle:hover {
            color: var(--orange);
            background: rgba(255, 107, 53, 0.05);
        }

        .login-button {
            width: 100%;
            background: linear-gradient(135deg, var(--orange) 0%, var(--orange-dark) 100%);
            color: var(--white);
            border: none;
            border-radius: 16px;
            padding: 18px 24px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: inherit;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-medium);
        }

        .login-button::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, var(--orange-light), var(--orange));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .login-button:hover::before {
            opacity: 1;
        }

        .login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -5px rgba(255, 107, 53, 0.4);
        }

        .login-button:active {
            transform: translateY(-1px);
        }

        .login-button span,
        .login-button i {
            position: relative;
            z-index: 1;
        }

        .login-button.loading {
            pointer-events: none;
        }

        .login-button.loading span {
            opacity: 0;
        }

        .spinner {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--white);
            animation: spin 0.8s linear infinite;
            opacity: 0;
        }

        .login-button.loading .spinner {
            opacity: 1;
        }

        @keyframes spin {
            to {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }

        .error-alert {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        .error-alert i {
            font-size: 18px;
            color: #dc2626;
            flex-shrink: 0;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .social-footer {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 18px;
            color: rgba(255,255,255,0.82);
            font-size: 14px;
            margin-top: 6px;
            letter-spacing: 0.02em;
        }

        .social-footer a {
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .social-footer a:hover {
            color: var(--white);
        }

        .social-footer i {
            font-size: 16px;
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            body {
                padding: 16px;
                padding-bottom: 20px; /* Espacio adicional abajo para el botón */
            }

            .app-shell {
                max-width: 420px;
                gap: 20px;
                padding-bottom: 10px; /* Asegurar espacio para el botón */
            }

            .brand-header img {
                height: 48px;
            }

            .login-container {
                padding: 32px 24px 28px;
                border-radius: 24px;
            }

            .brand-lockup {
                padding: 16px 20px;
                min-height: 86px;
                gap: 14px;
            }

            .brand-icon {
                width: 54px;
                height: 54px;
                font-size: 24px;
            }

            .brand-name {
                font-size: 26px;
            }

            .header {
                margin-bottom: 28px;
            }

            .form-group {
                margin-bottom: 20px;
            }

            .form-input {
                padding: 16px 18px 16px 52px;
            }

            .login-button {
                padding: 16px 20px;
                font-size: 15px;
            }
        }

        @media (max-width: 360px) {
            body {
                padding: 12px;
                padding-bottom: 16px;
            }

            .app-shell {
                gap: 16px;
            }

            .brand-header img {
                height: 44px;
            }

            .login-container {
                padding: 28px 20px 24px;
            }

            .brand-lockup {
                padding: 14px 16px;
                min-height: 80px;
                gap: 12px;
            }

            .brand-icon {
                width: 50px;
                height: 50px;
                border-radius: 18px;
                font-size: 22px;
            }

            .brand-name {
                font-size: 23px;
            }

            .brand-eyebrow {
                font-size: 11px;
                letter-spacing: 0.32em;
            }

            .header {
                margin-bottom: 24px;
            }

            .subtitle {
                font-size: 14px;
            }

            .form-group {
                margin-bottom: 18px;
            }

            .form-label {
                font-size: 13px;
                margin-bottom: 8px;
            }

            .form-input {
                padding: 14px 16px 14px 48px;
                font-size: 15px;
            }

            .input-icon {
                left: 16px;
                font-size: 16px;
            }

            .password-toggle {
                right: 16px;
                font-size: 16px;
            }

            .login-button {
                padding: 15px 18px;
                font-size: 14px;
            }

            .social-footer {
                flex-wrap: wrap;
                row-gap: 10px;
                font-size: 13px;
            }
        }

        /* Optimización para teclado virtual en móviles */
        @media (max-height: 600px) {
            body {
                padding: 12px 16px;
            }

            .app-shell {
                gap: 16px;
            }

            .brand-header {
                display: none; /* Ocultar logo cuando hay poco espacio vertical */
            }

            .brand-lockup {
                padding: 14px 18px;
                min-height: 76px;
            }

            .header {
                margin-bottom: 20px;
            }

            .subtitle {
                font-size: 13px;
            }

            .form-container {
                margin-bottom: 24px;
            }

            .form-group {
                margin-bottom: 16px;
            }

            .social-footer {
                margin-top: 4px;
                font-size: 12px;
            }
        }

        /* Para pantallas muy pequeñas con teclado visible */
        @media (max-height: 500px) {
            .brand-lockup {
                padding: 12px 16px;
                min-height: 70px;
                margin-bottom: 16px;
            }

            .brand-icon {
                width: 48px;
                height: 48px;
                font-size: 20px;
            }

            .brand-name {
                font-size: 20px;
            }

            .brand-eyebrow {
                font-size: 10px;
            }

            .header {
                margin-bottom: 16px;
            }

            .subtitle {
                display: none; /* Ocultar subtítulo en pantallas muy pequeñas */
            }

            .form-group {
                margin-bottom: 14px;
            }

            .form-container {
                margin-bottom: 20px;
            }

            .social-footer {
                display: none; /* Ocultar footer en pantallas muy pequeñas */
            }
        }

        /* Focus management */
        .form-input:focus,
        .login-button:focus,
        .password-toggle:focus {
            outline: 2px solid var(--orange);
            outline-offset: 2px;
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .form-input {
                border-width: 3px;
            }
            
            .login-button {
                border: 2px solid var(--navy-blue);
            }
        }

        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <div class="app-shell">
        <header class="brand-header">
            <img src="recursos/logo.png" alt="ErgoCuida" loading="eager" decoding="async" fetchpriority="high">
        </header>

        <div class="login-container">
            <header class="header">
                <div class="brand-lockup">
                    <div class="brand-icon">
                        <i class="fas fa-shield-halved"></i>
                    </div>
                    <div class="brand-text">
                        <span class="brand-eyebrow">PLATAFORMA</span>
                        <span class="brand-name"><strong>Ergo</strong>Cuida</span>
                    </div>
                </div>
                <p class="subtitle">Acceso seguro a tu plataforma de proyectos</p>
            </header>

            <?php if (isset($error)): ?>
            <div class="error-alert" role="alert">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <div id="loginOfflineBanner" class="offline-status">
                <span id="loginOfflineText">📡 Verificando conexión…</span>
                <span id="loginOfflineBadge" class="badge" style="display:none;">0</span>
            </div>

            <div id="loginDynamicError" class="error-alert" role="alert" style="display:none;">
                <i class="fas fa-exclamation-triangle"></i>
                <span id="loginDynamicErrorText"></span>
            </div>

            <form class="form-container" action="login.php" method="POST" id="loginForm">
            <div class="form-group">
                <label class="form-label" for="usuario">Correo Electrónico</label>
                <div class="input-container">
                    <input 
                        type="email" 
                        id="usuario" 
                        name="usuario" 
                        class="form-input" 
                        placeholder="tu@empresa.com"
                        required 
                        autocomplete="email"
                        value="<?php echo isset($_POST['usuario']) ? htmlspecialchars($_POST['usuario']) : ''; ?>"
                    >
                    <i class="fas fa-envelope input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Contraseña</label>
                <div class="input-container">
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-input" 
                        placeholder="Ingresa tu contraseña"
                        required
                        autocomplete="current-password"
                    >
                    <i class="fas fa-lock input-icon"></i>
                    <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Mostrar contraseña">
                        <i class="fas fa-eye" id="passwordToggleIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="login-button" id="loginButton">
                <span>
                    <i class="fas fa-sign-in-alt" style="margin-right: 10px;"></i>
                    Iniciar Sesión
                </span>
                <div class="spinner"></div>
            </button>
            </form>
        </div>

        <div class="social-footer">
            <a href="https://www.linkedin.com/company/ergocuida" target="_blank" rel="noopener">
                <i class="fab fa-linkedin-in"></i>
            </a>
            <a href="https://twitter.com/ergocuida" target="_blank" rel="noopener">
                <i class="fab fa-twitter"></i>
            </a>
            <a href="https://www.youtube.com/" target="_blank" rel="noopener">
                <i class="fab fa-youtube"></i>
            </a>
            <a href="https://www.facebook.com/" target="_blank" rel="noopener">
                <i class="fab fa-facebook-f"></i>
            </a>
            <a href="https://www.instagram.com/" target="_blank" rel="noopener">
                <i class="fab fa-instagram"></i>
            </a>
            <span>ErgoSolar.mx</span>
        </div>
    </div>

    <script src="public/js/pwa.js" defer></script>
    <script src="public/js/login.js" defer></script>
</body>
</html>