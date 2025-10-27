<?php
session_start();

// Headers de seguridad para prevenir caché y navegación hacia atrás
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

// Regenerar ID de sesión antes de destruir (seguridad adicional)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}

// Limpiar todas las variables de sesión
$_SESSION = array();

// Eliminar la cookie de sesión si existe (optimizado)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $params["path"],
            'domain' => $params["domain"],
            'secure' => $params["secure"],
            'httponly' => $params["httponly"],
            'samesite' => 'Strict' // Seguridad adicional contra CSRF
        ]
    );
}

// Destruir la sesión completamente
session_destroy();

// Redirigir al login con mensaje
header("Location: login.php?logout=1");
exit;
?>