<?php
session_start();
// Asegurar huso horario local (CDMX)
date_default_timezone_set('America/Mexico_City');
// Conversión forzada desde UTC (desactivada)
$FORZAR_CONVERTIR_UTC = false;
require_once __DIR__ . '/../includes/db.php';
// Alinear zona horaria de la sesión MySQL con el offset local actual (-06:00 / -05:00)
$mysql_offset = date('P');
@$conn->query("SET time_zone = '".$conn->real_escape_string($mysql_offset)."'");
// Quitar ajuste manual (ya no restar 8h)
$AJUSTE_HORAS_DISPLAY = 0;

function normalize_tipo_slug(?string $tipo): string {
    $valor = trim((string)$tipo);
    if ($valor === '') {
        return 'otro';
    }

    if (function_exists('mb_strtolower')) {
        $slug = mb_strtolower($valor, 'UTF-8');
    } else {
        $slug = strtolower($valor);
    }

    $slug = str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $slug);

    if (strpos($slug, 'entrada') !== false || strpos($slug, 'ingres') !== false || strpos($slug, 'reingres') !== false || strpos($slug, 'abr') !== false || strpos($slug, 'inicio') !== false || strpos($slug, 'apert') !== false) {
        return 'entrada';
    }

    if (strpos($slug, 'salida') !== false || strpos($slug, 'final') !== false || strpos($slug, 'termin') !== false || strpos($slug, 'cerr') !== false || strpos($slug, 'reti') !== false) {
        return 'salida';
    }

    if (strpos($slug, 'descans') !== false || strpos($slug, 'break') !== false || strpos($slug, 'paus') !== false || strpos($slug, 'comid') !== false || strpos($slug, 'reces') !== false) {
        return 'descanso';
    }

    if (strpos($slug, 'reanuda') !== false || strpos($slug, 'reanudo') !== false || strpos($slug, 'regres') !== false || strpos($slug, 'retorn') !== false) {
        return 'reanudar';
    }

    return 'otro';
}

function slug_to_folder(string $slug): string {
    switch ($slug) {
        case 'entrada':
            return 'entradas';
        case 'salida':
            return 'salidas';
        case 'descanso':
            return 'descansos';
        case 'reanudar':
            return 'reanudar';
        default:
            return 'otros';
    }
}

function slug_to_label(string $slug, string $fallback = ''): string {
    switch ($slug) {
        case 'entrada':
            return 'Entrada';
        case 'salida':
            return 'Salida';
        case 'descanso':
            return 'Descanso';
        case 'reanudar':
            return 'Reanudar descanso';
        default:
            return $fallback !== '' ? $fallback : 'Evento sin tipo';
    }
}

function folder_slug_from_path(string $path): string {
    $clean = str_replace('\\', '/', $path);
    // eliminar ../ repetidos para evitar falsos positivos
    while (strpos($clean, '../') === 0) {
        $clean = substr($clean, 3);
    }
    $segments = explode('/', trim($clean, '/'));
    if (count($segments) < 2) {
        return '';
    }
    // recorrer directorios desde el final (antes del archivo) buscando nombres conocidos
    for ($i = count($segments) - 2; $i >= 0; $i--) {
        $candidate = strtolower($segments[$i]);
        switch ($candidate) {
            case 'entradas':
                return 'entrada';
            case 'salidas':
                return 'salida';
            case 'descansos':
                return 'descanso';
            case 'reanudar':
                return 'reanudar';
        }
    }
    return '';
}

function map_tipo_subcarpeta(string $tipo): string {
    return slug_to_folder(normalize_tipo_slug($tipo));
}

// API ligero para AJAX
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $api = $_GET['api'];
    if ($api === 'employees') {
        $proyecto_id = (int)($_GET['proyecto_id'] ?? 0);
        $stmt = $conn->prepare("SELECT e.id, e.nombre FROM empleado_proyecto ep JOIN empleados e ON e.id = ep.empleado_id WHERE ep.proyecto_id = ? AND ep.activo=1 AND e.activo=1 ORDER BY e.nombre");
        $stmt->bind_param('i', $proyecto_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success'=>true, 'data'=>$rows]);
        exit;
    }
    if ($api === 'photos') {
        $proyecto_id = (int)($_GET['proyecto_id'] ?? 0);
    $tipo = trim($_GET['tipo'] ?? ''); // Entrada | Salida | Descanso
    $tipoSlugFiltro = $tipo !== '' ? normalize_tipo_slug($tipo) : '';
        $empleado_id = (int)($_GET['empleado_id'] ?? 0);
        $start_date = trim($_GET['start_date'] ?? '');
        $end_date   = trim($_GET['end_date'] ?? '');
        if($start_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$start_date)) $start_date='';
        if($end_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$end_date)) $end_date='';

        // Descubrir columnas disponibles en fotos_asistencia
        $cols = [];
        $resCols = $conn->query("SHOW COLUMNS FROM fotos_asistencia");
        if ($resCols) {
            while ($c = $resCols->fetch_assoc()) { $cols[$c['Field']] = true; }
        }

        // Columnas dinámicas
        $colGrupo = isset($cols['grupo_id']) ? 'grupo_id' : (isset($cols['proyecto_id']) ? 'proyecto_id' : null);
        if (!$colGrupo) { echo json_encode(['success'=>false,'error'=>'Tabla fotos_asistencia sin columna grupo/proyecto']); exit; }
        $colTipo = isset($cols['tipo_asistencia']) ? 'tipo_asistencia' : null;
        $colEmpleadoId = isset($cols['empleado_id']) ? 'empleado_id' : null;
        $colEmpleadoNombre = isset($cols['empleado_nombre']) ? 'empleado_nombre' : null;
        $colFecha = isset($cols['fecha_hora']) ? 'fecha_hora' : (isset($cols['created_at'])? 'created_at' : (isset($cols['fecha'])? 'fecha' : (isset($cols['timestamp'])? 'timestamp' : null)));
        $colId = isset($cols['id'])? 'id' : null;

        $params=[]; $types='';
        $sql = "SELECT fa.* , g.nombre as proyecto_nombre, g.empresa FROM fotos_asistencia fa JOIN grupos g ON fa.".$colGrupo." = g.id WHERE fa.".$colGrupo." = ?";
        $params[] = $proyecto_id; $types.='i';

        if ($empleado_id) {
            $conds = [];
            if ($colEmpleadoId) { $conds[] = "fa.".$colEmpleadoId." = ?"; $params[]=$empleado_id; $types.='i'; }
            if ($colEmpleadoNombre) { $conds[] = "fa.".$colEmpleadoNombre." IN (SELECT nombre FROM empleados WHERE id = ?)"; $params[]=$empleado_id; $types.='i'; }
            if (!empty($conds)) { $sql .= " AND (".implode(' OR ', $conds).")"; }
        }

        // Filtro por rango de fechas (solo si la tabla tiene columna de fecha)
        if($colFecha && $start_date && $end_date){
            $sql .= " AND DATE(fa.".$colFecha.") BETWEEN ? AND ?";
            $params[]=$start_date; $params[]=$end_date; $types.='ss';
        } elseif($colFecha && $start_date){
            $sql .= " AND DATE(fa.".$colFecha.") >= ?"; $params[]=$start_date; $types.='s';
        } elseif($colFecha && $end_date){
            $sql .= " AND DATE(fa.".$colFecha.") <= ?"; $params[]=$end_date; $types.='s';
        }

        if ($colFecha) { $sql .= " ORDER BY fa.".$colFecha." DESC"; }
        elseif ($colId) { $sql .= " ORDER BY fa.".$colId." DESC"; }
        $sql .= " LIMIT 500";

        $stmt = $conn->prepare($sql);
        if (!$stmt) { echo json_encode(['success'=>false,'error'=>'SQL inválido','debug'=>$conn->error,'sql'=>$sql]); exit; }
        if ($params) {
            // bind_param requiere referencias; construimos arreglo por referencia
            $bind = [];
            $bind[] = $types;
            foreach ($params as $k => $v) { $bind[$k+1] = &$params[$k]; }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        if (!$stmt->execute()) { echo json_encode(['success'=>false,'error'=>'Fallo al ejecutar','debug'=>$stmt->error]); exit; }
        $res = $stmt->get_result();
        $out=[];
    while ($r = $res->fetch_assoc()) {
        $tipoValor = $colTipo ? ($r[$colTipo] ?? '') : '';
        $tipoFolder = $tipoValor !== '' ? map_tipo_subcarpeta($tipoValor) : '';
            // Normalización avanzada de rutas de imagen para evitar 404
            $raw = '';
            if (!empty($r['foto_procesada'])) {
                $raw = $r['foto_procesada'];
            } elseif (!empty($r['archivo_procesado'])) {
                $raw = $r['archivo_procesado'];
            }

            $raw = str_replace('..', '', $raw);
            $raw = ltrim($raw, '/');
            if (strpos($raw, 'admin/uploads/asistencias/') === 0) {
                $raw = substr($raw, strlen('admin/'));
            }

            $urlImg = $raw;
            if (strpos($raw, 'uploads/fotos_asistencia/') === 0) {
                $urlImg = '../' . $raw;
            }

            $fechaRef = $colFecha && !empty($r[$colFecha]) ? substr($r[$colFecha], 0, 10) : date('Y-m-d');
            $grupoRef = $r[$colGrupo] ?? $proyecto_id;

            $fsBaseAdmin = __DIR__ . DIRECTORY_SEPARATOR;
            $fsBaseRoot  = dirname(__DIR__) . DIRECTORY_SEPARATOR;
            $finalUrl = '';
            $candidatos = [];

            $addCandidato = function(string $ruta) use (&$candidatos) {
                if ($ruta === '') { return; }
                if (!in_array($ruta, $candidatos, true)) { $candidatos[] = $ruta; }
            };

            $addCandidato($urlImg);
            if ($raw !== '' && $raw !== $urlImg) { $addCandidato($raw); }

            if ($urlImg && strpos($urlImg, 'uploads/asistencias/') === 0) {
                $addCandidato('admin/' . $urlImg);
            }
            if ($raw && strpos($raw, 'uploads/asistencias/') === 0) {
                $addCandidato('admin/' . $raw);
            }

            if ($raw && strpos($raw, '/') === false) {
                $baseRuta = 'uploads/asistencias/' . $grupoRef . '/' . $fechaRef . '/';
                $folderes = [];
                if ($tipoFolder !== '') { $folderes[] = $tipoFolder; }
                $folderes = array_merge($folderes, ['entradas','salidas','descansos','reanudar','otros']);
                $folderes = array_unique($folderes);
                foreach ($folderes as $folderNombre) {
                    $addCandidato($baseRuta . $folderNombre . '/' . $raw);
                    $addCandidato('admin/' . $baseRuta . $folderNombre . '/' . $raw);
                }
                $addCandidato($baseRuta . $raw);
                $addCandidato('admin/' . $baseRuta . $raw);
            }

            foreach ($candidatos as $rel) {
                $relNorm = str_replace('\\', '/', $rel);
                if (strpos($relNorm, '../') === 0) {
                    $relTrim = substr($relNorm, 3);
                    if ($relTrim !== '' && file_exists($fsBaseRoot . str_replace('/', DIRECTORY_SEPARATOR, $relTrim))) {
                        $finalUrl = '../' . ltrim($relTrim, '/');
                        break;
                    }
                    continue;
                }
                $relPath = str_replace('/', DIRECTORY_SEPARATOR, $relNorm);
                if (file_exists($fsBaseAdmin . $relPath)) {
                    $finalUrl = $relNorm;
                    break;
                }
                if (file_exists($fsBaseRoot . $relPath)) {
                    $finalUrl = '../' . $relNorm;
                    break;
                }
            }

            if (!$finalUrl) {
                $finalUrl = $urlImg;
            }

            $folderSlug = $finalUrl ? folder_slug_from_path($finalUrl) : '';
            $tipoSlugNormalizado = normalize_tipo_slug($tipoValor);
            if ($folderSlug !== '' && ($tipoSlugNormalizado === 'otro' || $tipoSlugNormalizado === '')) {
                $tipoSlugNormalizado = $folderSlug;
            }
            if ($folderSlug !== '') {
                $tipoFolder = slug_to_folder($folderSlug);
            }
            $tipoLabel = slug_to_label($tipoSlugNormalizado, $tipoValor);

            if ($tipoSlugFiltro !== '' && $tipoSlugNormalizado !== $tipoSlugFiltro) {
                continue;
            }

            $lat = isset($r['latitud']) ? $r['latitud'] : (isset($r['lat']) ? $r['lat'] : null);
            $lng = isset($r['longitud']) ? $r['longitud'] : (isset($r['lng']) ? $r['lng'] : null);
            $dir = $r['direccion_aproximada'] ?? ($r['direccion'] ?? '');
            $rawFecha = $colFecha ? ($r[$colFecha] ?? '') : '';
            $fechaLocal = $rawFecha;
            if ($rawFecha) {
                try {
                    $dt = new DateTime($rawFecha);
                    if (isset($AJUSTE_HORAS_DISPLAY) && $AJUSTE_HORAS_DISPLAY != 0) {
                        $dt->modify(($AJUSTE_HORAS_DISPLAY>0?'+':'').$AJUSTE_HORAS_DISPLAY.' hour');
                    }
                    $fechaLocal = $dt->format('d/m/Y h:i:s a');
                } catch (Exception $e) {}
            }
            $out[] = [
                'img'=>$finalUrl,
                'empleado'=>$r['empleado_nombre'] ?? '',
                'tipo'=>$tipoLabel,
                'tipo_slug'=>$tipoSlugNormalizado,
                'tipo_original'=>$tipoValor,
                'motivo'=>$r['motivo'] ?? '',
                'fecha_hora'=>$rawFecha,
                'fecha_local'=>$fechaLocal,
                'lat'=>$lat,
                'lng'=>$lng,
                'direccion'=>$dir
            ];
        }
        echo json_encode(['success'=>true,'data'=>$out]);
        exit;
    }
    echo json_encode(['success'=>false,'error'=>'API no válida']);
    exit;
}

// Obtener proyectos activos
$proyectos_result = $conn->query("SELECT id, nombre, empresa FROM grupos WHERE activo = 1 ORDER BY nombre");
$proyectos = [];
while ($proyecto = $proyectos_result->fetch_assoc()) {
    $proyectos[] = $proyecto;
}

// Procesar formulario de foto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['foto_data'])) {
    $grupo_id = intval($_POST['grupo_id']);
    $empleado_nombre = trim($_POST['empleado_nombre']);
    $empleado_telefono = trim($_POST['empleado_telefono']);
    $latitud = floatval($_POST['latitud']);
    $longitud = floatval($_POST['longitud']);
    $direccion = trim($_POST['direccion'] ?? '');
    $tipo_asistencia = trim($_POST['tipo_asistencia'] ?? '');
    $subcarpeta_tipo = map_tipo_subcarpeta($tipo_asistencia);
    $motivo = trim($_POST['motivo'] ?? '');
    $foto_data = $_POST['foto_data'];
    $fecha_actual = date('Y-m-d');

    if (!$grupo_id || !$empleado_nombre || !$foto_data || !$tipo_asistencia) {
        $mensaje_error = "Faltan datos obligatorios para registrar la asistencia.";
    } else {
        // Crear subcarpeta por proyecto y día
    $upload_dir = "uploads/asistencias/{$grupo_id}/{$fecha_actual}/{$subcarpeta_tipo}/";
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        try {
            // Procesar imagen base64
            $foto_data = preg_replace('/^data:image\/(png|jpeg);base64,/', '', $foto_data);
            $foto_data = str_replace(' ', '+', $foto_data);
            $foto_binaria = base64_decode($foto_data);
            if (!$foto_binaria) throw new Exception("Error al decodificar la imagen");
            $imagen_base = imagecreatefromstring($foto_binaria);
            if (!$imagen_base) throw new Exception("No se pudo procesar la imagen");
            $ancho_imagen = imagesx($imagen_base);
            $alto_imagen  = imagesy($imagen_base);

            // --- Overlay discreto ---
            $overlay_altura = 80; // Aumenta la altura para dos líneas
            $overlay = imagecreatetruecolor($ancho_imagen, $overlay_altura);
            // Fondo negro semitransparente (60% opacidad)
            $fondo_negro = imagecolorallocatealpha($overlay, 0, 0, 0, 80); // 0=opaco, 127=transparente
            imagefill($overlay, 0, 0, $fondo_negro);
            imagesavealpha($overlay, true);
            // Colores
            $texto_blanco = imagecolorallocate($overlay, 255, 255, 255);
            $texto_amarillo = imagecolorallocate($overlay, 255, 255, 0);
            $texto_cyan = imagecolorallocate($overlay, 0, 255, 255);
            $texto_verde = imagecolorallocate($overlay, 0, 255, 0);
            // Texto compacto
            $fecha = date("d/m/Y");
            $hora = date("H:i:s");
            $empleado = strtoupper($empleado_nombre);
            $gps = ($latitud && $longitud) ? number_format($latitud, 4) . ", " . number_format($longitud, 4) : "";
            // Construir línea
            $info = "FECHA: $fecha   HORA: $hora   EMPLEADO: $empleado";
            if ($gps) $info .= "   GPS: $gps";
            // Escribir texto (fuente 3, más pequeño)
            imagestring($overlay, 3, 15, 15, $info, $texto_blanco);
            // Segunda línea: dirección (si existe)
            if ($direccion) {
                $direccion_overlay = mb_strimwidth($direccion, 0, 90, '...'); // Limita largo
                imagestring($overlay, 2, 15, 45, "DIRECCION: $direccion_overlay", $texto_cyan);
            }
            // Pegar overlay en la parte inferior
            imagecopy($imagen_base, $overlay, 0, $alto_imagen - $overlay_altura, 0, 0, $ancho_imagen, $overlay_altura);
            imagedestroy($overlay);

            // --- Mapa mejorado ---
            $mapa_imagen = null;
            if ($latitud != 0 && $longitud != 0) {
                $mapa_url = "../staticmap.php?center={$latitud},{$longitud}&zoom=16&size=300x200&markers={$latitud},{$longitud},red";
                $mapa_imagen = @imagecreatefrompng($mapa_url);
                if (!$mapa_imagen) {
                    $mapa_imagen = imagecreate(300, 200);
                    $bg_mapa = imagecolorallocate($mapa_imagen, 240, 240, 240);
                    $txt_negro = imagecolorallocate($mapa_imagen, 0, 0, 0);
                    $txt_azul = imagecolorallocate($mapa_imagen, 0, 100, 200);
                    $txt_rojo = imagecolorallocate($mapa_imagen, 200, 0, 0);
                    $borde_gris = imagecolorallocate($mapa_imagen, 128, 128, 128);
                    imagefill($mapa_imagen, 0, 0, $bg_mapa);
                    imagestring($mapa_imagen, 5, 80, 10, "UBICACION GPS", $txt_rojo);
                    imagestring($mapa_imagen, 5, 80, 25, "=============", $txt_rojo);
                    imagestring($mapa_imagen, 4, 20, 50, "LATITUD:", $txt_azul);
                    imagestring($mapa_imagen, 4, 20, 65, number_format($latitud, 6), $txt_negro);
                    imagestring($mapa_imagen, 4, 20, 85, "LONGITUD:", $txt_azul);
                    imagestring($mapa_imagen, 4, 20, 100, number_format($longitud, 6), $txt_negro);
                    if ($direccion) {
                        imagestring($mapa_imagen, 3, 15, 125, "DIRECCION:", $txt_azul);
                        $lineas_direccion = str_split($direccion, 35);
                        $y_pos = 140;
                        foreach ($lineas_direccion as $index => $linea) {
                            if ($index < 3) {
                                imagestring($mapa_imagen, 3, 15, $y_pos, $linea, $txt_negro);
                                $y_pos += 15;
                            }
                        }
                    }
                    imagerectangle($mapa_imagen, 0, 0, 299, 199, $borde_gris);
                }
            }
            imagecopymerge($imagen_base, $overlay, 0, $alto_imagen - $overlay_altura, 0, 0, $ancho_imagen, $overlay_altura, 90);
            if ($mapa_imagen) {
                $margen = 15;
                $mw = imagesx($mapa_imagen);
                $mh = imagesy($mapa_imagen);
                if ($ancho_imagen > $mw + $margen && $alto_imagen > $mh + $margen * 2) {
                    $x_mapa = $ancho_imagen - $mw - $margen;
                    $y_mapa = $margen;
                    $blanco = imagecolorallocate($imagen_base, 255, 255, 255);
                    imagefilledrectangle($imagen_base, $x_mapa - 5, $y_mapa - 5, $x_mapa + $mw + 5, $y_mapa + $mh + 5, $blanco);
                    imagecopy($imagen_base, $mapa_imagen, $x_mapa, $y_mapa, 0, 0, $mw, $mh);
                    $negro = imagecolorallocate($imagen_base, 0, 0, 0);
                    imagerectangle($imagen_base, $x_mapa - 2, $y_mapa - 2, $x_mapa + $mw + 2, $y_mapa + $mh + 2, $negro);
                    imagerectangle($imagen_base, $x_mapa - 1, $y_mapa - 1, $x_mapa + $mw + 1, $y_mapa + $mh + 1, $negro);
                }
                imagedestroy($mapa_imagen);
            }
            imagedestroy($overlay);
            $nombre_archivo  = 'asistencia_' . $grupo_id . '_' . time() . '.jpg';
            $ruta_completa   = $upload_dir . $nombre_archivo;
            if (imagejpeg($imagen_base, $ruta_completa, 95)) {
                $stmt = $conn->prepare("INSERT INTO fotos_asistencia (grupo_id, empleado_nombre, empleado_telefono, foto_procesada, latitud, longitud, direccion_aproximada, tipo_asistencia, motivo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssddsss", $grupo_id, $empleado_nombre, $empleado_telefono, $ruta_completa, $latitud, $longitud, $direccion, $tipo_asistencia, $motivo);
                if ($stmt->execute()) {
                    $mensaje_exito = "✅ Foto de asistencia registrada correctamente con información ampliada y mapa mejorado.";
                } else {
                    $mensaje_error = "Error al guardar en base de datos: " . $stmt->error;
                }
            } else {
                $mensaje_error = "Error al guardar la imagen procesada.";
            }
            imagedestroy($imagen_base);
        } catch (Exception $e) {
            $mensaje_error = "Error al procesar la imagen: " . $e->getMessage();
        }
    }
}

// Cargar últimas fotos
$fotos_recientes = [];
$sqlFotos = "SELECT fa.*, g.nombre as proyecto_nombre, g.empresa FROM fotos_asistencia fa JOIN grupos g ON fa.grupo_id = g.id ORDER BY fa.fecha_hora DESC LIMIT 500";
$fotos_result = $conn->query($sqlFotos);
while ($foto = $fotos_result->fetch_assoc()) {
    // Normalizar ruta de imagen y GPS
    $img_src = '';
    if (!empty($foto['foto_procesada'])) {
        $img_src = $foto['foto_procesada']; // relativo a admin/
    } elseif (!empty($foto['archivo_procesado'])) {
        // legado: archivo en ../uploads/fotos_asistencia/
        $img_src = '../uploads/fotos_asistencia/' . $foto['archivo_procesado'];
    }
    $foto['__img_src'] = $img_src;
    $foto['__lat'] = isset($foto['latitud']) ? $foto['latitud'] : (isset($foto['lat']) ? $foto['lat'] : null);
    $foto['__lng'] = isset($foto['longitud']) ? $foto['longitud'] : (isset($foto['lng']) ? $foto['lng'] : null);
    $fotos_recientes[] = $foto;
}

// Agrupar fotos por proyecto y fecha para la galería
$fotos_agrupadas = [];
foreach ($fotos_recientes as $foto) {
    $proyecto = $foto['proyecto_nombre'];
    $fh = $foto['fecha_hora'];
    if ($fh && isset($AJUSTE_HORAS_DISPLAY) && $AJUSTE_HORAS_DISPLAY != 0) {
        try { $dtg = new DateTime($fh); $dtg->modify(($AJUSTE_HORAS_DISPLAY>0?'+':'').$AJUSTE_HORAS_DISPLAY.' hour'); $fh = $dtg->format('Y-m-d H:i:s'); } catch(Exception $e) {}
    }
    $fecha = $fh ? date('Y-m-d', strtotime($fh)) : date('Y-m-d');
    $fotos_agrupadas[$proyecto][$fecha][] = $foto;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📸 Fotos de Asistencia - Sistema de Emergencias</title>
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
            background: #fafafa;
            min-height: 100vh;
            color: #1a365d;
            line-height: 1.5;
        }
        
        a {
            text-decoration: none;
        }
        
        .container {
            max-width: 1050px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: #fff;
            border-radius: 20px;
            padding: 32px;
            margin: 24px 0 32px;
            box-shadow: 0 4px 20px rgba(26,54,93,.08);
            border: 1px solid #f1f5f9;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 24px;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .header-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg,#ff7a00 0%,#1a365d 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 32px;
            box-shadow: 0 8px 32px rgba(255,122,0,.25);
        }
        
        .header-info h1 {
            font-size: 32px;
            font-weight: 700;
            color: #1a365d;
            margin: 0 0 6px;
            letter-spacing: -.02em;
        }
        
        .header-info p {
            font-size: 16px;
            color: #718096;
            font-weight: 400;
            margin: 0;
        }
        
        .header-actions {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 14px 24px;
            border: none;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: .2s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg,#ff7a00 0%,#ff9500 100%);
            color: #fff;
            box-shadow: 0 6px 20px rgba(255,122,0,.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(255,122,0,.4);
        }
        
        .btn-secondary {
            background: #fff;
            color: #1a365d;
            border: 2px solid #e2e8f0;
        }
        
        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }
        
        .section {
            background: #fff;
            border-radius: 20px;
            padding: 40px 36px;
            box-shadow: 0 4px 20px rgba(26,54,93,.08);
            border: 1px solid #f1f5f9;
            margin-bottom: 32px;
        }
        
        .section h2 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 24px;
            color: #1a365d;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .section h3 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #1a365d;
        }
        
        .alert {
            margin-top: 26px;
            padding: 16px 18px;
            border-radius: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            margin-bottom: 24px;
        }
        
        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #bbf7d0;
        }
        
        .alert-danger {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        
        .alert-info {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        
        .proyecto-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .proyecto-card {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 24px;
            cursor: pointer;
            background: #fff;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(26,54,93,.05);
        }
        
        .proyecto-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(26,54,93,.15);
            border-color: #ff7a00;
        }
        
        .proyecto-card.selected {
            border-color: #ff7a00;
            background: #fff8f1;
            box-shadow: 0 8px 25px rgba(255,122,0,.15);
        }
        
        .proyecto-nombre {
            font-weight: 700;
            color: #1a365d;
            font-size: 16px;
            margin-bottom: 8px;
        }
        
        .proyecto-empresa {
            color: #718096;
            font-size: 14px;
        }
        
        .panel-detalle {
            display: none;
            margin-top: 32px;
        }
        
        .panel-content {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }
        
        .empleados-section {
            flex: 1;
            min-width: 280px;
        }
        
        .fotos-section {
            flex: 2;
            min-width: 400px;
        }
        
        .section-label {
            font-weight: 600;
            margin-bottom: 12px;
            color: #1a365d;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .empleados-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 300px;
            overflow: auto;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px;
            background: #fafafa;
        }
        
        .tipos-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .btn-chip {
            padding: 10px 18px;
            border: 2px solid #e2e8f0;
            border-radius: 20px;
            background: #fff;
            color: #1a365d;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-chip:hover {
            border-color: #ff7a00;
            background: #fff8f1;
            color: #ff7a00;
        }
        
        .btn-chip.active {
            background: linear-gradient(135deg,#ff7a00 0%,#ff9500 100%);
            color: #fff;
            border-color: #ff7a00;
            box-shadow: 0 4px 12px rgba(255,122,0,.25);
        }
        
        .foto-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 24px;
            margin-top: 20px;
        }
        
        .foto-card {
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(26,54,93,.08);
            border: 1px solid #f1f5f9;
        }
        
        .foto-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(26,54,93,.15);
        }
        
        .foto-card img {
            width: 100%;
            height: 250px;
            object-fit: cover;
        }
        
        .foto-info {
            padding: 20px;
        }
        
        .foto-info div {
            margin: 8px 0;
            font-size: 14px;
            color: #1a365d;
        }
        
        .foto-info strong {
            color: #1a365d;
            font-weight: 600;
        }
        
        .tipo-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .loading {
            color: #ff7a00;
            font-weight: 600;
            text-align: center;
            padding: 40px;
            font-size: 16px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #ff7a00;
            margin-bottom: 16px;
        }
        
        @media (max-width: 780px) {
            .header {
                padding: 24px;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-actions {
                width: 100%;
                justify-content: stretch;
            }
            
            .header-actions .btn {
                flex: 1;
                justify-content: center;
            }
            
            .section {
                padding: 32px 26px;
            }
            
            .panel-content {
                flex-direction: column;
            }
            
            .foto-grid {
                grid-template-columns: 1fr;
            }
            
            .proyecto-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-icon">
                    <i class="fas fa-camera"></i>
                </div>
                <div class="header-info">
                    <h1>Sistema de Fotos de Asistencia</h1>
                    <p>Gestión y visualización de fotografías de asistencia</p>
                </div>
            </div>
            <div class="header-actions">
                <a href="admin.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver al Panel
                </a>
            </div>
        </div>
    </div>

    <?php if(isset($mensaje_exito)): ?><div class="alert alert-success"><?=htmlspecialchars($mensaje_exito)?></div><?php endif; ?>
    <?php if(isset($mensaje_error)): ?><div class="alert alert-danger"><?=htmlspecialchars($mensaje_error)?></div><?php endif; ?>

    <?php if(empty($proyectos)): ?>
        <div class="section">
            <div class="alert alert-info">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>No hay proyectos activos.</strong><br>
                    Primero crea un proyecto en el <a href="admin.php">panel principal</a>.
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="section">
            <h2><i class="fas fa-folder-open"></i> Asistencias por Proyecto</h2>
            <div class="proyecto-grid" id="grid-proyectos">
                <?php foreach($proyectos as $p): ?>
                    <div class="proyecto-card" onclick="selectProyecto(<?= (int)$p['id']?>,'<?= htmlspecialchars($p['nombre'], ENT_QUOTES)?>')">
                        <div class="proyecto-nombre"><?=htmlspecialchars($p['nombre'])?></div>
                        <div class="proyecto-empresa">Empresa: <?=htmlspecialchars($p['empresa'])?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="section panel-detalle" id="panel-detalle">
            <h3 id="titulo-proyecto"></h3>
            <div class="panel-content">
                <div class="empleados-section">
                    <div class="section-label">Empleados</div>
                    <div id="lista-empleados" class="empleados-list"></div>
                </div>
                <div class="fotos-section">
                    <div class="section-label">Tipos de asistencia</div>
                                        <div class="tipos-buttons" style="align-items:center;gap:8px;flex-wrap:wrap;">
                        <button class="btn-chip" data-tipo="Entrada" onclick="selectTipo('Entrada')">
                            <i class="fas fa-sign-in-alt"></i> Entradas
                        </button>
                        <button class="btn-chip" data-tipo="Salida" onclick="selectTipo('Salida')">
                            <i class="fas fa-sign-out-alt"></i> Salidas
                        </button>
                        <button class="btn-chip" data-tipo="Descanso" onclick="selectTipo('Descanso')">
                            <i class="fas fa-coffee"></i> Descansos
                        </button>
                        <button class="btn-chip" data-tipo="Reanudar" onclick="selectTipo('Reanudar')">
                            <i class="fas fa-play"></i> Regreso de descanso
                        </button>
                                                <div style="display:flex;gap:6px;align-items:center;margin-left:auto;flex-wrap:wrap;">
                                                    <input type="date" id="f_ini" class="btn-chip" style="padding:8px 10px;font-size:13px;">
                                                    <input type="date" id="f_fin" class="btn-chip" style="padding:8px 10px;font-size:13px;">
                                                    <button class="btn-chip" style="background:#1a365d;color:#fff;border-color:#1a365d;" onclick="aplicarFecha()"><i class="fas fa-filter"></i> Filtrar</button>
                                                    <button class="btn-chip" style="background:#718096;color:#fff;border-color:#718096;" onclick="limpiarFecha()"><i class="fas fa-times"></i></button>
                                                </div>
                    </div>
                    <div id="contenedor-fotos"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
</div>

<script>
let selectedProyecto = null;
let selectedEmpleado = 0;
let selectedTipo = '';

// Permitir pre-seleccionar tipo vía ?tipo=Entrada/Salida/Descanso
const urlParams = new URLSearchParams(window.location.search);
const presetTipo = urlParams.get('tipo');
if(presetTipo){ selectedTipo = presetTipo; }

function selectProyecto(id, nombre){
    selectedProyecto = id; selectedEmpleado = 0; selectedTipo = '';
    
    // Actualizar estilos de proyectos
    document.querySelectorAll('.proyecto-card').forEach(card => {
        card.classList.remove('selected');
    });
    event.target.closest('.proyecto-card').classList.add('selected');
    
    document.getElementById('panel-detalle').style.display='block';
    document.getElementById('titulo-proyecto').innerHTML = `<i class="fas fa-project-diagram"></i> Proyecto: ${nombre}`;
    
    // Cargar empleados
    const caja = document.getElementById('lista-empleados');
    caja.innerHTML = '<button class="btn-chip" onclick="selectEmpleado(0)"><i class="fas fa-users"></i> Todos</button>';
    fetch(`fotos_asistencia.php?api=employees&proyecto_id=${id}`).then(r=>r.json()).then(j=>{
        if(!j.success) return;
        j.data.forEach(e=>{
            const b=document.createElement('button');
            b.className='btn-chip'; 
            b.innerHTML = `<i class="fas fa-user"></i> ${e.nombre}`;
            b.onclick=()=>selectEmpleado(e.id);
            caja.appendChild(b);
        });
    });
    
    // Limpiar tipos seleccionados
    document.querySelectorAll('.btn-chip[data-tipo]').forEach(btn => { btn.classList.remove('active'); });
    // Si había un tipo predefinido (por query) activarlo visualmente y cargar
    if(presetTipo){
        const btn = document.querySelector(`.btn-chip[data-tipo="${presetTipo}"]`);
        if(btn){ btn.classList.add('active'); selectedTipo = presetTipo; loadFotos(); }
    }
    
    // Limpiar fotos
    document.getElementById('contenedor-fotos').innerHTML = '<div class="empty-state"><i class="fas fa-camera"></i><h3>Selecciona un tipo de asistencia</h3><p>Elige entrada, salida, descanso o regreso de descanso para visualizar</p></div>';
}

function selectEmpleado(id){ 
    selectedEmpleado = id; 
    
    // Actualizar estilos de empleados
    document.querySelectorAll('#lista-empleados .btn-chip').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    if(selectedTipo) loadFotos(); 
}

function selectTipo(tipo){ 
    selectedTipo = tipo; 
    document.querySelectorAll('.btn-chip[data-tipo]').forEach(btn => btn.classList.remove('active'));
    // Asegurar que se marque el botón completo (si clic en icono)
    if(event && event.currentTarget){ event.currentTarget.classList.add('active'); }
    else {
        const btn = document.querySelector(`.btn-chip[data-tipo="${tipo}"]`); if(btn) btn.classList.add('active');
    }
    loadFotos();
}

function loadFotos(){
    if(!selectedProyecto || !selectedTipo) return;
    const cont = document.getElementById('contenedor-fotos');
    cont.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Cargando fotos...</div>';
    const f1 = document.getElementById('f_ini')?.value || '';
    const f2 = document.getElementById('f_fin')?.value || '';
    const url = `fotos_asistencia.php?api=photos&proyecto_id=${selectedProyecto}&tipo=${encodeURIComponent(selectedTipo)}&empleado_id=${selectedEmpleado||0}&start_date=${encodeURIComponent(f1)}&end_date=${encodeURIComponent(f2)}`;
    
    fetch(url).then(r=>r.json()).then(j=>{
        if(!j.success){ 
            cont.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ${j.error||'Error cargando fotos'}</div>`; 
            return; 
        }
        
        const data = j.data;
        if(!data.length){ 
            cont.innerHTML = '<div class="empty-state"><i class="fas fa-images"></i><h3>No hay registros</h3><p>No se encontraron fotos para los filtros seleccionados</p></div>'; 
            return; 
        }
        
        // Render
        const grid = document.createElement('div'); 
        grid.className='foto-grid';
        
        data.forEach(f=>{
            const card=document.createElement('div'); 
            card.className='foto-card';
            
            if(f.img){ 
                card.innerHTML = `<a href="${f.img}" target="_blank"><img src="${f.img}" alt="foto" onerror="this.parentElement.innerHTML='<div style=&quot;height:250px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8;flex-direction:column;&quot;><i class=&quot;fas fa-image&quot; style=&quot;font-size:48px;margin-bottom:8px;&quot;></i>Imagen no disponible</div>'"></a>`; 
            } else { 
                card.innerHTML = '<div style="height:250px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8;flex-direction:column;"><i class="fas fa-image" style="font-size:48px;margin-bottom:8px;"></i>Imagen no disponible</div>'; 
            }
            
            const meta=document.createElement('div'); 
            meta.className='foto-info';
            const fechaTxt = f.fecha_local || f.fecha_hora || '';
            const tipoSlug = (f.tipo_slug || '').toLowerCase();
            const tipoTexto = f.tipo || f.tipo_original || 'Evento sin tipo';
            let color = '#64748b';
            let icon = 'fas fa-clock';

            if (tipoSlug==='entrada') { color = '#16a34a'; icon = 'fas fa-sign-in-alt'; }
            else if (tipoSlug==='salida') { color = '#dc2626'; icon = 'fas fa-sign-out-alt'; }
            else if (tipoSlug==='descanso') { color = '#f59e0b'; icon = 'fas fa-coffee'; }
            else if (tipoSlug==='reanudar') { color = '#2563eb'; icon = 'fas fa-play'; }

            meta.innerHTML = `
                <div><strong><i class="fas fa-user"></i> ${escapeHtml(f.empleado||'')}</strong></div>
                <div><i class="fas fa-calendar"></i> ${fechaTxt}</div>
                <div><i class="${icon}"></i> <span class="tipo-badge" style="background:${color}20;color:${color};border:1px solid ${color}55">${escapeHtml(tipoTexto)}</span>${f.motivo? ' — '+escapeHtml(f.motivo):''}</div>
                ${f.lat&&f.lng? `<div><i class="fas fa-map-marker-alt"></i> ${Number(f.lat).toFixed(4)}, ${Number(f.lng).toFixed(4)} — <a target="_blank" href="https://maps.google.com/?q=${f.lat},${f.lng}" style="color:#ff7a00;">Ver Mapa</a></div>`:''}
                ${f.direccion? `<div><i class="fas fa-location-arrow"></i> ${escapeHtml(f.direccion)}</div>`:''}
            `;
            
            card.appendChild(meta);
            grid.appendChild(card);
        });
        
        cont.innerHTML=''; 
        cont.appendChild(grid);
    }).catch((e)=>{ 
        cont.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Error de red: ${e&&e.message? e.message: 'Error desconocido'}</div>`; 
    });
}

function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c=>({"&":"&amp;","<":"&lt;",
">":"&gt;","\"":"&quot;","'":"&#39;"}[c])); }

function aplicarFecha(){ if(selectedTipo) loadFotos(); }
function limpiarFecha(){ const a=document.getElementById('f_ini'); const b=document.getElementById('f_fin'); if(a) a.value=''; if(b) b.value=''; if(selectedTipo) loadFotos(); }
</script>
</body>
</html>