<?php
/*
 Procesamiento automático de PDFs SUA:
 - Extrae Fecha de Proceso
 - Extrae tripletas: NSS, Nombre, CURP
 - Guarda registro del lote y detalle por empleado
 - Marca empleados no presentes este mes como bloqueados (no asignables)
 - Permite desbloquear manualmente vía parámetros (GET action=unblock&id=...)
 Requisitos de BD (crear si no existen):
   sua_lotes(id, fecha_proceso DATE, archivo VARCHAR, total INT, created_at TIMESTAMP)
   sua_empleados(id, lote_id FK, nss VARCHAR(20), nombre VARCHAR(120), curp VARCHAR(25), UNIQUE(nss, lote_id))
   empleados (agregar columnas nss, curp, bloqueado TINYINT DEFAULT 0 NULL)
   autorizados_mes(fecha DATE, nss VARCHAR(20), nombre VARCHAR(120), curp VARCHAR(25), PRIMARY KEY(fecha,nss))
 DISCLAIMER: Ajusta longitud / charset según tu esquema.
*/
session_start();
require_once '../includes/db.php';
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol']!=='admin') { header('Location: ../login.php'); exit; }
require '../vendor/autoload.php';
use Smalot\PdfParser\Parser;

// Debug activable con ?debug=1
if(isset($_GET['debug'])){
  define('SUA_DEBUG', true);
}
global $SUA_DEBUG_INFO; $SUA_DEBUG_INFO=[];

// Utilidad global para limpiar nombre (eliminar sufijos sueltos R / A derivados de columnas)
if(!function_exists('suaCleanNombre')){
  function suaCleanNombre($nombre){
    $nombre=trim(preg_replace('/\s+/',' ',$nombre));
    if($nombre==='') return $nombre;
    $tokens=explode(' ',$nombre);
    // Eliminar tokens finales de una sola letra R o A (hasta 3 repeticiones)
    $i=0; while(count($tokens)>2 && $i<3){ $last=end($tokens); if(strlen($last)===1 && in_array($last,['R','A'])){ array_pop($tokens); $i++; } else break; }
    return implode(' ',$tokens);
  }
}

if (!defined('SUA_PASSWORD_LENGTH')) {
  define('SUA_PASSWORD_LENGTH', 6);
}
if (!defined('SUA_PASSWORD_CHARSET')) {
  define('SUA_PASSWORD_CHARSET', 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789');
}
if (!defined('SUA_LEGACY_PASSWORDS')) {
  define('SUA_LEGACY_PASSWORDS', ['123456']);
}
if (!defined('SUA_EMAIL_DOMAIN')) {
  define('SUA_EMAIL_DOMAIN', 'ergosolar.com');
}

function suaGeneratePassword(int $length = SUA_PASSWORD_LENGTH): string {
  $length = max(1, $length);
  $charset = SUA_PASSWORD_CHARSET;
  $charsetLength = strlen($charset);
  if ($charsetLength === 0) {
    return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
  }
  $password = '';
  for ($i = 0; $i < $length; $i++) {
    $password .= $charset[random_int(0, $charsetLength - 1)];
  }
  return $password;
}

function suaEmailBaseFromName(string $nombre): string {
  $nombre = trim($nombre);
  if ($nombre === '') {
  return 'servicio_especializado';
  }
  $nombreLower = mb_strtolower($nombre, 'UTF-8');
  $trans = @iconv('UTF-8', 'ASCII//TRANSLIT', $nombreLower);
  if ($trans !== false) {
    $nombreLower = $trans;
  }
  $tokens = preg_split('/\s+/', $nombreLower);
  $sanitized = [];
  foreach ($tokens as $token) {
    $token = preg_replace('/[^a-z0-9]/', '', $token);
    if ($token !== '') {
      $sanitized[] = $token;
    }
  }
  if (empty($sanitized)) {
  $sanitized[] = 'servicio_especializado';
  }
  $base = implode('.', array_slice($sanitized, 0, 5));
  return substr($base, 0, 60);
}

function suaGenerateUniqueEmail(mysqli $conn, string $nombre, ?int $preferUserId = null): string {
  $base = suaEmailBaseFromName($nombre);
  $domain = SUA_EMAIL_DOMAIN;
  $email = $base . '@' . $domain;
  $suffix = 1;
  $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
  if (!$stmt) {
    return $email;
  }
  while (true) {
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row || ($preferUserId && (int)$row['id'] === (int)$preferUserId)) {
      break;
    }
    $email = $base . ++$suffix . '@' . $domain;
  }
  $stmt->close();
  return $email;
}

function suaEnsurePasswordVisible(mysqli $conn, int $userId, ?string $passwordPlain = null, bool $force = false): ?string {
  if ($userId <= 0) {
    return null;
  }

  $current = null;
  if ($stmt = $conn->prepare('SELECT COALESCE(password_visible, "") AS password_visible FROM users WHERE id = ? LIMIT 1')) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
      $current = trim((string)($row['password_visible'] ?? ''));
    }
    $stmt->close();
  }

  $needsUpdate = $force || $current === null || $current === '';
  if (!$needsUpdate && defined('SUA_LEGACY_PASSWORDS') && is_array(SUA_LEGACY_PASSWORDS)) {
    if (in_array($current, SUA_LEGACY_PASSWORDS, true)) {
      $needsUpdate = true;
    }
  }

  if ($needsUpdate) {
    $passwordPlain = $passwordPlain ?: suaGeneratePassword();
    $hash = password_hash($passwordPlain, PASSWORD_DEFAULT);
    if ($stmt = $conn->prepare('UPDATE users SET password = ?, password_visible = ? WHERE id = ?')) {
      $stmt->bind_param('ssi', $hash, $passwordPlain, $userId);
      $stmt->execute();
      $stmt->close();
    }
    return $passwordPlain;
  }

  return $current;
}

function suaRegistrarCredencial(mysqli $conn, array &$destino, int $userId, string $fallbackNombre = ''): void {
  if ($userId <= 0) {
    return;
  }
  if ($stmt = $conn->prepare('SELECT name, email, COALESCE(password_visible, "") AS password_visible FROM users WHERE id = ? LIMIT 1')) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
      if (!empty($row['email'])) {
        $clave = strtolower($row['email']);
        $destino[$clave] = [
          'nombre' => $row['name'] ?: $fallbackNombre,
          'email' => $row['email'],
          'password' => $row['password_visible'] !== '' ? $row['password_visible'] : 'No disponible'
        ];
      }
    }
    $stmt->close();
  }
}

function suaEnsureRolColumnSupportsServicioEspecializado(mysqli $conn): void {
  static $checked = false;
  if ($checked) {
    return;
  }
  $checked = true;

  $sql = "SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'rol' LIMIT 1";
  if (!($result = $conn->query($sql))) {
    return;
  }
  $info = $result->fetch_assoc();
  $result->close();
  if (!$info) {
    return;
  }

  $dataType = strtolower((string)($info['DATA_TYPE'] ?? ''));
  $isNullable = (isset($info['IS_NULLABLE']) && strtoupper($info['IS_NULLABLE']) === 'YES');
  $defaultValue = $info['COLUMN_DEFAULT'];
  $nullSql = $isNullable ? 'NULL' : 'NOT NULL';
  $defaultSql = '';
  if (!is_null($defaultValue)) {
    $defaultSql = " DEFAULT '" . $conn->real_escape_string($defaultValue) . "'";
  }

  if ($dataType === 'enum') {
    $columnType = (string)($info['COLUMN_TYPE'] ?? '');
    if (preg_match('/^enum\((.*)\)$/i', $columnType, $matches)) {
      $rawValues = $matches[1];
      $options = str_getcsv($rawValues, ',', "'");
      $options = array_map('trim', $options);
      if (!in_array('servicio_especializado', $options, true)) {
        $options[] = 'servicio_especializado';
        $options = array_values(array_unique($options));
        $enumList = implode(',', array_map(static function ($value) use ($conn) {
          return "'" . $conn->real_escape_string($value) . "'";
        }, $options));
        $conn->query("ALTER TABLE users MODIFY COLUMN rol ENUM($enumList) $nullSql$defaultSql");
      }
    }
    return;
  }

  $maxLen = isset($info['CHARACTER_MAXIMUM_LENGTH']) ? (int)$info['CHARACTER_MAXIMUM_LENGTH'] : 0;
  if ($maxLen > 0 && $maxLen < 20) {
    $conn->query("ALTER TABLE users MODIFY COLUMN rol VARCHAR(32) $nullSql$defaultSql");
  }
}

suaEnsureRolColumnSupportsServicioEspecializado($conn);

// Crear tablas si faltan (idempotente básico)
$conn->query("CREATE TABLE IF NOT EXISTS sua_lotes (id INT AUTO_INCREMENT PRIMARY KEY, fecha_proceso DATE NOT NULL, archivo VARCHAR(255) NOT NULL, total INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$conn->query("CREATE TABLE IF NOT EXISTS sua_empleados (id INT AUTO_INCREMENT PRIMARY KEY, lote_id INT NOT NULL, nss VARCHAR(25) NOT NULL, nombre VARCHAR(150) NOT NULL, curp VARCHAR(25) NOT NULL, UNIQUE KEY uniq_lote_nss (lote_id,nss), CONSTRAINT fk_lote FOREIGN KEY(lote_id) REFERENCES sua_lotes(id) ON DELETE CASCADE)");

$colsEmp = $conn->query("SHOW COLUMNS FROM empleados LIKE 'bloqueado'");
if(!$colsEmp->num_rows) { $conn->query("ALTER TABLE empleados ADD COLUMN bloqueado TINYINT(1) NOT NULL DEFAULT 0"); }
$colsNss = $conn->query("SHOW COLUMNS FROM empleados LIKE 'nss'");
if(!$colsNss->num_rows) { $conn->query("ALTER TABLE empleados ADD COLUMN nss VARCHAR(25) NULL"); }
$colsCurp = $conn->query("SHOW COLUMNS FROM empleados LIKE 'curp'");
if(!$colsCurp->num_rows) { $conn->query("ALTER TABLE empleados ADD COLUMN curp VARCHAR(25) NULL"); }
$colsEmpresaSE = $conn->query("SHOW COLUMNS FROM sua_empleados LIKE 'empresa'");
if(!$colsEmpresaSE->num_rows) { $conn->query("ALTER TABLE sua_empleados ADD COLUMN empresa VARCHAR(50) NULL"); }
$colsEmpresaEmp = $conn->query("SHOW COLUMNS FROM empleados LIKE 'empresa'");
if(!$colsEmpresaEmp->num_rows) { $conn->query("ALTER TABLE empleados ADD COLUMN empresa VARCHAR(50) NULL"); }
$conn->query("CREATE TABLE IF NOT EXISTS autorizados_mes (fecha DATE NOT NULL, nss VARCHAR(25) NOT NULL, nombre VARCHAR(150) NOT NULL, curp VARCHAR(25) NOT NULL, PRIMARY KEY(fecha,nss))");

$colPwdVisible = $conn->query("SHOW COLUMNS FROM users LIKE 'password_visible'");
if(!$colPwdVisible->num_rows) {
  $conn->query("ALTER TABLE users ADD COLUMN password_visible VARCHAR(191) NULL AFTER password");
}

// Crear tabla de anexos para almacenar PDFs adicionales con fecha
$conn->query("CREATE TABLE IF NOT EXISTS sua_anexos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  archivo VARCHAR(255) NOT NULL,
  fecha_anexo DATE NOT NULL,
  nombre_original VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_by INT DEFAULT NULL,
  INDEX idx_fecha (fecha_anexo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mensaje=''; $error=''; $lote_id=null; $empleados_extraidos=[]; $fecha_proceso=null; $faltantes=[]; $proyectos=[]; $credenciales_generadas=[];
$mensaje_anexo=''; $error_anexo='';

// Procesar subida de anexo PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_anexo']) && isset($_FILES['anexo_pdf'])) {
  $fecha_anexo = isset($_POST['fecha_anexo']) ? trim($_POST['fecha_anexo']) : '';

  if (empty($fecha_anexo)) {
    $error_anexo = 'Debe seleccionar una fecha para el anexo.';
  } elseif ($_FILES['anexo_pdf']['error'] === UPLOAD_ERR_OK) {
    $tmp = $_FILES['anexo_pdf']['tmp_name'];
    $nombre_original = basename($_FILES['anexo_pdf']['name']);
    $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));

    if ($ext !== 'pdf') {
      $error_anexo = 'Solo se permiten archivos PDF.';
    } else {
      $uploadDir = __DIR__ . '/../uploads/sua_anexos/';
      if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
      }

      $timestamp = time();
      $nombreArchivo = 'anexo_' . date('Ymd_His', $timestamp) . '_' . uniqid() . '.pdf';
      $rutaDestino = $uploadDir . $nombreArchivo;

      if (move_uploaded_file($tmp, $rutaDestino)) {
        $userId = $_SESSION['user_id'] ?? null;
        $stmt = $conn->prepare('INSERT INTO sua_anexos (archivo, fecha_anexo, nombre_original, created_by) VALUES (?, ?, ?, ?)');
        if ($stmt) {
          $stmt->bind_param('sssi', $nombreArchivo, $fecha_anexo, $nombre_original, $userId);
          if ($stmt->execute()) {
            $mensaje_anexo = 'Anexo subido exitosamente con fecha ' . date('d/m/Y', strtotime($fecha_anexo)) . '.';
          } else {
            $error_anexo = 'Error al registrar el anexo en la base de datos.';
            @unlink($rutaDestino);
          }
          $stmt->close();
        } else {
          $error_anexo = 'Error al preparar la consulta.';
          @unlink($rutaDestino);
        }
      } else {
        $error_anexo = 'Error al mover el archivo al directorio de destino.';
      }
    }
  } else {
    $error_anexo = 'Error al subir el archivo. Código: ' . $_FILES['anexo_pdf']['error'];
  }
}

// Eliminar anexos seleccionados
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_anexos']) && isset($_POST['anexo_ids'])) {
  $anexo_ids = $_POST['anexo_ids'];
  $uploadDir = __DIR__ . '/../uploads/sua_anexos/';

  foreach ($anexo_ids as $anexo_id) {
    $anexo_id = (int)$anexo_id;
    if ($anexo_id > 0) {
      $stmt = $conn->prepare('SELECT archivo FROM sua_anexos WHERE id = ? LIMIT 1');
      if ($stmt) {
        $stmt->bind_param('i', $anexo_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
          $archivo = $row['archivo'];
          $rutaArchivo = $uploadDir . $archivo;
          if (file_exists($rutaArchivo)) {
            @unlink($rutaArchivo);
          }
        }
        $stmt->close();
      }

      $stmt = $conn->prepare('DELETE FROM sua_anexos WHERE id = ?');
      if ($stmt) {
        $stmt->bind_param('i', $anexo_id);
        $stmt->execute();
        $stmt->close();
      }
    }
  }
  $mensaje_anexo = 'Anexos eliminados exitosamente.';
}

// Guardar empresa seleccionada en la sesión y en los empleados extraídos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_autorizados'])) {
  $empresa = isset($_POST['empresa_sua']) ? trim($_POST['empresa_sua']) : '';
  if ($empresa) {
    $_SESSION['ultima_sua_auto']['empresa'] = $empresa;
    if (!empty($empleados_extraidos)) {
      foreach ($empleados_extraidos as &$e) {
        $e['empresa'] = $empresa;
      }
      unset($e);
    }
    if (!empty($_SESSION['ultima_sua_auto']['empleados'])) {
      foreach ($_SESSION['ultima_sua_auto']['empleados'] as &$e) {
        $e['empresa'] = $empresa;
      }
      unset($e);
    }
  }
}

// Nuevo: permitir procesamiento directo de una lista pegada (NSS NOMBRE CURP por línea)
function extraerDesdeLista($texto){
  $resultado=[];
  $lineas=preg_split('/\r?\n/',$texto);
  // Acepta CURP con homoclave alfanumérica + dígito final (flexible)
  $pat='/^(\d{2}-\d{2}-\d{2}-\d{4}-\d)\s+(.+?)\s+([A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d)\s*$/iu';
  $stopWords=['REING','REING.','BAJA','ALTA'];
  // suaCleanNombre ahora está definida en el ámbito global
  foreach($lineas as $ln){
    $ln=trim($ln);
    if($ln==='') continue;
    if(preg_match($pat,$ln,$m)){
      $nss=$m[1]; $nombre=trim(preg_replace('/\s+/',' ',strtoupper($m[2])));
      // Filtrar palabras de estado
      $nombreTokens=array_filter(explode(' ',$nombre),function($t) use($stopWords){ return $t!=='' && !in_array($t,$stopWords,true); });
      $nombre=suaCleanNombre(implode(' ',$nombreTokens));
      $curp=strtoupper($m[3]);
      if(strlen($curp)===18){ $resultado[]=['nss'=>$nss,'nombre'=>$nombre,'curp'=>$curp]; }
    } else {
      // Intento secundario: buscar NSS y CURP dentro de la línea aunque haya múltiples en una sola línea
      $patNSS='/(\d{2}-\d{2}-\d{2}-\d{4}-\d)/';
      $patCURP='/[A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d/';
      if(preg_match_all($patNSS,$ln,$nsss) && preg_match_all($patCURP,$ln,$curps)){
        // Heurística: repartir por orden; lo que esté entre NSS y CURP es nombre
        $pos=[]; foreach($nsss[1] as $n){ $pos[strpos($ln,$n)]=['type'=>'nss','val'=>$n]; }
        foreach($curps[0] as $c){ $pos[strpos($ln,$c)][]=['type'=>'curp','val'=>$c]; }
        ksort($pos);
        // Simplificado: dividir la línea en tokens y reconstruir pares secuenciales NSS -> nombre tokens -> CURP
        $tokens=preg_split('/\s+/',$ln);
        $currentNSS=null; $nombreTokens=[];
        foreach($tokens as $tok){
          $tokUp=strtoupper($tok);
          if(in_array($tokUp,$stopWords,true)) continue; // ignorar marcadores
          if(preg_match($patNSS,$tok,$mn)){ // nuevo NSS
            if($currentNSS && !empty($nombreTokens)){ /* reset sin curp; descartamos */ }
            $currentNSS=$mn[1]; $nombreTokens=[]; continue;
          }
          if($currentNSS && preg_match($patCURP,$tok,$mc)){
            $nombre=trim(preg_replace('/\s+/',' ',strtoupper(implode(' ',$nombreTokens))));
            // Eliminar stopWords del nombre final
            $nombreTokensFiltered=array_filter(explode(' ',$nombre),function($t) use($stopWords){ return $t!=='' && !in_array($t,$stopWords,true); });
            $nombre=implode(' ',$nombreTokensFiltered);
            $curp=strtoupper($mc[0]);
            if($nombre!==''){ $nombre=suaCleanNombre($nombre); }
            if($nombre!=='' && strlen($curp)===18){ $resultado[]=['nss'=>$currentNSS,'nombre'=>$nombre,'curp'=>$curp]; }
            $currentNSS=null; $nombreTokens=[]; continue;
          }
          if($currentNSS){ $nombreTokens[]=$tok; }
        }
      }
    }
  }
  return $resultado;
}

function extraerFecha($texto){
  $patrones=[
    '/Fecha de Proceso:\s*(\d{1,2})\/(\w{3,4})\.?\/(\d{4})/iu',
    '/Fecha:\s*(\d{1,2})\/(\w{3,4})\.?\/(\d{4})/iu',
    '/(\d{1,2})\/(\w{3,4})\.?\/(\d{4})/iu'
  ];
  $meses=['ene'=>'01','feb'=>'02','mar'=>'03','abr'=>'04','may'=>'05','jun'=>'06','jul'=>'07','ago'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dic'=>'12'];
  foreach($patrones as $p){
    if(preg_match($p,$texto,$m)){
      $dia=str_pad($m[1],2,'0',STR_PAD_LEFT); $mes=strtolower(substr($m[2],0,3)); $anio=$m[3];
      return $anio.'-'.($meses[$mes]??'01').'-'.$dia;
    }
  }
  return date('Y-m-d');
}

function extraerEmpleados($texto){
  $originalTexto=$texto; // para debug
  $resultadoLineas=[];
  // PASO 0: Escaneo por líneas sin normalizar demasiado para no perder separaciones de tabla
  $lineas=preg_split('/\r?\n|\f/',$texto); // \f para salto de página
  $patNSS='/^(\s*)(\d{2}-\d{2}-\d{2}-\d{4}-\d)(.*)$/';
  $patCURP='/[A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d/';
  foreach($lineas as $ln){
    $raw=$ln;
    if(preg_match($patNSS,$ln,$mN)){
      $nss=$mN[2];
      // Buscar CURP en la línea (o concatenando con la siguiente si corte de columnas)
      $buscar=$ln;
      if(!preg_match($patCURP,$buscar,$mC)){
        // intentar unir con próxima línea (si la hay) sin NSS nuevo
        $idxLine=current(array_keys($lineas));
      }
      if(preg_match($patCURP,$ln,$mC)){
        $curp=$mC[0];
        // Nombre = texto entre NSS y CURP eliminando números y marcadores
        $entre=substr($ln,strpos($ln,$nss)+strlen($nss));
        $entre=substr($entre,0,strpos($entre,$curp));
        $entre=str_replace(['Reing','Reing.','Baja','Alta'],' ',$entre);
        // quitar columnas numéricas (números y decimales aislados)
        $entre=preg_replace('/\b[0-9]{1,4}(?:\.[0-9]{1,2})?\b/',' ',$entre);
        $entre=preg_replace('/[^A-ZÁÉÍÓÚÑ ]/iu',' ',$entre);
        $entre=strtoupper(trim(preg_replace('/\s+/',' ',$entre)));
        if(strlen($entre)>=5 && strlen($curp)===18){
          $resultadoLineas[$nss]=['nss'=>$nss,'nombre'=>$entre,'curp'=>strtoupper($curp)];
        }
      }
    }
  }

  // Normalizar saltos / espacios
  $t = preg_replace('/[\r\n\t]+/',' ',$texto);
  $t = preg_replace('/\s+/',' ',$t);
  // Quitar marcadores de movimientos que rompen nombres
  $t = preg_replace('/\b(Reing|Reing\.|Baja|Alta)\b/iu',' ',$t);
  // Separar casos pegados como PáginaReing -> Página Reing para poder eliminar después
  $t = preg_replace('/Página(Reing|Baja|Alta)/iu','Página $1',$t);
  // Eliminar bloques de movimientos con fechas y columnas numéricas (heurístico)
  // Consumir secuencias tipo: Reing 14/04/2025 4 449.71 0 0 0 92.32 ... hasta que aparece otro marcador, un NSS o fin
  // Eliminación menos agresiva: sólo el token y la fecha inmediata para no tragarse líneas con siguiente NSS
  $t = preg_replace('/\b(Reing|Baja|Alta)\s+\d{2}\/\d{2}\/\d{4}/iu',' ',$t);
  // Eliminar encabezados y glosarios irrelevantes
  $encabezados = [
    'Trabajadores con Articulo 33','Trabajadores Pensionados en I.V.','Trabajadores Pensionados en C.V.',
    'Trabajadores con Semana Reducida','Trabajadores con Jornada Reducida','Trabajadores Eventuales del Campo',
    'Salario Diario Integrado','Incapacidades','Ausentismos','Total a pagar:','Total de Cotizantes:',
    'Total de Días cotizados','Para el cálculo del seguro de I.V. se utilizará el tope','S.M.G.D.F.:','U. M. A.:'
  ];
  $patHeads = '/'.implode('|', array_map(function($h){ return preg_quote($h,'/'); }, $encabezados)).'/iu';
  $t = preg_replace($patHeads,' ',$t);
  // Eliminar abreviaturas aisladas del glosario (Art. 33, P/IV, P/CV, S/R, J/R, E/C, SDI, INC, AUS, C. F., etc.)
  $t = preg_replace('/\b(Art\.\s*33|P\/IV|P\/CV|S\/R|J\/R|E\/C|SDI|INC|AUS|C\.\s*F\.|EXC\.\s*PAT\.|EXC\.\s*OBR\.|P\.D\.\s*PAT|P\.D\.\s*OBR|G\.M\.P\.\s*PAT\.|G\.M\.P\.\s*OBR|R\.T\.|I\.V\.\s*PAT|I\.V\.\s*OBR|G\.P\.S\.)\b/iu',' ',$t);
  // Compactar de nuevo espacios
  $t = preg_replace('/\s+/',' ',$t);

  $resultado = [];
  $add = function($nss,$nombre,$curp,&$resultado){
    $nss=trim($nss); $curp=trim($curp);
    $nombre=trim(preg_replace('/\s+/',' ',$nombre));
    $nombre=strtoupper(preg_replace('/[^A-ZÁÉÍÓÚÑ\s]/u','',$nombre));
  // Filtrar stopWords dentro del nombre
  $nombre = trim(preg_replace('/\b(REING|REING\.|BAJA|ALTA)\b/u','',$nombre));
  if(strlen($curp)!==18) return; // CURP debe tener 18
    if(strlen($nombre)<5 || strlen($nombre)>90) return;
    if(!preg_match('/^\d{2}-\d{2}-\d{2}-\d{4}-\d$/',$nss)) return;
  $resultado[$nss] = ['nss'=>$nss,'nombre'=>suaCleanNombre($nombre),'curp'=>$curp];
  };

  // 1. Patrón principal (no tan estricto en longitud nombre)
  $patPrincipal='/([0-9]{2}-[0-9]{2}-[0-9]{2}-[0-9]{4}-[0-9])\s+([A-ZÁÉÍÓÚÑ ]+?)\s+([A-Z]{4}[0-9]{6}[A-Z0-9]{6}[A-Z0-9][0-9])/iu';
  if(preg_match_all($patPrincipal,$t,$m,PREG_SET_ORDER)){
    foreach($m as $coinc){ $add($coinc[1],$coinc[2],$coinc[3],$resultado); }
  }

  // 2. Fallback: dividir por NSS y buscar CURP dentro de cada segmento
  if(empty($resultado)){
    $patNSS='/\d{2}-\d{2}-\d{2}-\d{4}-\d/';
  $patCURP='/[A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d/i';
    preg_match_all($patNSS,$t,$nssLista);
    $partes = preg_split($patNSS,$t,-1,PREG_SPLIT_DELIM_CAPTURE);
    $nsss = $nssLista[0];
    for($i=1;$i<count($partes);$i++){
      if(!isset($nsss[$i-1])) continue; $nss=$nsss[$i-1]; $segmento=$partes[$i];
      if(preg_match($patCURP,$segmento,$cMatch)){
        $curp=$cMatch[0];
        $nombreParte = preg_replace('/'.preg_quote($curp,'/').'/','',$segmento,1);
        $add($nss,$nombreParte,$curp,$resultado);
      }
    }
  }

  // 3. Patrón específico adicional (puede capturar nombres pegados)
  if(empty($resultado)){
  $patExtra='/([0-9]{2}-[0-9]{2}-[0-9]{2}-[0-9]{4}-[0-9])\s+([A-ZÁÉÍÓÚÑ ]{3,}?)([A-Z]{4}[0-9]{6}[A-Z0-9]{6}[A-Z0-9][0-9])/iu';
    if(preg_match_all($patExtra,$t,$m2,PREG_SET_ORDER)){
      foreach($m2 as $coinc){ $add($coinc[1],$coinc[2],$coinc[3],$resultado); }
    }
  }

  // 4. Fallback final: escaneo tokenizado NSS ... CURP ignorando números intermedios
  if(count($resultado)<2){ // sólo si pocos resultados para evitar sobrecaptura
    $tokens=preg_split('/\s+/',$t);
    $patNSS='/^\d{2}-\d{2}-\d{2}-\d{4}-\d$/';
    $patCURP='/^[A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d$/';
    $stop=['REING','REING.','BAJA','ALTA'];
    $currentNSS=null; $nameTokens=[];
    foreach($tokens as $tok){
      $u=strtoupper($tok);
      if(preg_match($patNSS,$tok)){
        // flush anterior si incompleto
        $currentNSS=$tok; $nameTokens=[]; continue;
      }
      if($currentNSS){
        if(preg_match($patCURP,$u)){
          $nombre=trim(preg_replace('/\s+/',' ',implode(' ',$nameTokens)));
          if($nombre!==''){ $nombre=suaCleanNombre($nombre); $add($currentNSS,$nombre,$u,$resultado); }
          $currentNSS=null; $nameTokens=[]; continue;
        }
        if(in_array($u,$stop,true)) continue; // ignorar marcador
        if(preg_match('/^[0-9.,]+$/',$tok)) continue; // ignorar columnas numéricas
        if(preg_match('/^[A-ZÁÉÍÓÚÑ]+$/u',$u)) { $nameTokens[]=$u; }
      }
    }
  }

  // 5. Pasada universal adicional: capturar cualquier NSS no registrado aún (por si los casos problemáticos quedaron fuera).
  $patNSSAll='/\d{2}-\d{2}-\d{2}-\d{4}-\d/';
  $patCURPAll='/[A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d/';
  if(preg_match_all($patNSSAll,$t,$nsssAll,PREG_OFFSET_CAPTURE)){
    foreach($nsssAll[0] as $idx=>$data){
      $nss=$data[0];
      if(isset($resultado[$nss])) continue; // ya capturado
      $offset=$data[1];
      // Tomar un segmento desde este NSS hasta 320 chars adelante como ventana (mayor tolerancia a saltos/página)
      $segmento = substr($t,$offset,320);
      if(preg_match($patCURPAll,$segmento,$curpMatch,PREG_OFFSET_CAPTURE)){
        $curp=$curpMatch[0][0];
        // Nombre es lo que hay entre NSS y CURP
        $nombreBruto = substr($segmento,strlen($nss),$curpMatch[0][1]-strlen($nss));
        $nombreBruto = preg_replace('/\b(REING|REING\.|BAJA|ALTA)\b/',' ',$nombreBruto);
        $nombreBruto = preg_replace('/[0-9.,]+/',' ',$nombreBruto);
        $nombreBruto = strtoupper(preg_replace('/[^A-ZÁÉÍÓÚÑ\s]/u',' ',$nombreBruto));
        $nombre = trim(preg_replace('/\s+/',' ',$nombreBruto));
        if($nombre && strlen($curp)===18 && !isset($resultado[$nss])){
          $resultado[$nss]=['nss'=>$nss,'nombre'=>suaCleanNombre($nombre),'curp'=>$curp];
        }
      }
    }
  }

  // 6. Brute-force global regex pass (muy tolerante) para cualquier caso faltante
  $patBruto='/((?:\d{2}-){3}\d{4}-\d)\s+([A-ZÁÉÍÓÚÑ ]{3,90}?)[ ]{1,}([A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d)/u';
  if(preg_match_all($patBruto,$t,$mm,PREG_SET_ORDER)){
    foreach($mm as $co){
      $nss=$co[1]; if(isset($resultado[$nss])) continue;
      $nombre=strtoupper(trim(preg_replace('/\s+/',' ',$co[2])));
      $nombre=preg_replace('/\b(REING|REING\.|BAJA|ALTA)\b/','',$nombre);
      $curp=strtoupper($co[3]);
      if(strlen($curp)===18 && strlen($nombre)>=5){
        $resultado[$nss]=['nss'=>$nss,'nombre'=>suaCleanNombre($nombre),'curp'=>$curp];
      }
    }
  }

  // 7. Patrón NSS + CURP (posible pegado con nombre) + nombre (casos faltantes)
  if(true){
    $patNssCurp='/((?:\d{2}-){3}\d{4}-\d)\s+([A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d)(?:\s+|\t)?(\d{1,3})?\s*([A-ZÁÉÍÓÚÑ]{3,}(?:\s+[A-ZÁÉÍÓÚÑ]{2,}){0,6})/u';
    if(preg_match_all($patNssCurp,$originalTexto,$mcases,PREG_SET_ORDER)){
      foreach($mcases as $mset){
        $nss=$mset[1]; if(isset($resultado[$nss])) continue;
        $curp=strtoupper($mset[2]);
        $nombre=$mset[4];
        // Si CURP y nombre venían pegados (sin espacio) ya no entran porque exigimos espacio tras grupo 2; agregamos otro detect
        if(strlen($nombre)<3){
          // fallback concatenado dentro de mismo token
          $afterCurp=substr($mset[0], strpos($mset[0], $curp)+18);
          $afterCurp=preg_replace('/^[0-9\s]+/','',$afterCurp);
          $nombre=$afterCurp;
        }
        $nombre=strtoupper(preg_replace('/[^A-ZÁÉÍÓÚÑ\s]/u',' ',$nombre));
        $nombre=preg_replace('/\b(REING|REING\.|BAJA|ALTA)\b/',' ',$nombre);
        $nombre=trim(preg_replace('/\s+/',' ',$nombre));
        if(strlen($curp)===18 && strlen($nombre)>=5){
          $resultado[$nss]=['nss'=>$nss,'nombre'=>suaCleanNombre($nombre),'curp'=>$curp];
        }
      }
    }
    // Caso CURP+NOMBRE pegados sin espacio: NSS <tab> CURP+NOMBRE
    $patPegado='/((?:\d{2}-){3}\d{4}-\d)\s+([A-Z]{4}\d{6}[A-Z0-9]{6}[A-Z0-9]\d)([A-ZÁÉÍÓÚÑ]{3,})(?:\s+|\t)([A-ZÁÉÍÓÚÑ]{2,}(?:\s+[A-ZÁÉÍÓÚÑ]{2,}){0,6})/u';
    if(preg_match_all($patPegado,$originalTexto,$mpeg,PREG_SET_ORDER)){
      foreach($mpeg as $row){
        $nss=$row[1]; if(isset($resultado[$nss])) continue;
        $curp=strtoupper($row[2]);
        $nombre=strtoupper($row[3].' '.$row[4]);
        $nombre=preg_replace('/\b(REING|REING\.|BAJA|ALTA)\b/',' ',$nombre);
        $nombre=trim(preg_replace('/\s+/',' ',$nombre));
        if(strlen($curp)===18 && strlen($nombre)>=5){
          $resultado[$nss]=['nss'=>$nss,'nombre'=>suaCleanNombre($nombre),'curp'=>$curp];
        }
      }
    }
  }

  // Mezclar resultados de línea y heurísticos (prioriza línea para mismos NSS)
  foreach($resultadoLineas as $k=>$v){ $resultado[$k]=$v; }
  $final=array_values($resultado);

  // DEBUG: registrar NSS que aparecen en texto pero no quedaron en resultado
  if(defined('SUA_DEBUG')){
    $allNSS=[]; $patAll='/\d{2}-\d{2}-\d{2}-\d{4}-\d/';
    if(preg_match_all($patAll,$originalTexto,$all,PREG_OFFSET_CAPTURE)){
      foreach($all[0] as $m){ $allNSS[$m[0]]=$m[1]; }
      $capturados=[]; foreach($final as $e){ $capturados[$e['nss']]=true; }
      $missing=array_diff_key($allNSS,$capturados);
      $snips=[]; foreach($missing as $nss=>$pos){
        $start=max(0,$pos-60); $snippet=substr($originalTexto,$start,220); $snippet=str_replace(["\r","\n"],' ',$snippet);
        $snips[]=['nss'=>$nss,'snippet'=>$snippet];
      }
      $GLOBALS['SUA_DEBUG_INFO']=[
        'total_nss_en_texto'=>count($allNSS),
        'capturados'=>count($capturados),
        'faltantes'=>count($missing),
        'faltantes_detalle'=>$snips
      ];
      // Log file
      $logDir=__DIR__.'/../logs'; if(!is_dir($logDir)) @mkdir($logDir,0777,true);
      $logFile=$logDir.'/sua_debug.log';
      $entry=date('Y-m-d H:i:s')."\tNSS_total=".count($allNSS)." capturados=".count($capturados)." faltantes=".count($missing)."\n";
      foreach($snips as $s){ $entry.='MISSED '.$s['nss'].' => '.substr($s['snippet'],0,200)."\n"; }
      @file_put_contents($logFile,$entry,FILE_APPEND);
    }
  }

  return $final;
}

if(isset($_GET['action']) && $_GET['action']==='unblock' && isset($_GET['id'])){
    $id=(int)$_GET['id'];
    $conn->query("UPDATE empleados SET bloqueado=0 WHERE id=$id");
    header('Location: procesar_sua_auto.php?unblocked=1'); exit;
}

// Cargar proyectos activos para asignación
$resProy = $conn->query("SELECT id,nombre FROM grupos WHERE activo=1 ORDER BY nombre");
if($resProy){ $proyectos = $resProy->fetch_all(MYSQLI_ASSOC); }

$bloqueados = [];
if ($resBloq = $conn->query("SELECT e.id, e.nombre, e.nss, e.curp, u.email, u.password_visible FROM empleados e LEFT JOIN users u ON u.id = e.id WHERE e.bloqueado = 1 ORDER BY e.nombre")) {
  while ($row = $resBloq->fetch_assoc()) {
    $key = $row['nss'] ?: $row['id'];
    if (!isset($bloqueados[$key])) {
      $bloqueados[$key] = $row;
    }
  }
  $bloqueados = array_values($bloqueados);
}

// Restaurar última extracción para acciones de postprocesado sin re-subir archivo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['sua_pdf']) && !isset($_POST['lista_sua'])) {
  if (!empty($_SESSION['ultima_sua_auto']) && is_array($_SESSION['ultima_sua_auto'])) {
    $ultimaSua = $_SESSION['ultima_sua_auto'];
    $empleados_extraidos = $ultimaSua['empleados'] ?? $empleados_extraidos;
    $fecha_proceso = $ultimaSua['fecha'] ?? $fecha_proceso;
    $credenciales_generadas = $ultimaSua['credenciales'] ?? $credenciales_generadas;
  }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (!empty($_SESSION['ultima_sua_auto']) && is_array($_SESSION['ultima_sua_auto'])) {
    $ultimaSua = $_SESSION['ultima_sua_auto'];
    if (!$empleados_extraidos) {
      $empleados_extraidos = $ultimaSua['empleados'] ?? $empleados_extraidos;
    }
    if (!$fecha_proceso) {
      $fecha_proceso = $ultimaSua['fecha'] ?? $fecha_proceso;
    }
    if (empty($credenciales_generadas)) {
      $credenciales_generadas = $ultimaSua['credenciales'] ?? $credenciales_generadas;
    }
  }
}

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['desbloquear_empleado'])){
  $empleado_desbloquear = (int)($_POST['empleado_id'] ?? 0);
  if($empleado_desbloquear > 0){
    if($stmtDes = $conn->prepare('UPDATE empleados SET bloqueado = 0, activo = 1 WHERE id = ?')){
      $stmtDes->bind_param('i', $empleado_desbloquear);
      $stmtDes->execute();
      $stmtDes->close();
      $mensaje = 'Empleado desbloqueado correctamente.';
    }
  }
}

// Guardar autorizados sin asignar a proyecto
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['guardar_autorizados'])){
  if(!$empleados_extraidos){
    $error='No hay lista en memoria. Vuelve a procesar un PDF o lista.';
  } else {
    $mesFecha = substr($fecha_proceso,0,7).'-01';
    $ins = $conn->prepare("INSERT INTO autorizados_mes(fecha,nss,nombre,curp) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), curp=VALUES(curp)");
    $creados=0; $actualizados=0;
    $nssSUA = [];
    foreach($empleados_extraidos as $e){
      $nss = $e['nss']; $nombre=$e['nombre']; $curp=$e['curp'];
      if(!$nss) continue; // seguridad
      $nssSUA[] = $nss;
      // Guardar / actualizar en autorizados del mes
      $ins->bind_param('ssss',$mesFecha,$nss,$nombre,$curp); $ins->execute();
      // Buscar empleado por NSS
      $sel = $conn->prepare('SELECT id FROM empleados WHERE nss=? LIMIT 1');
      $sel->bind_param('s',$nss); $sel->execute(); $row=$sel->get_result()->fetch_assoc();
      if($row){
        $up=$conn->prepare('UPDATE empleados SET nombre=?, curp=?, puesto="Servicio Especializado", bloqueado=0, activo=1 WHERE id=?');
        $up->bind_param('ssi',$nombre,$curp,$row['id']);
        $up->execute();
        $actualizados++;
      } else {
        $email = suaGenerateUniqueEmail($conn, $nombre);
        $pwdPlain = suaGeneratePassword();
        $pwdHash = password_hash($pwdPlain, PASSWORD_DEFAULT);
        $userId = 0;
        if ($stmtUser = $conn->prepare("INSERT INTO users(name,email,password,password_visible,rol,activo) VALUES (?,?,?,?, 'servicio_especializado',1)")) {
          $stmtUser->bind_param('ssss', $nombre, $email, $pwdHash, $pwdPlain);
          if ($stmtUser->execute()) {
            $userId = (int)$stmtUser->insert_id;
          }
          $stmtUser->close();
        }
        if ($userId === 0) {
          if ($stmtFind = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1")) {
            $stmtFind->bind_param('s', $email);
            $stmtFind->execute();
            $rowUser = $stmtFind->get_result()->fetch_assoc();
            $userId = $rowUser ? (int)$rowUser['id'] : 0;
            $stmtFind->close();
          }
        }
        if($userId){
          $stmtEmp = $conn->prepare("INSERT INTO empleados(id,nombre,nss,curp,empresa,puesto,activo,bloqueado) VALUES(?,?,?,?,?, 'Servicio Especializado',1,0) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), nss=VALUES(nss), curp=VALUES(curp), empresa=VALUES(empresa), puesto='Servicio Especializado', activo=1, bloqueado=0");
          $stmtEmp->bind_param('issss',$userId,$nombre,$nss,$curp,$e['empresa']); $stmtEmp->execute();
          suaEnsurePasswordVisible($conn, $userId, $pwdPlain, true);
          suaRegistrarCredencial($conn, $credenciales_generadas, $userId, $nombre);
          $creados++;
        }
      }
      if($row){
        suaEnsurePasswordVisible($conn, (int)$row['id']);
        suaRegistrarCredencial($conn, $credenciales_generadas, (int)$row['id'], $nombre);
      }
    }
    // Bloquear y desasignar empleados de la empresa que no estén en el SUA
    $empresaSeleccionada = isset($_POST['empresa_sua']) ? trim($_POST['empresa_sua']) : '';
    if ($empresaSeleccionada && !empty($nssSUA)) {
      // Buscar empleados activos de esa empresa que no estén en el SUA
      $sqlBloquear = "SELECT id FROM empleados WHERE empresa = ? AND activo = 1 AND bloqueado = 0 AND nss NOT IN (" . implode(',', array_fill(0, count($nssSUA), '?')) . ")";
      $params = array_merge([$empresaSeleccionada], $nssSUA);
      $types = str_repeat('s', count($params));
      $stmtBloquear = $conn->prepare($sqlBloquear);
      $stmtBloquear->bind_param($types, ...$params);
      $stmtBloquear->execute();
      $resultBloquear = $stmtBloquear->get_result();
      $idsBloquear = [];
      while ($row = $resultBloquear->fetch_assoc()) {
        $idsBloquear[] = (int)$row['id'];
      }
      $stmtBloquear->close();
      // Bloquear y desasignar
      foreach ($idsBloquear as $idEmp) {
        $conn->query("UPDATE empleados SET bloqueado = 1, activo = 0 WHERE id = " . $idEmp);
        $conn->query("UPDATE empleado_proyecto SET activo = 0 WHERE empleado_id = " . $idEmp);
        $conn->query("UPDATE empleado_asignaciones SET status = 'finalizado' WHERE empleado_id = " . $idEmp . " AND status <> 'finalizado'");
      }
    }
    $total = count($empleados_extraidos);
    $mensaje="Lista de autorizados guardada. Actualizados: $actualizados, creados: $creados, total procesados: $total.";
  }
}

// Acciones manuales sobre Servicios Especializados no renovados
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accion_faltantes'])){
  $accion = $_POST['accion_faltantes'];
  $seleccionados = $_POST['faltantes'] ?? [];
  $ids = array_filter(array_map('intval', (array)$seleccionados));

  if(empty($ids)){
    $error = 'No seleccionaste Servicios Especializados.';
  } else {
    $list = implode(',', $ids);
    if($accion === 'bloquear'){
      if($conn->query("UPDATE empleados SET bloqueado=1 WHERE id IN ($list)")){
        $mensaje = 'Bloqueados ' . count($ids) . ' Servicios Especializados.';
      } else {
        $error = 'No se pudieron bloquear los Servicios Especializados seleccionados.';
      }
    } elseif($accion === 'baja'){
      $okEmpleados = $conn->query("UPDATE empleados SET activo=0, bloqueado=1 WHERE id IN ($list)");
      $okProyectos = $conn->query("UPDATE empleado_proyecto SET activo=0 WHERE empleado_id IN ($list)");
      if($okEmpleados && $okProyectos){
        $mensaje = 'Dados de baja ' . count($ids) . ' Servicios Especializados.';
      } else {
        $error = 'No se completó la baja de los Servicios Especializados seleccionados.';
      }
    } else {
      $error = 'Acción no reconocida para Servicios Especializados no renovados.';
    }
  }
}

// Eliminar lotes seleccionados (PDFs SUA)
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['eliminar_lotes'])){
  $lote_ids = $_POST['lote_ids'] ?? [];
  if($lote_ids){
    $ids = array_filter(array_map('intval',$lote_ids));
    if($ids){
      $list = implode(',', $ids);
      // Eliminación en cascada (sua_empleados tiene FK ON DELETE CASCADE)
      if($conn->query("DELETE FROM sua_lotes WHERE id IN ($list)")){
        $mensaje = 'Eliminados '.count($ids).' lotes SUA.';
      } else {
        $error = 'Error al eliminar lotes.';
      }
    }
  } else {
    $error = 'No seleccionaste ningún lote para eliminar.';
  }
}

// Procesar lista pegada (prioridad sobre PDF si viene)
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['lista_sua']) && trim($_POST['lista_sua'])!==''){
  $fecha_proceso = $_POST['fecha_proceso'] ?? date('Y-m-d');
  $empleados_extraidos = extraerDesdeLista($_POST['lista_sua']);
  if(!$empleados_extraidos){
    $error='No se detectaron empleados en la lista.';
  } else {
    // Registrar lote
    $stmt=$conn->prepare('INSERT INTO sua_lotes(fecha_proceso,archivo,total) VALUES(?,?,?)');
    $total=count($empleados_extraidos); $archivo='lista_manual.txt'; $stmt->bind_param('ssi',$fecha_proceso,$archivo,$total); $stmt->execute(); $lote_id=$stmt->insert_id;
    foreach($empleados_extraidos as $emp){
      $empresa = isset($emp['empresa']) ? $emp['empresa'] : (isset($_SESSION['ultima_sua_auto']['empresa']) ? $_SESSION['ultima_sua_auto']['empresa'] : null);
      $stmtE=$conn->prepare('INSERT IGNORE INTO sua_empleados(lote_id,nss,nombre,curp,empresa) VALUES(?,?,?,?,?)');
      $stmtE->bind_param('issss',$lote_id,$emp['nss'],$emp['nombre'],$emp['curp'],$empresa); $stmtE->execute();
      $stmtA=$conn->prepare('INSERT INTO autorizados_mes(fecha,nss,nombre,curp) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), curp=VALUES(curp)');
      $mesFecha=substr($fecha_proceso,0,7).'-01'; $stmtA->bind_param('ssss',$mesFecha,$emp['nss'],$emp['nombre'],$emp['curp']); $stmtA->execute();
      $resEmp=$conn->prepare('SELECT id FROM empleados WHERE nss=? LIMIT 1'); $resEmp->bind_param('s',$emp['nss']); $resEmp->execute(); $row=$resEmp->get_result()->fetch_assoc();
      if($row){ $up=$conn->prepare('UPDATE empleados SET curp=?, bloqueado=0, empresa=? WHERE id=?'); $up->bind_param('sssi',$emp['curp'],$empresa,$row['id']); $up->execute(); }
    }
  $mensaje='Lista procesada. Servicios Especializados detectados: '.$total;
  // Guardar en sesión para acciones posteriores
  $_SESSION['ultima_sua_auto']=['fecha'=>$fecha_proceso,'empleados'=>$empleados_extraidos,'credenciales'=>$credenciales_generadas];
  }
}

// Procesamiento tradicional PDF (solo si no se envió lista)
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['sua_pdf']) && (!isset($_POST['lista_sua']) || trim($_POST['lista_sua'])==='')){
    if($_FILES['sua_pdf']['error']===UPLOAD_ERR_OK){
        $tmp=$_FILES['sua_pdf']['tmp_name']; $orig=$_FILES['sua_pdf']['name'];
        if(strtolower(pathinfo($orig,PATHINFO_EXTENSION))!=='pdf'){ $error='Sube un PDF válido.'; }
        else {
            $dir='../uploads/'; if(!is_dir($dir)) mkdir($dir,0777,true);
            $dest=$dir.'sua_'.date('Y-m-d_H-i-s').'.pdf';
            if(move_uploaded_file($tmp,$dest)){
                try {
                    $parser=new Parser(); $pdf=$parser->parseFile($dest); $texto=$pdf->getText();
                    $fecha_proceso=extraerFecha($texto);
                    $empleados_extraidos=extraerEmpleados($texto);
                    if(!$empleados_extraidos){ $error='No se detectaron empleados en el PDF.'; }
                    else {
                        // Registrar lote
                        $stmt=$conn->prepare('INSERT INTO sua_lotes(fecha_proceso,archivo,total) VALUES(?,?,?)');
                        $total=count($empleados_extraidos); $archivo=basename($dest); $stmt->bind_param('ssi',$fecha_proceso,$archivo,$total); $stmt->execute(); $lote_id=$stmt->insert_id;
                        // Insertar empleados del lote y registrar autorizados del mes
            foreach($empleados_extraidos as $emp){
              $empresa = isset($emp['empresa']) ? $emp['empresa'] : (isset($_SESSION['ultima_sua_auto']['empresa']) ? $_SESSION['ultima_sua_auto']['empresa'] : null);
              $stmtE=$conn->prepare('INSERT IGNORE INTO sua_empleados(lote_id,nss,nombre,curp,empresa) VALUES(?,?,?,?,?)');
              $stmtE->bind_param('issss',$lote_id,$emp['nss'],$emp['nombre'],$emp['curp'],$empresa); $stmtE->execute();
              $stmtA=$conn->prepare('INSERT INTO autorizados_mes(fecha,nss,nombre,curp) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), curp=VALUES(curp)');
              $mesFecha=substr($fecha_proceso,0,7).'-01'; $stmtA->bind_param('ssss',$mesFecha,$emp['nss'],$emp['nombre'],$emp['curp']); $stmtA->execute();
              // Sincronizar con empleados (por NSS si existe)
              $resEmp=$conn->prepare('SELECT id FROM empleados WHERE nss=? LIMIT 1'); $resEmp->bind_param('s',$emp['nss']); $resEmp->execute(); $row=$resEmp->get_result()->fetch_assoc();
              if($row){
                $up=$conn->prepare('UPDATE empleados SET curp=?, bloqueado=0, empresa=? WHERE id=?'); $up->bind_param('sssi',$emp['curp'],$empresa,$row['id']); $up->execute();
              }
            }
                        $mensaje='Procesado correctamente. Servicios Especializados detectados: '.$total;
            $_SESSION['ultima_sua_auto']=['fecha'=>$fecha_proceso,'empleados'=>$empleados_extraidos,'credenciales'=>$credenciales_generadas];
                    }
                } catch(Exception $ex){ $error='Error procesando PDF: '.$ex->getMessage(); }
            } else { $error='No se pudo mover el archivo.'; }
        }
    } else { $error='Error de subida (código '.$_FILES['sua_pdf']['error'].')'; }
}

// Listado de últimos lotes
$lotes=$conn->query("SELECT * FROM sua_lotes ORDER BY id DESC LIMIT 20");

// Listado de anexos
$anexos=$conn->query("SELECT * FROM sua_anexos ORDER BY fecha_anexo DESC, id DESC LIMIT 50");

// Calcular faltantes (no renovados) respecto a empleados activos actuales
if($empleados_extraidos){
  $nssLista = array_column($empleados_extraidos,'nss');
  $inClause = "''"; if($nssLista){ $safe = array_map(function($v) use($conn){ return "'".$conn->real_escape_string($v)."'";}, $nssLista); $inClause = implode(',',$safe); }
  $resF = $conn->query("SELECT id,nombre,nss FROM empleados WHERE activo=1 AND nss IS NOT NULL AND nss<>'' AND nss NOT IN ($inClause) ORDER BY nombre");
  if($resF){ $faltantes = $resF->fetch_all(MYSQLI_ASSOC); }
}

if(!empty($credenciales_generadas)){
  ksort($credenciales_generadas);
}
$credenciales_lista = array_values($credenciales_generadas);

if(!isset($_SESSION['ultima_sua_auto'])){
  $_SESSION['ultima_sua_auto'] = [];
}
if($fecha_proceso){
  $_SESSION['ultima_sua_auto']['fecha'] = $fecha_proceso;
}
if(!empty($empleados_extraidos)){
  $_SESSION['ultima_sua_auto']['empleados'] = $empleados_extraidos;
}
$_SESSION['ultima_sua_auto']['credenciales'] = $credenciales_generadas;

$downloadType = isset($_GET['download']) ? strtolower((string)$_GET['download']) : null;
if ($downloadType) {
  $downloadCredenciales = $credenciales_generadas;
  if (empty($downloadCredenciales) && !empty($_SESSION['ultima_sua_auto']['credenciales']) && is_array($_SESSION['ultima_sua_auto']['credenciales'])) {
    $downloadCredenciales = $_SESSION['ultima_sua_auto']['credenciales'];
  }

  if (!empty($downloadCredenciales)) {
    ksort($downloadCredenciales);
  }

  if ($downloadType === 'txt') {
    if (!empty($downloadCredenciales)) {
      $lines = ["Nombre\tCorreo\tContraseña"];
      foreach ($downloadCredenciales as $cred) {
        $nombre = $cred['nombre'] ?? '';
        $correo = $cred['email'] ?? '';
        $password = $cred['password'] ?? 'No disponible';
        $lines[] = $nombre . "\t" . $correo . "\t" . $password;
      }
      $content = implode("\r\n", $lines) . "\r\n";
    } else {
      $content = "No hay credenciales disponibles.";
    }
    $filename = 'credenciales_' . date('Ymd_His') . '.txt';
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
  }

  if ($downloadType === 'csv') {
    $filename = 'credenciales_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    if ($output === false) {
      echo "No se pudo generar el archivo.";
      exit;
    }

    // BOM for Excel UTF-8 compatibility
    echo "\xEF\xBB\xBF";
    fputcsv($output, ['Nombre', 'Correo', 'Contraseña']);

    if (!empty($downloadCredenciales)) {
      foreach ($downloadCredenciales as $cred) {
        fputcsv($output, [
          $cred['nombre'] ?? '',
          $cred['email'] ?? '',
          $cred['password'] ?? 'No disponible'
        ]);
      }
    } else {
      fputcsv($output, ['No hay credenciales disponibles', '', '']);
    }
    fclose($output);
    exit;
  }

  header('Content-Type: text/plain; charset=UTF-8');
  header('Content-Disposition: inline');
  echo 'Formato de descarga no soportado.';
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Procesar SUA Automático</title>
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

.back-button {
    background: #fff;
    color: #1a365d;
    border: 2px solid #e2e8f0;
    padding: 14px 24px;
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

.back-button:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    transform: translateY(-1px);
}

.panel {
    background: #fff;
    border-radius: 20px;
    padding: 40px 36px;
    box-shadow: 0 4px 20px rgba(26,54,93,.08);
    border: 1px solid #f1f5f9;
    margin-bottom: 32px;
}

.section-title {
    font-size: 24px;
    font-weight: 600;
    margin-bottom: 24px;
    color: #1a365d;
    display: flex;
    align-items: center;
    gap: 12px;
}

form.upload {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: center;
}

input[type=file] {
    padding: 16px;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    background: #fafafa;
    color: #1e293b;
    transition: .2s;
    font-family: inherit;
}

input[type=file]:focus {
    outline: none;
    border-color: #ff7a00;
    box-shadow: 0 0 0 3px rgba(255,122,0,.15);
    background: #fff;
}

input[type=date], input[type=text], textarea, select {
    padding: 16px;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    background: #fafafa;
    color: #1e293b;
    transition: .2s;
    font-family: inherit;
    font-size: 15px;
}

input[type=date]:focus, input[type=text]:focus, textarea:focus, select:focus {
    outline: none;
    border-color: #ff7a00;
    box-shadow: 0 0 0 3px rgba(255,122,0,.15);
    background: #fff;
}

button {
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
    background: #475569;
    color: #fff;
}

.btn-secondary:hover {
    background: #334155;
    transform: translateY(-1px);
}

.btn-success {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
}

.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
}

.btn-warning {
    background: linear-gradient(135deg,#f59e0b,#d97706);
    color: #fff;
}

.btn-warning:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(245, 158, 11, 0.4);
}

.btn-danger {
    background: linear-gradient(135deg,#ef4444,#dc2626);
    color: #fff;
}

.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4);
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

.alert-error {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

table {
    border-collapse: collapse;
    width: 100%;
    font-size: 14px;
}

th, td {
    border: 1px solid #e2e8f0;
    padding: 12px 16px;
    text-align: left;
}

th {
    background: #f8fafc;
    font-weight: 600;
    color: #1a365d;
}

.mini-table-wrapper {
    max-height: 300px;
    overflow: auto;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin: 20px 0;
}

.action-section {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 24px;
    margin: 20px 0;
}

.action-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 16px;
    color: #1a365d;
}

.warning-section {
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 16px;
    padding: 24px;
    margin: 20px 0;
}

.warning-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 16px;
    color: #c2410c;
}

.form-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #1a365d;
    margin: 0 0 6px;
    letter-spacing: .5px;
    text-transform: uppercase;
}

.checkbox-table {
    max-height: 250px;
    overflow: auto;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin: 12px 0;
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
    
    .header-actions .back-button {
        flex: 1;
        justify-content: center;
    }
    
    .panel {
        padding: 32px 26px;
    }
    
    form.upload {
        flex-direction: column;
        align-items: stretch;
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
                    <i class="fas fa-file-pdf"></i>
                </div>
                <div class="header-info">
                    <h1>Procesar SUA Automático</h1>
                    <p>Procesamiento de archivos PDF y listas SUA</p>
                </div>
            </div>
            <div class="header-actions">
                <a href="admin.php" class="back-button">
                    <i class="fas fa-arrow-left"></i>
                    Volver al Menú
                </a>
            </div>
        </div>
    </div>
    <div class="panel">
      <h2 class="section-title">
          <i class="fas fa-unlock"></i>
          Bloqueo SUA
      </h2>
      <?php if (!empty($bloqueados)): ?>
      <div class="mini-table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Nombre</th>
              <th>NSS</th>
              <th>CURP</th>
              <th>Credencial</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bloqueados as $bloq): ?>
              <tr>
                <td><?= htmlspecialchars($bloq['nombre'] ?? '') ?></td>
                <td><?= htmlspecialchars($bloq['nss'] ?? '-') ?></td>
                <td><?= htmlspecialchars($bloq['curp'] ?? '-') ?></td>
                <td>
                  <div style="display:flex;flex-direction:column;gap:4px;">
                    <span style="font-weight:600;">Correo:</span>
                    <span><?= htmlspecialchars($bloq['email'] ?? 'Sin correo') ?></span>
                    <span style="font-weight:600;">Contraseña:</span>
                    <span><?= htmlspecialchars($bloq['password_visible'] ?? 'No disponible') ?></span>
                  </div>
                </td>
                <td>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="empleado_id" value="<?= (int)($bloq['id'] ?? 0) ?>">
                    <button type="submit" name="desbloquear_empleado" class="btn-secondary" style="padding:8px 12px;border-radius:10px;">
                      <i class="fas fa-unlock"></i> Desbloquear
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <div class="alert alert-success" style="margin:0;">
          <i class="fas fa-check-circle"></i>
          No hay colaboradores bloqueados por SUA.
        </div>
      <?php endif; ?>
    </div>

    <!-- Nuevo Panel de Anexos -->
    <div class="panel">
      <h2 class="section-title">
          <i class="fas fa-paperclip"></i>
          Anexos
          <span style="font-size:14px;font-weight:400;color:#64748b;">(PDFs adicionales con fecha)</span>
      </h2>

      <?php if($mensaje_anexo): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?= htmlspecialchars($mensaje_anexo) ?>
        </div>
      <?php endif; ?>
      <?php if($error_anexo): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($error_anexo) ?>
        </div>
      <?php endif; ?>

      <form class="upload" method="post" enctype="multipart/form-data" style="margin-bottom:32px;">
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
          <div style="flex:1;min-width:200px;">
            <label class="form-label" for="fecha_anexo">Fecha del Anexo</label>
            <input type="date" name="fecha_anexo" id="fecha_anexo" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required style="width:100%;padding:10px;border-radius:10px;border:1px solid #cbd5e1;">
          </div>
          <div style="flex:2;min-width:250px;">
            <label class="form-label" for="anexo_pdf">Archivo PDF</label>
            <input type="file" name="anexo_pdf" id="anexo_pdf" accept="application/pdf" required>
          </div>
          <button type="submit" name="subir_anexo" class="btn-primary" title="Subir Anexo PDF">
              <i class="fas fa-upload"></i>
              Subir Anexo
          </button>
        </div>
      </form>

      <h3 class="section-title" style="font-size:16px;margin-top:24px;">
          <i class="fas fa-list"></i>
          Anexos Registrados
      </h3>

      <form method="post" onsubmit="return confirm('¿Eliminar los anexos seleccionados? Esta acción no se puede deshacer.');">
        <input type="hidden" name="eliminar_anexos" value="1">
        <div class="mini-table-wrapper">
          <table>
            <thead>
              <tr>
                <th style="width:60px;"><input type="checkbox" id="chk_all_anexos" onclick="toggleAllAnexos(this)"></th>
                <th>Fecha del Anexo</th>
                <th>Archivo Original</th>
                <th>Fecha de Subida</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php if($anexos && $anexos->num_rows): while($anexo=$anexos->fetch_assoc()): ?>
                <tr>
                  <td style="text-align:center;">
                    <input type="checkbox" name="anexo_ids[]" value="<?= (int)$anexo['id'] ?>" title="Anexo #<?= (int)$anexo['id'] ?>">
                  </td>
                  <td><?= htmlspecialchars(date('d/m/Y', strtotime($anexo['fecha_anexo']))) ?></td>
                  <td><?= htmlspecialchars($anexo['nombre_original']) ?></td>
                  <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($anexo['created_at']))) ?></td>
                  <td>
                    <a href="../uploads/sua_anexos/<?= urlencode($anexo['archivo']) ?>" target="_blank" class="btn-secondary" style="padding:6px 12px;font-size:13px;text-decoration:none;">
                      <i class="fas fa-eye"></i> Ver PDF
                    </a>
                  </td>
                </tr>
              <?php endwhile; else: ?>
                <tr><td colspan="5" style="text-align:center;color:#64748b;padding:32px;">No hay anexos registrados</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if($anexos && $anexos->num_rows): ?>
        <div style="margin-top:24px;display:flex;justify-content:flex-start;">
          <button type="submit" class="btn-danger">
              <i class="fas fa-trash"></i>
              Eliminar Seleccionados
          </button>
        </div>
        <?php endif; ?>
      </form>
    </div>

    <div class="panel">
        <h2 class="section-title">
            <i class="fas fa-file-pdf"></i>
            Procesar SUA - PDF (Principal)
        </h2>
        <form class="upload" method="post" enctype="multipart/form-data">
          <input type="file" name="sua_pdf" accept="application/pdf" required>
          <button type="submit" class="btn-primary" title="Procesar PDF SUA">
              <i class="fas fa-upload"></i>
              Procesar PDF SUA
          </button>
          <button type="button" id="toggleManualBtn" class="btn-secondary" onclick="toggleManual()">
              <i class="fas fa-keyboard"></i>
              Mostrar Captura Manual
          </button>
        </form>
        <div id="manualBox" style="display:none;" class="action-section">
          <h3 class="action-title">
              <i class="fas fa-keyboard"></i>
              Captura Manual (Opcional)
          </h3>
          <form class="upload" method="post" style="flex-direction:column;align-items:stretch;gap:16px;">
            <div style="display:flex;gap:16px;flex-wrap:wrap;">
              <div style="flex:1;min-width:200px;">
                <label class="form-label">Fecha de Proceso</label>
                <input type="date" name="fecha_proceso" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
              </div>
              <div style="display:flex;align-items:flex-end;gap:16px;">
                <button type="submit" class="btn-primary">
                    <i class="fas fa-check"></i>
                    Procesar Lista
                </button>
              </div>
            </div>
            <textarea name="lista_sua" rows="6" placeholder="NSS NOMBRE COMPLETO CURP (una por línea)" style="width:100%;font-family:monospace;resize:vertical;"></textarea>
            <details style="font-size:13px;color:#64748b;margin-top:12px;">
                <summary style="cursor:pointer;font-weight:600;color:#1a365d;">
                    <i class="fas fa-info-circle"></i>
                    Formato esperado (ejemplo)
                </summary>
                <div style="margin-top:8px;font-family:monospace;background:#f1f5f9;padding:12px;border-radius:8px;">
                  62-84-67-2655-2 COYOTL REYES JOSE REMEDIOS CORR670901HPLYYM03<br>
                  61-05-84-0852-9 CUAYA ADRIAN VICTOR CUAV841116HPLYDC09
                </div>
            </details>
            <div style="margin-top:8px;font-size:12px;color:#718096;">
                <i class="fas fa-lightbulb"></i>
                Esta sección es opcional. Si tienes el PDF, úsalo preferentemente arriba.
            </div>
          </form>
        </div>
        <?php if($mensaje): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= $mensaje ?>
            </div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= $error ?>
            </div>
        <?php endif; ?>
        <?php if($empleados_extraidos): ?>
            <h3 class="section-title" style="margin-top:32px;">
                <i class="fas fa-users"></i>
                Servicios Especializados Detectados (<?= count($empleados_extraidos) ?>) - Fecha: <?= htmlspecialchars($fecha_proceso) ?>
            </h3>
    <div class="mini-table-wrapper">
      <table>
        <thead><tr><th>#</th><th>NSS</th><th>Nombre</th><th>CURP</th><th>Empresa</th></tr></thead>
        <tbody>
          <?php $i=1; foreach($empleados_extraidos as $e): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= htmlspecialchars($e['nss']) ?></td>
              <td><?= htmlspecialchars($e['nombre']) ?></td>
              <td><?= htmlspecialchars($e['curp']) ?></td>
              <td><?= htmlspecialchars($e['empresa'] ?? ($_SESSION['ultima_sua_auto']['empresa'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

            <!-- Acciones post-procesamiento -->
            <div style="margin-top:32px;display:grid;gap:24px;">
              <div class="action-section">
                <h4 class="action-title">
                    <i class="fas fa-save"></i>
                    Guardar Lista de Autorizados
                </h4>
                <form method="post" style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;">
                  <input type="hidden" name="guardar_autorizados" value="1">
                  <label for="empresa_sua" style="font-size:14px;font-weight:600;">Empresa:</label>
                  <select name="empresa_sua" id="empresa_sua" style="padding:8px 12px;border-radius:8px;">
                    <option value="Stone">Stone</option>
                    <option value="Remedios">Remedios</option>
                    <option value="ErgoSolar">ErgoSolar</option>
                  </select>
                  <button type="submit" class="btn-success">
                      <i class="fas fa-check"></i>
                      Guardar / Actualizar
                  </button>
                  <span style="font-size:14px;color:#64748b;">Registra o actualiza la autorización del mes y desbloquea los empleados presentes.</span>
                </form>
              </div>

              <?php if($faltantes): ?>
              <div class="warning-section">
                <h4 class="warning-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    Advertencia: Servicios Especializados no renovados (<?= count($faltantes) ?>)
                </h4>
                <p style="margin:0 0 16px;font-size:14px;color:#92400e;">Estos Servicios Especializados estaban activos anteriormente pero no aparecen en la nueva lista. Selecciona los que desees bloquear o dar de baja; si prefieres revisarlos más tarde, puedes ignorar esta advertencia.</p>
                <form method="post" style="display:flex;flex-direction:column;gap:16px;">
                  <div class="checkbox-table" style="border-color:#fcd34d;background:#fff;">
                    <table>
                      <thead><tr style="background:#fef3c7;"><th>Seleccionar</th><th>NSS</th><th>Nombre</th></tr></thead>
                      <tbody>
                        <?php foreach($faltantes as $f): ?>
                          <tr>
                            <td style="text-align:center;"><input type="checkbox" name="faltantes[]" value="<?= (int)$f['id'] ?>"></td>
                            <td><?= htmlspecialchars($f['nss']) ?></td>
                            <td><?= htmlspecialchars($f['nombre']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" name="accion_faltantes" value="bloquear" class="btn-secondary">
                        <i class="fas fa-lock"></i>
                        Bloquear seleccionados
                    </button>
                    <button type="submit" name="accion_faltantes" value="baja" class="btn-warning">
                        <i class="fas fa-user-minus"></i>
                        Dar de baja seleccionados
                    </button>
                    <span style="font-size:13px;color:#92400e;">Si aún no deseas bloquear o dar de baja, simplemente deja esta advertencia sin cambios.</span>
                  </div>
                </form>
              </div>
              <?php endif; ?>
            </div>

              <?php if (!empty($credenciales_lista)): ?>
              <div class="action-section">
                <h4 class="action-title">
                    <i class="fas fa-id-card"></i>
                    Credenciales generadas
                </h4>
                <?php
                  $downloadParams = $_GET;
                  unset($downloadParams['download'], $downloadParams['download_txt'], $downloadParams['download_format']);
                  $baseQuery = http_build_query($downloadParams);
                  $baseUrlRaw = $_SERVER['PHP_SELF'];
                  $queryPrefix = $baseQuery === '' ? '?' : '?' . $baseQuery . '&';
                  $downloadTxtUrl = htmlspecialchars($baseUrlRaw . $queryPrefix . 'download=txt', ENT_QUOTES, 'UTF-8');
                  $downloadCsvUrl = htmlspecialchars($baseUrlRaw . $queryPrefix . 'download=csv', ENT_QUOTES, 'UTF-8');
                ?>
                <div style="margin-bottom:16px;display:flex;gap:12px;flex-wrap:wrap;">
                  <a class="btn-secondary" href="<?= $downloadCsvUrl ?>">
                    <i class="fas fa-file-excel"></i>
                    Descargar CSV
                  </a>
                </div>
                <div class="mini-table-wrapper">
                  <table>
                    <thead>
                      <tr>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Contraseña asignada</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($credenciales_lista as $cred): ?>
                        <tr>
                          <td><?= htmlspecialchars($cred['nombre'] ?? '') ?></td>
                          <td><?= htmlspecialchars($cred['email'] ?? '') ?></td>
                          <td><?= htmlspecialchars($cred['password'] ?? 'No disponible') ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <p style="font-size:12px;color:#64748b;margin-top:8px;">
                  <i class="fas fa-info-circle"></i>
                  Las contraseñas listadas corresponden a la clave inicial asignada; si el colaborador la cambió aparecerá “No disponible”.
                </p>
              </div>
              <?php endif; ?>

        <?php endif; ?>
    </div>

    <div class="panel">
      <h2 class="section-title">
          <i class="fas fa-file-pdf"></i>
          PDFs SUA 
          <span style="font-size:14px;font-weight:400;color:#64748b;">(últimos registros)</span>
      </h2>
      <form method="post" onsubmit="return confirm('¿Eliminar los lotes seleccionados? Esta acción no se puede deshacer.');">
        <input type="hidden" name="eliminar_lotes" value="1">
        <div class="mini-table-wrapper">
          <table>
            <thead>
              <tr>
                <th style="width:60px;"><input type="checkbox" id="chk_all_lotes" onclick="toggleAllLotes(this)"></th>
                <th>Fecha Proceso</th><th>Archivo</th><th>Total</th>
              </tr>
            </thead>
            <tbody>
              <?php if($lotes && $lotes->num_rows): while($l=$lotes->fetch_assoc()): ?>
                <tr>
                  <td style="text-align:center;"><input type="checkbox" name="lote_ids[]" value="<?= (int)$l['id'] ?>" title="Lote #<?= (int)$l['id'] ?>"></td>
                  <td><?= htmlspecialchars($l['fecha_proceso']) ?></td>
                  <td>
                    <a href="ver_pdf.php?archivo=<?= urlencode($l['archivo']) ?>" target="_blank" style="color:#ff7a00;font-weight:500;">Ver PDF</a>
                    <a href="descargar_credenciales_lote.php?lote_id=<?= (int)$l['id'] ?>" class="btn-secondary" style="margin-left:8px;padding:4px 10px;font-size:13px;vertical-align:middle;" title="Descargar credenciales CSV">
                      <i class="fas fa-file-csv"></i> CSV
                    </a>
                  </td>
                  <td><?= $l['total'] ?></td>
                </tr>
              <?php endwhile; else: ?>
                <tr><td colspan="4" style="text-align:center;color:#64748b;padding:32px;">Sin registros</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div style="margin-top:24px;display:flex;justify-content:flex-start;align-items:center;flex-wrap:wrap;gap:16px;">
          <button type="submit" class="btn-danger">
              <i class="fas fa-trash"></i>
              Eliminar Seleccionados
          </button>
        </div>
      </form>
    </div>
</div>

<!-- Panel de bloqueados eliminado a solicitud -->
<script>
function toggleAllLotes(master){
  const checks=document.querySelectorAll('input[name="lote_ids[]"]');
  checks.forEach(c=>c.checked=master.checked);
}
function toggleAllAnexos(master){
  const checks=document.querySelectorAll('input[name="anexo_ids[]"]');
  checks.forEach(c=>c.checked=master.checked);
}
function toggleManual(){
  const box=document.getElementById('manualBox');
  const btn=document.getElementById('toggleManualBtn');
  const show=box.style.display==='none';
  box.style.display=show?'block':'none';
  btn.textContent = show? 'Ocultar Captura Manual':'Mostrar Captura Manual (Opcional)';
}
</script>

</body>
</html>
<?php
