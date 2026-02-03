<?php
// Configurar timezone corretamente (Brasil)
date_default_timezone_set('America/Sao_Paulo');

require_once 'config.php';

// --- LÓGICA DE ALERTA: CLIENTES SEM AGENDAMENTO (PRÓXIMOS 3 DIAS) ---
$sql_alerta = "SELECT fantasia FROM clientes 
               WHERE (data_fim IS NULL OR data_fim = '0000-00-00') 
               AND id_cliente NOT IN (
                   SELECT DISTINCT id_cliente FROM treinamentos 
                   WHERE data_treinamento >= CURDATE() 
                   AND data_treinamento <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
               )";
$clientes_sem_agenda = $pdo->query($sql_alerta)->fetchAll(PDO::FETCH_COLUMN);

// --- CONTAGENS PARA OS CARDS ---
$total_pendentes = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE status = 'PENDENTE'")->fetchColumn();
$total_hoje = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE DATE(data_treinamento) = CURDATE()")->fetchColumn();

// --- FILTRO POR CLIENTE ---
$filtro_cliente = isset($_GET['filtro_cliente']) ? trim($_GET['filtro_cliente']) : '';
$where_conditions = [];
$params = [];

if (!empty($filtro_cliente)) {
    $where_conditions[] = "c.fantasia LIKE ?";
    $params[] = "%{$filtro_cliente}%";
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM treinamentos WHERE id_treinamento = ?");
    $stmt->execute([$id]);
    header("Location: treinamentos.php?msg=Removido com sucesso");
    exit;
}

// Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_cliente = $_POST['id_cliente'];
    $id_contato = $_POST['id_contato'];
    $tema = $_POST['tema'];
    $status = $_POST['status'];
    $data_treinamento = !empty($_POST['data_treinamento']) ? $_POST['data_treinamento'] : null;

    if (isset($_POST['id_treinamento']) && !empty($_POST['id_treinamento'])) {
        $stmt = $pdo->prepare("UPDATE treinamentos SET id_cliente=?, id_contato=?, tema=?, status=?, data_treinamento=? WHERE id_treinamento=?");
        $stmt->execute([$id_cliente, $id_contato, $tema, $status, $data_treinamento, $_POST['id_treinamento']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO treinamentos (id_cliente, id_contato, tema, status, data_treinamento) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$id_cliente, $id_contato, $tema, $status, $data_treinamento]);
    }
    header("Location: treinamentos.php?msg=Sucesso");
    exit;
}

// Consulta principal com filtro
$sql_treinamentos = "
    SELECT t.*, c.fantasia as cliente_nome, co.nome as contato_nome 
    FROM treinamentos t
    LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
    LEFT JOIN contatos co ON t.id_contato = co.id_contato
    $where_sql
    ORDER BY t.status ASC, t.data_treinamento DESC
";

$stmt = $pdo->prepare($sql_treinamentos);
$stmt->execute($params);
$treinamentos = $stmt->fetchAll();

$total_resultados = count($treinamentos);

$clientes_list = $pdo->query("SELECT id_cliente, fantasia FROM clientes ORDER BY fantasia ASC")->fetchAll();

include 'header.php';
?>

<div class="container-fluid py-4 bg-light min-vh-100">
    <div class="row align-items-center mb-4">
        <div class="col">
            <h4 class="fw-bold text-dark mb-1">Agenda de Treinamentos</h4>
            <p class="text-muted small">Gestão de capacitação técnica dos clientes</p>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
                <i class="bi bi-plus-lg me-2"></i>Novo Agendamento
            </button>
        </div>
    </div>

    <!-- FILTRO POR CLIENTE -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body p-3">
                    <form method="GET" action="" class="row g-3 align-items-center">
                        <div class="col-md-8">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="bi bi-search text-muted"></i>
                                </span>
                                <input type="text" 
                                       name="filtro_cliente" 
                                       class="form-control border-start-0" 
                                       placeholder="Buscar por nome do cliente..."
                                       value="<?= htmlspecialchars($filtro_cliente) ?>"
                                       style="height: 45px;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary flex-grow-1" style="height: 45px;">
                                    <i class="bi bi-funnel me-2"></i>Filtrar
                                </button>
                                <?php if(!empty($filtro_cliente)): ?>
                                    <a href="treinamentos.php" class="btn btn-outline-secondary" style="height: 45px;">
                                        <i class="bi bi-x-lg me-2"></i>Limpar
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                    
                    <?php if(!empty($filtro_cliente)): ?>
                        <div class="mt-3 alert alert-info py-2">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-info-circle me-2"></i>
                                <div>
                                    <small class="fw-bold">Filtro ativo:</small>
                                    Mostrando <?= $total_resultados ?> treinamento(s) para 
                                    <strong>"<?= htmlspecialchars($filtro_cliente) ?>"</strong>
                                    <?php if($total_resultados == 0): ?>
                                        - Nenhum resultado encontrado.
                                    <?php endif; ?>
                                </div>
                                <?php if($total_resultados > 0): ?>
                                    <div class="ms-auto">
                                        <a href="treinamentos.php" class="btn btn-sm btn-outline-info">
                                            Ver todos os treinamentos
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- CARDS DE ESTATÍSTICAS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-3 border-start border-warning border-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase">Pendentes</span>
                            <h2 class="fw-bold my-1 text-dark"><?= $total_pendentes ?></h2>
                        </div>
                        <div class="bg-warning bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-clock text-warning" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-3 border-start border-primary border-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase">Hoje</span>
                            <h2 class="fw-bold my-1 text-dark"><?= $total_hoje ?></h2>
                        </div>
                        <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-calendar-check text-primary" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-3 border-start border-danger border-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase">Críticos s/ Agenda</span>
                            <h2 class="fw-bold my-1 text-dark"><?= count($clientes_sem_agenda) ?></h2>
                        </div>
                        <div class="bg-danger bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-exclamation-triangle text-danger" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-3 border-start border-success border-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase">Total Resultados</span>
                            <h2 class="fw-bold my-1 text-dark"><?= $total_resultados ?></h2>
                        </div>
                        <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-list-check text-success" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABELA DE TREINAMENTOS -->
    <div class="card shadow-sm border-0 rounded-3 overflow-hidden">
        <div class="card-header bg-white border-bottom py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark">
                    <i class="bi bi-table me-2"></i>Lista de Treinamentos
                </h6>
                <span class="badge bg-light text-dark">
                    <i class="bi bi-file-earmark-text me-1"></i><?= $total_resultados ?> registro(s)
                </span>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4 border-0 text-muted small fw-bold text-uppercase">Cliente / Tema</th>
                        <th class="border-0 text-muted small fw-bold text-uppercase">Data Agendada</th>
                        <th class="border-0 text-muted small fw-bold text-uppercase">Contato</th>
                        <th class="border-0 text-muted small fw-bold text-uppercase text-center">Status</th>
                        <th class="border-0 text-muted small fw-bold text-uppercase text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($total_resultados > 0): ?>
                        <?php 
                        // Obter data/hora atual
                        $data_atual = new DateTime();
                        $timestamp_atual = $data_atual->getTimestamp();
                        
                        foreach ($treinamentos as $t): 
                            // LÓGICA CORRIGIDA PARA VERIFICAR VENCIMENTO
                            $isVencido = false;
                            
                            if ($t['status'] == 'PENDENTE' && !empty($t['data_treinamento'])) {
                                $data_treinamento = new DateTime($t['data_treinamento']);
                                $timestamp_treinamento = $data_treinamento->getTimestamp();
                                
                                // Verifica se a data/hora do treinamento já passou
                                if ($timestamp_treinamento < $timestamp_atual) {
                                    $isVencido = true;
                                }
                            }
                        ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($t['cliente_nome']) ?></div>
                                    <span class="badge bg-light text-dark border fw-normal"><?= htmlspecialchars($t['tema']) ?></span>
                                </td>
                                <td>
                                    <div class="<?= $isVencido ? 'text-danger fw-bold' : 'text-muted' ?> small">
                                        <i class="bi bi-calendar3 me-1"></i>
                                        <?= $t['data_treinamento'] ? date('d/m/Y H:i', strtotime($t['data_treinamento'])) : '---' ?>
                                        <?php if($isVencido): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger ms-2">VENCIDO</span>
                                        <?php elseif($t['data_treinamento'] && strtotime($t['data_treinamento']) > $timestamp_atual): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success ms-2">AGENDADO</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="small text-muted">
                                        <i class="bi bi-person me-1"></i>
                                        <?= htmlspecialchars($t['contato_nome'] ?? '---') ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $t['status'] == 'Resolvido' ? 'bg-success-subtle text-success border border-success' : 'bg-warning-subtle text-warning border border-warning' ?> px-3 py-2">
                                        <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i>
                                        <?= $t['status'] ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-group shadow-sm">
                                        <!-- 1. BOTÃO LUPA (OBSERVAÇÕES) -->
                                        <?php if (!empty($t['observacoes'])): ?>
                                            <button class="btn btn-sm btn-light border text-info view-obs-btn" 
                                                    data-bs-toggle="tooltip"
                                                    data-bs-title="Ver Observação"
                                                    data-obs="<?= htmlspecialchars($t['observacoes']) ?>"
                                                    data-cliente="<?= htmlspecialchars($t['cliente_nome']) ?>">
                                                <i class="bi bi-search"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-light border text-muted" disabled
                                                    data-bs-toggle="tooltip"
                                                    data-bs-title="Sem observações">
                                                <i class="bi bi-search"></i>
                                            </button>
                                        <?php endif; ?>

                                        <!-- 2. BOTÃO GOOGLE (SINCRONIZAR/REMOVER) -->
                                        <?php if (empty($t['google_event_id'])): ?>
                                            <button class="btn btn-sm btn-light border text-danger sync-google-btn" 
                                                    data-bs-toggle="tooltip"
                                                    data-bs-title="Sincronizar com Google Agenda"
                                                    data-id="<?= $t['id_treinamento'] ?>">
                                                <i class="bi bi-google"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-light border text-primary delete-google-btn" 
                                                    data-bs-toggle="tooltip"
                                                    data-bs-title="Remover do Google Agenda"
                                                    data-id="<?= $t['id_treinamento'] ?>"
                                                    data-event-id="<?= $t['google_event_id'] ?>">
                                                <i class="bi bi-calendar-x"></i>
                                            </button>
                                        <?php endif; ?>

                                        <!-- 3. BOTÃO EDITAR -->
                                        <button class="btn btn-sm btn-light border edit-btn"
                                                data-bs-toggle="tooltip"
                                                data-bs-title="Editar Treinamento"
                                                data-id="<?= $t['id_treinamento'] ?>"
                                                data-cliente="<?= $t['id_cliente'] ?>"
                                                data-contato="<?= $t['id_contato'] ?>"
                                                data-tema="<?= htmlspecialchars($t['tema']) ?>"
                                                data-status="<?= $t['status'] ?>"
                                                data-data="<?= $t['data_treinamento'] ? date('Y-m-d\TH:i', strtotime($t['data_treinamento'])) : '' ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <!-- 4. BOTÃO EXCLUIR -->
                                        <a href="?delete=<?= $t['id_treinamento'] ?>" 
                                           class="btn btn-sm btn-light border text-danger"
                                           data-bs-toggle="tooltip"
                                           data-bs-title="Excluir Treinamento"
                                           onclick="return confirm('Tem certeza que deseja excluir este treinamento? Esta ação não pode ser desfeita.')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-5">
                                <div class="mb-3">
                                    <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                                </div>
                                <h5 class="text-muted mb-2">Nenhum treinamento encontrado</h5>
                                <p class="text-muted mb-4">
                                    <?php if(!empty($filtro_cliente)): ?>
                                        Não foram encontrados treinamentos para o cliente "<?= htmlspecialchars($filtro_cliente) ?>"
                                    <?php else: ?>
                                        Você ainda não possui treinamentos cadastrados.
                                    <?php endif; ?>
                                </p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
                                    <i class="bi bi-plus-lg me-2"></i>Criar Primeiro Treinamento
                                </button>
                                <?php if(!empty($filtro_cliente)): ?>
                                    <a href="treinamentos.php" class="btn btn-outline-secondary ms-2">
                                        Limpar Filtro
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL PARA VER OBSERVAÇÕES -->
<div class="modal fade" id="modalViewObs" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold text-dark"><i class="bi bi-chat-left-text me-2 text-info"></i>Observações do Treinamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pb-4">
                <div class="mb-3">
                    <div class="small text-muted mb-2 text-uppercase fw-bold">
                        <i class="bi bi-building me-1"></i>Cliente:
                        <span id="view_obs_cliente" class="text-primary"></span>
                    </div>
                </div>
                <div class="p-3 bg-light rounded-3 border">
                    <h6 class="fw-bold text-muted mb-2">Observações da Finalização:</h6>
                    <div id="view_obs_text" class="mb-0 text-dark" style="white-space: pre-wrap; line-height: 1.6;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL PARA AGENDAR/EDITAR TREINAMENTO -->
<div class="modal fade" id="modalTreinamento" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold" id="modalTitle">Agendar Treinamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <input type="hidden" name="id_treinamento" id="id_treinamento">
                
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Cliente</label>
                    <select name="id_cliente" id="id_cliente" class="form-select" required onchange="filterContatos(this.value)">
                        <option value="">Selecione o cliente...</option>
                        <?php foreach ($clientes_list as $c): ?>
                            <option value="<?= $c['id_cliente'] ?>"><?= htmlspecialchars($c['fantasia']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Contato</label>
                    <select name="id_contato" id="id_contato" class="form-select" required disabled>
                        <option value="">Aguardando cliente...</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Tema</label>
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

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Data/Hora</label>
                        <input type="datetime-local" name="data_treinamento" id="data_treinamento" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="PENDENTE">PENDENTE</option>
                            <option value="Resolvido">Resolvido</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Fechar</button>
                <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
// Inicializar tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    })
    
    // Auto-focus no campo de busca
    const searchField = document.querySelector('input[name="filtro_cliente"]');
    if (searchField && !searchField.value) {
        searchField.focus();
    }
});

// AJAX Contatos
function filterContatos(id_cliente, selected_contato = null) {
    const contatoSelect = document.getElementById('id_contato');
    if (!id_cliente) {
        contatoSelect.innerHTML = '<option value="">Aguardando cliente...</option>';
        contatoSelect.disabled = true;
        return;
    }
    fetch('get_contatos_cliente.php?id_cliente=' + id_cliente)
        .then(r => r.json())
        .then(data => {
            contatoSelect.innerHTML = '<option value="">Selecione o contato...</option>';
            data.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id_contato;
                opt.textContent = c.nome;
                if (selected_contato == c.id_contato) opt.selected = true;
                contatoSelect.appendChild(opt);
            });
            contatoSelect.disabled = false;
        });
}

// 1. Modal Observação
document.querySelectorAll('.view-obs-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('view_obs_cliente').innerText = this.dataset.cliente;
        document.getElementById('view_obs_text').innerText = this.dataset.obs;
        new bootstrap.Modal(document.getElementById('modalViewObs')).show();
    });
});

// 2. Sincronização Google
document.querySelectorAll('.sync-google-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const icon = this.querySelector('i');
        const originalClass = icon.className;
        icon.className = 'spinner-border spinner-border-sm';
        
        fetch('google_calendar_sync.php?id_treinamento=' + id)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (data.already_synced) {
                        alert('Este treinamento já está sincronizado com o Google Agenda.');
                    } else {
                        alert('Sincronizado com sucesso! Evento criado no Google Agenda.');
                    }
                    location.reload();
                } else if (data.auth_url) {
                    window.location.href = data.auth_url;
                } else {
                    alert('Erro: ' + data.message);
                    icon.className = originalClass;
                }
            })
            .catch(error => {
                alert('Erro na comunicação com o servidor.');
                icon.className = originalClass;
            });
    });
});

// 3. Remover do Google Agenda (Função adicional se necessário)
document.querySelectorAll('.delete-google-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        if (confirm('Deseja remover este evento do Google Agenda?')) {
            const id = this.dataset.id;
            const eventId = this.dataset.eventId;
            const icon = this.querySelector('i');
            const originalClass = icon.className;
            icon.className = 'spinner-border spinner-border-sm';
            
            // Você precisará criar um arquivo google_calendar_remove.php para esta função
            fetch('google_calendar_remove.php?id_treinamento=' + id + '&event_id=' + eventId)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Evento removido do Google Agenda com sucesso!');
                        location.reload();
                    } else {
                        alert('Erro: ' + data.message);
                        icon.className = originalClass;
                    }
                })
                .catch(error => {
                    alert('Erro na comunicação com o servidor.');
                    icon.className = originalClass;
                });
        }
    });
});

// 4. Editar Agendamento
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('modalTitle').innerText = 'Editar Registro';
        document.getElementById('id_treinamento').value = this.dataset.id;
        document.getElementById('id_cliente').value = this.dataset.cliente;
        document.getElementById('tema').value = this.dataset.tema;
        document.getElementById('status').value = this.dataset.status;
        document.getElementById('data_treinamento').value = this.dataset.data;
        filterContatos(this.dataset.cliente, this.dataset.contato);
        new bootstrap.Modal(document.getElementById('modalTreinamento')).show();
    });
});
</script>

<?php include 'footer.php'; ?>