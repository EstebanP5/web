<?php
/**
 * Script de Optimización del Sistema
 * Optimiza índices, tablas y consultas para mejorar el rendimiento
 */

session_start();
require_once __DIR__ . '/../includes/db.php';

// Verificar que solo admin pueda ejecutar este script
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    die('Acceso denegado. Solo administradores pueden ejecutar este script.');
}

$mensaje = '';
$errores = [];
$optimizaciones = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ejecutar_optimizacion'])) {
    try {
        // 1. Agregar índices faltantes para mejorar rendimiento
        $indices = [
            // Tabla asistencia
            ["CREATE INDEX IF NOT EXISTS idx_asistencia_fecha ON asistencia(fecha)", "Índice en asistencia.fecha"],
            ["CREATE INDEX IF NOT EXISTS idx_asistencia_empleado_fecha ON asistencia(empleado_id, fecha)", "Índice en asistencia(empleado_id, fecha)"],
            ["CREATE INDEX IF NOT EXISTS idx_asistencia_proyecto_fecha ON asistencia(proyecto_id, fecha)", "Índice en asistencia(proyecto_id, fecha)"],
            
            // Tabla grupos
            ["CREATE INDEX IF NOT EXISTS idx_grupos_empresa ON grupos(empresa)", "Índice en grupos.empresa"],
            ["CREATE INDEX IF NOT EXISTS idx_grupos_activo ON grupos(activo)", "Índice en grupos.activo"],
            
            // Tabla empleados
            ["CREATE INDEX IF NOT EXISTS idx_empleados_activo ON empleados(activo)", "Índice en empleados.activo"],
            ["CREATE INDEX IF NOT EXISTS idx_empleados_bloqueado ON empleados(bloqueado)", "Índice en empleados.bloqueado"],
            
            // Tabla empleado_proyecto
            ["CREATE INDEX IF NOT EXISTS idx_emp_proy_activo ON empleado_proyecto(activo)", "Índice en empleado_proyecto.activo"],
            ["CREATE INDEX IF NOT EXISTS idx_emp_proy_empleado ON empleado_proyecto(empleado_id, activo)", "Índice en empleado_proyecto(empleado_id, activo)"],
            
            // Tabla users
            ["CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)", "Índice en users.email"],
            ["CREATE INDEX IF NOT EXISTS idx_users_rol ON users(rol)", "Índice en users.rol"],
            ["CREATE INDEX IF NOT EXISTS idx_users_activo ON users(activo)", "Índice en users.activo"],
            
            // Tabla proyectos_pm
            ["CREATE INDEX IF NOT EXISTS idx_proy_pm_user ON proyectos_pm(user_id, activo)", "Índice en proyectos_pm(user_id, activo)"],
            ["CREATE INDEX IF NOT EXISTS idx_proy_pm_proyecto ON proyectos_pm(proyecto_id, activo)", "Índice en proyectos_pm(proyecto_id, activo)"],
        ];
        
        foreach ($indices as $index) {
            try {
                if ($conn->query($index[0])) {
                    $optimizaciones[] = "✅ " . $index[1];
                }
            } catch (Exception $e) {
                // El índice ya existe, continuar
                $optimizaciones[] = "ℹ️ " . $index[1] . " (ya existe)";
            }
        }
        
        // 2. Optimizar tablas
        $tablas = ['asistencia', 'grupos', 'empleados', 'empleado_proyecto', 'users', 'proyectos_pm', 
                   'project_managers', 'suas', 'pm_documentos', 'empresas_responsables'];
        
        foreach ($tablas as $tabla) {
            try {
                if ($conn->query("OPTIMIZE TABLE $tabla")) {
                    $optimizaciones[] = "✅ Tabla $tabla optimizada";
                }
            } catch (Exception $e) {
                $optimizaciones[] = "⚠️ No se pudo optimizar la tabla $tabla: " . $e->getMessage();
            }
        }
        
        // 3. Analizar tablas para actualizar estadísticas
        foreach ($tablas as $tabla) {
            try {
                if ($conn->query("ANALYZE TABLE $tabla")) {
                    $optimizaciones[] = "✅ Tabla $tabla analizada (estadísticas actualizadas)";
                }
            } catch (Exception $e) {
                $optimizaciones[] = "⚠️ No se pudo analizar la tabla $tabla";
            }
        }
        
        // 4. Limpiar registros antiguos (opcional - comentado por seguridad)
        // Descomenta estas líneas si quieres eliminar registros antiguos
        /*
        // Eliminar asistencias de más de 2 años
        $fecha_limite = date('Y-m-d', strtotime('-2 years'));
        $result = $conn->query("DELETE FROM asistencia WHERE fecha < '$fecha_limite'");
        if ($result) {
            $optimizaciones[] = "✅ Asistencias antiguas eliminadas (más de 2 años)";
        }
        */
        
        // 5. Verificar integridad referencial
        $checks = [
            ["SELECT COUNT(*) as count FROM asistencia a LEFT JOIN empleados e ON a.empleado_id = e.id WHERE e.id IS NULL", 
             "Asistencias huérfanas (sin empleado)"],
            ["SELECT COUNT(*) as count FROM asistencia a LEFT JOIN grupos g ON a.proyecto_id = g.id WHERE g.id IS NULL", 
             "Asistencias huérfanas (sin proyecto)"],
            ["SELECT COUNT(*) as count FROM empleado_proyecto ep LEFT JOIN empleados e ON ep.empleado_id = e.id WHERE e.id IS NULL", 
             "Asignaciones huérfanas (sin empleado)"],
        ];
        
        foreach ($checks as $check) {
            $result = $conn->query($check[0]);
            if ($result) {
                $row = $result->fetch_assoc();
                $count = (int)$row['count'];
                if ($count > 0) {
                    $errores[] = "⚠️ Se encontraron $count registros de " . $check[1];
                } else {
                    $optimizaciones[] = "✅ " . $check[1] . ": OK (0)";
                }
            }
        }
        
        // 6. Configuraciones recomendadas de MySQL (solo mostrar, no aplicar automáticamente)
        $config_recomendada = [
            "innodb_buffer_pool_size = 256M (o 70% de RAM disponible)",
            "innodb_log_file_size = 64M",
            "innodb_flush_log_at_trx_commit = 2",
            "query_cache_size = 32M",
            "query_cache_type = 1",
            "tmp_table_size = 64M",
            "max_heap_table_size = 64M",
        ];
        
        $mensaje = "<strong>✅ Optimización completada exitosamente.</strong><br><br>";
        $mensaje .= "<strong>Optimizaciones realizadas:</strong><br>";
        $mensaje .= "<ul style='margin-left: 20px;'>";
        foreach ($optimizaciones as $opt) {
            $mensaje .= "<li>$opt</li>";
        }
        $mensaje .= "</ul>";
        
        if (!empty($errores)) {
            $mensaje .= "<br><strong>⚠️ Advertencias encontradas:</strong><br>";
            $mensaje .= "<ul style='margin-left: 20px;'>";
            foreach ($errores as $error) {
                $mensaje .= "<li>$error</li>";
            }
            $mensaje .= "</ul>";
        }
        
        $mensaje .= "<br><strong>📋 Configuraciones recomendadas para MySQL:</strong><br>";
        $mensaje .= "<p>Agrega estas líneas a tu archivo <code>my.ini</code> o <code>my.cnf</code>:</p>";
        $mensaje .= "<pre style='background: #f8fafc; padding: 15px; border-radius: 8px; overflow-x: auto;'>";
        foreach ($config_recomendada as $config) {
            $mensaje .= $config . "\n";
        }
        $mensaje .= "</pre>";
        
    } catch (Exception $e) {
        $errores[] = "Error general: " . $e->getMessage();
    }
}

// Obtener estadísticas del sistema
$stats = [];
$tablas_info = ['asistencia', 'grupos', 'empleados', 'users', 'suas', 'pm_documentos'];
foreach ($tablas_info as $tabla) {
    $result = $conn->query("SELECT COUNT(*) as total FROM $tabla");
    if ($result) {
        $row = $result->fetch_assoc();
        $stats[$tabla] = (int)$row['total'];
    }
}

// Obtener tamaño de la base de datos
$db_name = DB_NAME;
$result = $conn->query("SELECT 
    table_schema AS 'Database',
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)'
    FROM information_schema.TABLES 
    WHERE table_schema = '$db_name'
    GROUP BY table_schema");
$db_size = '0 MB';
if ($result && $row = $result->fetch_assoc()) {
    $db_size = $row['Size (MB)'] . ' MB';
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Optimización del Sistema - ErgoCuida</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f1f5f9;
            padding: 20px;
            color: #1e293b;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 28px; margin-bottom: 10px; color: #1e293b; }
        .header p { color: #64748b; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .card h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        .btn-primary:hover {
            background: #2563eb;
        }
        .btn-success {
            background: #22c55e;
            color: white;
        }
        .btn-success:hover {
            background: #16a34a;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        .nav-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
        }
        .nav-link:hover {
            color: #2563eb;
        }
        .info-box {
            background: #eff6ff;
            border: 2px solid #3b82f6;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .info-box h3 {
            color: #1e40af;
            margin-bottom: 10px;
        }
        .info-box ul {
            margin-left: 20px;
            margin-top: 10px;
        }
        .info-box li {
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="admin.php" class="nav-link"><i class="fas fa-arrow-left"></i> Volver al Panel de Administración</a>
        
        <div class="header">
            <h1><i class="fas fa-tachometer-alt"></i> Optimización del Sistema</h1>
            <p>Mejora el rendimiento del sistema optimizando índices y tablas de la base de datos</p>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-success">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['asistencia'] ?? 0; ?></div>
                <div class="stat-label">Registros de Asistencia</div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <div class="stat-number"><?php echo $stats['grupos'] ?? 0; ?></div>
                <div class="stat-label">Proyectos</div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <div class="stat-number"><?php echo $stats['empleados'] ?? 0; ?></div>
                <div class="stat-label">Empleados</div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                <div class="stat-number"><?php echo $stats['users'] ?? 0; ?></div>
                <div class="stat-label">Usuarios</div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                <div class="stat-number"><?php echo $db_size; ?></div>
                <div class="stat-label">Tamaño de BD</div>
            </div>
        </div>

        <div class="info-box">
            <h3><i class="fas fa-info-circle"></i> ¿Qué hace este proceso?</h3>
            <p>La optimización del sistema realizará las siguientes acciones:</p>
            <ul>
                <li><strong>Agregar índices:</strong> Mejora la velocidad de búsqueda y consultas</li>
                <li><strong>Optimizar tablas:</strong> Reorganiza datos para mejor rendimiento</li>
                <li><strong>Analizar tablas:</strong> Actualiza estadísticas del optimizador de MySQL</li>
                <li><strong>Verificar integridad:</strong> Detecta registros huérfanos o inconsistencias</li>
                <li><strong>Recomendaciones:</strong> Proporciona configuraciones óptimas de MySQL</li>
            </ul>
            <p style="margin-top: 15px;"><strong>Tiempo estimado:</strong> 1-5 minutos (dependiendo del tamaño de la base de datos)</p>
            <p><strong>Impacto:</strong> No afecta los datos existentes, solo mejora el rendimiento</p>
        </div>

        <div class="card">
            <h2><i class="fas fa-rocket"></i> Ejecutar Optimización</h2>
            <p style="margin-bottom: 20px; color: #64748b;">
                Haz clic en el botón de abajo para iniciar el proceso de optimización. 
                El sistema creará índices faltantes, optimizará tablas y verificará la integridad de los datos.
            </p>
            <form method="POST">
                <input type="hidden" name="ejecutar_optimizacion" value="1">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-play"></i> Ejecutar Optimización Ahora
                </button>
            </form>
        </div>

        <div class="card">
            <h2><i class="fas fa-chart-line"></i> Estadísticas del Sistema</h2>
            <table style="width: 100%; border-collapse: collapse;">
                <tr style="border-bottom: 1px solid #e2e8f0;">
                    <td style="padding: 12px;"><strong>Tabla</strong></td>
                    <td style="padding: 12px; text-align: right;"><strong>Registros</strong></td>
                </tr>
                <?php foreach ($stats as $tabla => $total): ?>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 12px;"><?php echo htmlspecialchars($tabla); ?></td>
                        <td style="padding: 12px; text-align: right; font-weight: 600; color: #3b82f6;">
                            <?php echo number_format($total); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr style="background: #f8fafc;">
                    <td style="padding: 12px;"><strong>Tamaño Total de la Base de Datos</strong></td>
                    <td style="padding: 12px; text-align: right; font-weight: 700; color: #059669;">
                        <?php echo $db_size; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
