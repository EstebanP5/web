<?php
session_start();
if(!isset($_SESSION['user_id']) || $_SESSION['user_rol']!=='pm'){ header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../includes/db.php';
$pm=(int)$_SESSION['user_id'];

// Obtener proyectos con estadísticas
$proyectos=[];
$st=$conn->prepare("
    SELECT g.*, 
           COUNT(DISTINCT ep.empleado_id) as empleados_count,
           COUNT(DISTINCT CASE WHEN a.fecha = CURDATE() THEN a.empleado_id END) as asistencias_hoy,
           DATEDIFF(COALESCE(g.fecha_fin, CURDATE()), CURDATE()) as dias_restantes
    FROM proyectos_pm ppm 
    JOIN grupos g ON ppm.proyecto_id=g.id 
    LEFT JOIN empleado_proyecto ep ON ep.proyecto_id=g.id AND ep.activo=1
    LEFT JOIN asistencia a ON a.proyecto_id=g.id AND a.empleado_id=ep.empleado_id
    WHERE ppm.user_id=? AND g.activo=1 
    GROUP BY g.id 
    ORDER BY g.nombre
");
$st->bind_param('i',$pm); $st->execute(); $rs=$st->get_result(); 
while($p=$rs->fetch_assoc()) $proyectos[]=$p;

// Filtros
$search = $_GET['search'] ?? '';
$view = $_GET['view'] ?? 'grid';
if($search) {
    $proyectos = array_filter($proyectos, function($p) use ($search) {
        return stripos($p['nombre'], $search) !== false || 
               stripos($p['empresa'], $search) !== false || 
               stripos($p['localidad'], $search) !== false;
    });
}
$pmCssPath = __DIR__ . '/../assets/pm.css';
$pmCssVersion = file_exists($pmCssPath) ? filemtime($pmCssPath) : time();
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Mis Proyectos - PM Dashboard</title><meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../assets/pm.css?v=<?= $pmCssVersion; ?>">
</head><body>
<?php require_once 'common/navigation.php'; ?>
<style>
:root {
    --primary-color: #3b82f6;
    --primary-dark: #1d4ed8;
    --success-color: #10b981;
    --success-dark: #059669;
    --warning-color: #f59e0b;
    --warning-dark: #d97706;
    --danger-color: #ef4444;
    --danger-dark: #dc2626;
    --gray-50: #f8fafc;
    --gray-100: #f1f5f9;
    --gray-200: #e2e8f0;
    --gray-300: #cbd5e1;
    --gray-400: #94a3b8;
    --gray-500: #64748b;
    --gray-600: #475569;
    --gray-700: #334155;
    --gray-800: #1e293b;
    --gray-900: #0f172a;
}


body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--gray-50);
    margin: 0;
    color: var(--gray-900);
    transition: all 0.3s ease;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 32px 24px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 24px;
}

.header-content h1 {
    font-size: 32px;
    font-weight: 700;
    margin: 0;
    color: var(--gray-900);
    display: flex;
    align-items: center;
    gap: 16px;
}

.header-content p {
    margin: 8px 0 0;
    color: var(--gray-600);
    font-size: 16px;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.search-box {
    position: relative;
    min-width: 300px;
}

.search-box input {
    width: 100%;
    padding: 12px 16px 12px 48px;
    border: 2px solid var(--gray-200);
    border-radius: 12px;
    font-size: 14px;
    transition: all 0.3s;
    background: white;
    color: var(--gray-900);
}

.search-box input:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.search-box i {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--gray-500);
}

.view-toggle {
    display: flex;
    background: var(--gray-100);
    border-radius: 10px;
    padding: 4px;
}

.view-btn {
    padding: 8px 16px;
    border: none;
    background: none;
    border-radius: 6px;
    cursor: pointer;
    color: var(--gray-600);
    transition: all 0.3s;
    font-weight: 500;
}

.view-btn.active {
    background: white;
    color: var(--primary-color);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.projects-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.stat-card {
    background: white;
    padding: 24px;
    border-radius: 16px;
    border: 1px solid var(--gray-200);
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    transition: all 0.3s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
}

.stat-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--primary-color);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 14px;
    color: var(--gray-600);
    font-weight: 500;
}

.projects-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 24px;
}

.projects-list {
    background: white;
    border-radius: 16px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.project-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 20px;
    padding: 28px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
}

.project-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary-color), var(--success-color));
}

.project-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.12);
    border-color: var(--primary-color);
}

.project-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
}

.project-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: 6px;
    line-height: 1.3;
}

.project-company {
    font-size: 14px;
    color: var(--gray-600);
    display: flex;
    align-items: center;
    gap: 6px;
}

.project-status {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    background: #dcfce7;
    color: #166534;
    display: flex;
    align-items: center;
    gap: 6px;
}

.project-status.ending-soon {
    background: #fef3c7;
    color: #92400e;
}

.project-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}

.project-stat {
    text-align: center;
    padding: 16px;
    background: var(--gray-50);
    border-radius: 12px;
    border: 1px solid var(--gray-200);
}

.project-stat-value {
    font-size: 20px;
    font-weight: 700;
    color: var(--primary-color);
    margin-bottom: 4px;
}

.project-stat-label {
    font-size: 12px;
    color: var(--gray-600);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.project-details {
    margin-bottom: 24px;
}

.detail-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
    font-size: 14px;
    color: var(--gray-700);
}

.detail-row i {
    width: 16px;
    color: var(--gray-500);
}

.project-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn {
    padding: 10px 16px;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 38px;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
}

.btn-success {
    background: linear-gradient(135deg, var(--success-color), var(--success-dark));
    color: white;
}

.btn-warning {
    background: linear-gradient(135deg, var(--warning-color), var(--warning-dark));
    color: white;
}

.btn-secondary {
    background: var(--gray-100);
    color: var(--gray-700);
    border: 1px solid var(--gray-200);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
}

.empty-state i {
    font-size: 64px;
    color: var(--gray-400);
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 20px;
    margin-bottom: 8px;
    color: var(--gray-700);
}

.empty-state p {
    color: var(--gray-600);
}

.list-item {
    padding: 20px 24px;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s;
}

.list-item:hover {
    background: var(--gray-50);
}

.list-item:last-child {
    border-bottom: none;
}

@media (max-width: 768px) {
    .container {
        padding: 20px 16px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .header-actions {
        justify-content: space-between;
    }
    
    .search-box {
        min-width: auto;
        flex: 1;
    }
    
    .projects-grid {
        grid-template-columns: 1fr;
    }
    
    .project-header {
        flex-direction: column;
        gap: 12px;
    }
    
    .project-stats {
        grid-template-columns: 1fr;
    }
}
</style>
<div class="container">
    <div class="page-header">
        <div class="header-content">
            <h1><i class="fas fa-project-diagram"></i> Mis Proyectos</h1>
            <p>Gestiona y supervisa todos tus proyectos asignados</p>
        </div>
        <div class="header-actions">
            <a href="forms/crear_proyecto.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Nuevo Proyecto
            </a>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Buscar proyectos..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="view-toggle">
                <button class="view-btn <?= $view === 'grid' ? 'active' : '' ?>" onclick="setView('grid')">
                    <i class="fas fa-th-large"></i>
                </button>
                <button class="view-btn <?= $view === 'list' ? 'active' : '' ?>" onclick="setView('list')">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>
    </div>

    <?php if(!empty($proyectos)): ?>
    <div class="projects-stats">
        <div class="stat-card">
            <div class="stat-value"><?= count($proyectos) ?></div>
            <div class="stat-label">Proyectos Activos</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= array_sum(array_column($proyectos, 'empleados_count')) ?></div>
            <div class="stat-label">Total Empleados</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= array_sum(array_column($proyectos, 'asistencias_hoy')) ?></div>
            <div class="stat-label">Asistencias Hoy</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= count(array_filter($proyectos, function($p) { return $p['dias_restantes'] <= 7 && $p['dias_restantes'] >= 0; })) ?></div>
            <div class="stat-label">Finalizan Pronto</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if(empty($proyectos)): ?>
    <div class="empty-state">
        <i class="fas fa-project-diagram"></i>
        <h3>No tienes proyectos asignados</h3>
        <p>Crea tu primer proyecto con el botón "Nuevo Proyecto" o solicita acceso al administrador.</p>
    </div>
    <?php else: ?>
    
    <div class="projects-container" id="projectsContainer">
        <div class="projects-<?= $view ?>" id="projectsView">
            <?php foreach($proyectos as $p): 
                $status_class = '';
                $status_text = 'Activo';
                $status_icon = 'fa-check-circle';
                
                if($p['dias_restantes'] <= 7 && $p['dias_restantes'] >= 0) {
                    $status_class = 'ending-soon';
                    $status_text = 'Finaliza Pronto';
                    $status_icon = 'fa-clock';
                }
            ?>
                <?php if($view === 'grid'): ?>
                <div class="project-card" data-search="<?= strtolower($p['nombre'].' '.$p['empresa'].' '.$p['localidad']) ?>">
                    <div class="project-header">
                        <div>
                            <div class="project-title"><?= htmlspecialchars($p['nombre']) ?></div>
                            <div class="project-company">
                                <i class="fas fa-building"></i>
                                <?= htmlspecialchars($p['empresa']) ?>
                            </div>
                        </div>
                        <div class="project-status <?= $status_class ?>">
                            <i class="fas <?= $status_icon ?>"></i>
                            <?= $status_text ?>
                        </div>
                    </div>
                    
                    <div class="project-stats">
                        <div class="project-stat">
                            <div class="project-stat-value"><?= $p['empleados_count'] ?></div>
                            <div class="project-stat-label">Empleados</div>
                        </div>
                        <div class="project-stat">
                            <div class="project-stat-value"><?= $p['asistencias_hoy'] ?></div>
                            <div class="project-stat-label">Presentes Hoy</div>
                        </div>
                    </div>
                    
                    <div class="project-details">
                        <?php if($p['localidad']): ?>
                        <div class="detail-row">
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?= htmlspecialchars($p['localidad']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if($p['fecha_inicio']): ?>
                        <div class="detail-row">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Inicio: <?= date('d/m/Y', strtotime($p['fecha_inicio'])) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if($p['fecha_fin']): ?>
                        <div class="detail-row">
                            <i class="fas fa-calendar-times"></i>
                            <span>Fin: <?= date('d/m/Y', strtotime($p['fecha_fin'])) ?></span>
                            <?php if($p['dias_restantes'] >= 0): ?>
                                <span style="color: var(--warning-color); font-weight: 600; margin-left: 8px;">
                                    (<?= $p['dias_restantes'] ?> días restantes)
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="project-actions">
                        <a href="asistencias.php?proyecto=<?= $p['id'] ?>" class="btn btn-primary">
                            <i class="fas fa-calendar-check"></i> Asistencias
                        </a>
                        <a href="empleados.php?proyecto=<?= $p['id'] ?>" class="btn btn-secondary">
                            <i class="fas fa-users"></i> Equipo
                        </a>
                        <a href="proyecto_equipo.php?id=<?= $p['id'] ?>" class="btn btn-success">
                            <i class="fas fa-user-cog"></i> Gestionar
                        </a>
                        <a href="fotos_asistencia.php?proyecto=<?= $p['id'] ?>" class="btn btn-secondary">
                            <i class="fas fa-camera"></i> Fotos
                        </a>
                        <?php if($p['lat'] && $p['lng']): ?>
                        <a href="https://maps.google.com/?q=<?= $p['lat'] ?>,<?= $p['lng'] ?>" target="_blank" class="btn btn-warning">
                            <i class="fas fa-map-marker-alt"></i> Ubicación
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="list-item" data-search="<?= strtolower($p['nombre'].' '.$p['empresa'].' '.$p['localidad']) ?>">
                    <div>
                        <div class="project-title" style="margin-bottom: 8px;"><?= htmlspecialchars($p['nombre']) ?></div>
                        <div style="display: flex; gap: 20px; font-size: 14px; color: var(--gray-600);">
                            <span><i class="fas fa-building"></i> <?= htmlspecialchars($p['empresa']) ?></span>
                            <?php if($p['localidad']): ?>
                            <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($p['localidad']) ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-users"></i> <?= $p['empleados_count'] ?> empleados</span>
                            <span><i class="fas fa-calendar-check"></i> <?= $p['asistencias_hoy'] ?> presentes</span>
                        </div>
                    </div>
                    <div class="project-actions">
                        <a href="asistencias.php?proyecto=<?= $p['id'] ?>" class="btn btn-primary">
                            <i class="fas fa-calendar-check"></i>
                        </a>
                        <a href="empleados.php?proyecto=<?= $p['id'] ?>" class="btn btn-secondary">
                            <i class="fas fa-users"></i>
                        </a>
                        <a href="proyecto_equipo.php?id=<?= $p['id'] ?>" class="btn btn-success" title="Gestionar equipo">
                            <i class="fas fa-user-cog"></i>
                        </a>
                        <?php if($p['lat'] && $p['lng']): ?>
                        <a href="https://maps.google.com/?q=<?= $p['lat'] ?>,<?= $p['lng'] ?>" target="_blank" class="btn btn-warning">
                            <i class="fas fa-map-marker-alt"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function setView(view) {
    const url = new URL(window.location);
    url.searchParams.set('view', view);
    window.location = url;
}

function performSearch() {
    const searchTerm = document.getElementById('searchInput').value;
    const url = new URL(window.location);
    if (searchTerm) {
        url.searchParams.set('search', searchTerm);
    } else {
        url.searchParams.delete('search');
    }
    window.location = url;
}

// Real-time search
document.getElementById('searchInput').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const projects = document.querySelectorAll('[data-search]');
    
    projects.forEach(project => {
        const searchData = project.getAttribute('data-search');
        if (searchData.includes(searchTerm)) {
            project.style.display = '';
        } else {
            project.style.display = 'none';
        }
    });
});

// Enter key search
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        performSearch();
    }
});

// Auto-focus search on Ctrl+F
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        document.getElementById('searchInput').focus();
    }
});
</script>

</body></html>
