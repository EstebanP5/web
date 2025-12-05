<?php
// Componente de navegación unificada para Responsables
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$user_name = $_SESSION['user_name'] ?? '';

$scriptDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? ''));
$respBaseUri = rtrim($scriptDir, '/');
if ($respBaseUri === '') {
    $respBaseUri = '/';
}
$respBaseUri = preg_replace('#/common$#', '', $respBaseUri);

if (!function_exists('resp_nav_path')) {
    function resp_nav_path(string $baseUri, string $path): string
    {
        if ($baseUri === '' || $baseUri === '/') {
            return '/' . ltrim($path, '/');
        }
        return rtrim($baseUri, '/') . '/' . ltrim($path, '/');
    }
}
?>

<nav class="resp-navigation">
    <div class="nav-container">
        <div class="nav-brand">
            <i class="fas fa-building"></i>
            <span>Portal Responsable</span>
        </div>
        
        <div class="nav-menu">
            <a href="<?= htmlspecialchars(resp_nav_path($respBaseUri, 'index.php')) ?>" class="nav-item <?= $current_page === 'index' || $current_page === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="<?= htmlspecialchars(resp_nav_path($respBaseUri, 'trabajadores.php')) ?>" class="nav-item <?= $current_page === 'trabajadores' ? 'active' : '' ?>">
                <i class="fas fa-users"></i>
                <span>Trabajadores</span>
            </a>
            <a href="<?= htmlspecialchars(resp_nav_path($respBaseUri, 'suas.php')) ?>" class="nav-item <?= $current_page === 'suas' ? 'active' : '' ?>">
                <i class="fas fa-file-invoice"></i>
                <span>SUAs</span>
            </a>
        </div>
        
        <div class="nav-user">
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?= htmlspecialchars($user_name) ?></span>
            </div>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</nav>

<style>
.resp-navigation {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    padding: 0 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.15);
    position: sticky;
    top: 0;
    z-index: 1000;
}

.resp-navigation .nav-container {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 60px;
}

.resp-navigation .nav-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    color: white;
    font-weight: 700;
    font-size: 18px;
}

.resp-navigation .nav-brand i {
    font-size: 24px;
}

.resp-navigation .nav-menu {
    display: flex;
    align-items: center;
    gap: 5px;
}

.resp-navigation .nav-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    color: rgba(255,255,255,0.85);
    text-decoration: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
}

.resp-navigation .nav-item:hover {
    background: rgba(255,255,255,0.15);
    color: white;
}

.resp-navigation .nav-item.active {
    background: rgba(255,255,255,0.25);
    color: white;
}

.resp-navigation .nav-item i {
    font-size: 16px;
}

.resp-navigation .nav-user {
    display: flex;
    align-items: center;
    gap: 15px;
}

.resp-navigation .user-info {
    display: flex;
    align-items: center;
    gap: 8px;
    color: white;
    font-size: 14px;
}

.resp-navigation .user-info i {
    font-size: 20px;
}

.resp-navigation .nav-logout {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    background: rgba(255,255,255,0.15);
    color: white;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.2s ease;
}

.resp-navigation .nav-logout:hover {
    background: rgba(239,68,68,0.9);
}

/* Responsive */
@media (max-width: 900px) {
    .resp-navigation .nav-container {
        flex-wrap: wrap;
        height: auto;
        padding: 10px 0;
        gap: 10px;
    }
    
    .resp-navigation .nav-menu {
        order: 3;
        width: 100%;
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .resp-navigation .nav-item span {
        display: none;
    }
    
    .resp-navigation .nav-item {
        padding: 10px 14px;
    }
}

@media (max-width: 600px) {
    .resp-navigation .nav-brand span {
        display: none;
    }
    
    .resp-navigation .user-info span {
        display: none;
    }
}
</style>
