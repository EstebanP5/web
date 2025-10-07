<?php
session_start();
require_once '../includes/db.php';

// Verificar autenticación y rol de administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

require '../vendor/autoload.php';
use Smalot\PdfParser\Parser;

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$mensaje_exito = '';
$mensaje_error = '';
$empleados_detectados = [];
$fecha_procesada = '';
$faltantes = [];
$guardado = false;

// Asegurar columnas y tablas usadas por lógica avanzada
$conn->query("CREATE TABLE IF NOT EXISTS sua_lotes (id INT AUTO_INCREMENT PRIMARY KEY, fecha_proceso DATE NOT NULL, archivo VARCHAR(255) NOT NULL, total INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$conn->query("CREATE TABLE IF NOT EXISTS sua_empleados (id INT AUTO_INCREMENT PRIMARY KEY, lote_id INT NOT NULL, nss VARCHAR(25) NOT NULL, nombre VARCHAR(150) NOT NULL, curp VARCHAR(25) NOT NULL, UNIQUE KEY uniq_lote_nss (lote_id,nss), CONSTRAINT fk_lote2 FOREIGN KEY(lote_id) REFERENCES sua_lotes(id) ON DELETE CASCADE)");
$conn->query("CREATE TABLE IF NOT EXISTS autorizados_mes (fecha DATE NOT NULL, nss VARCHAR(25) NOT NULL, nombre VARCHAR(150) NOT NULL, curp VARCHAR(25) NOT NULL, PRIMARY KEY(fecha,nss))");
$colsBloq = $conn->query("SHOW COLUMNS FROM empleados LIKE 'bloqueado'"); if(!$colsBloq->num_rows){ $conn->query("ALTER TABLE empleados ADD COLUMN bloqueado TINYINT(1) NOT NULL DEFAULT 0"); }
$colsNss = $conn->query("SHOW COLUMNS FROM empleados LIKE 'nss'"); if(!$colsNss->num_rows){ $conn->query("ALTER TABLE empleados ADD COLUMN nss VARCHAR(25) NULL"); }
$colsCurp = $conn->query("SHOW COLUMNS FROM empleados LIKE 'curp'"); if(!$colsCurp->num_rows){ $conn->query("ALTER TABLE empleados ADD COLUMN curp VARCHAR(25) NULL"); }

// --- Lógica avanzada reutilizada (simplificada) del parser automático ---
if(!function_exists('suaCleanNombre')){
    function suaCleanNombre($nombre){
        $nombre=trim(preg_replace('/\s+/',' ',$nombre));
        $tokens=explode(' ',$nombre); $i=0; while(count($tokens)>2 && $i<3){ $last=end($tokens); if(strlen($last)===1 && in_array($last,['R','A'])){ array_pop($tokens); $i++; } else break; }
        return implode(' ',$tokens);
    }
}
function extraerEmpleadosAvanzado($texto){
    $originalTexto=$texto; $resultado=[];
    // Pre limpieza básica
    $t = preg_replace('/[\r\t]+/',' ',$texto);
    // Mantener saltos de línea para escaneo línea
    $lineas=preg_split('/\n|\f/',$texto);
    $patLineNSS='/^(?:\s*)(\d{2}-\d{2}-\d{2}-\d{4}-\d)\s+(.*)$/';
    $patCURP='/[A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d/';
    foreach($lineas as $ln){
        if(preg_match($patLineNSS,$ln,$m)){ $nss=$m[1]; if(isset($resultado[$nss])) continue; if(preg_match($patCURP,$ln,$curpM)){ $curp=$curpM[0]; $entre=$m[2]; $entrePart=substr($entre,0,strpos($entre,$curp)); $entrePart=str_replace(['Reing','Reing.','Baja','Alta'],' ',$entrePart); $entrePart=preg_replace('/\b[0-9]{1,4}(?:\.[0-9]{1,2})?\b/',' ',$entrePart); $entrePart=preg_replace('/[^A-ZÁÉÍÓÚÑ ]/iu',' ',$entrePart); $nombre=suaCleanNombre(strtoupper(trim(preg_replace('/\s+/',' ',$entrePart)))); if(strlen($nombre)>=5 && strlen($curp)===18) $resultado[$nss]=['nss'=>$nss,'nombre'=>$nombre,'curp'=>strtoupper($curp)]; } }
    }
    // Normalizado lineal para otros patrones
    $t2 = strtoupper(preg_replace('/\s+/',' ',preg_replace('/[\n\r\f]+/',' ',$texto)));
    // Patrón principal flexible
    if(preg_match_all('/(\d{2}-\d{2}-\d{2}-\d{4}-\d)\s+([A-ZÁÉÍÓÚÑ ]{5,90}?)\s+([A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d)/u',$t2,$mm,PREG_SET_ORDER)){
        foreach($mm as $m){ $nss=$m[1]; if(isset($resultado[$nss])) continue; $nombre=suaCleanNombre(trim($m[2])); $curp=$m[3]; if(strlen($curp)===18 && strlen($nombre)>=5) $resultado[$nss]=['nss'=>$nss,'nombre'=>$nombre,'curp'=>$curp]; }
    }
    // Pass universal ventana
    if(preg_match_all('/\d{2}-\d{2}-\d{2}-\d{4}-\d/',$t2,$all,PREG_OFFSET_CAPTURE)){
        foreach($all[0] as $data){ $nss=$data[0]; if(isset($resultado[$nss])) continue; $offset=$data[1]; $seg=substr($t2,$offset,320); if(preg_match('/[A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d/',$seg,$cM,PREG_OFFSET_CAPTURE)){ $curp=$cM[0][0]; $nombreBr=substr($seg,strlen($nss),$cM[0][1]-strlen($nss)); $nombreBr=preg_replace('/\b(REING|REING\.|BAJA|ALTA)\b/',' ',$nombreBr); $nombreBr=preg_replace('/[0-9.,]+/',' ',$nombreBr); $nombre=suaCleanNombre(trim(preg_replace('/\s+/',' ',$nombreBr))); if($nombre && strlen($curp)===18) $resultado[$nss]=['nss'=>$nss,'nombre'=>$nombre,'curp'=>$curp]; } }
    }
    return array_values($resultado);
}

function recomputeFaltantes($conn,$empleados_detectados){
    if(empty($empleados_detectados)) return [];
    $nssLista = array_column($empleados_detectados,'nss');
    $inClause = "''"; if($nssLista){ $safe = array_map(function($v) use($conn){ return "'".$conn->real_escape_string($v)."'";}, $nssLista); $inClause = implode(',',$safe); }
    $res = $conn->query("SELECT id,nombre,nss FROM empleados WHERE activo=1 AND nss IS NOT NULL AND nss<>'' AND nss NOT IN ($inClause) ORDER BY nombre");
    return $res? $res->fetch_all(MYSQLI_ASSOC):[];
}

// Función para extraer fecha
function extraerFechaProceso($texto) {
    $patrones_fecha = [
        '/Fecha de Proceso:\s*(\d{1,2})\/(\w{3,4})\.?\/(\d{4})/iu',
        '/Fecha:\s*(\d{1,2})\/(\w{3,4})\.?\/(\d{4})/iu',
        '/(\d{1,2})\/(\w{3,4})\.?\/(\d{4})/iu'
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
    
    return date('Y-m-d');
}

// Restaurar última lista si existe (persistencia entre acciones sin volver a subir PDF)
if(isset($_SESSION['ultima_sua']) && empty($_FILES) && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $empleados_detectados = $_SESSION['ultima_sua']['empleados'] ?? [];
    if(isset($_POST['fecha_procesada'])) $fecha_procesada = $_POST['fecha_procesada'];
    elseif(isset($_SESSION['ultima_sua']['fecha'])) $fecha_procesada = $_SESSION['ultima_sua']['fecha'];
    $faltantes = recomputeFaltantes($conn,$empleados_detectados);
}

// Obtener proyectos activos para asignación
$proyectos = $conn->query("SELECT id, nombre FROM grupos WHERE activo = 1 ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

// Procesar asignación masiva de empleados
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asignar_empleados'])) {
    if(!$fecha_procesada && isset($_POST['fecha_procesada'])) $fecha_procesada = $_POST['fecha_procesada'];
    $proyecto_id = intval($_POST['proyecto_id']);
    $empleados_seleccionados = $_POST['empleados_seleccionados'] ?? [];
    $contador_exitosos = 0;
    
    if ($proyecto_id && !empty($empleados_seleccionados)) {
        foreach ($empleados_seleccionados as $empleado_data) {
            $data = json_decode($empleado_data, true);
            if ($data) {
                $nombre = $data['nombre'];
                $nss = $data['nss'];
                $curp = $data['curp'];
                
                // Insertar en autorizados_mes
                $stmt = $conn->prepare("INSERT INTO autorizados_mes (nombre, nss, curp, fecha) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nss = VALUES(nss), curp = VALUES(curp)");
                $stmt->bind_param("ssss", $nombre, $nss, $curp, $fecha_procesada);
                $stmt->execute();
                
                // Crear usuario si no existe
                $parts = array_filter(preg_split('/\s+/', trim($nombre)));
                $cleanParts = [];
                foreach($parts as $pp){ $cleanParts[] = strtolower(preg_replace('/[^a-zA-Z0-9ñÑáéíóúÁÉÍÓÚ]/','', $pp)); }
                $email_usuario = ($cleanParts? implode('.', $cleanParts) : 'servicio_especializado') . '@ergosolar.com';
                $stmt_user = $conn->prepare("INSERT INTO users (name, email, password, rol, activo) VALUES (?, ?, ?, 'servicio_especializado', 1) ON DUPLICATE KEY UPDATE name = VALUES(name)");
                $password_hash = password_hash('123456', PASSWORD_DEFAULT);
                $stmt_user->bind_param("sss", $nombre, $email_usuario, $password_hash);
                $stmt_user->execute();
                $user_id_empleado = $conn->insert_id ?: $conn->query("SELECT id FROM users WHERE email = '$email_usuario'")->fetch_assoc()['id'];
                
                // Crear empleado
                $stmt_emp = $conn->prepare("INSERT INTO empleados (id, nombre, nss, curp, puesto, activo) VALUES (?, ?, ?, ?, 'Servicio Especializado', 1) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), nss = VALUES(nss), curp = VALUES(curp), puesto='Servicio Especializado', activo=1, bloqueado=0");
                $stmt_emp->bind_param("isss", $user_id_empleado, $nombre, $nss, $curp);
                $stmt_emp->execute();
                
                // Asignar al proyecto (solo uno activo a la vez)
                $stmt_d = $conn->prepare("UPDATE empleado_proyecto SET activo=0 WHERE empleado_id=? AND activo=1");
                $stmt_d->bind_param("i", $user_id_empleado);
                $stmt_d->execute();
                $stmt_asig1 = $conn->prepare("UPDATE empleado_proyecto SET activo=1, fecha_asignacion=NOW() WHERE empleado_id=? AND proyecto_id=?");
                $stmt_asig1->bind_param("ii", $user_id_empleado, $proyecto_id);
                $stmt_asig1->execute();
                if ($stmt_asig1->affected_rows === 0) {
                    $stmt_asig2 = $conn->prepare("INSERT INTO empleado_proyecto (empleado_id, proyecto_id, activo, fecha_asignacion) VALUES (?, ?, 1, NOW())");
                    $stmt_asig2->bind_param("ii", $user_id_empleado, $proyecto_id);
                    $stmt_asig2->execute();
                }
                
                $contador_exitosos++;
            }
        }
        
        $mensaje_exito = "✅ $contador_exitosos empleados asignados exitosamente al proyecto.";
    } else {
        $mensaje_error = "❌ Selecciona un proyecto y al menos un empleado.";
    }
}

// Guardar lista completa de autorizados (sin asignar proyecto necesariamente)
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['guardar_autorizados'])){
    if(!$fecha_procesada && isset($_POST['fecha_procesada'])) $fecha_procesada = $_POST['fecha_procesada'];
    if(empty($empleados_detectados) && isset($_SESSION['ultima_sua']['empleados'])) $empleados_detectados = $_SESSION['ultima_sua']['empleados'];
    if($empleados_detectados){
        $ins = $conn->prepare("INSERT INTO autorizados_mes (fecha,nss,nombre,curp) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), curp=VALUES(curp)");
        foreach($empleados_detectados as $e){ $ins->bind_param("ssss", $fecha_procesada,$e['nss'],$e['nombre'],$e['curp']); $ins->execute(); }
        // Desbloquear / activar los presentes
        $nssLista = array_column($empleados_detectados,'nss');
        if($nssLista){
            $safe = array_map(function($v) use($conn){ return "'".$conn->real_escape_string($v)."'";}, $nssLista);
            $conn->query("UPDATE empleados SET bloqueado=0, activo=1 WHERE nss IN (".implode(',',$safe).")");
        }
        $faltantes = recomputeFaltantes($conn,$empleados_detectados);
        $mensaje_exito = "✅ Lista de autorizados guardada (".count($empleados_detectados)." empleados).";
    } else {
        $mensaje_error = "❌ No hay empleados en memoria para guardar. Vuelve a procesar el PDF.";
    }
}

// Dar de baja seleccionados
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['dar_baja'])){
    $bajas = $_POST['bajas'] ?? [];
    if($bajas){
        $ids = array_map('intval',$bajas);
        $idsList = implode(',', $ids);
        $conn->query("UPDATE empleados SET bloqueado=1, activo=0 WHERE id IN ($idsList)");
        $conn->query("UPDATE empleado_proyecto SET activo=0 WHERE empleado_id IN ($idsList)");
        $mensaje_exito = "✅ ".count($ids)." empleados dados de baja.";
        // Recalcular faltantes excluyendo ya dados de baja
        $faltantes = recomputeFaltantes($conn,$empleados_detectados);
    } else {
        $mensaje_error = "❌ No seleccionaste empleados para baja.";
    }
}

// Procesar PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
    $nombreArchivo = $_FILES['archivo']['tmp_name'];
    $nombreOriginal = $_FILES['archivo']['name'];
    
    $uploadDir = '../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    
    $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
    $nombreGuardado = 'sua_' . date('Y-m-d_H-i-s') . '.' . $extension;
    $rutaCompleta = $uploadDir . $nombreGuardado;
    
    if (move_uploaded_file($nombreArchivo, $rutaCompleta)) {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($rutaCompleta);
            $texto = $pdf->getText();
            
            $fecha_procesada = extraerFechaProceso($texto);
            $empleados_detectados = extraerEmpleadosAvanzado($texto);
            
            if (empty($empleados_detectados)) {
                $mensaje_error = "❌ No se encontraron empleados en el PDF. Verifica el formato del archivo.";
            } else {
                $mensaje_exito = "✅ PDF procesado correctamente. Se encontraron " . count($empleados_detectados) . " empleados.";
                // Calcular faltantes (empleados activos con NSS conocido que no están en lista nueva)
                $faltantes = recomputeFaltantes($conn,$empleados_detectados);
                // Persistir en sesión para acciones posteriores
                $_SESSION['ultima_sua'] = [ 'fecha'=>$fecha_procesada,'empleados'=>$empleados_detectados ];
            }
            
        } catch (Exception $e) {
            $mensaje_error = "❌ Error al procesar el PDF: " . $e->getMessage();
        }
    } else {
        $mensaje_error = "❌ Error al subir el archivo PDF.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procesador de PDF SUA - Ergo PMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .header-title i {
            font-size: 28px;
        }
        
        .header-title h1 {
            font-size: 24px;
            font-weight: 700;
        }
        
        .nav-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }
        
        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
        }
        
        .section h3 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            color: #374151;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        
        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #bbf7d0;
        }
        
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .empleados-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }
        
        .empleado-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            background: #f8fafc;
            transition: all 0.2s;
        }
        
        .empleado-card:hover {
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
        }
        
        .empleado-card.selected {
            border-color: #10b981;
            background: #ecfdf5;
        }
        
        .empleado-nombre {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
        }
        
        .empleado-datos {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 12px;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .select-all-btn {
            margin-bottom: 16px;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #3b82f6;
            margin-bottom: 4px;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }
            
            .empleados-grid {
                grid-template-columns: 1fr;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="header-title">
                <i class="fas fa-file-pdf"></i>
                <div>
                    <h1>Procesador PDF SUA</h1>
                    <div style="font-size: 14px; opacity: 0.9;">Sistema de Gestión de Proyectos</div>
                </div>
            </div>
            <a href="admin.php" class="nav-btn">
                <i class="fas fa-arrow-left"></i>
                Volver al Panel
            </a>
        </div>
    </header>

    <div class="container">
        <?php if ($mensaje_exito): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $mensaje_exito; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($mensaje_error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $mensaje_error; ?>
            </div>
        <?php endif; ?>

        <!-- Subir PDF -->
        <div class="section">
            <h3><i class="fas fa-upload"></i> Subir Archivo PDF SUA</h3>
            <form method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="archivo">Seleccionar archivo PDF del SUA</label>
                    <input type="file" id="archivo" name="archivo" class="form-control" accept=".pdf" required>
                    <small style="color: #64748b; font-size: 12px;">
                        Sube el archivo PDF del SUA que contiene la lista de Servicios Especializados con NSS, nombres y CURP.
                    </small>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-file-upload"></i>
                    Procesar PDF
                </button>
            </form>
        </div>

        <?php if (!empty($empleados_detectados)): ?>
            <!-- Estadísticas -->
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($empleados_detectados); ?></div>
                    <div class="stat-label">Servicios Especializados Detectados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo date('d/m/Y', strtotime($fecha_procesada)); ?></div>
                    <div class="stat-label">Fecha Procesada</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($proyectos); ?></div>
                    <div class="stat-label">Proyectos Disponibles</div>
                </div>
                <div class="stat-card" style="background:#fff7ed">
                    <div class="stat-number" style="color:#f97316"><?php echo count($faltantes); ?></div>
                    <div class="stat-label">No renovados</div>
                </div>
            </div>

            <!-- Guardar lista autorizados -->
            <div class="section" style="border-left:4px solid #10b981">
                <h3><i class="fas fa-database"></i> Guardar Lista de Autorizados</h3>
                <form method="post">
                    <input type="hidden" name="guardar_autorizados" value="1">
                    <input type="hidden" name="fecha_procesada" value="<?php echo htmlspecialchars($fecha_procesada); ?>">
                    <button class="btn btn-success" type="submit"><i class="fas fa-save"></i> Guardar / Actualizar Autorizados</button>
                </form>
            </div>

            <?php if($faltantes): ?>
            <div class="section" style="border-left:4px solid #f59e0b;background:#fffbeb">
                <h3 style="color:#92400e"><i class="fas fa-exclamation-triangle"></i> Empleados no renovados (opcional dar de baja)</h3>
                <form method="post" style="margin-bottom:10px">
                    <input type="hidden" name="dar_baja" value="1">
                    <div style="max-height:220px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;background:#fff">
                        <table style="width:100%;border-collapse:collapse;font-size:12px">
                            <thead><tr style="background:#f1f5f9"><th style="padding:6px 8px;text-align:left">Baja?</th><th style="padding:6px 8px;text-align:left">NSS</th><th style="padding:6px 8px;text-align:left">Nombre</th></tr></thead>
                            <tbody>
                                <?php foreach($faltantes as $f): ?>
                                <tr>
                                    <td style="padding:4px 6px"><input type="checkbox" name="bajas[]" value="<?php echo (int)$f['id']; ?>"></td>
                                    <td style="padding:4px 6px"><?php echo htmlspecialchars($f['nss']); ?></td>
                                    <td style="padding:4px 6px"><?php echo htmlspecialchars($f['nombre']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-primary" type="submit" style="margin-top:10px;background:linear-gradient(135deg,#f59e0b,#d97706)"><i class="fas fa-user-slash"></i> Dar de baja seleccionados (opcional)</button>
                </form>
                <small style="color:#92400e;font-size:12px">Si no deseas dar de baja aún, simplemente ignora esta sección.</small>
            </div>
            <?php endif; ?>

            <!-- Asignación a Proyecto -->
            <div class="section">
                <h3><i class="fas fa-users-cog"></i> Asignar Empleados a Proyecto</h3>
                <form method="post" id="asignacionForm">
                    <input type="hidden" name="asignar_empleados" value="1">
                    <input type="hidden" name="fecha_procesada" value="<?php echo $fecha_procesada; ?>">
                    
                    <div class="form-group">
                        <label for="proyecto_id">Seleccionar Proyecto</label>
                        <select id="proyecto_id" name="proyecto_id" class="form-control" required>
                            <option value="">-- Selecciona un proyecto --</option>
                            <?php foreach ($proyectos as $proyecto): ?>
                                <option value="<?php echo $proyecto['id']; ?>">
                                    <?php echo htmlspecialchars($proyecto['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <button type="button" class="btn btn-primary select-all-btn" onclick="toggleSelectAll()">
                            <i class="fas fa-check-double"></i>
                            Seleccionar Todos
                        </button>
                    </div>

                    <div class="empleados-grid">
                        <?php foreach ($empleados_detectados as $index => $empleado): ?>
                            <div class="empleado-card" onclick="toggleEmpleado(<?php echo $index; ?>)">
                                <div class="empleado-nombre"><?php echo htmlspecialchars($empleado['nombre']); ?></div>
                                <div class="empleado-datos">
                                    <strong>NSS:</strong> <?php echo htmlspecialchars($empleado['nss']); ?><br>
                                    <strong>CURP:</strong> <?php echo htmlspecialchars($empleado['curp']); ?>
                                </div>
                                <div class="checkbox-wrapper">
                                    <input type="checkbox" 
                                           id="emp_<?php echo $index; ?>" 
                                           name="empleados_seleccionados[]" 
                                           value="<?php echo htmlspecialchars(json_encode($empleado)); ?>"
                                           onchange="updateCardSelection(<?php echo $index; ?>)">
                                    <label for="emp_<?php echo $index; ?>">Seleccionar para asignar</label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top: 24px; text-align: center;">
                        <button type="submit" class="btn btn-success"><i class="fas fa-user-plus"></i> Asignar Empleados Seleccionados</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleEmpleado(index) {
            const checkbox = document.getElementById('emp_' + index);
            checkbox.checked = !checkbox.checked;
            updateCardSelection(index);
        }

        function updateCardSelection(index) {
            const checkbox = document.getElementById('emp_' + index);
            const card = checkbox.closest('.empleado-card');
            
            if (checkbox.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        }

        function toggleSelectAll() {
            const checkboxes = document.querySelectorAll('input[name="empleados_seleccionados[]"]');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            
            checkboxes.forEach((checkbox, index) => {
                checkbox.checked = !allChecked;
                updateCardSelection(index);
            });
            
            const btn = document.querySelector('.select-all-btn');
            btn.innerHTML = allChecked ? 
                '<i class="fas fa-check-double"></i> Seleccionar Todos' : 
                '<i class="fas fa-times"></i> Deseleccionar Todos';
        }

        // Inicializar selecciones al cargar
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('input[name="empleados_seleccionados[]"]');
            checkboxes.forEach((checkbox, index) => {
                updateCardSelection(index);
            });
        });
    </script>
</body>
</html>