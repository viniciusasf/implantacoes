<?php
require_once 'config.php';

// 1. Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM clientes WHERE id_cliente = ?");
    $stmt->execute([$id]);
    header("Location: clientes.php?msg=Cliente removido com sucesso");
    exit;
}

// 2. Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fantasia = $_POST['fantasia'];
    $servidor = $_POST['servidor'];
    $vendedor = $_POST['vendedor'];
    $telefone = $_POST['telefone'];
    $data_inicio = $_POST['data_inicio'];
    // Garante que se estiver vazio no formulário, salva como NULL no banco
    $data_fim = (!empty($_POST['data_fim']) && $_POST['data_fim'] !== '0000-00-00') ? $_POST['data_fim'] : null; 
    $observacao = $_POST['observacao'];

    if (isset($_POST['id_cliente']) && !empty($_POST['id_cliente'])) {
        $stmt = $pdo->prepare("UPDATE clientes SET fantasia=?, servidor=?, vendedor=?, telefone_ddd=?, data_inicio=?, data_fim=?, observacao=? WHERE id_cliente=?");
        $stmt->execute([$fantasia, $servidor, $vendedor, $telefone, $data_inicio, $data_fim, $observacao, $_POST['id_cliente']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO clientes (fantasia, servidor, vendedor, telefone_ddd, data_inicio, data_fim, observacao) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fantasia, $servidor, $vendedor, $telefone, $data_inicio, $data_fim, $observacao]);
    }
    header("Location: clientes.php?msg=Dados atualizados com sucesso");
    exit;
}

// 3. Consulta de Clientes
$filtro = isset($_GET['filtro']) ? trim($_GET['filtro']) : '';
$sql = "SELECT * FROM clientes";
if (!empty($filtro)) {
    $sql .= " WHERE fantasia LIKE ? OR vendedor LIKE ? OR servidor LIKE ?";
    $params = ["%$filtro%", "%$filtro%", "%$filtro%"];
    $stmt = $pdo->prepare($sql . " ORDER BY fantasia ASC");
    $stmt->execute($params);
} else {
    $stmt = $pdo->query($sql . " ORDER BY fantasia ASC");
}
$clientes = $stmt->fetchAll();

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-0">Gestão de Clientes</h2>
        <p class="text-muted small mb-0">Acompanhamento de implantações e prazos.</p>
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

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-3 align-items-center">
            <div class="col-md-10">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="filtro" class="form-control border-start-0 ps-0" placeholder="Buscar cliente..." value="<?php echo htmlspecialchars($filtro); ?>">
                </div>
            </div>
            <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Buscar</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Empresa</th>
                        <th>Vendedor</th>
                        <th>Data Início</th>
                        <th>Dias em Aberto</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $c): 
                        $dias_exibicao = '<span class="text-muted small">---</span>';
                        
                        // LÓGICA CORRIGIDA: Só considera "Concluído" se a data for válida e diferente de zero
                        $tem_data_fim = (!empty($c['data_fim']) && $c['data_fim'] !== '0000-00-00');

                        if ($tem_data_fim) {
                            $dias_exibicao = '<span class="badge bg-success-subtle text-success px-3">Concluído</span>';
                        } 
                        elseif (!empty($c['data_inicio']) && $c['data_inicio'] !== '0000-00-00') {
                            try {
                                $data_ini = new DateTime($c['data_inicio']);
                                $hoje = new DateTime();
                                $intervalo = $data_ini->diff($hoje);
                                $total_dias = $intervalo->days;

                                if ($data_ini > $hoje) {
                                    $dias_exibicao = "<span class='text-dark'>0 dias</span>";
                                } else {
                                    $cor = ($total_dias > 91) ? 'text-danger fw-bold' : 'text-dark';
                                    $dias_exibicao = "<span class='{$cor}'>{$total_dias} dias</span>";
                                }
                            } catch (Exception $e) {
                                $dias_exibicao = '<span class="text-muted small">Data Inválida</span>';
                            }
                        }
                    ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($c['fantasia']); ?></div>
                                <span class="text-muted x-small">#<?php echo $c['servidor']; ?></span>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($c['vendedor']); ?></span></td>
                            <td><small><?php echo ($c['data_inicio'] && $c['data_inicio'] !== '0000-00-00') ? date('d/m/Y', strtotime($c['data_inicio'])) : '---'; ?></small></td>
                            <td><?php echo $dias_exibicao; ?></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-light text-primary edit-btn me-1"
                                    data-id="<?php echo $c['id_cliente']; ?>"
                                    data-fantasia="<?php echo htmlspecialchars($c['fantasia']); ?>"
                                    data-servidor="<?php echo htmlspecialchars($c['servidor']); ?>"
                                    data-vendedor="<?php echo htmlspecialchars($c['vendedor']); ?>"
                                    data-telefone="<?php echo htmlspecialchars($c['telefone_ddd']); ?>"
                                    data-data_inicio="<?php echo $c['data_inicio']; ?>"
                                    data-data_fim="<?php echo ($c['data_fim'] === '0000-00-00' ? '' : $c['data_fim']); ?>"
                                    data-obs="<?php echo htmlspecialchars($c['observacao']); ?>" 
                                    data-bs-toggle="modal" data-bs-target="#modalCliente">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <a href="clientes.php?delete=<?php echo $c['id_cliente']; ?>" class="btn btn-sm btn-light text-danger" onclick="return confirm('Deseja excluir?')"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

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
                        <input type="text" name="fantasia" id="fantasia" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small">Servidor</label>
                            <input type="text" name="servidor" id="servidor" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small">Vendedor</label>
                            <input type="text" name="vendedor" id="vendedor" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-semibold small">Telefone</label>
                            <input type="text" name="telefone" id="telefone" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small text-primary">Data Início</label>
                            <input type="date" name="data_inicio" id="data_inicio" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small text-success">Data Fim (Conclusão)</label>
                            <input type="date" name="data_fim" id="data_fim" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Observação</label>
                        <textarea name="observacao" id="observacao" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-light fw-bold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">Salvar Cliente</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('modalTitle').innerText = 'Editar Cliente';
            document.getElementById('id_cliente').value = this.dataset.id;
            document.getElementById('fantasia').value = this.dataset.fantasia;
            document.getElementById('servidor').value = this.dataset.servidor;
            document.getElementById('vendedor').value = this.dataset.vendedor;
            document.getElementById('telefone').value = this.dataset.telefone;
            document.getElementById('data_inicio').value = this.dataset.data_inicio;
            document.getElementById('data_fim').value = this.dataset.data_fim || '';
            document.getElementById('observacao').value = this.dataset.obs;
        });
    });

    document.getElementById('modalCliente').addEventListener('hidden.bs.modal', function() {
        document.getElementById('modalTitle').innerText = 'Novo Cliente';
        this.querySelector('form').reset();
        document.getElementById('id_cliente').value = '';
    });
</script>

<?php include 'footer.php'; ?>