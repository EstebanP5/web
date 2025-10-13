<?php
require_once __DIR__ . '/admin_init.php';

$pageTitle = $pageTitle ?? 'Panel de Administración';
$activePage = $activePage ?? '';
$pageHeading = $pageHeading ?? $pageTitle;
$pageDescription = $pageDescription ?? '';
$headerActions = $headerActions ?? [];

function admin_is_active(string $slug, string $current): string {
    return $slug === $current ? ' is-active' : '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="../assets/admin.css?v=1" rel="stylesheet">
</head>
<body>
    <div class="admin-layout">
        <aside class="admin-sidebar">
            <div class="admin-sidebar__brand">
                <div class="admin-sidebar__mark">
                    <i class="fas fa-hard-hat"></i>
                </div>
                <div>
                    <span class="admin-sidebar__title">ErgoCuida</span>
                    <span class="admin-sidebar__subtitle">Administración</span>
                </div>
            </div>
            <nav class="admin-sidebar__nav">
                <a class="admin-sidebar__link<?php echo admin_is_active('dashboard', $activePage); ?>" href="admin.php">
                    <i class="fas fa-gauge"></i>
                    <span>Panel</span>
                </a>
                <a class="admin-sidebar__link<?php echo admin_is_active('projects', $activePage); ?>" href="proyectos.php">
                    <i class="fas fa-project-diagram"></i>
                    <span>Proyectos</span>
                </a>
                <a class="admin-sidebar__link<?php echo admin_is_active('pm', $activePage); ?>" href="project_managers.php">
                    <i class="fas fa-user-tie"></i>
                    <span>Project Managers</span>
                </a>
                <a class="admin-sidebar__link<?php echo admin_is_active('employees', $activePage); ?>" href="empleados.php">
                    <i class="fas fa-users"></i>
                    <span>Servicios Especializados</span>
                </a>
                <a class="admin-sidebar__link<?php echo admin_is_active('attendance', $activePage); ?>" href="asistencias_mejorado.php">
                    <i class="fas fa-calendar-check"></i>
                    <span>Asistencia</span>
                </a>
                <a class="admin-sidebar__link<?php echo admin_is_active('sua', $activePage); ?>" href="procesar_sua_auto.php">
                    <i class="fas fa-shield-alt"></i>
                    <span>SUA Automático</span>
                </a>
                <a class="admin-sidebar__link<?php echo admin_is_active('videos', $activePage); ?>" href="videos.php">
                    <i class="fas fa-video"></i>
                    <span>Videos</span>
                </a>
            </nav>
            <div class="admin-sidebar__footer">
                <span class="admin-sidebar__user">
                    <i class="fas fa-user-shield"></i>
                    <?php echo htmlspecialchars($currentUserName); ?>
                </span>
                <a class="admin-btn admin-btn--ghost" href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    Salir
                </a>
            </div>
        </aside>
        <div class="admin-main">
            <header class="admin-topbar">
                <div>
                    <h1><?php echo htmlspecialchars($pageHeading); ?></h1>
                    <?php if ($pageDescription): ?>
                        <p><?php echo htmlspecialchars($pageDescription); ?></p>
                    <?php endif; ?>
                </div>
                <?php if (!empty($headerActions)): ?>
                    <div class="admin-topbar__actions">
                        <?php foreach ($headerActions as $action): ?>
                            <?php
                                $actionHref = $action['href'] ?? '#';
                                $actionIcon = $action['icon'] ?? '';
                                $actionLabel = $action['label'] ?? '';
                                $variant = $action['variant'] ?? 'primary';
                            ?>
                            <a class="admin-btn admin-btn--<?php echo htmlspecialchars($variant); ?>" href="<?php echo htmlspecialchars($actionHref); ?>">
                                <?php if ($actionIcon): ?>
                                    <i class="fas <?php echo htmlspecialchars($actionIcon); ?>"></i>
                                <?php endif; ?>
                                <span><?php echo htmlspecialchars($actionLabel); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </header>
            <main class="admin-content">
