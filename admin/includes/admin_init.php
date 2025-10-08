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

if (!function_exists('admin_auto_close_projects')) {
    function admin_auto_close_projects(mysqli $conn): void
    {
        try {
            $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        } catch (Throwable $e) {
            $today = date('Y-m-d');
        }

        $proyectosCierre = [];
        if ($stmt = $conn->prepare("SELECT id FROM grupos WHERE activo = 1 AND fecha_fin IS NOT NULL AND fecha_fin <> '' AND fecha_fin < ?")) {
            $stmt->bind_param('s', $today);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $id = (int)($row['id'] ?? 0);
                        if ($id > 0) {
                            $proyectosCierre[] = $id;
                        }
                    }
                }
            }
            $stmt->close();
        }

        if (empty($proyectosCierre)) {
            return;
        }

        foreach ($proyectosCierre as $proyectoId) {
            try {
                if (!$conn->begin_transaction()) {
                    continue;
                }

                if ($stmt = $conn->prepare('UPDATE grupos SET activo = 0 WHERE id = ?')) {
                    $stmt->bind_param('i', $proyectoId);
                    $stmt->execute();
                    $stmt->close();
                }

                if ($stmt = $conn->prepare('UPDATE empleado_proyecto SET activo = 0 WHERE proyecto_id = ? AND activo = 1')) {
                    $stmt->bind_param('i', $proyectoId);
                    $stmt->execute();
                    $stmt->close();
                }

                if ($stmt = $conn->prepare('UPDATE proyectos_pm SET activo = 0 WHERE proyecto_id = ? AND activo = 1')) {
                    $stmt->bind_param('i', $proyectoId);
                    $stmt->execute();
                    $stmt->close();
                }

                if ($stmt = $conn->prepare('DELETE FROM empleado_programacion WHERE proyecto_id = ? AND semana_inicio >= ?')) {
                    $stmt->bind_param('is', $proyectoId, $today);
                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
            }
        }
    }
}

admin_auto_close_projects($conn);
?>
