<?php
require_once 'config.php';

// 1. Relatório por Vendedor - MODIFICADO PARA INCLUIR CANCELADOS
$sql_vendedores = "SELECT 
                    vendedor,
                    COUNT(*) as total_clientes,
                    SUM(CASE WHEN (data_fim IS NULL OR data_fim = '0000-00-00') THEN 1 ELSE 0 END) as clientes_ativos,
                    SUM(CASE WHEN (data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao NOT LIKE '%CANCELADO%') THEN 1 ELSE 0 END) as clientes_concluidos,
                    SUM(CASE WHEN (data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao LIKE '%CANCELADO%') THEN 1 ELSE 0 END) as clientes_cancelados
                   FROM clientes 
                   WHERE vendedor IS NOT NULL AND vendedor != ''
                   GROUP BY vendedor 
                   ORDER BY total_clientes DESC";

$stmt_vendedores = $pdo->query($sql_vendedores);
$vendedores = $stmt_vendedores->fetchAll(PDO::FETCH_ASSOC);

// 2. Relatório por Servidor - MODIFICADO PARA INCLUIR CANCELADOS
$sql_servidores = "SELECT 
                    servidor,
                    COUNT(*) as total_clientes,
                    SUM(CASE WHEN (data_fim IS NULL OR data_fim = '0000-00-00') THEN 1 ELSE 0 END) as clientes_ativos,
                    SUM(CASE WHEN (data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao NOT LIKE '%CANCELADO%') THEN 1 ELSE 0 END) as clientes_concluidos,
                    SUM(CASE WHEN (data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao LIKE '%CANCELADO%') THEN 1 ELSE 0 END) as clientes_cancelados,
                    GROUP_CONCAT(DISTINCT vendedor SEPARATOR ', ') as vendedores
                   FROM clientes 
                   WHERE servidor IS NOT NULL AND servidor != ''
                   GROUP BY servidor 
                   ORDER BY total_clientes DESC";

$stmt_servidores = $pdo->query($sql_servidores);
$servidores = $stmt_servidores->fetchAll(PDO::FETCH_ASSOC);

// 3. Totais gerais - MODIFICADO PARA INCLUIR CANCELADOS
$total_clientes = $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
$total_vendedores = $pdo->query("SELECT COUNT(DISTINCT vendedor) FROM clientes WHERE vendedor IS NOT NULL AND vendedor != ''")->fetchColumn();
$total_servidores = $pdo->query("SELECT COUNT(DISTINCT servidor) FROM clientes WHERE servidor IS NOT NULL AND servidor != ''")->fetchColumn();

// NOVO: Contagem de cancelados geral
$total_cancelados = $pdo->query("SELECT COUNT(*) FROM clientes WHERE data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao LIKE '%CANCELADO%'")->fetchColumn();
$total_concluidos = $pdo->query("SELECT COUNT(*) FROM clientes WHERE data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao NOT LIKE '%CANCELADO%'")->fetchColumn();

// Calcular total ativos
$total_ativos = $total_clientes - $total_concluidos - $total_cancelados;

include 'header.php';
?>

<style>
    :root {
        --primary-color: #4361ee;
        --secondary-color: #3f37c9;
        --success-color: #10b981;
        --warning-color: #f59e0b;
        --danger-color: #ef4444;
        --info-color: #3b82f6;
        --dark-color: #495057;
    }

    .stat-card {
        border-radius: 12px;
        border: none;
        transition: transform 0.2s, box-shadow 0.2s;
        height: 100%;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
    }

    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }

    .progress-thin {
        height: 6px;
        border-radius: 3px;
    }

    .table-card {
        border-radius: 12px;
        border: none;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
    }

    .badge-pill {
        border-radius: 10px;
        padding: 4px 10px;
        font-size: 0.75rem;
    }

    .avatar-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 16px;
        color: white;
    }

    .avatar-vendedor {
        background-color: var(--primary-color);
    }

    .avatar-servidor {
        background-color: var(--secondary-color);
    }

    .chart-container {
        height: 300px;
        position: relative;
    }

    /* Estilo para tabelas */
    .table-hover tbody tr:hover {
        background-color: rgba(67, 97, 238, 0.05);
    }

    .table> :not(caption)>*>* {
        padding: 1rem 1.25rem;
    }

    /* Custom scrollbar */
    .table-responsive::-webkit-scrollbar {
        height: 6px;
    }

    .table-responsive::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .table-responsive::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 10px;
    }

    .table-responsive::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }
    
    /* Estilos para badges de status */
    .badge-ativos {
        background-color: rgba(25, 135, 84, 0.1) !important;
        color: #198754 !important;
        border: 1px solid rgba(25, 135, 84, 0.2) !important;
    }
    
    .badge-concluidos {
        background-color: rgba(13, 110, 253, 0.1) !important;
        color: #0d6efd !important;
        border: 1px solid rgba(13, 110, 253, 0.2) !important;
    }
    
    .badge-cancelados {
        background-color: rgba(220, 53, 69, 0.1) !important;
        color: #dc3545 !important;
        border: 1px solid rgba(220, 53, 69, 0.2) !important;
    }
</style>

<div class="container-fluid py-4">
    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0">Relatórios Analíticos</h4>
            <p class="text-muted mb-0">Análise detalhada por vendedor e servidor</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary" onclick="window.print()">
                <i class="bi bi-printer me-2"></i>Imprimir
            </button>
            <button class="btn btn-primary" onclick="exportToExcel()">
                <i class="bi bi-download me-2"></i>Exportar
            </button>
        </div>
    </div>

    <!-- Cards de Estatísticas - ATUALIZADO COM 6 CARDS -->
    <div class="row g-4 mb-4">
        <!-- Card Total Clientes -->
        <div class="col-xl-2 col-md-4">
            <div class="card stat-card border-left-primary border-start-4 border-start-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold">Total Clientes</h6>
                            <h2 class="fw-bold mb-0"><?= $total_clientes ?></h2>
                            <span class="text-muted small">Cadastrados</span>
                        </div>
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-people"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Card Vendedores -->
        <div class="col-xl-2 col-md-4">
            <div class="card stat-card border-left-success border-start-4 border-start-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold">Vendedores</h6>
                            <h2 class="fw-bold mb-0"><?= $total_vendedores ?></h2>
                            <span class="text-muted small">Ativos</span>
                        </div>
                        <div class="stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-person-badge"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Card Servidores -->
        <div class="col-xl-2 col-md-4">
            <div class="card stat-card border-left-warning border-start-4 border-start-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold">Servidores</h6>
                            <h2 class="fw-bold mb-0"><?= $total_servidores ?></h2>
                            <span class="text-muted small">Ativos</span>
                        </div>
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-server"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- NOVO CARD: Clientes Concluídos -->
        <div class="col-xl-2 col-md-4">
            <div class="card stat-card border-left-info border-start-4 border-start-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold">Concluídos</h6>
                            <h2 class="fw-bold mb-0"><?= $total_concluidos ?></h2>
                            <span class="text-muted small">Implantação finalizada</span>
                        </div>
                        <div class="stat-icon bg-info bg-opacity-10 text-info">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- NOVO CARD: Clientes Cancelados -->
        <div class="col-xl-2 col-md-4">
            <div class="card stat-card border-left-danger border-start-4 border-start-danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold">Cancelados</h6>
                            <h2 class="fw-bold mb-0"><?= $total_cancelados ?></h2>
                            <span class="text-muted small">Implantação cancelada</span>
                        </div>
                        <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                            <i class="bi bi-x-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Card Taxa Ativos -->
        <div class="col-xl-2 col-md-4">
            <div class="card stat-card border-left-secondary border-start-4 border-start-secondary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold">Taxa Ativos</h6>
                            <h2 class="fw-bold mb-0">
                                <?= $total_clientes > 0 ? round(($total_ativos / $total_clientes) * 100) : 0 ?>%
                            </h2>
                            <span class="text-muted small">Em implantação</span>
                        </div>
                        <div class="stat-icon bg-secondary bg-opacity-10 text-secondary">
                            <i class="bi bi-graph-up"></i>
                        </div>
                    </div>
                    <div class="progress progress-thin mt-3">
                        <div class="progress-bar bg-secondary" style="width: <?= $total_clientes > 0 ? ($total_ativos / $total_clientes) * 100 : 0 ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Conteúdo Principal -->
    <div class="row g-4">
        <!-- Relatório por Vendedor -->
        <div class="col-lg-6">
            <div class="card table-card">
                <div class="card-header bg-white border-0 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0">
                            <i class="bi bi-person-badge text-primary me-2"></i>
                            Por Vendedor
                        </h5>
                        <span class="badge bg-primary rounded-pill"><?= count($vendedores) ?> vendedores</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Vendedor</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Ativos</th>
                                    <th class="text-center">Concluídos</th>
                                    <th class="text-center">Cancelados</th>
                                    <th class="text-end pe-4">Distribuição</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($vendedores)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            <i class="bi bi-emoji-frown display-6 d-block mb-2"></i>
                                            Nenhum vendedor com clientes cadastrados
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($vendedores as $v):
                                        $percentage = $total_clientes > 0 ? ($v['total_clientes'] / $total_clientes) * 100 : 0;
                                    ?>
                                        <tr>
                                            <td class="ps-4">
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-circle avatar-vendedor me-3">
                                                        <?= substr($v['vendedor'], 0, 1) ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold"><?= htmlspecialchars($v['vendedor']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold"><?= $v['total_clientes'] ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-ativos badge-pill">
                                                    <?= $v['clientes_ativos'] ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-concluidos badge-pill">
                                                    <?= $v['clientes_concluidos'] ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-cancelados badge-pill">
                                                    <?= $v['clientes_cancelados'] ?>
                                                </span>
                                            </td>
                                            <td class="pe-4">
                                                <div class="d-flex align-items-center">
                                                    <div class="progress progress-thin flex-grow-1 me-2">
                                                        <div class="progress-bar bg-primary" style="width: <?= $percentage ?>%"></div>
                                                    </div>
                                                    <span class="text-muted small"><?= round($percentage) ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if (!empty($vendedores)): ?>
                    <div class="card-footer bg-white border-0 py-3">
                        <div class="row">
                            <div class="col-6">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Total de clientes: <?= array_sum(array_column($vendedores, 'total_clientes')) ?>
                                </small>
                            </div>
                            <div class="col-6 text-end">
                                <small class="text-muted">
                                    <i class="bi bi-clock me-1"></i>
                                    Atualizado em <?= date('d/m/Y H:i') ?>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Relatório por Servidor -->
        <div class="col-lg-6">
            <div class="card table-card">
                <div class="card-header bg-white border-0 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0">
                            <i class="bi bi-server text-success me-2"></i>
                            Por Servidor
                        </h5>
                        <span class="badge bg-success rounded-pill"><?= count($servidores) ?> servidores</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Servidor</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Ativos</th>
                                    <th class="text-center">Concluídos</th>
                                    <th class="text-center">Cancelados</th>
                                    <th class="text-end pe-4">Vendedores</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($servidores)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            <i class="bi bi-hdd-stack display-6 d-block mb-2"></i>
                                            Nenhum servidor com clientes cadastrados
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($servidores as $s): ?>
                                        <tr>
                                            <td class="ps-4">
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-circle avatar-servidor me-3">
                                                        <i class="bi bi-server"></i>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold"><?= htmlspecialchars($s['servidor']) ?></div>
                                                        <small class="text-muted"><?= $s['vendedores'] ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold"><?= $s['total_clientes'] ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-ativos badge-pill">
                                                    <?= $s['clientes_ativos'] ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-concluidos badge-pill">
                                                    <?= $s['clientes_concluidos'] ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-cancelados badge-pill">
                                                    <?= $s['clientes_cancelados'] ?>
                                                </span>
                                            </td>
                                            <td class="pe-4">
                                                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                                                    <?= count(explode(', ', $s['vendedores'])) ?> vendedor(es)
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if (!empty($servidores)): ?>
                    <div class="card-footer bg-white border-0 py-3">
                        <div class="row">
                            <div class="col-6">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Média: <?= round(array_sum(array_column($servidores, 'total_clientes')) / count($servidores), 1) ?> clientes/servidor
                                </small>
                            </div>
                            <div class="col-6 text-end">
                                <small class="text-muted">
                                    <i class="bi bi-clock me-1"></i>
                                    Atualizado em <?= date('d/m/Y H:i') ?>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Gráficos e Estatísticas Avançadas -->
    <div class="row g-4 mt-4">
        <!-- Distribuição Percentual -->
        <div class="col-lg-6">
            <div class="card table-card">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-pie-chart text-warning me-2"></i>
                        Distribuição Percentual
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Vendedores Top 5 -->
                        <div class="col-md-6">
                            <h6 class="fw-bold mb-3">Top 5 Vendedores</h6>
                            <?php
                            $top_vendedores = array_slice($vendedores, 0, 5);
                            foreach ($top_vendedores as $index => $v):
                                $percentage = $total_clientes > 0 ? ($v['total_clientes'] / $total_clientes) * 100 : 0;
                            ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="small"><?= htmlspecialchars($v['vendedor']) ?></span>
                                        <span class="small fw-bold"><?= round($percentage) ?>%</span>
                                    </div>
                                    <div class="progress progress-thin">
                                        <div class="progress-bar" style="width: <?= $percentage ?>%; background-color: var(--primary-color); opacity: <?= 1 - ($index * 0.15) ?>;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Servidores Top 5 -->
                        <div class="col-md-6">
                            <h6 class="fw-bold mb-3">Top 5 Servidores</h6>
                            <?php
                            $top_servidores = array_slice($servidores, 0, 5);
                            foreach ($top_servidores as $index => $s):
                                $percentage = $total_clientes > 0 ? ($s['total_clientes'] / $total_clientes) * 100 : 0;
                            ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="small"><?= htmlspecialchars($s['servidor']) ?></span>
                                        <span class="small fw-bold"><?= round($percentage) ?>%</span>
                                    </div>
                                    <div class="progress progress-thin">
                                        <div class="progress-bar" style="width: <?= $percentage ?>%; background-color: var(--success-color); opacity: <?= 1 - ($index * 0.15) ?>;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- NOVO: Gráfico de distribuição por status -->
                    <div class="mt-4">
                        <h6 class="fw-bold mb-3">Distribuição por Status</h6>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="d-flex align-items-center">
                                <div class="bg-success me-2" style="width: 12px; height: 12px; border-radius: 2px;"></div>
                                <span class="small">Ativos: <?= $total_ativos ?> (<?= $total_clientes > 0 ? round(($total_ativos / $total_clientes) * 100) : 0 ?>%)</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <div class="bg-info me-2" style="width: 12px; height: 12px; border-radius: 2px;"></div>
                                <span class="small">Concluídos: <?= $total_concluidos ?> (<?= $total_clientes > 0 ? round(($total_concluidos / $total_clientes) * 100) : 0 ?>%)</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <div class="bg-danger me-2" style="width: 12px; height: 12px; border-radius: 2px;"></div>
                                <span class="small">Cancelados: <?= $total_cancelados ?> (<?= $total_clientes > 0 ? round(($total_cancelados / $total_clientes) * 100) : 0 ?>%)</span>
                            </div>
                        </div>
                        <div class="progress" style="height: 20px; border-radius: 10px;">
                            <div class="progress-bar bg-success" style="width: <?= $total_clientes > 0 ? ($total_ativos / $total_clientes) * 100 : 0 ?>%"></div>
                            <div class="progress-bar bg-info" style="width: <?= $total_clientes > 0 ? ($total_concluidos / $total_clientes) * 100 : 0 ?>%"></div>
                            <div class="progress-bar bg-danger" style="width: <?= $total_clientes > 0 ? ($total_cancelados / $total_clientes) * 100 : 0 ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Estatísticas Rápidas -->
        <div class="col-lg-6">
            <div class="card table-card">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-speedometer2 text-danger me-2"></i>
                        Estatísticas Rápidas
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-primary bg-opacity-10 rounded p-2 me-3">
                                    <i class="bi bi-trophy text-primary"></i>
                                </div>
                                <div>
                                    <div class="small text-muted">Vendedor Top</div>
                                    <div class="fw-bold">
                                        <?= !empty($vendedores) ? htmlspecialchars($vendedores[0]['vendedor']) : 'N/A' ?>
                                        <span class="text-muted small">(<?= !empty($vendedores) ? $vendedores[0]['total_clientes'] : '0' ?> clientes)</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-success bg-opacity-10 rounded p-2 me-3">
                                    <i class="bi bi-hdd-rack text-success"></i>
                                </div>
                                <div>
                                    <div class="small text-muted">Servidor Top</div>
                                    <div class="fw-bold">
                                        <?= !empty($servidores) ? htmlspecialchars($servidores[0]['servidor']) : 'N/A' ?>
                                        <span class="text-muted small">(<?= !empty($servidores) ? $servidores[0]['total_clientes'] : '0' ?> clientes)</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-info bg-opacity-10 rounded p-2 me-3">
                                    <i class="bi bi-lightning-charge text-info"></i>
                                </div>
                                <div>
                                    <div class="small text-muted">Maior Taxa Ativos</div>
                                    <div class="fw-bold">
                                        <?php
                                        $maior_taxa = 0;
                                        $vendedor_maior_taxa = '';
                                        foreach ($vendedores as $v) {
                                            $taxa = $v['total_clientes'] > 0 ? ($v['clientes_ativos'] / $v['total_clientes']) * 100 : 0;
                                            if ($taxa > $maior_taxa) {
                                                $maior_taxa = $taxa;
                                                $vendedor_maior_taxa = $v['vendedor'];
                                            }
                                        }
                                        echo htmlspecialchars($vendedor_maior_taxa ?: 'N/A');
                                        ?>
                                        <span class="text-muted small">(<?= round($maior_taxa) ?>%)</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-warning bg-opacity-10 rounded p-2 me-3">
                                    <i class="bi bi-check-circle text-warning"></i>
                                </div>
                                <div>
                                    <div class="small text-muted">Maior Taxa Concluídos</div>
                                    <div class="fw-bold">
                                        <?php
                                        $maior_concluidos = 0;
                                        $vendedor_maior_concluidos = '';
                                        foreach ($vendedores as $v) {
                                            $taxa = $v['total_clientes'] > 0 ? ($v['clientes_concluidos'] / $v['total_clientes']) * 100 : 0;
                                            if ($taxa > $maior_concluidos) {
                                                $maior_concluidos = $taxa;
                                                $vendedor_maior_concluidos = $v['vendedor'];
                                            }
                                        }
                                        echo htmlspecialchars($vendedor_maior_concluidos ?: 'N/A');
                                        ?>
                                        <span class="text-muted small">(<?= round($maior_concluidos) ?>%)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- NOVO: Estatística para maior taxa de cancelados -->
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-danger bg-opacity-10 rounded p-2 me-3">
                                    <i class="bi bi-exclamation-octagon text-danger"></i>
                                </div>
                                <div>
                                    <div class="small text-muted">Maior Taxa Cancelados</div>
                                    <div class="fw-bold">
                                        <?php
                                        $maior_cancelados = 0;
                                        $vendedor_maior_cancelados = '';
                                        foreach ($vendedores as $v) {
                                            $taxa = $v['total_clientes'] > 0 ? ($v['clientes_cancelados'] / $v['total_clientes']) * 100 : 0;
                                            if ($taxa > $maior_cancelados) {
                                                $maior_cancelados = $taxa;
                                                $vendedor_maior_cancelados = $v['vendedor'];
                                            }
                                        }
                                        echo htmlspecialchars($vendedor_maior_cancelados ?: 'N/A');
                                        ?>
                                        <span class="text-muted small">(<?= round($maior_cancelados) ?>%)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- NOVO: Taxa de sucesso (concluídos vs cancelados) -->
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-success bg-opacity-10 rounded p-2 me-3">
                                    <i class="bi bi-graph-up-arrow text-success"></i>
                                </div>
                                <div>
                                    <div class="small text-muted">Taxa de Sucesso</div>
                                    <div class="fw-bold">
                                        <?php
                                        $total_finalizados = $total_concluidos + $total_cancelados;
                                        $taxa_sucesso = $total_finalizados > 0 ? ($total_concluidos / $total_finalizados) * 100 : 0;
                                        echo round($taxa_sucesso) . '%';
                                        ?>
                                    </div>
                                    <span class="text-muted small">
                                        <?= $total_concluidos ?> de <?= $total_finalizados ?> finalizados
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Função para exportar para Excel - ATUALIZADA COM CANCELADOS
    function exportToExcel() {
        // Cria uma tabela temporária com os dados
        let html = '<table border="1">';

        // Adiciona dados dos vendedores
        html += '<tr><th colspan="6">RELATÓRIO POR VENDEDOR</th></tr>';
        html += '<tr><th>Vendedor</th><th>Total Clientes</th><th>Ativos</th><th>Concluídos</th><th>Cancelados</th><th>%</th></tr>';

        <?php foreach ($vendedores as $v): ?>
            html += '<tr>';
            html += '<td><?= addslashes($v['vendedor']) ?></td>';
            html += '<td><?= $v['total_clientes'] ?></td>';
            html += '<td><?= $v['clientes_ativos'] ?></td>';
            html += '<td><?= $v['clientes_concluidos'] ?></td>';
            html += '<td><?= $v['clientes_cancelados'] ?></td>';
            html += '<td><?= $total_clientes > 0 ? round(($v['total_clientes'] / $total_clientes) * 100) : 0 ?>%</td>';
            html += '</tr>';
        <?php endforeach; ?>

        // Adiciona dados dos servidores
        html += '<tr><th colspan="6"><br>RELATÓRIO POR SERVIDOR</th></tr>';
        html += '<tr><th>Servidor</th><th>Total Clientes</th><th>Ativos</th><th>Concluídos</th><th>Cancelados</th><th>Vendedores</th></tr>';

        <?php foreach ($servidores as $s): ?>
            html += '<tr>';
            html += '<td><?= addslashes($s['servidor']) ?></td>';
            html += '<td><?= $s['total_clientes'] ?></td>';
            html += '<td><?= $s['clientes_ativos'] ?></td>';
            html += '<td><?= $s['clientes_concluidos'] ?></td>';
            html += '<td><?= $s['clientes_cancelados'] ?></td>';
            html += '<td><?= $s['vendedores'] ?></td>';
            html += '</tr>';
        <?php endforeach; ?>

        // Adiciona resumo geral
        html += '<tr><th colspan="6"><br>RESUMO GERAL</th></tr>';
        html += '<tr><th>Total Clientes</th><th>Ativos</th><th>Concluídos</th><th>Cancelados</th><th>Taxa Ativos</th><th>Taxa Sucesso</th></tr>';
        html += '<tr>';
        html += '<td><?= $total_clientes ?></td>';
        html += '<td><?= $total_ativos ?></td>';
        html += '<td><?= $total_concluidos ?></td>';
        html += '<td><?= $total_cancelados ?></td>';
        html += '<td><?= $total_clientes > 0 ? round(($total_ativos / $total_clientes) * 100) : 0 ?>%</td>';
        html += '<td><?= ($total_concluidos + $total_cancelados) > 0 ? round(($total_concluidos / ($total_concluidos + $total_cancelados)) * 100) : 0 ?>%</td>';
        html += '</tr>';

        html += '</table>';

        // Cria um blob e faz download
        const blob = new Blob([html], {
            type: 'application/vnd.ms-excel'
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'relatorio_clientes_<?= date('Y-m-d') ?>.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // Tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
</script>

<?php include 'footer.php'; ?>