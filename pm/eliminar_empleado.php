<?php
session_start();
if(!isset($_SESSION['user_id']) || $_SESSION['user_rol']!=='pm'){ http_response_code(403); exit; }
require_once __DIR__ . '/../includes/db.php';
$pm = (int)$_SESSION['user_id'];
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;



// Elimina (desactiva) el empleado
$stmt = $conn->prepare("UPDATE empleados SET activo=0 WHERE id=?");
$stmt->bind_param('i', $id);
$stmt->execute();

header('Content-Type: application/json');
echo json_encode(['success'=>true]);
