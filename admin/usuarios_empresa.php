<?php
require_once __DIR__ . '/includes/admin_init.php';

// Asegurar columna empresa en tabla users
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS empresa VARCHAR(100) DEFAULT NULL");

$mensaje_exito = $_SESSION['flash_success'] ?? '';
$mensaje_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$empresas_disponibles = ['CEDISA', 'Stone', 'Remedios'];

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        switch ($accion) {
            case 'crear':
                $nombre = trim($_POST['nombre'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';
                $empresa = trim($_POST['empresa'] ?? '');
                
                if ($nombre === '' || $email === '' || $password === '') {
                    throw new RuntimeException('Nombre, correo y contraseña son obligatorios.');
                }
                if (!in_array($empresa, $empresas_disponibles, true)) {
                    throw new RuntimeException('Selecciona una empresa válida.');
                }
                
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (name, email, password, rol, empresa, activo) VALUES (?, ?, ?, 'empresa', ?, 1)");
                if (!$stmt || !$stmt->bind_param('ssss', $nombre, $email, $hash, $empresa) || !$stmt->execute()) {
                    throw new RuntimeException('Error al crear el usuario. Verifica que el correo no esté en uso.');
                }
                $_SESSION['flash_success'] = 'Usuario de empresa creado correctamente.';
                break;
                
            case 'editar':
                $userId = (int)($_POST['user_id'] ?? 0);
                $nombre = trim($_POST['nombre'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $empresa = trim($_POST['empresa'] ?? '');
                
                if ($userId <= 0 || $nombre === '' || $email === '') {
                    throw new RuntimeException('Datos incompletos.');
                }
                if (!in_array($empresa, $empresas_disponibles, true)) {
                    throw new RuntimeException('Selecciona una empresa válida.');
                }
                
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, empresa = ? WHERE id = ? AND rol = 'empresa'");
                if (!$stmt || !$stmt->bind_param('sssi', $nombre, $email, $empresa, $userId) || !$stmt->execute()) {
                    throw new RuntimeException('Error al actualizar el usuario.');
                }
                $_SESSION['flash_success'] = 'Usuario actualizado.';
                break;
                
            case 'password':
                $userId = (int)($_POST['user_id'] ?? 0);
                $password = $_POST['password'] ?? '';
                
                if ($userId <= 0 || $password === '') {
                    throw new RuntimeException('Ingresa una nueva contraseña.');
                }
                
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND rol = 'empresa'");
                if (!$stmt || !$stmt->bind_param('si', $hash, $userId) || !$stmt->execute()) {
                    throw new RuntimeException('Error al actualizar la contraseña.');
                }
                $_SESSION['flash_success'] = 'Contraseña actualizada.';
                break;
                
            case 'estado':
                $userId = (int)($_POST['user_id'] ?? 0);
                $activo = (int)($_POST['activo'] ?? 0) === 1 ? 1 : 0;
                
                if ($userId <= 0) {
                    throw new RuntimeException('Usuario no válido.');
                }
                
                $stmt = $conn->prepare("UPDATE users SET activo = ? WHERE id = ? AND rol = 'empresa'");
                if (!$stmt || !$stmt->bind_param('ii', $activo, $userId) || !$stmt->execute()) {
                    throw new RuntimeException('Error al cambiar el estado.');
                }
                $_SESSION['flash_success'] = $activo ? 'Usuario reactivado.' : 'Usuario dado de baja.';
                break;
                
            case 'eliminar':
                $userId = (int)($_POST['user_id'] ?? 0);
                
                if ($userId <= 0) {
                    throw new RuntimeException('Usuario no válido.');
                }
                
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND rol = 'empresa'");
                if (!$stmt || !$stmt->bind_param('i', $userId) || !$stmt->execute()) {
                    throw new RuntimeException('Error al eliminar el usuario.');
                }
                $_SESSION['flash_success'] = 'Usuario eliminado.';
                break;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    
    header('Location: usuarios_empresa.php');
    exit;
}

// Obtener usuarios de empresa
$usuarios = [];
$result = $conn->query("SELECT id, name, email, empresa, activo, created_at FROM users WHERE rol = 'empresa' ORDER BY empresa, name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $usuarios[] = $row;
    }
}

// Contar por empresa
$conteoEmpresas = [];
foreach ($usuarios as $u) {
    $emp = $u['empresa'] ?? 'Sin asignar';
    if (!isset($conteoEmpresas[$emp])) {
        $conteoEmpresas[$emp] = 0;
    }
    $conteoEmpresas[$emp]++;
}

$pageTitle = 'Usuarios de Empresa - ErgoCuida';
$activePage = 'empresa';
$pageHeading = 'Usuarios de Empresa';
$pageDescription = 'Administra los accesos de representantes de empresas (Stone, Remedios, CEDISA) para visualizar información de sus empleados.';
$headerActions = [
    [
        'label' => 'Nuevo Usuario',
        'icon' => 'fa-user-plus',
        'onclick' => 'openModal("modalCrear")',
        'variant' => 'primary'
    ],
];
include __DIR__ . '/includes/header.php';
?>

<?php if ($mensaje_exito): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?= htmlspecialchars($mensaje_exito); ?>
    </div>
<?php endif; ?>

<?php if ($mensaje_error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?= htmlspecialchars($mensaje_error); ?>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon employees">
                <i class="fas fa-building"></i>
            </div>
        </div>
        <div class="stat-number"><?= count($usuarios); ?></div>
        <div class="stat-label">Usuarios de empresa</div>
    </div>
    <?php foreach ($empresas_disponibles as $emp): ?>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon projects">
                <i class="fas fa-users"></i>
            </div>
        </div>
        <div class="stat-number"><?= $conteoEmpresas[$emp] ?? 0; ?></div>
        <div class="stat-label"><?= htmlspecialchars($emp); ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="section">
    <h3><i class="fas fa-info-circle"></i> Información</h3>
    <p style="color: #64748b; font-size: 14px; line-height: 1.7;">
        Los usuarios de empresa pueden acceder a un panel de solo lectura donde visualizan:
    </p>
    <ul style="color: #64748b; font-size: 14px; margin: 12px 0 0 20px; line-height: 1.8;">
        <li>Listado de empleados de su empresa</li>
        <li>Proyectos donde participan sus empleados</li>
        <li>Últimos lotes SUA procesados</li>
        <li>Posibilidad de subir documentos SUA</li>
    </ul>
</div>

<div class="section">
    <h3><i class="fas fa-users"></i> Listado de Usuarios</h3>
    
    <?php if (empty($usuarios)): ?>
        <div class="empty-state">
            <i class="fas fa-user-slash"></i>
            <h4>Sin usuarios</h4>
            <p>No hay usuarios de empresa registrados.</p>
            <button class="btn btn-primary" onclick="openModal('modalCrear')"><i class="fas fa-user-plus"></i> Crear Usuario</button>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Empresa</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td>
                                <div class="table-entity">
                                    <strong><?= htmlspecialchars($u['name'] ?? ''); ?></strong>
                                    <span class="table-note"><?= htmlspecialchars($u['email'] ?? ''); ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge" style="background: #e0f2fe; color: #0369a1;">
                                    <?= htmlspecialchars($u['empresa'] ?? 'Sin asignar'); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?= (int)$u['activo'] === 1 ? 'status-activo' : 'status-inactivo'; ?>">
                                    <?= (int)$u['activo'] === 1 ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button class="btn btn-secondary btn-compact" onclick="openEditModal(<?= (int)$u['id']; ?>, '<?= htmlspecialchars(addslashes($u['name'] ?? ''), ENT_QUOTES); ?>', '<?= htmlspecialchars(addslashes($u['email'] ?? ''), ENT_QUOTES); ?>', '<?= htmlspecialchars($u['empresa'] ?? '', ENT_QUOTES); ?>')">
                                        <i class="fas fa-pen"></i> Editar
                                    </button>
                                    <button class="btn btn-primary btn-compact" onclick="openPasswordModal(<?= (int)$u['id']; ?>)">
                                        <i class="fas fa-key"></i> Contraseña
                                    </button>
                                    <form class="inline-form" method="POST" onsubmit="return confirm('<?= (int)$u['activo'] === 1 ? '¿Dar de baja?' : '¿Reactivar?'; ?>');">
                                        <input type="hidden" name="accion" value="estado">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id']; ?>">
                                        <input type="hidden" name="activo" value="<?= (int)$u['activo'] === 1 ? 0 : 1; ?>">
                                        <button type="submit" class="btn <?= (int)$u['activo'] === 1 ? 'btn-warning' : 'btn-success'; ?> btn-compact">
                                            <i class="fas <?= (int)$u['activo'] === 1 ? 'fa-user-slash' : 'fa-user-check'; ?>"></i>
                                            <?= (int)$u['activo'] === 1 ? 'Baja' : 'Activar'; ?>
                                        </button>
                                    </form>
                                    <form class="inline-form" method="POST" onsubmit="return confirm('¿Eliminar este usuario permanentemente?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-compact">
                                            <i class="fas fa-trash"></i> Eliminar
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Crear -->
<div id="modalCrear" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Nuevo Usuario de Empresa</h3>
            <button type="button" class="close-btn" onclick="closeModal('modalCrear')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="accion" value="crear">
            <div class="form-grid">
                <div class="form-group">
                    <label for="crearNombre">Nombre completo *</label>
                    <input type="text" id="crearNombre" name="nombre" class="form-control" required placeholder="Ej: Juan Pérez">
                </div>
                <div class="form-group">
                    <label for="crearEmail">Correo electrónico *</label>
                    <input type="email" id="crearEmail" name="email" class="form-control" required placeholder="usuario@empresa.com">
                </div>
                <div class="form-group">
                    <label for="crearEmpresa">Empresa *</label>
                    <select id="crearEmpresa" name="empresa" class="form-control" required>
                        <option value="">Selecciona una empresa</option>
                        <?php foreach ($empresas_disponibles as $emp): ?>
                            <option value="<?= htmlspecialchars($emp); ?>"><?= htmlspecialchars($emp); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="crearPassword">Contraseña *</label>
                    <input type="password" id="crearPassword" name="password" class="form-control" required placeholder="Mínimo 8 caracteres">
                </div>
            </div>
            <div class="modal-actions modal-actions--right">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalCrear')">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Crear Usuario</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Editar -->
<div id="modalEditar" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Editar Usuario</h3>
            <button type="button" class="close-btn" onclick="closeModal('modalEditar')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="user_id" id="editarUserId">
            <div class="form-grid">
                <div class="form-group">
                    <label for="editarNombre">Nombre completo *</label>
                    <input type="text" id="editarNombre" name="nombre" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="editarEmail">Correo electrónico *</label>
                    <input type="email" id="editarEmail" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="editarEmpresa">Empresa *</label>
                    <select id="editarEmpresa" name="empresa" class="form-control" required>
                        <option value="">Selecciona una empresa</option>
                        <?php foreach ($empresas_disponibles as $emp): ?>
                            <option value="<?= htmlspecialchars($emp); ?>"><?= htmlspecialchars($emp); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-actions modal-actions--right">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEditar')">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Contraseña -->
<div id="modalPassword" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Cambiar Contraseña</h3>
            <button type="button" class="close-btn" onclick="closeModal('modalPassword')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="accion" value="password">
            <input type="hidden" name="user_id" id="passwordUserId">
            <div class="form-group">
                <label for="nuevaPassword">Nueva contraseña *</label>
                <input type="password" id="nuevaPassword" name="password" class="form-control" required placeholder="Mínimo 8 caracteres">
            </div>
            <div class="modal-actions modal-actions--right">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalPassword')">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Actualizar</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).style.display = 'block';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
function openEditModal(id, nombre, email, empresa) {
    document.getElementById('editarUserId').value = id;
    document.getElementById('editarNombre').value = nombre;
    document.getElementById('editarEmail').value = email;
    document.getElementById('editarEmpresa').value = empresa;
    openModal('modalEditar');
}
function openPasswordModal(id) {
    document.getElementById('passwordUserId').value = id;
    document.getElementById('nuevaPassword').value = '';
    openModal('modalPassword');
}
// Close modal on outside click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});
// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
