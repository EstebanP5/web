<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'pm') {
    header('Location: ../../login.php');
    exit;
}

require_once __DIR__ . '/../../includes/db.php';

$pmUserId = (int)$_SESSION['user_id'];
$employeeId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if ($employeeId <= 0) {
    header('Location: ../empleados.php');
    exit;
}

$projects = [];
if ($stmtProjects = $conn->prepare('SELECT proyecto_id FROM proyectos_pm WHERE user_id = ?')) {
    $stmtProjects->bind_param('i', $pmUserId);
    if ($stmtProjects->execute()) {
        $resultProjects = $stmtProjects->get_result();
        if ($resultProjects) {
            while ($row = $resultProjects->fetch_assoc()) {
                $projects[] = (int)$row['proyecto_id'];
            }
        }
    }
    $stmtProjects->close();
}

if (empty($projects)) {
    $_SESSION['pm_service_error'] = 'No tienes proyectos asignados en este momento.';
    header('Location: ../empleados.php');
    exit;
}

$hasAccess = false;
if (!empty($projects)) {
    $placeholders = implode(',', array_fill(0, count($projects), '?'));
    $sqlAccess = 'SELECT 1 FROM (
                     SELECT empleado_id FROM empleado_proyecto WHERE proyecto_id IN (' . $placeholders . ')
                     UNION
                     SELECT empleado_id FROM empleado_asignaciones WHERE proyecto_id IN (' . $placeholders . ')
                 ) rel WHERE rel.empleado_id = ? LIMIT 1';
    if ($stmtAccess = $conn->prepare($sqlAccess)) {
        $params = array_merge($projects, $projects, [$employeeId]);
        $types = str_repeat('i', count($projects) * 2 + 1);
        $stmtAccess->bind_param($types, ...$params);
        $stmtAccess->execute();
        $stmtAccess->store_result();
        $hasAccess = $stmtAccess->num_rows > 0;
        $stmtAccess->close();
    }
}

if (!$hasAccess) {
    $_SESSION['pm_service_error'] = 'Este Servicio Especializado no está vinculado a tus proyectos.';
    header('Location: ../empleados.php');
    exit;
}

$employee = null;
$userRow = null;
if ($stmtEmployee = $conn->prepare('SELECT e.id, e.nombre, e.telefono, e.nss, e.curp, e.empresa, e.activo, u.email, u.password_visible FROM empleados e LEFT JOIN users u ON u.id = e.id WHERE e.id = ? LIMIT 1')) {
    $stmtEmployee->bind_param('i', $employeeId);
    if ($stmtEmployee->execute()) {
        $resultEmployee = $stmtEmployee->get_result();
        if ($resultEmployee) {
            $employee = $resultEmployee->fetch_assoc();
        }
    }
    $stmtEmployee->close();
}

if (!$employee) {
    $_SESSION['pm_service_error'] = 'No se encontró al Servicio Especializado solicitado.';
    header('Location: ../empleados.php');
    exit;
}

if (!empty($employee['email'])) {
    $userRow = [
        'email' => $employee['email'],
        'password_visible' => $employee['password_visible'] ?? ''
    ];
}

$values = [
    'nombre' => trim((string)$employee['nombre']),
    'telefono' => trim((string)$employee['telefono']),
    'nss' => trim((string)($employee['nss'] ?? '')),
    'curp' => trim((string)($employee['curp'] ?? '')),
    'empresa' => trim((string)($employee['empresa'] ?? '')),
    'email' => trim((string)($employee['email'] ?? '')),
];

$feedback = ['type' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['nombre'] = trim($_POST['nombre'] ?? '');
    $values['telefono'] = trim($_POST['telefono'] ?? '');
    $values['nss'] = trim($_POST['nss'] ?? '');
    $values['curp'] = trim($_POST['curp'] ?? '');
    $values['empresa'] = trim($_POST['empresa'] ?? '');
    $values['email'] = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $passwordConfirm = trim($_POST['password_confirm'] ?? '');

    $errors = [];

    if ($values['nombre'] === '') {
        $errors[] = 'El nombre es obligatorio.';
    }
    if ($values['telefono'] === '') {
        $errors[] = 'El teléfono es obligatorio.';
    }
    if ($values['nss'] === '') {
        $errors[] = 'El NSS es obligatorio.';
    }
    if ($values['curp'] === '') {
        $errors[] = 'La CURP es obligatoria.';
    }
    if ($values['empresa'] === '' || !in_array($values['empresa'], ['CEDISA', 'Stone', 'Remedios'], true)) {
        $errors[] = 'Selecciona una empresa válida.';
    }

    if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ingresa un correo electrónico válido.';
    }

    if ($userRow && $values['email'] === '') {
        $errors[] = 'Elimina el acceso únicamente a través del administrador. Mantén un correo válido para este Servicio Especializado.';
    }

    if ($password !== '') {
        if (strlen($password) < 8) {
            $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
        }
        if ($passwordConfirm === '') {
            $errors[] = 'Confirma la contraseña ingresada.';
        } elseif ($password !== $passwordConfirm) {
            $errors[] = 'Las contraseñas no coinciden.';
        }
        if ($values['email'] === '') {
            $errors[] = 'Asigna un correo antes de definir una contraseña.';
        }
    }

    if (!$userRow && $values['email'] !== '' && $password === '') {
        $errors[] = 'Define una contraseña para habilitar el acceso del Servicio Especializado.';
    }

    if ($values['email'] !== '') {
        if ($stmtEmail = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1')) {
            $stmtEmail->bind_param('si', $values['email'], $employeeId);
            if ($stmtEmail->execute()) {
                $resultEmail = $stmtEmail->get_result();
                if ($resultEmail && $resultEmail->num_rows > 0) {
                    $errors[] = 'El correo electrónico ya está en uso por otro usuario.';
                }
            }
            $stmtEmail->close();
        }
    }

    if (empty($errors)) {
        $passwordHash = null;
        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }

        $conn->begin_transaction();
        try {
            $stmtUpdate = $conn->prepare('UPDATE empleados SET nombre = ?, telefono = ?, nss = ?, curp = ?, empresa = ? WHERE id = ?');
            if (!$stmtUpdate) {
                throw new RuntimeException('No se pudo preparar la actualización del Servicio Especializado.');
            }
            $stmtUpdate->bind_param('sssssi', $values['nombre'], $values['telefono'], $values['nss'], $values['curp'], $values['empresa'], $employeeId);
            if (!$stmtUpdate->execute()) {
                $stmtUpdate->close();
                throw new RuntimeException('No se pudo actualizar la información del Servicio Especializado.');
            }
            $stmtUpdate->close();

            if ($userRow) {
                if ($values['email'] !== '') {
                    $stmtUserEmail = $conn->prepare('UPDATE users SET email = ?, activo = 1 WHERE id = ?');
                    if (!$stmtUserEmail) {
                        throw new RuntimeException('No se pudo preparar la actualización del correo.');
                    }
                    $stmtUserEmail->bind_param('si', $values['email'], $employeeId);
                    if (!$stmtUserEmail->execute()) {
                        $stmtUserEmail->close();
                        throw new RuntimeException('No se pudo actualizar el correo electrónico.');
                    }
                    $stmtUserEmail->close();
                }
                if ($passwordHash !== null) {
                    $stmtUserPwd = $conn->prepare('UPDATE users SET password = ?, password_visible = ?, activo = 1 WHERE id = ?');
                    if (!$stmtUserPwd) {
                        throw new RuntimeException('No se pudo preparar la actualización de la contraseña.');
                    }
                    $stmtUserPwd->bind_param('ssi', $passwordHash, $password, $employeeId);
                    if (!$stmtUserPwd->execute()) {
                        $stmtUserPwd->close();
                        throw new RuntimeException('No se pudo actualizar la contraseña.');
                    }
                    $stmtUserPwd->close();
                    $employee['password_visible'] = $password;
                }
            } else {
                if ($values['email'] !== '' && $passwordHash !== null) {
                    $stmtInsertUser = $conn->prepare("INSERT INTO users (id, name, email, password, password_visible, rol, activo) VALUES (?, ?, ?, ?, ?, 'servicio_especializado', 1)");
                    if (!$stmtInsertUser) {
                        throw new RuntimeException('No se pudo preparar la creación del usuario.');
                    }
                    $stmtInsertUser->bind_param('issss', $employeeId, $values['nombre'], $values['email'], $passwordHash, $password);
                    if (!$stmtInsertUser->execute()) {
                        $stmtInsertUser->close();
                        throw new RuntimeException('No se pudo crear el usuario vinculado.');
                    }
                    $stmtInsertUser->close();
                    $employee['password_visible'] = $password;
                }
            }

            $conn->commit();

            $feedback['type'] = 'success';
            $feedback['message'] = 'Servicio Especializado actualizado correctamente.';

            $employee['nombre'] = $values['nombre'];
            $employee['telefono'] = $values['telefono'];
            $employee['nss'] = $values['nss'];
            $employee['curp'] = $values['curp'];
            $employee['empresa'] = $values['empresa'];
            $employee['email'] = $values['email'];
            if ($values['email'] !== '') {
                $userRow = ['email' => $values['email'], 'password_visible' => $password !== '' ? $password : ($employee['password_visible'] ?? '')];
            }
        } catch (Throwable $e) {
            $conn->rollback();
            $feedback['type'] = 'error';
            $feedback['message'] = 'No se pudo guardar la información. Intenta nuevamente.';
        }
    } else {
        $feedback['type'] = 'error';
        $feedback['message'] = 'Corrige los siguientes puntos:<ul><li>' . implode('</li><li>', array_map(static function ($msg) {
            return htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        }, $errors)) . '</li></ul>';
    }
}

$pmCssPath = __DIR__ . '/../../assets/pm.css';
$pmCssVersion = file_exists($pmCssPath) ? filemtime($pmCssPath) : time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Servicio Especializado - PM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/pm.css?v=<?= $pmCssVersion; ?>">
    <style>
        body {
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0;
            color: #0f172a;
        }
        .pm-form-wrapper {
            max-width: 720px;
            margin: 32px auto;
            background: #ffffff;
            border-radius: 20px;
            padding: 36px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
            border: 1px solid #e2e8f0;
        }
        .pm-form-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
        }
        .pm-form-header h1 {
            font-size: 28px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #0f172a;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-primary {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.25);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 32px rgba(5, 150, 105, 0.35);
        }
        .btn-secondary {
            background: #ffffff;
            color: #1f2937;
            border: 1px solid #cbd5f5;
        }
        .btn-secondary:hover {
            background: #f8fafc;
            transform: translateY(-1px);
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        input, select {
            width: 100%;
            padding: 14px 16px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            background: #f8fafc;
            font-size: 14px;
            color: #0f172a;
            transition: all 0.2s ease;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #10b981;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
        }
        .pm-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }
        .pm-grid .full-row {
            grid-column: 1 / -1;
        }
        .pm-feedback {
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 24px;
            font-size: 14px;
        }
        .pm-feedback.success {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .pm-feedback.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .pm-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 28px;
        }
        .pm-hint {
            font-size: 12px;
            color: #64748b;
            margin-top: 6px;
        }
        @media (max-width: 640px) {
            .pm-form-wrapper {
                padding: 24px;
                margin: 16px;
            }
            .pm-form-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
<?php require_once dirname(__DIR__) . '/common/navigation.php'; ?>
<div class="pm-form-wrapper">
    <div class="pm-form-header">
        <h1><i class="fas fa-user-pen"></i> Editar servicio</h1>
        <a href="../empleados.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Volver</a>
    </div>
    <p style="color:#475569; margin-bottom:20px;">Actualiza los datos de contacto y acceso del Servicio Especializado seleccionado.</p>

    <?php if ($feedback['type'] === 'success'): ?>
        <div class="pm-feedback success"><?= $feedback['message']; ?></div>
    <?php elseif ($feedback['type'] === 'error'): ?>
        <div class="pm-feedback error"><?= $feedback['message']; ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <div class="pm-grid">
            <div>
                <label for="nombre">Nombre completo *</label>
                <input type="text" id="nombre" name="nombre" required value="<?= htmlspecialchars($values['nombre'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nombre y apellidos" />
            </div>
            <div>
                <label for="telefono">Teléfono *</label>
                <input type="tel" id="telefono" name="telefono" required value="<?= htmlspecialchars($values['telefono'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ej. 555-123-4567" />
            </div>
            <div>
                <label for="nss">NSS *</label>
                <input type="text" id="nss" name="nss" required value="<?= htmlspecialchars($values['nss'], ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div>
                <label for="curp">CURP *</label>
                <input type="text" id="curp" name="curp" required value="<?= htmlspecialchars($values['curp'], ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div class="full-row">
                <label for="empresa">Empresa *</label>
                <select id="empresa" name="empresa" required>
                    <option value="">Selecciona una empresa</option>
                    <option value="CEDISA" <?= $values['empresa'] === 'CEDISA' ? 'selected' : ''; ?>>CEDISA</option>
                    <option value="Stone" <?= $values['empresa'] === 'Stone' ? 'selected' : ''; ?>>Stone</option>
                    <option value="Remedios" <?= $values['empresa'] === 'Remedios' ? 'selected' : ''; ?>>Remedios</option>
                </select>
            </div>
            <div class="full-row">
                <label for="email">Correo electrónico</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="usuario@correo.com" />
                <div class="pm-hint">El correo es necesario para habilitar el acceso del Servicio Especializado.</div>
            </div>
            <div>
                <label for="password">Contraseña nueva</label>
                <input type="password" id="password" name="password" placeholder="Mínimo 8 caracteres" />
            </div>
            <div>
                <label for="password_confirm">Confirmar contraseña</label>
                <input type="password" id="password_confirm" name="password_confirm" placeholder="Repite la contraseña" />
            </div>
        </div>
        <div class="pm-form-actions">
            <a href="../empleados.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar cambios</button>
        </div>
    </form>
</div>
</body>
</html>
