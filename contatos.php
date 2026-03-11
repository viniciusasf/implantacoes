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
    /* === INTEGRAÇÃO COM DESIGN TOKENS DE HEADER.PHP + DARK THEME === */
    body, html {
        background-color: var(--bg-body);
    }
    .container-fluid.py-4 {
        padding-top: 1rem !important;
        padding-bottom: 2rem !important;
    }

    .page-title {
        font-family: var(--font-heading);
        font-size: 1.75rem;
        letter-spacing: -0.5px;
        margin-bottom: 0.25rem;
    }

    .search-container {
        position: relative;
        flex: 1;
        max-width: 450px;
    }
    .search-container .form-control {
        padding-left: 45px;
        border-radius: var(--radius-md);
        border: 1px solid var(--border-color);
        background-color: var(--bg-card);
        color: var(--text-main);
        transition: all 0.3s ease;
        height: 48px;
        box-shadow: var(--shadow-sm);
    }
    .search-container .form-control:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 4px var(--primary-light);
        outline: none;
    }
    .search-container .search-icon {
        position: absolute;
        left: 18px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        z-index: 10;
        font-size: 1.1rem;
    }

    .contacts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1.5rem;
        padding-bottom: 2rem;
    }

    .contact-card {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-sm);
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    .contact-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-card);
        border-color: var(--primary-light);
    }
    
    .card-visual-header {
        padding: 1.5rem 1.25rem 0.5rem;
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        background: rgba(255, 255, 255, 0.02);
        border-bottom: 1px solid var(--border-color);
    }

    .avatar-circle {
        width: 50px;
        height: 50px;
        background: var(--primary-light);
        color: var(--primary);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        flex-shrink: 0;
    }

    .contact-info {
        flex: 1;
        min-width: 0;
    }
    .name-premium {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-main);
        margin-bottom: 0.1rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .role-premium {
        font-size: 0.8rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .card-body-premium {
        padding: 1rem 1.25rem;
        flex: 1;
    }

    .info-row {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
        color: var(--text-main);
        font-size: 0.9rem;
    }
    .info-row i {
        color: var(--text-muted);
        width: 16px;
        text-align: center;
    }

    .client-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--bg-body);
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        border: 1px solid var(--border-color);
        color: var(--text-muted);
        margin-top: 8px;
    }

    .card-footer-premium {
        padding: 0.75rem 1.25rem;
        background: rgba(255, 255, 255, 0.02);
        border-top: 1px solid var(--border-color);
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
    }

    .action-buttons {
        display: flex;
        gap: 0.4rem;
    }

    .btn-action {
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        transition: all 0.2s;
        border: 1px solid transparent;
        background: var(--bg-body);
        color: var(--text-muted);
        text-decoration: none;
        font-size: 0.9rem;
    }
    .btn-action:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); }
    .btn-action.edit { color: var(--info); background: var(--info-light); }
    .btn-action.edit:hover { background: var(--info); color: white; border-color: var(--info); }
    .btn-action.delete { color: var(--danger); background: var(--danger-light); }
    .btn-action.delete:hover { background: var(--danger); color: white; border-color: var(--danger); }

    /* PAGINAÇÃO */
    .pagination-modern .page-link {
        padding: 8px 16px;
        color: var(--text-main);
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        transition: all 0.2s;
    }
    .pagination-modern .page-item.active .page-link {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }
    .pagination-modern .page-link:hover:not(.active) {
        background: var(--bg-body);
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(15px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .fade-in { animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }

    /* MODAL */
    .contact-modal .form-control,
    .contact-modal .form-select,
    .contact-modal textarea {
        background-color: var(--bg-body) !important;
        color: var(--text-main) !important;
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        padding: 0.6rem 1rem;
    }
    .contact-modal .form-control:focus,
    .contact-modal .form-select:focus,
    .contact-modal textarea:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light);
    }
    .contact-modal label {
        color: var(--text-muted) !important;
    }
    .modal-content {
        background-color: var(--bg-card) !important;
        border: 1px solid var(--border-color) !important;
    }
    .modal-header, .modal-footer {
        border-color: var(--border-color) !important;
    }
    .modal-title { color: var(--text-main) !important; }
    .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }

</style>

<div class="container-fluid py-4">
    <!-- TOPO PADRÃO DO SISTEMA -->
    <div class="row align-items-center mb-4">
        <div class="col">
            <h3 class="page-title fw-bold mb-1"><i class="bi bi-person-vcard-fill me-2 text-primary"></i>Diretório de Contatos</h3>
            <p class="text-muted small mb-0">Gestão centralizada de relacionamentos.</p>
        </div>
        <div class="col-auto">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-secondary ms-2 px-3 py-2 rounded-3">Total: <?= $total_registros ?></span>
                <button class="btn btn-primary px-4 fw-bold shadow-sm d-flex align-items-center"
                    data-bs-toggle="modal"
                    data-bs-target="#modalContato">
                    <i class="bi bi-plus-lg me-2"></i>Novo Contato
                </button>
            </div>
        </div>
    </div>

    <!-- BARRA DE CONTROLES -->
    <div class="d-flex align-items-center gap-2 mb-4 flex-wrap">
        <div class="search-container" style="flex: 1; min-width: 220px; max-width: 420px;">
            <i class="bi bi-search search-icon"></i>
            <form method="GET" action="contatos.php" class="d-inline w-100">
                <input type="text" name="filtro" class="form-control w-100" placeholder="Buscar por nome, empresa ou cargo..." value="<?= htmlspecialchars($filtro) ?>">
            </form>
        </div>
    </div>

    <!-- MAIN CARDS -->
    <div class="contacts-grid">
        <?php foreach ($contatos as $index => $c): ?>
            <div class="contact-card fade-in" style="--card-index: <?= $index ?>; animation-delay: calc(var(--card-index, 0) * 0.05s);">
                <div class="card-visual-header">
                    <div class="avatar-circle">
                        <i class="bi bi-person"></i>
                    </div>
                    <div class="contact-info">
                        <div class="name-premium" title="<?= htmlspecialchars($c['nome']) ?>"><?= htmlspecialchars($c['nome']) ?></div>
                        <div class="role-premium" title="<?= htmlspecialchars($c['cargo'] ?: 'Sem Cargo') ?>"><?= htmlspecialchars($c['cargo']) ?: 'Sem Cargo' ?></div>
                    </div>
                </div>
                
                <div class="card-body-premium">
                    <?php if($c['telefone_ddd']): ?>
                        <div class="info-row">
                            <i class="bi bi-telephone"></i>
                            <span><?= htmlspecialchars($c['telefone_ddd']) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if($c['email']): ?>
                        <div class="info-row">
                            <i class="bi bi-envelope"></i>
                            <span class="text-truncate"><?= htmlspecialchars($c['email']) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="client-chip text-truncate w-100" title="<?= htmlspecialchars($c['fantasia']) ?>">
                        <i class="bi bi-building"></i> <?= htmlspecialchars($c['fantasia']) ?>
                    </div>
                </div>

                <div class="card-footer-premium">
                    <div class="action-buttons">
                        <button class="btn-action edit edit-trigger"
                                data-bs-toggle="tooltip"
                                data-bs-title="Editar contato"
                                data-id="<?= $c['id_contato'] ?>" 
                                data-cliente="<?= $c['id_cliente'] ?>"
                                data-nome="<?= htmlspecialchars($c['nome']) ?>"
                                data-email="<?= htmlspecialchars($c['email']) ?>"
                                data-cargo="<?= htmlspecialchars($c['cargo']) ?>"
                                data-telefone="<?= htmlspecialchars($c['telefone_ddd']) ?>"
                                data-obs="<?= htmlspecialchars($c['observacao']) ?>"
                                data-bs-toggle="modal" data-bs-target="#modalContato">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <a href="contatos.php?delete=<?= $c['id_contato'] ?>" 
                           class="btn-action delete" 
                           data-bs-toggle="tooltip"
                           data-bs-title="Excluir contato"
                           onclick="return confirm('Excluir este contato?')">
                            <i class="bi bi-trash"></i>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Paginação Premium -->
    <?php if ($total_paginas > 1): ?>
        <div class="mt-4 mb-5 d-flex justify-content-center">
            <nav>
                <ul class="pagination pagination-modern gap-2 shadow-sm rounded-3">
                    <?php if ($pagina > 1): ?>
                        <li class="page-item">
                            <a class="page-link rounded-3" href="contatos.php?pagina=<?= $pagina - 1 ?>&filtro=<?= urlencode($filtro) ?>">
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
                            <a class="page-link rounded-3" href="contatos.php?pagina=<?= $i ?>&filtro=<?= urlencode($filtro) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($pagina < $total_paginas): ?>
                        <li class="page-item">
                            <a class="page-link rounded-3" href="contatos.php?pagina=<?= $pagina + 1 ?>&filtro=<?= urlencode($filtro) ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Reutilizado -->
<div class="modal fade" id="modalContato" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content rounded-4 border-0 shadow-lg contact-modal">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="modal-title fw-bold" id="modalL">Novo Contato</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <input type="hidden" name="id_contato" id="id_c">
                <div class="mb-3">
                    <label class="form-label small fw-bold"><i class="bi bi-building me-1"></i>Empresa</label>
                    <select name="id_cliente" id="id_cl" class="form-select" required>
                        <?php foreach ($clientes as $cl): ?>
                            <option value="<?= $cl['id_cliente'] ?>"><?= htmlspecialchars($cl['fantasia']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold"><i class="bi bi-person me-1"></i>Nome</label>
                    <input type="text" name="nome" id="nom" class="form-control" required placeholder="Nome completo">
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold"><i class="bi bi-briefcase me-1"></i>Cargo</label>
                        <input type="text" name="cargo" id="car" class="form-control" placeholder="Ex: Gestor de T.I">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold"><i class="bi bi-telephone me-1"></i>Telefone</label>
                        <input type="text" name="telefone_ddd" id="tel" class="form-control" placeholder="(00) 00000-0000">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold"><i class="bi bi-envelope me-1"></i>E-mail</label>
                    <input type="email" name="email" id="ema" class="form-control" placeholder="contato@empresa.com">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold"><i class="bi bi-chat-left-text me-1"></i>Observações</label>
                    <textarea name="observacao" id="obs" class="form-control" rows="3" placeholder="Informações adicionais..."></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light fw-bold px-4" data-bs-dismiss="modal"><i class="bi bi-x-circle me-2"></i>Cancelar</button>
                <button type="submit" class="btn btn-primary fw-bold shadow-sm px-4"><i class="bi bi-check-circle me-2"></i>Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Limpar modal ao esconder
    var modalContatoEl = document.getElementById('modalContato');
    if (modalContatoEl) {
        modalContatoEl.addEventListener('hidden.bs.modal', function () {
            document.getElementById('modalL').innerText = 'Novo Contato';
            document.getElementById('id_c').value = '';
            document.getElementById('nom').value = '';
            document.getElementById('ema').value = '';
            document.getElementById('car').value = '';
            document.getElementById('tel').value = '';
            document.getElementById('obs').value = '';
            // Resetar o select para a primeira opção
            var selectCliente = document.getElementById('id_cl');
            if (selectCliente && selectCliente.options.length > 0) {
                selectCliente.selectedIndex = 0;
            }
        });
    }

    // Usando delegação de eventos para garantir que funcione em todos os cards
    document.body.addEventListener('click', function(e) {
        var btn = e.target.closest('.edit-trigger');
        if (btn) {
            document.getElementById('modalL').innerText = 'Editar Contato';
            document.getElementById('id_c').value = btn.dataset.id || '';
            document.getElementById('id_cl').value = btn.dataset.cliente || '';
            document.getElementById('nom').value = btn.dataset.nome || '';
            document.getElementById('ema').value = (btn.dataset.email === 'null' || !btn.dataset.email) ? '' : btn.dataset.email;
            document.getElementById('car').value = btn.dataset.cargo || '';
            document.getElementById('tel').value = btn.dataset.telefone || '';
            document.getElementById('obs').value = btn.dataset.obs || '';
            
            // Força a exibição do Modal programaticamente para não conflitar com o data-bs-toggle="tooltip"
            var myModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalContato'));
            myModal.show();
        }
    });
});
</script>

<?php include 'footer.php'; ?>
