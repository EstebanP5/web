<?php
// Zona horaria local para México (CDMX)
date_default_timezone_set('America/Mexico_City');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Configuración de base de datos
require_once __DIR__ . '/../includes/db.php';

if (!function_exists('map_tipo_subcarpeta')) {
    function map_tipo_subcarpeta(string $tipo): string {
        $slug = strtolower(trim($tipo));
        $slug = str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $slug);

        if ($slug === '') {
            return 'otros';
        }

        $startsWith = function(string $haystack, string $needle): bool {
            return strpos($haystack, $needle) === 0;
        };

        $contains = function(string $haystack, string $needle): bool {
            return strpos($haystack, $needle) !== false;
        };

        if ($startsWith($slug, 'entra') || $contains($slug, 'ingres') || $contains($slug, 'reingres') || $contains($slug, 'abr')) {
            return 'entradas';
        }

        if ($startsWith($slug, 'salid') || $contains($slug, 'termin') || $contains($slug, 'cerr')) {
            return 'salidas';
        }

        if ($contains($slug, 'descans') || $contains($slug, 'break') || $contains($slug, 'paus') || $contains($slug, 'comid') || $contains($slug, 'reces')) {
            return 'descansos';
        }

        if ($contains($slug, 'reanuda') || $contains($slug, 'reanudo') || $contains($slug, 'regres') || $contains($slug, 'retorn')) {
            return 'reanudar';
        }

        return preg_replace('/[^a-z0-9_-]+/', '-', $slug);
    }
}

// Alinear zona horaria de la sesión MySQL con la de PHP (usa el offset actual -06:00/-05:00)
$mysql_offset = date('P');
@$conn->query("SET time_zone='".$conn->real_escape_string($mysql_offset)."'");

// Headers para respuesta JSON
header('Content-Type: application/json');

// Verificar que es una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    // Validar parámetros requeridos
    $servicio_especializado_id = intval($_POST['servicio_especializado_id'] ?? ($_POST['empleado_id'] ?? 0));
    $grupo_id = intval($_POST['grupo_id'] ?? 0);
    $lat = floatval($_POST['lat'] ?? 0);
    $lng = floatval($_POST['lng'] ?? 0);
    $direccion = trim($_POST['direccion'] ?? '');
    $tipo_asistencia = trim($_POST['tipo_asistencia'] ?? '');
    // Normalizar tipo (asegurar separación por Entrada/Salida/Descanso/Reanudar)
    $tipo_l = strtolower($tipo_asistencia);
    if($tipo_l==='reabrir'){ $tipo_asistencia='Entrada'; }
    else if($tipo_l==='entrada'){ $tipo_asistencia='Entrada'; }
    else if($tipo_l==='salida'){ $tipo_asistencia='Salida'; }
    else if($tipo_l==='descanso'){ $tipo_asistencia='Descanso'; }
    else if($tipo_l==='reanudar'){ $tipo_asistencia='Reanudar'; }
    else { $tipo_asistencia=ucfirst($tipo_l); }
    $motivo = trim($_POST['motivo'] ?? '');

    $dedupeKey = null;
    $dedupeNow = time();
    $dedupeWindow = 30; // segundos para ignorar duplicados inmediatos
    if (in_array($tipo_asistencia, ['Descanso', 'Reanudar'], true)) {
        if (!isset($_SESSION['ultima_foto_asistencia'])) {
            $_SESSION['ultima_foto_asistencia'] = [];
        }
        $dedupeKey = $servicio_especializado_id . '|' . $tipo_asistencia;
        $lastTs = $_SESSION['ultima_foto_asistencia'][$dedupeKey] ?? 0;
        if ($dedupeNow - $lastTs < $dedupeWindow) {
            echo json_encode([
                'success' => true,
                'message' => 'Foto de descanso reutilizada (registro reciente).',
                'data' => [
                    'duplicate' => true,
                    'tipo_asistencia' => $tipo_asistencia,
                    'registrada_en' => $lastTs
                ]
            ]);
            exit;
        }
    }
    $subcarpeta_tipo = map_tipo_subcarpeta($tipo_asistencia);

    if (!$servicio_especializado_id || !$lat || !$lng || !$tipo_asistencia) {
        throw new Exception('Faltan parámetros requeridos: servicio_especializado_id, lat, lng, tipo_asistencia');
    }

    // Validar rango de coordenadas
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        throw new Exception('Coordenadas GPS inválidas');
    }

    // Verificar que se subió una foto
    if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se recibió la foto o hubo un error en la subida');
    }

    $foto_file = $_FILES['foto'];
    
    // Validar tipo de archivo
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
    if (!in_array($foto_file['type'], $allowed_types)) {
        throw new Exception('Tipo de archivo no permitido. Solo JPG y PNG');
    }

    // Validar tamaño (máximo 10MB)
    if ($foto_file['size'] > 10 * 1024 * 1024) {
        throw new Exception('El archivo es demasiado grande. Máximo 10MB');
    }

    // Obtener datos del Servicio Especializado desde empleados o users
    $servicio_especializado = null;
    $stmt = $conn->prepare("SELECT nombre, telefono FROM empleados WHERE id = ?");
    $stmt->bind_param("i", $servicio_especializado_id);
    $stmt->execute();
    $servicio_especializado = $stmt->get_result()->fetch_assoc();
    if (!$servicio_especializado) {
        $stmt = $conn->prepare("SELECT name AS nombre, '' AS telefono FROM users WHERE id = ?");
        $stmt->bind_param("i", $servicio_especializado_id);
        $stmt->execute();
        $servicio_especializado = $stmt->get_result()->fetch_assoc();
    }
    if (!$servicio_especializado) { throw new Exception('Servicio Especializado no encontrado'); }

    // Directorio de salida
    $fecha_carpeta = date('Y-m-d');
    if ($grupo_id > 0) {
        $upload_dir = "../admin/uploads/asistencias/{$grupo_id}/{$fecha_carpeta}/{$subcarpeta_tipo}/";
        $db_rel_dir = "uploads/asistencias/{$grupo_id}/{$fecha_carpeta}/{$subcarpeta_tipo}/";
    } else {
        $upload_dir = '../uploads/fotos_asistencia/' . $subcarpeta_tipo . '/';
        $db_rel_dir = 'uploads/fotos_asistencia/' . $subcarpeta_tipo . '/';
    }
    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }

    // Generar nombres únicos para archivos
    $timestamp = time();
    $fecha_hora = date('Y-m-d H:i:s');
    $fecha_archivo = date('Y-m-d_H-i-s');
    
    $nombre_original = "asistencia_original_{$servicio_especializado_id}_{$timestamp}.jpg";
    $nombre_procesada = "asistencia_procesada_{$servicio_especializado_id}_{$timestamp}.jpg";
    $ruta_original = $upload_dir . $nombre_original;
    $ruta_procesada = $upload_dir . $nombre_procesada;
    $ruta_procesada_db = $db_rel_dir . $nombre_procesada;

    // Cargar imagen original
    $imagen_original = null;
    switch ($foto_file['type']) {
        case 'image/jpeg':
        case 'image/jpg':
            $imagen_original = imagecreatefromjpeg($foto_file['tmp_name']);
            break;
        case 'image/png':
            $imagen_original = imagecreatefrompng($foto_file['tmp_name']);
            break;
    }

    if (!$imagen_original) {
        throw new Exception('No se pudo procesar la imagen');
    }

    // Guardar imagen original
    imagejpeg($imagen_original, $ruta_original, 90);

    // Crear copia para procesar
    $ancho_orig = imagesx($imagen_original);
    $alto_orig = imagesy($imagen_original);
    $imagen_procesada = imagecreatetruecolor($ancho_orig, $alto_orig);
    imagecopy($imagen_procesada, $imagen_original, 0, 0, 0, 0, $ancho_orig, $alto_orig);

    // === DESCARGAR MAPA DE STATICMAPLITE (opcional, con tolerancia a fallos) ===
    $zoom = 16;
    $mapa_ancho = 300;
    $mapa_alto = 200;
    
    // URL del mapa usando nuestro StaticMapLite
    $mapa_url = "http://localhost/web/staticmap.php?center={$lat},{$lng}&zoom={$zoom}&size={$mapa_ancho}x{$mapa_alto}&markers={$lat},{$lng},red&maptype=mapnik";
    
    // Descargar el mapa (si falla, continuamos sin mapa)
    $imagen_mapa = null;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $mapa_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $mapa_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($http_code === 200 && $mapa_data) {
        $tmp_img = @imagecreatefromstring($mapa_data);
        if ($tmp_img !== false) {
            $imagen_mapa = $tmp_img;
        } else {
            error_log('procesar_foto_asistencia: no se pudo crear imagen del mapa');
        }
    } else {
        error_log('procesar_foto_asistencia: no se pudo descargar el mapa (' . $http_code . ') ' . $curl_err);
    }

    // === COMPOSICIÓN FINAL ===
    
    if ($imagen_mapa) {
        // Posicionar mapa en esquina inferior derecha con margen
        $margen = 20;
        $mapa_x = $ancho_orig - $mapa_ancho - $margen;
        $mapa_y = $alto_orig - $mapa_alto - $margen;
        
        // Si la imagen es muy pequeña, ajustar el mapa
        if ($mapa_x < 0 || $mapa_y < 0) {
            $mapa_ancho = max(50, min($mapa_ancho, $ancho_orig - $margen * 2));
            $mapa_alto = max(50, min($mapa_alto, $alto_orig - $margen * 2));
            $mapa_x = $ancho_orig - $mapa_ancho - $margen;
            $mapa_y = $alto_orig - $mapa_alto - $margen;
            
            // Redimensionar mapa si es necesario
            $mapa_redimensionado = imagecreatetruecolor($mapa_ancho, $mapa_alto);
            imagecopyresampled($mapa_redimensionado, $imagen_mapa, 0, 0, 0, 0, 
                              $mapa_ancho, $mapa_alto, imagesx($imagen_mapa), imagesy($imagen_mapa));
            imagedestroy($imagen_mapa);
            $imagen_mapa = $mapa_redimensionado;
        }

        // Crear fondo semitransparente para el mapa
        $fondo_mapa = imagecolorallocatealpha($imagen_procesada, 0, 0, 0, 30);
        imagefilledrectangle($imagen_procesada, $mapa_x - 5, $mapa_y - 5, 
                            $mapa_x + $mapa_ancho + 5, $mapa_y + $mapa_alto + 5, $fondo_mapa);

        // Copiar mapa sobre la foto
        imagecopy($imagen_procesada, $imagen_mapa, $mapa_x, $mapa_y, 0, 0, 
                  imagesx($imagen_mapa), imagesy($imagen_mapa));
    }

    // === AGREGAR INFORMACIÓN DE TEXTO ===
    
    // Colores para texto
    $color_texto = imagecolorallocate($imagen_procesada, 255, 255, 255);
    $color_sombra = imagecolorallocate($imagen_procesada, 0, 0, 0);
    $color_fondo = imagecolorallocatealpha($imagen_procesada, 0, 0, 0, 60);
    
    // Preparar textos
    $texto_fecha = "Fecha: " . date('d/m/Y H:i:s');
    $texto_servicio_especializado = "Servicio Especializado: " . $servicio_especializado['nombre'];
    $texto_gps = "GPS: {$lat}, {$lng}";
    $texto_direccion = $direccion ? "Ubicación: " . substr($direccion, 0, 50) : "";
    
    // Usar fuente del sistema (builtin) o intentar cargar Arial
    $fuente_path = __DIR__ . '/../assets/arial.ttf';
    $usar_ttf = file_exists($fuente_path);
    
    $textos = [$texto_fecha, $texto_servicio_especializado, $texto_gps];
    if ($texto_direccion) $textos[] = $texto_direccion;
    
    // Calcular área necesaria para texto
    $alto_texto = count($textos) * 25 + 20;
    $ancho_texto = $ancho_orig - 40;
    
    // Crear fondo semitransparente para texto
    imagefilledrectangle($imagen_procesada, 20, 20, 20 + $ancho_texto, 20 + $alto_texto, $color_fondo);
    
    // Escribir textos
    $y_texto = 35;
    foreach ($textos as $texto) {
        // Sombra
        if ($usar_ttf) {
            imagettftext($imagen_procesada, 12, 0, 26, $y_texto + 1, $color_sombra, $fuente_path, $texto);
            imagettftext($imagen_procesada, 12, 0, 25, $y_texto, $color_texto, $fuente_path, $texto);
        } else {
            imagestring($imagen_procesada, 4, 26, $y_texto - 14, $texto, $color_sombra);
            imagestring($imagen_procesada, 4, 25, $y_texto - 15, $texto, $color_texto);
        }
        $y_texto += 25;
    }

    // Guardar imagen procesada
    imagejpeg($imagen_procesada, $ruta_procesada, 90);

    // === GUARDAR EN BASE DE DATOS ===
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $asistencia_id = 0; 
    $db_errs = [];

    // Detectar columnas disponibles en fotos_asistencia
    $cols = [];
    if ($resCols = $conn->query("SHOW COLUMNS FROM fotos_asistencia")) {
        while ($r = $resCols->fetch_assoc()) { $cols[$r['Field']] = true; }
        $resCols->free();
    }

    // Construir INSERT dinámico según columnas disponibles
    $fields = [];
    $placeholders = [];
    $types = '';
    $values = [];

    $add = function($name, $type, $value) use (&$fields, &$placeholders, &$types, &$values, $cols) {
        if (isset($cols[$name])) { $fields[] = $name; $placeholders[] = '?'; $types .= $type; $values[] = $value; }
    };

    // Campos comunes opcionales
    $tel = $servicio_especializado['telefono'] ?? '';
    $db_ruta_original = ($grupo_id > 0 ? $db_rel_dir : $db_rel_dir) . $nombre_original; // siempre relativo a 'uploads/...'

    // Preferir nombres de columnas modernas si existen; si no, variantes antiguas
    $add('grupo_id', 'i', $grupo_id);
    $add('empleado_id', 'i', $servicio_especializado_id);
    $add('empleado_nombre', 's', $servicio_especializado['nombre']);
    $add('empleado_telefono', 's', $tel);
    if (isset($cols['foto_procesada'])) {
        $add('foto_procesada', 's', $ruta_procesada_db);
    } elseif (isset($cols['archivo_procesado'])) {
        $add('archivo_procesado', 's', $nombre_procesada);
    }
    if (isset($cols['latitud']) || isset($cols['lat'])) {
        if (isset($cols['latitud'])) $add('latitud', 'd', $lat); else $add('lat', 'd', $lat);
    }
    if (isset($cols['longitud']) || isset($cols['lng'])) {
        if (isset($cols['longitud'])) $add('longitud', 'd', $lng); else $add('lng', 'd', $lng);
    }
    if (isset($cols['direccion_aproximada']) || isset($cols['direccion'])) {
        if (isset($cols['direccion_aproximada'])) $add('direccion_aproximada', 's', $direccion); else $add('direccion', 's', $direccion);
    }
    $add('tipo_asistencia', 's', $tipo_asistencia);
    $add('motivo', 's', $motivo);
    $add('ip_address', 's', $ip_address);
    // fecha_hora si existe; si no, la tabla podría tener DEFAULT CURRENT_TIMESTAMP
    $add('fecha_hora', 's', $fecha_hora);
    // archivo_original si existe
    if (isset($cols['archivo_original'])) { $add('archivo_original', 's', $db_ruta_original); }

    if (empty($fields)) {
        throw new Exception('La tabla fotos_asistencia no tiene columnas compatibles para insertar');
    }

    $sql = 'INSERT INTO fotos_asistencia (' . implode(',', $fields) . ') VALUES (' . implode(',', $placeholders) . ')';
    $stmt = $conn->prepare($sql);
    if (!$stmt) { throw new Exception('Error preparando inserción: ' . $conn->error); }

    // bind_param requiere variables referenciables
    $bindValues = $values;
    $stmt->bind_param($types, ...$bindValues);
    if (!$stmt->execute()) {
        throw new Exception('Error al guardar en base de datos: ' . $stmt->error);
    }
    $asistencia_id = $stmt->insert_id;

    // Limpiar memoria
    imagedestroy($imagen_original);
    imagedestroy($imagen_procesada);
    if (isset($imagen_mapa) && $imagen_mapa) imagedestroy($imagen_mapa);

    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'message' => 'Foto de asistencia registrada exitosamente',
        'data' => [
            'id' => $asistencia_id,
            'servicio_especializado' => $servicio_especializado['nombre'],
            'fecha_hora' => $fecha_hora,
            'archivo_procesado' => $nombre_procesada,
            'coordenadas' => "{$lat}, {$lng}",
            'tipo_asistencia' => $tipo_asistencia,
            'motivo' => $motivo
        ]
    ]);

    if ($dedupeKey !== null) {
        $_SESSION['ultima_foto_asistencia'][$dedupeKey] = $dedupeNow;
    }

} catch (Exception $e) {
    // Log del error
    error_log("Error en procesar_foto_asistencia.php: " . $e->getMessage());
    
    // Limpiar recursos si existen
    if (isset($imagen_original)) imagedestroy($imagen_original);
    if (isset($imagen_procesada)) imagedestroy($imagen_procesada);
    if (isset($imagen_mapa)) imagedestroy($imagen_mapa);
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>
