<?php
// Conexión global reutilizable con soporte para variables de entorno
if (!defined('DB_HOST')) {
	$envHost = getenv('DB_HOST');
	define('DB_HOST', $envHost !== false ? $envHost : 'ergoems.ddns.net');
}

if (!defined('DB_USER')) {
	$envUser = getenv('DB_USER');
	define('DB_USER', $envUser !== false ? $envUser : 'ErgoEMS');
}

if (!defined('DB_PASS')) {
	$envPass = getenv('DB_PASS');
	define('DB_PASS', $envPass !== false ? $envPass : 'C4nt0n4DBu53r$2024');
}

if (!defined('DB_NAME')) {
	$envName = getenv('DB_NAME');
	define('DB_NAME', $envName !== false ? $envName : 'emergencias');
}

if (!defined('DB_PORT')) {
	$envPort = getenv('DB_PORT');
	define('DB_PORT', $envPort !== false ? (int)$envPort : 3306);
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) die('Error de conexión: ' . $conn->connect_error);
$conn->set_charset('utf8mb4');
