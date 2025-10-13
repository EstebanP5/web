<?php
// Conexión global reutilizable con soporte para variables de entorno
if (!defined('PROJECT_BASE_PATH')) {
	define('PROJECT_BASE_PATH', dirname(__DIR__));
}

if (!defined('ENV_BOOTSTRAPPED')) {
	$autoloadPath = PROJECT_BASE_PATH . '/vendor/autoload.php';
	if (file_exists($autoloadPath)) {
		require_once $autoloadPath;
		if (class_exists(Dotenv\Dotenv::class)) {
			$dotenv = Dotenv\Dotenv::createImmutable(PROJECT_BASE_PATH);
			$dotenv->safeLoad();
		}
	}
	define('ENV_BOOTSTRAPPED', true);
}

if (!defined('DB_HOST')) {
	$envHost = getenv('DB_HOST');
	$defaultHost = 'ergoems.ddns.net';
	if ($envHost !== false && $envHost !== '') {
		define('DB_HOST', $envHost);
	} else {
		$resolved = @gethostbyname($defaultHost);
		$fallbackHost = '127.0.0.1';
		define('DB_HOST', $resolved === $defaultHost ? $fallbackHost : $defaultHost);
	}
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

$allowLocalFallback = filter_var(getenv('DB_ALLOW_LOCAL_FALLBACK') ?: 'true', FILTER_VALIDATE_BOOLEAN);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$hostsToTry = [DB_HOST];
if ($allowLocalFallback) {
	if (!in_array('127.0.0.1', $hostsToTry, true)) {
		$hostsToTry[] = '127.0.0.1';
	}
	if (!in_array('localhost', $hostsToTry, true)) {
		$hostsToTry[] = 'localhost';
	}
}

$conn = null;
$lastException = null;

foreach ($hostsToTry as $candidateHost) {
	try {
		$conn = new mysqli($candidateHost, DB_USER, DB_PASS, DB_NAME, DB_PORT);
		$conn->set_charset('utf8mb4');
		if (!defined('DB_HOST_EFFECTIVE')) {
			define('DB_HOST_EFFECTIVE', $candidateHost);
		}
		break;
	} catch (mysqli_sql_exception $e) {
		$lastException = $e;
		continue;
	}
}

if ($conn === null) {
	$errorMessage = 'Error de conexión a la base de datos.';
	if ($lastException !== null) {
		$errorMessage .= ' Detalle: ' . $lastException->getMessage();
	}
	error_log($errorMessage);
	die($errorMessage);
}
