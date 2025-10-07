<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Procesar autorización desde PDF si viene de esa página
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autorizar_nombre_pdf'])) {
    $nombre = trim($_POST['autorizar_nombre_pdf']);
    $telefono = trim($_POST['autorizar_telefono_pdf']);
    $nss = trim($_POST['autorizar_nss_pdf'] ?? '');
    $curp = trim($_POST['autorizar_curp_pdf'] ?? '');
    $fecha = trim($_POST['autorizar_fecha_pdf']);
    
    if ($nombre && $fecha) {
        // Validar si ya está autorizado para ese mes
        $stmt_check = $conn->prepare("SELECT id FROM autorizados_mes WHERE nombre = ? AND fecha = ?");
        $stmt_check->bind_param("ss", $nombre, $fecha);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            $mensaje_error = "Ya está autorizado este empleado para el mes.";        } else {
            // Insertar en autorizados_mes (solo autorizar, NO asignar automáticamente)
            $stmt = $conn->prepare("INSERT INTO autorizados_mes (nombre, telefono, nss, curp, fecha) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $nombre, $telefono, $nss, $curp, $fecha);
            $stmt->execute();
            
            $mensaje_exito = "Empleado autorizado para el mes: $nombre";
        }
    }
}

// Procesar autorización masiva desde PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autorizar_todos_pdf'])) {
    $empleados_data = json_decode($_POST['empleados_data'], true);
    $fecha = trim($_POST['fecha_proceso']);
    
    if ($empleados_data && $fecha) {        $empleados_autorizados = 0;
        $empleados_ya_autorizados = 0;
        $empleados_sin_telefono = 0;
          foreach ($empleados_data as $empleado) {
            $nombre = trim($empleado['nombre']);
            $telefono = trim($empleado['telefono']);
            $nss = trim($empleado['nss'] ?? '');
            $curp = trim($empleado['curp'] ?? '');
            
            if (!$telefono) {
                $empleados_sin_telefono++;
                continue;
            }
            
            // Validar si ya está autorizado para ese mes
            $stmt_check = $conn->prepare("SELECT id FROM autorizados_mes WHERE nombre = ? AND fecha = ?");
            $stmt_check->bind_param("ss", $nombre, $fecha);
            $stmt_check->execute();
            $stmt_check->store_result();
            
            if ($stmt_check->num_rows > 0) {
                $empleados_ya_autorizados++;            } else {
                // Insertar en autorizados_mes (solo autorizar, NO asignar automáticamente)
                $stmt = $conn->prepare("INSERT INTO autorizados_mes (nombre, telefono, nss, curp, fecha) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $nombre, $telefono, $nss, $curp, $fecha);
                $stmt->execute();
                $empleados_autorizados++;
            }
        }
          // Mensaje de resultado
        $mensaje_exito = "🚀 Autorización masiva completada: $empleados_autorizados empleado(s) autorizado(s)";
        if ($empleados_ya_autorizados > 0) {
            $mensaje_exito .= " ($empleados_ya_autorizados ya estaban autorizados)";
        }
        if ($empleados_sin_telefono > 0) {
            $mensaje_exito .= " ($empleados_sin_telefono omitidos por falta de teléfono)";
        }
    } else {
        $mensaje_error = "Error en los datos para autorización masiva.";
    }
}

// Código de asignación masiva removido - solo asignación individual permitida

// Procesar asignación individual de empleado autorizado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asignar_empleado'])) {
    $nombre = trim($_POST['asignar_empleado']);
    $telefono = trim($_POST['telefono_empleado']);
    
    if ($nombre && $telefono) {
        // Obtener el proyecto actual
        $result = $conn->query("SELECT id, nombre as proyecto_nombre FROM grupos WHERE activo = 1 ORDER BY id DESC LIMIT 1");
        $grupo = $result ? $result->fetch_assoc() : null;
        
        if ($grupo) {
            $grupo_id = $grupo['id'];
            $proyecto_nombre = $grupo['proyecto_nombre'];
            
            // Verificar si ya está en el proyecto
            $stmt_check = $conn->prepare("SELECT id FROM empleados WHERE grupo_id = ? AND nombre = ?");
            $stmt_check->bind_param("is", $grupo_id, $nombre);
            $stmt_check->execute();
            $stmt_check->store_result();
              if ($stmt_check->num_rows == 0) {
                // Obtener NSS y CURP del empleado autorizado
                $stmt_datos = $conn->prepare("SELECT nss, curp FROM autorizados_mes WHERE nombre = ? LIMIT 1");
                $stmt_datos->bind_param("s", $nombre);
                $stmt_datos->execute();
                $datos_result = $stmt_datos->get_result();
                $datos_empleado = $datos_result->fetch_assoc();
                $nss = $datos_empleado['nss'] ?? '';
                $curp = $datos_empleado['curp'] ?? '';
                
                // Agregar al proyecto
                $stmt_emp = $conn->prepare("INSERT INTO empleados (grupo_id, nombre, telefono, nss, curp, activo) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt_emp->bind_param("issss", $grupo_id, $nombre, $telefono, $nss, $curp);
                $stmt_emp->execute();
                $mensaje_exito = "Empleado $nombre asignado al proyecto: $proyecto_nombre";
            } else {
                $mensaje_error = "El empleado $nombre ya está asignado al proyecto actual.";
            }
        } else {
            $mensaje_error = "No hay un proyecto activo para asignar empleados.";
        }
    }
}

// Procesar asignación de empleado a proyecto específico
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asignar_proyecto_especifico'])) {
    $nombre = trim($_POST['empleado_nombre_asignar']);
    $telefono = trim($_POST['empleado_telefono_asignar']);
    $proyecto_id = intval($_POST['proyecto_seleccionado']);
    $accion = $_POST['accion_asignacion']; // 'asignar' o 'mover'
    
    if ($nombre && $telefono && $proyecto_id) {
        // Obtener información del proyecto seleccionado
        $stmt_proyecto = $conn->prepare("SELECT nombre FROM grupos WHERE id = ?");
        $stmt_proyecto->bind_param("i", $proyecto_id);
        $stmt_proyecto->execute();
        $proyecto_result = $stmt_proyecto->get_result();
        $proyecto_info = $proyecto_result->fetch_assoc();
        
        if ($proyecto_info) {
            $proyecto_nombre = $proyecto_info['nombre'];
            
            if ($accion === 'mover') {
                // Primero eliminar de otros proyectos
                $stmt_delete = $conn->prepare("DELETE FROM empleados WHERE nombre = ?");
                $stmt_delete->bind_param("s", $nombre);
                $stmt_delete->execute();
            }
            
            // Verificar si ya está en el proyecto destino
            $stmt_check = $conn->prepare("SELECT id FROM empleados WHERE grupo_id = ? AND nombre = ?");
            $stmt_check->bind_param("is", $proyecto_id, $nombre);
            $stmt_check->execute();
            $stmt_check->store_result();
              if ($stmt_check->num_rows == 0) {
                // Obtener NSS y CURP del empleado autorizado
                $stmt_datos = $conn->prepare("SELECT nss, curp FROM autorizados_mes WHERE nombre = ? LIMIT 1");
                $stmt_datos->bind_param("s", $nombre);
                $stmt_datos->execute();
                $datos_result = $stmt_datos->get_result();
                $datos_empleado = $datos_result->fetch_assoc();
                $nss = $datos_empleado['nss'] ?? '';
                $curp = $datos_empleado['curp'] ?? '';
                
                // Agregar al proyecto
                $stmt_emp = $conn->prepare("INSERT INTO empleados (grupo_id, nombre, telefono, nss, curp, activo) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt_emp->bind_param("issss", $proyecto_id, $nombre, $telefono, $nss, $curp);
                $stmt_emp->execute();
                
                $accion_texto = ($accion === 'mover') ? 'movido' : 'asignado';
                $mensaje_exito = "Empleado $nombre $accion_texto al proyecto: $proyecto_nombre";
            } else {
                $mensaje_error = "El empleado $nombre ya está asignado al proyecto: $proyecto_nombre";
            }
        } else {
            $mensaje_error = "Proyecto no encontrado.";
        }
    }
}

// Limpiar toda la tabla de autorizados
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['limpiar_tabla'])) {
    $mes_actual = date('Y-m-01');
    $stmt = $conn->prepare("DELETE FROM autorizados_mes WHERE fecha >= ?");
    $stmt->bind_param("s", $mes_actual);
    $stmt->execute();
    $mensaje_exito = "Tabla de autorizados limpiada completamente.";
}

// Eliminar empleado autorizado individual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_autorizado'])) {
    $nombre = trim($_POST['eliminar_autorizado']);
    $fecha = trim($_POST['fecha_autorizado']);
    
    if ($nombre && $fecha) {
        $stmt = $conn->prepare("DELETE FROM autorizados_mes WHERE nombre = ? AND fecha = ?");
        $stmt->bind_param("ss", $nombre, $fecha);
        $stmt->execute();
        $mensaje_exito = "Empleado $nombre eliminado de autorizados.";
    }
}

// Editar empleado autorizado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_autorizado'])) {
    $nombre_original = trim($_POST['nombre_original']);
    $fecha_original = trim($_POST['fecha_original']);
    $nuevo_nombre = trim($_POST['nuevo_nombre']);
    $nuevo_telefono = trim($_POST['nuevo_telefono']);
    $nuevo_nss = trim($_POST['nuevo_nss'] ?? '');
    $nuevo_curp = trim($_POST['nuevo_curp'] ?? '');
    
    if ($nombre_original && $fecha_original && $nuevo_nombre && $nuevo_telefono) {
        $stmt = $conn->prepare("UPDATE autorizados_mes SET nombre = ?, telefono = ?, nss = ?, curp = ? WHERE nombre = ? AND fecha = ?");
        $stmt->bind_param("ssssss", $nuevo_nombre, $nuevo_telefono, $nuevo_nss, $nuevo_curp, $nombre_original, $fecha_original);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $mensaje_exito = "Empleado actualizado correctamente.";
        } else {
            $mensaje_error = "No se pudo actualizar el empleado.";
        }
    }
}

// CREAR GRUPO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear'])) {
    $stmt = $conn->prepare("
        INSERT INTO grupos (token, nombre, localidad, lat, lng, pm_nombre, pm_telefono, empresa, contacto_seguro_nombre, contacto_seguro_telefono, activo) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $token = bin2hex(random_bytes(16));
    $contacto_seguro_nombre = trim($_POST['contacto_seguro_nombre'] ?? '');
    $contacto_seguro_telefono = trim($_POST['contacto_seguro_telefono'] ?? '');
    
    $stmt->bind_param(
        "sssddsssss",
        $token,
        $_POST['nombre'],
        $_POST['localidad'],
        $_POST['lat'],
        $_POST['lng'],
        $_POST['pm_nombre'],
        $_POST['pm_telefono'],
        $_POST['empresa'],
        $contacto_seguro_nombre,
        $contacto_seguro_telefono
    );
    $stmt->execute();
    $grupo_id = $stmt->insert_id;

    echo "<div class='success'>✅ Grupo creado exitosamente. <a href=\"../public/emergency.php?token=$token\" target=\"_blank\">Ver página de emergencias</a></div>";
}

// ELIMINAR GRUPO
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $conn->query("DELETE FROM empleados WHERE grupo_id = $id");
    $conn->query("DELETE FROM grupos WHERE id = $id");
    echo "<div class='success'>🗑️ Grupo eliminado.</div>";
}

// TRAER GRUPOS
$grupos = $conn->query("SELECT * FROM grupos ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin de Grupos de Emergencia</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    * {
      box-sizing: border-box;
    }
    body { 
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
      padding: 1em; 
      background: #f5f5f5;
      margin: 0;
    }
    .container {
      max-width: 1200px;
      margin: 0 auto;
      background: white;
      padding: 2em;
      border-radius: 10px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    h1 {
      color: #2c3e50;
      text-align: center;
      margin-bottom: 2em;
      font-size: 2.2em;
    }
    h2 {
      color: #34495e;
      border-bottom: 2px solid #3498db;
      padding-bottom: 0.5em;
      margin-top: 2em;
    }
    .form-section {
      background: #f8f9fa;
      padding: 1.5em;
      border-radius: 8px;
      margin-bottom: 2em;
    }
    label { 
      display: block; 
      margin-top: 15px; 
      font-weight: 600;
      color: #2c3e50;
    }
    input[type="text"], input[type="number"], input[type="tel"] {
      width: 100%;
      padding: 12px;
      border: 2px solid #ddd;
      border-radius: 6px;
      font-size: 14px;
      transition: border-color 0.3s;
    }
    input:focus {
      outline: none;
      border-color: #3498db;
    }
    .location-section {
      background: #e8f4f8;
      padding: 1em;
      border-radius: 6px;
      margin: 15px 0;
    }
    .location-input-group {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-top: 10px;
    }
    .location-buttons {
      display: flex;
      gap: 10px;
      margin-top: 10px;
      flex-wrap: wrap;
    }
    .empleado { 
      border: 2px solid #e74c3c; 
      padding: 15px; 
      margin: 15px 0; 
      border-radius: 8px;
      background: #fff5f5;
      position: relative;
    }
    .empleado h4 {
      margin-top: 0;
      color: #e74c3c;
    }
    .empleado-inputs {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 15px;
    }
    .remove-empleado {
      position: absolute;
      top: 10px;
      right: 10px;
      background: #e74c3c;
      color: white;
      border: none;
      border-radius: 50%;
      width: 30px;
      height: 30px;
      cursor: pointer;
      font-size: 16px;
    }
    button { 
      padding: 12px 20px; 
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-size: 14px;
      font-weight: 600;
      transition: all 0.3s;
    }
    .btn-primary {
      background: #3498db;
      color: white;
    }
    .btn-primary:hover {
      background: #2980b9;
    }
    .btn-success {
      background: #27ae60;
      color: white;
    }
    .btn-success:hover {
      background: #229954;
    }
    .btn-warning {
      background: #f39c12;
      color: white;
    }
    .btn-warning:hover {
      background: #e67e22;
    }
    .btn-danger {
      background: #e74c3c;
      color: white;
    }
    .btn-danger:hover {
      background: #c0392b;
    }
    .btn-secondary {
      background: #95a5a6;
      color: white;
    }    .btn-secondary:hover {
      background: #7f8c8d;
    }    .btn-small {
      padding: 5px 10px !important;
      font-size: 12px !important;
      border-radius: 4px !important;
    }
    .btn-mini {
      padding: 3px 6px !important;
      font-size: 10px !important;
      border-radius: 3px !important;
      margin: 0 2px !important;
    }
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
    }
    .modal-content {
      background-color: white;
      margin: 10% auto;
      padding: 20px;
      border-radius: 8px;
      width: 90%;
      max-width: 500px;
      position: relative;
    }
    .close {
      color: #aaa;
      float: right;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
    }
    .close:hover {
      color: black;
    }
    .form-group {
      margin-bottom: 15px;
    }
    .form-group label {
      display: block;
      margin-bottom: 5px;
      font-weight: bold;
    }
    .form-group input {
      width: 100%;
      padding: 8px;
      border: 1px solid #ddd;
      border-radius: 4px;
    }
    .card { 
      border: 2px solid #ddd; 
      padding: 20px; 
      margin: 15px 0; 
      border-radius: 10px;
      background: white;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    .card-header {
      display: flex;
      justify-content: between;
      align-items: center;
      margin-bottom: 15px;
    }
    .card-title {
      font-size: 1.3em;
      font-weight: bold;
      color: #2c3e50;
      margin: 0;
    }
    .card-subtitle {
      color: #7f8c8d;
      margin: 5px 0;
    }
    .acciones { 
      margin-top: 15px;
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    .success {
      background: #d4edda;
      color: #155724;
      padding: 15px;
      border-radius: 6px;
      margin: 15px 0;
      border: 1px solid #c3e6cb;
    }
    .error {
      background: #f8d7da;
      color: #721c24;
      padding: 15px;
      border-radius: 6px;
      margin: 15px 0;
      border: 1px solid #f5c6cb;
    }
    .loading {
      display: none;
      color: #3498db;
      font-style: italic;
    }
    .coordinates-info {
      background: #fff3cd;
      padding: 10px;
      border-radius: 4px;
      margin-top: 10px;
      font-size: 0.9em;
      color: #856404;
    }
    .alert {
      padding: 15px;
      margin: 15px 0;
      border-radius: 6px;
    }
    .alert-success {
      background: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    .alert-danger {
      background: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }
    @media (max-width: 768px) {
      .container {
        padding: 1em;
        margin: 0.5em;
      }
      .location-input-group {
        grid-template-columns: 1fr;
      }
      .empleado-inputs {
        grid-template-columns: 1fr;
      }
      .location-buttons {
        flex-direction: column;
      }
      .acciones {
        flex-direction: column;
      }
      button {
        width: 100%;
      }
    }
  </style>
</head>
<body>
<div class="container">
  <h1>🚨 Administrador de Grupos de Emergencia</h1>
  
  <!-- Panel de navegación principal -->
  <div style="background:#f8f9fa; padding:15px; border-radius:8px; margin-bottom:20px; text-align:center;">
    <h3 style="margin:0 0 15px 0; color:#2c3e50;">🛠️ Panel de Control</h3>
    <div style="display:flex; justify-content:center; gap:15px; flex-wrap:wrap;">
      <a href="fotos_asistencia.php" target="_blank" class="btn-success" style="text-decoration:none; padding:12px 20px; border-radius:6px;">
        📷 Sistema de Fotos de Asistencia
      </a>
      <button onclick="scrollToSection('pdf-upload')" class="btn-primary" style="padding:12px 20px; border-radius:6px;">
        📄 Gestión de PDFs
      </button>
      <button onclick="scrollToSection('empleados-autorizados')" class="btn-secondary" style="padding:12px 20px; border-radius:6px;">
        👥 Servicios Especializados Autorizados
      </button>
    </div>
  </div>

  <h2 id="pdf-upload">Subir y Procesar PDF</h2>
  <div class="form-section">
    <form action="procesar_pdf.php" method="post" enctype="multipart/form-data">
      <label for="archivo">Selecciona un PDF:</label>
      <input type="file" name="archivo" id="archivo" accept="application/pdf" required>
      <button type="submit" class="btn-primary">Subir PDF</button>
    </form>
  </div>  <h2 id="empleados-autorizados">Autorizados del Mes Actual</h2>
  <div class="form-section">
    <?php if (isset($mensaje_exito)): ?>
      <div class="alert alert-success"><?= htmlspecialchars($mensaje_exito) ?></div>
    <?php endif; ?>
    <?php if (isset($mensaje_error)): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($mensaje_error) ?></div>
    <?php endif; ?>
    
    <!-- Enlace permanente al último PDF cargado -->
    <?php 
    // Verificar si hay un PDF en la sesión para mostrar enlace permanente
    $pdf_permanente = null;
    if (isset($_SESSION['pdf_procesado'])) {
        $pdf_permanente = $_SESSION['pdf_procesado']['archivo'];
    } else {
        // Buscar el último PDF cargado en la carpeta uploads
        $uploads_dir = 'uploads/';
        if (is_dir($uploads_dir)) {
            $files = glob($uploads_dir . '*.pdf');
            if (!empty($files)) {
                // Ordenar por fecha de modificación y tomar el más reciente
                usort($files, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                $pdf_permanente = basename($files[0]);
            }
        }
    }
    
    if ($pdf_permanente): ?>
    <div style="background:#f8f9fa; padding:10px; border-radius:5px; margin-bottom:15px; text-align:center;">
      📄 <strong>PDF Actual:</strong> 
      <a href="ver_pdf.php?archivo=<?= urlencode($pdf_permanente) ?>" target="_blank" 
         class="btn-secondary btn-small" style="text-decoration:none; margin-left:10px;">
        Ver PDF de Referencia
      </a>
    </div>
    <?php endif; ?>
      <?php
    // Mostrar empleados extraídos del PDF si están disponibles
    if (isset($_SESSION['pdf_procesado'])):
        $pdf_data = $_SESSION['pdf_procesado'];
        $nombres = $pdf_data['nombres'];
        $fecha = $pdf_data['fecha'];
        $archivo = $pdf_data['archivo'];
        
        // Actualizar autorizados en tiempo real
        $autorizados = [];
        $res_aut_temp = $conn->query("SELECT nombre FROM autorizados_mes WHERE fecha = '$fecha'");
        while ($row = $res_aut_temp->fetch_assoc()) {
            $autorizados[] = $row['nombre'];
        }
    ?>
      <div id="pdf-results" style="background:#e8f5e8; padding:15px; border-radius:8px; margin-bottom:20px;">
  <h3 style="color:#27ae60; margin-top:0;">📄 Servicios Especializados Extraídos del PDF</h3>
        <p><strong>Fecha de proceso:</strong> <?= htmlspecialchars($fecha) ?> | 
           <a href="ver_pdf.php?archivo=<?= urlencode($archivo) ?>" target="_blank" class="btn-secondary btn-small" style="text-decoration:none;">Ver PDF</a>
        </p>
          <?php if (empty($nombres)): ?>
          <div class="alert alert-danger">No se encontraron nombres conocidos en el PDF.</div>
        <?php else: ?>          <!-- Botón para autorizar todos -->
          <div style="margin-bottom:15px; text-align:center; background:#fff3cd; padding:12px; border-radius:6px;">
            <button type="button" onclick="autorizarTodosDelPDF()" class="btn-warning" style="padding:10px 20px;">
              🚀 Autorizar TODOS con Teléfonos
            </button>
            <br>            <span style="margin-top:8px; display:inline-block; font-size:0.9em; color:#856404;">
              <strong>💡 Instrucciones:</strong> Ingresa teléfono (obligatorio), NSS y CURP (opcionales) de los Servicios Especializados que quieres autorizar, 
              luego haz clic en este botón para autorizarlos todos de una vez.
            </span>
          </div>
            <table border="1" cellpadding="8" style="width:100%;background:#fff; margin-top:10px;">            <tr style="background:#f8f9fa;"><th>Nombre</th><th>Teléfono</th><th>NSS</th><th>CURP</th><th>Acción</th></tr>
            <?php foreach ($nombres as $empleado_data): 
                $nombre = is_array($empleado_data) ? $empleado_data['nombre'] : $empleado_data;
                $nss_extraido = is_array($empleado_data) ? ($empleado_data['nss'] ?? '') : '';
                $curp_extraido = is_array($empleado_data) ? ($empleado_data['curp'] ?? '') : '';
                $ya_autorizado = in_array($nombre, $autorizados);
            ?>
              <tr>
                <td><?= htmlspecialchars($nombre) ?></td>
                <td>
                  <?php if ($ya_autorizado): ?>
                    <span style="color:green">Autorizado</span>
                  <?php else: ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="autorizar_nombre_pdf" value="<?= htmlspecialchars($nombre) ?>">
                      <input type="hidden" name="autorizar_fecha_pdf" value="<?= htmlspecialchars($fecha) ?>">
                      <input type="text" name="autorizar_telefono_pdf" placeholder="Teléfono" required style="width:120px;" class="telefono-input" data-nombre="<?= htmlspecialchars($nombre) ?>">
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($ya_autorizado): ?>
                    <span style="color:green">-</span>
                  <?php else: ?>
                    <input type="text" name="autorizar_nss_pdf" placeholder="NSS" value="<?= htmlspecialchars($nss_extraido) ?>" style="width:100px;" class="nss-input" data-nombre="<?= htmlspecialchars($nombre) ?>" <?= $nss_extraido ? 'readonly' : '' ?>>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($ya_autorizado): ?>
                    <span style="color:green">-</span>
                  <?php else: ?>
                    <input type="text" name="autorizar_curp_pdf" placeholder="CURP" value="<?= htmlspecialchars($curp_extraido) ?>" style="width:120px;" class="curp-input" data-nombre="<?= htmlspecialchars($nombre) ?>" <?= $curp_extraido ? 'readonly' : '' ?>>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($ya_autorizado): ?>
                    <span style="color:green">✔</span>
                  <?php else: ?>
                      <button type="submit" class="btn-success btn-small autorizar-individual">Autorizar</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
        
        <button onclick="document.getElementById('pdf-results').style.display='none'; <?php unset($_SESSION['pdf_procesado']); ?>" 
                style="margin-top:10px; background:#95a5a6; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer;">
          Ocultar Resultados
        </button>
      </div>      <?php endif; ?>
        <table border="1" cellpadding="8" style="width:100%;background:#fff;">
        <tr style="background:#f8f9fa;">
          <th>Nombre</th>
          <th>Teléfono</th>
          <th>NSS</th>
          <th>CURP</th>
          <th>Fecha</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
        <?php        $mes_actual = date('Y-m-01');
        $res_aut = $conn->query("SELECT nombre, telefono, nss, curp, fecha FROM autorizados_mes WHERE fecha >= '$mes_actual' ORDER BY nombre");
        
        // Obtener proyecto actual para verificar asignaciones
        $proyecto_actual = $conn->query("SELECT id, nombre as proyecto_nombre FROM grupos WHERE activo = 1 ORDER BY id DESC LIMIT 1");
        $proyecto = $proyecto_actual ? $proyecto_actual->fetch_assoc() : null;
        
        // Obtener empleados ya asignados a cualquier proyecto
        $empleados_en_proyectos = [];
        $res_emp_todos = $conn->query("SELECT e.nombre, g.nombre as proyecto_nombre FROM empleados e JOIN grupos g ON e.grupo_id = g.id WHERE e.activo = 1 AND g.activo = 1");
        while ($emp = $res_emp_todos->fetch_assoc()) {
            $empleados_en_proyectos[$emp['nombre']] = $emp['proyecto_nombre'];
        }
        
        if ($res_aut && $res_aut->num_rows > 0) {
          while ($row = $res_aut->fetch_assoc()) {
            $esta_asignado = isset($empleados_en_proyectos[$row['nombre']]);
            $proyecto_asignado = $esta_asignado ? $empleados_en_proyectos[$row['nombre']] : null;              echo '<tr>';
            
            echo '<td>'.htmlspecialchars($row['nombre']).'</td>';
            echo '<td>'.htmlspecialchars($row['telefono']).'</td>';
            echo '<td>'.htmlspecialchars($row['nss'] ?? '').'</td>';
            echo '<td>'.htmlspecialchars($row['curp'] ?? '').'</td>';
            echo '<td>'.htmlspecialchars($row['fecha']).'</td>';
            
            // Estado de asignación
            echo '<td>';
            if ($esta_asignado) {
                echo '<span style="color:green; font-weight:bold;">✔ Asignado</span><br>';
                echo '<small style="color:#666;">('.htmlspecialchars($proyecto_asignado).')</small>';
            } else {
                echo '<span style="color:#888;">Sin asignar</span>';
            }
            echo '</td>';
              echo '<td>';
              // Botón Editar
            echo '<button type="button" onclick="editarEmpleado(\''.htmlspecialchars($row['nombre'], ENT_QUOTES).'\', \''.htmlspecialchars($row['telefono'], ENT_QUOTES).'\', \''.htmlspecialchars($row['nss'] ?? '', ENT_QUOTES).'\', \''.htmlspecialchars($row['curp'] ?? '', ENT_QUOTES).'\', \''.htmlspecialchars($row['fecha'], ENT_QUOTES).'\')" class="btn-primary btn-mini">✏️</button> ';
            
            // Botón Asignar o Mover según el estado
            if ($esta_asignado) {
                echo '<button type="button" onclick="moverAProyecto(\''.htmlspecialchars($row['nombre'], ENT_QUOTES).'\', \''.htmlspecialchars($row['telefono'], ENT_QUOTES).'\')" class="btn-warning btn-mini" title="Mover a otro proyecto">📋</button> ';
            } else {
                echo '<button type="button" onclick="asignarAProyecto(\''.htmlspecialchars($row['nombre'], ENT_QUOTES).'\', \''.htmlspecialchars($row['telefono'], ENT_QUOTES).'\')" class="btn-success btn-mini" title="Asignar a proyecto">➕</button> ';
            }
            
            // Botón Eliminar
            echo '<button type="button" onclick="eliminarEmpleado(\''.htmlspecialchars($row['nombre'], ENT_QUOTES).'\', \''.htmlspecialchars($row['fecha'], ENT_QUOTES).'\')" class="btn-danger btn-mini">🗑️</button>';
            
            echo '</td>';
            echo '</tr>';
          }
        } else {
          echo '<tr><td colspan="7" style="text-align:center;color:#888;">No hay autorizados para este mes.</td></tr>';        }
        ?>      </table>
    
    <!-- Información del nuevo flujo de trabajo -->
    <div style="background:#e3f2fd; padding:15px; border-radius:6px; margin-top:15px; border-left:4px solid #2196f3;">
      <h4 style="color:#1976d2; margin-top:0;">💡 Nuevo Flujo de Asignación Individual</h4>
      <p style="color:#0d47a1; margin-bottom:0; font-size:0.95em;">
  <strong>✅ Ahora cada Servicio Especializado se asigna INDIVIDUALMENTE:</strong><br>
  • Los Servicios Especializados autorizados aparecen en esta tabla sin asignación automática<br>
  • Usa el botón <strong>"➕" (Asignar)</strong> para asignar Servicios Especializados uno por uno al proyecto que elijas<br>
  • Usa el botón <strong>"📋" (Mover)</strong> para cambiar Servicios Especializados ya asignados a otros proyectos<br>
  • <strong>Control total:</strong> Tú decides exactamente dónde va cada Servicio Especializado
      </p>
    </div></form>
    
    <!-- Botones de acción para la tabla -->
    <div style="margin-top:15px; display:flex; gap:10px; align-items:center;">
      <form method="post" style="display:inline;" onsubmit="return confirm('¿Estás seguro de limpiar TODA la tabla de autorizados del mes? Esta acción no se puede deshacer.')">
        <input type="hidden" name="limpiar_tabla" value="1">
        <button type="submit" class="btn-danger">🧹 Limpiar Tabla Completa</button>
      </form>
      
      <?php 
      // Contar autorizados para mostrar el total
      $count_result = $conn->query("SELECT COUNT(*) as total FROM autorizados_mes WHERE fecha >= '$mes_actual'");
      $total_autorizados = $count_result ? $count_result->fetch_assoc()['total'] : 0;
      if ($total_autorizados > 0):      ?>
        <span style="color:#666; font-size:14px;">
          Total: <?= $total_autorizados ?> Servicio(s) Especializado(s) autorizado(s)
        </span>
      <?php endif; ?>
    </div>
    
    <?php if ($proyecto): ?>
      <div style="background:#fff3cd; padding:10px; border-radius:4px; margin-top:10px; font-size:0.9em; color:#856404;">
        <strong>📋 Proyecto Actual:</strong> <?= htmlspecialchars($proyecto['proyecto_nombre']) ?><br>
  <strong>👷 Servicios Especializados Asignados:</strong> <?= count($empleados_en_proyectos) ?>
      </div>
    <?php else: ?>
      <div style="background:#f8d7da; padding:10px; border-radius:4px; margin-top:10px; font-size:0.9em; color:#721c24;">
  <strong>⚠️ No hay proyecto activo.</strong> Crea un proyecto para poder asignar Servicios Especializados.
      </div>
    <?php endif; ?>
  </div>

  <h2>Crear Nuevo Grupo</h2>
  <div class="form-section">
    <form method="post" id="grupo-form">
      <input type="hidden" name="crear" value="1">
      
      <label>Nombre del Proyecto *</label>
      <input name="nombre" required placeholder="Ej: Construcción Torre Central">
      
      <label>Empresa asociada *</label>
      <input name="empresa" required placeholder="Ej: Stone.">
        <div class="location-section">
        <label>📍 Ubicación del Proyecto (Coordenadas) *</label>
        <div class="location-input-group">
          <div>
            <label>Latitud</label>
            <input name="lat" type="number" step="any" id="lat-input" placeholder="Ej: 19.4326" required>
          </div>
          <div>
            <label>Longitud</label>
            <input name="lng" type="number" step="any" id="lng-input" placeholder="Ej: -99.1332" required>
          </div>
        </div>
        
        <div class="location-buttons">          <button type="button" class="btn-secondary" onclick="usarUbicacionActual()">📍 Usar Mi Ubicación</button>
          <button type="button" class="btn-warning" onclick="abrirMapa()">🗺️ Seleccionar en Mapa</button>
          <button type="button" class="btn-primary" onclick="verificarUbicacion()">📍 Verificar Ubicación</button>
        </div>
          <div id="coordinates-status" class="loading"></div>
        <div id="ubicacion-verificada" class="coordinates-info" style="display: none;"></div>
        <input name="localidad" type="hidden" value="Ubicación por coordenadas" id="direccion-input">
        
        <div class="coordinates-info">
          💡 <strong>Tips:</strong><br>
          • Usa "📍 Usar Mi Ubicación" para obtener las coordenadas actuales con GPS<br>
          • Usa "�️ Seleccionar en Mapa" para abrir Google Maps y obtener coordenadas precisas<br>
          • También puedes ingresar las coordenadas manualmente si las conoces<br>
          • Usa "📍 Verificar Ubicación" para confirmar la dirección de las coordenadas ingresadas
        </div>
      </div>

      <label>Nombre del Project Manager *</label>
      <input name="pm_nombre" required placeholder="Ej: Juan Pérez López">
        <label>Teléfono del Project Manager *</label>
      <input name="pm_telefono" type="tel" required placeholder="Ej: +52 55 1234 5678">

      <h3 style="color: #2c3e50; margin-top: 2em;">🏥 Contactos Adicionales (Opcional)</h3>
      <div style="background: #e8f4f8; padding: 1em; border-radius: 6px; margin: 15px 0;">
        <label>Nombre del Contacto del Seguro/Emergencias</label>
        <input name="contacto_seguro_nombre" placeholder="Ej: Dr. Juan Pérez - Seguro Social">
        
        <label>Teléfono del Contacto del Seguro/Emergencias</label>
        <input name="contacto_seguro_telefono" type="tel" placeholder="Ej: +52 55 1234 5678">
        
        <div style="font-size: 0.9em; color: #666; margin-top: 10px;">
          💡 <strong>Opcional:</strong> Puedes agregar un contacto adicional como médico de la empresa, 
          contacto del seguro, o cualquier otro número de emergencia relacionado con el proyecto.
        </div>
      </div>
      
      <div style="margin-top: 2em; text-align: center;">
        <button type="submit" class="btn-primary" style="font-size: 1.1em; padding: 15px 30px;">
          ✅ Crear Grupo de Emergencia
        </button>
      </div>
    </form>
  </div>

  <h2>Grupos Existentes (<?= count($grupos) ?>)</h2>
  <?php if (empty($grupos)): ?>
    <div class="card">
      <p style="text-align: center; color: #7f8c8d; font-style: italic;">
        No hay grupos creados aún. Crea el primer grupo usando el formulario de arriba.
      </p>
    </div>
  <?php else: ?>
    <?php foreach ($grupos as $g): ?>
      <div class="card">        <div class="card-header">
          <div>
            <h3 class="card-title"><?= htmlspecialchars($g['nombre']) ?></h3>
            <p class="card-subtitle">📍 <?= htmlspecialchars($g['localidad']) ?> | 🏢 <?= htmlspecialchars($g['empresa']) ?></p>
            <p class="card-subtitle">👨‍💼 PM: <?= htmlspecialchars($g['pm_nombre']) ?> – 📞 <?= htmlspecialchars($g['pm_telefono']) ?></p>
            <?php if (!empty($g['contacto_seguro_nombre']) && !empty($g['contacto_seguro_telefono'])): ?>
              <p class="card-subtitle">🏥 Contacto Adicional: <?= htmlspecialchars($g['contacto_seguro_nombre']) ?> – 📞 <?= htmlspecialchars($g['contacto_seguro_telefono']) ?></p>
            <?php endif; ?>
            <?php if ($g['lat'] && $g['lng']): ?>
              <p class="card-subtitle">🌍 Coordenadas: <?= number_format($g['lat'], 4) ?>, <?= number_format($g['lng'], 4) ?></p>
            <?php else: ?>
              <p class="card-subtitle" style="color: #e74c3c;">⚠️ Sin coordenadas configuradas</p>
            <?php endif; ?>
          </div>
        </div>
        
        <div class="acciones">
          <a href="../public/emergency.php?token=<?= $g['token'] ?>" target="_blank">
            <button class="btn-danger">🆘 Página de Emergencias</button>
          </a>
          <a href="editar_grupo.php?id=<?= $g['id'] ?>">
            <button class="btn-warning">✏️ Editar Grupo</button>
          </a>
          <?php if ($g['lat'] && $g['lng']): ?>
            <a href="https://maps.google.com/?q=<?= $g['lat'] ?>,<?= $g['lng'] ?>" target="_blank">
              <button class="btn-secondary">🗺️ Ver en Mapa</button>
            </a>
          <?php endif; ?>
          <a href="?eliminar=<?= $g['id'] ?>" onclick="return confirm('¿Estás seguro de eliminar este grupo? Esta acción no se puede deshacer.')">
            <button class="btn-danger">🗑️ Eliminar</button>
          </a>
        </div>
      </div>    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Modal para editar empleado -->
<div id="editModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="cerrarModal()">&times;</span>
    <h3>✏️ Editar Empleado</h3>
    <form method="post" id="editForm">
      <input type="hidden" name="editar_autorizado" value="1">
      <input type="hidden" name="nombre_original" id="nombreOriginal">
      <input type="hidden" name="fecha_original" id="fechaOriginal">
        <div class="form-group">
        <label>Nombre Completo:</label>
        <input type="text" name="nuevo_nombre" id="nuevoNombre" required>
      </div>
      
      <div class="form-group">
        <label>Teléfono:</label>
        <input type="tel" name="nuevo_telefono" id="nuevoTelefono" required>
      </div>
      
      <div class="form-group">
        <label>NSS (Número de Seguro Social):</label>
        <input type="text" name="nuevo_nss" id="nuevoNSS" placeholder="Ej: 12345678901">
      </div>
      
      <div class="form-group">
        <label>CURP:</label>
        <input type="text" name="nuevo_curp" id="nuevoCURP" placeholder="Ej: ABCD123456HDFGHI01" maxlength="18">
      </div>
      
      <div style="text-align:right; margin-top:20px;">
        <button type="button" onclick="cerrarModal()" class="btn-secondary">Cancelar</button>
        <button type="submit" class="btn-primary">Guardar Cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal para asignar empleado a proyecto específico -->
<div id="proyectoModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="cerrarModalProyecto()">&times;</span>
    <h3>📋 Asignar Empleado a Proyecto</h3>
    <form method="post" id="proyectoForm">
      <input type="hidden" name="asignar_proyecto_especifico" value="1">
      <input type="hidden" name="empleado_nombre_asignar" id="empleadoNombreAsignar">
      <input type="hidden" name="empleado_telefono_asignar" id="empleadoTelefonoAsignar">
      
      <div class="form-group">
        <label><strong>Empleado:</strong></label>
        <div id="empleadoInfo" style="background:#f8f9fa; padding:10px; border-radius:4px; margin-bottom:15px;"></div>
      </div>
      
      <div class="form-group">
        <label>Seleccionar Proyecto:</label>
        <select name="proyecto_seleccionado" id="proyectoSeleccionado" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
          <option value="">-- Seleccionar Proyecto --</option>
          <?php
          // Obtener todos los proyectos activos
          $proyectos_result = $conn->query("SELECT id, nombre, empresa FROM grupos WHERE activo = 1 ORDER BY nombre");
          if ($proyectos_result) {
              while ($proyecto_row = $proyectos_result->fetch_assoc()) {
                  echo '<option value="'.$proyecto_row['id'].'">'.htmlspecialchars($proyecto_row['nombre']).' ('.htmlspecialchars($proyecto_row['empresa']).')</option>';
              }
          }
          ?>
        </select>
      </div>
        <div class="form-group">
        <label>Acción:</label>
        <div style="margin-top:5px;">
          <label style="display:inline; margin-right:15px;">
            <input type="radio" name="accion_asignacion" value="asignar" style="margin-right:5px;">
            Asignar (mantener en proyectos actuales)
          </label>
          <label style="display:inline;">
            <input type="radio" name="accion_asignacion" value="mover" checked style="margin-right:5px;">
            Mover (cambiar de proyecto)
          </label>
        </div>
        <div style="background:#e8f5e8; padding:8px; border-radius:4px; margin-top:8px; font-size:0.9em; color:#155724;">
          <strong>💡 Recomendado:</strong> Usar "Mover" para evitar duplicados y mantener empleados en un solo proyecto activo.
        </div>
      </div>
      
      <div id="proyectoActualInfo" style="background:#fff3cd; padding:10px; border-radius:4px; margin:15px 0; font-size:0.9em; color:#856404; display:none;">
        <strong>⚠️ Proyectos actuales del empleado:</strong>
        <div id="proyectosActualesList"></div>
      </div>
      
      <div style="text-align:right; margin-top:20px;">
        <button type="button" onclick="cerrarModalProyecto()" class="btn-secondary">Cancelar</button>
        <button type="submit" class="btn-primary">Confirmar Asignación</button>
      </div>
    </form>
  </div>
</div>

<script>
// FUNCIÓN PARA USAR UBICACIÓN ACTUAL (GPS)
function usarUbicacionActual() {
  const status = document.getElementById('coordinates-status');
  
  if (!navigator.geolocation) {
    alert('Tu navegador no soporta geolocalización');
    return;
  }
  
  status.style.display = 'block';
  status.textContent = '📍 Obteniendo tu ubicación...';
  
  navigator.geolocation.getCurrentPosition(
    function(position) {
      const lat = position.coords.latitude;
      const lng = position.coords.longitude;
      
      document.getElementById('lat-input').value = lat.toFixed(6);
      document.getElementById('lng-input').value = lng.toFixed(6);
      
      status.style.display = 'none';
      alert(`✅ Ubicación obtenida: ${lat.toFixed(4)}, ${lng.toFixed(4)}`);
    },
    function(error) {
      status.style.display = 'none';
      let errorMsg = '';
      switch(error.code) {
        case error.PERMISSION_DENIED:
          errorMsg = 'Permiso de ubicación denegado. Permite el acceso a tu ubicación.';
          break;
        case error.POSITION_UNAVAILABLE:
          errorMsg = 'Información de ubicación no disponible.';
          break;
        case error.TIMEOUT:
          errorMsg = 'Tiempo de espera agotado para obtener ubicación.';
          break;
        default:
          errorMsg = 'Error desconocido al obtener ubicación.';
          break;
      }
      alert(`❌ Error: ${errorMsg}`);
    },
    {
      enableHighAccuracy: true,
      timeout: 15000,
      maximumAge: 300000
    }
  );
}

// FUNCIÓN PARA ABRIR GOOGLE MAPS
function abrirMapa() {
  const lat = document.getElementById('lat-input').value;
  const lng = document.getElementById('lng-input').value;
  
  let url = 'https://www.google.com/maps';
  
  if (lat && lng) {
    url += `/@${lat},${lng},15z`;
  } else {
    url += '/search/mexico';
  }
  
  window.open(url, '_blank');
  
  alert('🗺️ Instrucciones:\n1. Busca tu ubicación exacta en Google Maps\n2. Haz clic derecho en el lugar exacto\n3. Copia las coordenadas que aparecen\n4. Pégalas en los campos de Latitud y Longitud');
}

// FUNCIÓN PARA VERIFICAR UBICACIÓN A PARTIR DE COORDENADAS
async function verificarUbicacion() {
  const latInput = document.getElementById('lat-input');
  const lngInput = document.getElementById('lng-input');
  const status = document.getElementById('coordinates-status');
  const info = document.getElementById('ubicacion-verificada');

  const lat = parseFloat(latInput.value);
  const lng = parseFloat(lngInput.value);

  if (!lat || !lng) {
    alert('Por favor ingresa las coordenadas (latitud y longitud) antes de verificar');
    return;
  }

  // Verificar que las coordenadas sean válidas
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
    alert('Las coordenadas ingresadas no son válidas');
    return;
  }

  status.style.display = 'block';
  status.textContent = '🔍 Verificando ubicación...';
  info.style.display = 'none';

  try {
    // Usar Nominatim para geocodificación inversa
    const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1&accept-language=es`);
    const data = await response.json();
    
    if (data && data.display_name) {
      // Construir una dirección más limpia
      let direccion = '';
      const address = data.address;
      
      if (address) {
        // Construir dirección de forma inteligente
        const partes = [];
        
        if (address.road || address.street) {
          partes.push(address.road || address.street);
        }
        if (address.house_number) {
          partes[partes.length - 1] += ` ${address.house_number}`;
        }
        if (address.neighbourhood || address.suburb) {
          partes.push(address.neighbourhood || address.suburb);
        }
        if (address.city || address.town || address.village) {
          partes.push(address.city || address.town || address.village);
        }
        if (address.state) {
          partes.push(address.state);
        }
        if (address.country && address.country !== 'México') {
          partes.push(address.country);
        }
        
        direccion = partes.join(', ');
      }
      
      // Si no se pudo construir una dirección limpia, usar la completa
      if (!direccion) {
        direccion = data.display_name;
      }
      
      status.style.display = 'none';
      info.style.display = 'block';
      info.style.background = '#d4edda';
      info.style.color = '#155724';
      info.innerHTML = `✅ <strong>Ubicación verificada:</strong><br>
                       📍 <strong>Coordenadas:</strong> ${lat.toFixed(4)}, ${lng.toFixed(4)}<br>
                       🏠 <strong>Dirección:</strong> ${direccion}<br>
                       <a href="https://maps.google.com/?q=${lat},${lng}" target="_blank" style="color:#155724; text-decoration:underline;">Ver en Google Maps</a>`;
      
      // Auto-ocultar mensaje después de 15 segundos
      setTimeout(() => {
        info.style.display = 'none';
      }, 15000);
      
    } else {
      throw new Error('No se pudo encontrar una dirección para estas coordenadas');
    }
  } catch (error) {
    status.style.display = 'none';
    info.style.display = 'block';
    info.style.background = '#f8d7da';
    info.style.color = '#721c24';
    info.innerHTML = `❌ <strong>Error:</strong> ${error.message}<br>
                     Verifica que las coordenadas sean correctas o intenta nuevamente.`;
    
    // Auto-ocultar mensaje después de 10 segundos
    setTimeout(() => {
      info.style.display = 'none';
    }, 10000);
  }
}

// VALIDACIÓN DEL FORMULARIO
document.getElementById('grupo-form').addEventListener('submit', function(e) {
  const lat = document.getElementById('lat-input').value;
  const lng = document.getElementById('lng-input').value;
  
  if (!lat || !lng) {
    e.preventDefault();
    alert('⚠️ Las coordenadas son importantes para el sistema de emergencias.\n\nPor favor usa una de las opciones para obtener las coordenadas del proyecto.');
    return false;
  }
  
  // Verificar que las coordenadas estén en un rango válido para México
  const latNum = parseFloat(lat);
  const lngNum = parseFloat(lng);
  
  if (latNum < 14 || latNum > 33 || lngNum < -118 || lngNum > -86) {
    const confirm = window.confirm('⚠️ Las coordenadas parecen estar fuera de México.\n\n¿Estás seguro de que son correctas?');
    if (!confirm) {
      e.preventDefault();
      return false;
    }
  }
});

// EVENTOS ADICIONALES PARA MEJORAR LA EXPERIENCIA
// Auto-limpiar mensajes de error/éxito
document.addEventListener('DOMContentLoaded', function() {
  // Limpiar mensajes después de 10 segundos
  setTimeout(() => {
    const alerts = document.querySelectorAll('.success, .error');
    alerts.forEach(alert => {
      alert.style.display = 'none';
    });
  }, 10000);
});

// Validar coordenadas en tiempo real
document.getElementById('lat-input').addEventListener('blur', function() {
  const lat = parseFloat(this.value);
  if (this.value && (lat < -90 || lat > 90)) {
    alert('⚠️ La latitud debe estar entre -90 y 90 grados');
    this.focus();
  }
});

document.getElementById('lng-input').addEventListener('blur', function() {
  const lng = parseFloat(this.value);
  if (this.value && (lng < -180 || lng > 180)) {
    alert('⚠️ La longitud debe estar entre -180 y 180 grados');
    this.focus();
  }
});

// Funciones para el modal de edición
function editarEmpleado(nombre, telefono, nss, curp, fecha) {
  document.getElementById('nombreOriginal').value = nombre;
  document.getElementById('fechaOriginal').value = fecha;
  document.getElementById('nuevoNombre').value = nombre;
  document.getElementById('nuevoTelefono').value = telefono;
  document.getElementById('nuevoNSS').value = nss || '';
  document.getElementById('nuevoCURP').value = curp || '';
  document.getElementById('editModal').style.display = 'block';
}

function cerrarModal() {
  document.getElementById('editModal').style.display = 'none';
}

// Funciones para el modal de asignación a proyecto
function asignarAProyecto(nombre, telefono) {
  document.getElementById('empleadoNombreAsignar').value = nombre;
  document.getElementById('empleadoTelefonoAsignar').value = telefono;
  document.getElementById('empleadoInfo').innerHTML = `<strong>${nombre}</strong><br>📞 ${telefono}`;
  
  // Obtener proyectos actuales del empleado
  obtenerProyectosActuales(nombre);
  
  document.getElementById('proyectoModal').style.display = 'block';
}

function cerrarModalProyecto() {
  document.getElementById('proyectoModal').style.display = 'none';
}

async function obtenerProyectosActuales(nombre) {
  try {
    const response = await fetch('obtener_proyectos_empleado.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: `nombre=${encodeURIComponent(nombre)}`
    });
    
    if (response.ok) {
      const data = await response.json();
      const proyectosActualesDiv = document.getElementById('proyectoActualInfo');
      const proyectosListDiv = document.getElementById('proyectosActualesList');
      
      if (data.proyectos && data.proyectos.length > 0) {
        proyectosListDiv.innerHTML = data.proyectos.map(p => `• ${p.nombre} (${p.empresa})`).join('<br>');
        proyectosActualesDiv.style.display = 'block';
      } else {
        proyectosActualesDiv.style.display = 'none';
      }
    }
  } catch (error) {
    console.error('Error al obtener proyectos actuales:', error);
  }
}

// Función para autorizar todos los empleados del PDF con teléfonos
function autorizarTodosDelPDF() {
  console.log('Función autorizarTodosDelPDF ejecutada');
    // Recopilar todos los empleados que tienen teléfono ingresado
  const inputs = document.querySelectorAll('.telefono-input');
  const nssInputs = document.querySelectorAll('.nss-input');
  const curpInputs = document.querySelectorAll('.curp-input');
  const empleadosParaAutorizar = [];
  let empleadosConTelefono = 0;
  let empleadosYaAutorizados = 0;
  
  inputs.forEach((input, index) => {
    const telefono = input.value.trim();
    const nombre = input.getAttribute('data-nombre');
    const nss = nssInputs[index] ? nssInputs[index].value.trim() : '';
    const curp = curpInputs[index] ? curpInputs[index].value.trim() : '';
    
    if (telefono) {
      empleadosConTelefono++;
      empleadosParaAutorizar.push({
        nombre: nombre,
        telefono: telefono,
        nss: nss,
        curp: curp
      });
    }
  });
    // Contar empleados ya autorizados
  document.querySelectorAll('table tr').forEach(row => {
    const cells = row.querySelectorAll('td');
    if (cells.length >= 2) {
      const telefonoCell = cells[1];
      if (telefonoCell.textContent.includes('Autorizado')) {
        empleadosYaAutorizados++;
      }
    }
  });
  
  if (empleadosParaAutorizar.length === 0) {
    alert('No hay empleados con teléfonos ingresados para autorizar.\n\nPor favor ingresa al menos un número de teléfono antes de usar la autorización masiva.');
    return;
  }
  
  const confirmMessage = `¿Estás seguro de autorizar ${empleadosParaAutorizar.length} empleado(s) de una vez?\n\n` +
                        `Empleados con teléfono: ${empleadosConTelefono}\n` +
                        `Ya autorizados: ${empleadosYaAutorizados}\n\n` +
                        `Esta acción autorizará a todos los empleados que tengan número de teléfono ingresado.`;
    if (!confirm(confirmMessage)) {
    return;
  }
    // Obtener la fecha de proceso de la página
  let fechaProceso = '';
  
  // Buscar en el párrafo que contiene "Fecha de proceso:"
  const paragrafoFecha = document.querySelector('p');
  if (paragrafoFecha && paragrafoFecha.innerHTML.includes('Fecha de proceso:')) {
    const textoCompleto = paragrafoFecha.textContent;
    const match = textoCompleto.match(/Fecha de proceso:\s*([^\|]+)/);
    if (match) {
      fechaProceso = match[1].trim();
    }
  }
  
  console.log('Fecha extraída:', fechaProceso);
  
  if (!fechaProceso) {
    alert('Error: No se pudo obtener la fecha de proceso.');
    return;
  }
  
  // Crear formulario oculto para enviar los datos
  const form = document.createElement('form');
  form.method = 'post';
  form.style.display = 'none';
  
  // Campo para indicar que es autorización masiva
  const inputAction = document.createElement('input');
  inputAction.type = 'hidden';
  inputAction.name = 'autorizar_todos_pdf';
  inputAction.value = '1';
  form.appendChild(inputAction);
  
  // Campo con los datos de empleados
  const inputData = document.createElement('input');
  inputData.type = 'hidden';
  inputData.name = 'empleados_data';
  inputData.value = JSON.stringify(empleadosParaAutorizar);
  form.appendChild(inputData);
  
  // Campo con la fecha
  const inputFecha = document.createElement('input');
  inputFecha.type = 'hidden';  inputFecha.name = 'fecha_proceso';
  inputFecha.value = fechaProceso;
  form.appendChild(inputFecha);
  
  // Agregar al DOM y enviar
  document.body.appendChild(form);
  form.submit();
}

// Funciones de asignación masiva removidas - solo asignación individual

function eliminarEmpleado(nombre, fecha) {
  if (confirm(`¿Estás seguro de eliminar a ${nombre} de los autorizados?`)) {
    const form = document.createElement('form');
    form.method = 'post';
    form.innerHTML = `
      <input type="hidden" name="eliminar_autorizado" value="${nombre}">
      <input type="hidden" name="fecha_autorizado" value="${fecha}">
    `;
    document.body.appendChild(form);
    form.submit();
  }
}

function asignarAProyecto(nombre, telefono) {
  document.getElementById('empleadoNombreAsignar').value = nombre;
  document.getElementById('empleadoTelefonoAsignar').value = telefono;
  document.getElementById('empleadoInfo').innerHTML = `<strong>${nombre}</strong><br>📞 ${telefono}`;
  
  // Cambiar el título del modal
  document.querySelector('#proyectoModal h3').textContent = '➕ Asignar Empleado a Proyecto';
  
  // Para empleados no asignados, permitir tanto asignar como mover
  document.querySelector('input[name="accion_asignacion"][value="asignar"]').checked = true;
  document.querySelector('input[name="accion_asignacion"][value="asignar"]').disabled = false;
  
  // Obtener proyectos actuales del empleado (debería estar vacío)
  obtenerProyectosActuales(nombre);
  
  document.getElementById('proyectoModal').style.display = 'block';
}

function moverAProyecto(nombre, telefono) {
  document.getElementById('empleadoNombreAsignar').value = nombre;
  document.getElementById('empleadoTelefonoAsignar').value = telefono;
  document.getElementById('empleadoInfo').innerHTML = `<strong>${nombre}</strong><br>📞 ${telefono}`;
  
  // Cambiar el título del modal
  document.querySelector('#proyectoModal h3').textContent = '📋 Mover Empleado a Otro Proyecto';
  
  // Solo permitir "mover" para empleados ya asignados y deshabilitar "asignar" para evitar duplicados
  document.querySelector('input[name="accion_asignacion"][value="mover"]').checked = true;
  document.querySelector('input[name="accion_asignacion"][value="asignar"]').disabled = true;
  
  // Obtener proyectos actuales del empleado
  obtenerProyectosActuales(nombre);
    document.getElementById('proyectoModal').style.display = 'block';
}

// Event listener principal removido (solo para checkboxes de asignación masiva)
document.addEventListener('DOMContentLoaded', function() {  
  // Auto-limpiar mensajes después de 10 segundos
  setTimeout(() => {
    const alerts = document.querySelectorAll('.success, .error');
    alerts.forEach(alert => {
      alert.style.display = 'none';
    });
  }, 10000);
});

// Función para scroll suave a secciones
function scrollToSection(sectionId) {
  const element = document.getElementById(sectionId);
  if (element) {
    element.scrollIntoView({ 
      behavior: 'smooth',
      block: 'start' 
    });
  }
}

// Cerrar modal al hacer clic fuera de él
window.onclick = function(event) {
  const editModal = document.getElementById('editModal');
  const proyectoModal = document.getElementById('proyectoModal');
  
  if (event.target == editModal) {
    cerrarModal();
  }
  
  if (event.target == proyectoModal) {
    cerrarModalProyecto();
  }
}
</script>
</body>
</html>