<?php
require '../vendor/autoload.php';
use Smalot\PdfParser\Parser;

require_once __DIR__ . '/../includes/db.php';

// Obtener el grupo actual (más reciente)
function obtenerGrupoActual($conn) {
    $result = $conn->query("SELECT id FROM grupos WHERE activo = 1 ORDER BY id DESC LIMIT 1");
    return $result ? $result->fetch_assoc()['id'] ?? null : null;
}

// Función mejorada para extraer empleados del PDF SUA
function extraerEmpleadosSUA($texto) {
    $empleados_encontrados = [];
    
    // Normalizar el texto: eliminar saltos de línea y espacios extra
    $texto_normalizado = preg_replace('/[\r\n\t]+/', ' ', $texto);
    $texto_normalizado = preg_replace('/\s+/', ' ', $texto_normalizado);
    
    // Patrón completo: NSS + NOMBRE + CURP en secuencia
    // NSS: XX-XX-XX-XXXX-X, seguido de nombre (palabras con letras), seguido de CURP (18 caracteres)
    $patron_completo = '/(\d{2}-\d{2}-\d{2}-\d{4}-\d)\s+([A-ZÁÉÍÓÚÑ\s]+?)\s+([A-Z]{4}\d{6}[A-Z0-9]{6}\d{2})/i';
    
    // Buscar todas las coincidencias del patrón completo
    if (preg_match_all($patron_completo, $texto_normalizado, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $nss = trim($match[1]);
            $nombre_raw = trim($match[2]);
            $curp = trim($match[3]);
            
            // Limpiar el nombre: solo letras, espacios y acentos
            $nombre_limpio = preg_replace('/[^A-ZÁÉÍÓÚÑ\s]/i', '', $nombre_raw);
            $nombre_limpio = preg_replace('/\s+/', ' ', trim($nombre_limpio));
            $nombre_limpio = strtoupper($nombre_limpio);
            
            // Validar que el nombre tenga longitud razonable (más de 8 caracteres)
            if (strlen($nombre_limpio) >= 8 && strlen($nombre_limpio) <= 80) {
                $empleados_encontrados[] = [
                    'nss' => $nss,
                    'nombre' => $nombre_limpio,
                    'curp' => $curp
                ];
            }
        }
    }
    
    // Si no encuentra con el patrón completo, intentar método alternativo
    if (empty($empleados_encontrados)) {
        // Método alternativo: buscar por separadores de NSS
        $patron_nss = '/\d{2}-\d{2}-\d{2}-\d{4}-\d/';
        $patron_curp = '/[A-Z]{4}\d{6}[A-Z0-9]{6}\d{2}/';
        
        // Dividir el texto usando NSS como separador
        $partes = preg_split($patron_nss, $texto_normalizado, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        // Obtener todos los NSS encontrados
        preg_match_all($patron_nss, $texto_normalizado, $nss_matches);
        $nss_list = $nss_matches[0];
        
        // Procesar cada parte entre NSS
        for ($i = 1; $i < count($partes); $i++) {
            if (isset($nss_list[$i-1])) {
                $nss = $nss_list[$i-1];
                $contenido = $partes[$i];
                
                // Buscar CURP en esta parte
                if (preg_match($patron_curp, $contenido, $curp_match)) {
                    $curp = $curp_match[0];
                    
                    // Extraer nombre: todo lo que está antes del CURP
                    $nombre_parte = str_replace($curp, '', $contenido);
                    
                    // Limpiar nombre
                    $nombre_limpio = preg_replace('/[^A-ZÁÉÍÓÚÑ\s]/i', '', $nombre_parte);
                    $nombre_limpio = preg_replace('/\s+/', ' ', trim($nombre_limpio));
                    $nombre_limpio = strtoupper($nombre_limpio);
                    
                    if (strlen($nombre_limpio) >= 8 && strlen($nombre_limpio) <= 80) {
                        $empleados_encontrados[] = [
                            'nss' => $nss,
                            'nombre' => $nombre_limpio,
                            'curp' => $curp
                        ];
                    }
                }
            }
        }
    }
    
    // Método adicional: buscar patrones específicos de tu ejemplo
    if (empty($empleados_encontrados)) {
        // Para casos como "61-05-84-0852-9 CUAYA ADRIAN VICTOR CUAV841116HPLYDC09"
        $patron_especifico = '/(\d{2}-\d{2}-\d{2}-\d{4}-\d)\s+([A-Z\s]+?)([A-Z]{4}\d{6}[A-Z0-9]{6}\d{2})/';
        
        if (preg_match_all($patron_especifico, $texto_normalizado, $matches_esp, PREG_SET_ORDER)) {
            foreach ($matches_esp as $match) {
                $nss = trim($match[1]);
                $nombre_raw = trim($match[2]);
                $curp = trim($match[3]);
                
                // Limpiar nombre
                $nombre_limpio = preg_replace('/[^A-ZÁÉÍÓÚÑ\s]/i', '', $nombre_raw);
                $nombre_limpio = preg_replace('/\s+/', ' ', trim($nombre_limpio));
                $nombre_limpio = strtoupper($nombre_limpio);
                
                if (strlen($nombre_limpio) >= 8 && strlen($nombre_limpio) <= 80) {
                    $empleados_encontrados[] = [
                        'nss' => $nss,
                        'nombre' => $nombre_limpio,
                        'curp' => $curp
                    ];
                }
            }
        }
    }
    
    // Eliminar duplicados basados en NSS
    $empleados_unicos = [];
    $nss_vistos = [];
    
    foreach ($empleados_encontrados as $empleado) {
        if (!in_array($empleado['nss'], $nss_vistos)) {
            $empleados_unicos[] = $empleado;
            $nss_vistos[] = $empleado['nss'];
        }
    }
    
    return $empleados_unicos;
}

// Función mejorada para extraer la fecha
function extraerFechaProceso($texto) {
    // Patrón más flexible para la fecha
    $patrones_fecha = [
        '/Fecha de Proceso:\s*(\d{1,2})\/(\w{3,4})\.?\/(\d{4})/iu',
        '/Fecha:\s*(\d{1,2})\/(\w{3,4})\.?\/(\d{4})/iu',
        '/Proceso:\s*(\d{1,2})\/(\w{3,4})\.?\/(\d{4})/iu',
        '/(\d{1,2})\/(\w{3,4})\.?\/(\d{4})/iu' // Patrón más genérico
    ];
    
    $meses = [
        'ene' => '01', 'feb' => '02', 'mar' => '03', 'abr' => '04', 
        'may' => '05', 'jun' => '06', 'jul' => '07', 'ago' => '08', 
        'sep' => '09', 'oct' => '10', 'nov' => '11', 'dic' => '12'
    ];
    
    foreach ($patrones_fecha as $patron) {
        if (preg_match($patron, $texto, $match)) {
            $dia = str_pad($match[1], 2, '0', STR_PAD_LEFT);
            $mes_txt = strtolower(substr($match[2], 0, 3));
            $mes = isset($meses[$mes_txt]) ? $meses[$mes_txt] : '01';
            $anio = $match[3];
            return "$anio-$mes-$dia";
        }
    }
    
    // Si no encuentra fecha, usar la actual
    return date('Y-m-d');
}

// Guardar autorizado si se envía el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autorizar_nombre'])) {
    $nombre = trim($_POST['autorizar_nombre']);
    $telefono = trim($_POST['autorizar_telefono']);
    $fecha = trim($_POST['autorizar_fecha']);
    $pdf_archivo = $_POST['pdf_archivo'] ?? '';
    
    if ($nombre && $fecha) {
        // Validar si ya está autorizado para el mes
        $stmt_check = $conn->prepare("SELECT id FROM autorizados_mes WHERE nombre = ? AND fecha = ?");
        $stmt_check->bind_param("ss", $nombre, $fecha);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            $mensaje_error = "Ya está autorizado este empleado para el mes.";
        } else {
            // Insertar en autorizados_mes
            $stmt = $conn->prepare("INSERT INTO autorizados_mes (nombre, telefono, fecha) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $nombre, $telefono, $fecha);
            $stmt->execute();
            
            // Agregar automáticamente al proyecto actual si existe
            $grupo_id = obtenerGrupoActual($conn);
            if ($grupo_id) {
                // Verificar si ya está en el proyecto
                $stmt_check_emp = $conn->prepare("SELECT id FROM empleados WHERE grupo_id = ? AND nombre = ?");
                $stmt_check_emp->bind_param("is", $grupo_id, $nombre);
                $stmt_check_emp->execute();
                $stmt_check_emp->store_result();
                
                if ($stmt_check_emp->num_rows == 0) {
                    // Agregar al proyecto
                    $stmt_emp = $conn->prepare("INSERT INTO empleados (grupo_id, nombre, telefono, activo) VALUES (?, ?, ?, 1)");
                    $stmt_emp->bind_param("iss", $grupo_id, $nombre, $telefono);
                    $stmt_emp->execute();
                    $mensaje_exito = "Empleado autorizado y agregado al proyecto: $nombre";
                } else {
                    $mensaje_exito = "Empleado autorizado (ya estaba en el proyecto): $nombre";
                }
            } else {
                $mensaje_exito = "Empleado autorizado para el mes: $nombre";
            }
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Procesando PDF</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 6px; margin: 15px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; margin: 15px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 6px; margin: 15px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 6px; margin: 15px 0; }
        .btn-primary { background: #3498db; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; }
        .btn-success { background: #27ae60; color: white; padding: 5px 10px; border: none; border-radius: 4px; cursor: pointer; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f8f9fa; }
        .debug { background: #f8f9fa; border: 1px solid #dee2e6; padding: 10px; margin: 10px 0; font-family: monospace; }
    </style>
</head>
<body>
<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    if ($_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $nombreArchivo = $_FILES['archivo']['tmp_name'];
        $nombreOriginal = $_FILES['archivo']['name'];
        
        // Crear directorio uploads si no existe
        $uploadDir = '../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // Generar nombre único para el archivo
        $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $nombreGuardado = 'sua_' . date('Y-m-d_H-i-s') . '.' . $extension;
        $rutaCompleta = $uploadDir . $nombreGuardado;
        
        // Mover archivo a directorio permanente
        if (move_uploaded_file($nombreArchivo, $rutaCompleta)) {
            try {
                $parser = new Parser();
                $pdf = $parser->parseFile($rutaCompleta);
                $texto = $pdf->getText();
                
                echo "<div class='info'>📄 <strong>Archivo procesado:</strong> $nombreOriginal</div>";
                
                // Extraer la fecha usando la función mejorada
                $fecha = extraerFechaProceso($texto);
                echo "<div class='info'>📅 <strong>Fecha extraída:</strong> $fecha</div>";
                
                // Extraer empleados usando la función mejorada
                $empleados_encontrados = extraerEmpleadosSUA($texto);
                
                echo "<div class='info'>👥 <strong>Empleados encontrados:</strong> " . count($empleados_encontrados) . "</div>";
                
                // Mostrar empleados encontrados para debug
                if (!empty($empleados_encontrados)) {
                    echo "<div class='debug'>";
                    echo "<h3>🔍 Servicios Especializados detectados:</h3>";
                    foreach ($empleados_encontrados as $emp) {
                        echo "<strong>NSS:</strong> " . htmlspecialchars($emp['nss']) . " | ";
                        echo "<strong>Nombre:</strong> " . htmlspecialchars($emp['nombre']) . " | ";
                        echo "<strong>CURP:</strong> " . htmlspecialchars($emp['curp']) . "<br>";
                    }
                    echo "</div>";
                }
                
                // Convertir a formato compatible con la interfaz actual
                $nombres = [];
                foreach ($empleados_encontrados as $empleado) {
                    $nombres[] = [
                        'nombre' => $empleado['nombre'],
                        'nss' => $empleado['nss'],
                        'curp' => $empleado['curp']
                    ];
                }

                // Obtener nombres ya autorizados para ese mes
                $autorizados = [];
                $res_aut = $conn->query("SELECT nombre FROM autorizados_mes WHERE fecha = '$fecha'");
                if ($res_aut) {
                    while ($row = $res_aut->fetch_assoc()) {
                        $autorizados[] = $row['nombre'];
                    }
                }

                // Guardar información del PDF procesado en sesión para mostrar en admin.php
                session_start();
                $_SESSION['pdf_procesado'] = [
                    'nombres' => $nombres,
                    'fecha' => $fecha,
                    'archivo' => $nombreGuardado,
                    'autorizados' => $autorizados,
                    'total_encontrados' => count($empleados_encontrados)
                ];

                echo "<div class='success'>✅ Procesamiento completado exitosamente. Redirigiendo...</div>";
                echo "<script>setTimeout(function(){ window.location.href = 'admin.php'; }, 2000);</script>";
                
            } catch (Exception $e) {
                echo "<div class='error'>❌ Error al procesar el PDF: " . htmlspecialchars($e->getMessage()) . "</div>";
                echo '<br><a href="admin.php" class="btn-primary">Volver</a>';
            }
        } else {
            echo "<div class='error'>❌ Error al guardar el archivo PDF.</div>";
            echo '<br><a href="admin.php" class="btn-primary">Volver</a>';
        }
    } else {
        echo "<div class='error'>❌ Error al subir el archivo.</div>";
        echo '<br><a href="admin.php" class="btn-primary">Volver</a>';
    }
}
?>
</body>
</html>