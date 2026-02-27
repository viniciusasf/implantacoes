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

// Agrupar por categoria
$orientacoes_agrupadas = [];
foreach ($orientacoes as $o) {
    $orientacoes_agrupadas[$o['categoria']][] = $o;
}

include 'header.php';
?>

<style>
.page-title {
    font-size: 1.6rem;
    letter-spacing: 0.2px;
}

.help-search-container {
    position: relative;
}

.help-search-container .form-control {
    height: 45px;
    border-radius: 10px;
    border: 2px solid #e9ecef;
    padding-left: 45px;
    transition: all 0.3s;
}

.help-search-container .form-control:focus {
    border-color: #4361ee;
    box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.15);
}

.help-search-container .search-icon {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    z-index: 10;
}

.filter-input {
    height: 45px;
    border-radius: 10px;
    border: 2px solid #e9ecef;
}

.filter-input:focus {
    border-color: #4361ee;
    box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.15);
}

.filter-btn {
    height: 45px;
    border-radius: 10px;
    font-weight: 600;
}

.help-modal .form-control,
.help-modal .form-select {
    border-radius: 10px;
    border: 2px solid #e9ecef;
    transition: all 0.25s;
}

.help-modal .form-control:focus,
.help-modal .form-select:focus {
    border-color: #4361ee;
    box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.15);
}

.collapse-icon {
    transition: transform 0.3s ease;
}
.collapsed .collapse-icon {
    transform: rotate(-90deg);
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="page-title fw-bold mb-0"><i class="bi bi-journal-richtext me-2 text-primary"></i>Orientacoes do Sistema</h3>
        <p class="text-muted small mb-0">Guia passo a passo de procedimentos e dúvidas frequentes.</p>
    </div>
    <button class="btn btn-primary shadow-sm filter-btn" data-bs-toggle="modal" data-bs-target="#modalOrientacao">
        <i class="bi bi-plus-lg me-2"></i>Nova Orientação
    </button>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($_GET['msg']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Barra de Busca -->
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-3 align-items-center">
            <div class="col-md-6">
                <div class="help-search-container">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text"
                        name="busca"
                        class="form-control"
                        placeholder="Buscar por titulo, descricao ou palavras-chave..."
                        value="<?php echo htmlspecialchars($busca); ?>"
                        autofocus>
                </div>
            </div>
            <div class="col-md-4">
                <select name="filtro_categoria" class="form-select filter-input">
                    <option value="">Todas as categorias</option>
                    <option value="Clientes" <?php echo $filtro_categoria == 'Clientes' ? 'selected' : ''; ?>>Clientes</option>
                    <option value="Contatos" <?php echo $filtro_categoria == 'Contatos' ? 'selected' : ''; ?>>Contatos</option>
                    <option value="Treinamentos" <?php echo $filtro_categoria == 'Treinamentos' ? 'selected' : ''; ?>>Treinamentos</option>
                    <option value="Tarefas" <?php echo $filtro_categoria == 'Tarefas' ? 'selected' : ''; ?>>Tarefas</option>
                    <option value="Google Agenda" <?php echo $filtro_categoria == 'Google Agenda' ? 'selected' : ''; ?>>Google Agenda</option>
                    <option value="Geral" <?php echo $filtro_categoria == 'Geral' ? 'selected' : ''; ?>>Geral</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary filter-btn flex-fill">
                    <i class="bi bi-search me-1"></i>Buscar
                </button>
                <?php if (!empty($busca) || !empty($filtro_categoria)): ?>
                    <a href="orientacoes.php" class="btn btn-outline-secondary filter-btn" title="Limpar busca">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Resultados -->
<?php if (empty($orientacoes)): ?>
    <div class="card shadow-sm border-0">
        <div class="card-body text-center py-5">
            <i class="bi bi-info-circle fs-1 text-muted d-block mb-3"></i>
            <p class="text-muted">
                <?php echo (!empty($busca) || !empty($filtro_categoria)) ? 'Nenhuma orientação encontrada com os filtros aplicados.' : 'Nenhuma orientação cadastrada ainda.'; ?>
            </p>
            <?php if (!empty($busca) || !empty($filtro_categoria)): ?>
                <a href="orientacoes.php" class="btn btn-sm btn-outline-primary">Limpar busca</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <!-- Accordion por Categoria -->
    <div class="accordion" id="accordionOrientacoes">
        <?php
        $first = true; // Variável para controlar o primeiro item
        foreach ($orientacoes_agrupadas as $categoria => $items):
            $categoria_id = md5($categoria);
        ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white border-0" id="heading<?php echo $categoria_id; ?>">
                    <h2 class="mb-0">
                        <button class="btn btn-link btn-block text-start fw-bold text-dark text-decoration-none d-flex align-items-center collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#collapse<?php echo $categoria_id; ?>"
                            aria-expanded="false" 
                            <?php echo $categoria_id; ?>
                            <i class="bi bi-folder-fill text-primary me-2 fs-5"></i>
                            <?php echo htmlspecialchars($categoria); ?>
                            <span class="badge bg-primary ms-2"><?php echo count($items); ?></span>
                            <i class="bi bi-chevron-down ms-auto collapse-icon"></i> <!-- Ícone indicador -->
                        </button>
                    </h2>
                </div>

                <div id="collapse<?php echo $categoria_id; ?>"
                    class="collapse" <!-- ALTERADO: removida a classe 'show' -->
                    <aria-labelledby="heading<?php echo $categoria_id; ?>"<data-bs-parent="#accordionOrientacoes">
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($items as $o): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h5 class="mb-2 fw-bold">
                                                <i class="bi bi-lightbulb text-warning me-2"></i>
                                                <?php echo htmlspecialchars($o['titulo']); ?>
                                            </h5>
                                            <div class="text-muted mb-2" style="white-space: pre-wrap;">
                                                <?php echo nl2br(htmlspecialchars($o['descricao'])); ?>
                                            </div>
                                            <?php if (!empty($o['link_acesso'])): ?>
                                                <div class="mb-2">
                                                    <a href="<?php echo htmlspecialchars($o['link_acesso']); ?>"
                                                        class="btn btn-sm btn-outline-primary"
                                                        target="_blank">
                                                        <i class="bi bi-link-45deg me-1"></i>Acessar Link
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($o['palavras_chave'])): ?>
                                                <div class="small text-muted">
                                                    <i class="bi bi-tags me-1"></i>
                                                    <?php
                                                    $tags = explode(',', $o['palavras_chave']);
                                                    foreach ($tags as $tag): ?>
                                                        <span class="badge bg-light text-dark border me-1"><?php echo trim(htmlspecialchars($tag)); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ms-3">
                                            <button class="btn btn-sm btn-light text-primary edit-btn me-1"
                                                data-id="<?php echo $o['id_orientacao']; ?>"
                                                data-titulo="<?php echo htmlspecialchars($o['titulo']); ?>"
                                                data-categoria="<?php echo htmlspecialchars($o['categoria']); ?>"
                                                data-descricao="<?php echo htmlspecialchars($o['descricao']); ?>"
                                                data-link="<?php echo htmlspecialchars($o['link_acesso']); ?>"
                                                data-palavras="<?php echo htmlspecialchars($o['palavras_chave']); ?>"
                                                data-ordem="<?php echo $o['ordem']; ?>"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalOrientacao">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <a href="orientacoes.php?delete=<?php echo $o['id_orientacao']; ?>"
                                                class="btn btn-sm btn-light text-danger"
                                                onclick="return confirm('Deseja realmente excluir esta orientação?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php
            $first = false;
        endforeach;
        ?>
    </div>
<?php endif; ?>

<!-- Modal Orientação -->
<div class="modal fade" id="modalOrientacao" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg help-modal">
            <form method="POST">
                <div class="modal-header border-0 px-4 pt-4">
                    <h5 class="fw-bold" id="modalTitle">Nova Orientação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-2">
                    <input type="hidden" name="id_orientacao" id="id_orientacao">

                    <div class="row mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold small">Título da Orientação</label>
                            <input type="text"
                                name="titulo"
                                id="titulo"
                                class="form-control"
                                required
                                placeholder="Ex: Como cadastrar um novo cliente">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Ordem</label>
                            <input type="number"
                                name="ordem"
                                id="ordem"
                                class="form-control"
                                value="0"
                                min="0"
                                placeholder="0">
                            <small class="text-muted">Ordem de exibição</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Categoria</label>
                        <select name="categoria" id="categoria" class="form-select" required>
                            <option value="">Selecione uma categoria...</option>
                            <option value="Clientes">Clientes</option>
                            <option value="Contatos">Contatos</option>
                            <option value="Treinamentos">Treinamentos</option>
                            <option value="Tarefas">Tarefas</option>
                            <option value="Sistema">Sistema</option>
                            <option value="Geral">Geral</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Descrição / Passo a Passo</label>
                        <textarea name="descricao"
                            id="descricao"
                            class="form-control"
                            rows="6"
                            required
                            placeholder="Descreva o procedimento passo a passo...

Exemplo:
1. Acesse o menu Clientes
2. Clique em 'Novo Cliente'
3. Preencha os dados obrigatórios
4. Clique em 'Salvar'"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Link de Acesso (Opcional)</label>
                        <input type="url"
                            name="link_acesso"
                            id="link_acesso"
                            class="form-control"
                            placeholder="https://exemplo.com/pagina">
                        <small class="text-muted">URL para página relacionada ou documentação externa</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Palavras-chave (Tags)</label>
                        <input type="text"
                            name="palavras_chave"
                            id="palavras_chave"
                            class="form-control"
                            placeholder="cadastro, cliente, empresa">
                        <small class="text-muted">Separe por vírgulas. Facilita a busca e localização.</small>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 gap-2">
                    <button type="button" class="btn btn-light fw-bold px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">Salvar Orientacao</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Lógica de Edição
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
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

    // Reset ao fechar modal
    document.getElementById('modalOrientacao').addEventListener('hidden.bs.modal', function() {
        document.getElementById('modalTitle').innerText = 'Nova Orientação';
        document.querySelector('form').reset();
        document.getElementById('id_orientacao').value = '';
    });
</script>

<?php include 'footer.php'; ?>
