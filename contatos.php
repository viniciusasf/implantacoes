<?php
require_once 'config.php';

// Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM contatos WHERE id_contato = ?");
    $stmt->execute([$id]);
    header("Location: contatos.php?msg=Contato removido com sucesso&tipo=success");
    exit;
}

// Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_cliente = $_POST['id_cliente'];
    $nome = $_POST['nome'];
    $email = isset($_POST['email']) ? trim($_POST['email']) : null;
    if ($email === '') {
        $email = null;
    }
    $cargo = $_POST['cargo'];
    $telefone = $_POST['telefone_ddd'];
    $observacao = $_POST['observacao'];
    
    if (isset($_POST['id_contato']) && !empty($_POST['id_contato'])) {
        $stmt = $pdo->prepare("UPDATE contatos SET id_cliente=?, nome=?, email=?, cargo=?, telefone_ddd=?, observacao=? WHERE id_contato=?");
        $stmt->execute([$id_cliente, $nome, $email, $cargo, $telefone, $observacao, $_POST['id_contato']]);
        $msg = "Contato atualizado com sucesso";
    } else {
        $stmt = $pdo->prepare("INSERT INTO contatos (id_cliente, nome, email, cargo, telefone_ddd, observacao) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id_cliente, $nome, $email, $cargo, $telefone, $observacao]);
        $msg = "Contato adicionado com sucesso";
    }
    header("Location: contatos.php?msg=" . urlencode($msg) . "&tipo=success");
    exit;
}

// Filtro único
$filtro = isset($_GET['filtro']) ? trim($_GET['filtro']) : '';

// Ordenação
$ordenacao = isset($_GET['ordenacao']) ? $_GET['ordenacao'] : 'nome';
$direcao = isset($_GET['direcao']) ? $_GET['direcao'] : 'asc';

// Validação da ordenação para segurança
$colunas_permitidas = ['nome', 'fantasia', 'cargo', 'telefone_ddd'];
$ordenacao = in_array($ordenacao, $colunas_permitidas) ? $ordenacao : 'nome';
$direcao = $direcao === 'desc' ? 'desc' : 'asc';

// Paginação
$por_pagina = 15;
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina - 1) * $por_pagina;

// Query principal com contagem para paginação
$sql_base = "SELECT c.*, cl.fantasia FROM contatos c 
             JOIN clientes cl ON c.id_cliente = cl.id_cliente 
             WHERE 1=1";
$sql_contagem = "SELECT COUNT(*) as total FROM contatos c 
                 JOIN clientes cl ON c.id_cliente = cl.id_cliente 
                 WHERE 1=1";

$params = [];
$params_contagem = [];

if (!empty($filtro)) {
    $sql_base .= " AND (cl.fantasia LIKE ? OR c.nome LIKE ? OR c.email LIKE ? OR c.cargo LIKE ?)";
    $sql_contagem .= " AND (cl.fantasia LIKE ? OR c.nome LIKE ? OR c.email LIKE ? OR c.cargo LIKE ?)";
    
    $param_value = "%$filtro%";
    $params = [$param_value, $param_value, $param_value, $param_value];
    $params_contagem = [$param_value, $param_value, $param_value, $param_value];
}

// Executar contagem
$stmt_contagem = $pdo->prepare($sql_contagem);
$stmt_contagem->execute($params_contagem);
$total_registros = $stmt_contagem->fetchColumn();

// Calcular total de páginas
$total_paginas = ceil($total_registros / $por_pagina);

// Query dos dados com ordenação e paginação
$sql_base .= " ORDER BY c.$ordenacao $direcao LIMIT ?, ?";

// Adicionar parâmetros de paginação
$params[] = $offset;
$params[] = $por_pagina;

// Preparar e executar query principal
$stmt = $pdo->prepare($sql_base);
$stmt->execute($params);
$contatos = $stmt->fetchAll();

// Trazer apenas clientes ativos para o modal
$clientes = $pdo->query("SELECT id_cliente, fantasia FROM clientes 
                         WHERE (data_fim IS NULL OR data_fim = '0000-00-00' OR data_fim > NOW())
                         ORDER BY fantasia ASC")->fetchAll();

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-0">Contatos</h2>
        <p class="text-muted small mb-0">Gerencie as pessoas de contato em cada cliente.</p>
    </div>
    <button class="btn btn-primary shadow-sm d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#modalContato">
        <i class="bi bi-plus-lg me-2"></i>Novo Contato
    </button>
</div>

<?php if (isset($_GET['msg'])): 
    $tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'success';
    $classes = [
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info'
    ];
    $icones = [
        'success' => 'bi-check-circle',
        'error' => 'bi-exclamation-circle',
        'warning' => 'bi-exclamation-triangle',
        'info' => 'bi-info-circle'
    ];
    $classe = $classes[$tipo] ?? 'alert-success';
    $icone = $icones[$tipo] ?? 'bi-check-circle';
?>
    <div class="alert <?php echo $classe; ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="bi <?php echo $icone; ?> me-2"></i><?php echo htmlspecialchars($_GET['msg']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Barra de Filtro Simplificada -->
<div class="card shadow-sm border-0 mb-4">
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
                           placeholder="Buscar por cliente, nome do contato ou cargo..."
                           value="<?php echo htmlspecialchars($filtro); ?>"
                           autocomplete="off">
                </div>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill d-flex align-items-center justify-content-center">
                    <i class="bi bi-search me-2"></i>Buscar
                </button>
                <?php if (!empty($filtro)): ?>
                    <a href="contatos.php" class="btn btn-outline-secondary d-flex align-items-center" title="Limpar filtro">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white border-0 py-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="d-flex align-items-center">
                    <i class="bi bi-person-lines-fill text-primary me-2"></i>
                    <span class="text-muted small">
                        <?php if ($total_registros > 0): ?>
                            Exibindo <strong><?php echo min($por_pagina, count($contatos)); ?></strong> de <strong><?php echo $total_registros; ?></strong> contatos
                            <?php if (!empty($filtro)): ?>
                                <span class="text-primary">(filtrados)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            Nenhum contato encontrado
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <div class="col-md-6 text-md-end">
                <div class="btn-group" role="group">
                    <a href="contatos.php?ordenacao=<?php echo $ordenacao; ?>&direcao=<?php echo $direcao == 'asc' ? 'desc' : 'asc'; ?>&filtro=<?php echo urlencode($filtro); ?>"
                       class="btn btn-outline-secondary btn-sm d-flex align-items-center">
                        <i class="bi bi-sort-<?php echo $direcao == 'asc' ? 'down' : 'up'; ?> me-1"></i>
                        Ordenar
                    </a>
                    <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <?php echo ucfirst(str_replace('_ddd', '', $ordenacao)); ?>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="contatos.php?ordenacao=nome&direcao=asc&filtro=<?php echo urlencode($filtro); ?>">Nome</a></li>
                        <li><a class="dropdown-item" href="contatos.php?ordenacao=fantasia&direcao=asc&filtro=<?php echo urlencode($filtro); ?>">Cliente</a></li>
                        <li><a class="dropdown-item" href="contatos.php?ordenacao=cargo&direcao=asc&filtro=<?php echo urlencode($filtro); ?>">Cargo</a></li>
                        <li><a class="dropdown-item" href="contatos.php?ordenacao=telefone_ddd&direcao=asc&filtro=<?php echo urlencode($filtro); ?>">Telefone</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card-body p-0">
        <?php if (empty($contatos)): ?>
            <div class="text-center py-5">
                <i class="bi bi-person-x fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted mb-3">
                    <?php echo !empty($filtro) ? 
                        'Nenhum contato encontrado com o filtro aplicado.' : 
                        'Nenhum contato cadastrado ainda. Comece adicionando o primeiro!'; ?>
                </p>
                <?php if (!empty($filtro)): ?>
                    <a href="contatos.php" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Limpar filtro
                    </a>
                <?php else: ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalContato">
                        <i class="bi bi-plus-lg me-1"></i>Adicionar Contato
                    </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive table-scroll-container" style="max-height: 500px;">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4 py-3 border-bottom-0">
                                <a href="contatos.php?ordenacao=nome&direcao=<?php echo ($ordenacao == 'nome' && $direcao == 'asc') ? 'desc' : 'asc'; ?>&filtro=<?php echo urlencode($filtro); ?>"
                                   class="text-decoration-none text-dark d-flex align-items-center">
                                    Nome
                                    <?php if ($ordenacao == 'nome'): ?>
                                        <i class="bi bi-caret-<?php echo $direcao == 'asc' ? 'up' : 'down'; ?>-fill ms-1 small"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-3 border-bottom-0">
                                <a href="contatos.php?ordenacao=fantasia&direcao=<?php echo ($ordenacao == 'fantasia' && $direcao == 'asc') ? 'desc' : 'asc'; ?>&filtro=<?php echo urlencode($filtro); ?>"
                                   class="text-decoration-none text-dark d-flex align-items-center">
                                    Cliente
                                    <?php if ($ordenacao == 'fantasia'): ?>
                                        <i class="bi bi-caret-<?php echo $direcao == 'asc' ? 'up' : 'down'; ?>-fill ms-1 small"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-3 border-bottom-0">
                                <a href="contatos.php?ordenacao=cargo&direcao=<?php echo ($ordenacao == 'cargo' && $direcao == 'asc') ? 'desc' : 'asc'; ?>&filtro=<?php echo urlencode($filtro); ?>"
                                   class="text-decoration-none text-dark d-flex align-items-center">
                                    Cargo
                                    <?php if ($ordenacao == 'cargo'): ?>
                                        <i class="bi bi-caret-<?php echo $direcao == 'asc' ? 'up' : 'down'; ?>-fill ms-1 small"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-3 border-bottom-0">
                                <a href="contatos.php?ordenacao=telefone_ddd&direcao=<?php echo ($ordenacao == 'telefone_ddd' && $direcao == 'asc') ? 'desc' : 'asc'; ?>&filtro=<?php echo urlencode($filtro); ?>"
                                   class="text-decoration-none text-dark d-flex align-items-center">
                                    Telefone
                                    <?php if ($ordenacao == 'telefone_ddd'): ?>
                                        <i class="bi bi-caret-<?php echo $direcao == 'asc' ? 'up' : 'down'; ?>-fill ms-1 small"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="text-end pe-4 py-3 border-bottom-0">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contatos as $c): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark d-flex align-items-center">
                                    <i class="bi bi-person-circle text-primary me-2"></i>
                                    <?php echo htmlspecialchars($c['nome']); ?>
                                </div>
                                <?php if (!empty($c['email'])): ?>
                                    <span class="text-muted small d-flex align-items-center mt-1">
                                        <i class="bi bi-envelope me-1"></i>
                                        <?php echo htmlspecialchars($c['email']); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="text-muted x-small d-block">ID: #<?php echo $c['id_contato']; ?></span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border d-inline-flex align-items-center">
                                    <i class="bi bi-building me-1"></i>
                                    <?php echo htmlspecialchars($c['fantasia']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($c['cargo'])): ?>
                                    <span class="text-muted small d-flex align-items-center">
                                        <i class="bi bi-briefcase me-1"></i>
                                        <?php echo htmlspecialchars($c['cargo']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted fst-italic small">Não informado</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($c['telefone_ddd'])): ?>
                                    <span class="d-flex align-items-center">
                                        <i class="bi bi-telephone text-success me-1"></i>
                                        <?php echo htmlspecialchars($c['telefone_ddd']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted fst-italic small">Não informado</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-outline-primary edit-btn me-1" 
                                            data-id="<?php echo $c['id_contato']; ?>"
                                            data-cliente="<?php echo $c['id_cliente']; ?>"
                                            data-nome="<?php echo htmlspecialchars($c['nome']); ?>"
                                            data-email="<?php echo htmlspecialchars($c['email']); ?>"
                                            data-cargo="<?php echo htmlspecialchars($c['cargo']); ?>"
                                            data-telefone="<?php echo htmlspecialchars($c['telefone_ddd']); ?>"
                                            data-obs="<?php echo htmlspecialchars($c['observacao']); ?>"
                                            data-bs-toggle="modal" data-bs-target="#modalContato"
                                            title="Editar contato">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <a href="contatos.php?delete=<?php echo $c['id_contato']; ?>&pagina=<?php echo $pagina; ?>&filtro=<?php echo urlencode($filtro); ?>" 
                                       class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Tem certeza que deseja excluir o contato \\'<?php echo addslashes($c['nome']); ?>\\'?')"
                                       title="Excluir contato">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Paginação -->
            <?php if ($total_paginas > 1): ?>
            <div class="card-footer bg-white border-0 py-3">
                <nav aria-label="Navegação de páginas">
                    <ul class="pagination justify-content-center mb-0">
                        <?php if ($pagina > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="contatos.php?pagina=<?php echo $pagina-1; ?>&ordenacao=<?php echo $ordenacao; ?>&direcao=<?php echo $direcao; ?>&filtro=<?php echo urlencode($filtro); ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php
                        $inicio = max(1, $pagina - 2);
                        $fim = min($total_paginas, $pagina + 2);
                        
                        if ($inicio > 1) {
                            echo '<li class="page-item"><a class="page-link" href="contatos.php?pagina=1&ordenacao='.$ordenacao.'&direcao='.$direcao.'&filtro='.urlencode($filtro).'">1</a></li>';
                            if ($inicio > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        
                        for ($i = $inicio; $i <= $fim; $i++):
                        ?>
                            <li class="page-item <?php echo ($i == $pagina) ? 'active' : ''; ?>">
                                <a class="page-link" href="contatos.php?pagina=<?php echo $i; ?>&ordenacao=<?php echo $ordenacao; ?>&direcao=<?php echo $direcao; ?>&filtro=<?php echo urlencode($filtro); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php
                        if ($fim < $total_paginas) {
                            if ($fim < $total_paginas - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            echo '<li class="page-item"><a class="page-link" href="contatos.php?pagina='.$total_paginas.'&ordenacao='.$ordenacao.'&direcao='.$direcao.'&filtro='.urlencode($filtro).'">'.$total_paginas.'</a></li>';
                        }
                        ?>
                        
                        <?php if ($pagina < $total_paginas): ?>
                            <li class="page-item">
                                <a class="page-link" href="contatos.php?pagina=<?php echo $pagina+1; ?>&ordenacao=<?php echo $ordenacao; ?>&direcao=<?php echo $direcao; ?>&filtro=<?php echo urlencode($filtro); ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Contato -->
<div class="modal fade" id="modalContato" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" id="formContato">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="fw-bold d-flex align-items-center" id="modalTitle">
                        <i class="bi bi-person-plus me-2"></i>Novo Contato
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_contato" id="id_contato">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small d-flex align-items-center">
                            <i class="bi bi-building me-1"></i>Cliente (ativos)
                        </label>
                        <select name="id_cliente" id="id_cliente" class="form-select" required>
                            <option value="">Selecione um cliente ativo...</option>
                            <?php if (empty($clientes)): ?>
                                <option value="" disabled>Nenhum cliente ativo encontrado</option>
                            <?php else: ?>
                                <?php foreach ($clientes as $cl): ?>
                                    <option value="<?php echo $cl['id_cliente']; ?>"><?php echo htmlspecialchars($cl['fantasia']); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <?php if (empty($clientes)): ?>
                            <div class="alert alert-warning mt-2 small">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Não há clientes ativos cadastrados. Cadastre um cliente antes de adicionar contatos.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small d-flex align-items-center">
                            <i class="bi bi-person me-1"></i>Nome Completo
                        </label>
                        <input type="text" name="nome" id="nome" class="form-control" required placeholder="Ex: João Silva">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small d-flex align-items-center">
                            <i class="bi bi-envelope me-1"></i>E-mail
                        </label>
                        <input type="email" name="email" id="email" class="form-control" placeholder="contato@empresa.com.br">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small d-flex align-items-center">
                                <i class="bi bi-briefcase me-1"></i>Cargo
                            </label>
                            <input type="text" name="cargo" id="cargo" class="form-control" placeholder="Ex: Gerente">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small d-flex align-items-center">
                                <i class="bi bi-telephone me-1"></i>Telefone
                            </label>
                            <input type="text" name="telefone_ddd" id="telefone_ddd" class="form-control" placeholder="(00) 00000-0000" maxlength="15">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small d-flex align-items-center">
                            <i class="bi bi-chat-text me-1"></i>Observação
                        </label>
                        <textarea name="observacao" id="observacao" class="form-control" rows="3" placeholder="Informações adicionais sobre este contato..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-light fw-bold d-flex align-items-center" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i>Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary px-4 d-flex align-items-center" <?php echo empty($clientes) ? 'disabled' : ''; ?>>
                        <i class="bi bi-check-lg me-1"></i>Salvar Contato
                    </button>
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
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Editar Contato';
        document.getElementById('id_contato').value = this.dataset.id;
        document.getElementById('id_cliente').value = this.dataset.cliente;
        document.getElementById('nome').value = this.dataset.nome;
        document.getElementById('email').value = this.dataset.email || '';
        document.getElementById('cargo').value = this.dataset.cargo;
        document.getElementById('telefone_ddd').value = maskPhone(this.dataset.telefone);
        document.getElementById('observacao').value = this.dataset.obs;
    });
});

document.getElementById('modalContato').addEventListener('hidden.bs.modal', function () {
    document.getElementById('modalTitle').innerHTML = '<i class="bi bi-person-plus me-2"></i>Novo Contato';
    document.getElementById('formContato').reset();
    document.getElementById('id_contato').value = '';
});

// Foco automático no campo de busca
const filtroInput = document.querySelector('input[name="filtro"]');
if (filtroInput && window.location.search.includes('filtro=')) {
    filtroInput.focus();
    filtroInput.select();
}
</script>

<?php include 'footer.php'; ?>
