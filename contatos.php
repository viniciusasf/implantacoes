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
    if ($email === '') { $email = null; }
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

$colunas_permitidas = ['nome', 'fantasia', 'cargo', 'telefone_ddd'];
$ordenacao = in_array($ordenacao, $colunas_permitidas) ? $ordenacao : 'nome';
$direcao = $direcao === 'desc' ? 'desc' : 'asc';

// Paginação
$por_pagina = 12;
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina - 1) * $por_pagina;

// Query principal
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
    $val = "%$filtro%";
    $params = [$val, $val, $val, $val];
    $params_contagem = [$val, $val, $val, $val];
}

$stmt_c = $pdo->prepare($sql_contagem);
$stmt_c->execute($params_contagem);
$total_registros = $stmt_c->fetchColumn();
$total_paginas = ceil($total_registros / $por_pagina);

$sql_base .= " ORDER BY c.$ordenacao $direcao LIMIT ?, ?";
$params[] = $offset;
$params[] = $por_pagina;

$stmt = $pdo->prepare($sql_base);
$stmt->execute($params);
$contatos = $stmt->fetchAll();

$clientes = $pdo->query("SELECT id_cliente, fantasia FROM clientes 
                         WHERE (data_fim IS NULL OR data_fim = '0000-00-00' OR data_fim > NOW())
                         ORDER BY fantasia ASC")->fetchAll();

include 'header.php';
?>

<style>
    :root {
        --c-primary: #4361ee;
        --c-secondary: #7209b7;
        --c-dark: #1e293b;
        --c-border: #e2e8f0;
    }

    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .contacts-wrapper {
        animation: fadeInUp 0.5s ease-out;
    }

    .page-header-premium {
        margin-bottom: 2.5rem;
    }

    .title-modern {
        font-family: var(--font-heading);
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--c-dark);
        letter-spacing: -0.5px;
    }

    .search-card-premium {
        background: white;
        border-radius: 20px;
        box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05);
        padding: 0.75rem;
        margin-bottom: 2.5rem;
        border: none;
    }

    .search-input-group {
        display: flex;
        align-items: center;
        gap: 12px;
        padding-left: 1rem;
    }

    .search-input-group .form-control {
        border: none;
        box-shadow: none;
        font-size: 1.05rem;
        font-weight: 500;
        padding: 0.75rem 0;
    }

    /* Grid de Cards */
    .contact-card-premium {
        background: white;
        border-radius: 24px;
        border: 1px solid var(--c-border);
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        height: 100%;
        overflow: hidden;
        position: relative;
    }

    .contact-card-premium:hover {
        transform: translateY(-10px);
        border-color: var(--c-primary);
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1);
    }

    .card-visual-header {
        height: 80px;
        background: linear-gradient(135deg, #4361ee 0%, #7209b7 100%);
        position: relative;
    }

    .avatar-circle {
        width: 70px;
        height: 70px;
        background: white;
        border-radius: 20px;
        position: absolute;
        bottom: -35px;
        left: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        color: var(--c-primary);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        border: 4px solid white;
    }

    .quick-actions {
        position: absolute;
        top: 15px;
        right: 15px;
        display: flex;
        gap: 8px;
        opacity: 0;
        transform: translateX(10px);
        transition: all 0.3s ease;
    }

    .contact-card-premium:hover .quick-actions {
        opacity: 1;
        transform: translateX(0);
    }

    .action-pill {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(8px);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(255,255,255,0.3);
        transition: all 0.2s;
    }

    .action-pill:hover { background: white; color: var(--c-primary); transform: scale(1.1); }
    .action-pill.btn-del:hover { color: #ef4444; }

    .card-body-premium {
        padding: 50px 24px 24px;
    }

    .name-premium {
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--c-dark);
        margin-bottom: 4px;
    }

    .role-premium {
        font-size: 0.85rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 16px;
        display: block;
    }

    .info-row {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
        color: #475569;
        font-size: 0.95rem;
        text-decoration: none;
    }

    .client-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #f1f5f9;
        padding: 6px 14px;
        border-radius: 100px;
        font-size: 0.8rem;
        font-weight: 700;
        border: 1px solid #e2e8f0;
        margin-top: 8px;
    }

    /* Floating Action Button */
    .fab-add {
        position: fixed;
        bottom: 40px;
        right: 40px;
        width: 65px;
        height: 65px;
        border-radius: 22px;
        background: linear-gradient(135deg, #4361ee 0%, #7209b7 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        box-shadow: 0 15px 35px rgba(67, 97, 238, 0.3);
        border: none;
        transition: all 0.3s;
        z-index: 1000;
    }

    .fab-add:hover {
        transform: rotate(90deg) scale(1.1);
        color: white;
    }
</style>

<div class="contacts-wrapper container-fluid">
    <div class="page-header-premium d-flex justify-content-between align-items-end">
        <div>
            <h1 class="title-modern">Directório de Contatos</h1>
            <p class="text-muted">Gestão centralizada de relacionamentos.</p>
        </div>
        <div class="d-none d-md-block">
            <span class="badge bg-white text-dark shadow-sm px-3 py-2 rounded-3 border">Total: <strong><?= $total_registros ?></strong></span>
        </div>
    </div>

    <div class="search-card-premium">
        <form method="GET">
            <div class="row align-items-center g-0">
                <div class="col">
                    <div class="search-input-group">
                        <i class="bi bi-search text-muted"></i>
                        <input type="text" name="filtro" class="form-control" placeholder="Buscar por nome, empresa ou cargo..." value="<?= htmlspecialchars($filtro) ?>">
                    </div>
                </div>
                <div class="col-auto px-2">
                    <button type="submit" class="btn btn-primary rounded-4 px-4">Filtrar</button>
                </div>
            </div>
        </form>
    </div>

    <div class="row g-4">
        <?php foreach ($contatos as $c): ?>
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="contact-card-premium shadow-sm">
                    <div class="card-visual-header">
                        <div class="avatar-circle"><i class="bi bi-person-vcard"></i></div>
                        <div class="quick-actions">
                            <button class="action-pill edit-trigger" 
                                    data-id="<?= $c['id_contato'] ?>" 
                                    data-cliente="<?= $c['id_cliente'] ?>"
                                    data-nome="<?= htmlspecialchars($c['nome']) ?>"
                                    data-email="<?= htmlspecialchars($c['email']) ?>"
                                    data-cargo="<?= htmlspecialchars($c['cargo']) ?>"
                                    data-telefone="<?= htmlspecialchars($c['telefone_ddd']) ?>"
                                    data-obs="<?= htmlspecialchars($c['observacao']) ?>"
                                    data-bs-toggle="modal" data-bs-target="#modalContato">
                                <i class="bi bi-pencil-fill"></i>
                            </button>
                            <a href="contatos.php?delete=<?= $c['id_contato'] ?>" class="action-pill btn-del" onclick="return confirm('Excluir?')">
                                <i class="bi bi-trash-fill"></i>
                            </a>
                        </div>
                    </div>
                    <div class="card-body-premium">
                        <h4 class="name-premium"><?= htmlspecialchars($c['nome']) ?></h4>
                        <span class="role-premium"><?= $c['cargo'] ?: 'Sem Cargo' ?></span>
                        <hr class="my-3 opacity-10">
                        <?php if($c['telefone_ddd']): ?>
                            <div class="info-row"><i class="bi bi-telephone"></i> <?= htmlspecialchars($c['telefone_ddd']) ?></div>
                        <?php endif; ?>
                        <?php if($c['email']): ?>
                            <div class="info-row"><i class="bi bi-envelope"></i> <?= htmlspecialchars($c['email']) ?></div>
                        <?php endif; ?>
                        <div class="client-chip"><i class="bi bi-building"></i> <?= htmlspecialchars($c['fantasia']) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Paginação Premium -->
    <?php if ($total_paginas > 1): ?>
        <div class="mt-5 d-flex justify-content-center">
            <nav>
                <ul class="pagination pagination-modern gap-2">
                    <?php if ($pagina > 1): ?>
                        <li class="page-item">
                            <a class="page-link rounded-3 border-0 shadow-sm" href="contatos.php?pagina=<?= $pagina - 1 ?>&filtro=<?= urlencode($filtro) ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php
                    $inicio = max(1, $pagina - 2);
                    $fim = min($total_paginas, $pagina + 2);
                    
                    for ($i = $inicio; $i <= $fim; $i++):
                    ?>
                        <li class="page-item <?= ($i == $pagina) ? 'active' : '' ?>">
                            <a class="page-link rounded-3 border-0 shadow-sm" href="contatos.php?pagina=<?= $i ?>&filtro=<?= urlencode($filtro) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($pagina < $total_paginas): ?>
                        <li class="page-item">
                            <a class="page-link rounded-3 border-0 shadow-sm" href="contatos.php?pagina=<?= $pagina + 1 ?>&filtro=<?= urlencode($filtro) ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<style>
    /* Estilos adicionais para paginação */
    .pagination-modern .page-link {
        padding: 10px 18px;
        color: var(--c-dark);
        font-weight: 600;
        transition: all 0.2s;
        background: white;
    }

    .pagination-modern .page-item.active .page-link {
        background: var(--c-primary);
        color: white;
        box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
    }

    .pagination-modern .page-link:hover:not(.active) {
        background: #f1f5f9;
        transform: translateY(-2px);
    }
</style>

<button class="fab-add" data-bs-toggle="modal" data-bs-target="#modalContato"><i class="bi bi-plus"></i></button>

<!-- Modal Reutilizado -->
<div class="modal fade" id="modalContato" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form method="POST">
                <div class="modal-header bg-primary text-white p-4 border-0">
                    <h5 class="modal-title fw-bold" id="modalL">Novo Contato</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="id_contato" id="id_c">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Empresa</label>
                        <select name="id_cliente" id="id_cl" class="form-select rounded-3" required>
                            <?php foreach ($clientes as $cl): ?>
                                <option value="<?= $cl['id_cliente'] ?>"><?= htmlspecialchars($cl['fantasia']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nome</label>
                        <input type="text" name="nome" id="nom" class="form-control rounded-3" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Cargo</label>
                            <input type="text" name="cargo" id="car" class="form-control rounded-3">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Telefone</label>
                            <input type="text" name="telefone_ddd" id="tel" class="form-control rounded-3">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">E-mail</label>
                        <input type="email" name="email" id="ema" class="form-control rounded-3">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Obs</label>
                        <textarea name="observacao" id="obs" class="form-control rounded-3" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-3">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.edit-trigger').forEach(b => {
    b.onclick = function() {
        document.getElementById('modalL').innerText = 'Editar Contato';
        document.getElementById('id_c').value = this.dataset.id;
        document.getElementById('id_cl').value = this.dataset.cliente;
        document.getElementById('nom').value = this.dataset.nome;
        document.getElementById('ema').value = this.dataset.email === 'null' ? '' : this.dataset.email;
        document.getElementById('car').value = this.dataset.cargo;
        document.getElementById('tel').value = this.dataset.telefone;
        document.getElementById('obs').value = this.dataset.obs;
    }
});
</script>

<?php include 'footer.php'; ?>
