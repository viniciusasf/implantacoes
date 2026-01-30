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

// Consulta principal
$treinamentos = $pdo->query("
    SELECT t.*, c.fantasia as cliente_nome, co.nome as contato_nome 
    FROM treinamentos t
    LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
    LEFT JOIN contatos co ON t.id_contato = co.id_contato
    ORDER BY t.status ASC, t.data_treinamento DESC
")->fetchAll();

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

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-3 border-start border-warning border-4">
                <div class="card-body">
                    <span class="text-muted small fw-bold text-uppercase">Pendentes</span>
                    <h2 class="fw-bold my-1 text-dark"><?= $total_pendentes ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-3 border-start border-primary border-4">
                <div class="card-body">
                    <span class="text-muted small fw-bold text-uppercase">Hoje</span>
                    <h2 class="fw-bold my-1 text-dark"><?= $total_hoje ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-3 border-start border-danger border-4">
                <div class="card-body">
                    <span class="text-muted small fw-bold text-uppercase">Críticos s/ Agenda</span>
                    <h2 class="fw-bold my-1 text-dark"><?= count($clientes_sem_agenda) ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-3 overflow-hidden">
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
                    <?php foreach ($treinamentos as $t): 
                        $isVencido = ($t['status'] == 'PENDENTE' && strtotime($t['data_treinamento']) < time());
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
                                </div>
                            </td>
                            <td>
                                <span class="small text-muted"><?= htmlspecialchars($t['contato_nome'] ?? '---') ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge <?= $t['status'] == 'Resolvido' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?> px-3">
                                    <?= $t['status'] ?>
                                </span>
                            </td>
                            <td class="text-end pe-4">
                                <div class="btn-group shadow-sm">
                                    <?php if (!empty($t['observacoes'])): ?>
                                        <button class="btn btn-sm btn-light border text-info view-obs-btn" 
                                                data-obs="<?= htmlspecialchars($t['observacoes']) ?>"
                                                data-cliente="<?= htmlspecialchars($t['cliente_nome']) ?>"
                                                title="Ver Observação">
                                            <i class="bi bi-search"></i>
                                        </button>
                                    <?php endif; ?>

                                    <?php if (empty($t['google_event_id'])): ?>
                                        <button class="btn btn-sm btn-light border text-danger sync-google-btn" data-id="<?= $t['id_treinamento'] ?>" title="Sincronizar Google">
                                            <i class="bi bi-google"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-light border text-dark delete-google-btn" data-id="<?= $t['id_treinamento'] ?>" title="Remover da Agenda">
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

                                    <a href="?delete=<?= $t['id_treinamento'] ?>" class="btn btn-sm btn-light border text-danger" onclick="return confirm('Deseja excluir?')">
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

<div class="modal fade" id="modalViewObs" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold text-dark"><i class="bi bi-chat-left-text me-2 text-info"></i>Observações</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pb-4">
                <div class="p-3 bg-light rounded-3">
                    <div class="small text-muted mb-2 text-uppercase fw-bold">Cliente: <span id="view_obs_cliente" class="text-primary"></span></div>
                    <p id="view_obs_text" class="mb-0 text-dark" style="white-space: pre-wrap;"></p>
                </div>
            </div>
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

    // Modal Observação
    document.querySelectorAll('.view-obs-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('view_obs_cliente').innerText = this.dataset.cliente;
            document.getElementById('view_obs_text').innerText = this.dataset.obs;
            new bootstrap.Modal(document.getElementById('modalViewObs')).show();
        });
    });

    // Sincronização Google
    document.querySelectorAll('.sync-google-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const icon = this.querySelector('i');
            icon.className = 'spinner-border spinner-border-sm';
            fetch('google_calendar_sync.php?id_treinamento=' + id)
                .then(r => r.json())
                .then(data => {
                    if (data.auth_url) window.location.href = data.auth_url;
                    else { alert(data.message); location.reload(); }
                });
        });
    });

    // Editar Agendamento
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