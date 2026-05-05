<?php
require_once 'config.php';

// Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM orientacoes WHERE id_orientacao = ?");
    $stmt->execute([$id]);
    header("Location: orientacoes.php?msg=Orientação removida com sucesso");
    exit;
}

// Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $titulo = $_POST['titulo'];
    $categoria = $_POST['categoria'];
    $descricao = $_POST['descricao'];
    $link_acesso = !empty($_POST['link_acesso']) ? $_POST['link_acesso'] : null;
    $palavras_chave = $_POST['palavras_chave'];
    $ordem = !empty($_POST['ordem']) ? $_POST['ordem'] : 0;

    if (isset($_POST['id_orientacao']) && !empty($_POST['id_orientacao'])) {
        $stmt = $pdo->prepare("UPDATE orientacoes SET titulo=?, categoria=?, descricao=?, link_acesso=?, palavras_chave=?, ordem=? WHERE id_orientacao=?");
        $stmt->execute([$titulo, $categoria, $descricao, $link_acesso, $palavras_chave, $ordem, $_POST['id_orientacao']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO orientacoes (titulo, categoria, descricao, link_acesso, palavras_chave, ordem) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$titulo, $categoria, $descricao, $link_acesso, $palavras_chave, $ordem]);
    }
    header("Location: orientacoes.php?msg=Operação realizada com sucesso");
    exit;
}

// Filtro de busca
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$filtro_categoria = isset($_GET['filtro_categoria']) ? trim($_GET['filtro_categoria']) : '';

$sql = "SELECT * FROM orientacoes WHERE 1=1";
$params = [];

if (!empty($busca)) {
    $sql .= " AND (titulo LIKE ? OR descricao LIKE ? OR palavras_chave LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

if (!empty($filtro_categoria)) {
    $sql .= " AND categoria = ?";
    $params[] = $filtro_categoria;
}

$sql .= " ORDER BY categoria ASC, ordem ASC, titulo ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orientacoes = $stmt->fetchAll();
$total_orientacoes = count($orientacoes);

// Agrupar por categoria
$orientacoes_agrupadas = [];
foreach ($orientacoes as $o) {
    $orientacoes_agrupadas[$o['categoria']][] = $o;
}

include 'header.php';
?>

<style>
/* // Design System Clean & Modern (Perplexity Style) */
:root {
    --glass-bg: rgba(255, 255, 255, 0.03);
    --glass-border: rgba(255, 255, 255, 0.08);
    --card-hover-y: -4px;
}

[data-theme="dark"] :root {
    --glass-bg: rgba(15, 23, 42, 0.4);
    --glass-border: rgba(255, 255, 255, 0.1);
}

/* Page Header - Simplificado e menos intrusivo */
.modern-header {
    padding: 1rem 0 2rem 0;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 2rem;
}

.title-accent {
    color: var(--primary);
    background: linear-gradient(120deg, var(--primary), var(--purple));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

/* Search Area - Sem sobreposição forçada */
.search-section {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    margin-bottom: 2.5rem;
    box-shadow: var(--shadow-sm);
}

.search-input-wrapper {
    position: relative;
    flex-grow: 1;
}

.search-input-wrapper i {
    position: absolute;
    left: 1.25rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--primary);
    font-size: 1.2rem;
}

.search-input-wrapper .form-control {
    padding-left: 3.5rem;
    height: 52px;
    background: var(--bg-body) !important;
    border: 1px solid var(--border-color) !important;
}

/* Accordion & Cards Style */
.category-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    margin-bottom: 1rem;
    transition: all 0.25s ease;
}

.category-card:hover {
    border-color: var(--primary);
}

.accordion-trigger {
    padding: 1.25rem 1.5rem;
    display: flex;
    align-items: center;
    width: 100%;
    background: none;
    border: none;
    text-align: left;
    color: inherit;
}

.icon-box {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    margin-right: 1.25rem;
}

.orientation-item {
    background: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 1.5rem;
    height: 100%;
    transition: all 0.3s ease;
}

.orientation-item:hover {
    transform: translateY(var(--card-hover-y));
    box-shadow: var(--shadow-hover);
    border-color: rgba(67, 97, 238, 0.3);
}

/* Typography & Badges */
.tag-pill {
    font-size: 0.7rem;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    background: var(--primary-light);
    color: var(--primary);
    border: 1px solid transparent;
}

.badge-count {
    background: var(--bg-body);
    color: var(--text-muted);
    font-size: 0.75rem;
    padding: 0.25rem 0.6rem;
    border-radius: 6px;
    margin-left: 1rem;
    border: 1px solid var(--border-color);
}

/* Animations */
.gsap-reveal {
    opacity: 0;
    transform: translateY(15px);
}

.rotate-icon {
    transition: transform 0.3s ease;
}

.collapsed .rotate-icon {
    transform: rotate(-90deg);
}

/* Helpers */
.fw-800 { font-weight: 800; }
</style>

<div class="container-fluid px-0">
    <!-- Modern Header -->
    <div class="modern-header d-flex justify-content-between align-items-end">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.8rem;">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Orientações</li>
                </ol>
            </nav>
            <h2 class="fw-800 mb-0">Central de <span class="title-accent">Orientações</span></h2>
            <p class="text-muted small mb-0">
                <span class="badge bg-primary bg-opacity-10 text-primary me-2"><?php echo $total_orientacoes; ?> registro<?php echo $total_orientacoes != 1 ? 's' : ''; ?></span>
                Documentação e procedimentos operacionais atualizados.
            </p>
        </div>
        <button class="btn btn-primary px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalOrientacao">
            <i class="bi bi-plus-lg"></i>
            <span class="ms-2">Nova Orientação</span>
        </button>
    </div>

    <!-- Mensagens -->
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="background: var(--success-light); color: var(--success);">
            <i class="bi bi-check2-circle me-2"></i><?php echo htmlspecialchars($_GET['msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search Section -->
    <div class="search-section">
        <form method="GET" class="row g-3 align-items-center">
            <div class="col-lg-7">
                <div class="search-input-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text"
                        name="busca"
                        class="form-control"
                        placeholder="Pesquisar por termo, dúvida ou categoria..."
                        value="<?php echo htmlspecialchars($busca); ?>"
                        autofocus>
                </div>
            </div>
            <div class="col-lg-3">
                <select name="filtro_categoria" class="form-select" style="height: 52px;">
                    <option value="">Todas as Categorias</option>
                    <option value="Clientes" <?php echo $filtro_categoria == 'Clientes' ? 'selected' : ''; ?>>Clientes</option>
                    <option value="Contatos" <?php echo $filtro_categoria == 'Contatos' ? 'selected' : ''; ?>>Contatos</option>
                    <option value="Treinamentos" <?php echo $filtro_categoria == 'Treinamentos' ? 'selected' : ''; ?>>Treinamentos</option>
                    <option value="Tarefas" <?php echo $filtro_categoria == 'Tarefas' ? 'selected' : ''; ?>>Tarefas</option>
                    <option value="Google Agenda" <?php echo $filtro_categoria == 'Google Agenda' ? 'selected' : ''; ?>>Google Agenda</option>
                    <option value="Geral" <?php echo $filtro_categoria == 'Geral' ? 'selected' : ''; ?>>Geral</option>
                </select>
            </div>
            <div class="col-lg-2">
                <button type="submit" class="btn btn-primary w-100 fw-bold" style="height: 52px;">Filtrar</button>
            </div>
        </form>
    </div>

    <!-- Content Area -->
    <?php if (empty($orientacoes)): ?>
        <div class="text-center py-5">
            <div class="mb-4 text-muted"><i class="bi bi-search" style="font-size: 3rem; opacity: 0.2;"></i></div>
            <h5 class="text-muted">Nenhum resultado encontrado.</h5>
            <a href="orientacoes.php" class="btn btn-link text-primary mt-2">Limpar todos os filtros</a>
        </div>
    <?php else: ?>
        <div class="accordion" id="accordionMain">
            <?php foreach ($orientacoes_agrupadas as $categoria => $items): 
                $cat_id = 'cat_'.md5($categoria);
            ?>
                <div class="category-card gsap-reveal">
                    <button class="accordion-trigger collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $cat_id; ?>">
                        <div class="icon-box"><i class="bi bi-folder2"></i></div>
                        <div class="flex-grow-1">
                            <h6 class="mb-0 fw-bold text-dark"><?php echo htmlspecialchars($categoria); ?></h6>
                            <span class="text-muted extra-small"><?php echo count($items); ?> orientações registradas</span>
                        </div>
                        <div class="ms-auto d-flex align-items-center">
                            <span class="badge-count"><?php echo count($items); ?></span>
                            <i class="bi bi-chevron-down rotate-icon ms-3 opacity-50"></i>
                        </div>
                    </button>
                    
                    <div id="<?php echo $cat_id; ?>" class="collapse" data-bs-parent="#accordionMain">
                        <div class="p-4 pt-0">
                            <div class="row g-4">
                                <?php foreach ($items as $o): ?>
                                    <div class="col-12 col-md-6">
                                        <div class="orientation-item">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <h6 class="fw-bold mb-0 text-main" style="max-width: 80%;"><?php echo htmlspecialchars($o['titulo']); ?></h6>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-link text-muted p-0" type="button" data-bs-toggle="dropdown">
                                                        <i class="bi bi-three-dots-vertical"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                        <li><a class="dropdown-item py-2 edit-btn" href="#" 
                                                            data-id="<?php echo $o['id_orientacao']; ?>"
                                                            data-titulo="<?php echo htmlspecialchars($o['titulo']); ?>"
                                                            data-categoria="<?php echo htmlspecialchars($o['categoria']); ?>"
                                                            data-descricao="<?php echo htmlspecialchars($o['descricao']); ?>"
                                                            data-link="<?php echo htmlspecialchars($o['link_acesso']); ?>"
                                                            data-palavras="<?php echo htmlspecialchars($o['palavras_chave']); ?>"
                                                            data-ordem="<?php echo $o['ordem']; ?>"
                                                            data-bs-toggle="modal" data-bs-target="#modalOrientacao">
                                                            <i class="bi bi-pencil me-2 text-primary"></i>Editar</a></li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li><a class="dropdown-item py-2 text-danger" href="orientacoes.php?delete=<?php echo $o['id_orientacao']; ?>" onclick="return confirm('Excluir orientação?')">
                                                            <i class="bi bi-trash3 me-2"></i>Remover</a></li>
                                                    </ul>
                                                </div>
                                            </div>
                                            
                                            <p class="text-muted small mb-4" style="line-height: 1.6; white-space: pre-wrap;"><?php echo htmlspecialchars($o['descricao']); ?></p>
                                            
                                            <div class="d-flex align-items-center justify-content-between mt-auto">
                                                <div class="d-flex flex-wrap gap-1">
                                                    <?php 
                                                    if(!empty($o['palavras_chave'])):
                                                        foreach(explode(',', $o['palavras_chave']) as $tag): ?>
                                                            <span class="tag-pill">#<?php echo trim($tag); ?></span>
                                                        <?php endforeach;
                                                    endif; ?>
                                                </div>
                                                <?php if(!empty($o['link_acesso'])): ?>
                                                    <a href="<?php echo $o['link_acesso']; ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                                        Ver Link <i class="bi bi-arrow-up-right ms-1" style="font-size: 0.7rem;"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal -->
<div class="modal fade" id="modalOrientacao" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <div class="modal-header border-0 p-4 pb-0">
                    <h5 class="fw-bold" id="modalTitle">Nova Orientação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="id_orientacao" id="id_orientacao">
                    <div class="row g-3">
                        <div class="col-md-9">
                            <label class="form-label small fw-bold">Título</label>
                            <input type="text" name="titulo" id="titulo" class="form-control" placeholder="Título da orientação" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Ordem</label>
                            <input type="number" name="ordem" id="ordem" class="form-control" value="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Categoria</label>
                            <select name="categoria" id="categoria" class="form-select" required>
                                <option value="Clientes">Clientes</option>
                                <option value="Contatos">Contatos</option>
                                <option value="Treinamentos">Treinamentos</option>
                                <option value="Tarefas">Tarefas</option>
                                <option value="Google Agenda">Google Agenda</option>
                                <option value="Geral">Geral</option>
                                <option value="Sistema">Sistema</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Conteúdo da Orientação</label>
                            <textarea name="descricao" id="descricao" class="form-control" rows="8" placeholder="Passo a passo ou descrição técnica..." required></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Link (Opcional)</label>
                            <input type="url" name="link_acesso" id="link_acesso" class="form-control" placeholder="https://...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Tags (Separadas por vírgula)</label>
                            <input type="text" name="palavras_chave" id="palavras_chave" class="form-control" placeholder="tag1, tag2">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">Salvar Informação</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // GSAP Entrance
    gsap.from(".modern-header", { duration: 0.6, opacity: 0, x: -20, ease: "power2.out" });
    gsap.from(".search-section", { duration: 0.8, opacity: 0, y: 10, delay: 0.1, ease: "power2.out" });
    gsap.to(".gsap-reveal", { duration: 0.5, opacity: 1, y: 0, stagger: 0.08, delay: 0.3, ease: "power2.out" });

    // Modal Logic
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('modalTitle').innerText = 'Editar Orientação';
            document.getElementById('id_orientacao').value = this.dataset.id;
            document.getElementById('titulo').value = this.dataset.titulo;
            document.getElementById('categoria').value = this.dataset.categoria;
            document.getElementById('descricao').value = this.dataset.descricao;
            document.getElementById('link_acesso').value = this.dataset.link;
            document.getElementById('palavras_chave').value = this.dataset.palavras;
            document.getElementById('ordem').value = this.dataset.ordem;
        });
    });

    const modal = document.getElementById('modalOrientacao');
    modal.addEventListener('hidden.bs.modal', function() {
        document.getElementById('modalTitle').innerText = 'Nova Orientação';
        modal.querySelector('form').reset();
        document.getElementById('id_orientacao').value = '';
    });
});
</script>

<?php include 'footer.php'; ?>
