<?php
date_default_timezone_set('America/Sao_Paulo');
require_once 'config.php';

function garantirTabelaMateriaisTreinamentosPagina(PDO $pdo): bool
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS materiais_treinamentos (
            id_material INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            id_treinamento INT NOT NULL,
            id_cliente INT NULL,
            cliente_nome VARCHAR(255) NOT NULL,
            tema_solicitado TEXT NOT NULL,
            data_solicitacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status_material VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
            data_envio DATETIME NULL,
            UNIQUE KEY uq_material_treinamento (id_treinamento),
            KEY idx_status_material (status_material),
            KEY idx_cliente_material (id_cliente)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Throwable $e) {
        error_log('Erro ao preparar materiais_treinamentos: ' . $e->getMessage());
        return false;
    }
}

if (!garantirTabelaMateriaisTreinamentosPagina($pdo)) {
    http_response_code(500);
    exit('Não foi possível preparar o controle de materiais de treinamento.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_material_enviado'])) {
    $idMaterial = (int) ($_POST['id_material'] ?? 0);
    if ($idMaterial > 0) {
        $agora = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("UPDATE materiais_treinamentos SET status_material = 'ENVIADO', data_envio = ? WHERE id_material = ? AND status_material = 'ABERTO'");
        $stmt->execute([$agora, $idMaterial]);
    }
    header('Location: materiais_treinamentos.php?msg=' . urlencode('Material marcado como enviado.'));
    exit;
}

$filtro = strtoupper(trim((string) ($_GET['status'] ?? 'ABERTO')));
if (!in_array($filtro, ['ABERTO', 'ENVIADO', 'TODOS'], true)) $filtro = 'ABERTO';
$sql = "SELECT id_material, id_treinamento, cliente_nome, tema_solicitado, data_solicitacao, status_material, data_envio FROM materiais_treinamentos";
$params = [];
if ($filtro !== 'TODOS') {
    $sql .= ' WHERE status_material = ?';
    $params[] = $filtro;
}
$sql .= " ORDER BY CASE WHEN status_material = 'ABERTO' THEN 0 ELSE 1 END, data_solicitacao DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$materiais = $stmt->fetchAll(PDO::FETCH_ASSOC);
$qtdAbertos = (int) $pdo->query("SELECT COUNT(*) FROM materiais_treinamentos WHERE status_material = 'ABERTO'")->fetchColumn();

include 'header.php';
?>
<div class="container-fluid px-lg-5 pt-4 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <div class="text-muted small mb-1">Operação / Treinamentos</div>
            <h2 class="fw-bold mb-1"><i class="bi bi-play-btn text-primary me-2"></i>Vídeos e apostilas solicitados</h2>
            <p class="text-muted mb-0">Acompanhe os materiais combinados com os clientes durante os treinamentos.</p>
        </div>
        <div class="badge bg-warning-subtle text-warning-emphasis fs-6 px-3 py-2"><?= $qtdAbertos ?> aguardando envio</div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success border-0 rounded-3 fw-semibold"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars((string) $_GET['msg'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a class="btn <?= $filtro === 'ABERTO' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?status=ABERTO">Aguardando envio</a>
        <a class="btn <?= $filtro === 'ENVIADO' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?status=ENVIADO">Enviados</a>
        <a class="btn <?= $filtro === 'TODOS' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?status=TODOS">Todos</a>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th class="ps-4">Data da solicitação</th><th>Cliente</th><th>Tema solicitado</th><th>Treinamento</th><th>Status</th><th class="text-end pe-4">Ação</th></tr></thead>
                <tbody>
                <?php if (!$materiais): ?>
                    <tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>Nenhuma solicitação nesta lista.</td></tr>
                <?php else: foreach ($materiais as $material): ?>
                    <tr>
                        <td class="ps-4 text-nowrap"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($material['data_solicitacao'])), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($material['cliente_nome'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="min-width:240px; white-space:pre-line"><?= nl2br(htmlspecialchars($material['tema_solicitado'], ENT_QUOTES, 'UTF-8')) ?></td>
                        <td>#<?= (int) $material['id_treinamento'] ?></td>
                        <td><?php if ($material['status_material'] === 'ABERTO'): ?><span class="badge text-bg-warning">Aguardando envio</span><?php else: ?><span class="badge text-bg-success">Enviado<?= $material['data_envio'] ? ' em ' . htmlspecialchars(date('d/m/Y', strtotime($material['data_envio'])), ENT_QUOTES, 'UTF-8') : '' ?></span><?php endif; ?></td>
                        <td class="text-end pe-4">
                            <?php if ($material['status_material'] === 'ABERTO'): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Confirma que o vídeo/apostila deste tema foi enviado ao cliente?')">
                                    <input type="hidden" name="marcar_material_enviado" value="1">
                                    <input type="hidden" name="id_material" value="<?= (int) $material['id_material'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success fw-bold text-nowrap"><i class="bi bi-check2-circle me-1"></i>Vídeo Enviado</button>
                                </form>
                            <?php else: ?><span class="text-muted small">Concluído</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
