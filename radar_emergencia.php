<?php
require_once 'config.php';

// Data boundaries
$dias_limite_inatividade = 5;
$dias_aguardando_retorno = 5;

// Consulta principal:
// 1. Pega todos os clientes ativos (data_fim is null)
// 2. Que não tem nenhum treinamento PENDENTE
// 3. Onde o ULTIMO treinamento foi há > 5 dias (ou nunca teve e a data adesão > 5 dias)
$query_clientes_inativos = "
    SELECT 
        c.id_cliente, 
        c.fantasia, 
        c.vendedor, 
        c.data_inicio,
        MAX(t.data_treinamento) as ulimo_treinamento_data,
        (
            SELECT MAX(data_observacao) 
            FROM observacoes_cliente oc 
            WHERE oc.id_cliente = c.id_cliente AND oc.tipo = 'CONTATO'
        ) as ultimo_contato_data,
        (
            SELECT conteudo 
            FROM observacoes_cliente oc2 
            WHERE oc2.id_cliente = c.id_cliente AND oc2.tipo = 'CONTATO' 
            ORDER BY oc2.data_observacao DESC LIMIT 1
        ) as ultima_mensagem
    FROM clientes c
    LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
    WHERE (c.data_fim IS NULL OR c.data_fim = '0000-00-00')
    AND c.id_cliente NOT IN (
        SELECT DISTINCT id_cliente FROM treinamentos WHERE status = 'PENDENTE'
    )
    GROUP BY c.id_cliente, c.fantasia, c.vendedor, c.data_inicio
    HAVING 
        (ulimo_treinamento_data < DATE_SUB(CURDATE(), INTERVAL :dias_inatividade DAY)) OR 
        (ulimo_treinamento_data IS NULL AND c.data_inicio < DATE_SUB(CURDATE(), INTERVAL :dias_inatividade2 DAY))
    ORDER BY ulimo_treinamento_data ASC, c.data_inicio ASC
";

$stmt = $pdo->prepare($query_clientes_inativos);
$stmt->bindValue(':dias_inatividade', $dias_limite_inatividade, PDO::PARAM_INT);
$stmt->bindValue(':dias_inatividade2', $dias_limite_inatividade, PDO::PARAM_INT);
$stmt->execute();
$todos_inativos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lista_para_contatar = [];
$lista_aguardando_resposta = [];

$hoje_time = time();

foreach ($todos_inativos as $cliente) {
    // Calculando dias sem treinamento
    if ($cliente['ulimo_treinamento_data']) {
        $dias_sem_treinamento = floor(($hoje_time - strtotime($cliente['ulimo_treinamento_data'])) / 86400);
    } else {
        $dias_sem_treinamento = floor(($hoje_time - strtotime($cliente['data_inicio'])) / 86400);
    }
    
    $cliente['dias_sem_treinamento'] = $dias_sem_treinamento;

    // Calculando dias desde o ultimo contato
    $dias_ultimo_contato = null;
    if ($cliente['ultimo_contato_data']) {
        $dias_ultimo_contato = floor(($hoje_time - strtotime($cliente['ultimo_contato_data'])) / 86400);
    }
    $cliente['dias_ultimo_contato'] = $dias_ultimo_contato;

    // Regra de separação das abas
    if ($dias_ultimo_contato !== null && $dias_ultimo_contato <= $dias_aguardando_retorno) {
        $lista_aguardando_resposta[] = $cliente;
    } else {
        $lista_para_contatar[] = $cliente;
    }
}

include 'header.php';
?>

<style>
/* // Design System Clean & Modern (Perplexity Style) */
:root {
    --bg-body: #f8fafc;
    --bg-card: #ffffff;
    --border-color: #e2e8f0;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --primary: #4361ee;
    --primary-light: rgba(67, 97, 238, 0.08);
    --warning: #f59e0b;
    --warning-light: rgba(245, 158, 11, 0.1);
    --danger: #ef4444;
    --danger-light: rgba(239, 68, 68, 0.1);
}

[data-theme="dark"] {
    --bg-body: #0d0e12;
    --bg-card: #14151a;
    --border-color: #2b2e35;
    --text-main: #f1f5f9;
    --text-muted: #94a3b8;
    --primary-light: rgba(67, 97, 238, 0.15);
    --warning-light: rgba(245, 158, 11, 0.15);
    --danger-light: rgba(239, 68, 68, 0.15);
}

body {
    background-color: var(--bg-body) !important;
    color: var(--text-main) !important;
}

/* Page Header */
.premium-header {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 24px;
    padding: 2.5rem;
    margin-bottom: 2.5rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.05);
    position: relative;
    overflow: hidden;
}

.title-accent {
    background: linear-gradient(120deg, #ef4444, #f59e0b);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-weight: 900;
}

/* Tabs Panel */
.nav-tabs-premium {
    border-bottom: 2px solid var(--border-color);
    margin-bottom: 2rem;
    gap: 1rem;
    display: flex;
}

.nav-tabs-premium .nav-item .nav-link {
    border: none;
    background: transparent;
    color: var(--text-muted);
    font-weight: 700;
    padding: 1rem 1.5rem;
    position: relative;
    transition: all 0.3s;
    border-radius: 12px 12px 0 0;
}

.nav-tabs-premium .nav-item .nav-link:hover {
    color: var(--text-main);
    background: rgba(255,255,255,0.02);
}

.nav-tabs-premium .nav-item .nav-link.active {
    color: var(--primary);
    background: transparent;
}

.nav-tabs-premium .nav-item .nav-link.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--primary);
    border-radius: 3px 3px 0 0;
}

.nav-item-danger .nav-link.active {
    color: var(--danger) !important;
}

.nav-item-danger .nav-link.active::after {
    background: var(--danger) !important;
}

.nav-item-warning .nav-link.active {
    color: var(--warning) !important;
}

.nav-item-warning .nav-link.active::after {
    background: var(--warning) !important;
}


/* List Cards */
.client-card-premium {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 18px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    transition: all 0.3s;
    display: flex;
    justify-content: space-between;
    align-items: stretch;
}

.client-card-premium:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.05);
    border-color: var(--primary-light);
}

.client-card-danger {
    border-left: 4px solid var(--danger);
}

.client-card-warning {
    border-left: 4px solid var(--warning);
}

.client-card-critical {
    border: 1px solid var(--danger);
    background: var(--danger-light);
}

.badge-premium {
    padding: 0.5rem 1rem;
    border-radius: 10px;
    font-weight: 800;
    font-size: 0.8rem;
}

.message-box {
    background: rgba(0,0,0,0.03);
    border-radius: 8px;
    padding: 0.8rem 1rem;
    margin-top: 1rem;
    font-size: 0.85rem;
    color: var(--text-muted);
    border-left: 3px solid var(--border-color);
}
[data-theme="dark"] .message-box { background: rgba(255,255,255,0.03); }

/* Buttons */
.btn-action-premium {
    padding: 0.75rem 1.5rem;
    border-radius: 12px;
    font-weight: 700;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
}

.btn-contact {
    background: var(--primary-light);
    color: var(--primary);
    border: 1px solid transparent;
}

.btn-contact:hover {
    background: var(--primary);
    color: white;
}

/* Modals */
.modal-content {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 24px;
}
.form-control, .form-select {
    background-color: rgba(0,0,0,0.02) !important;
    border-color: var(--border-color) !important;
    color: var(--text-main) !important;
    border-radius: 12px !important;
    padding: 0.75rem 1rem !important;
}
[data-theme="dark"] .form-control, [data-theme="dark"] .form-select { background-color: rgba(255,255,255,0.02) !important; }
.form-control:focus, .form-select:focus {
    border-color: var(--primary) !important;
    box-shadow: 0 0 0 3px var(--primary-light) !important;
}

.gsap-reveal { opacity: 0; transform: translateY(20px); }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

<div class="container-fluid px-lg-5 py-5">
    
    <!-- Header -->
    <div class="premium-header gsap-reveal">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-2" style="font-size: 0.8rem;">
                        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">Dashboard</a></li>
                        <li class="breadcrumb-item active">Radar de Emergência</li>
                    </ol>
                </nav>
                <h2 class="fw-900 mb-1"><i class="bi bi-radar text-danger me-2"></i> Radar de <span class="title-accent">Emergência</span></h2>
                <p class="text-muted mb-0">Monitoramento de clientes inativos e gestão de retomada de treinamentos.</p>
            </div>
            
            <div class="d-flex gap-3">
                <div class="bg-danger bg-opacity-10 text-danger px-4 py-3 rounded-4 border border-danger border-opacity-25 text-center">
                    <div class="h3 fw-900 mb-0"><?= count($lista_para_contatar) ?></div>
                    <div class="small fw-bold text-uppercase" style="letter-spacing: 1px;">Ação Imediata</div>
                </div>
                <div class="bg-warning bg-opacity-10 text-warning px-4 py-3 rounded-4 border border-warning border-opacity-25 text-center">
                    <div class="h3 fw-900 mb-0"><?= count($lista_aguardando_resposta) ?></div>
                    <div class="small fw-bold text-uppercase" style="letter-spacing: 1px;">Aguardando</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success border-0 rounded-4 mb-4 fw-bold gsap-reveal bg-success bg-opacity-10 text-success">
            <i class="bi bi-check-circle-fill me-2"></i> <?= $_SESSION['success'] ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger border-0 rounded-4 mb-4 fw-bold gsap-reveal bg-danger bg-opacity-10 text-danger">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $_SESSION['error'] ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs-premium gsap-reveal" id="radarTabs" role="tablist">
        <li class="nav-item nav-item-danger" role="presentation">
            <button class="nav-link active" id="contatar-tab" data-bs-toggle="tab" data-bs-target="#contatar" type="button" role="tab" aria-selected="true" style="font-size: 1.1rem;">
                <i class="bi bi-exclamation-diamond-fill me-2"></i> Para Contatar
                <span class="badge bg-danger rounded-pill ms-2"><?= count($lista_para_contatar) ?></span>
            </button>
        </li>
        <li class="nav-item nav-item-warning" role="presentation">
            <button class="nav-link" id="aguardando-tab" data-bs-toggle="tab" data-bs-target="#aguardando" type="button" role="tab" aria-selected="false" style="font-size: 1.1rem;">
                <i class="bi bi-hourglass-split me-2"></i> Aguardando Resposta
                <span class="badge bg-warning text-dark rounded-pill ms-2"><?= count($lista_aguardando_resposta) ?></span>
            </button>
        </li>
    </ul>

    <!-- Tabs Content -->
    <div class="tab-content" id="radarTabsContent">
        
        <!-- Tab: Para Contatar -->
        <div class="tab-pane fade show active" id="contatar" role="tabpanel" aria-labelledby="contatar-tab">
            <?php if (empty($lista_para_contatar)): ?>
                <div class="text-center py-5 gsap-reveal mt-5">
                    <div class="bg-success bg-opacity-10 text-success d-inline-flex p-4 rounded-circle mb-4">
                        <i class="bi bi-check2-all display-3"></i>
                    </div>
                    <h3 class="fw-900">Nenhum cliente em risco imediato!</h3>
                    <p class="text-muted">Todos os clientes inativos já foram contatados recentemente.</p>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($lista_para_contatar as $c): 
                        $is_critical = $c['dias_sem_treinamento'] >= 30;
                        $card_class = $is_critical ? 'client-card-critical' : 'client-card-danger';
                    ?>
                        <div class="col-12 gsap-reveal">
                            <div class="client-card-premium <?= $card_class ?>">
                                <!-- Info Left -->
                                <div class="d-flex gap-4 flex-grow-1">
                                    <div class="text-center" style="min-width: 90px;">
                                        <div class="h2 fw-900 <?= $is_critical ? 'text-danger' : 'text-main' ?> mb-0"><?= $c['dias_sem_treinamento'] ?></div>
                                        <div class="small fw-bold text-muted text-uppercase">Dias Inativo</div>
                                    </div>
                                    
                                    <div class="vr bg-secondary opacity-25"></div>
                                    
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center gap-3 mb-1">
                                            <h4 class="fw-900 mb-0"><?= htmlspecialchars($c['fantasia']) ?></h4>
                                            <?php if ($is_critical): ?>
                                                <span class="badge bg-danger">Risco Alto (>30 dias)</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted small mb-3">
                                            <i class="bi bi-person-badge me-1"></i> Resp: <span class="fw-bold"><?= htmlspecialchars($c['vendedor'] ?: 'Não definido') ?></span>
                                            <span class="mx-2">•</span>
                                            <i class="bi bi-calendar2-x me-1"></i> Último Treino: <?= $c['ulimo_treinamento_data'] ? date('d/m/Y', strtotime($c['ulimo_treinamento_data'])) : 'Nunca realizou' ?>
                                        </div>

                                        <?php if ($c['ultima_mensagem']): ?>
                                            <div class="message-box mt-3">
                                                <div class="fw-bold text-main mb-1 d-flex justify-content-between align-items-center">
                                                    <span><i class="bi bi-chat-left-text me-1"></i> Histórico Antigo (Ignorado há <?= $c['dias_ultimo_contato'] ?> dias)</span>
                                                    <span class="small opacity-75"><?= date('d/m/Y H:i', strtotime($c['ultimo_contato_data'])) ?></span>
                                                </div>
                                                <?= nl2br(htmlspecialchars(mb_strimwidth($c['ultima_mensagem'], 0, 150, "..."))) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Actions Right -->
                                <div class="d-flex flex-column justify-content-center align-items-end gap-2 ps-4" style="min-width: 250px;">
                                    <button class="btn btn-action-premium btn-contact w-100 justify-content-center" 
                                            onclick="abrirModalContato(<?= $c['id_cliente'] ?>, '<?= htmlspecialchars(addslashes($c['fantasia'])) ?>')">
                                        <i class="bi bi-whatsapp"></i> Registrar Contato
                                    </button>
                                    <a href="treinamentos_cliente.php?id_cliente=<?= $c['id_cliente'] ?>" class="btn btn-action-premium btn-dark border-secondary w-100 justify-content-center text-white" style="background: rgba(255,255,255,0.05);">
                                        <i class="bi bi-person-lines-fill"></i> Ficha Completa
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab: Aguardando Resposta -->
        <div class="tab-pane fade" id="aguardando" role="tabpanel" aria-labelledby="aguardando-tab">
            <?php if (empty($lista_aguardando_resposta)): ?>
                 <div class="text-center py-5 mt-5">
                    <div class="bg-warning bg-opacity-10 text-warning d-inline-flex p-4 rounded-circle mb-4">
                        <i class="bi bi-hourglass display-3"></i>
                    </div>
                    <h3 class="fw-900">Nenhum cliente aguardando no momento.</h3>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($lista_aguardando_resposta as $c): ?>
                        <div class="col-12">
                            <div class="client-card-premium client-card-warning opacity-75" style="transform: scale(0.98);">
                                <!-- Info Left -->
                                <div class="d-flex gap-4 flex-grow-1">
                                    <div class="text-center" style="min-width: 90px;">
                                        <div class="h3 fw-900 text-warning mb-0"><i class="bi bi-clock-history"></i></div>
                                        <div class="small fw-bold text-muted text-uppercase mt-1"><?= $c['dias_ultimo_contato'] ?> dias</div>
                                        <div class="small text-muted" style="font-size: 0.7rem;">desde contato</div>
                                    </div>
                                    
                                    <div class="vr bg-secondary opacity-25"></div>
                                    
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center gap-3 mb-1">
                                            <h5 class="fw-900 mb-0"><?= htmlspecialchars($c['fantasia']) ?></h5>
                                            <span class="badge bg-warning text-dark">Em ciclo de espera</span>
                                        </div>
                                        
                                        <div class="text-muted small mb-2">
                                            Total de dias sem Treinamento: <span class="fw-bold"><?= $c['dias_sem_treinamento'] ?> dias</span>
                                        </div>

                                        <?php if ($c['ultima_mensagem']): ?>
                                            <div class="message-box border-warning" style="border-left-width: 3px;">
                                                <div class="fw-bold text-warning mb-1 d-flex justify-content-between">
                                                    <span>Última Interação Enviada</span>
                                                    <span class="small opacity-75"><?= date('d/m/Y H:i', strtotime($c['ultimo_contato_data'])) ?></span>
                                                </div>
                                                <?= nl2br(htmlspecialchars($c['ultima_mensagem'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Actions Right -->
                                <div class="d-flex flex-column justify-content-center align-items-end gap-2 ps-4" style="min-width: 200px;">
                                     <button class="btn btn-action-premium btn-warning w-100 justify-content-center text-dark" 
                                            onclick="abrirModalContato(<?= $c['id_cliente'] ?>, '<?= htmlspecialchars(addslashes($c['fantasia'])) ?>')">
                                        <i class="bi bi-reply"></i> Novo Registro
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Registrar Contato -->
<div class="modal fade" id="modalContatoRadar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="salvar_contato_radar.php" class="modal-content border-0">
            <input type="hidden" name="id_cliente" id="radar_id_cliente">
            
            <div class="modal-header border-0 p-4 pb-2">
                <div>
                    <h4 class="fw-900 mb-1">Registrar Contato</h4>
                    <p class="text-muted small mb-0">Cliente: <span id="radar_nome_cliente" class="fw-bold text-main"></span></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-4 pt-2">
                <div class="alert alert-info border-0 rounded-4 small mb-4 bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-info-circle-fill me-2"></i> Ao registrar um contato, este cliente será movido para a aba "Aguardando Resposta" por 5 dias.
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Título do Contato (Opcional)</label>
                    <input type="text" name="titulo" class="form-control" placeholder="Ex: Contato via WhatsApp" value="Contato Radar de Emergência">
                </div>
                
                <div class="mb-0">
                    <label class="form-label small fw-bold text-muted">Mensagem / Resumo da Interação <span class="text-danger">*</span></label>
                    <textarea name="conteudo" class="form-control" rows="4" required placeholder="Digite o que foi discutido ou enviado ao cliente..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer border-0 p-4 pt-2 gap-2">
                <button type="button" class="btn btn-dark bg-transparent border-secondary text-muted fw-bold px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-action-premium btn-primary text-white border-0 px-4">
                    <i class="bi bi-send-check"></i> Salvar Interação
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function abrirModalContato(idCliente, nomeCliente) {
        document.getElementById('radar_id_cliente').value = idCliente;
        document.getElementById('radar_nome_cliente').innerText = nomeCliente;
        var modal = new bootstrap.Modal(document.getElementById('modalContatoRadar'));
        modal.show();
    }

    document.addEventListener('DOMContentLoaded', () => {
        gsap.to(".gsap-reveal", {
            duration: 0.6,
            opacity: 1,
            y: 0,
            stagger: 0.08,
            ease: "power2.out"
        });
        
        // Tab transition animations
        const tabs = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabs.forEach(tab => {
            tab.addEventListener('shown.bs.tab', function (event) {
                const targetId = event.target.getAttribute('data-bs-target');
                const pane = document.querySelector(targetId);
                const items = pane.querySelectorAll('.gsap-reveal, .client-card-premium');
                
                gsap.fromTo(items, 
                    { opacity: 0, y: 15 },
                    { opacity: 1, y: 0, duration: 0.4, stagger: 0.05, ease: "power2.out" }
                );
            });
        });
    });
</script>

<?php include 'footer.php'; ?>
