<?php
session_start();
if(!isset($_SESSION['user_id']) || $_SESSION['user_rol']!=='pm'){ http_response_code(403); exit; }
require_once __DIR__ . '/../includes/db.php';
$pm = (int)$_SESSION['user_id'];
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

// Verifica que el proyecto pertenezca al PM
$stmt = $conn->prepare("SELECT 1 FROM proyectos_pm WHERE user_id=? AND proyecto_id=?");
$stmt->bind_param('ii', $pm, $id);
$stmt->execute();
$stmt->store_result();
if($stmt->num_rows === 0) { http_response_code(403); exit; }

// Elimina (desactiva) el proyecto
$stmt = $conn->prepare("UPDATE grupos SET activo=0 WHERE id=?");
$stmt->bind_param('i', $id);
$stmt->execute();

header('Content-Type: application/json');
echo json_encode(['success'=>true]);
