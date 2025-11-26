<?php
require_once __DIR__ . '/includes/admin_init.php';

$mensaje_exito = $_SESSION['flash_success'] ?? '';
$mensaje_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Asegurar que la columna empresa existe en users
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'empresa'");
if (!$result || $result->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN empresa VARCHAR(100) NULL AFTER rol");
}

// Asegurar que el rol 'responsable_empresa' está soportado en la columna rol
$result = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'rol'");
if ($result) {
    $row = $result->fetch_assoc();
    $columnType = $row['COLUMN_TYPE'] ?? '';
    
    if (stripos($columnType, 'enum') !== false && stripos($columnType, 'responsable_empresa') === false) {
        // Extraer valores del enum y agregar el nuevo
        if (preg_match("/^enum\((.+)\)$/i", $columnType, $matches)) {
            $existingValues = $matches[1];
            $newEnumValues = $existingValues . ",'responsable_empresa'";
            $conn->query("ALTER TABLE users MODIFY COLUMN rol ENUM(" . str_replace("'", "", $newEnumValues) . ") NULL");
        }
    } elseif (stripos($columnType, 'varchar') !== false) {
        // Ya es VARCHAR, no necesita cambios
    }
}

function admin_empresa_redirect(): void {
    $uri = $_SERVER['REQUEST_URI'] ?? 'responsables_empresa.php';
    header('Location: ' . $uri);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    
    switch ($accion) {
        case 'crear':
            $nombre = trim($_POST['nombre'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $empresa = trim($_POST['empresa'] ?? '');
            
            if ($nombre === '' || $email === '' || $password === '' || $empresa === '') {
                $_SESSION['flash_error'] = 'Todos los campos son obligatorios.';
                admin_empresa_redirect();
            }
            
            if (!in_array($empresa, ['CEDISA', 'Stone', 'Remedios'])) {
                $_SESSION['flash_error'] = 'La empresa seleccionada no es válida.';
                admin_empresa_redirect();
            }
            
            // Verificar que el email no existe
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $_SESSION['flash_error'] = 'El correo electrónico ya está registrado.';
                    $stmt->close();
                    admin_empresa_redirect();
                }
                $stmt->close();
            }
            
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $rol = 'responsable_empresa';
            $activo = 1;
            
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, password_visible, rol, empresa, activo) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('ssssssi', $nombre, $email, $hash, $password, $rol, $empresa, $activo);
                if ($stmt->execute()) {
                    $_SESSION['flash_success'] = 'Responsable de empresa creado correctamente.';
                } else {
                    $_SESSION['flash_error'] = 'Error al crear el responsable: ' . $conn->error;
                }
                $stmt->close();
            }
            admin_empresa_redirect();
            break;
            
        case 'editar':
            if ($userId <= 0) {
                $_SESSION['flash_error'] = 'Usuario no válido.';
                admin_empresa_redirect();
            }
            
            $nombre = trim($_POST['nombre'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $empresa = trim($_POST['empresa'] ?? '');
            $password = $_POST['password'] ?? '';
            
            if ($nombre === '' || $email === '' || $empresa === '') {
                $_SESSION['flash_error'] = 'Nombre, email y empresa son obligatorios.';
                admin_empresa_redirect();
            }
            
            if (!in_array($empresa, ['CEDISA', 'Stone', 'Remedios'])) {
                $_SESSION['flash_error'] = 'La empresa seleccionada no es válida.';
                admin_empresa_redirect();
            }
            
            // Actualizar información básica
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, empresa = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('sssi', $nombre, $email, $empresa, $userId);
                $stmt->execute();
                $stmt->close();
            }
            
            // Actualizar contraseña si se proporcionó una nueva
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ?, password_visible = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('ssi', $hash, $password, $userId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            
            $_SESSION['flash_success'] = 'Responsable actualizado correctamente.';
            admin_empresa_redirect();
            break;
            
        case 'estado':
            if ($userId <= 0) {
                $_SESSION['flash_error'] = 'Usuario no válido.';
                admin_empresa_redirect();
            }
            
            $activo = isset($_POST['activo']) && (int)$_POST['activo'] === 1 ? 1 : 0;
            $stmt = $conn->prepare("UPDATE users SET activo = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $activo, $userId);
                $stmt->execute();
                $stmt->close();
            }
            
            $_SESSION['flash_success'] = $activo ? 'Responsable reactivado.' : 'Responsable dado de baja.';
            admin_empresa_redirect();
            break;
            
        case 'eliminar':
            if ($userId <= 0) {
                $_SESSION['flash_error'] = 'Usuario no válido.';
                admin_empresa_redirect();
            }
            
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND rol = 'responsable_empresa'");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $_SESSION['flash_success'] = 'Responsable eliminado correctamente.';
                } else {
                    $_SESSION['flash_error'] = 'No se pudo eliminar el responsable.';
                }
                $stmt->close();
            }
            admin_empresa_redirect();
            break;
    }
}

// Obtener lista de responsables de empresa
$responsables = [];
$result = $conn->query("SELECT * FROM users WHERE rol = 'responsable_empresa' ORDER BY empresa, name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $responsables[] = $row;
    }
}

// Estadísticas por empresa
$stats_empresas = [
    'CEDISA' => 0,
    'Stone' => 0,
    'Remedios' => 0,
];

foreach ($responsables as $resp) {
    $empresa = $resp['empresa'] ?? '';
    if (isset($stats_empresas[$empresa])) {
        $stats_empresas[$empresa]++;
    }
}

$pageTitle = 'Responsables de Empresa - ErgoCuida';
$activePage = 'empresa_responsables';
$pageHeading = 'Responsables de Empresa';
$pageDescription = 'Gestiona los accesos de responsables de cada empresa para consultar sus servicios especializados.';
$headerActions = [
    [
        'label' => 'Nuevo Responsable',
        'icon' => 'fa-user-plus',
        'href' => '#',
        'variant' => 'primary',
        'onclick' => "openModal('modalCrear')"
    ]
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
                <i class="fas fa-users"></i>
            </div>
        </div>
        <div class="stat-number"><?= count($responsables); ?></div>
        <div class="stat-label">Responsables registrados</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon projects">
                <i class="fas fa-building"></i>
            </div>
        </div>
        <div class="stat-number"><?= $stats_empresas['CEDISA']; ?></div>
        <div class="stat-label">CEDISA</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon attendance">
                <i class="fas fa-building"></i>
            </div>
        </div>
        <div class="stat-number"><?= $stats_empresas['Stone']; ?></div>
        <div class="stat-label">Stone</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon pms">
                <i class="fas fa-building"></i>
            </div>
        </div>
        <div class="stat-number"><?= $stats_empresas['Remedios']; ?></div>
        <div class="stat-label">Remedios</div>
    </div>
</div>

<div class="section">
    <h3><i class="fas fa-users"></i> Listado de Responsables de Empresa</h3>
    
    <?php if (empty($responsables)): ?>
        <div class="empty-state">
            <i class="fas fa-user-slash"></i>
            <h4>Sin responsables registrados</h4>
            <p>Crea el primer responsable de empresa usando el botón "Nuevo Responsable".</p>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Empresa</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($responsables as $resp): ?>
                        <?php
                            $activo = (int)($resp['activo'] ?? 0) === 1;
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($resp['name'] ?? ''); ?></strong>
                            </td>
                            <td><?= htmlspecialchars($resp['email'] ?? ''); ?></td>
                            <td>
                                <span class="status-badge" style="background: #dbeafe; color: #1e40af;">
                                    <?= htmlspecialchars($resp['empresa'] ?? ''); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?= $activo ? 'status-activo' : 'status-inactivo'; ?>">
                                    <?= $activo ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="btn btn-primary btn-compact"
                                            data-open="modalEditar"
                                            data-id="<?= (int)$resp['id']; ?>"
                                            data-nombre="<?= htmlspecialchars($resp['name'] ?? '', ENT_QUOTES); ?>"
                                            data-email="<?= htmlspecialchars($resp['email'] ?? '', ENT_QUOTES); ?>"
                                            data-empresa="<?= htmlspecialchars($resp['empresa'] ?? '', ENT_QUOTES); ?>">
                                        <i class="fas fa-pen"></i> Editar
                                    </button>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('¿<?= $activo ? 'Dar de baja' : 'Reactivar'; ?> a este responsable?');">
                                        <input type="hidden" name="accion" value="estado">
                                        <input type="hidden" name="user_id" value="<?= (int)$resp['id']; ?>">
                                        <input type="hidden" name="activo" value="<?= $activo ? 0 : 1; ?>">
                                        <button type="submit" class="btn <?= $activo ? 'btn-warning' : 'btn-success'; ?> btn-compact">
                                            <i class="fas <?= $activo ? 'fa-user-minus' : 'fa-user-check'; ?>"></i>
                                            <?= $activo ? 'Dar de baja' : 'Reactivar'; ?>
                                        </button>
                                    </form>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar este responsable? Esta acción no se puede deshacer.');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="user_id" value="<?= (int)$resp['id']; ?>">
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
            <h3>Nuevo Responsable de Empresa</h3>
            <button type="button" class="close-btn" onclick="closeModal('modalCrear')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="accion" value="crear">
            <div class="form-grid">
                <div class="form-group">
                    <label for="crearNombre">Nombre completo *</label>
                    <input type="text" id="crearNombre" name="nombre" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="crearEmail">Correo electrónico *</label>
                    <input type="email" id="crearEmail" name="email" class="form-control" required>
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="crearEmpresa">Empresa *</label>
                    <select id="crearEmpresa" name="empresa" class="form-control" required>
                        <option value="">Seleccionar empresa</option>
                        <option value="CEDISA">CEDISA</option>
                        <option value="Stone">Stone</option>
                        <option value="Remedios">Remedios</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="crearPassword">Contraseña *</label>
                    <input type="password" id="crearPassword" name="password" class="form-control" required minlength="6">
                </div>
            </div>
            <div class="modal-actions modal-actions--right">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalCrear')">Cancelar</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Crear Responsable</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Editar -->
<div id="modalEditar" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Editar Responsable</h3>
            <button type="button" class="close-btn" onclick="closeModal('modalEditar')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="user_id" id="editarId">
            <div class="form-grid">
                <div class="form-group">
                    <label for="editarNombre">Nombre completo *</label>
                    <input type="text" id="editarNombre" name="nombre" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="editarEmail">Correo electrónico *</label>
                    <input type="email" id="editarEmail" name="email" class="form-control" required>
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="editarEmpresa">Empresa *</label>
                    <select id="editarEmpresa" name="empresa" class="form-control" required>
                        <option value="">Seleccionar empresa</option>
                        <option value="CEDISA">CEDISA</option>
                        <option value="Stone">Stone</option>
                        <option value="Remedios">Remedios</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="editarPassword">Nueva Contraseña</label>
                    <input type="password" id="editarPassword" name="password" class="form-control" placeholder="Dejar vacío para mantener">
                </div>
            </div>
            <div class="modal-actions modal-actions--right">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEditar')">Cancelar</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

window.addEventListener('click', (event) => {
    if (event.target.classList && event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
});

// Configurar botones de editar
document.querySelectorAll('[data-open="modalEditar"]').forEach(button => {
    button.addEventListener('click', () => {
        document.getElementById('editarId').value = button.dataset.id || '';
        document.getElementById('editarNombre').value = button.dataset.nombre || '';
        document.getElementById('editarEmail').value = button.dataset.email || '';
        document.getElementById('editarEmpresa').value = button.dataset.empresa || '';
        document.getElementById('editarPassword').value = '';
        openModal('modalEditar');
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
