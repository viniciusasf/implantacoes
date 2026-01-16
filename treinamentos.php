<?php
require_once 'config.php';

// Lógica para Deletar
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
    
    // Buscar dados do contato
    $stmt_c = $pdo->prepare("SELECT nome, telefone_ddd FROM contatos WHERE id_contato = ?");
    $stmt_c->execute([$id_contato]);
    $contato = $stmt_c->fetch();
    
    $nome_contato = $contato['nome'];
    $telefone_contato = $contato['telefone_ddd'];
    $data_encerrado = ($status == 'Resolvido') ? date('Y-m-d H:i:s') : null;

    if (isset($_POST['id_treinamento']) && !empty($_POST['id_treinamento'])) {
        $stmt = $pdo->prepare("UPDATE treinamentos SET id_cliente=?, id_contato=?, nome_contato=?, telefone_contato=?, tema=?, status=?, data_treinamento=?, data_treinamento_encerrado=? WHERE id_treinamento=?");
        $stmt->execute([$id_cliente, $id_contato, $nome_contato, $telefone_contato, $tema, $status, $data_treinamento, $data_encerrado, $_POST['id_treinamento']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO treinamentos (id_cliente, id_contato, nome_contato, telefone_contato, tema, status, data_treinamento, data_treinamento_encerrado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id_cliente, $id_contato, $nome_contato, $telefone_contato, $tema, $status, $data_treinamento, $data_encerrado]);
    }
    header("Location: treinamentos.php?msg=Operação realizada com sucesso");
    exit;
}

// Filtros
$filtro_cliente = isset($_GET['filtro_cliente']) ? trim($_GET['filtro_cliente']) : '';
$filtro_contato = isset($_GET['filtro_contato']) ? trim($_GET['filtro_contato']) : '';

$sql = "SELECT t.*, c.fantasia FROM treinamentos t JOIN clientes c ON t.id_cliente = c.id_cliente WHERE 1=1";
$params = [];

if (!empty($filtro_cliente)) {
    $sql .= " AND c.fantasia LIKE ?";
    $params[] = "%$filtro_cliente%";
}

if (!empty($filtro_contato)) {
    $sql .= " AND t.nome_contato LIKE ?";
    $params[] = "%$filtro_contato%";
}

$sql .= " ORDER BY 
    CASE WHEN t.status = 'Pendente' THEN 0 ELSE 1 END,
    CASE WHEN t.status = 'Pendente' THEN t.data_treinamento END ASC,
    CASE WHEN t.status = 'Resolvido' THEN t.data_treinamento_encerrado END DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$treinamentos = $stmt->fetchAll();

$clientes = $pdo->query("SELECT id_cliente, fantasia FROM clientes ORDER BY fantasia ASC")->fetchAll();
$contatos_all = $pdo->query("SELECT id_contato, id_cliente, nome FROM contatos ORDER BY nome ASC")->fetchAll();

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-0">Treinamentos</h2>
        <p class="text-muted small mb-0">Agende e gerencie os treinamentos de implantação.</p>
    </div>
    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
        <i class="bi bi-plus-lg me-2"></i>Novo Treinamento
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
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="bi bi-building text-muted"></i>
                    </span>
                    <input type="text" 
                           name="filtro_cliente" 
                           class="form-control border-start-0 ps-0" 
                           placeholder="Filtrar por cliente/empresa..."
                           value="<?php echo htmlspecialchars($filtro_cliente); ?>">
                </div>
            </div>
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="bi bi-person text-muted"></i>
                    </span>
                    <input type="text" 
                           name="filtro_contato" 
                           class="form-control border-start-0 ps-0" 
                           placeholder="Filtrar por contato..."
                           value="<?php echo htmlspecialchars($filtro_contato); ?>">
                </div>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-1"></i>Buscar
                </button>
                <?php if (!empty($filtro_cliente) || !empty($filtro_contato)): ?>
                    <a href="treinamentos.php" class="btn btn-outline-secondary" title="Limpar filtros">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <?php if (empty($treinamentos)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted">
                    <?php echo (!empty($filtro_cliente) || !empty($filtro_contato)) ? 'Nenhum treinamento encontrado com os filtros aplicados.' : 'Nenhum treinamento cadastrado ainda.'; ?>
                </p>
                <?php if (!empty($filtro_cliente) || !empty($filtro_contato)): ?>
                    <a href="treinamentos.php" class="btn btn-sm btn-outline-primary">Limpar filtros</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div class="table-responsive table-scroll-container">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Agendado</th>
                        <th>Cliente</th>
                        <th>Contato</th>
                        <th>Tema</th>
                        <th>Status</th>
                        <th>Concluído em</th>
                        <th class="text-center">Agenda Google</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($treinamentos as $t): 
                        $row_class = ($t['status'] == 'Resolvido') ? 'bg-success bg-opacity-10' : '';
                    ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td class="ps-4">
                            <div class="fw-bold text-primary small">
                                <?php echo $t['data_treinamento'] ? date('d/m/Y H:i', strtotime($t['data_treinamento'])) : 'Não agendado'; ?>
                            </div>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($t['fantasia']); ?></span></td>
                        <td>
                            <div class="small fw-bold"><?php echo htmlspecialchars($t['nome_contato']); ?></div>
                            <div class="text-muted x-small"><?php echo htmlspecialchars($t['telefone_contato']); ?></div>
                        </td>
                        <td>
                            <div class="text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($t['tema']); ?>">
                                <?php echo htmlspecialchars($t['tema']); ?>
                            </div>
                        </td>
                        <td>
                            <div class="dropdown">
                                <button class="btn btn-sm rounded-pill px-3 <?php echo $t['status'] == 'Resolvido' ? 'btn-success-subtle text-success' : 'btn-warning-subtle text-warning'; ?> dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <?php echo $t['status']; ?>
                                </button>
                                <ul class="dropdown-menu shadow border-0">
                                    <li><a class="dropdown-item small" href="treinamentos.php?id=<?php echo $t['id_treinamento']; ?>&status=Pendente">Pendente</a></li>
                                    <li><a class="dropdown-item small" href="treinamentos.php?id=<?php echo $t['id_treinamento']; ?>&status=Resolvido">Resolvido</a></li>
                                </ul>
                            </div>
                        </td>
                        <td>
                            <span class="text-muted small">
                                <?php echo $t['data_treinamento_encerrado'] ? date('d/m/Y H:i', strtotime($t['data_treinamento_encerrado'])) : '-'; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <div class="btn-group" role="group">
                                <?php if (empty($t['google_event_id'])): ?>
                                    <!-- Botão de Sincronizar -->
                                    <button class="btn btn-sm btn-outline-danger sync-calendar" 
                                            data-id="<?php echo $t['id_treinamento']; ?>" 
                                            title="Adicionar ao Google Agenda">
                                        <i class="bi bi-google"></i>
                                    </button>
                                <?php else: ?>
                                    <!-- Botão indicando sincronizado -->
                                    <button class="btn btn-sm btn-success" disabled title="Sincronizado">
                                        <i class="bi bi-check-circle"></i>
                                    </button>
                                    <!-- Botão de Deletar do Google -->
                                    <button class="btn btn-sm btn-outline-danger delete-calendar" 
                                            data-id="<?php echo $t['id_treinamento']; ?>" 
                                            title="Remover do Google Agenda">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-light text-primary edit-btn me-1" 
                                    data-id="<?php echo $t['id_treinamento']; ?>"
                                    data-cliente="<?php echo $t['id_cliente']; ?>"
                                    data-contato="<?php echo $t['id_contato']; ?>"
                                    data-tema="<?php echo htmlspecialchars($t['tema']); ?>"
                                    data-status="<?php echo $t['status']; ?>"
                                    data-data="<?php echo $t['data_treinamento'] ? date('Y-m-d\TH:i', strtotime($t['data_treinamento'])) : ''; ?>"
                                    data-bs-toggle="modal" data-bs-target="#modalTreinamento">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <a href="treinamentos.php?delete=<?php echo $t['id_treinamento']; ?>" 
                               class="btn btn-sm btn-light text-danger delete-treinamento" 
                               data-has-google="<?php echo !empty($t['google_event_id']) ? '1' : '0'; ?>"
                               onclick="return confirm('<?php echo !empty($t['google_event_id']) ? 'Este treinamento está sincronizado com o Google Agenda. Deseja realmente excluir? (O evento no Google será mantido)' : 'Deseja realmente excluir este treinamento?'; ?>')">
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

<!-- Modal Treinamento -->
<div class="modal fade" id="modalTreinamento" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="fw-bold" id="modalTitle">Novo Treinamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_treinamento" id="id_treinamento">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Cliente</label>
                        <select name="id_cliente" id="id_cliente" class="form-select" required onchange="filterContatos(this.value)">
                            <option value="">Selecione um cliente...</option>
                            <?php foreach ($clientes as $cl): ?>
                                <option value="<?php echo $cl['id_cliente']; ?>"><?php echo htmlspecialchars($cl['fantasia']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Contato Responsável</label>
                        <select name="id_contato" id="id_contato" class="form-select" required disabled>
                            <option value="">Selecione o cliente primeiro...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Data e Hora do Treinamento</label>
                        <input type="datetime-local" name="data_treinamento" id="data_treinamento" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Tema do Treinamento</label>
                        <textarea name="tema" id="tema" class="form-control" rows="3" required placeholder="Descreva o que será treinado..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Status Inicial</label>
                        <select name="status" id="status" class="form-select">
                            <option value="Pendente">Pendente</option>
                            <option value="Resolvido">Resolvido</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-light fw-bold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4">Salvar Treinamento</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Objeto com todos os contatos para o filtro dinâmico
const allContatos = <?php echo json_encode($contatos_all); ?>;

function filterContatos(clienteId, selectedContatoId = null) {
    const select = document.getElementById('id_contato');
    select.innerHTML = '<option value="">Selecione um contato...</option>';
    
    if (!clienteId) {
        select.disabled = true;
        return;
    }

    const filtered = allContatos.filter(c => c.id_cliente == clienteId);
    
    if (filtered.length > 0) {
        filtered.forEach(c => {
            const option = document.createElement('option');
            option.value = c.id_contato;
            option.text = c.nome;
            if (selectedContatoId && c.id_contato == selectedContatoId) {
                option.selected = true;
            }
            select.appendChild(option);
        });
        select.disabled = false;
    } else {
        select.innerHTML = '<option value="">Nenhum contato cadastrado para este cliente</option>';
        select.disabled = true;
    }
}

// Lógica de Sincronização com Google Agenda
document.querySelectorAll('.sync-calendar').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const icon = this.querySelector('i');
        const originalClass = icon.className;
        
        icon.className = 'spinner-border spinner-border-sm';
        this.disabled = true;

        fetch(`google_calendar_sync.php?id_treinamento=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload(); // Recarrega para atualizar os botões
                } else {
                    if (data.auth_url) {
                        if (confirm('Autenticação necessária. Deseja abrir a página de autorização do Google?')) {
                            window.open(data.auth_url, '_blank');
                        }
                    } else {
                        alert('❌ Erro: ' + data.message);
                    }
                    icon.className = originalClass;
                    this.disabled = false;
                }
            })
            .catch(err => {
                console.error(err);
                alert('❌ Erro ao conectar com o servidor.');
                icon.className = originalClass;
                this.disabled = false;
            });
    });
});

// Lógica de Deleção do Google Agenda
document.querySelectorAll('.delete-calendar').forEach(btn => {
    btn.addEventListener('click', function() {
        if (!confirm('Deseja realmente remover este evento do Google Agenda?')) {
            return;
        }

        const id = this.dataset.id;
        const icon = this.querySelector('i');
        const originalClass = icon.className;
        
        icon.className = 'spinner-border spinner-border-sm';
        this.disabled = true;

        fetch(`google_calendar_delete.php?id_treinamento=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload(); // Recarrega para atualizar os botões
                } else {
                    alert('❌ Erro: ' + data.message);
                    icon.className = originalClass;
                    this.disabled = false;
                }
            })
            .catch(err => {
                console.error(err);
                alert('❌ Erro ao conectar com o servidor.');
                icon.className = originalClass;
                this.disabled = false;
            });
    });
});

// Lógica de Edição
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('modalTitle').innerText = 'Editar Treinamento';
        document.getElementById('id_treinamento').value = this.dataset.id;
        document.getElementById('id_cliente').value = this.dataset.cliente;
        document.getElementById('tema').value = this.dataset.tema;
        document.getElementById('status').value = this.dataset.status;
        document.getElementById('data_treinamento').value = this.dataset.data;
        
        filterContatos(this.dataset.cliente, this.dataset.contato);
    });
});

// Reset ao fechar modal
document.getElementById('modalTreinamento').addEventListener('hidden.bs.modal', function () {
    document.getElementById('modalTitle').innerText = 'Novo Treinamento';
    document.querySelector('form').reset();
    document.getElementById('id_treinamento').value = '';
    document.getElementById('id_contato').disabled = true;
});
</script>

<?php include 'footer.php'; ?>