<?php
date_default_timezone_set('America/Sao_Paulo');
require_once 'config.php';

// Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM contatos WHERE id_contato = ?");
    $stmt->execute([$id]);
    header("Location: contatos.php?msg=Contato+removido+com+sucesso&tipo=success");
    exit;
}

// Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_cliente = (int)$_POST['id_cliente'];
    $nome = $_POST['nome'];
    $email = isset($_POST['email']) ? trim($_POST['email']) : null;
    if ($email === '') { $email = null; }
    $cargo = $_POST['cargo'];
    $telefone = $_POST['telefone_ddd'];
    $observacao = $_POST['observacao'];
    
    if (isset($_POST['id_contato']) && !empty($_POST['id_contato'])) {
        $id_contato = (int)$_POST['id_contato'];
        $stmt = $pdo->prepare("UPDATE contatos SET id_cliente=?, nome=?, email=?, cargo=?, telefone_ddd=?, observacao=? WHERE id_contato=?");
        $stmt->execute([$id_cliente, $nome, $email, $cargo, $telefone, $observacao, $id_contato]);
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
/* Design System Clean & Modern */
[data-theme="dark"] {
    --bg-body: #0d0e12;
    --bg-card: #16171d;
    --border-color: #2b2e35;
    --text-main: #f1f5f9;
    --text-muted: #cbd5e1;
}

body, html {
    background-color: var(--bg-body);
    color: var(--text-main);
}

/* Modern Page Header */
.modern-header {
    padding: 1rem 0 2rem 0;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 2.5rem;
}

.title-accent {
    color: var(--primary);
    background: linear-gradient(120deg, var(--primary), var(--purple));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

/* Contact Card Premium */
.contact-card-premium {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    height: 100%;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
}

.contact-card-premium:hover {
    transform: translateY(-5px);
    border-color: var(--primary-light);
    box-shadow: var(--shadow-lg);
}

.contact-avatar {
    width: 48px;
    height: 48px;
    background: var(--primary-light);
    color: var(--primary);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-bottom: 1.25rem;
}

.contact-name {
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--text-main);
    margin-bottom: 0.25rem;
}

.contact-role {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--primary);
    margin-bottom: 1rem;
}

.contact-info-list {
    margin-bottom: 1.5rem;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    font-size: 0.85rem;
    color: var(--text-muted);
}

.info-item i {
    width: 16px;
    text-align: center;
}

.client-tag {
    background: var(--bg-body);
    border: 1px solid var(--border-color);
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-main);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: auto;
}

/* Controls */
.control-bar {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 1rem;
    margin-bottom: 2rem;
    display: flex;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.search-input-modern {
    background: var(--bg-body) !important;
    border: 1px solid var(--border-color) !important;
    border-radius: 10px !important;
    color: var(--text-main) !important;
    padding-left: 2.5rem !important;
}

.btn-modern {
    height: 42px;
    border-radius: 10px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}

.gsap-reveal {
    opacity: 0;
    transform: translateY(20px);
}
</style>

<div class="container-fluid px-lg-5 pt-4">

    <!-- Modern Header -->
    <div class="modern-header d-flex justify-content-between align-items-end gsap-reveal">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.8rem;">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Contatos</li>
                </ol>
            </nav>
            <h2 class="fw-800 mb-0">Diretório de <span class="title-accent">Contatos</span></h2>
            <p class="text-muted small mb-0">Gestão centralizada de relacionamentos com clientes.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-body border text-muted px-3 py-2 rounded-3 me-2">Total: <?= $total_registros ?></span>
            <button class="btn btn-primary btn-modern px-4" data-bs-toggle="modal" data-bs-target="#modalContato">
                <i class="bi bi-plus-lg"></i> Novo Contato
            </button>
        </div>
    </div>

    <!-- Controls Bar -->
    <div class="control-bar gsap-reveal">
        <div class="search-container position-relative flex-grow-1" style="max-width: 500px;">
            <i class="bi bi-search position-absolute text-muted" style="left: 1rem; top: 50%; transform: translateY(-50%);"></i>
            <form method="GET" action="contatos.php" id="searchForm">
                <input type="text" name="filtro" class="form-control search-input-modern w-100" 
                       placeholder="Buscar por nome, empresa ou cargo..." value="<?= htmlspecialchars($filtro) ?>">
            </form>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="row g-4 mb-5">
        <?php if (empty($contatos)): ?>
            <div class="col-12 text-center py-5">
                <i class="bi bi-person-x display-1 text-muted opacity-25 mb-4 d-block"></i>
                <h4 class="text-muted">Nenhum contato encontrado.</h4>
            </div>
        <?php endif; ?>

        <?php foreach ($contatos as $index => $c): ?>
        <div class="col-md-6 col-xl-3 gsap-reveal">
            <div class="contact-card-premium">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="contact-avatar">
                        <i class="bi bi-person"></i>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-link text-muted p-0" data-bs-toggle="dropdown">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg">
                            <li><a class="dropdown-item edit-trigger" href="#" 
                                data-id="<?= $c['id_contato'] ?>" 
                                data-cliente="<?= $c['id_cliente'] ?>"
                                data-nome="<?= htmlspecialchars($c['nome']) ?>"
                                data-email="<?= htmlspecialchars($c['email']) ?>"
                                data-cargo="<?= htmlspecialchars($c['cargo']) ?>"
                                data-telefone="<?= htmlspecialchars($c['telefone_ddd']) ?>"
                                data-obs="<?= htmlspecialchars($c['observacao']) ?>"
                                onclick="openEditModal(this)">
                                <i class="bi bi-pencil me-2"></i> Editar
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="contatos.php?delete=<?= $c['id_contato'] ?>" onclick="return confirm('Excluir contato?')">
                                <i class="bi bi-trash me-2"></i> Excluir
                            </a></li>
                        </ul>
                    </div>
                </div>

                <div class="contact-name text-truncate" title="<?= htmlspecialchars($c['nome']) ?>"><?= htmlspecialchars($c['nome']) ?></div>
                <div class="contact-role text-truncate"><?= htmlspecialchars($c['cargo']) ?: 'Sem Cargo' ?></div>

                <div class="contact-info-list">
                    <?php if($c['telefone_ddd']): ?>
                        <div class="info-item">
                            <i class="bi bi-telephone"></i>
                            <span><?= htmlspecialchars($c['telefone_ddd']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if($c['email']): ?>
                        <div class="info-item">
                            <i class="bi bi-envelope"></i>
                            <span class="text-truncate"><?= htmlspecialchars($c['email']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="client-tag text-truncate">
                    <i class="bi bi-building"></i> <?= htmlspecialchars($c['fantasia']) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_paginas > 1): ?>
        <div class="d-flex justify-content-center mb-5 gsap-reveal">
            <nav>
                <ul class="pagination pagination-modern gap-2 shadow-sm rounded-3">
                    <?php if ($pagina > 1): ?>
                        <li class="page-item">
                            <a class="page-link rounded-3 border-0 bg-card text-main" href="contatos.php?pagina=<?= $pagina - 1 ?>&filtro=<?= urlencode($filtro) ?>">
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
                            <a class="page-link rounded-3 border-0 <?= $i == $pagina ? 'btn-primary' : 'bg-card text-main' ?>" href="contatos.php?pagina=<?= $i ?>&filtro=<?= urlencode($filtro) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($pagina < $total_paginas): ?>
                        <li class="page-item">
                            <a class="page-link rounded-3 border-0 bg-card text-main" href="contatos.php?pagina=<?= $pagina + 1 ?>&filtro=<?= urlencode($filtro) ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- Modal de Contato Premium -->
<div class="modal fade" id="modalContato" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 24px; overflow: hidden;">
            <div class="modal-header p-4 border-0 bg-primary text-white position-relative">
                <div class="position-relative z-1">
                    <h5 class="modal-title fw-bold d-flex align-items-center" id="modalTitle">
                        <i class="bi bi-person-plus-fill me-3 fs-3"></i> Ficha de Contato
                    </h5>
                    <p class="mb-0 opacity-75 small">Gerencie as informações do seu contato</p>
                </div>
                <button type="button" class="btn-close btn-close-white position-relative z-1" data-bs-dismiss="modal"></button>
                <div class="position-absolute top-0 start-0 w-100 h-100" style="background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);"></div>
            </div>

            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="id_contato" id="id_contato">
                
                <div class="dashboard-section p-4 mb-3">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted">Empresa / Cliente</label>
                            <select name="id_cliente" id="id_cliente" class="form-select" required>
                                <option value="">Selecione um cliente...</option>
                                <?php foreach ($clientes as $cl): ?>
                                    <option value="<?= $cl['id_cliente'] ?>"><?= htmlspecialchars($cl['fantasia']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted">Nome do Contato</label>
                            <input type="text" name="nome" id="nome" class="form-control" required placeholder="Nome completo">
                        </div>
                        <div class="col-md-6 text-dark">
                            <label class="form-label small fw-bold text-muted">Cargo</label>
                            <input type="text" name="cargo" id="cargo" class="form-control" placeholder="Ex: Gestor Financeiro">
                        </div>
                        <div class="col-md-6 text-dark">
                            <label class="form-label small fw-bold text-muted">Telefone</label>
                            <input type="text" name="telefone_ddd" id="telefone_ddd" class="form-control" placeholder="(00) 00000-0000">
                        </div>
                        <div class="col-12 text-dark">
                            <label class="form-label small fw-bold text-muted">E-mail</label>
                            <input type="email" name="email" id="email" class="form-control" placeholder="exemplo@gmail.com">
                        </div>
                        <div class="col-12 text-dark">
                            <label class="form-label small fw-bold text-muted">Observações</label>
                            <textarea name="observacao" id="observacao" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer p-4 border-0 bg-white">
                <button type="button" class="btn btn-link text-muted fw-bold text-decoration-none me-auto" data-bs-dismiss="modal">Descartar</button>
                <button type="submit" class="btn btn-primary px-5 fw-bold" style="border-radius: 12px; height: 50px;">
                    <i class="bi bi-check-lg me-2"></i> Salvar Contato
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof gsap !== 'undefined') {
            gsap.to(".gsap-reveal", {
                opacity: 1,
                y: 0,
                duration: 0.6,
                stagger: 0.1,
                ease: "power2.out"
            });
        } else {
            document.querySelectorAll(".gsap-reveal").forEach(el => el.style.opacity = 1);
        }
    });

    function openEditModal(element) {
        const d = element.dataset;
        document.getElementById('modalTitle').innerText = 'Editar Contato';
        document.getElementById('id_contato').value = d.id;
        document.getElementById('id_cliente').value = d.cliente;
        document.getElementById('nome').value = d.nome;
        document.getElementById('email').value = (d.email === 'null' || !d.email) ? '' : d.email;
        document.getElementById('cargo').value = d.cargo;
        document.getElementById('telefone_ddd').value = d.telefone;
        document.getElementById('observacao').value = d.obs;
        
        const modal = new bootstrap.Modal(document.getElementById('modalContato'));
        modal.show();
    }

    // Reset modal on close
    document.getElementById('modalContato').addEventListener('hidden.bs.modal', function () {
        document.getElementById('modalTitle').innerText = 'Novo Contato';
        document.getElementById('id_contato').value = '';
        document.getElementById('id_cliente').value = '';
        document.getElementById('nome').value = '';
        document.getElementById('email').value = '';
        document.getElementById('cargo').value = '';
        document.getElementById('telefone_ddd').value = '';
        document.getElementById('observacao').value = '';
    });
</script>

<!-- jQuery e Mask Plugin -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>

<script>
    $(document).ready(function(){
        var behavior = function (val) {
            return val.replace(/\D/g, '').length === 11 ? '(00) 00000-0000' : '(00) 0000-00009';
        },
        options = {
            onKeyPress: function (val, e, field, options) {
                field.mask(behavior.apply({}, arguments), options);
            }
        };

        $('#telefone_ddd').mask(behavior, options);
    });
</script>

<?php include 'footer.php'; ?>
