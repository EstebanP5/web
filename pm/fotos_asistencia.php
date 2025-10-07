<?php
session_start();
date_default_timezone_set('America/Mexico_City');
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'pm') { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../includes/db.php';
// Alinear zona horaria de la sesión MySQL para NOW() y conversión de TIMESTAMP
$mysql_offset = date('P');
@$conn->query("SET time_zone='".$conn->real_escape_string($mysql_offset)."'");
$user_id = (int)$_SESSION['user_id'];
// Ajuste fijo para mostrar fotos (la BD está adelantada ~8h respecto a la hora real)
$AJUSTE_HORAS_DISPLAY = 0; // Sin ajuste manual

if (!function_exists('pm_str_contains')) {
    function pm_str_contains(string $haystack, string $needle): bool
    {
        return mb_strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('pm_normalize_tipo')) {
    function pm_normalize_tipo(?string $raw): array
    {
        $rawValue = trim((string)$raw);
        if ($rawValue === '') {
            return [
                'slug' => 'otro',
                'label' => 'Evento sin tipo',
                'raw' => ''
            ];
        }

        $normalized = mb_strtolower($rawValue, 'UTF-8');
        $normalized = strtr($normalized, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n'
        ]);

        if (pm_str_contains($normalized, 'entrada') || pm_str_contains($normalized, 'ingres') || pm_str_contains($normalized, 'reingreso') || pm_str_contains($normalized, 'reingres') || pm_str_contains($normalized, 'abrir') || pm_str_contains($normalized, 'inicio')) {
            return ['slug' => 'entrada', 'label' => 'Entrada registrada', 'raw' => $rawValue];
        }

        if (pm_str_contains($normalized, 'salida') || pm_str_contains($normalized, 'final') || pm_str_contains($normalized, 'termin') || pm_str_contains($normalized, 'cerr')) {
            return ['slug' => 'salida', 'label' => 'Salida registrada', 'raw' => $rawValue];
        }

        if (pm_str_contains($normalized, 'descans') || pm_str_contains($normalized, 'break') || pm_str_contains($normalized, 'paus') || pm_str_contains($normalized, 'comid') || pm_str_contains($normalized, 'reces')) {
            return ['slug' => 'descanso', 'label' => 'Inicio de descanso', 'raw' => $rawValue];
        }

        if (pm_str_contains($normalized, 'reanuda') || pm_str_contains($normalized, 'reanudo') || pm_str_contains($normalized, 'reanude') || pm_str_contains($normalized, 'regres') || pm_str_contains($normalized, 'retorn')) {
            return ['slug' => 'reanudo', 'label' => 'Regreso de descanso', 'raw' => $rawValue];
        }

        return [
            'slug' => 'otro',
            'label' => ucwords($rawValue),
            'raw' => $rawValue
        ];
    }
}

if (!function_exists('pm_folder_slug_from_path')) {
    function pm_folder_slug_from_path(string $path): string
    {
        $clean = str_replace('\\', '/', $path);
        while (strpos($clean, '../') === 0) {
            $clean = substr($clean, 3);
        }
        $segments = explode('/', trim($clean, '/'));
        if (count($segments) < 2) {
            return '';
        }

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
}

if (!function_exists('pm_label_for_slug')) {
    function pm_label_for_slug(string $slug): string
    {
        switch ($slug) {
            case 'entrada':
                return 'Entrada registrada';
            case 'salida':
                return 'Salida registrada';
            case 'descanso':
                return 'Inicio de descanso';
            case 'reanudo':
            case 'reanudar':
                return 'Regreso de descanso';
            default:
                return 'Evento sin tipo';
        }
    }
}

// Proyectos asignados al PM
$proyectos=[];
$stmt=$conn->prepare("SELECT g.id,g.nombre,g.empresa FROM proyectos_pm ppm JOIN grupos g ON ppm.proyecto_id=g.id WHERE ppm.user_id=? AND g.activo=1 ORDER BY g.nombre");
$stmt->bind_param('i',$user_id); $stmt->execute(); $rs=$stmt->get_result();
while($p=$rs->fetch_assoc()) $proyectos[]=$p;
$permitidos=array_flip(array_column($proyectos,'id'));
// Preselección vía parámetros GET (proyecto y tipo)
$proyecto_preseleccion = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
if(!isset($permitidos[$proyecto_preseleccion])) $proyecto_preseleccion = 0;
$tipo_preseleccion = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
// Eliminado ajuste manual vía UI; se aplica $AJUSTE_HORAS_DISPLAY automáticamente

// APIs
if(isset($_GET['api'])){
  header('Content-Type: application/json');
  $api=$_GET['api'];
  if($api==='employees'){
    $proyecto_id=(int)($_GET['proyecto_id']??0);
    if(!isset($permitidos[$proyecto_id])){echo json_encode(['success'=>false,'error'=>'No autorizado']); exit;}
    $st=$conn->prepare('SELECT e.id,e.nombre FROM empleado_proyecto ep JOIN empleados e ON e.id=ep.empleado_id WHERE ep.proyecto_id=? AND ep.activo=1 AND e.activo=1 ORDER BY e.nombre');
    $st->bind_param('i',$proyecto_id); $st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success'=>true,'data'=>$rows]); exit;
  }
  if($api==='photos'){
    $proyecto_id=(int)($_GET['proyecto_id']??0);
    if(!isset($permitidos[$proyecto_id])){echo json_encode(['success'=>false,'error'=>'No autorizado']); exit;}
    $tipo=trim($_GET['tipo']??''); $empleado_id=(int)($_GET['empleado_id']??0);
    $cols=[]; $rc=$conn->query('SHOW COLUMNS FROM fotos_asistencia'); if($rc){while($c=$rc->fetch_assoc()) $cols[$c['Field']]=true;}
  $colGrupo=isset($cols['grupo_id'])?'grupo_id':(isset($cols['proyecto_id'])?'proyecto_id':null); if(!$colGrupo){echo json_encode(['success'=>false,'error'=>'Sin columna grupo']);exit;}
  $colTipo=isset($cols['tipo_asistencia'])?'tipo_asistencia':(isset($cols['tipo'])?'tipo':null);
  $colEmpId=isset($cols['empleado_id'])?'empleado_id':null; $colEmpNom=isset($cols['empleado_nombre'])?'empleado_nombre':null; $colFecha=isset($cols['fecha_hora'])?'fecha_hora':(isset($cols['created_at'])?'created_at':(isset($cols['fecha'])?'fecha':null));
    $params=[];$types=''; $sql="SELECT fa.*,g.nombre as proyecto_nombre,g.empresa FROM fotos_asistencia fa JOIN grupos g ON fa.$colGrupo=g.id WHERE fa.$colGrupo=?"; $params[]=$proyecto_id;$types.='i';
    if($empleado_id){$conds=[]; if($colEmpId){$conds[]="fa.$colEmpId=?";$params[]=$empleado_id;$types.='i';} if($colEmpNom){$conds[]="fa.$colEmpNom IN (SELECT nombre FROM empleados WHERE id=?)";$params[]=$empleado_id;$types.='i';} if($conds) $sql.=' AND ('.implode(' OR ',$conds).')';}
    if($colFecha) $sql.=" ORDER BY fa.$colFecha DESC"; $sql.=' LIMIT 400';
    $st=$conn->prepare($sql); if(!$st){echo json_encode(['success'=>false,'error'=>'SQL invalido','debug'=>$conn->error]);exit;}
    if($params){$bind=[];$bind[]=$types; foreach($params as $k=>$v){$bind[$k+1]=&$params[$k];} call_user_func_array([$st,'bind_param'],$bind);} if(!$st->execute()){echo json_encode(['success'=>false,'error'=>'Exec fallo','debug'=>$st->error]);exit;}
        $filterSlug = $tipo !== '' ? strtolower($tipo) : '';
        if ($filterSlug === 'reanudar') { $filterSlug = 'reanudo'; }
        $rs=$st->get_result(); $out=[]; while($r=$rs->fetch_assoc()){
      $raw='';
      $candidatas=['foto_procesada','archivo_procesado','foto','imagen','path','archivo'];
      foreach($candidatas as $c){ if(!empty($r[$c])){ $raw=$r[$c]; break; } }
    $raw=str_replace(['..','\\'],['','/'],$raw); $raw=ltrim($raw,'/'); if(strpos($raw,'admin/uploads/asistencias/')===0) $raw=substr($raw,6);
      $fechaRef=$colFecha && !empty($r[$colFecha])? substr($r[$colFecha],0,10):date('Y-m-d');
      $grupoRef=$r[$colGrupo]??$proyecto_id;
      $tipoInfo = $colTipo ? pm_normalize_tipo($r[$colTipo] ?? '') : ['slug' => 'otro', 'label' => 'Evento sin tipo', 'raw' => ''];

      $fsAdmin=dirname(__DIR__).'/admin/'; $fsRoot=dirname(__DIR__).'/'; $final='';
      $candidatosPaths=[];
      $addCandidate=function(string $path) use (&$candidatosPaths){
          if($path===''){return;}
          if(!in_array($path,$candidatosPaths,true)){ $candidatosPaths[]=$path; }
      };

      if($raw!==''){
          $addCandidate($raw);
          if(strpos($raw,'admin/')!==0){
              $addCandidate('admin/'.$raw);
          }
      }

      if($raw!=='' && strpos($raw,'/')===false){
          $base='uploads/asistencias/'.$grupoRef.'/'.$fechaRef.'/';
          $folders=['entradas','salidas','descansos','reanudar','otros'];
          foreach($folders as $folder){
              $addCandidate($base.$folder.'/'.$raw);
              $addCandidate('admin/'.$base.$folder.'/'.$raw);
          }
          $addCandidate($base.$raw);
          $addCandidate('admin/'.$base.$raw);
      }

      foreach($candidatosPaths as $rel){
        if(file_exists($fsAdmin.$rel)){ $final='../admin/'.$rel; break; }
        if(file_exists($fsRoot.$rel)){ $final='../'.$rel; break; }
      }
      if($final===''){
          $final=$raw;
      }

      $folderSlug = $final ? pm_folder_slug_from_path($final) : '';
      if($folderSlug!==''){
          $tipoInfo['slug'] = ($folderSlug === 'reanudar') ? 'reanudo' : $folderSlug;
          $tipoInfo['label'] = pm_label_for_slug($tipoInfo['slug']);
          if($tipoInfo['raw'] === ''){
              $tipoInfo['raw'] = $tipoInfo['label'];
          }
      }

      if($filterSlug !== '' && $tipoInfo['slug'] !== $filterSlug){
          continue;
      }
        // Timestamp y corrección automática fija
            $rawF=$colFecha?($r[$colFecha]??''):''; $loc=''; $fechaIso=null; $timestamp=null;
            if($rawF){
                try{
                    $tz=new DateTimeZone('America/Mexico_City');
                    $dtLocal=new DateTime($rawF,$tz);
                    // Si la hora viene muy en el futuro (1-12h) restar ese exceso primero
                    $now=new DateTime('now',$tz);
                    $diffHours=($dtLocal->getTimestamp()-$now->getTimestamp())/3600;
                    if($diffHours>1 && $diffHours<=12){ $dtLocal->modify('-'.round($diffHours).' hours'); }
                    if($AJUSTE_HORAS_DISPLAY!==0){ $dtLocal->modify(($AJUSTE_HORAS_DISPLAY>0?'+':'').$AJUSTE_HORAS_DISPLAY.' hours'); }
                    $loc=$dtLocal->format('d/m/Y H:i:s');
                    $fechaIso=$dtLocal->format(DateTime::ATOM);
                    $timestamp=$dtLocal->getTimestamp();
                }catch(Exception $e){ $loc=$rawF; }
            }
            $lat=$r['latitud']??($r['lat']??null); $lng=$r['longitud']??($r['lng']??null); $dir=$r['direccion_aproximada']??($r['direccion']??'');
            $employeeId = ($colEmpId && !empty($r[$colEmpId])) ? (int)$r[$colEmpId] : null;
            $out[]=[
                'img'=>$final,
                'empleado'=>$r['empleado_nombre']??($r['empleado']??''),
                'empleado_id'=>$employeeId,
                'tipo'=>$tipoInfo['label'],
                'tipo_slug'=>$tipoInfo['slug'],
                'tipo_label'=>$tipoInfo['label'],
                'tipo_original'=>$tipoInfo['raw'],
                'motivo'=>$r['motivo']??'',
                'fecha_hora'=>$rawF,
                'fecha_local'=>$loc,
                'fecha_legible'=>$loc,
                'fecha_iso'=>$fechaIso,
                'timestamp'=>$timestamp,
                'lat'=>$lat,
                'lng'=>$lng,
                'direccion'=>$dir,
                'proyecto'=>$r['proyecto_nombre']??'',
                'empresa'=>$r['empresa']??''
            ];
    }
    echo json_encode(['success'=>true,'data'=>$out]); exit;
  }
  echo json_encode(['success'=>false,'error'=>'API invalida']); exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fotos de Asistencia - PM Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/pm.css?v=<?= filemtime(__DIR__ . '/../assets/pm.css'); ?>">
</head>
<body>
<?php require_once 'common/navigation.php'; ?>

<div class="pm-page">
    <header class="pm-header">
        <div>
            <h1 class="pm-header__title"><i class="fas fa-camera"></i> Evidencia fotográfica</h1>
            <p class="pm-header__subtitle">Confirma entradas, salidas y descansos con hora real y ubicación en un solo lugar.</p>
            <div class="filter-chips">
                <span class="chip"><i class="fas fa-diagram-project"></i> <?= count($proyectos); ?> proyectos asignados</span>
                <span class="chip"><i class="fas fa-database"></i> Últimos 400 registros disponibles</span>
            </div>
        </div>
        <div class="pm-header__actions">
            <a href="dashboard.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Volver al dashboard</a>
            <a href="asistencias.php" class="btn btn-primary"><i class="fas fa-calendar-check"></i> Asistencias</a>
        </div>
    </header>

    <?php if(empty($proyectos)): ?>
        <div class="pm-section empty-state">
            <i class="fas fa-folder-open"></i>
            <h3>No tienes proyectos asignados</h3>
            <p>Solicita al administrador que vincule tus proyectos para comenzar a revisar la evidencia.</p>
        </div>
    <?php else: ?>
        <section class="pm-section">
            <h3 class="pm-section__title"><i class="fas fa-diagram-project"></i> Selecciona un proyecto</h3>
            <p class="section-subtitle">Elige un proyecto para cargar la lista de colaboradores y sus registros fotográficos.</p>

            <div class="projects-grid" id="projectsGrid">
                <?php foreach($proyectos as $p): ?>
                    <div class="project-card" role="button" tabindex="0"
                         data-project-id="<?= (int)$p['id']; ?>"
                         data-project-name="<?= htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-project-company="<?= htmlspecialchars($p['empresa'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="project-name"><?= htmlspecialchars($p['nombre']); ?></div>
                        <?php if(!empty($p['empresa'])): ?>
                            <div class="project-company"><i class="fas fa-building"></i> <?= htmlspecialchars($p['empresa']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="pm-section details-panel" id="detailsPanel">
            <div class="details-header">
                <div>
                    <h3 class="project-title" id="projectTitle">Selecciona un proyecto</h3>
                    <p class="section-subtitle" id="projectSubtitle">Elige un proyecto para mostrar a tu equipo y filtrar por tipo de evento.</p>
                </div>
            </div>

            <div class="details-content">
                <aside class="sidebar">
                    <div class="sidebar-section">
                        <h4><i class="fas fa-users"></i> Empleados</h4>
                        <div class="employee-list" id="employeeList"></div>
                    </div>

                    <div class="sidebar-section">
                        <h4><i class="fas fa-filter"></i> Tipo de registro</h4>
                        <div class="type-filters">
                            <button class="type-btn" type="button" data-type="entrada">
                                <div class="type-icon entrada"><i class="fas fa-sign-in-alt"></i></div>
                                <span>Entradas</span>
                            </button>
                            <button class="type-btn" type="button" data-type="salida">
                                <div class="type-icon salida"><i class="fas fa-sign-out-alt"></i></div>
                                <span>Salidas</span>
                            </button>
                            <button class="type-btn" type="button" data-type="descanso">
                                <div class="type-icon descanso"><i class="fas fa-mug-hot"></i></div>
                                <span>Descansos</span>
                            </button>
                            <button class="type-btn" type="button" data-type="reanudo">
                                <div class="type-icon reanudo"><i class="fas fa-play"></i></div>
                                <span>Regreso de descanso</span>
                            </button>
                        </div>
                    </div>
                </aside>

                <div class="photos-container" id="photosContainer">
                    <div class="empty-state">
                        <i class="fas fa-filter"></i>
                        <h3>Selecciona un tipo de evento</h3>
                        <p>Elige entrada, salida, descanso o regreso de descanso para visualizar la evidencia correspondiente.</p>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>

<script>
const TYPE_ICONS = {
    entrada: 'sign-in-alt',
    salida: 'sign-out-alt',
    descanso: 'mug-hot',
    reanudo: 'play',
    otro: 'circle-question'
};

const RELATIVE_INTERVALS = [
    { unit: 'year', seconds: 31536000 },
    { unit: 'month', seconds: 2628000 },
    { unit: 'week', seconds: 604800 },
    { unit: 'day', seconds: 86400 },
    { unit: 'hour', seconds: 3600 },
    { unit: 'minute', seconds: 60 },
    { unit: 'second', seconds: 1 }
];

const relativeFormatter = typeof Intl !== 'undefined' && Intl.RelativeTimeFormat ? new Intl.RelativeTimeFormat('es', { numeric: 'auto' }) : null;
const dateFormatter = typeof Intl !== 'undefined' && Intl.DateTimeFormat ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : null;

let selectedProject = null;
let selectedEmployee = 0;
let selectedType = '';

const projectCards = document.querySelectorAll('.project-card');
const detailsPanel = document.getElementById('detailsPanel');
const projectTitle = document.getElementById('projectTitle');
const projectSubtitle = document.getElementById('projectSubtitle');

projectCards.forEach(card => {
    card.addEventListener('click', () => handleProjectSelection(card));
    card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            handleProjectSelection(card);
        }
    });
});

document.querySelectorAll('.type-btn').forEach(btn => {
    btn.addEventListener('click', () => selectType(btn.dataset.type));
});

function handleProjectSelection(card) {
    if (!card) return;
    selectProject(card);
}

function selectProject(card) {
    const id = parseInt(card.dataset.projectId, 10);
    if (!id) return;

    selectedProject = id;
    selectedEmployee = 0;
    selectedType = '';

    document.querySelectorAll('.project-card').forEach(el => el.classList.remove('selected'));
    card.classList.add('selected');

    if (detailsPanel) {
        detailsPanel.style.display = 'block';
    }

    if (projectTitle) {
        projectTitle.textContent = `Proyecto: ${card.dataset.projectName || 'Sin nombre'}`;
    }

    if (projectSubtitle) {
        const company = card.dataset.projectCompany || '';
        projectSubtitle.textContent = company
            ? `Empresa: ${company}. Selecciona a la persona y el tipo de registro que quieres revisar.`
            : 'Selecciona a la persona y el tipo de registro que quieres revisar.';
    }

    loadEmployees(id);

    const photosContainer = document.getElementById('photosContainer');
    if (photosContainer) {
        photosContainer.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-filter"></i>
                <h3>Selecciona un tipo de evento</h3>
                <p>Elige entrada, salida, descanso o regreso de descanso para ver la evidencia de tu equipo.</p>
            </div>
        `;
    }

    document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('selected'));
}

function loadEmployees(projectId) {
    const container = document.getElementById('employeeList');
    if (!container) return;

    container.innerHTML = `
        <div class="loading-state" style="padding:32px 16px;">
            <i class="fas fa-spinner"></i>
            <h3>Cargando equipo...</h3>
            <p>Recuperando los colaboradores asignados al proyecto.</p>
        </div>
    `;

    fetch(`fotos_asistencia.php?api=employees&proyecto_id=${projectId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                container.innerHTML = `
                    <div class="error-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>No se pudo cargar la información</h3>
                        <p>${escapeHtml(data.error || 'Intenta de nuevo en unos segundos')}</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = '';
            container.appendChild(createEmployeeOption({ id: 0, nombre: 'Todos los empleados', isAll: true }));

            if (!data.data.length) {
                const message = document.createElement('div');
                message.className = 'empty-state';
                message.style.padding = '32px 16px';
                message.innerHTML = `
                    <i class="fas fa-user-slash"></i>
                    <h3>Sin colaboradores activos</h3>
                    <p>No hay empleados activos asignados a este proyecto.</p>
                `;
                container.appendChild(message);
            } else {
                data.data.forEach(employee => {
                    container.appendChild(createEmployeeOption(employee));
                });
            }

            selectEmployee(0, { skipReload: true });
        })
        .catch(err => {
            container.innerHTML = `
                <div class="error-state">
                    <i class="fas fa-wifi"></i>
                    <h3>Sin conexión</h3>
                    <p>${escapeHtml(err.message)}</p>
                </div>
            `;
        });
}

function createEmployeeOption(employee) {
    const wrapper = document.createElement('div');
    wrapper.className = 'employee-option';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'employee-btn';
    btn.dataset.employeeId = employee.id;
    btn.innerHTML = `<i class="fas ${employee.isAll ? 'fa-users' : 'fa-user'}"></i> ${escapeHtml(employee.nombre)}`;
    btn.addEventListener('click', () => selectEmployee(employee.id));
    wrapper.appendChild(btn);

    if (!employee.isAll && employee.id) {
        const link = document.createElement('a');
        link.href = `empleados.php?focus=${employee.id}`;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.className = 'employee-link';
        link.title = 'Abrir ficha del trabajador';
        link.innerHTML = '<i class="fas fa-arrow-up-right-from-square"></i>';
        wrapper.appendChild(link);
    }

    return wrapper;
}

function selectEmployee(id, options = {}) {
    selectedEmployee = parseInt(id, 10) || 0;

    document.querySelectorAll('.employee-btn').forEach(btn => btn.classList.remove('selected'));
    const current = document.querySelector(`.employee-btn[data-employee-id="${selectedEmployee}"]`);
    if (current) {
        current.classList.add('selected');
    }

    if (selectedType && !options.skipReload) {
        loadPhotos();
    }
}

function selectType(type) {
    if (!type || !selectedProject) return;

    selectedType = type;
    document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('selected'));
    const current = document.querySelector(`.type-btn[data-type="${type}"]`);
    if (current) {
        current.classList.add('selected');
    }

    loadPhotos();
}

function loadPhotos() {
    if (!selectedProject || !selectedType) return;

    const container = document.getElementById('photosContainer');
    if (!container) return;

    container.innerHTML = `
        <div class="loading-state">
            <i class="fas fa-spinner"></i>
            <h3>Cargando fotos...</h3>
            <p>Estamos preparando la evidencia para los filtros seleccionados.</p>
        </div>
    `;

    const url = new URL('fotos_asistencia.php', window.location.href);
    url.searchParams.set('api', 'photos');
    url.searchParams.set('proyecto_id', selectedProject);
    url.searchParams.set('tipo', selectedType);
    url.searchParams.set('empleado_id', selectedEmployee || 0);

    fetch(url.toString())
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                container.innerHTML = `
                    <div class="error-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error al cargar</h3>
                        <p>${escapeHtml(data.error || 'No se pudo recuperar la evidencia')}</p>
                    </div>
                `;
                return;
            }

            if (!data.data.length) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-images"></i>
                        <h3>Sin registros para los filtros</h3>
                        <p>Prueba con otro colaborador o selecciona otro tipo de evento.</p>
                    </div>
                `;
                return;
            }

            const grid = document.createElement('div');
            grid.className = 'photos-grid';

            data.data.forEach(photo => {
                const card = document.createElement('div');
                card.className = 'photo-card';

                const photoUrl = photo.img ? encodeURI(photo.img) : '';
                const imageMarkup = photoUrl
                    ? `<div class="photo-container">
                            <a href="${photoUrl}" target="_blank" rel="noopener noreferrer">
                                <img src="${photoUrl}" alt="Foto de asistencia" loading="lazy">
                            </a>
                       </div>`
                    : `<div class="photo-placeholder"><i class="fas fa-image"></i></div>`;

                const typeSlug = (photo.tipo_slug || '').toLowerCase();
                const typeClass = typeSlug || 'otro';
                const typeLabel = photo.tipo_label || photo.tipo || 'Evento';
                const typeIcon = TYPE_ICONS[typeClass] || TYPE_ICONS.otro;

                const absoluteTime = formatDateTime(photo);
                const relativeTime = formatRelativeTime(photo.timestamp);
                const relativeMarkup = relativeTime ? `
                    <div class="detail-row">
                        <i class="fas fa-clock-rotate-left detail-icon"></i>
                        <span>${escapeHtml(relativeTime)}</span>
                    </div>
                ` : '';

                const motivoMarkup = photo.motivo ? `
                    <div class="detail-row">
                        <i class="fas fa-comment detail-icon"></i>
                        <span>${escapeHtml(photo.motivo)}</span>
                    </div>
                ` : '';

                const direccionMarkup = photo.direccion ? `
                    <div class="detail-row">
                        <i class="fas fa-map-marker-alt detail-icon"></i>
                        <span>${escapeHtml(photo.direccion)}</span>
                    </div>
                ` : '';

                const mapButton = (photo.lat && photo.lng) ? `
                    <a href="https://www.google.com/maps?q=${encodeURIComponent(photo.lat)},${encodeURIComponent(photo.lng)}" target="_blank" rel="noopener noreferrer" class="btn-small btn-map">
                        <i class="fas fa-map-location-dot"></i> Mapa
                    </a>
                ` : '';

                const viewPhotoButton = photoUrl ? `
                    <a href="${photoUrl}" target="_blank" rel="noopener noreferrer" class="btn-small btn-view">
                        <i class="fas fa-eye"></i> Ver evidencia
                    </a>
                ` : '';

                const employeeLink = photo.empleado_id ? `
                    <a href="empleados.php?focus=${photo.empleado_id}" target="_blank" rel="noopener noreferrer" class="btn-small btn-view">
                        <i class="fas fa-id-badge"></i> Ver trabajador
                    </a>
                ` : '';

                card.innerHTML = `
                    ${imageMarkup}
                    <div class="photo-info">
                        <div class="photo-employee">${escapeHtml(photo.empleado || 'Sin empleado')}</div>
                        <div class="photo-details">
                            <div class="detail-row">
                                <i class="fas fa-calendar-day detail-icon"></i>
                                <span>${escapeHtml(absoluteTime)}</span>
                            </div>
                            ${relativeMarkup}
                            <div class="detail-row">
                                <i class="fas fa-tag detail-icon"></i>
                                <span class="type-badge ${typeClass}" title="${escapeHtml(photo.tipo_original || typeLabel)}">
                                    <i class="fas fa-${typeIcon}"></i>
                                    ${escapeHtml(typeLabel)}
                                </span>
                            </div>
                            ${motivoMarkup}
                            ${direccionMarkup}
                        </div>
                        <div class="photo-actions">
                            ${viewPhotoButton}
                            ${mapButton}
                            ${employeeLink}
                        </div>
                    </div>
                `;

                grid.appendChild(card);
            });

            container.innerHTML = '';
            container.appendChild(grid);
        })
        .catch(err => {
            container.innerHTML = `
                <div class="error-state">
                    <i class="fas fa-wifi"></i>
                    <h3>Error de conexión</h3>
                    <p>${escapeHtml(err.message)}</p>
                </div>
            `;
        });
}

function formatDateTime(photo) {
    if (photo.fecha_legible) {
        return photo.fecha_legible;
    }
    if (photo.fecha_local) {
        return photo.fecha_local;
    }
    if (photo.fecha_iso) {
        try {
            const date = new Date(photo.fecha_iso);
            return dateFormatter ? dateFormatter.format(date) : date.toLocaleString('es-MX');
        } catch (err) {
            return photo.fecha_iso;
        }
    }
    if (photo.fecha_hora) {
        return photo.fecha_hora;
    }
    return 'Sin fecha';
}

function formatRelativeTime(timestamp) {
    if (!timestamp || !Number.isFinite(timestamp)) {
        return '';
    }

    const diffSeconds = Math.round((timestamp * 1000 - Date.now()) / 1000);

    for (const interval of RELATIVE_INTERVALS) {
        if (Math.abs(diffSeconds) >= interval.seconds || interval.unit === 'second') {
            const value = Math.round(diffSeconds / interval.seconds);
            if (relativeFormatter) {
                return relativeFormatter.format(value, interval.unit);
            }
            const absolute = Math.abs(value);
            const label = interval.unit.replace(/e?$/, absolute === 1 ? '' : 's');
            return value < 0 ? `hace ${absolute} ${label}` : `en ${absolute} ${label}`;
        }
    }

    return '';
}

function escapeHtml(value) {
    if (value === null || value === undefined) {
        return '';
    }
    const div = document.createElement('div');
    div.textContent = value;
    return div.innerHTML;
}

const preProject = <?= (int)$proyecto_preseleccion ?>;
const preTypeRaw = <?= json_encode($tipo_preseleccion, JSON_UNESCAPED_UNICODE) ?>;
const allowedTypes = ['entrada', 'salida', 'descanso', 'reanudo'];

if (preProject) {
    const preCard = document.querySelector(`.project-card[data-project-id="${preProject}"]`);
    if (preCard) {
        setTimeout(() => {
            selectProject(preCard);
            let normalizedType = (preTypeRaw || '').toLowerCase();
            if (normalizedType === 'reanudar' || normalizedType === 'regreso' || normalizedType === 'regreso de descanso') {
                normalizedType = 'reanudo';
            }
            if (allowedTypes.includes(normalizedType)) {
                setTimeout(() => selectType(normalizedType), 400);
            }
        }, 200);
    }
}
</script>

</body>
</html>