<?php
// overpass.php – Proxy seguro para consultas Overpass API

// Headers de CORS y contenido
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Validar método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Solo se permite POST']);
    exit;
}

// Leer y sanitizar la consulta
$query = $_POST['query'] ?? '';
$query = trim($query);

// Logging para debug (opcional - comentar en producción)
error_log("Overpass Query recibida: " . $query);

// Validar contenido
if (empty($query)) {
    http_response_code(400);
    echo json_encode(['error' => 'Consulta vacía']);
    exit;
}

// Validaciones de seguridad más específicas
$forbidden_terms = ['delete', 'create', 'drop', 'insert', 'update', '<script', 'javascript:'];
foreach ($forbidden_terms as $term) {
    if (stripos($query, $term) !== false) {
        http_response_code(400);
        echo json_encode(['error' => 'Consulta contiene términos no permitidos']);
        exit;
    }
}

// Preparar la solicitud a Overpass API
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "https://overpass-api.de/api/interpreter",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: Emergency-System/1.0'
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Logging para debug
error_log("Overpass API Response Code: " . $httpCode);
if ($error) {
    error_log("cURL Error: " . $error);
}

// Manejo de errores de cURL
if ($response === false) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error al conectar con Overpass API', 
        'detalle' => $error
    ]);
    exit;
}

// Verificar si la respuesta es JSON válida
$decoded = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE && $httpCode === 200) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Respuesta inválida de Overpass API',
        'detalle' => 'No es JSON válido'
    ]);
    exit;
}

// Devolver respuesta con código original
http_response_code($httpCode);
echo $response;
?>