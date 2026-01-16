<?php
require_once 'config.php';

// Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM clientes WHERE id_cliente = ?");
    $stmt->execute([$id]);
    header("Location: clientes.php?msg=Cliente removido com sucesso");
    exit;
}

// Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fantasia = $_POST['fantasia'];
    $servidor = $_POST['servidor'];
    $vendedor = $_POST['vendedor'];
    $telefone = $_POST['telefone_ddd'];
    $observacao = $_POST['observacao'];
    
    if (isset($_POST['id_cliente']) && !empty($_POST['id_cliente'])) {
        $stmt = $pdo->prepare("UPDATE clientes SET fantasia=?, servidor=?, vendedor=?, telefone_ddd=?, observacao=? WHERE id_cliente=?");
        $stmt->execute([$fantasia, $servidor, $vendedor, $telefone, $observacao, $_POST['id_cliente']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO clientes (fantasia, servidor, vendedor, telefone_ddd, observacao) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$fantasia, $servidor, $vendedor, $telefone, $observacao]);
    }
    header("Location: clientes.php?msg=Operação realizada com sucesso");
    exit;
}

// Filtro
$filtro = isset($_GET['filtro']) ? trim($_GET['filtro']) : '';
$sql = "SELECT * FROM clientes";
$params = [];

if (!empty($filtro)) {
    $sql .= " WHERE fantasia LIKE ? OR vendedor LIKE ? OR servidor LIKE ?";
    $params = ["%$filtro%", "%$filtro%", "%$filtro%"];
}

$sql .= " ORDER BY fantasia ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clientes = $stmt->fetchAll();

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-0">Clientes</h2>
        <p class="text-muted small mb-0">Gerencie as empresas em processo de implantação.</p>
    </div>
    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalCliente">
        <i class="bi bi-plus-lg me-2"></i>Novo Cliente
    </button>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($_GET['msg']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Barra de Filtro -->
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-3 align-items-center">
            <div class="col-md-10">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="bi bi-search text-muted"></i>
                    </span>
                    <input type="text" 
                           name="filtro" 
                           class="form-control border-start-0 ps-0" 
                           placeholder="Buscar por empresa, vendedor ou servidor..."
                           value="<?php echo htmlspecialchars($filtro); ?>"
                           autofocus>
                </div>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-1"></i>Buscar
                </button>
                <?php if (!empty($filtro)): ?>
                    <a href="clientes.php" class="btn btn-outline-secondary" title="Limpar filtro">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <?php if (empty($clientes)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted">
                    <?php echo !empty($filtro) ? 'Nenhum cliente encontrado com o filtro aplicado.' : 'Nenhum cliente cadastrado ainda.'; ?>
                </p>
                <?php if (!empty($filtro)): ?>
                    <a href="clientes.php" class="btn btn-sm btn-outline-primary">Limpar filtro</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive table-scroll-container">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Empresa</th>
                            <th>Servidor</th>
                            <th>Vendedor</th>
                            <th>Telefone</th>
                            <th class="text-end pe-4">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes as $c): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($c['fantasia']); ?></div>
                                <span class="text-muted x-small">ID: #<?php echo $c['id_cliente']; ?></span>
                            </td>
                            <td><code class="text-primary small"><?php echo htmlspecialchars($c['servidor']); ?></code></td>
                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($c['vendedor']); ?></span></td>
                            <td><?php echo htmlspecialchars($c['telefone_ddd']); ?></td>
                            <td class="text-end pe-4">
                                <!-- Botão Ver Tarefas -->
                                <a href="tarefas.php?filtro_cliente=<?php echo urlencode($c['fantasia']); ?>" 
                                   class="btn btn-sm btn-light text-success me-1"
                                   title="Ver tarefas deste cliente">
                                    <i class="bi bi-list-task"></i>
                                </a>
                                <button class="btn btn-sm btn-light text-info details-btn me-1" 
                                        data-id="<?php echo $c['id_cliente']; ?>"
                                        data-fantasia="<?php echo htmlspecialchars($c['fantasia']); ?>"
                                        data-bs-toggle="modal" data-bs-target="#modalDetalhes">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-sm btn-light text-primary edit-btn me-1" 
                                        data-id="<?php echo $c['id_cliente']; ?>"
                                        data-fantasia="<?php echo htmlspecialchars($c['fantasia']); ?>"
                                        data-servidor="<?php echo htmlspecialchars($c['servidor']); ?>"
                                        data-vendedor="<?php echo htmlspecialchars($c['vendedor']); ?>"
                                        data-telefone="<?php echo htmlspecialchars($c['telefone_ddd']); ?>"
                                        data-obs="<?php echo htmlspecialchars($c['observacao']); ?>"
                                        data-bs-toggle="modal" data-bs-target="#modalCliente">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <a href="clientes.php?delete=<?php echo $c['id_cliente']; ?>" 
                                   class="btn btn-sm btn-light text-danger" 
                                   onclick="return confirm('Deseja realmente excluir este cliente?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Detalhes do Cliente -->
<div class="modal fade" id="modalDetalhes" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0">
                <h5 class="fw-bold mb-0">Histórico de Treinamentos: <span id="detalhesClienteNome" class="text-primary"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="loadingTreinamentos" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Carregando histórico...</p>
                </div>
                <div id="listaTreinamentos" style="display:none;">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Data Agendada</th>
                                    <th>Tema</th>
                                    <th>Status</th>
                                    <th>Conclusão</th>
                                </tr>
                            </thead>
                            <tbody id="treinamentosBody">
                                <!-- Preenchido via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="noTreinamentos" class="text-center py-4" style="display:none;">
                    <i class="bi bi-info-circle fs-2 text-muted"></i>
                    <p class="mt-2 text-muted">Nenhum treinamento registrado para este cliente.</p>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light fw-bold" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Cliente (Novo/Editar) -->
<div class="modal fade" id="modalCliente" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="fw-bold" id="modalTitle">Novo Cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_cliente" id="id_cliente">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nome Fantasia</label>
                        <input type="text" name="fantasia" id="fantasia" class="form-control form-control-lg fs-6" placeholder="Ex: Empresa ABC" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small">Servidor</label>
                            <input type="text" name="servidor" id="servidor" class="form-control" placeholder="URL ou IP">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small">Vendedor</label>
                            <input type="text" name="vendedor" id="vendedor" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Telefone</label>
                        <input type="text" name="telefone_ddd" id="telefone_ddd" class="form-control" placeholder="(00) 00000-0000" maxlength="15">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Observação</label>
                        <textarea name="observacao" id="observacao" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-light fw-bold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4">Salvar Cliente</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Função para aplicar máscara de telefone
function maskPhone(value) {
    if (!value) return "";
    value = value.replace(/\D/g, "");
    value = value.replace(/^(\d{2})(\d)/g, "($1) $2");
    value = value.replace(/(\d)(\d{4})$/, "$1-$2");
    return value;
}

// Função para formatar data e hora corretamente
function formatDateTime(dateTimeStr) {
    if (!dateTimeStr) return "-";
    const date = new Date(dateTimeStr.replace(' ', 'T'));
    return date.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

const phoneInput = document.getElementById('telefone_ddd');
phoneInput.addEventListener('input', (e) => {
    e.target.value = maskPhone(e.target.value);
});

// Lógica para carregar detalhes (Treinamentos)
document.querySelectorAll('.details-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const nome = this.dataset.fantasia;
        document.getElementById('detalhesClienteNome').innerText = nome;
        
        document.getElementById('loadingTreinamentos').style.display = 'block';
        document.getElementById('listaTreinamentos').style.display = 'none';
        document.getElementById('noTreinamentos').style.display = 'none';
        document.getElementById('treinamentosBody').innerHTML = '';

        fetch(`get_treinamentos_cliente.php?id_cliente=${id}`)
            .then(response => response.json())
            .then(data => {
                document.getElementById('loadingTreinamentos').style.display = 'none';
                if (data.length > 0) {
                    data.forEach(t => {
                        const dataAg = formatDateTime(t.data_treinamento);
                        const dataEnc = formatDateTime(t.data_treinamento_encerrado);
                        const statusClass = t.status === 'RESOLVIDO' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
                        
                        const row = `<tr>
                            <td class="small">${dataAg}</td>
                            <td class="small fw-bold">${t.tema}</td>
                            <td><span class="badge ${statusClass} rounded-pill" style="font-size: 0.7rem;">${t.status}</span></td>
                            <td class="small text-muted">${dataEnc}</td>
                        </tr>`;
                        document.getElementById('treinamentosBody').innerHTML += row;
                    });
                    document.getElementById('listaTreinamentos').style.display = 'block';
                } else {
                    document.getElementById('noTreinamentos').style.display = 'block';
                }
            })
            .catch(err => {
                console.error('Erro ao buscar treinamentos:', err);
                document.getElementById('loadingTreinamentos').innerHTML = '<p class="text-danger">Erro ao carregar dados.</p>';
            });
    });
});

// Lógica para carregar edição
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('modalTitle').innerText = 'Editar Cliente';
        document.getElementById('id_cliente').value = this.dataset.id;
        document.getElementById('fantasia').value = this.dataset.fantasia;
        document.getElementById('servidor').value = this.dataset.servidor;
        document.getElementById('vendedor').value = this.dataset.vendedor;
        document.getElementById('telefone_ddd').value = maskPhone(this.dataset.telefone);
        document.getElementById('observacao').value = this.dataset.obs;
    });
});

document.getElementById('modalCliente').addEventListener('hidden.bs.modal', function () {
    document.getElementById('modalTitle').innerText = 'Novo Cliente';
    document.querySelector('form').reset();
    document.getElementById('id_cliente').value = '';
});
</script>

<?php include 'footer.php'; ?>