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

$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

$sqlPendencias = "SELECT p.*, t.tema, t.data_treinamento_encerrado, c.fantasia AS cliente_nome
                  FROM pendencias_treinamentos p
                  INNER JOIN treinamentos t ON t.id_treinamento = p.id_treinamento
                  LEFT JOIN clientes c ON c.id_cliente = p.id_cliente
                  WHERE p.status_pendencia = 'ABERTA'";

$params = [];

if ($busca !== '') {
    $sqlPendencias .= " AND (c.fantasia LIKE ? OR t.tema LIKE ? OR p.referencia_chamado LIKE ? OR p.id_treinamento = ?)";
    $likeBusca = "%$busca%";
    $params = [$likeBusca, $likeBusca, $likeBusca, (int)$busca];
}

$sqlPendencias .= " ORDER BY t.data_treinamento_encerrado DESC, p.data_criacao DESC";

$stmt = $pdo->prepare($sqlPendencias);
$stmt->execute($params);
$pendencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_pendencias = count($pendencias);

include 'header.php';
?>

<style>
/* Design System Clean & Modern */
[data-theme="dark"] {
    --bg-body: #0d0e12;
    --bg-card: #16171d;
    --border-color: #2b2e35;
    --text-main: #f1f5f9;
    --text-muted: #cbd5e1;
}

body, html {
    background-color: var(--bg-body);
    color: var(--text-main);
}

/* Modern Page Header */
.modern-header {
    padding: 1rem 0 2rem 0;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 2.5rem;
}

.title-accent {
    color: var(--warning);
    background: linear-gradient(120deg, var(--warning), #fbbf24);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

/* Controls */
.control-bar {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 1rem;
    margin-bottom: 2rem;
    display: flex;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
    box-shadow: 0 4px 15px rgba(0,0,0,0.02);
}

.search-input-modern {
    background: var(--bg-body) !important;
    border: 1px solid var(--border-color) !important;
    border-radius: 10px !important;
    color: var(--text-main) !important;
    padding-left: 2.5rem !important;
    height: 42px;
}

/* Dashboard Section (Table wrapper) */
.dashboard-section {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    box-shadow: 0 4px 15px rgba(0,0,0,0.02);
    overflow: hidden;
}

/* Table overrides */
.table-premium th {
    background: var(--bg-body);
    color: var(--text-muted);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 1rem;
    border-bottom: 1px solid var(--border-color);
    border-top: none;
}
.table-premium td {
    padding: 1.25rem 1rem;
    border-bottom: 1px solid var(--border-color);
    font-size: 0.9rem;
    vertical-align: middle;
    color: var(--text-main);
}
.table-premium tbody tr {
    transition: background 0.15s ease;
}
.table-premium tbody tr:hover {
    background: rgba(255,255,255,0.03);
}

/* Badges */
.badge-premium {
    font-size: 0.72rem;
    font-weight: 800;
    padding: 0.4rem 0.8rem;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    border: 1px solid transparent;
}
.badge-warning-soft { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border-color: rgba(245,158,11,0.25); }

.gsap-reveal {
    opacity: 0;
    transform: translateY(20px);
}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

<div class="container-fluid px-lg-5 pt-4">

    <!-- Modern Header -->
    <div class="modern-header d-flex justify-content-between align-items-end gsap-reveal">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.8rem;">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Pendências</li>
                </ol>
            </nav>
            <h2 class="fw-800 mb-0">Gestão de <span class="title-accent">Pendências</span></h2>
            <p class="text-muted small mb-0">Treinamentos encerrados com atividades pendentes ou chamados vinculados.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-body border text-muted px-3 py-2 rounded-3 me-2">Total: <?= $total_pendencias ?></span>
            <a href="treinamentos.php" class="btn btn-outline-secondary px-4 fw-bold" style="border-radius: 10px; height: 42px; display: inline-flex; align-items: center;">
                <i class="bi bi-calendar-check me-2"></i> Treinamentos
            </a>
        </div>
    </div>

    <!-- Mensagens -->
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success border-0 rounded-4 mb-4 fw-bold gsap-reveal bg-success bg-opacity-10 text-success">
            <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($_GET['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Control Bar -->
    <div class="control-bar gsap-reveal">
        <div class="search-container position-relative flex-grow-1" style="max-width: 500px;">
            <i class="bi bi-search position-absolute text-muted" style="left: 1.2rem; top: 50%; transform: translateY(-50%);"></i>
            <form method="GET" action="pendencias_treinamentos.php" id="searchForm">
                <input type="text" name="busca" class="form-control search-input-modern w-100" 
                       placeholder="Buscar por cliente, tema ou referência..." value="<?= htmlspecialchars($busca) ?>">
            </form>
        </div>
    </div>

    <div class="dashboard-section p-0 mb-5 gsap-reveal">
        <div class="table-responsive" style="max-height: 700px; overflow-y: auto;">
            <table class="table table-premium mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Treinamento</th>
                        <th>Cliente</th>
                        <th>Data Encerramento</th>
                        <th>Status</th>
                        <th>Observação (Origem)</th>
                        <th>Referência Externa</th>
                        <th class="text-end pe-4">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pendencias)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <i class="bi bi-ui-checks display-1 text-muted opacity-25 mb-4 d-block"></i>
                                <h4 class="text-muted">Nenhuma pendência em aberto encontrada.</h4>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pendencias as $p): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold mb-1">#<?= (int)$p['id_treinamento'] ?></div>
                                    <div class="small opacity-75 text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars((string)($p['tema'] ?? '---')) ?>">
                                        <?= htmlspecialchars((string)($p['tema'] ?? '---')) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-bold"><i class="bi bi-building text-muted me-2"></i><?= htmlspecialchars((string)($p['cliente_nome'] ?? '---')) ?></span>
                                </td>
                                <td>
                                    <?= !empty($p['data_treinamento_encerrado']) ? date('d/m/Y', strtotime($p['data_treinamento_encerrado'])) . ' <small class="ms-1 opacity-50">' . date('H:i', strtotime($p['data_treinamento_encerrado'])) . '</small>' : '<span class="text-muted">---</span>' ?>
                                </td>
                                <td>
                                    <span class="badge-premium badge-warning-soft">
                                        <i class="bi bi-clock-history me-1"></i><?= htmlspecialchars((string)$p['status_pendencia']) ?>
                                    </span>
                                </td>
                                <td style="max-width: 280px; font-size: 0.85rem;" class="opacity-75">
                                    <?= nl2br(htmlspecialchars((string)($p['observacao_finalizacao'] ?? '---'))) ?>
                                </td>
                                <td>
                                    <?php if (!empty($p['referencia_chamado'])): ?>
                                        <div class="bg-body border d-inline-flex align-items-center px-2 py-1 rounded-3 small">
                                            <i class="bi bi-ticket-detailed me-2 text-primary"></i> <?= htmlspecialchars((string)$p['referencia_chamado']) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">---</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <button type="button"
                                            class="btn btn-primary btn-sm px-3 fw-bold open-concluir-pendencia"
                                            style="border-radius: 8px;"
                                            data-id="<?= (int)$p['id_pendencia'] ?>"
                                            data-treinamento="<?= (int)$p['id_treinamento'] ?>"
                                            data-cliente="<?= htmlspecialchars((string)($p['cliente_nome'] ?? '---')) ?>">
                                        <i class="bi bi-check2-all me-1"></i> Finalizar
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

<!-- Modal de Conclusão Premium -->
<div class="modal fade" id="modalConcluirPendencia" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 24px; overflow: hidden; background: var(--bg-card);">
            <div class="modal-header border-0 p-4 border-bottom" style="background: rgba(255,255,255,0.02); border-color: var(--border-color)!important;">
                <h5 class="fw-bold mb-0 text-main d-flex align-items-center">
                    <div class="bg-success bg-opacity-10 text-success p-2 rounded-3 me-3 d-flex align-items-center justify-content-center">
                        <i class="bi bi-check2-circle"></i>
                    </div>
                    Finalizar Pendência
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-4">
                <input type="hidden" name="concluir_pendencia" value="1">
                <input type="hidden" name="id_pendencia" id="modal_id_pendencia">

                <div class="mb-4 p-3 rounded-4" style="background: var(--bg-body); border: 1px solid var(--border-color);">
                    <div class="small text-muted mb-1 text-uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 1px;">Referente ao Treinamento</div>
                    <div class="fw-bold text-primary" id="modal_pendencia_info" style="font-size: 1.1rem;"></div>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold text-muted text-uppercase" style="letter-spacing: 0.5px;">Observação de Conclusão <span class="opacity-50 text-lowercase">(opcional)</span></label>
                    <textarea name="observacao_conclusao" class="form-control text-main" style="background: var(--bg-body); border-color: var(--border-color); border-radius: 12px; padding: 1rem;" rows="3" placeholder="Informe como esta pendência foi resolvida..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer border-0 p-4 pt-2 gap-2" style="background: rgba(255,255,255,0.02);">
                <button type="button" class="btn btn-link text-muted fw-bold text-decoration-none me-auto" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success px-4 fw-bold" style="border-radius: 12px; height: 46px;">
                    <i class="bi bi-check-lg me-2"></i> Confirmar Resolução
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof gsap !== 'undefined') {
            gsap.to(".gsap-reveal", {
                duration: 0.6,
                opacity: 1,
                y: 0,
                stagger: 0.1,
                ease: "power2.out",
                clearProps: "transform"
            });
        } else {
            document.querySelectorAll('.gsap-reveal').forEach(el => el.style.opacity = 1);
        }

        document.querySelectorAll('.open-concluir-pendencia').forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                const treinamento = this.dataset.treinamento;
                const cliente = this.dataset.cliente;

                document.getElementById('modal_id_pendencia').value = id;
                document.getElementById('modal_pendencia_info').innerText = '#' + treinamento + ' \u2014 ' + cliente;

                new bootstrap.Modal(document.getElementById('modalConcluirPendencia')).show();
            });
        });
    });
</script>

<?php include 'footer.php'; ?>
