<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nombre'])) {
    $nombre = trim($_POST['nombre']);
    
    if ($nombre) {
        // Obtener proyectos donde está asignado el empleado
        $query = "
            SELECT g.nombre, g.empresa 
            FROM empleados e 
            JOIN grupos g ON e.grupo_id = g.id 
            WHERE e.nombre = ? AND e.activo = 1 AND g.activo = 1
            ORDER BY g.nombre
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $nombre);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $proyectos = [];
        while ($row = $result->fetch_assoc()) {
            $proyectos[] = [
                'nombre' => $row['nombre'],
                'empresa' => $row['empresa']
            ];
        }
        
        echo json_encode(['proyectos' => $proyectos]);
    } else {
        echo json_encode(['proyectos' => []]);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Método no válido']);
}

$conn->close();
?>
