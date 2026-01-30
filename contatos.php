<?php
require_once 'config.php';

// Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM contatos WHERE id_contato = ?");
    $stmt->execute([$id]);
    header("Location: contatos.php?msg=Contato removido com sucesso");
    exit;
}

// Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_cliente = $_POST['id_cliente'];
    $nome = $_POST['nome'];
    $cargo = $_POST['cargo'];
    $telefone = $_POST['telefone_ddd'];
    $observacao = $_POST['observacao'];
    
    if (isset($_POST['id_contato']) && !empty($_POST['id_contato'])) {
        $stmt = $pdo->prepare("UPDATE contatos SET id_cliente=?, nome=?, cargo=?, telefone_ddd=?, observacao=? WHERE id_contato=?");
        $stmt->execute([$id_cliente, $nome, $cargo, $telefone, $observacao, $_POST['id_contato']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO contatos (id_cliente, nome, cargo, telefone_ddd, observacao) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$id_cliente, $nome, $cargo, $telefone, $observacao]);
    }
    header("Location: contatos.php?msg=Operação realizada com sucesso");
    exit;
}

// Filtros
$filtro_cliente = isset($_GET['filtro_cliente']) ? trim($_GET['filtro_cliente']) : '';
$filtro_contato = isset($_GET['filtro_contato']) ? trim($_GET['filtro_contato']) : '';

$sql = "SELECT c.*, cl.fantasia FROM contatos c JOIN clientes cl ON c.id_cliente = cl.id_cliente WHERE 1=1";
$params = [];

if (!empty($filtro_cliente)) {
    $sql .= " AND cl.fantasia LIKE ?";
    $params[] = "%$filtro_cliente%";
}

if (!empty($filtro_contato)) {
    $sql .= " AND (c.nome LIKE ? OR c.cargo LIKE ?)";
    $params[] = "%$filtro_contato%";
    $params[] = "%$filtro_contato%";
}

$sql .= " ORDER BY c.nome ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contatos = $stmt->fetchAll();

$clientes = $pdo->query("SELECT id_cliente, fantasia FROM clientes ORDER BY fantasia ASC")->fetchAll();
include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-0">Contatos</h2>
        <p class="text-muted small mb-0">Gerencie as pessoas de contato em cada cliente.</p>
    </div>
    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalContato">
        <i class="bi bi-plus-lg me-2"></i>Novo Contato
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
                           placeholder="Filtrar por nome ou cargo..."
                           value="<?php echo htmlspecialchars($filtro_contato); ?>">
                </div>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-1"></i>Buscar
                </button>
                <?php if (!empty($filtro_cliente) || !empty($filtro_contato)): ?>
                    <a href="contatos.php" class="btn btn-outline-secondary" title="Limpar filtros">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($contatos)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted">
                    <?php echo (!empty($filtro_cliente) || !empty($filtro_contato)) ? 'Nenhum contato encontrado com os filtros aplicados.' : 'Nenhum contato cadastrado ainda.'; ?>
                </p>
                <?php if (!empty($filtro_cliente) || !empty($filtro_contato)): ?>
                    <a href="contatos.php" class="btn btn-sm btn-outline-primary">Limpar filtros</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive table-scroll-container" style="max-height: 500px;">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Nome</th>
                            <th>Cliente</th>
                            <th>Cargo</th>
                            <th>Telefone</th>
                            <th class="text-end pe-4">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contatos as $c): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($c['nome']); ?></div>
                                <span class="text-muted x-small">ID: #<?php echo $c['id_contato']; ?></span>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($c['fantasia']); ?></span></td>
                            <td><span class="text-muted small"><?php echo htmlspecialchars($c['cargo']); ?></span></td>
                            <td><?php echo htmlspecialchars($c['telefone_ddd']); ?></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-light text-primary edit-btn me-1" 
                                        data-id="<?php echo $c['id_contato']; ?>"
                                        data-cliente="<?php echo $c['id_cliente']; ?>"
                                        data-nome="<?php echo htmlspecialchars($c['nome']); ?>"
                                        data-cargo="<?php echo htmlspecialchars($c['cargo']); ?>"
                                        data-telefone="<?php echo htmlspecialchars($c['telefone_ddd']); ?>"
                                        data-obs="<?php echo htmlspecialchars($c['observacao']); ?>"
                                        data-bs-toggle="modal" data-bs-target="#modalContato">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <a href="contatos.php?delete=<?php echo $c['id_contato']; ?>" 
                                   class="btn btn-sm btn-light text-danger" 
                                   onclick="return confirm('Deseja realmente excluir este contato?')">
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

<!-- Modal Contato -->
<div class="modal fade" id="modalContato" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="fw-bold" id="modalTitle">Novo Contato</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_contato" id="id_contato">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Cliente</label>
                        <select name="id_cliente" id="id_cliente" class="form-select" required>
                            <option value="">Selecione um cliente...</option>
                            <?php foreach ($clientes as $cl): ?>
                                <option value="<?php echo $cl['id_cliente']; ?>"><?php echo htmlspecialchars($cl['fantasia']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nome Completo</label>
                        <input type="text" name="nome" id="nome" class="form-control" required placeholder="Ex: João Silva">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small">Cargo</label>
                            <input type="text" name="cargo" id="cargo" class="form-control" placeholder="Ex: Gerente">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small">Telefone</label>
                            <input type="text" name="telefone_ddd" id="telefone_ddd" class="form-control" placeholder="(00) 00000-0000" maxlength="15">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Observação</label>
                        <textarea name="observacao" id="observacao" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-light fw-bold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4">Salvar Contato</button>
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

const phoneInput = document.getElementById('telefone_ddd');
phoneInput.addEventListener('input', (e) => {
    e.target.value = maskPhone(e.target.value);
});

document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('modalTitle').innerText = 'Editar Contato';
        document.getElementById('id_contato').value = this.dataset.id;
        document.getElementById('id_cliente').value = this.dataset.cliente;
        document.getElementById('nome').value = this.dataset.nome;
        document.getElementById('cargo').value = this.dataset.cargo;
        document.getElementById('telefone_ddd').value = maskPhone(this.dataset.telefone);
        document.getElementById('observacao').value = this.dataset.obs;
    });
});

document.getElementById('modalContato').addEventListener('hidden.bs.modal', function () {
    document.getElementById('modalTitle').innerText = 'Novo Contato';
    document.querySelector('form').reset();
    document.getElementById('id_contato').value = '';
});
</script>

<?php include 'footer.php'; ?>