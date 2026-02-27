<?php
require_once 'config.php';

// Verificar se deve mostrar concluídas
$mostrar_concluidas = isset($_GET['mostrar_concluidas']) ? $_GET['mostrar_concluidas'] : '0';

// Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM tarefas WHERE id_tarefa = ?");
    $stmt->execute([$id]);
    header("Location: tarefas.php?msg=Tarefa removida com sucesso&tipo=success&mostrar_concluidas=" . $mostrar_concluidas);
    exit;
}

// Lógica para Alterar Status Rápido
if (isset($_GET['status']) && isset($_GET['id'])) {
    $status = $_GET['status'];
    $id = $_GET['id'];
    $stmt = $pdo->prepare("UPDATE tarefas SET status = ? WHERE id_tarefa = ?");
    $stmt->execute([$status, $id]);
    header("Location: tarefas.php?msg=Status da tarefa atualizado&tipo=success&mostrar_concluidas=" . $mostrar_concluidas);
    exit;
}

// Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_cliente = !empty($_POST['id_cliente']) ? $_POST['id_cliente'] : null;
    $responsavel = $_POST['responsavel'];
    $titulo = $_POST['titulo'];
    $descricao = $_POST['descricao'];
    $prioridade = $_POST['prioridade'];
    $status = $_POST['status'];
    $data_entrega = !empty($_POST['data_entrega']) ? $_POST['data_entrega'] : null;

    if (isset($_POST['id_tarefa']) && !empty($_POST['id_tarefa'])) {
        $stmt = $pdo->prepare("UPDATE tarefas SET id_cliente=?, responsavel=?, titulo=?, descricao=?, prioridade=?, status=?, data_entrega=? WHERE id_tarefa=?");
        $stmt->execute([$id_cliente, $responsavel, $titulo, $descricao, $prioridade, $status, $data_entrega, $_POST['id_tarefa']]);
        $msg = "Tarefa atualizada com sucesso";
    } else {
        $stmt = $pdo->prepare("INSERT INTO tarefas (id_cliente, responsavel, titulo, descricao, prioridade, status, data_entrega) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id_cliente, $responsavel, $titulo, $descricao, $prioridade, $status, $data_entrega]);
        $msg = "Tarefa adicionada com sucesso";
    }
    header("Location: tarefas.php?msg=" . urlencode($msg) . "&tipo=success&mostrar_concluidas=" . $mostrar_concluidas);
    exit;
}

// Filtros
$filtro_cliente = isset($_GET['filtro_cliente']) ? trim($_GET['filtro_cliente']) : '';
$filtro_responsavel = isset($_GET['filtro_responsavel']) ? trim($_GET['filtro_responsavel']) : '';
$filtro_status = isset($_GET['filtro_status']) ? trim($_GET['filtro_status']) : '';

// Ordenação
$ordenacao = isset($_GET['ordenacao']) ? $_GET['ordenacao'] : 'data_entrega';
$direcao = isset($_GET['direcao']) ? $_GET['direcao'] : 'asc';

// Validação da ordenação para segurança
$colunas_permitidas = ['titulo', 'responsavel', 'cliente_nome', 'data_entrega', 'status', 'prioridade'];
$ordenacao = in_array($ordenacao, $colunas_permitidas) ? $ordenacao : 'data_entrega';
$direcao = $direcao === 'desc' ? 'desc' : 'asc';

// Paginação
$por_pagina = 15;
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina - 1) * $por_pagina;

// Query para contagem e estatísticas
$sql_base = "SELECT t.*, c.fantasia as cliente_nome FROM tarefas t LEFT JOIN clientes c ON t.id_cliente = c.id_cliente WHERE 1=1";
$sql_contagem = "SELECT COUNT(*) as total FROM tarefas t LEFT JOIN clientes c ON t.id_cliente = c.id_cliente WHERE 1=1";

// Parâmetros e condições WHERE
$params = [];
$where_conditions = [];

// Por padrão, não mostrar tarefas concluídas
if ($mostrar_concluidas != '1') {
    $where_conditions[] = "t.status != 'Concluída'";
}

if (!empty($filtro_cliente)) {
    $where_conditions[] = "c.fantasia LIKE ?";
    $params[] = "%$filtro_cliente%";
}

if (!empty($filtro_responsavel)) {
    $where_conditions[] = "t.responsavel = ?";
    $params[] = $filtro_responsavel;
}

if (!empty($filtro_status)) {
    $where_conditions[] = "t.status = ?";
    $params[] = $filtro_status;
}

// Adicionar condições WHERE
if (!empty($where_conditions)) {
    $where_sql = " AND " . implode(" AND ", $where_conditions);
    $sql_base .= $where_sql;
    $sql_contagem .= $where_sql;
}

// Executar contagem total
$stmt_contagem = $pdo->prepare($sql_contagem);
$stmt_contagem->execute($params);
$total_registros = $stmt_contagem->fetchColumn();

// Calcular total de páginas
$total_paginas = ceil($total_registros / $por_pagina);

// Ordenação
$ordenacao_sql = '';
switch($ordenacao) {
    case 'cliente_nome': $ordenacao_sql = 'c.fantasia'; break;
    case 'data_entrega': $ordenacao_sql = 'CASE WHEN t.data_entrega IS NULL THEN 1 ELSE 0 END, t.data_entrega'; break;
    default: $ordenacao_sql = 't.' . $ordenacao;
}

// Query final com ordenação e paginação
$sql_base .= " ORDER BY $ordenacao_sql $direcao LIMIT :offset, :limit";

// Executar query principal
$stmt = $pdo->prepare($sql_base);
foreach ($params as $index => $value) {
    $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $por_pagina, PDO::PARAM_INT);
$stmt->execute();
$tarefas = $stmt->fetchAll();

// Estatísticas para os cards
$sql_estatisticas = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status != 'Concluída' THEN 1 ELSE 0 END) as pendentes,
    SUM(CASE WHEN status = 'Concluída' THEN 1 ELSE 0 END) as concluidas,
    SUM(CASE WHEN prioridade = 'Alta' AND status != 'Concluída' THEN 1 ELSE 0 END) as altas,
    SUM(CASE WHEN data_entrega < NOW() AND status != 'Concluída' THEN 1 ELSE 0 END) as atrasadas
    FROM tarefas WHERE 1=1";

$params_estatisticas = [];
$where_estatisticas = [];

if (!empty($filtro_cliente)) {
    $where_estatisticas[] = "id_cliente IN (SELECT id_cliente FROM clientes WHERE fantasia LIKE ?)";
    $params_estatisticas[] = "%$filtro_cliente%";
}

if (!empty($filtro_responsavel)) {
    $where_estatisticas[] = "responsavel = ?";
    $params_estatisticas[] = $filtro_responsavel;
}

if (!empty($filtro_status)) {
    $where_estatisticas[] = "status = ?";
    $params_estatisticas[] = $filtro_status;
}

if (!empty($where_estatisticas)) {
    $sql_estatisticas .= " AND " . implode(" AND ", $where_estatisticas);
}

$stmt_estatisticas = $pdo->prepare($sql_estatisticas);
$stmt_estatisticas->execute($params_estatisticas);
$estatisticas = $stmt_estatisticas->fetch(PDO::FETCH_ASSOC);

$clientes = $pdo->query("SELECT id_cliente, fantasia FROM clientes ORDER BY fantasia ASC")->fetchAll();

include 'header.php';
?>

<style>
/* Estilos para as tarefas */
.page-title {
    font-size: 1.6rem;
    letter-spacing: 0.2px;
}

.tasks-search-container {
    position: relative;
}

.tasks-search-container .form-control {
    height: 45px;
    border-radius: 10px;
    border: 2px solid #e9ecef;
    padding-left: 45px;
    transition: all 0.3s;
}

.tasks-search-container .form-control:focus {
    border-color: #4361ee;
    box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.15);
}

.tasks-search-container .search-icon {
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

.tasks-modal .form-control,
.tasks-modal .form-select {
    border-radius: 10px;
    border: 2px solid #e9ecef;
    transition: all 0.25s;
}

.tasks-modal .form-control:focus,
.tasks-modal .form-select:focus {
    border-color: #4361ee;
    box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.15);
}

.status-badge {
    min-width: 120px;
    text-align: center;
}

.status-dropdown .dropdown-toggle::after {
    display: none;
}

.prioridade-badge {
    min-width: 70px;
    text-align: center;
}

.table-hover tbody tr {
    transition: all 0.2s;
}

.table-hover tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.05) !important;
}

/* Badges coloridos */
.bg-danger-subtle {
    background-color: rgba(220, 53, 69, 0.1) !important;
    color: #dc3545 !important;
    border: 1px solid rgba(220, 53, 69, 0.2) !important;
}

.bg-warning-subtle {
    background-color: rgba(255, 193, 7, 0.1) !important;
    color: #ffc107 !important;
    border: 1px solid rgba(255, 193, 7, 0.2) !important;
}

.bg-success-subtle {
    background-color: rgba(25, 135, 84, 0.1) !important;
    color: #198754 !important;
    border: 1px solid rgba(25, 135, 84, 0.2) !important;
}

.bg-info-subtle {
    background-color: rgba(13, 202, 240, 0.1) !important;
    color: #0dcaf0 !important;
    border: 1px solid rgba(13, 202, 240, 0.2) !important;
}

.bg-primary-subtle {
    background-color: rgba(13, 110, 253, 0.1) !important;
    color: #0d6efd !important;
    border: 1px solid rgba(13, 110, 253, 0.2) !important;
}

/* Cards estatísticos */
.stat-card {
    transition: transform 0.3s, box-shadow 0.3s;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1) !important;
}

/* Toggle de concluídas */
.toggle-concluidas .form-check-input {
    width: 3em;
    height: 1.5em;
    cursor: pointer;
}

.toggle-concluidas .form-check-input:checked {
    background-color: #198754;
    border-color: #198754;
}

/* Ordenação */
.sortable-header {
    cursor: pointer;
    transition: all 0.2s;
}

.sortable-header:hover {
    background-color: rgba(0,0,0,0.03);
}

/* Badge de filtro ativo */
.filter-badge {
    font-size: 0.85rem;
}

/* Ações na tabela */
.action-buttons {
    display: flex;
    gap: 0.3rem;
    justify-content: flex-end;
}

.btn-action {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s;
    border: 1px solid #dee2e6;
    background: white;
    color: #6c757d;
    text-decoration: none;
}

.btn-action:hover {
    transform: scale(1.1);
    text-decoration: none;
}

.btn-action.edit:hover {
    background-color: #0d6efd;
    color: white;
    border-color: #0d6efd;
}

.btn-action.delete:hover {
    background-color: #dc3545;
    color: white;
    border-color: #dc3545;
}

/* Tarefas atrasadas */
.tarefa-atrasada {
    position: relative;
}

.tarefa-atrasada::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background-color: #dc3545;
    border-radius: 2px;
}

/* Descrição com tooltip */
.descricao-truncada {
    max-width: 250px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    cursor: help;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="page-title fw-bold mb-0"><i class="bi bi-list-task me-2 text-primary"></i>Tarefas</h3>
        <p class="text-muted small mb-0">Gerencie pendências e atividades das implantações.</p>
    </div>
    <button class="btn btn-primary shadow-sm d-flex align-items-center filter-btn" data-bs-toggle="modal" data-bs-target="#modalTarefa">
        <i class="bi bi-plus-lg me-2"></i>Nova Tarefa
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

<!-- Cards de Estatísticas -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm stat-card border-start border-primary border-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase">Total</span>
                        <h2 class="fw-bold my-1 text-dark"><?= $estatisticas['total'] ?></h2>
                    </div>
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                        <i class="bi bi-list-task text-primary" style="font-size: 1.5rem;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-0 shadow-sm stat-card border-start border-warning border-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase">Pendentes</span>
                        <h2 class="fw-bold my-1 text-dark"><?= $estatisticas['pendentes'] ?></h2>
                    </div>
                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle">
                        <i class="bi bi-clock text-warning" style="font-size: 1.5rem;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-0 shadow-sm stat-card border-start border-danger border-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase">Alta Prioridade</span>
                        <h2 class="fw-bold my-1 text-dark"><?= $estatisticas['altas'] ?></h2>
                    </div>
                    <div class="bg-danger bg-opacity-10 p-3 rounded-circle">
                        <i class="bi bi-exclamation-triangle text-danger" style="font-size: 1.5rem;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-0 shadow-sm stat-card border-start border-success border-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase">Concluídas</span>
                        <h2 class="fw-bold my-1 text-dark"><?= $estatisticas['concluidas'] ?></h2>
                    </div>
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                        <i class="bi bi-check-circle text-success" style="font-size: 1.5rem;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Barra de Filtro -->
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-3 align-items-center">
            <input type="hidden" name="ordenacao" value="<?= htmlspecialchars($ordenacao) ?>">
            <input type="hidden" name="direcao" value="<?= htmlspecialchars($direcao) ?>">
            <input type="hidden" name="mostrar_concluidas" value="<?= htmlspecialchars($mostrar_concluidas) ?>">
            
            <div class="col-md-4">
                <div class="tasks-search-container">
                    <i class="bi bi-building search-icon"></i>
                    <input type="text" 
                           name="filtro_cliente" 
                           class="form-control" 
                           placeholder="Filtrar por cliente..."
                           value="<?php echo htmlspecialchars($filtro_cliente); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="filtro_responsavel" class="form-select filter-input">
                    <option value="">Todos os responsáveis</option>
                    <option value="GestãoPRO" <?php echo $filtro_responsavel == 'GestãoPRO' ? 'selected' : ''; ?>>GestãoPRO</option>
                    <option value="Cliente" <?php echo $filtro_responsavel == 'Cliente' ? 'selected' : ''; ?>>Cliente</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="filtro_status" class="form-select filter-input">
                    <option value="">Todos os status</option>
                    <option value="Pendente" <?php echo $filtro_status == 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
                    <option value="Em Andamento" <?php echo $filtro_status == 'Em Andamento' ? 'selected' : ''; ?>>Em Andamento</option>
                    <?php if ($mostrar_concluidas == '1'): ?>
                        <option value="Concluída" <?php echo $filtro_status == 'Concluída' ? 'selected' : ''; ?>>Concluída</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary filter-btn flex-fill d-flex align-items-center justify-content-center">
                    <i class="bi bi-search me-2"></i>Filtrar
                </button>
                <?php if (!empty($filtro_cliente) || !empty($filtro_responsavel) || !empty($filtro_status)): ?>
                    <a href="tarefas.php?mostrar_concluidas=<?= $mostrar_concluidas ?>" 
                       class="btn btn-outline-secondary filter-btn d-flex align-items-center" 
                       title="Limpar filtros">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
        
        <!-- Controles adicionais -->
        <div class="row mt-3 align-items-center">
            <div class="col-md-6">
                <?php if (!empty($filtro_cliente) || !empty($filtro_responsavel) || !empty($filtro_status)): ?>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary-subtle text-primary filter-badge px-3 py-2">
                            <i class="bi bi-filter-circle me-2"></i>
                            <strong>Filtros ativos:</strong>
                            <?php if (!empty($filtro_cliente)): ?>
                                <span class="ms-2">Cliente: <?php echo htmlspecialchars($filtro_cliente); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($filtro_responsavel)): ?>
                                <span class="ms-2">Responsável: <?php echo htmlspecialchars($filtro_responsavel); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($filtro_status)): ?>
                                <span class="ms-2">Status: <?php echo htmlspecialchars($filtro_status); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-6 text-end">
                <div class="form-check form-switch toggle-concluidas d-inline-flex align-items-center">
                    <input class="form-check-input" 
                           type="checkbox" 
                           role="switch" 
                           id="toggleConcluidas" 
                           <?= $mostrar_concluidas == '1' ? 'checked' : '' ?>
                           onchange="toggleTarefasConcluidas(this.checked)">
                    <label class="form-check-label small fw-bold ms-2" for="toggleConcluidas">
                        <i class="bi bi-check-circle me-1"></i>Mostrar tarefas concluídas
                    </label>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cabeçalho da tabela com contador -->
<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white border-bottom py-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="d-flex align-items-center">
                    <i class="bi bi-list-task text-primary me-2"></i>
                    <span class="text-muted small">
                        <?php if ($total_registros > 0): ?>
                            Exibindo <strong><?= min($por_pagina, count($tarefas)) ?></strong> de <strong><?= $total_registros ?></strong> tarefas
                            <?php if (!empty($filtro_cliente) || !empty($filtro_responsavel) || !empty($filtro_status)): ?>
                                <span class="text-primary">(filtradas)</span>
                            <?php endif; ?>
                            <?php if ($mostrar_concluidas == '1'): ?>
                                <span class="text-success">(incluindo concluídas)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            Nenhuma tarefa encontrada
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <div class="col-md-6 text-md-end">
                <div class="btn-group" role="group">
                    <a href="tarefas.php?ordenacao=<?= $ordenacao ?>&direcao=<?= $direcao == 'asc' ? 'desc' : 'asc' ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>"
                       class="btn btn-outline-secondary btn-sm d-flex align-items-center">
                        <i class="bi bi-sort-<?= $direcao == 'asc' ? 'down' : 'up' ?> me-1"></i>
                        Ordenar
                    </a>
                    <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <?php 
                        $nomes_colunas = [
                            'titulo' => 'Título',
                            'responsavel' => 'Responsável',
                            'cliente_nome' => 'Cliente',
                            'data_entrega' => 'Prazo',
                            'status' => 'Status',
                            'prioridade' => 'Prioridade'
                        ];
                        echo $nomes_colunas[$ordenacao] ?? 'Prazo';
                        ?>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="tarefas.php?ordenacao=titulo&direcao=asc&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>">Título</a></li>
                        <li><a class="dropdown-item" href="tarefas.php?ordenacao=responsavel&direcao=asc&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>">Responsável</a></li>
                        <li><a class="dropdown-item" href="tarefas.php?ordenacao=cliente_nome&direcao=asc&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>">Cliente</a></li>
                        <li><a class="dropdown-item" href="tarefas.php?ordenacao=data_entrega&direcao=asc&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>">Prazo</a></li>
                        <li><a class="dropdown-item" href="tarefas.php?ordenacao=status&direcao=asc&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>">Status</a></li>
                        <li><a class="dropdown-item" href="tarefas.php?ordenacao=prioridade&direcao=asc&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>">Prioridade</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body p-0">
        <?php if (empty($tarefas)): ?>
            <div class="text-center py-5">
                <i class="bi bi-check2-circle fs-1 text-muted d-block mb-3"></i>
                <h5 class="text-muted mb-3">
                    <?php if ($mostrar_concluidas == '1'): ?>
                        Nenhuma tarefa encontrada
                    <?php else: ?>
                        Nenhuma pendência encontrada
                    <?php endif; ?>
                </h5>
                <p class="text-muted mb-4">
                    <?php if (!empty($filtro_cliente) || !empty($filtro_responsavel) || !empty($filtro_status)): ?>
                        Não foram encontradas tarefas com os filtros aplicados.
                    <?php elseif ($mostrar_concluidas == '1'): ?>
                        Você não possui tarefas cadastradas no sistema.
                    <?php else: ?>
                        Parabéns! Não há pendências no momento.
                    <?php endif; ?>
                </p>
                <?php if (!empty($filtro_cliente) || !empty($filtro_responsavel) || !empty($filtro_status)): ?>
                    <a href="tarefas.php?mostrar_concluidas=<?= $mostrar_concluidas ?>" class="btn btn-outline-primary">
                        <i class="bi bi-x-lg me-1"></i>Limpar filtros
                    </a>
                <?php endif; ?>
                <button class="btn btn-primary ms-2" data-bs-toggle="modal" data-bs-target="#modalTarefa">
                    <i class="bi bi-plus-lg me-2"></i>Criar Nova Tarefa
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive table-scroll-container" style="max-height: 500px;">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4 py-3 border-bottom-0">
                                <a href="tarefas.php?ordenacao=prioridade&direcao=<?= ($ordenacao == 'prioridade' && $direcao == 'asc') ? 'desc' : 'asc' ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>"
                                   class="text-decoration-none text-dark d-flex align-items-center sortable-header">
                                    <span class="text-muted small fw-bold text-uppercase">Prioridade</span>
                                    <?php if ($ordenacao == 'prioridade'): ?>
                                        <i class="bi bi-caret-<?= $direcao == 'asc' ? 'up' : 'down' ?>-fill ms-1 small text-primary"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-3 border-bottom-0">
                                <a href="tarefas.php?ordenacao=titulo&direcao=<?= ($ordenacao == 'titulo' && $direcao == 'asc') ? 'desc' : 'asc' ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>"
                                   class="text-decoration-none text-dark d-flex align-items-center sortable-header">
                                    <span class="text-muted small fw-bold text-uppercase">Tarefa</span>
                                    <?php if ($ordenacao == 'titulo'): ?>
                                        <i class="bi bi-caret-<?= $direcao == 'asc' ? 'up' : 'down' ?>-fill ms-1 small text-primary"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-3 border-bottom-0">
                                <a href="tarefas.php?ordenacao=responsavel&direcao=<?= ($ordenacao == 'responsavel' && $direcao == 'asc') ? 'desc' : 'asc' ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>"
                                   class="text-decoration-none text-dark d-flex align-items-center sortable-header">
                                    <span class="text-muted small fw-bold text-uppercase">Responsável</span>
                                    <?php if ($ordenacao == 'responsavel'): ?>
                                        <i class="bi bi-caret-<?= $direcao == 'asc' ? 'up' : 'down' ?>-fill ms-1 small text-primary"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-3 border-bottom-0">
                                <a href="tarefas.php?ordenacao=cliente_nome&direcao=<?= ($ordenacao == 'cliente_nome' && $direcao == 'asc') ? 'desc' : 'asc' ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>"
                                   class="text-decoration-none text-dark d-flex align-items-center sortable-header">
                                    <span class="text-muted small fw-bold text-uppercase">Cliente</span>
                                    <?php if ($ordenacao == 'cliente_nome'): ?>
                                        <i class="bi bi-caret-<?= $direcao == 'asc' ? 'up' : 'down' ?>-fill ms-1 small text-primary"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-3 border-bottom-0">
                                <a href="tarefas.php?ordenacao=data_entrega&direcao=<?= ($ordenacao == 'data_entrega' && $direcao == 'asc') ? 'desc' : 'asc' ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>"
                                   class="text-decoration-none text-dark d-flex align-items-center sortable-header">
                                    <span class="text-muted small fw-bold text-uppercase">Prazo</span>
                                    <?php if ($ordenacao == 'data_entrega'): ?>
                                        <i class="bi bi-caret-<?= $direcao == 'asc' ? 'up' : 'down' ?>-fill ms-1 small text-primary"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-3 border-bottom-0">
                                <a href="tarefas.php?ordenacao=status&direcao=<?= ($ordenacao == 'status' && $direcao == 'asc') ? 'desc' : 'asc' ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>"
                                   class="text-decoration-none text-dark d-flex align-items-center sortable-header">
                                    <span class="text-muted small fw-bold text-uppercase">Status</span>
                                    <?php if ($ordenacao == 'status'): ?>
                                        <i class="bi bi-caret-<?= $direcao == 'asc' ? 'up' : 'down' ?>-fill ms-1 small text-primary"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="text-end pe-4 py-3 border-bottom-0">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tarefas as $t): 
                            $isAtrasada = $t['data_entrega'] && strtotime($t['data_entrega']) < time() && $t['status'] != 'Concluída';
                            $rowClass = $isAtrasada ? 'tarefa-atrasada' : '';
                            $rowClass .= $t['status'] == 'Concluída' ? ' table-success' : '';
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td class="ps-4">
                                <?php 
                                $prioridadeClass = 'bg-info-subtle';
                                if($t['prioridade'] == 'Alta') $prioridadeClass = 'bg-danger-subtle';
                                if($t['prioridade'] == 'Média') $prioridadeClass = 'bg-warning-subtle';
                                ?>
                                <span class="badge <?php echo $prioridadeClass; ?> prioridade-badge px-3 py-2">
                                    <i class="bi bi-flag me-1"></i><?php echo $t['prioridade']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark d-flex align-items-center">
                                    <i class="bi bi-card-checklist text-primary me-2"></i>
                                    <?php echo htmlspecialchars($t['titulo']); ?>
                                </div>
                                <?php if(!empty($t['descricao'])): ?>
                                    <div class="descricao-truncada small text-muted mt-1" 
                                         data-bs-toggle="tooltip" 
                                         data-bs-title="<?php echo htmlspecialchars($t['descricao']); ?>">
                                        <?php echo htmlspecialchars($t['descricao']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $t['responsavel'] == 'GestãoPRO' ? 'bg-primary-subtle' : 'bg-secondary-subtle'; ?> rounded-pill px-3 py-2 d-flex align-items-center">
                                    <i class="bi bi-person-circle me-1"></i>
                                    <?php echo $t['responsavel']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border d-flex align-items-center">
                                    <i class="bi bi-building me-1"></i>
                                    <?php echo $t['cliente_nome'] ? htmlspecialchars($t['cliente_nome']) : 'Geral'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="<?= $isAtrasada ? 'text-danger fw-bold' : 'text-muted' ?> small d-flex align-items-center">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    <?php echo $t['data_entrega'] ? date('d/m/Y H:i', strtotime($t['data_entrega'])) : '-'; ?>
                                    <?php if($isAtrasada): ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger ms-2">ATRASADA</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="dropdown status-dropdown">
                                    <button class="btn btn-sm rounded-pill px-3 dropdown-toggle d-flex align-items-center status-badge <?php 
                                        if($t['status'] == 'Concluída') echo 'bg-success-subtle text-success';
                                        elseif($t['status'] == 'Em Andamento') echo 'bg-primary-subtle text-primary';
                                        else echo 'bg-warning-subtle text-warning';
                                    ?>" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-circle-fill me-1" style="font-size: 0.6rem;"></i>
                                        <?php echo $t['status']; ?>
                                    </button>
                                    <ul class="dropdown-menu shadow border-0">
                                        <li><a class="dropdown-item small d-flex align-items-center" href="tarefas.php?id=<?php echo $t['id_tarefa']; ?>&status=Pendente&mostrar_concluidas=<?= $mostrar_concluidas ?>"><i class="bi bi-circle text-warning me-2"></i>Pendente</a></li>
                                        <li><a class="dropdown-item small d-flex align-items-center" href="tarefas.php?id=<?php echo $t['id_tarefa']; ?>&status=Em Andamento&mostrar_concluidas=<?= $mostrar_concluidas ?>"><i class="bi bi-circle text-primary me-2"></i>Em Andamento</a></li>
                                        <li><a class="dropdown-item small d-flex align-items-center" href="tarefas.php?id=<?php echo $t['id_tarefa']; ?>&status=Concluída&mostrar_concluidas=<?= $mostrar_concluidas ?>"><i class="bi bi-circle text-success me-2"></i>Concluída</a></li>
                                    </ul>
                                </div>
                            </td>
                            <td class="text-end pe-4">
                                <div class="action-buttons">
                                    <button class="btn-action edit edit-btn me-1" 
                                            data-id="<?php echo $t['id_tarefa']; ?>"
                                            data-cliente="<?php echo $t['id_cliente']; ?>"
                                            data-responsavel="<?php echo $t['responsavel']; ?>"
                                            data-titulo="<?php echo htmlspecialchars($t['titulo']); ?>"
                                            data-descricao="<?php echo htmlspecialchars($t['descricao']); ?>"
                                            data-prioridade="<?php echo $t['prioridade']; ?>"
                                            data-status="<?php echo $t['status']; ?>"
                                            data-data="<?php echo $t['data_entrega'] ? date('Y-m-d\TH:i', strtotime($t['data_entrega'])) : ''; ?>"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalTarefa"
                                            title="Editar tarefa">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <a href="tarefas.php?delete=<?php echo $t['id_tarefa']; ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>&pagina=<?= $pagina ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>" 
                                       class="btn-action delete" 
                                       onclick="return confirm('Deseja realmente excluir esta tarefa?')"
                                       title="Excluir tarefa">
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
                                <a class="page-link" href="tarefas.php?pagina=<?= $pagina-1 ?>&ordenacao=<?= $ordenacao ?>&direcao=<?= $direcao ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php
                        $inicio = max(1, $pagina - 2);
                        $fim = min($total_paginas, $pagina + 2);
                        
                        if ($inicio > 1) {
                            echo '<li class="page-item"><a class="page-link" href="tarefas.php?pagina=1&ordenacao='.$ordenacao.'&direcao='.$direcao.'&filtro_cliente='.urlencode($filtro_cliente).'&filtro_responsavel='.urlencode($filtro_responsavel).'&filtro_status='.urlencode($filtro_status).'&mostrar_concluidas='.$mostrar_concluidas.'">1</a></li>';
                            if ($inicio > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        
                        for ($i = $inicio; $i <= $fim; $i++):
                        ?>
                            <li class="page-item <?= ($i == $pagina) ? 'active' : '' ?>">
                                <a class="page-link" href="tarefas.php?pagina=<?= $i ?>&ordenacao=<?= $ordenacao ?>&direcao=<?= $direcao ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php
                        if ($fim < $total_paginas) {
                            if ($fim < $total_paginas - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            echo '<li class="page-item"><a class="page-link" href="tarefas.php?pagina='.$total_paginas.'&ordenacao='.$ordenacao.'&direcao='.$direcao.'&filtro_cliente='.urlencode($filtro_cliente).'&filtro_responsavel='.urlencode($filtro_responsavel).'&filtro_status='.urlencode($filtro_status).'&mostrar_concluidas='.$mostrar_concluidas.'">'.$total_paginas.'</a></li>';
                        }
                        ?>
                        
                        <?php if ($pagina < $total_paginas): ?>
                            <li class="page-item">
                                <a class="page-link" href="tarefas.php?pagina=<?= $pagina+1 ?>&ordenacao=<?= $ordenacao ?>&direcao=<?= $direcao ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>&filtro_responsavel=<?= urlencode($filtro_responsavel) ?>&filtro_status=<?= urlencode($filtro_status) ?>&mostrar_concluidas=<?= $mostrar_concluidas ?>">
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

<!-- Modal Tarefa -->
<div class="modal fade" id="modalTarefa" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg tasks-modal">
            <form method="POST">
                <div class="modal-header border-0 px-4 pt-4">
                    <h5 class="fw-bold d-flex align-items-center" id="modalTitle">
                        <i class="bi bi-plus-circle me-2"></i>Nova Tarefa
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-2">
                    <input type="hidden" name="id_tarefa" id="id_tarefa">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small d-flex align-items-center">
                            <i class="bi bi-card-heading me-1"></i>Título da Tarefa
                        </label>
                        <input type="text" name="titulo" id="titulo" class="form-control" required placeholder="Ex: Configurar impressora fiscal">
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small d-flex align-items-center">
                                <i class="bi bi-person me-1"></i>Responsável
                            </label>
                            <select name="responsavel" id="responsavel" class="form-select">
                                <option value="GestãoPRO">GestãoPRO</option>
                                <option value="Cliente">Cliente</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small d-flex align-items-center">
                                <i class="bi bi-flag me-1"></i>Prioridade
                            </label>
                            <select name="prioridade" id="prioridade" class="form-select">
                                <option value="Baixa">Baixa</option>
                                <option value="Média" selected>Média</option>
                                <option value="Alta">Alta</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small d-flex align-items-center">
                            <i class="bi bi-building me-1"></i>Cliente (Opcional)
                        </label>
                        <select name="id_cliente" id="id_cliente" class="form-select">
                            <option value="">Geral / Sem Cliente</option>
                            <?php foreach ($clientes as $cl): ?>
                                <option value="<?php echo $cl['id_cliente']; ?>"><?php echo htmlspecialchars($cl['fantasia']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small d-flex align-items-center">
                                <i class="bi bi-calendar me-1"></i>Prazo de Entrega
                            </label>
                            <input type="datetime-local" name="data_entrega" id="data_entrega" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small d-flex align-items-center">
                                <i class="bi bi-circle-fill me-1"></i>Status
                            </label>
                            <select name="status" id="status" class="form-select">
                                <option value="Pendente">Pendente</option>
                                <option value="Em Andamento">Em Andamento</option>
                                <option value="Concluída">Concluída</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small d-flex align-items-center">
                            <i class="bi bi-card-text me-1"></i>Descrição / Observações
                        </label>
                        <textarea name="descricao" id="descricao" class="form-control" rows="3" placeholder="Detalhes da tarefa..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 gap-2">
                    <button type="button" class="btn btn-light fw-bold px-4 d-flex align-items-center" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i>Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary fw-bold px-4 d-flex align-items-center">
                        <i class="bi bi-check-lg me-1"></i>Salvar Tarefa
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Inicializar tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Configurar data/hora para o campo de prazo
    const dataEntregaInput = document.getElementById('data_entrega');
    if (dataEntregaInput && !dataEntregaInput.value) {
        const now = new Date();
        // Adicionar 7 dias como padrão
        now.setDate(now.getDate() + 7);
        // Format para datetime-local
        dataEntregaInput.value = now.toISOString().slice(0, 16);
    }
});

// Toggle para mostrar tarefas concluídas
function toggleTarefasConcluidas(mostrar) {
    const url = new URL(window.location.href);
    url.searchParams.set('mostrar_concluidas', mostrar ? '1' : '0');
    url.searchParams.set('pagina', '1'); // Resetar para primeira página
    window.location.href = url.toString();
}

// Editar tarefa
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Editar Tarefa';
        document.getElementById('id_tarefa').value = this.dataset.id;
        document.getElementById('id_cliente').value = this.dataset.cliente;
        document.getElementById('responsavel').value = this.dataset.responsavel;
        document.getElementById('titulo').value = this.dataset.titulo;
        document.getElementById('descricao').value = this.dataset.descricao;
        document.getElementById('prioridade').value = this.dataset.prioridade;
        document.getElementById('status').value = this.dataset.status;
        
        // Formatar data para o input datetime-local
        if (this.dataset.data) {
            document.getElementById('data_entrega').value = this.dataset.data;
        } else {
            document.getElementById('data_entrega').value = '';
        }
    });
});

// Reset modal quando fechado
document.getElementById('modalTarefa').addEventListener('hidden.bs.modal', function () {
    document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Nova Tarefa';
    document.querySelector('form').reset();
    document.getElementById('id_tarefa').value = '';
    
    // Resetar valores padrão
    document.getElementById('responsavel').value = 'GestãoPRO';
    document.getElementById('prioridade').value = 'Média';
    document.getElementById('status').value = 'Pendente';
    
    // Resetar data para 7 dias à frente
    const now = new Date();
    now.setDate(now.getDate() + 7);
    document.getElementById('data_entrega').value = now.toISOString().slice(0, 16);
});

// Auto-focus no campo de busca
const searchField = document.querySelector('input[name="filtro_cliente"]');
if (searchField) {
    searchField.focus();
}
</script>

<?php include 'footer.php'; ?>
