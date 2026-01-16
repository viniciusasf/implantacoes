<?php
require_once 'config.php';

// Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM tarefas WHERE id_tarefa = ?");
    $stmt->execute([$id]);
    header("Location: tarefas.php?msg=Tarefa removida com sucesso");
    exit;
}

// Lógica para Alterar Status Rápido
if (isset($_GET['status']) && isset($_GET['id'])) {
    $status = $_GET['status'];
    $id = $_GET['id'];
    $stmt = $pdo->prepare("UPDATE tarefas SET status = ? WHERE id_tarefa = ?");
    $stmt->execute([$status, $id]);
    header("Location: tarefas.php?msg=Status da tarefa atualizado");
    exit;
}

// Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_cliente = !empty($_POST['id_cliente']) ? $_POST['id_cliente'] : null;
    $titulo = $_POST['titulo'];
    $descricao = $_POST['descricao'];
    $prioridade = $_POST['prioridade'];
    $status = $_POST['status'];
    $data_entrega = !empty($_POST['data_entrega']) ? $_POST['data_entrega'] : null;

    if (isset($_POST['id_tarefa']) && !empty($_POST['id_tarefa'])) {
        $stmt = $pdo->prepare("UPDATE tarefas SET id_cliente=?, titulo=?, descricao=?, prioridade=?, status=?, data_entrega=? WHERE id_tarefa=?");
        $stmt->execute([$id_cliente, $titulo, $descricao, $prioridade, $status, $data_entrega, $_POST['id_tarefa']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO tarefas (id_cliente, titulo, descricao, prioridade, status, data_entrega) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id_cliente, $titulo, $descricao, $prioridade, $status, $data_entrega]);
    }
    header("Location: tarefas.php?msg=Operação realizada com sucesso");
    exit;
}

// Busca de Tarefas
$tarefas = $pdo->query("
    SELECT t.*, c.fantasia as cliente_nome 
    FROM tarefas t 
    LEFT JOIN clientes c ON t.id_cliente = c.id_cliente 
    ORDER BY 
        CASE WHEN t.status = 'Concluída' THEN 2 ELSE 1 END,
        CASE WHEN t.prioridade = 'Alta' THEN 1 WHEN t.prioridade = 'Média' THEN 2 ELSE 3 END,
        t.data_entrega ASC
")->fetchAll();

$clientes = $pdo->query("SELECT id_cliente, fantasia FROM clientes ORDER BY fantasia ASC")->fetchAll();

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-0">Tarefas (Tasks)</h2>
        <p class="text-muted small mb-0">Gerencie pendências e atividades das implantações.</p>
    </div>
    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTarefa">
        <i class="bi bi-plus-lg me-2"></i>Nova Tarefa
    </button>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($_GET['msg']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive table-scroll-container">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Prioridade</th>
                        <th>Tarefa</th>
                        <th>Cliente</th>
                        <th>Prazo</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tarefas as $t): ?>
                    <tr>
                        <td class="ps-4">
                            <?php 
                            $badgeClass = 'bg-info';
                            if($t['prioridade'] == 'Alta') $badgeClass = 'bg-danger';
                            if($t['prioridade'] == 'Média') $badgeClass = 'bg-warning text-dark';
                            ?>
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo $t['prioridade']; ?></span>
                        </td>
                        <td>
                            <div class="fw-bold"><?php echo htmlspecialchars($t['titulo']); ?></div>
                            <div class="text-muted small text-truncate" style="max-width: 250px;"><?php echo htmlspecialchars($t['descricao']); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border">
                                <?php echo $t['cliente_nome'] ? htmlspecialchars($t['cliente_nome']) : 'Geral'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="small <?php echo (strtotime($t['data_entrega']) < time() && $t['status'] != 'Concluída') ? 'text-danger fw-bold' : 'text-muted'; ?>">
                                <?php echo $t['data_entrega'] ? date('d/m/Y H:i', strtotime($t['data_entrega'])) : '-'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="dropdown">
                                <button class="btn btn-sm rounded-pill px-3 <?php 
                                    if($t['status'] == 'Concluída') echo 'btn-success-subtle text-success';
                                    elseif($t['status'] == 'Em Andamento') echo 'btn-primary-subtle text-primary';
                                    else echo 'btn-warning-subtle text-warning';
                                ?> dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <?php echo $t['status']; ?>
                                </button>
                                <ul class="dropdown-menu shadow border-0">
                                    <li><a class="dropdown-item small" href="tarefas.php?id=<?php echo $t['id_tarefa']; ?>&status=Pendente">Pendente</a></li>
                                    <li><a class="dropdown-item small" href="tarefas.php?id=<?php echo $t['id_tarefa']; ?>&status=Em Andamento">Em Andamento</a></li>
                                    <li><a class="dropdown-item small" href="tarefas.php?id=<?php echo $t['id_tarefa']; ?>&status=Concluída">Concluída</a></li>
                                </ul>
                            </div>
                        </td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-light text-primary edit-btn me-1" 
                                    data-id="<?php echo $t['id_tarefa']; ?>"
                                    data-cliente="<?php echo $t['id_cliente']; ?>"
                                    data-titulo="<?php echo htmlspecialchars($t['titulo']); ?>"
                                    data-descricao="<?php echo htmlspecialchars($t['descricao']); ?>"
                                    data-prioridade="<?php echo $t['prioridade']; ?>"
                                    data-status="<?php echo $t['status']; ?>"
                                    data-data="<?php echo $t['data_entrega'] ? date('Y-m-d\TH:i', strtotime($t['data_entrega'])) : ''; ?>"
                                    data-bs-toggle="modal" data-bs-target="#modalTarefa">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <a href="tarefas.php?delete=<?php echo $t['id_tarefa']; ?>" 
                               class="btn btn-sm btn-light text-danger" 
                               onclick="return confirm('Deseja realmente excluir esta tarefa?')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Tarefa -->
<div class="modal fade" id="modalTarefa" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="fw-bold" id="modalTitle">Nova Tarefa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_tarefa" id="id_tarefa">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Cliente (Opcional)</label>
                        <select name="id_cliente" id="id_cliente" class="form-select">
                            <option value="">Geral / Sem Cliente</option>
                            <?php foreach ($clientes as $cl): ?>
                                <option value="<?php echo $cl['id_cliente']; ?>"><?php echo htmlspecialchars($cl['fantasia']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Título da Tarefa</label>
                        <input type="text" name="titulo" id="titulo" class="form-control" required placeholder="Ex: Configurar impressora fiscal">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Prazo de Entrega</label>
                        <input type="datetime-local" name="data_entrega" id="data_entrega" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Prioridade</label>
                        <select name="prioridade" id="prioridade" class="form-select">
                            <option value="Baixa">Baixa</option>
                            <option value="Média" selected>Média</option>
                            <option value="Alta">Alta</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="Pendente">Pendente</option>
                            <option value="Em Andamento">Em Andamento</option>
                            <option value="Concluída">Concluída</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Descrição / Observações</label>
                        <textarea name="descricao" id="descricao" class="form-control" rows="3" placeholder="Detalhes da tarefa..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-light fw-bold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4">Salvar Tarefa</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('modalTitle').innerText = 'Editar Tarefa';
        document.getElementById('id_tarefa').value = this.dataset.id;
        document.getElementById('id_cliente').value = this.dataset.cliente;
        document.getElementById('titulo').value = this.dataset.titulo;
        document.getElementById('descricao').value = this.dataset.descricao;
        document.getElementById('prioridade').value = this.dataset.prioridade;
        document.getElementById('status').value = this.dataset.status;
        document.getElementById('data_entrega').value = this.dataset.data;
    });
});

document.getElementById('modalTarefa').addEventListener('hidden.bs.modal', function () {
    document.getElementById('modalTitle').innerText = 'Nova Tarefa';
    document.querySelector('form').reset();
    document.getElementById('id_tarefa').value = '';
});
</script>

<?php include 'footer.php'; ?>
