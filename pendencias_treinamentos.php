<?php
require_once 'config.php';

function garantirTabelaPendenciasTreinamentos(PDO $pdo)
{
    $sql = "CREATE TABLE IF NOT EXISTS pendencias_treinamentos (
        id_pendencia INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_treinamento INT NOT NULL,
        id_cliente INT NULL,
        status_pendencia VARCHAR(20) NOT NULL DEFAULT 'ABERTA',
        observacao_finalizacao TEXT NULL,
        referencia_chamado VARCHAR(255) NULL,
        observacao_conclusao TEXT NULL,
        data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        data_atualizacao DATETIME NULL,
        data_conclusao DATETIME NULL,
        UNIQUE KEY uq_pendencia_treinamento (id_treinamento),
        KEY idx_status_pendencia (status_pendencia),
        KEY idx_cliente_pendencia (id_cliente)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($sql);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

if (!garantirTabelaPendenciasTreinamentos($pdo)) {
    header("Location: relatorio.php?msg=" . urlencode("Nao foi possivel preparar a tabela de pendencias de treinamentos."));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['concluir_pendencia'])) {
    $idPendencia = (int)($_POST['id_pendencia'] ?? 0);
    $observacaoConclusao = trim((string)($_POST['observacao_conclusao'] ?? ''));
    $dataConclusao = date('Y-m-d H:i:s');

    if ($idPendencia > 0) {
        $stmt = $pdo->prepare(
            "UPDATE pendencias_treinamentos
             SET status_pendencia = 'CONCLUIDA',
                 observacao_conclusao = ?,
                 data_conclusao = ?,
                 data_atualizacao = ?
             WHERE id_pendencia = ?"
        );
        $stmt->execute([
            $observacaoConclusao !== '' ? $observacaoConclusao : null,
            $dataConclusao,
            $dataConclusao,
            $idPendencia
        ]);
    }

    header("Location: pendencias_treinamentos.php?msg=" . urlencode("Pendencia concluida com sucesso."));
    exit;
}

$sqlPendencias = "SELECT p.*, t.tema, t.data_treinamento_encerrado, c.fantasia AS cliente_nome
                  FROM pendencias_treinamentos p
                  INNER JOIN treinamentos t ON t.id_treinamento = p.id_treinamento
                  LEFT JOIN clientes c ON c.id_cliente = p.id_cliente
                  WHERE p.status_pendencia = 'ABERTA'
                  ORDER BY t.data_treinamento_encerrado DESC, p.data_criacao DESC";
$pendencias = $pdo->query($sqlPendencias)->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h2 class="fw-bold mb-1">Pendencias de Treinamentos</h2>
        <p class="text-muted mb-0">Treinamentos encerrados com atividades pendentes em outros sistemas.</p>
    </div>
    <a href="relatorio.php" class="btn btn-outline-primary">Voltar para Agendamentos</a>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle me-2"></i> <?= htmlspecialchars($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 rounded-3 overflow-hidden">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
        <h5 class="mb-0 fw-bold text-dark">Pendencias em aberto</h5>
        <span class="badge bg-danger"><?= count($pendencias) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Treinamento</th>
                        <th>Cliente</th>
                        <th>Data encerramento</th>
                        <th>Status</th>
                        <th>Observacao de finalizacao</th>
                        <th>Referencia externo</th>
                        <th class="text-end pe-4">Acao</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pendencias)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">Nenhuma pendencia em aberto.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pendencias as $p): ?>
                            <tr>
                                <td class="ps-4 fw-bold">
                                    #<?= (int)$p['id_treinamento'] ?>
                                    <div class="small text-muted"><?= htmlspecialchars((string)($p['tema'] ?? '---')) ?></div>
                                </td>
                                <td><?= htmlspecialchars((string)($p['cliente_nome'] ?? '---')) ?></td>
                                <td><?= !empty($p['data_treinamento_encerrado']) ? date('d/m/Y H:i', strtotime($p['data_treinamento_encerrado'])) : '---' ?></td>
                                <td><span class="badge bg-warning text-dark"><?= htmlspecialchars((string)$p['status_pendencia']) ?></span></td>
                                <td style="max-width: 320px; white-space: normal;">
                                    <?= nl2br(htmlspecialchars((string)($p['observacao_finalizacao'] ?? '---'))) ?>
                                </td>
                                <td>
                                    <?php if (!empty($p['referencia_chamado'])): ?>
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars((string)$p['referencia_chamado']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">---</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <button type="button"
                                            class="btn btn-sm btn-success open-concluir-pendencia"
                                            data-id="<?= (int)$p['id_pendencia'] ?>"
                                            data-treinamento="<?= (int)$p['id_treinamento'] ?>"
                                            data-cliente="<?= htmlspecialchars((string)($p['cliente_nome'] ?? '---')) ?>">
                                        <i class="bi bi-check2-circle me-1"></i>Finalizar pendencia
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalConcluirPendencia" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold text-dark"><i class="bi bi-check2-circle me-2 text-success"></i>Finalizar pendencia</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <input type="hidden" name="concluir_pendencia" value="1">
                <input type="hidden" name="id_pendencia" id="modal_id_pendencia">

                <div class="mb-3 p-3 bg-light rounded-3">
                    <div class="small text-muted mb-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Treinamento:</div>
                    <div class="fw-bold text-primary" id="modal_pendencia_info"></div>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Observacao de conclusao (opcional)</label>
                    <textarea name="observacao_conclusao" class="form-control" rows="3" placeholder="Informe como a pendencia foi tratada."></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm">Concluir pendencia</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('.open-concluir-pendencia').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const treinamento = this.dataset.treinamento;
            const cliente = this.dataset.cliente;

            document.getElementById('modal_id_pendencia').value = id;
            document.getElementById('modal_pendencia_info').innerText = '#' + treinamento + ' | ' + cliente;

            new bootstrap.Modal(document.getElementById('modalConcluirPendencia')).show();
        });
    });
</script>

<?php include 'footer.php'; ?>
