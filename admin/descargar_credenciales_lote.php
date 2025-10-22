<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol']!=='admin') { http_response_code(403); exit; }
require_once '../includes/db.php';

$lote_id = isset($_GET['lote_id']) ? (int)$_GET['lote_id'] : 0;
if ($lote_id <= 0) { http_response_code(400); echo 'Lote inválido.'; exit; }

// Obtener empleados del lote
$stmt = $conn->prepare('SELECT se.nombre, u.email, u.password_visible FROM sua_empleados se LEFT JOIN empleados e ON e.nss=se.nss LEFT JOIN users u ON u.id=e.id WHERE se.lote_id=?');
$stmt->bind_param('i', $lote_id);
$stmt->execute();
$res = $stmt->get_result();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="credenciales_lote_' . $lote_id . '.csv"');
echo "\xEF\xBB\xBF"; // BOM para Excel
$out = fopen('php://output', 'w');
fputcsv($out, ['Nombre', 'Correo', 'Contraseña']);
while ($row = $res->fetch_assoc()) {
    fputcsv($out, [
        $row['nombre'] ?? '',
        $row['email'] ?? '',
        $row['password_visible'] ?? 'No disponible'
    ]);
}
fclose($out);
exit;
