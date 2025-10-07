<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prueba del Sistema Integrado</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .header {
            background: #2c3e50;
            color: white;
            text-align: center;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .test-section {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .test-section h2 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        .status-ok {
            color: #27ae60;
            font-weight: bold;
        }
        .status-error {
            color: #e74c3c;
            font-weight: bold;
        }
        .link-button {
            display: inline-block;
            background: #3498db;
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 6px;
            margin: 10px 10px 10px 0;
            font-weight: bold;
        }
        .link-button:hover {
            background: #2980b9;
        }
        .link-button.secondary {
            background: #95a5a6;
        }
        .link-button.success {
            background: #27ae60;
        }
        .test-list {
            list-style-type: none;
            padding: 0;
            require_once __DIR__ . '/../includes/db.php';
            echo '<p class="status-ok">✅ Conexión a base de datos exitosa</p>';

            // Verificar tablas necesarias
            $tablas_requeridas = ['grupos', 'empleados', 'autorizados_mes', 'fotos_asistencia'];
            foreach ($tablas_requeridas as $tabla) {
                $result = $conn->query("SHOW TABLES LIKE '$tabla'");
                if ($result && $result->num_rows > 0) {
                    echo '<p class="status-ok">✅ Tabla ' . htmlspecialchars($tabla) . ' existe</p>';
                } else {
                    echo '<p class="status-error">❌ Tabla ' . htmlspecialchars($tabla) . ' no encontrada</p>';
                }
            }

            // Verificar datos críticos
            $consultas = [
                'grupos' => "SELECT COUNT(*) AS total FROM grupos WHERE activo = 1",
                'empleados' => "SELECT COUNT(*) AS total FROM empleados",
                'autorizados_mes' => "SELECT COUNT(*) AS total FROM autorizados_mes WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
                'fotos_asistencia' => "SELECT COUNT(*) AS total FROM fotos_asistencia WHERE fecha_hora >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            ];

            foreach ($consultas as $nombre => $sql) {
                $result = $conn->query($sql);
                $total = $result ? (int)$result->fetch_assoc()['total'] : 0;
                echo '<p class="status-ok">✅ ' . ucfirst(str_replace('_', ' ', $nombre)) . ': ' . $total . ' registros</p>';
            }
                if ($result && $result->num_rows > 0) {
                    echo '<p class="status-ok">✅ Tabla ' . htmlspecialchars($tabla) . ' existe</p>';
                } else {
                    echo '<p class="status-error">❌ Tabla ' . htmlspecialchars($tabla) . ' no encontrada</p>';
                }
            }
            
            // Verificar columnas NSS y CURP
            $columnas_result = $conn->query("SHOW COLUMNS FROM autorizados_mes WHERE Field IN ('nss', 'curp')");
            if ($columnas_result && $columnas_result->num_rows >= 2) {
                echo '<p class="status-ok">✅ Columnas NSS y CURP existen en autorizados_mes</p>';
            } else {
                echo '<p class="status-error">❌ Faltan columnas NSS y/o CURP en autorizados_mes</p>';
            }
            
            $conn->close();
        }
        
        // Verificar archivos del sistema
        $archivos_requeridos = [
            'admiN.php' => 'Panel de administración principal',
            'procesar_pdf.php' => 'Procesador de PDFs SUA',
            'mapeo_empleados.php' => 'Sistema de mapeo de empleados',
            'fotos_asistencia.php' => 'Sistema de fotos de asistencia',
            '../staticmap.php' => 'Generador de mapas (StaticMapLite)'
        ];
        
        foreach ($archivos_requeridos as $archivo => $descripcion) {
            if (file_exists($archivo)) {
                echo '<p class="status-ok">✅ ' . htmlspecialchars($descripcion) . ' (' . htmlspecialchars($archivo) . ')</p>';
            } else {
                echo '<p class="status-error">❌ ' . htmlspecialchars($descripcion) . ' (' . htmlspecialchars($archivo) . ') no encontrado</p>';
            }
        }
        ?>
    </div>

    <div class="test-section">
        <h2>🛠️ Accesos Rápidos</h2>
        <p>Enlaces a las principales funcionalidades del sistema:</p>
        
        <a href="admiN.php" class="link-button success">
            🏠 Panel de Administración Principal
        </a>
        
        <a href="fotos_asistencia.php" class="link-button">
            📷 Sistema de Fotos de Asistencia
        </a>
        
        <a href="procesar_pdf.php" class="link-button secondary">
            📄 Procesador de PDFs (Directo)
        </a>
    </div>

    <div class="test-section">
        <h2>📝 Funcionalidades Implementadas</h2>
        <ul class="test-list">
            <li><strong>Mapeo Automático de Empleados:</strong> Sistema actualizado con todos los NSS, nombres y CURP proporcionados</li>
            <li><strong>Extracción Automática desde PDF:</strong> Los PDFs SUA ahora mapean automáticamente NSS, nombre y CURP</li>
            <li><strong>Interfaz de Admin Mejorada:</strong> Panel principal con navegación integrada y campos NSS/CURP</li>
            <li><strong>Sistema de Fotos de Asistencia:</strong> Captura de foto, GPS, fecha/hora y mapa integrado</li>
            <li><strong>Base de Datos Actualizada:</strong> Todas las tablas incluyen campos NSS y CURP</li>
            <li><strong>StaticMapLite Integrado:</strong> Generación de mapas usando OpenStreetMap (gratis)</li>
            <li><strong>Navegación Fluida:</strong> Botones de acceso directo y scroll suave entre secciones</li>
        </ul>
    </div>

    <div class="test-section">
        <h2>🔍 Datos de Empleados Mapeados</h2>
        <?php
        require_once 'mapeo_empleados.php';
        $empleados = MapeoEmpleados::obtenerTodosLosEmpleados();
        echo '<p><strong>Total de empleados en el mapeo:</strong> ' . count($empleados) . '</p>';
        echo '<p><strong>Primeros 5 empleados del mapeo:</strong></p>';
        echo '<table border="1" cellpadding="8" style="width:100%; margin-top:10px;">';
        echo '<tr style="background:#f8f9fa;"><th>NSS</th><th>Nombre</th><th>CURP</th></tr>';
        
        for ($i = 0; $i < min(5, count($empleados)); $i++) {
            $emp = $empleados[$i];
            echo '<tr>';
            echo '<td>' . htmlspecialchars($emp['nss']) . '</td>';
            echo '<td>' . htmlspecialchars($emp['nombre']) . '</td>';
            echo '<td>' . htmlspecialchars($emp['curp']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        ?>
    </div>

    <div class="test-section">
        <h2>📋 Instrucciones de Uso</h2>
        <ol>
            <li><strong>Subir PDF:</strong> Ve al panel de administración y sube un archivo PDF SUA</li>
            <li><strong>Mapeo Automático:</strong> El sistema extraerá automáticamente NSS, nombres y CURP de Servicios Especializados conocidos</li>
            <li><strong>Autorizar Servicios Especializados:</strong> Completa los teléfonos y autoriza Servicios Especializados individualmente o en lote</li>
            <li><strong>Fotos de Asistencia:</strong> Usa el sistema de fotos para registrar asistencia con GPS y mapas</li>
            <li><strong>Gestión Completa:</strong> Edita Servicios Especializados, asigna a proyectos y gestiona toda la información desde el admin</li>
        </ol>
    </div>

    <div class="test-section">
        <h2>⚠️ Notas Importantes</h2>
        <ul>
            <li>Asegúrate de ejecutar el archivo <code>update_database_final.sql</code> para tener todas las columnas necesarias</li>
            <li>El sistema de fotos requiere permisos de ubicación en el navegador</li>
            <li>Los mapas se generan usando OpenStreetMap y StaticMapLite (100% gratuito)</li>
            <li>El mapeo de empleados es extensible - puedes agregar más empleados en <code>mapeo_empleados.php</code></li>
        </ul>
    </div>

    <script>
        // Auto-refresh status cada 30 segundos
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
