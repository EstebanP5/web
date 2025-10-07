<?php
function check_session() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php");
        exit;
    }
}

function check_role($role) {
    if ($_SESSION['role'] !== $role) {
        // O redirigir a una página de "acceso denegado"
        header("Location: ../login.php");
        exit;
    }
}
?>
