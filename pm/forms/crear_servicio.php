<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'pm') {
    header('Location: ../../login.php');
    exit;
}

require_once __DIR__ . '/../../includes/db.php';

$pmUserId = (int)$_SESSION['user_id'];

$projects = [];
$projectIds = [];
if ($stmtProjects = $conn->prepare('SELECT g.id, g.nombre FROM proyectos_pm ppm JOIN grupos g ON ppm.proyecto_id = g.id WHERE ppm.user_id = ? AND g.activo = 1 ORDER BY g.nombre')) {
    $stmtProjects->bind_param('i', $pmUserId);
    if ($stmtProjects->execute()) {
        $resultProjects = $stmtProjects->get_result();
        if ($resultProjects) {
            while ($row = $resultProjects->fetch_assoc()) {
                $projects[] = $row;
                $projectIds[] = (int)$row['id'];
            }
        }
    }
    $stmtProjects->close();
}

$values = [
    'nombre' => '',
    'telefono' => '',
    'nss' => '',
    'curp' => '',
    'email' => '',
    'empresa' => '',
    'proyecto_id' => 0,
];

$feedback = ['type' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['nombre'] = trim($_POST['nombre'] ?? '');
    $values['telefono'] = trim($_POST['telefono'] ?? '');
    $values['nss'] = trim($_POST['nss'] ?? '');
    $values['curp'] = trim($_POST['curp'] ?? '');
    $values['email'] = trim($_POST['email'] ?? '');
    $values['empresa'] = trim($_POST['empresa'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $passwordConfirm = trim($_POST['password_confirm'] ?? '');
    $values['proyecto_id'] = isset($_POST['proyecto_id']) && ctype_digit((string)$_POST['proyecto_id']) ? (int)$_POST['proyecto_id'] : 0;

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
    if ($values['empresa'] === '' || !in_array($values['empresa'], ['ErgoSolar', 'Stone', 'Remedios'], true)) {
        $errors[] = 'Selecciona una empresa válida.';
    }
    if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Proporciona un correo electrónico válido.';
    }
    if ($password === '') {
        $errors[] = 'La contraseña es obligatoria.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
    }
    if ($passwordConfirm === '') {
        $errors[] = 'Confirma la contraseña ingresada.';
    } elseif ($password !== $passwordConfirm) {
        $errors[] = 'Las contraseñas no coinciden.';
    }

    if ($values['proyecto_id'] !== 0 && !in_array($values['proyecto_id'], $projectIds, true)) {
        $errors[] = 'El proyecto seleccionado no pertenece a tu cartera.';
    }

    $altaImssFile = $_FILES['alta_imss'] ?? null;
    $altaImssInfo = null;
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
    $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];

    if (!$altaImssFile || ($altaImssFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Debes adjuntar el alta del IMSS en formato PDF o imagen.';
    } elseif (($altaImssFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = 'No se pudo recibir el archivo del IMSS. Código de error: ' . (int)$altaImssFile['error'];
    } elseif (($altaImssFile['size'] ?? 0) <= 0) {
        $errors[] = 'El archivo del IMSS está vacío.';
    } else {
        $extension = strtolower(pathinfo($altaImssFile['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = 'Formato de archivo no permitido. Usa PDF o imagen (JPG/PNG).';
        } elseif (($altaImssFile['size'] ?? 0) > 10 * 1024 * 1024) {
            $errors[] = 'El archivo del IMSS no debe superar los 10 MB.';
        } else {
            $detectedMime = null;
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detectedMime = finfo_file($finfo, $altaImssFile['tmp_name']);
                    finfo_close($finfo);
                }
            }
            if ($detectedMime && !in_array($detectedMime, $allowedMimes, true)) {
                $errors[] = 'El archivo del IMSS debe ser PDF o imagen (JPG/PNG).';
            } else {
                $altaImssInfo = [
                    'extension' => $extension,
                    'mime' => $detectedMime ?: ($altaImssFile['type'] ?? ''),
                    'original' => $altaImssFile['name'] ?? 'alta_imss',
                    'tmp_name' => $altaImssFile['tmp_name']
                ];
            }
        }
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $userId = 0;
        $employeeId = 0;
        $documentSaved = false;
        $documentPath = null;
        $transactionStarted = false;

        try {
            if (!$conn->begin_transaction()) {
                throw new RuntimeException('No se pudo iniciar la transacción.');
            }
            $transactionStarted = true;

            $stmtUser = $conn->prepare("INSERT INTO users (name, email, password, rol, activo) VALUES (?, ?, ?, 'servicio_especializado', 1)");
            if (!$stmtUser) {
                throw new RuntimeException('No se pudo preparar la creación del usuario.');
            }
            $stmtUser->bind_param('sss', $values['nombre'], $values['email'], $hash);
            if (!$stmtUser->execute()) {
                if ($stmtUser->errno === 1062) {
                    throw new RuntimeException('El correo ya está en uso.');
                }
                throw new RuntimeException('No se pudo crear el usuario.');
            }
            $userId = (int)$conn->insert_id;
            $stmtUser->close();

            $stmtEmployee = $conn->prepare('INSERT INTO empleados (id, nombre, telefono, nss, curp, empresa, activo, fecha_registro) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())');
            if (!$stmtEmployee) {
                throw new RuntimeException('No se pudo preparar la creación del Servicio Especializado.');
            }
            $stmtEmployee->bind_param('isssss', $userId, $values['nombre'], $values['telefono'], $values['nss'], $values['curp'], $values['empresa']);
            if (!$stmtEmployee->execute()) {
                throw new RuntimeException('No se pudo guardar el Servicio Especializado.');
            }
            $employeeId = $userId;
            $stmtEmployee->close();

            if ($values['proyecto_id'] > 0) {
                $stmtDeactivate = $conn->prepare('UPDATE empleado_proyecto SET activo = 0 WHERE empleado_id = ? AND activo = 1');
                if ($stmtDeactivate) {
                    $stmtDeactivate->bind_param('i', $employeeId);
                    $stmtDeactivate->execute();
                    $stmtDeactivate->close();
                }
                $stmtAssign = $conn->prepare('UPDATE empleado_proyecto SET activo = 1, fecha_asignacion = NOW() WHERE empleado_id = ? AND proyecto_id = ?');
                if ($stmtAssign) {
                    $stmtAssign->bind_param('ii', $employeeId, $values['proyecto_id']);
                    $stmtAssign->execute();
                    $affected = $stmtAssign->affected_rows;
                    $stmtAssign->close();
                    if ($affected === 0) {
                        $stmtInsertAssign = $conn->prepare('INSERT INTO empleado_proyecto (empleado_id, proyecto_id, activo, fecha_asignacion) VALUES (?, ?, 1, NOW())');
                        if ($stmtInsertAssign) {
                            $stmtInsertAssign->bind_param('ii', $employeeId, $values['proyecto_id']);
                            $stmtInsertAssign->execute();
                            $stmtInsertAssign->close();
                        }
                    }
                }
            }

            if ($altaImssInfo) {
                $uploadsDir = dirname(__DIR__, 2) . '/uploads/altas_imss';
                if (!is_dir($uploadsDir)) {
                    if (!mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
                        throw new RuntimeException('No se pudo crear el directorio para guardar el alta del IMSS.');
                    }
                }

                $randomSegment = bin2hex(random_bytes(4));
                $filename = 'alta_imss_' . $employeeId . '_' . date('Ymd_His') . '_' . $randomSegment . '.' . $altaImssInfo['extension'];
                $destination = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
                if (!is_uploaded_file($altaImssInfo['tmp_name']) || !move_uploaded_file($altaImssInfo['tmp_name'], $destination)) {
                    throw new RuntimeException('No se pudo guardar el alta del IMSS en el servidor.');
                }

                $relativePath = 'uploads/altas_imss/' . $filename;
                $createDocs = "CREATE TABLE IF NOT EXISTS empleado_documentos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    empleado_id INT NOT NULL,
                    tipo VARCHAR(50) NOT NULL,
                    ruta_archivo VARCHAR(255) NOT NULL,
                    nombre_original VARCHAR(255) DEFAULT NULL,
                    mime_type VARCHAR(100) DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_empleado_tipo (empleado_id, tipo),
                    CONSTRAINT fk_empleado_documentos_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
                if (!$conn->query($createDocs)) {
                    unlink($destination);
                    throw new RuntimeException('No se pudo preparar el registro del alta del IMSS.');
                }

                $stmtDoc = $conn->prepare('INSERT INTO empleado_documentos (empleado_id, tipo, ruta_archivo, nombre_original, mime_type, created_at) VALUES (?, "alta_imss", ?, ?, ?, NOW())');
                if (!$stmtDoc) {
                    unlink($destination);
                    throw new RuntimeException('No se pudo preparar el guardado del alta del IMSS.');
                }
                $stmtDoc->bind_param('isss', $employeeId, $relativePath, $altaImssInfo['original'], $altaImssInfo['mime']);
                if (!$stmtDoc->execute()) {
                    $stmtDoc->close();
                    unlink($destination);
                    throw new RuntimeException('No se pudo registrar el alta del IMSS.');
                }
                $stmtDoc->close();

                $documentSaved = true;
                $documentPath = $destination;
            }

            $conn->commit();
            $transactionStarted = false;

            $feedback['type'] = 'success';
            $feedback['message'] = 'Servicio Especializado creado correctamente. Ya puede marcar asistencia.';
            foreach ($values as $key => $default) {
                $values[$key] = $key === 'proyecto_id' ? 0 : '';
            }
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            if ($documentSaved && $documentPath && file_exists($documentPath)) {
                unlink($documentPath);
            }
            if ($employeeId > 0) {
                $conn->query('DELETE FROM empleado_proyecto WHERE empleado_id = ' . (int)$employeeId);
                $conn->query('DELETE FROM empleado_documentos WHERE empleado_id = ' . (int)$employeeId);
                $conn->query('DELETE FROM empleados WHERE id = ' . (int)$employeeId);
            }
            if ($userId > 0) {
                $conn->query('DELETE FROM users WHERE id = ' . (int)$userId);
            }
            $feedback['type'] = 'error';
            $feedback['message'] = 'No se pudo completar el registro: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    } else {
        $feedback['type'] = 'error';
        $feedback['message'] = 'Corrige los siguientes puntos:<ul><li>' . implode('</li><li>', array_map(function ($msg) {
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
    <title>Nuevo Servicio Especializado - PM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/pm.css?v=<?= $pmCssVersion; ?>">
    <style>
        body {
            background: #f8fafc;
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
        .btn i {
            font-size: 14px;
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
        .pm-form-wrapper {
            max-width: 900px;
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
        .pm-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
        }
        .pm-form-grid .full-row {
            grid-column: 1 / -1;
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
        <h1><i class="fas fa-user-plus"></i> Nuevo Servicio Especializado</h1>
        <a href="../empleados.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Volver</a>
    </div>

    <p style="color:#475569; margin-bottom:20px;">Registra a un nuevo Servicio Especializado, genera sus credenciales y vincúlalo a uno de tus proyectos si lo necesitas.</p>

    <?php if ($feedback['type'] === 'success'): ?>
        <div class="pm-feedback success"><?= $feedback['message']; ?></div>
    <?php elseif ($feedback['type'] === 'error'): ?>
        <div class="pm-feedback error"><?= $feedback['message']; ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" autocomplete="off">
        <div class="pm-form-grid">
            <div>
                <label for="nombre">Nombre completo *</label>
                <input type="text" id="nombre" name="nombre" required value="<?= htmlspecialchars($values['nombre'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Ej. María Pérez" />
            </div>
            <div>
                <label for="telefono">Teléfono *</label>
                <input type="tel" id="telefono" name="telefono" required value="<?= htmlspecialchars($values['telefono'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="10 dígitos" />
            </div>
            <div>
                <label for="nss">NSS *</label>
                <input type="text" id="nss" name="nss" required value="<?= htmlspecialchars($values['nss'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="XX-XX-XX-XXXX-X" />
            </div>
            <div>
                <label for="curp">CURP *</label>
                <input type="text" id="curp" name="curp" required value="<?= htmlspecialchars($values['curp'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="18 caracteres" />
            </div>
            <div class="full-row">
                <label for="empresa">Empresa *</label>
                <select id="empresa" name="empresa" required>
                    <option value="">Selecciona una empresa</option>
                    <option value="ErgoSolar" <?= $values['empresa'] === 'ErgoSolar' ? 'selected' : ''; ?>>ErgoSolar</option>
                    <option value="Stone" <?= $values['empresa'] === 'Stone' ? 'selected' : ''; ?>>Stone</option>
                    <option value="Remedios" <?= $values['empresa'] === 'Remedios' ? 'selected' : ''; ?>>Remedios</option>
                </select>
            </div>
            <div class="full-row">
                <label for="email">Correo electrónico *</label>
                <input type="email" id="email" name="email" required value="<?= htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="usuario@correo.com" />
            </div>
            <div>
                <label for="password">Contraseña *</label>
                <input type="password" id="password" name="password" required placeholder="Mínimo 8 caracteres" />
            </div>
            <div>
                <label for="password_confirm">Confirmar contraseña *</label>
                <input type="password" id="password_confirm" name="password_confirm" required placeholder="Repite la contraseña" />
            </div>
            <div class="full-row">
                <label for="proyecto_id">Asignar a proyecto</label>
                <select id="proyecto_id" name="proyecto_id">
                    <option value="0">Sin asignación inicial</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?= (int)$project['id']; ?>" <?= $values['proyecto_id'] === (int)$project['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($project['nombre'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="pm-hint">Puedes reasignarlo más adelante desde la vista del proyecto.</div>
            </div>
            <div class="full-row">
                <label for="alta_imss">Alta IMSS (PDF, JPG o PNG) *</label>
                <input type="file" id="alta_imss" name="alta_imss" accept=".pdf,.jpg,.jpeg,.png" required />
                <div class="pm-hint">Máximo 10 MB. Este documento es necesario para habilitar su asistencia.</div>
            </div>
        </div>

        <div class="pm-form-actions">
            <a href="../empleados.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Servicio</button>
        </div>
    </form>
</div>
</body>
</html>
