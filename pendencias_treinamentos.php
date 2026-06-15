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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nova_pendencia_avulsa'])) {
    $idCliente = (int)($_POST['id_cliente'] ?? 0);
    $observacao = trim((string)($_POST['observacao'] ?? ''));

    if ($idCliente > 0 && $observacao !== '') {
        $stmt = $pdo->prepare("INSERT INTO pendencias_treinamentos (id_treinamento, id_cliente, status_pendencia, observacao_finalizacao) VALUES (NULL, ?, 'ABERTA', ?)");
        $stmt->execute([$idCliente, $observacao]);
        header("Location: pendencias_treinamentos.php?msg=" . urlencode("Pendencia avulsa criada com sucesso."));
        exit;
    }
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

$sqlPendencias = "SELECT p.*, t.tema, t.data_treinamento_encerrado, c.fantasia AS cliente_nome, c.anexo, c.chamados AS cliente_chamados
                  FROM pendencias_treinamentos p
                  LEFT JOIN treinamentos t ON t.id_treinamento = p.id_treinamento
                  LEFT JOIN clientes c ON c.id_cliente = p.id_cliente
                  WHERE p.status_pendencia = 'ABERTA'";

$params = [];

if ($busca !== '') {
    $sqlPendencias .= " AND (c.fantasia LIKE ? OR t.tema LIKE ? OR p.id_treinamento = ?)";
    $likeBusca = "%$busca%";
    $params = [$likeBusca, $likeBusca, (int)$busca];
}

$sqlPendencias .= " ORDER BY c.fantasia ASC, t.data_treinamento_encerrado DESC, p.data_criacao DESC";

$stmt = $pdo->prepare($sqlPendencias);
$stmt->execute($params);
$pendencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_pendencias = count($pendencias);

$clientes_lista = $pdo->query("SELECT id_cliente, fantasia FROM clientes WHERE status = 'EM ANDAMENTO' ORDER BY fantasia ASC")->fetchAll(PDO::FETCH_ASSOC);

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
    --primary-light: rgba(67, 97, 238, 0.15);
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

    /* Table Style */
    .table-premium {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        overflow: hidden;
        margin-bottom: 3rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
    }

    .table-premium table {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
    }

    .table-premium thead th {
        background: var(--bg-body);
        font-size: 0.75rem !important;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-muted) !important;
        padding: 1rem 1rem;
        border-bottom: 2px solid var(--border-color) !important;
        border-top: none;
    }

    .table-premium tbody tr {
        transition: all 0.2s ease;
    }

    .table-premium tbody tr:hover {
        background-color: var(--primary-light) !important;
    }

    .table-premium tbody td {
        padding: 1rem 1rem;
        border-bottom: 1px solid var(--border-color) !important;
        font-size: 0.9rem;
        color: var(--text-main);
        vertical-align: middle;
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

/* Botão GestãoPRO */
.btn-action-gestao {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: all 0.3s;
    text-decoration: none;
    color: #ff9800; 
    background: rgba(255, 152, 0, 0.1); 
    border: 1px solid rgba(255, 152, 0, 0.2);
}
.btn-action-gestao:hover { 
    background: #ff9800; 
    color: white; 
    transform: translateY(-2px); 
    box-shadow: 0 4px 10px rgba(255, 152, 0, 0.2);
}

/* Botão Chamados */
.btn-action-chamados {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: all 0.3s;
    text-decoration: none;
    color: #10b981;
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.2);
}
.btn-action-chamados:hover {
    background: #10b981;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(16, 185, 129, 0.2);
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
    </div>

    <!-- Mensagens -->
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success border-0 rounded-4 mb-4 fw-bold gsap-reveal bg-success bg-opacity-10 text-success">
            <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($_GET['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="table-premium gsap-reveal">
        <div class="p-4 border-bottom d-flex justify-content-between align-items-center" style="background: var(--bg-card);">
            <h5 class="fw-bold mb-0">Listagem de Pendências</h5>
            <button class="btn btn-warning fw-bold text-dark px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNovaPendencia" style="border-radius: 12px;">
                <i class="bi bi-plus-circle me-2"></i>Nova Pendência
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Treinamento</th>
                        <th>Cliente</th>
                        <th>Observação (Origem)</th>
                        <th class="text-center">Chamados</th>
                        <th class="text-end pe-4">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pendencias)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5">
                                <i class="bi bi-ui-checks display-1 text-muted opacity-25 mb-4 d-block"></i>
                                <h4 class="text-muted">Nenhuma pendência em aberto encontrada.</h4>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pendencias as $p): ?>
                            <tr>
                                <td class="ps-4">
                                    <?php if (!empty($p['id_treinamento'])): ?>
                                        <div class="fw-bold mb-1">#<?= (int)$p['id_treinamento'] ?></div>
                                        <div class="small opacity-75 text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars((string)($p['tema'] ?? '---')) ?>">
                                            <?= htmlspecialchars((string)($p['tema'] ?? '---')) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="fw-bold mb-1 text-warning"><i class="bi bi-star-fill me-1"></i> Avulsa</div>
                                        <div class="small opacity-75">Criada manualmente</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-bold"><i class="bi bi-building text-muted me-2"></i><?= htmlspecialchars((string)($p['cliente_nome'] ?? '---')) ?></span>
                                </td>
                                <td style="max-width: 280px; font-size: 0.85rem;" class="opacity-75">
                                    <?= nl2br(htmlspecialchars((string)($p['observacao_finalizacao'] ?? '---'))) ?>
                                </td>
                                <td class="text-center">
                                    <?php
                                        // Usa o campo chamados do cliente; se vazio, gera a partir do anexo
                                        $link_chamados_p = '';
                                        if (!empty($p['cliente_chamados'])) {
                                            $link_chamados_p = $p['cliente_chamados'];
                                        } elseif (!empty($p['anexo'])) {
                                            $base_p = (strpos($p['anexo'], 'http') === 0) ? $p['anexo'] : 'https://' . $p['anexo'];
                                            $link_chamados_p = rtrim($base_p, '?') . '?tab=chamados-abertos';
                                        }
                                    ?>
                                    <?php if (!empty($link_chamados_p)): ?>
                                        <a href="<?= htmlspecialchars($link_chamados_p) ?>" target="_blank"
                                           class="btn-action-chamados" title="Abrir Chamados do Cliente">
                                            <i class="bi bi-headset"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">---</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end align-items-center gap-2">
                                        <?php if (!empty($p['anexo'])): ?>
                                            <a href="<?= (strpos($p['anexo'], 'http') === 0) ? $p['anexo'] : 'https://' . $p['anexo'] ?>" 
                                               target="_blank" class="btn-action-gestao" title="Link GestãoPRO">
                                                <i class="bi bi-rocket-takeoff"></i>
                                            </a>
                                        <?php endif; ?>
                                        <button type="button"
                                                class="btn btn-primary btn-sm px-3 fw-bold open-concluir-pendencia"
                                                style="border-radius: 8px;"
                                                data-id="<?= (int)$p['id_pendencia'] ?>"
                                                data-treinamento="<?= $p['id_treinamento'] ? (int)$p['id_treinamento'] : 'Avulsa' ?>"
                                                data-cliente="<?= htmlspecialchars((string)($p['cliente_nome'] ?? '---')) ?>">
                                            <i class="bi bi-check2-all me-1"></i> Finalizar
                                        </button>
                                    </div>
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

<!-- Modal Nova Pendência Avulsa -->
<div class="modal fade" id="modalNovaPendencia" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 24px; overflow: hidden; background: var(--bg-card);">
            <div class="modal-header border-0 p-4 border-bottom" style="background: rgba(255,255,255,0.02); border-color: var(--border-color)!important;">
                <h5 class="fw-bold mb-0 text-main d-flex align-items-center">
                    <div class="bg-warning bg-opacity-10 text-warning p-2 rounded-3 me-3 d-flex align-items-center justify-content-center">
                        <i class="bi bi-plus-circle"></i>
                    </div>
                    Nova Pendência Avulsa
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-4">
                <input type="hidden" name="nova_pendencia_avulsa" value="1">

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase" style="letter-spacing: 0.5px;">Cliente</label>
                    <select name="id_cliente" class="form-select text-main" style="background: var(--bg-body); border-color: var(--border-color); border-radius: 12px; height: 46px;" required>
                        <option value="">Selecione um cliente...</option>
                        <?php foreach($clientes_lista as $cl): ?>
                            <option value="<?= $cl['id_cliente'] ?>"><?= htmlspecialchars($cl['fantasia']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold text-muted text-uppercase" style="letter-spacing: 0.5px;">Observação / Descrição da Pendência</label>
                    <textarea name="observacao" class="form-control text-main" style="background: var(--bg-body); border-color: var(--border-color); border-radius: 12px; padding: 1rem;" rows="4" placeholder="Descreva a pendência..." required></textarea>
                </div>
            </div>
            
            <div class="modal-footer border-0 p-4 pt-2 gap-2" style="background: rgba(255,255,255,0.02);">
                <button type="button" class="btn btn-link text-muted fw-bold text-decoration-none me-auto" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-warning text-dark px-4 fw-bold" style="border-radius: 12px; height: 46px;">
                    <i class="bi bi-save me-2"></i> Criar Pendência
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
