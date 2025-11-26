<?php
require_once __DIR__ . '/includes/admin_init.php';

// Obtener documentos SUA de empresas
$filtroEmpresa = $_GET['empresa'] ?? '';
$documentos = [];

$sql = "SELECT d.*, u.name AS usuario_nombre, u.email AS usuario_email 
    FROM empresa_sua_documentos d 
    LEFT JOIN users u ON d.user_id = u.id 
    WHERE 1=1";
$params = [];
$types = '';

if ($filtroEmpresa !== '') {
    $sql .= " AND d.empresa = ?";
    $params[] = $filtroEmpresa;
    $types .= 's';
}

$sql .= " ORDER BY d.created_at DESC LIMIT 100";

if ($stmt = $conn->prepare($sql)) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $documentos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Obtener lista de empresas con documentos
$empresasConDocs = [];
$result = $conn->query("SELECT DISTINCT empresa FROM empresa_sua_documentos ORDER BY empresa");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $empresasConDocs[] = $row['empresa'];
    }
}

$pageTitle = 'Documentos SUA de Empresas - ErgoCuida';
$activePage = 'empresa';
$pageHeading = 'Documentos SUA de Empresas';
$pageDescription = 'Visualiza los documentos SUA subidos por los representantes de cada empresa.';
include __DIR__ . '/includes/header.php';
?>

<div class="section">
    <h3><i class="fas fa-filter"></i> Filtros</h3>
    <form method="GET" class="filters-grid" style="margin-bottom: 0;">
        <div class="form-group">
            <label for="empresa">Filtrar por empresa</label>
            <select id="empresa" name="empresa" class="form-control" onchange="this.form.submit()">
                <option value="">Todas las empresas</option>
                <?php foreach (['CEDISA', 'Stone', 'Remedios'] as $emp): ?>
                    <option value="<?= htmlspecialchars($emp); ?>" <?= $filtroEmpresa === $emp ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($emp); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <div>
                <a href="empresa_documentos.php" class="btn btn-secondary btn-compact"><i class="fas fa-rotate"></i> Limpiar</a>
            </div>
        </div>
    </form>
</div>

<div class="section">
    <h3><i class="fas fa-file-pdf"></i> Documentos Subidos</h3>
    
    <?php if (empty($documentos)): ?>
        <div class="empty-state">
            <i class="fas fa-folder-open"></i>
            <h4>Sin documentos</h4>
            <p>No hay documentos SUA subidos<?= $filtroEmpresa ? ' para ' . htmlspecialchars($filtroEmpresa) : ''; ?>.</p>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Documento</th>
                        <th>Empresa</th>
                        <th>Subido por</th>
                        <th>Fecha documento</th>
                        <th>Fecha subida</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documentos as $doc): ?>
                        <tr>
                            <td>
                                <div class="table-entity">
                                    <strong><?= htmlspecialchars($doc['nombre'] ?? ''); ?></strong>
                                    <?php if (!empty($doc['descripcion'])): ?>
                                        <span class="table-note"><?= htmlspecialchars(mb_substr($doc['descripcion'], 0, 50)); ?>...</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge" style="background: #e0f2fe; color: #0369a1;">
                                    <?= htmlspecialchars($doc['empresa'] ?? ''); ?>
                                </span>
                            </td>
                            <td>
                                <?= htmlspecialchars($doc['usuario_nombre'] ?? 'Desconocido'); ?>
                                <?php if (!empty($doc['usuario_email'])): ?>
                                    <br><small style="color: #64748b;"><?= htmlspecialchars($doc['usuario_email']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $doc['fecha_documento'] ? date('d/m/Y', strtotime($doc['fecha_documento'])) : '-'; ?>
                            </td>
                            <td>
                                <?= date('d/m/Y H:i', strtotime($doc['created_at'])); ?>
                            </td>
                            <td>
                                <a href="../<?= htmlspecialchars($doc['ruta_archivo']); ?>" target="_blank" class="btn btn-secondary btn-compact">
                                    <i class="fas fa-eye"></i> Ver
                                </a>
                                <a href="../<?= htmlspecialchars($doc['ruta_archivo']); ?>" download class="btn btn-primary btn-compact">
                                    <i class="fas fa-download"></i> Descargar
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
