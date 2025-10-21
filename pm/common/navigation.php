<?php
// Componente de navegación unificada para PM
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$user_name = $_SESSION['user_name'] ?? '';

$scriptDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? ''));
$pmBaseUri = rtrim($scriptDir, '/');
if ($pmBaseUri === '') {
    $pmBaseUri = '/';
}
$pmBaseUri = preg_replace('#/forms$#', '', $pmBaseUri);

if (!function_exists('pm_nav_path')) {
    function pm_nav_path(string $baseUri, string $path): string
    {
        if ($baseUri === '' || $baseUri === '/') {
            return '/' . ltrim($path, '/');
        }
        return rtrim($baseUri, '/') . '/' . ltrim($path, '/');
    }
}
?>

<nav class="pm-navigation">
    <div class="nav-container">
        <div class="nav-brand">
            <i class="fas fa-user-tie"></i>
            <span>Project Manager</span>
        </div>
        
        <div class="nav-menu">
            <a href="<?= htmlspecialchars(pm_nav_path($pmBaseUri, 'dashboard.php')) ?>" class="nav-item <?= $current_page === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="<?= htmlspecialchars(pm_nav_path($pmBaseUri, 'proyectos.php')) ?>" class="nav-item <?= $current_page === 'proyectos' ? 'active' : '' ?>">
                <i class="fas fa-project-diagram"></i>
                <span>Proyectos</span>
            </a>
            <a href="<?= htmlspecialchars(pm_nav_path($pmBaseUri, 'empleados.php')) ?>" class="nav-item <?= $current_page === 'empleados' ? 'active' : '' ?>">
                <i class="fas fa-users"></i>
                <span>Servicios Especializados</span>
            </a>
            <a href="<?= htmlspecialchars(pm_nav_path($pmBaseUri, 'asistencias.php')) ?>" class="nav-item <?= $current_page === 'asistencias' ? 'active' : '' ?>">
                <i class="fas fa-calendar-check"></i>
                <span>Asistencias</span>
            </a>
            <a href="<?= htmlspecialchars(pm_nav_path($pmBaseUri, 'fotos_asistencia.php')) ?>" class="nav-item <?= $current_page === 'fotos_asistencia' ? 'active' : '' ?>">
                <i class="fas fa-camera"></i>
                <span>Fotos</span>
            </a>
            <a href="../common/videos.php" class="nav-item">
                <i class="fas fa-video"></i>
                <span>Videos</span>
            </a>
        </div>

        <div class="nav-user">
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars(explode(' ', $user_name)[0]) ?></div>
                <div class="user-role">PM</div>
            </div>
            <a href="<?= htmlspecialchars(pm_nav_path($pmBaseUri, '../logout.php')) ?>" class="nav-logout">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
        
        <button class="nav-toggle" onclick="toggleMobileMenu()">
            <i class="fas fa-bars"></i>
        </button>
    </div>
</nav>


<script>
function toggleMobileMenu() {
    const menu = document.querySelector('.nav-menu');
    menu.classList.toggle('active');
}

// Close mobile menu when clicking outside
document.addEventListener('click', function(e) {
    const nav = document.querySelector('.pm-navigation');
    const menu = document.querySelector('.nav-menu');
    const toggle = document.querySelector('.nav-toggle');
    
    if (!nav.contains(e.target) && menu.classList.contains('active')) {
        menu.classList.remove('active');
    }
});
</script>