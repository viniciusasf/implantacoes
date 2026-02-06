<?php
date_default_timezone_set('America/Sao_Paulo');
require_once 'config.php';

// 1. Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM clientes WHERE id_cliente = ?");
    $stmt->execute([$id]);
    header("Location: clientes.php?msg=Cliente removido com sucesso");
    exit;
}

// 2. Lógica para Encerrar Implantação
if (isset($_GET['encerrar'])) {
    $id = $_GET['encerrar'];
    $data_fim = date('Y-m-d');

    $stmt = $pdo->prepare("UPDATE clientes SET data_fim = ? WHERE id_cliente = ?");
    $stmt->execute([$data_fim, $id]);

    header("Location: clientes.php?msg=Implantacao encerrada com sucesso");
    exit;
}

// 3. Lógica para Cancelar Implantação
if (isset($_GET['cancelar'])) {
    $id = $_GET['cancelar'];
    $data_cancelamento = date('Y-m-d');
    $motivo_cancelamento = isset($_GET['motivo']) ? $_GET['motivo'] : 'Cliente desistiu';

    $stmt = $pdo->prepare("UPDATE clientes SET data_fim = ?, observacao = CONCAT(IFNULL(observacao, ''), ' | CANCELADO: ', ?) WHERE id_cliente = ?");
    $stmt->execute([$data_cancelamento, $motivo_cancelamento, $id]);

    header("Location: clientes.php?msg=Implantacao cancelada com sucesso");
    exit;
}

// 4. Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fantasia = $_POST['fantasia'];
    $servidor = $_POST['servidor'];
    $vendedor = $_POST['vendedor'];
    $num_licencas = $_POST['num_licencas'] ?? 0;
    $serial = $_POST['serial'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $data_inicio = $_POST['data_inicio'];
    $data_fim = (!empty($_POST['data_fim']) && $_POST['data_fim'] !== '0000-00-00') ? $_POST['data_fim'] : null;
    $observacao = $_POST['observacao'] ?? '';
    $emitir_nf = $_POST['emitir_nf'] ?? 'Não';
    $configurado = $_POST['configurado'] ?? 'Não';

    if (isset($_POST['id_cliente']) && !empty($_POST['id_cliente'])) {
        $stmt = $pdo->prepare("UPDATE clientes SET fantasia=?, servidor=?, vendedor=?, num_licencas=?, serial=?, telefone_ddd=?, data_inicio=?, data_fim=?, observacao=?, emitir_nf=?, configurado=? WHERE id_cliente=?");
        $stmt->execute([$fantasia, $servidor, $vendedor, $num_licencas, $serial, $telefone, $data_inicio, $data_fim, $observacao, $emitir_nf, $configurado, $_POST['id_cliente']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO clientes (fantasia, servidor, vendedor, num_licencas, serial, telefone_ddd, data_inicio, data_fim, observacao, emitir_nf, configurado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fantasia, $servidor, $vendedor, $num_licencas, $serial, $telefone, $data_inicio, $data_fim, $observacao, $emitir_nf, $configurado]);
    }
    header("Location: clientes.php?msg=Dados atualizados");
    exit;
}

// 5. Consulta de Dados e Filtros
$filtro = isset($_GET['filtro']) ? trim($_GET['filtro']) : '';
$estagio = isset($_GET['estagio']) ? $_GET['estagio'] : '';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'cards';

$sql = "SELECT c.*, 
               COUNT(t.id_treinamento) as total_treinamentos,
               MAX(t.data_treinamento) as ultimo_treinamento,
               SUM(CASE WHEN t.status = 'PENDENTE' THEN 1 ELSE 0 END) as treinamentos_pendentes
        FROM clientes c
        LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
        WHERE 1=1";
$params = [];

if (!empty($filtro)) {
    $sql .= " AND (c.fantasia LIKE ? OR c.vendedor LIKE ? OR c.servidor LIKE ?)";
    $params = array_merge($params, ["%$filtro%", "%$filtro%", "%$filtro%"]);
}

if (!empty($busca)) {
    $sql .= " AND c.fantasia LIKE ?";
    $params[] = "%$busca%";
}

$sql .= " GROUP BY c.id_cliente ORDER BY c.fantasia ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$todos_clientes = $stmt->fetchAll();

// 6. Lógica de Contagem para os CARDS
$integracao = 0;
$operacional = 0;
$finalizacao = 0;
$critico = 0;
$clientes_filtrados = [];

$clientes_com_treinamentos = 0;
$clientes_em_atraso = 0;
$clientes_sem_treinamentos = 0;

foreach ($todos_clientes as $cl) {
    if ($cl['total_treinamentos'] > 0) {
        $clientes_com_treinamentos++;
    } else {
        $clientes_sem_treinamentos++;
    }

    if ($cl['treinamentos_pendentes'] > 0) {
        $clientes_em_atraso++;
    }

    $status_cl = "concluido";
    if (empty($cl['data_fim']) || $cl['data_fim'] === '0000-00-00') {
        $d = (new DateTime($cl['data_inicio']))->diff(new DateTime())->days;
        if ($d <= 30) {
            $integracao++;
            $status_cl = "integracao";
        } elseif ($d <= 70) {
            $operacional++;
            $status_cl = "operacional";
        } elseif ($d <= 91) {
            $finalizacao++;
            $status_cl = "finalizacao";
        } else {
            $critico++;
            $status_cl = "critico";
        }
    }

    if (empty($estagio) || $estagio == $status_cl) {
        $clientes_filtrados[] = $cl;
    }
}

include 'header.php';
?>

<style>
    :root {
        --primary-color: #4361ee;
        --success-color: #06d6a0;
        --warning-color: #ffd166;
        --danger-color: #ef476f;
        --info-color: #118ab2;
    }

    body, html {
        overflow-y: hidden !important;
        height: 100vh;
        background-color: #f8f9fa;
    }

    .container-fluid.py-4 {
        overflow-y: hidden !important;
        height: 100vh;
        display: flex;
        flex-direction: column;
    }

    /* HEADER STYLES */
    .main-header {
        background: linear-gradient(135deg, #4361ee 0%, #3a56d4 100%);
        color: white;
        padding: 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
        box-shadow: 0 4px 20px rgba(67, 97, 238, 0.15);
    }

    .header-stats {
        display: flex;
        gap: 1.5rem;
        align-items: center;
    }

    .stat-item {
        text-align: center;
        padding: 0.5rem 1rem;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        backdrop-filter: blur(10px);
    }

    .stat-value {
        font-size: 1.5rem;
        font-weight: bold;
        margin-bottom: 0.25rem;
    }

    .stat-label {
        font-size: 0.8rem;
        opacity: 0.9;
    }

    /* SEARCH AND FILTERS */
    .search-container {
        position: relative;
        flex: 1;
        max-width: 400px;
    }

    .search-container .form-control {
        padding-left: 45px;
        border-radius: 10px;
        border: 2px solid #e9ecef;
        transition: all 0.3s;
        height: 45px;
    }

    .search-container .form-control:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.15);
    }

    .search-container .search-icon {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        z-index: 10;
    }

    .btn-clear-search {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #6c757d;
        z-index: 10;
    }

    /* VIEW TOGGLE */
    .view-toggle {
        display: flex;
        background: white;
        border-radius: 10px;
        padding: 4px;
        border: 2px solid #e9ecef;
    }

    .view-toggle-btn {
        padding: 8px 16px;
        border: none;
        background: transparent;
        border-radius: 8px;
        color: #6c757d;
        transition: all 0.3s;
    }

    .view-toggle-btn.active {
        background: var(--primary-color);
        color: white;
        box-shadow: 0 2px 8px rgba(67, 97, 238, 0.3);
    }

    /* STATUS CARDS */
    .status-card {
        transition: all 0.3s ease;
        border: none;
        border-radius: 12px;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }

    .status-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    }

    .status-card.active {
        box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.3);
    }

    .status-indicator {
        width: 5px;
        height: 100%;
        position: absolute;
        left: 0;
        top: 0;
    }

    /* CLIENT CARDS VIEW - CORREÇÕES ESPECÍFICAS */
.client-cards-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
    padding: 1rem;
    overflow-y: auto;
    flex: 1;
    max-height: calc(100vh - 350px);
    
    /* Responsividade */
    @media (max-width: 1200px) {
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    }
    
    @media (max-width: 992px) {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    }
    
    @media (max-width: 768px) {
        grid-template-columns: 1fr; /* Uma coluna em mobile */
    }
}

/* Estilizar a barra de scroll */
.client-cards-container::-webkit-scrollbar {
    width: 8px;
}

.client-cards-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.client-cards-container::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

.client-cards-container::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Para Firefox */
.client-cards-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
    padding: 1rem;
    overflow-y: auto;
    flex: 1;
    
    /* Calcula altura automaticamente baseada no espaço disponível */
    height: calc(100vh - var(--header-height) - var(--controls-height) - var(--status-cards-height));
    min-height: 300px;
}

/* Defina essas variáveis no início do CSS */
:root {
    --header-height: 140px;
    --controls-height: 100px;
    --status-cards-height: 150px;
}

.client-card {
    background: white;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 280px; /* Altura mínima do card */
}

/* ... resto do CSS permanece igual ... */

    .client-card {
        background: white;
        border-radius: 12px;
        border: 1px solid #e9ecef;
        transition: all 0.3s;
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .client-card:hover {
        border-color: var(--primary-color);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
    }

    .client-card-header {
        padding: 1rem 1rem 0.5rem 1rem;
        border-bottom: 1px solid #f1f3f4;
    }

    .client-card-body {
        padding: 1rem;
        flex: 1;
    }

    .client-card-footer {
        padding: 0.75rem 1rem;
        background: #f8f9fa;
        border-top: 1px solid #e9ecef;
        display: flex;
        justify-content: space-between;
        align-items: center;
        min-height: 60px;
    }

    .client-info-left {
        flex: 1;
        min-width: 0;
        margin-right: 1rem;
    }

    .client-card-footer .action-buttons {
        display: flex;
        gap: 0.5rem;
        flex-shrink: 0;
    }

    /* CORREÇÃO ESPECÍFICA PARA BOTÕES NOS CARDS */
    .client-card .btn-action {
        width: 36px !important;
        height: 36px !important;
        min-width: 36px !important;
        max-width: 36px !important;
        padding: 0 !important;
        margin: 0 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        border-radius: 8px;
        transition: all 0.2s;
        position: relative;
        overflow: hidden;
        flex-shrink: 0;
        border: 1px solid #dee2e6;
    }

    /* Garantir que só o ícone fique visível */
    .client-card .btn-action > *:not(i) {
        display: none !important;
    }

    .client-card .btn-action i {
        font-size: 1rem;
        line-height: 1;
        margin: 0;
        padding: 0;
        display: block;
    }

    /* Remover qualquer texto oculto */
    .client-card .btn-action::after,
    .client-card .btn-action::before {
        content: none !important;
    }

    .client-card .btn-action:hover {
        transform: scale(1.1);
    }

    .client-status-badge {
        font-size: 0.7rem;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-weight: 500;
    }

    .progress {
        height: 4px;
    }

    /* TABLE VIEW */
    .table-container {
        flex: 1;
        overflow-y: auto;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }

    .table thead th {
        background-color: #f8f9fa;
        color: #6c757d;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.5px;
        border-top: none;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    /* ACTION BUTTONS PARA TABELA */
    .table-container .action-buttons {
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
    }

    .table-container .btn-action {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .table-container .btn-action:hover {
        transform: scale(1.1);
    }

    /* EMPTY STATE */
    .empty-state {
        padding: 4rem 2rem;
        text-align: center;
        color: #6c757d;
    }

    .empty-state-icon {
        font-size: 4rem;
        opacity: 0.3;
        margin-bottom: 1.5rem;
    }

    /* MODAL STYLES */
    .modal-content {
        border-radius: 16px;
        border: none;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
    }

    .modal-header {
        border-bottom: none;
        padding: 1.5rem 1.5rem 0.5rem 1.5rem;
    }

    .modal-body {
        padding: 0 1.5rem 1.5rem 1.5rem;
    }

    .form-label {
        font-weight: 600;
        color: #495057;
        margin-bottom: 0.5rem;
    }

    /* ANIMATIONS */
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .fade-in {
        animation: fadeIn 0.5s ease forwards;
    }
</style>

<div class="container-fluid py-4">
    <!-- HEADER COM ESTATÍSTICAS -->
    <div class="main-header">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h4 class="fw-bold mb-2"><i class="bi bi-people-fill me-2"></i>Gestão de Clientes</h4>
                <p class="mb-0 opacity-75">Gerencie todos os clientes em implantação</p>
            </div>
            <div class="col-md-6">
                <div class="d-flex justify-content-end align-items-center gap-3">
                    <div class="header-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?= count($todos_clientes) ?></div>
                            <div class="stat-label">Total</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?= $clientes_com_treinamentos ?></div>
                            <div class="stat-label">Com Treinamentos</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?= $clientes_em_atraso ?></div>
                            <div class="stat-label">Em Atraso</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- BARRA DE CONTROLES -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="d-flex align-items-center gap-3">
                <!-- CAMPO DE BUSCA -->
                <div class="search-container">
                    <i class="bi bi-search search-icon"></i>
                    <form method="GET" action="clientes.php" class="d-inline" id="searchForm">
                        <input type="text"
                            name="busca"
                            class="form-control"
                            placeholder="Buscar cliente pelo nome..."
                            value="<?= htmlspecialchars($busca) ?>"
                            autocomplete="off"
                            data-bs-toggle="tooltip"
                            data-bs-title="Digite para buscar clientes">
                        <?php if (!empty($estagio)): ?>
                            <input type="hidden" name="estagio" value="<?= htmlspecialchars($estagio) ?>">
                        <?php endif; ?>
                        <?php if (!empty($filtro)): ?>
                            <input type="hidden" name="filtro" value="<?= htmlspecialchars($filtro) ?>">
                        <?php endif; ?>
                        <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode) ?>">
                    </form>
                    <?php if (!empty($busca)): ?>
                        <button type="button" class="btn-clear-search" onclick="clearSearch()" title="Limpar busca">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- BOTÃO NOVO CLIENTE -->
                <button class="btn btn-primary fw-bold px-4 py-2"
                    data-bs-toggle="modal"
                    data-bs-target="#modalCliente"
                    style="border-radius: 10px; height: 45px;">
                    <i class="bi bi-plus-lg me-2"></i>Novo Cliente
                </button>
            </div>
        </div>

        <div class="col-md-4">
            <div class="d-flex justify-content-end align-items-center gap-3">
                <!-- TOGGLE DE VISUALIZAÇÃO -->
                <div class="view-toggle">
                    <button class="view-toggle-btn <?= $view_mode == 'cards' ? 'active' : '' ?>"
                        onclick="changeViewMode('cards')"
                        data-bs-toggle="tooltip"
                        data-bs-title="Visualização em cards">
                        <i class="bi bi-grid-3x3-gap"></i>
                    </button>
                    <button class="view-toggle-btn <?= $view_mode == 'list' ? 'active' : '' ?>"
                        onclick="changeViewMode('list')"
                        data-bs-toggle="tooltip"
                        data-bs-title="Visualização em lista">
                        <i class="bi bi-list"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- CARDS DE STATUS -->
    <div class="row g-3 mb-4">
        <?php
        $status_data = [
            ['id' => 'integracao', 'label' => 'Integração', 'count' => $integracao, 'color' => '#0dcaf0', 'icon' => 'bi-rocket-takeoff', 'days' => '0-30d'],
            ['id' => 'operacional', 'label' => 'Operacional', 'count' => $operacional, 'color' => '#0d6efd', 'icon' => 'bi-gear', 'days' => '31-70d'],
            ['id' => 'finalizacao', 'label' => 'Finalização', 'count' => $finalizacao, 'color' => '#ffc107', 'icon' => 'bi-flag', 'days' => '71-91d'],
            ['id' => 'critico', 'label' => 'Crítico', 'count' => $critico, 'color' => '#dc3545', 'icon' => 'bi-exclamation-triangle', 'days' => '> 91d']
        ];
        foreach ($status_data as $s): ?>
            <div class="col-md-3">
                <a href="?estagio=<?= $s['id'] ?>&busca=<?= urlencode($busca) ?>&filtro=<?= urlencode($filtro) ?>&view=<?= $view_mode ?>"
                    class="text-decoration-none">
                    <div class="card status-card shadow-sm <?= ($estagio == $s['id']) ? 'active' : '' ?>">
                        <div class="status-indicator" style="background-color: <?= $s['color'] ?>"></div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="text-muted mb-2 fw-bold"><?= $s['label'] ?></h6>
                                    <h2 class="fw-bold mb-1" style="color: <?= $s['color'] ?>"><?= $s['count'] ?></h2>
                                    <small class="text-muted"><?= $s['days'] ?></small>
                                </div>
                                <div style="color: <?= $s['color'] ?>; font-size: 1.5rem;">
                                    <i class="<?= $s['icon'] ?>"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- CONTEÚDO PRINCIPAL (CARDS OU TABELA) -->
    <?php if ($view_mode == 'cards'): ?>
        <!-- VISUALIZAÇÃO EM CARDS -->
        <div class="client-cards-container">
            <?php if (empty($clientes_filtrados)): ?>
                <div class="col-12">
                    <div class="empty-state">
                        <i class="bi bi-people empty-state-icon"></i>
                        <?php if (!empty($busca)): ?>
                            <h5 class="fw-bold mb-2">Nenhum cliente encontrado</h5>
                            <p class="mb-3">Não foram encontrados clientes com "<?= htmlspecialchars($busca) ?>"</p>
                            <a href="clientes.php?view=cards" class="btn btn-outline-primary">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Limpar busca
                            </a>
                        <?php else: ?>
                            <h5 class="fw-bold mb-2">Nenhum cliente cadastrado</h5>
                            <p class="mb-3">Comece cadastrando seu primeiro cliente</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCliente">
                                <i class="bi bi-plus-lg me-2"></i>Cadastrar Cliente
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($clientes_filtrados as $c):
                    $dias = (new DateTime($c['data_inicio']))->diff(new DateTime())->days;

                    if (empty($c['data_fim']) || $c['data_fim'] === '0000-00-00') {
                        if ($dias <= 30) {
                            $status = 'integracao';
                            $status_color = '#0dcaf0';
                        } elseif ($dias <= 70) {
                            $status = 'operacional';
                            $status_color = '#0d6efd';
                        } elseif ($dias <= 91) {
                            $status = 'finalizacao';
                            $status_color = '#ffc107';
                        } else {
                            $status = 'critico';
                            $status_color = '#dc3545';
                        }
                    } else {
                        $status = 'concluido';
                        $status_color = '#06d6a0';
                    }

                    $progress = min(($dias / 91) * 100, 100);
                    $emitir_nf = isset($c['emitir_nf']) ? $c['emitir_nf'] : 'Não';
                    $configurado = isset($c['configurado']) ? $c['configurado'] : 'Não';
                ?>
                    <div class="client-card fade-in">
                        <div class="client-card-header">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="fw-bold mb-1 text-truncate" title="<?= htmlspecialchars($c['fantasia']) ?>">
                                        <?= htmlspecialchars($c['fantasia']) ?>
                                    </h6>
                                    <small class="text-muted">
                                        <i class="bi bi-hdd me-1"></i><?= htmlspecialchars($c['servidor']) ?>
                                    </small>
                                </div>
                                <span class="client-status-badge" style="background-color: <?= $status_color ?>20; color: <?= $status_color ?>;">
                                    <?= ucfirst($status) ?>
                                </span>
                            </div>

                            <div class="progress mt-2">
                                <div class="progress-bar"
                                    role="progressbar"
                                    style="width: <?= $progress ?>%; background-color: <?= $status_color ?>;"
                                    aria-valuenow="<?= $progress ?>"
                                    aria-valuemin="0"
                                    aria-valuemax="100">
                                </div>
                            </div>
                        </div>

                        <div class="client-card-body">
                            <div class="row g-2">
                                <div class="col-6">
                                    <small class="text-muted d-block">Vendedor</small>
                                    <span class="fw-semibold"><?= htmlspecialchars($c['vendedor']) ?></span>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Início</small>
                                    <span class="fw-semibold"><?= date('d/m/Y', strtotime($c['data_inicio'])) ?></span>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Dias</small>
                                    <span class="fw-semibold"><?= $dias ?> dias</span>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Treinamentos</small>
                                    <div class="d-flex align-items-center">
                                        <span class="fw-semibold"><?= $c['total_treinamentos'] ?></span>
                                        <?php if ($c['treinamentos_pendentes'] > 0): ?>
                                            <span class="badge bg-danger ms-2" style="font-size: 0.6rem;">
                                                <?= $c['treinamentos_pendentes'] ?> pendente(s)
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($emitir_nf == 'Sim'): ?>
                                <div class="mt-3 p-2 rounded" style="background-color: <?= $configurado == 'Sim' ? '#d1e7dd' : '#fff3cd' ?>;">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-receipt me-2" style="color: <?= $configurado == 'Sim' ? '#198754' : '#ffc107' ?>;"></i>
                                        <small class="fw-bold" style="color: <?= $configurado == 'Sim' ? '#198754' : '#ffc107' ?>;">
                                            NF: <?= $emitir_nf ?> | Config: <?= $configurado ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="client-card-footer">
                            <div class="client-info-left">
                                <small class="text-muted text-truncate d-block">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    <?= $c['ultimo_treinamento'] ? 'Último: ' . date('d/m/Y', strtotime($c['ultimo_treinamento'])) : 'Sem treinamentos' ?>
                                </small>
                            </div>
                            <div class="action-buttons">
                                <button class="btn btn-sm btn-outline-primary btn-action"
                                    data-bs-toggle="tooltip"
                                    data-bs-title="Editar cliente"
                                    data-id="<?= $c['id_cliente'] ?>"
                                    data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>"
                                    data-servidor="<?= htmlspecialchars($c['servidor']) ?>"
                                    data-vendedor="<?= htmlspecialchars($c['vendedor']) ?>"
                                    data-num_licencas="<?= isset($c['num_licencas']) ? $c['num_licencas'] : 0 ?>"
                                    data-serial="<?= isset($c['serial']) ? htmlspecialchars($c['serial']) : '' ?>"
                                    data-data_inicio="<?= $c['data_inicio'] ?>"
                                    data-data_fim="<?= $c['data_fim'] ?>"
                                    data-emitir_nf="<?= htmlspecialchars($c['emitir_nf']) ?>"
                                    data-configurado="<?= htmlspecialchars($c['configurado']) ?>"
                                    data-observacao="<?= htmlspecialchars($c['observacao'] ?? '') ?>"
                                    onclick="openEditModal(this)">
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <a href="treinamentos_cliente.php?id_cliente=<?= $c['id_cliente'] ?>"
                                    class="btn btn-sm btn-outline-info btn-action"
                                    data-bs-toggle="tooltip"
                                    data-bs-title="Ver treinamentos">
                                    <i class="bi bi-journal-check"></i>
                                </a>

                                <?php if (empty($c['data_fim']) || $c['data_fim'] === '0000-00-00'): ?>
                                    <a href="?encerrar=<?= $c['id_cliente'] ?>"
                                        class="btn btn-sm btn-outline-warning btn-action"
                                        data-bs-toggle="tooltip"
                                        data-bs-title="Encerrar implantação"
                                        onclick="return confirm('Tem certeza que deseja encerrar a implantação deste cliente?')">
                                        <i class="bi bi-check-circle"></i>
                                    </a>
                                <?php endif; ?>

                                <button class="btn btn-sm btn-outline-danger btn-action"
                                    data-bs-toggle="tooltip"
                                    data-bs-title="Cancelar Implantação"
                                    data-id="<?= $c['id_cliente'] ?>"
                                    data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>"
                                    onclick="openCancelModal(this)">
                                    <i class="bi bi-x-circle"></i>
                                </button>

                                <a href="?delete=<?= $c['id_cliente'] ?>"
                                    class="btn btn-sm btn-outline-danger btn-action"
                                    data-bs-toggle="tooltip"
                                    data-bs-title="Excluir cliente"
                                    onclick="return confirm('Tem certeza que deseja excluir este cliente?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- VISUALIZAÇÃO EM TABELA -->
        <div class="table-container">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Cliente / Servidor</th>
                        <th>Vendedor</th>
                        <th>Status</th>
                        <th>Início</th>
                        <th>Dias</th>
                        <th>Treinamentos</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clientes_filtrados)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <div class="empty-state">
                                    <i class="bi bi-people empty-state-icon"></i>
                                    <?php if (!empty($busca)): ?>
                                        <h5 class="fw-bold mb-2">Nenhum cliente encontrado</h5>
                                        <p class="mb-3">Não foram encontrados clientes com "<?= htmlspecialchars($busca) ?>"</p>
                                        <a href="clientes.php?view=list" class="btn btn-outline-primary">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>Limpar busca
                                        </a>
                                    <?php else: ?>
                                        <h5 class="fw-bold mb-2">Nenhum cliente cadastrado</h5>
                                        <p class="mb-3">Comece cadastrando seu primeiro cliente</p>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCliente">
                                            <i class="bi bi-plus-lg me-2"></i>Cadastrar Cliente
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clientes_filtrados as $c):
                            $dias = (new DateTime($c['data_inicio']))->diff(new DateTime())->days;

                            if (empty($c['data_fim']) || $c['data_fim'] === '0000-00-00') {
                                if ($dias <= 30) {
                                    $status = 'Integração';
                                    $status_color = '#0dcaf0';
                                } elseif ($dias <= 70) {
                                    $status = 'Operacional';
                                    $status_color = '#0d6efd';
                                } elseif ($dias <= 91) {
                                    $status = 'Finalização';
                                    $status_color = '#ffc107';
                                } else {
                                    $status = 'Crítico';
                                    $status_color = '#dc3545';
                                }
                            } else {
                                $status = 'Concluído';
                                $status_color = '#06d6a0';
                            }
                        ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold"><?= htmlspecialchars($c['fantasia']) ?></div>
                                    <div class="d-flex align-items-center">
                                        <small class="text-muted"><?= htmlspecialchars($c['servidor']) ?></small>
                                        <?php if ($c['emitir_nf'] == 'Sim'): ?>
                                            <span class="badge ms-2 <?= $c['configurado'] == 'Sim' ? 'bg-success' : 'bg-warning' ?>"
                                                style="font-size: 0.6rem;"
                                                data-bs-toggle="tooltip"
                                                data-bs-title="NF: <?= $c['emitir_nf'] ?> | Config: <?= $c['configurado'] ?>">
                                                NF
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($c['vendedor']) ?></span>
                                </td>
                                <td>
                                    <span class="badge" style="background-color: <?= $status_color ?>20; color: <?= $status_color ?>; border: 1px solid <?= $status_color ?>;">
                                        <?= $status ?>
                                    </span>
                                </td>
                                <td><?= date('d/m/Y', strtotime($c['data_inicio'])) ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="fw-bold me-2"><?= $dias ?></span>
                                        <small class="text-muted">dias</small>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="fw-bold"><?= $c['total_treinamentos'] ?></span>
                                        <?php if ($c['treinamentos_pendentes'] > 0): ?>
                                            <span class="badge bg-danger ms-2" style="font-size: 0.6rem;">
                                                +<?= $c['treinamentos_pendentes'] ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="action-buttons">
                                        <button class="btn btn-sm btn-outline-primary btn-action"
                                            data-bs-toggle="tooltip"
                                            data-bs-title="Editar cliente"
                                            data-id="<?= $c['id_cliente'] ?>"
                                            data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>"
                                            data-servidor="<?= htmlspecialchars($c['servidor']) ?>"
                                            data-vendedor="<?= htmlspecialchars($c['vendedor']) ?>"
                                            data-num_licencas="<?= isset($c['num_licencas']) ? $c['num_licencas'] : 0 ?>"
                                            data-serial="<?= isset($c['serial']) ? htmlspecialchars($c['serial']) : '' ?>"
                                            data-data_inicio="<?= $c['data_inicio'] ?>"
                                            data-data_fim="<?= $c['data_fim'] ?>"
                                            data-emitir_nf="<?= htmlspecialchars($c['emitir_nf']) ?>"
                                            data-configurado="<?= htmlspecialchars($c['configurado']) ?>"
                                            data-observacao="<?= htmlspecialchars($c['observacao'] ?? '') ?>"
                                            onclick="openEditModal(this)">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <a href="treinamentos_cliente.php?id_cliente=<?= $c['id_cliente'] ?>"
                                            class="btn btn-sm btn-outline-info btn-action"
                                            data-bs-toggle="tooltip"
                                            data-bs-title="Ver treinamentos">
                                            <i class="bi bi-journal-check"></i>
                                        </a>

                                        <?php if (empty($c['data_fim']) || $c['data_fim'] === '0000-00-00'): ?>
                                            <a href="?encerrar=<?= $c['id_cliente'] ?>"
                                                class="btn btn-sm btn-outline-warning btn-action"
                                                data-bs-toggle="tooltip"
                                                data-bs-title="Encerrar implantação"
                                                onclick="return confirm('Tem certeza que deseja encerrar a implantação deste cliente?')">
                                                <i class="bi bi-check-circle"></i>
                                            </a>
                                        <?php endif; ?>

                                        <button class="btn btn-sm btn-outline-danger btn-action"
                                            data-bs-toggle="tooltip"
                                            data-bs-title="Cancelar Implantação"
                                            data-id="<?= $c['id_cliente'] ?>"
                                            data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>"
                                            onclick="openCancelModal(this)">
                                            <i class="bi bi-x-circle"></i>
                                        </button>

                                        <a href="?delete=<?= $c['id_cliente'] ?>"
                                            class="btn btn-sm btn-outline-danger btn-action"
                                            data-bs-toggle="tooltip"
                                            data-bs-title="Excluir cliente"
                                            onclick="return confirm('Tem certeza que deseja excluir este cliente?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL DE CANCELAMENTO -->
<div class="modal fade" id="modalCancelar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold text-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>Cancelar Implantação
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <div class="alert alert-warning border-warning">
                    <div class="d-flex">
                        <i class="bi bi-exclamation-octagon-fill me-3" style="font-size: 1.5rem;"></i>
                        <div>
                            <h6 class="alert-heading fw-bold">Atenção!</h6>
                            Esta ação marcará a implantação como <strong>cancelada</strong>. O cliente permanecerá no sistema mas será movido para a lista de cancelados.
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="mb-2">
                        <span class="text-muted small">Cliente:</span>
                        <div class="fw-bold" id="cancelClienteNome"></div>
                    </div>
                </div>

                <form method="GET" action="clientes.php" id="cancelForm">
                    <input type="hidden" name="cancelar" id="cancelClienteId">

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">
                            <i class="bi bi-chat-left-text me-1"></i>Motivo do Cancelamento
                            <span class="text-danger">*</span>
                        </label>
                        <select name="motivo" id="motivoCancelamento" class="form-select" required>
                            <option value="">Selecione um motivo...</option>
                            <option value="Desistência do cliente">Desistência do cliente</option>
                            <option value="Problemas financeiros">Problemas financeiros</option>
                            <option value="Incompatibilidade técnica">Incompatibilidade técnica</option>
                            <option value="Mudança de fornecedor">Mudança de fornecedor</option>
                            <option value="Problemas de comunicação">Problemas de comunicação</option>
                            <option value="Outros">Outros (especificar abaixo)</option>
                        </select>
                    </div>

                    <div class="mb-3" id="outrosMotivoDiv" style="display: none;">
                        <label class="form-label small fw-bold text-muted">Especificar motivo:</label>
                        <input type="text"
                            name="motivo_outros"
                            id="motivo_outros"
                            class="form-control"
                            placeholder="Descreva o motivo do cancelamento...">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">
                            <i class="bi bi-calendar me-1"></i>Data do Cancelamento
                        </label>
                        <input type="date"
                            name="data_cancelamento"
                            class="form-control"
                            value="<?= date('Y-m-d') ?>"
                            readonly>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">
                    <i class="bi bi-arrow-left me-2"></i>Voltar
                </button>
                <button type="button" class="btn btn-danger px-4 fw-bold shadow-sm" onclick="confirmarCancelamento()">
                    <i class="bi bi-x-circle me-2"></i>Confirmar Cancelamento
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DE CLIENTE -->
<div class="modal fade" id="modalCliente" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold" id="modalTitle">Ficha do Cliente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <input type="hidden" name="id_cliente" id="id_cliente">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-bold">Nome Fantasia</label>
                        <input type="text" name="fantasia" id="fantasia" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Servidor</label>
                        <input type="text" name="servidor" id="servidor" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Vendedor</label>
                        <input type="text" name="vendedor" id="vendedor" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Nº Licenças</label>
                        <input type="number" name="num_licencas" id="num_licencas" class="form-control" min="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Serial</label>
                        <input type="text" name="serial" id="serial" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Telefone/DDD</label>
                        <input type="text" name="telefone" id="telefone" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Data Início</label>
                        <input type="date" name="data_inicio" id="data_inicio" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Data Conclusão</label>
                        <input type="date" name="data_fim" id="id_data_fim" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold" id="label_emitir_nf">Emitir nota fiscal</label>
                        <select name="emitir_nf" id="emitir_nf" class="form-select" onchange="toggleConfigurado(this.value)">
                            <option value="Não">Não</option>
                            <option value="Sim">Sim</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="div_configurado" style="display: none;">
                        <label class="form-label small fw-bold text-muted" id="label_configurado">Configurado</label>
                        <select name="configurado" id="configurado" class="form-select">
                            <option value="Não">Não</option>
                            <option value="Sim">Sim</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Observações</label>
                        <textarea name="observacao" id="observacao" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light fw-bold px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary fw-bold shadow-sm px-4">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Inicializar tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });

        // Configurar data atual como padrão para novo cliente
        const dataInicioInput = document.getElementById('data_inicio');
        if (dataInicioInput && !dataInicioInput.value) {
            const today = new Date().toISOString().split('T')[0];
            dataInicioInput.value = today;
        }

        // Corrigir botões nos cards (proteção extra)
        const cardButtons = document.querySelectorAll('.client-card .btn-action');
        cardButtons.forEach(btn => {
            // Limpar qualquer conteúdo que não seja o ícone
            const icon = btn.querySelector('i');
            if (icon) {
                btn.innerHTML = '';
                btn.appendChild(icon.cloneNode(true));
            }
        });
    });

    // Funções de controle da view
    function changeViewMode(mode) {
        const url = new URL(window.location.href);
        url.searchParams.set('view', mode);
        window.location.href = url.toString();
    }

    function clearSearch() {
        const url = new URL(window.location.href);
        url.searchParams.delete('busca');
        window.location.href = url.toString();
    }

    // Busca com debounce
    let searchTimeout;
    const searchInput = document.querySelector('input[name="busca"]');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });
    }

    // Modal functions
    function toggleConfigurado(valor) {
        const div = document.getElementById('div_configurado');
        div.style.display = (valor === 'Sim') ? 'block' : 'none';
        updateModalVisual();
    }

    function updateModalVisual() {
        const emitirNf = document.getElementById('emitir_nf').value;
        const configurado = document.getElementById('configurado').value;
        const emitirSelect = document.getElementById('emitir_nf');
        const configSelect = document.getElementById('configurado');
        const configLabel = document.getElementById('label_configurado');
        const emitirLabel = document.getElementById('label_emitir_nf');

        // Reset classes
        emitirSelect.classList.remove('border-warning', 'border-success', 'border-2');
        configSelect.classList.remove('border-warning', 'border-success', 'border-2');
        emitirLabel.classList.remove('text-warning', 'text-success', 'fw-bold');
        configLabel.classList.remove('text-warning', 'text-success', 'fw-bold', 'text-muted');

        if (emitirNf === 'Sim') {
            emitirSelect.classList.add('border-2');
            emitirLabel.classList.add('fw-bold');

            if (configurado === 'Não') {
                emitirSelect.classList.add('border-warning');
                configSelect.classList.add('border-warning', 'border-2');
                emitirLabel.classList.add('text-warning');
                configLabel.classList.add('text-warning', 'fw-bold');
            } else if (configurado === 'Sim') {
                emitirSelect.classList.add('border-success');
                configSelect.classList.add('border-success', 'border-2');
                emitirLabel.classList.add('text-success');
                configLabel.classList.add('text-success', 'fw-bold');
            }
        } else {
            configLabel.classList.add('text-muted');
        }
    }

    function openEditModal(button) {
        document.getElementById('modalTitle').innerText = 'Editar Cliente';
        document.getElementById('id_cliente').value = button.dataset.id;
        document.getElementById('fantasia').value = button.dataset.fantasia;
        document.getElementById('servidor').value = button.dataset.servidor;
        document.getElementById('vendedor').value = button.dataset.vendedor;
        document.getElementById('num_licencas').value = button.dataset.num_licencas || 0;
        document.getElementById('serial').value = button.dataset.serial || '';
        document.getElementById('telefone').value = button.dataset.telefone || '';
        document.getElementById('data_inicio').value = button.dataset.data_inicio;
        document.getElementById('id_data_fim').value = button.dataset.data_fim || '';
        document.getElementById('observacao').value = button.dataset.observacao || '';

        const nf = button.dataset.emitir_nf || 'Não';
        const conf = button.dataset.configurado || 'Não';

        document.getElementById('emitir_nf').value = nf;
        document.getElementById('configurado').value = conf;

        toggleConfigurado(nf);
        updateModalVisual();

        // Abrir modal
        const modalEl = document.getElementById('modalCliente');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    // Reset modal ao fechar
    document.getElementById('modalCliente').addEventListener('hidden.bs.modal', function() {
        const form = this.querySelector('form');
        form.reset();
        document.getElementById('id_cliente').value = '';
        document.getElementById('modalTitle').innerText = 'Ficha do Cliente';
        document.getElementById('emitir_nf').value = 'Não';
        document.getElementById('configurado').value = 'Não';
        toggleConfigurado('Não');

        // Resetar data para hoje
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('data_inicio').value = today;
    });

    // Configurar eventos dos selects
    document.getElementById('emitir_nf').addEventListener('change', function() {
        toggleConfigurado(this.value);
    });

    document.getElementById('configurado').addEventListener('change', updateModalVisual);

    // Função para abrir modal de cancelamento
    function openCancelModal(button) {
        document.getElementById('cancelClienteId').value = button.dataset.id;
        document.getElementById('cancelClienteNome').textContent = button.dataset.fantasia;

        // Resetar formulário
        document.getElementById('motivoCancelamento').value = '';
        document.getElementById('outrosMotivoDiv').style.display = 'none';
        document.getElementById('motivo_outros').value = '';

        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('modalCancelar'));
        modal.show();
    }

    // Mostrar/ocultar campo "outros" conforme seleção
    document.getElementById('motivoCancelamento').addEventListener('change', function() {
        const outrosDiv = document.getElementById('outrosMotivoDiv');
        outrosDiv.style.display = (this.value === 'Outros') ? 'block' : 'none';
        if (this.value !== 'Outros') {
            document.getElementById('motivo_outros').value = '';
        }
    });

    // Função para confirmar cancelamento
    function confirmarCancelamento() {
        const motivoSelect = document.getElementById('motivoCancelamento');
        const motivoOutros = document.getElementById('motivo_outros');

        if (!motivoSelect.value) {
            alert('Por favor, selecione um motivo para o cancelamento.');
            motivoSelect.focus();
            return;
        }

        if (motivoSelect.value === 'Outros' && !motivoOutros.value.trim()) {
            alert('Por favor, especifique o motivo do cancelamento.');
            motivoOutros.focus();
            return;
        }

        // Montar parâmetro do motivo
        let motivoFinal = motivoSelect.value;
        if (motivoSelect.value === 'Outros' && motivoOutros.value.trim()) {
            motivoFinal = motivoOutros.value.trim();
        }

        // Adicionar motivo à URL
        const form = document.getElementById('cancelForm');
        const motivoInput = document.createElement('input');
        motivoInput.type = 'hidden';
        motivoInput.name = 'motivo';
        motivoInput.value = encodeURIComponent(motivoFinal);
        form.appendChild(motivoInput);

        // Enviar formulário
        form.submit();
    }
</script>

<?php include 'footer.php'; ?>