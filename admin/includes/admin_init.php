<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_rol'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUserName = $_SESSION['user_name'] ?? 'Administrador';
?>
