<?php
require_once 'config.php';

// Lógica para Deletar do Banco Local
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM treinamentos WHERE id_treinamento = ?");
    $stmt->execute([$id]);
    header("Location: treinamentos.php?msg=Treinamento removido com sucesso");
    exit;
}

// Lógica para Alterar Status Rápido
if (isset($_GET['status']) && isset($_GET['id'])) {
    $status = $_GET['status'];
    $id = $_GET['id'];
    $data_encerrado = ($status == 'Resolvido') ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare("UPDATE treinamentos SET status = ?, data_treinamento_encerrado = ? WHERE id_treinamento = ?");
    $stmt->execute([$status, $data_encerrado, $id]);
    header("Location: treinamentos.php?msg=Status atualizado");
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
    header("Location: treinamentos.php?msg=Operação realizada com sucesso");
    exit;
}

// CONSULTA COM A NOVA ORDENAÇÃO SOLICITADA
$treinamentos = $pdo->query("
    SELECT t.*, c.fantasia as cliente_nome, co.nome as contato_nome 
    FROM treinamentos t
    LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
    LEFT JOIN contatos co ON t.id_contato = co.id_contato
    ORDER BY 
        CASE WHEN t.status = 'PENDENTE' THEN 1 ELSE 2 END ASC, 
        t.data_treinamento ASC
")->fetchAll();

$clientes = $pdo->query("SELECT id_cliente, fantasia FROM clientes ORDER BY fantasia ASC")->fetchAll();

include 'header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="mb-4">
            <h2 class="fw-bold">Treinamentos</h2>
            <p class="text-muted">Controle de Treinamentos</p>
        </div>
        <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
            <i class="bi bi-plus-lg me-2"></i>Novo Treinamento
        </button>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-check-circle me-2"></i> <?= htmlspecialchars($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-4">Cliente / Tema</th>
                            <th>Data Agendada</th>
                            <th>Status</th>
                            <th>Contato</th>
                            <th class="text-end pe-4">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($treinamentos as $t): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($t['cliente_nome']) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($t['tema']) ?></div>
                                </td>
                                <td>
                                    <div class="small">
                                        <i class="bi bi-calendar3 me-1 text-primary"></i>
                                        <?= $t['data_treinamento'] ? date('d/m/Y H:i', strtotime($t['data_treinamento'])) : '<span class="text-danger">Não definida</span>' ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge rounded-pill <?= ($t['status'] == 'Resolvido') ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?>">
                                        <?= htmlspecialchars($t['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="small fw-medium"><?= htmlspecialchars($t['contato_nome']) ?></div>
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

                                        <button class="btn btn-sm btn-outline-primary edit-btn"
                                            data-id="<?= $t['id_treinamento'] ?>"
                                            data-cliente="<?= $t['id_cliente'] ?>"
                                            data-contato="<?= $t['id_contato'] ?>"
                                            data-tema="<?= htmlspecialchars($t['tema']) ?>"
                                            data-status="<?= $t['status'] ?>"
                                            data-data="<?= $t['data_treinamento'] ? date('Y-m-d\TH:i', strtotime($t['data_treinamento'])) : '' ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <a href="?delete=<?= $t['id_treinamento'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Deseja excluir este registro?')">
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
</div>

<div class="modal fade" id="modalTreinamento" tabindex="-1">
    <div class="modal-dialog">
        <form action="" method="POST" class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold" id="modalTitle">Treinamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_treinamento" id="id_treinamento">

                <div class="mb-3">
                    <label class="form-label small fw-bold">Cliente</label>
                    <select name="id_cliente" id="id_cliente" class="form-select" required onchange="filterContatos(this.value)">
                        <option value="">Selecione um cliente...</option>
                        <?php foreach ($clientes as $c): ?>
                            <option value="<?= $c['id_cliente'] ?>"><?= htmlspecialchars($c['fantasia']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Contato</label>
                    <select name="id_contato" id="id_contato" class="form-select" required disabled>
                        <option value="">Selecione o cliente primeiro...</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Tema</label>
                    <input type="text" name="tema" id="tema" class="form-control" required>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Data/Hora</label>
                        <input type="datetime-local" name="data_treinamento" id="data_treinamento" class="form-control">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="PENDENTE">PENDENTE</option>
                            <option value="RESOLVIDO">RESOLVIDO</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Fechar</button>
                <button type="submit" class="btn btn-primary px-4">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Scripts de Filtragem de Contato, Sincronização Google e Edição (mantidos conforme versão anterior)
    function filterContatos(id_cliente, selected_contato = null) {
        const contatoSelect = document.getElementById('id_contato');
        if (!id_cliente) {
            contatoSelect.innerHTML = '<option value="">Selecione o cliente primeiro...</option>';
            contatoSelect.disabled = true;
            return;
        }
        contatoSelect.disabled = true;
        contatoSelect.innerHTML = '<option>Carregando...</option>';

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

    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('modalTitle').innerText = 'Editar Treinamento';
            document.getElementById('id_treinamento').value = this.dataset.id;
            document.getElementById('id_cliente').value = this.dataset.cliente;
            document.getElementById('tema').value = this.dataset.tema;
            document.getElementById('status').value = this.dataset.status;
            document.getElementById('data_treinamento').value = this.dataset.data;
            filterContatos(this.dataset.cliente, this.dataset.contato);
            new bootstrap.Modal(document.getElementById('modalTreinamento')).show();
        });
    });

    document.getElementById('modalTreinamento').addEventListener('hidden.bs.modal', function() {
        document.getElementById('modalTitle').innerText = 'Novo Treinamento';
        document.querySelector('form').reset();
        document.getElementById('id_treinamento').value = '';
        document.getElementById('id_contato').disabled = true;
    });
</script>

<?php include 'footer.php'; ?>