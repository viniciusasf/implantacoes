<?php
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

// Consultas para a Tabela
$treinamentos = $pdo->query("
    SELECT t.*, c.fantasia as cliente_nome, co.nome as contato_nome 
    FROM treinamentos t
    LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
    LEFT JOIN contatos co ON t.id_contato = co.id_contato
    ORDER BY t.status ASC, t.data_treinamento ASC
")->fetchAll();

$clientes_list = $pdo->query("SELECT id_cliente, fantasia FROM clientes ORDER BY fantasia ASC")->fetchAll();

include 'header.php';
?>

<style>
    .card-stat {
        border: none !important;
        border-radius: 12px;
    }

    .table thead th {
        background-color: #f8f9fa;
        color: #6c757d;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.5px;
    }

    .btn-action {
        padding: 5px 10px;
        border-radius: 8px;
        transition: 0.2s;
    }

    .alert-custom {
        border-radius: 12px;
        border: none;
        background: #fff3cd;
        color: #856404;
    }

    .table-scroll-container {
        max-height: 65vh;
        /* Ocupa 65% da altura da janela, mantendo o layout responsivo */
        overflow-y: auto;
    }
</style>

<div class="container-fluid py-4 bg-light min-vh-100">
    <div class="row align-items-center mb-4">
        <div class="col">
            <h4 class="fw-bold text-dark mb-1">Agenda de Treinamentos</h4>
            <p class="text-muted small">Gestão de capacitação técnica dos clientes</p>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
                <i class="bi bi-calendar-plus me-2"></i>Novo Agendamento
            </button>
        </div>
    </div>



    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-stat shadow-sm border-start border-warning border-4">
                <div class="card-body">
                    <span class="text-muted small fw-bold text-uppercase">Pendentes</span>
                    <h2 class="fw-bold my-1 text-dark"><?= $total_pendentes ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat shadow-sm border-start border-primary border-4">
                <div class="card-body">
                    <span class="text-muted small fw-bold text-uppercase">Agendados para Hoje</span>
                    <h2 class="fw-bold my-1 text-dark"><?= $total_hoje ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat shadow-sm border-start border-danger border-4">
                <div class="card-body">
                    <span class="text-muted small fw-bold text-uppercase">Críticos s/ Agenda</span>
                    <h2 class="fw-bold my-1 text-dark"><?= count($clientes_sem_agenda) ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-3 overflow-hidden">
        <div class="table-responsive table-scroll-container" style="max-height: 500px;">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Cliente / Tema</th>
                        <th>Data Agendada</th>
                        <th>Contato Responsável</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody class="border-top-0">
                    <?php foreach ($treinamentos as $t):
                        $isVencido = ($t['status'] == 'PENDENTE' && strtotime($t['data_treinamento']) < time());
                    ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark"><?= htmlspecialchars($t['cliente_nome']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($t['tema']) ?></span>
                            </td>
                            <td>
                                <div class="<?= $isVencido ? 'text-danger fw-bold' : 'text-dark' ?> small">
                                    <i class="bi bi-clock me-1"></i>
                                    <?= $t['data_treinamento'] ? date('d/m/Y H:i', strtotime($t['data_treinamento'])) : 'Não definido' ?>
                                </div>
                            </td>
                            <td>
                                <div class="small text-muted"><i class="bi bi-person me-1"></i><?= htmlspecialchars($t['contato_nome'] ?? '---') ?></div>
                            </td>
                            <td class="text-center">
                                <?php if ($t['status'] == 'Resolvido'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success d-inline-flex align-items-center"
                                        style="cursor: help;"
                                        data-bs-toggle="popover"
                                        data-bs-trigger="hover focus"
                                        title="Observações do Encerramento"
                                        data-bs-content="<?= htmlspecialchars($t['observacoes'] ?? 'Sem observações registradas.') ?>">
                                        <?= $t['status'] ?>
                                        <i class="bi bi-info-circle ms-1" style="font-size: 0.8rem;"></i>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning">
                                        <?= $t['status'] ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <div class="btn-group shadow-sm">
                                    <?php if (empty($t['google_event_id'])): ?>
                                        <button class="btn btn-sm btn-outline-danger sync-google-btn" data-id="<?= $t['id_treinamento'] ?>" title="Sincronizar Google">
                                            <i class="bi bi-google"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-dark delete-google-btn" data-id="<?= $t['id_treinamento'] ?>" title="Remover da Agenda">
                                            <i class="bi bi-calendar-x"></i>
                                        </button>
                                    <?php endif; ?>

                                    <button class="btn btn-sm btn-light border edit-btn"
                                        data-id="<?= $t['id_treinamento'] ?>"
                                        data-cliente="<?= $t['id_cliente'] ?>"
                                        data-contato="<?= $t['id_contato'] ?>"
                                        data-tema="<?= htmlspecialchars($t['tema']) ?>"
                                        data-status="<?= $t['status'] ?>"
                                        data-data="<?= $t['data_treinamento'] ? date('Y-m-d\TH:i', strtotime($t['data_treinamento'])) : '' ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>

                                    <a href="?delete=<?= $t['id_treinamento'] ?>" class="btn btn-sm btn-light border text-danger" onclick="return confirm('Eliminar treinamento?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

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
                        <option value="">Selecione...</option>
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
                    <label class="form-label small fw-bold text-muted">Tema da Sessão</label>
                    <select name="tema" id="tema" class="form-select" required>
                        <option value="">ESCOLHA UM TEMA</option>
                        <option value="INSTALAÇÃO SISTEMA">INSTALAÇÃO SISTEMA</option>
                        <option value="CADASTROS">CADASTROS</option>
                        <option value="ORÇAMENTO DE VENDA">ORÇAMENTO DE VENDA</option>
                        <option value="ENTRADA DE COMPRA">ENTRADA DE COMPRA</option>
                        <option value="PDV">PDV</option>                        
                        <option value="NOTA FISCAL">NOTA FISCAL</option>
                        <option value="NOTA FISCAL SERVIÇO">NOTA FISCAL SERVIÇO</option>
                        <option value="PRODUÇÃO/OS">PRODUÇÃO/OS</option>
                        <option value="GNRE">GNRE</option>
                        <option value="MDF">MDF</option>
                        <option value="RELATÓRIOS">RELATÓRIOS</option>
                        <option value="IMPRESSOES">IMPRESSÕES</option>
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
                            <option value="RESOLVIDO">RESOLVIDO</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Fechar</button>
                <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">Salvar Agendamento</button>
            </div>
        </form>
    </div>
</div>

<script>
    // 1. Função para carregar contatos via AJAX
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

    // 2. Lógica de Sincronização Google
    document.querySelectorAll('.sync-google-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const icon = this.querySelector('i');
            icon.className = 'spinner-border spinner-border-sm';
            this.disabled = true;
            fetch('google_calendar_sync.php?id_treinamento=' + id)
                .then(r => r.json())
                .then(data => {
                    if (data.auth_url) window.location.href = data.auth_url;
                    else {
                        alert(data.message);
                        if (data.success) location.reload();
                        else {
                            icon.className = 'bi bi-google';
                            this.disabled = false;
                        }
                    }
                });
        });
    });

    // 3. Remover do Google Agenda
    document.querySelectorAll('.delete-google-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!confirm('Remover agendamento do Google Agenda?')) return;
            const id = this.dataset.id;
            const icon = this.querySelector('i');
            icon.className = 'spinner-border spinner-border-sm';
            fetch('google_calendar_delete.php?id_treinamento=' + id)
                .then(r => r.json())
                .then(data => {
                    alert(data.message);
                    location.reload();
                });
        });
    });

    // 4. Lógica para EDITAR (Preencher Modal)
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('modalTitle').innerText = 'Editar Treinamento';
            document.getElementById('id_treinamento').value = this.dataset.id;
            document.getElementById('id_cliente').value = this.dataset.cliente;
            document.getElementById('tema').value = this.dataset.tema;
            document.getElementById('status').value = this.dataset.status;
            document.getElementById('data_treinamento').value = this.dataset.data;
            filterContatos(this.dataset.cliente, this.dataset.contato);

            var myModal = new bootstrap.Modal(document.getElementById('modalTreinamento'));
            myModal.show();
        });
    });

    // 5. LIMPEZA DO MODAL AO FECHAR
    const modalElement = document.getElementById('modalTreinamento');
    modalElement.addEventListener('hidden.bs.modal', function() {
        document.getElementById('modalTitle').innerText = 'Agendar Treinamento';
        const form = this.querySelector('form');
        form.reset();
        document.getElementById('id_treinamento').value = '';
        const contatoSelect = document.getElementById('id_contato');
        contatoSelect.innerHTML = '<option value="">Aguardando cliente...</option>';
        contatoSelect.disabled = true;
    });

    // Ativa todos os popovers da página (Bootstrap 5)
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
    var popoverList = popoverTriggerList.map(function(popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl)
    })
</script>

<?php include 'footer.php'; ?>