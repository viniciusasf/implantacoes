<?php
require_once 'config.php';

$id_cliente = isset($_GET['id_cliente']) ? $_GET['id_cliente'] : null;


if (!$id_cliente) {
    header("Location: clientes.php");
    exit;
}



// 1. Busca os dados do cliente para o cabeçalho
$stmtCli = $pdo->prepare("SELECT fantasia, servidor, vendedor FROM clientes WHERE id_cliente = ?");
$stmtCli->execute([$id_cliente]);
$cliente = $stmtCli->fetch();

if (!$cliente) {
    header("Location: clientes.php");
    exit;
}

// 2. Buscar contatos deste cliente específico
try {
    $stmtTest = $pdo->prepare("SHOW COLUMNS FROM contatos");
    $stmtTest->execute();
    $colunas = $stmtTest->fetchAll(PDO::FETCH_COLUMN);

    $colunas_disponiveis = [];
    if (in_array('nome', $colunas)) $colunas_disponiveis[] = 'nome';
    if (in_array('telefone', $colunas)) $colunas_disponiveis[] = 'telefone';
    if (in_array('celular', $colunas)) $colunas_disponiveis[] = 'celular';
    if (in_array('email', $colunas)) $colunas_disponiveis[] = 'email';
    if (in_array('telefone_ddd', $colunas)) $colunas_disponiveis[] = 'telefone_ddd';

    if (empty($colunas_disponiveis)) {
        $colunas_disponiveis[] = 'nome';
    }

    $query_contatos = "SELECT id_contato, " . implode(', ', $colunas_disponiveis) . " FROM contatos WHERE id_cliente = ? ORDER BY nome ASC";
    $stmtContatos = $pdo->prepare($query_contatos);
    $stmtContatos->execute([$id_cliente]);
    $contatos = $stmtContatos->fetchAll();
} catch (PDOException $e) {
    $stmtContatos = $pdo->prepare("SELECT id_contato, nome FROM contatos WHERE id_cliente = ? ORDER BY nome ASC");
    $stmtContatos->execute([$id_cliente]);
    $contatos = $stmtContatos->fetchAll();
}

// 3. Busca todos os treinamentos
$stmt = $pdo->prepare("SELECT * FROM treinamentos WHERE id_cliente = ? ORDER BY data_treinamento DESC, id_treinamento DESC");
$stmt->execute([$id_cliente]);
$treinamentos = $stmt->fetchAll();



// 5. Estatísticas
$total_treinamentos = count($treinamentos);
$treinamentos_realizados = 0;
$treinamentos_pendentes = 0;
$ultimo_treinamento = null;
$primeiro_treinamento = null;

if ($total_treinamentos > 0) {
    foreach ($treinamentos as $t) {
        $status = strtoupper($t['status'] ?? '');
        if ($status == 'REALIZADO' || $status == 'RESOLVIDO') {
            $treinamentos_realizados++;
        } elseif ($status == 'PENDENTE') {
            $treinamentos_pendentes++;
        }
    }

    $ultimo_treinamento = $treinamentos[0]['data_treinamento'] ?? null;
    $primeiro_treinamento = end($treinamentos)['data_treinamento'] ?? null;
}

include 'header.php';
?>

<style>
/* // Design System Clean & Modern (Perplexity Style) */
:root {
    --bg-body: #f1f5f9;
    --bg-card: #ffffff;
    --border-color: #e2e8f0;
    --text-main: #1a202c;
    --text-muted: #64748b;
    --primary-light: rgba(67, 97, 238, 0.08);
    --success-light: rgba(16, 185, 129, 0.08);
    --purple-light: rgba(114, 9, 183, 0.08);
    --bg-observation: #f8fafc;
    --text-observation: #475569;
}

[data-theme="dark"] {
    --bg-body: #0d0e12;
    --bg-card: #14151a;
    --border-color: #2b2e35;
    --text-main: #f1f5f9;
    --text-muted: #94a3b8;
    --primary-light: rgba(67, 97, 238, 0.15);
    --success-light: rgba(16, 185, 129, 0.15);
    --purple-light: rgba(114, 9, 183, 0.15);
    --bg-observation: rgba(255, 255, 255, 0.03);
    --text-observation: #94a3b8;
}

body {
    background-color: var(--bg-body) !important;
    color: var(--text-main) !important;
}

/* Premium Header */
.modern-header {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 24px;
    padding: 2.5rem;
    margin-bottom: 2.5rem;
    box-shadow: 0 8px 32px rgba(0,0,0,0.3);
    position: relative;
    overflow: hidden;
}

.modern-header::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 350px;
    height: 350px;
    background: radial-gradient(circle, var(--primary-light) 0%, transparent 70%);
    opacity: 0.5;
    z-index: 0;
}

.title-accent {
    color: var(--primary);
    background: linear-gradient(120deg, var(--primary), var(--purple));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-weight: 800;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin-bottom: 3rem;
}

.stat-card-premium {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 1.75rem;
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    position: relative;
    text-align: center;
}

.stat-card-premium:hover {
    transform: translateY(-8px);
    border-color: var(--primary);
    box-shadow: 0 15px 30px rgba(0,0,0,0.4);
}

.stat-icon-circle {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    margin: 0 auto 1.5rem auto;
}

/* Timeline Customization */
.timeline-container {
    position: relative;
    padding: 1rem 0;
}

.timeline-line {
    position: absolute;
    left: 20px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--border-color);
    border-radius: 2px;
}

.timeline-item {
    position: relative;
    margin-bottom: 2.5rem;
    padding-left: 65px;
    opacity: 0;
    transform: translateY(20px);
}

.timeline-dot {
    position: absolute;
    left: 11px;
    top: 0;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--bg-body);
    border: 4px solid var(--primary);
    z-index: 2;
    box-shadow: 0 0 0 5px var(--bg-body);
}

.timeline-card-premium {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 22px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.timeline-card-premium:hover {
    border-color: var(--primary-light);
    transform: translateX(10px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}

.timeline-header-premium {
    background: rgba(255,255,255,0.02);
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.timeline-footer-premium {
    background: rgba(0,0,0,0.15);
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border-color);
    color: var(--text-muted);
    font-size: 0.75rem;
}

/* Buttons and Inputs */
.btn-premium {
    padding: 0.8rem 1.8rem;
    border-radius: 12px;
    font-weight: 700;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.9rem;
}

.btn-primary-premium { background: var(--primary); color: white; border: none; }
.btn-primary-premium:hover { background: #3a56d4; transform: translateY(-3px); box-shadow: 0 8px 20px rgba(67, 97, 238, 0.4); color: white;}

.btn-purple-premium { background: #7209b7; color: white; border: none; }
.btn-purple-premium:hover { background: #5a08a5; transform: translateY(-3px); box-shadow: 0 8px 20px rgba(114, 9, 183, 0.4); color: white;}

/* Modal & Form Fixes */
.modal-content {
    background-color: var(--bg-card) !important;
    border: 1px solid var(--border-color) !important;
    color: var(--text-main) !important;
    border-radius: 28px !important;
}

.form-control, .form-select {
    background-color: rgba(0,0,0,0.3) !important;
    border-color: var(--border-color) !important;
    color: var(--text-main) !important;
    padding: 0.8rem 1rem !important;
    border-radius: 14px !important;
}

.form-control:focus, .form-select:focus {
    background-color: rgba(0,0,0,0.4) !important;
    border-color: var(--primary) !important;
    box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15) !important;
}

.observation-card-premium {
    background: rgba(255,255,255,0.02);
    border: 1px solid var(--border-color);
    border-radius: 18px;
    margin-bottom: 1.5rem;
    transition: all 0.3s;
}
.observation-card-premium:hover { border-color: #7209b7; background: rgba(114, 9, 183, 0.05); }

.observation-box-timeline {
    padding: 1.25rem;
    background: var(--bg-observation);
    border-radius: 14px;
    border-left: 4px solid var(--primary);
    margin-bottom: 1.5rem;
    color: var(--text-observation);
    font-size: 0.9rem;
    line-height: 1.6;
}

/* GSAP Helper */
.gsap-reveal { opacity: 0; transform: translateY(30px); }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

<div class="container-fluid px-lg-5 py-5">
    <!-- Premium Header -->
    <div class="modern-header gsap-reveal">
        <div class="position-relative z-1">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-4">
                <div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-2" style="font-size: 0.75rem;">
                            <li class="breadcrumb-item"><a href="clientes.php" class="text-decoration-none text-muted">Gestão</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Ficha do Cliente</li>
                        </ol>
                    </nav>
                    <h2 class="fw-800 mb-1">Histórico: <span class="title-accent"><?= htmlspecialchars($cliente['fantasia']) ?></span></h2>
                    <div class="d-flex flex-wrap gap-4 mt-3">
                        <div class="text-muted small"><i class="bi bi-cpu me-1"></i> <span class="fw-bold">Servidor:</span> <?= htmlspecialchars($cliente['servidor'] ?: '---') ?></div>
                        <div class="text-muted small"><i class="bi bi-person-badge me-1"></i> <span class="fw-bold">Vendedor:</span> <?= htmlspecialchars($cliente['vendedor'] ?: '---') ?></div>
                        <div class="text-muted small"><i class="bi bi-fingerprint me-1"></i> <span class="fw-bold">ID:</span> #<?= $id_cliente ?></div>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn-premium btn-primary-premium" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
                        <i class="bi bi-plus-lg"></i> Novo Treinamento
                    </button>
                    <a href="clientes.php" class="btn btn-outline-secondary btn-premium border-0"><i class="bi bi-arrow-left"></i> Voltar</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid gsap-reveal">
        <div class="stat-card-premium">
            <div class="stat-icon-circle bg-primary bg-opacity-10 text-primary">
                <i class="bi bi-journal-check"></i>
            </div>
            <div class="h2 fw-800 mb-1"><?= $total_treinamentos ?></div>
            <div class="text-muted small fw-bold text-uppercase letter-spacing-1">Sessões Totais</div>
        </div>
        <div class="stat-card-premium">
            <div class="stat-icon-circle bg-success bg-opacity-10 text-success">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="h2 fw-800 mb-1 text-success"><?= $treinamentos_realizados ?></div>
            <div class="text-muted small fw-bold text-uppercase letter-spacing-1">Concluídos</div>
        </div>
        <div class="stat-card-premium">
            <div class="stat-icon-circle bg-warning bg-opacity-10 text-warning">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="h2 fw-800 mb-1 text-warning"><?= $treinamentos_pendentes ?></div>
            <div class="text-muted small fw-bold text-uppercase letter-spacing-1">Aguardando</div>
        </div>

    </div>

    <!-- Timeline Area -->
    <div class="gsap-reveal mt-5">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div class="bg-primary bg-opacity-10 p-2 rounded-3">
                <i class="bi bi-calendar-range fs-4 text-primary"></i>
            </div>
            <h4 class="fw-800 mb-0">Jornada do Cliente</h4>
        </div>

        <?php if ($total_treinamentos > 0): ?>
            <div class="timeline-container">
                <div class="timeline-line"></div>

                <?php foreach ($treinamentos as $index => $t):
                    $status = strtoupper($t['status'] ?? 'PENDENTE');
                    $isResolved = ($status == 'REALIZADO' || $status == 'RESOLVIDO');
                    $status_color = $isResolved ? '#10b981' : ($status == 'PENDENTE' ? '#f59e0b' : ($status == 'CANCELADO' ? '#ef4444' : '#3b82f6'));
                    $status_text = $isResolved ? 'Realizado' : ( ($status == 'PENDENTE') ? 'Pendente' : (($status == 'CANCELADO') ? 'Cancelado' : 'Agendado'));

                    $data_t = ($t['data_treinamento'] ?? null) ? strtotime($t['data_treinamento']) : 0;
                    $data_br = $data_t ? date('d/m/Y', $data_t) : '---';
                    $data_full = $data_t ? date('d/m/Y H:i', $data_t) : '---';
                ?>
                    <div class="timeline-item">
                        <div class="timeline-dot" style="border-color: <?= $status_color ?>;"></div>
                        <div class="timeline-card-premium">
                            <div class="timeline-header-premium">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="fw-800 text-main fs-5"><?= $data_br ?></div>
                                    <span class="badge" style="background: <?= $status_color ?>15; color: <?= $status_color ?>; border: 1px solid <?= $status_color ?>30; font-size: 0.65rem; padding: 0.4rem 0.8rem;">
                                        <?= strtoupper($status_text) ?>
                                    </span>
                                </div>
                                <button class="btn btn-sm btn-outline-secondary border-0 edit-treinamento-btn px-3"
                                    data-id="<?= $t['id_treinamento'] ?? '' ?>"
                                    data-data_treinamento="<?= $t['data_treinamento'] ?? '' ?>"
                                    data-tema="<?= htmlspecialchars($t['tema'] ?? '') ?>"
                                    data-status="<?= $t['status'] ?? '' ?>"
                                    data-id_contato="<?= $t['id_contato'] ?? '' ?>"
                                    data-google_event_link="<?= htmlspecialchars($t['google_event_link'] ?? '') ?>"
                                    data-observacoes="<?= htmlspecialchars($t['observacoes'] ?? '') ?>"
                                    style="background: rgba(255,255,255,0.03);">
                                    <i class="bi bi-pencil-square me-1"></i> Ajustar
                                </button>
                            </div>
                            <div class="timeline-body p-4">
                                <h5 class="fw-800 mb-3 d-flex align-items-center gap-2">
                                    <div style="width: 8px; height: 8px; border-radius: 50%; background: <?= $status_color ?>;"></div>
                                    <?= htmlspecialchars($t['tema']) ?>
                                </h5>
                                
                                <?php if (!empty($t['observacoes'])): ?>
                                    <div class="observation-box-timeline">
                                        <?= nl2br(htmlspecialchars($t['observacoes'])) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex flex-wrap gap-4 mt-3">
                                    <?php 
                                        $nome_contato = '';
                                        if(!empty($t['id_contato'])) {
                                            foreach($contatos as $cx) {
                                                if(($cx['id_contato'] ?? null) == $t['id_contato']) {
                                                    $nome_contato = $cx['nome'] ?? '---';
                                                }
                                            }
                                        }
                                        if($nome_contato): 
                                    ?>
                                        <div class="small text-muted"><i class="bi bi-person me-1"></i> <span class="fw-bold">Interlocutor:</span> <?= htmlspecialchars($nome_contato) ?></div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($t['google_event_link'])): ?>
                                        <a href="<?= htmlspecialchars($t['google_event_link']) ?>" target="_blank" class="small text-decoration-none text-primary fw-bold">
                                            <i class="bi bi-calendar2-event me-1"></i> Abrir na Agenda
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="timeline-footer-premium d-flex justify-content-between">
                                <span>REGISTRO PROTOCOLO #<?= $t['id_treinamento'] ?></span>
                                <span><i class="bi bi-clock me-1"></i> <?= $data_full ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="stat-card-premium text-center py-5">
                <i class="bi bi-journal-x display-4 text-muted opacity-25 mb-3"></i>
                <h5 class="text-muted">Sem registros de atividades</h5>
                <p class="text-muted small mb-4">Este cliente ainda não iniciou sua jornada de treinamentos.</p>
                <button class="btn-premium btn-primary-premium" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
                    <i class="bi bi-plus-lg"></i> Iniciar Jornada
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>



<!-- MODAL PARA AGENDAR/EDITAR TREINAMENTO -->
<div class="modal fade" id="modalTreinamento" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="salvar_treinamento.php" class="modal-content">
            <input type="hidden" name="id_cliente" value="<?= $id_cliente ?>">
            <input type="hidden" name="id_treinamento" id="id_treinamento">
            <div class="modal-header border-0 p-4">
                <h5 class="fw-800 mb-0" id="modalTitle">Agendar Treinamento</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 pt-0">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Apoio Técnico ao Cliente</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($cliente['fantasia']) ?>" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Selecione o Contato</label>
                    <select name="id_contato" id="id_contato" class="form-select" required>
                        <option value="">Buscar contato...</option>
                        <?php foreach($contatos as $cx): ?>
                            <option value="<?= $cx['id_contato'] ?>"><?= htmlspecialchars($cx['nome'] ?? '---') ?> (<?= ($cx['celular'] ?? '') ?: ($cx['telefone'] ?? '') ?: 'S/ Tel' ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Módulo/Tema</label>
                    <select name="tema" id="tema" class="form-select" required>
                        <option value="INSTALAÇÃO SISTEMA">INSTALAÇÃO SISTEMA</option>
                        <option value="CADASTROS">CADASTROS</option>
                        <option value="ORÇAMENTO DE VENDA">ORÇAMENTO DE VENDA</option>
                        <option value="ENTRADA DE COMPRA">ENTRADA DE COMPRAS</option>
                        <option value="FINANCEIRO">FINANCEIRO</option>
                        <option value="PRODUÇÃO/OS">PRODUÇÃO/OS</option>
                        <option value="PDV">PDV</option>
                        <option value="NOTA FISCAL">NOTA FISCAL</option>
                        <option value="RELATÓRIOS">RELATÓRIOS</option>
                        <option value="OUTROS">OUTROS</option>
                    </select>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold text-muted">Data e Horário</label>
                        <input type="datetime-local" name="data_treinamento" id="data_treinamento" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold text-muted">Status Atual</label>
                        <select name="status" id="status" class="form-select">
                            <option value="PENDENTE">PENDENTE</option>
                            <option value="AGENDADO">AGENDADO</option>
                            <option value="REALIZADO">REALIZADO</option>
                            <option value="CANCELADO">CANCELADO</option>
                            <option value="Resolvido">Resolvido</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Link Google Agenda</label>
                    <input type="url" name="google_event_link" id="google_event_link" class="form-control" placeholder="https://...">
                </div>
                <div class="mb-0">
                    <label class="form-label small fw-bold text-muted">Minuta / Observações</label>
                    <textarea name="observacoes" id="observacoes" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-black text-muted fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn-premium btn-primary-premium">Processar Registro</button>
            </div>
        </form>
    </div>
</div>

<script>
    // GSAP Entrance Animations
    document.addEventListener('DOMContentLoaded', function() {
        gsap.to(".gsap-reveal", {
            opacity: 1,
            y: 0,
            duration: 0.8,
            stagger: 0.15,
            ease: "power2.out"
        });

        gsap.to(".timeline-item", {
            opacity: 1,
            y: 0,
            duration: 0.6,
            stagger: 0.1,
            ease: "power2.out",
            delay: 0.5
        });

        // Setup edit buttons
        document.querySelectorAll('.edit-treinamento-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const data = this.dataset;
                document.getElementById('modalTitle').innerText = 'Editar Registro';
                document.getElementById('id_treinamento').value = data.id;
                document.getElementById('tema').value = data.tema;
                document.getElementById('status').value = data.status || 'PENDENTE';
                document.getElementById('id_contato').value = data.id_contato || '';
                document.getElementById('google_event_link').value = data.google_event_link || '';
                document.getElementById('observacoes').value = data.observacoes || '';
                
                if (data.data_treinamento) {
                    const d = data.data_treinamento.replace(' ', 'T').slice(0, 16);
                    document.getElementById('data_treinamento').value = d;
                }

                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTreinamento')).show();
            });
        });
    });

    const modalTreinamento = document.getElementById('modalTreinamento');
    if (modalTreinamento) {
        modalTreinamento.addEventListener('hidden.bs.modal', function () {
            document.getElementById('id_treinamento').value = '';
            document.getElementById('modalTitle').innerText = 'Agendar Treinamento';
            document.getElementById('observacoes').value = '';
        });
    }
</script>

<?php include 'footer.php'; ?>
