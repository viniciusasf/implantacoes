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

// 3. Totais gerais
$total_clientes = $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
$total_vendedores = $pdo->query("SELECT COUNT(DISTINCT vendedor) FROM clientes WHERE vendedor IS NOT NULL AND vendedor != ''")->fetchColumn();
$total_servidores = $pdo->query("SELECT COUNT(DISTINCT servidor) FROM clientes WHERE servidor IS NOT NULL AND servidor != ''")->fetchColumn();

// Contagem de cancelados geral
$total_cancelados = $pdo->query("SELECT COUNT(*) FROM clientes WHERE data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao LIKE '%CANCELADO%'")->fetchColumn();
$total_concluidos = $pdo->query("SELECT COUNT(*) FROM clientes WHERE data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao NOT LIKE '%CANCELADO%'")->fetchColumn();

// Calcular total ativos
$total_ativos = $total_clientes - $total_concluidos - $total_cancelados;
$total_clientes_com_concluidos = $total_ativos + $total_concluidos;

include 'header.php';
?>

<!-- // custom dashboard styles -->
<style>
    /* // hero section e header da página */
    .page-header {
        margin-bottom: 2rem;
    }
    
    .page-title {
        font-family: var(--font-heading);
        font-size: 1.75rem;
        font-weight: 700;
        letter-spacing: -0.5px;
        margin-bottom: 0.25rem;
    }

    /* // cards de estatísticas (benefícios/destaques) */
    .stat-card {
        border-radius: var(--radius-lg);
        border: none;
        transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1), box-shadow 0.3s;
        height: 100%;
        background: var(--bg-card);
        box-shadow: var(--shadow-sm);
        position: relative;
        overflow: hidden;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; width: 4px; height: 100%;
        background: var(--primary);
        border-radius: var(--radius-lg) 0 0 var(--radius-lg);
    }
    .stat-card.border-primary::before { background: var(--primary); }
    .stat-card.border-success::before { background: var(--success); }
    .stat-card.border-warning::before { background: var(--warning); }
    .stat-card.border-info::before { background: var(--info); }
    .stat-card.border-danger::before { background: var(--danger); }
    .stat-card.border-dark::before { background: var(--text-dark); }

    .stat-icon {
        width: 56px;
        height: 56px;
        border-radius: var(--radius-md);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        transition: all 0.3s ease;
    }
    
    .stat-card:hover .stat-icon {
        transform: scale(1.1) rotate(5deg);
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 800;
        font-family: var(--font-heading);
        line-height: 1.2;
        color: var(--text-dark);
    }

    /* // tabelas modernas */
    .table-container {
        border-radius: var(--radius-lg);
        background: var(--bg-card);
        padding: 1.5rem;
        box-shadow: var(--shadow-card);
        height: 100%;
        overflow: hidden;
    }
    
    .table-responsive {
        max-height: 480px;
        overflow-y: auto;
        padding-right: 0.5rem;
    }

    .table {
        margin-bottom: 0;
    }
    
    .table thead th {
        position: sticky;
        top: 0;
        z-index: 10;
        background-color: var(--bg-card);
        border-bottom: 2px solid #f1f5f9;
        color: var(--text-muted);
        font-size: 0.75rem;
        padding: 1.2rem 1rem;
    }

    .table tbody tr {
        transition: background-color 0.2s;
    }
    
    .table tbody tr:hover {
        background-color: #f8fafc;
    }
    
    .table tbody td {
        padding: 1.2rem 1rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    /* // design system badges & avatares */
    .badge-soft {
        padding: 0.4em 0.8em;
        border-radius: var(--radius-sm);
        font-weight: 600;
        font-size: 0.75rem;
        font-family: var(--font-body);
    }
    
    .badge-soft-success { background: var(--success-light); color: #059669; }
    .badge-soft-info { background: var(--info-light); color: #0284c7; }
    .badge-soft-danger { background: var(--danger-light); color: #dc2626; }

    .avatar-circle {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1.1rem;
        color: white;
        box-shadow: var(--shadow-sm);
    }

    .avatar-vendedor { background: linear-gradient(135deg, var(--primary), #818cf8); }
    .avatar-servidor { background: linear-gradient(135deg, var(--success), #34d399); }

    /* // progress bars modernas */
    .progress-thin {
        height: 8px;
        border-radius: 10px;
        background-color: #f1f5f9;
        overflow: hidden;
    }
    
    .progress-thin .progress-bar {
        border-radius: 10px;
        background: linear-gradient(90deg, var(--primary), #818cf8);
    }

    /* // quick stats cards */
    .quick-stat {
        background: #f8fafc;
        border-radius: var(--radius-md);
        padding: 1.2rem;
        transition: all 0.2s;
        border: 1px solid transparent;
    }
    
    .quick-stat:hover {
        background: #fff;
        border-color: #e2e8f0;
        box-shadow: var(--shadow-sm);
        transform: translateY(-2px);
    }

    .quick-stat-icon {
        width: 40px;
        height: 40px;
        border-radius: var(--radius-sm);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }

    /* custom scrollbar for tables */
    .table-responsive::-webkit-scrollbar { width: 6px; }
    .table-responsive::-webkit-scrollbar-track { background: transparent; }
    .table-responsive::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    .table-responsive::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>

<div class="container-fluid">
    <!-- // page header -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h2 class="page-title">Dashboard Operacional</h2>
            <p class="text-muted mb-0" style="font-weight: 500;">Visão geral de implantações, vendedores e servidores.</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary shadow-sm" onclick="window.print()">
                <i class="bi bi-printer"></i> Imprimir
            </button>
            <button class="btn btn-primary" onclick="exportToExcel()">
                <i class="bi bi-download"></i> Exportar
            </button>
        </div>
    </div>

    <!-- // hero metrics: Cards de Estatísticas Principais -->
    <div class="row g-4 mb-5">
        <!-- Total com Concluídos -->
        <div class="col-xl-2 col-lg-4 col-md-6">
            <div class="card stat-card border-dark">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold mb-2" style="letter-spacing: 0.5px;">Efetivados</h6>
                            <h2 class="stat-value mb-1"><?= $total_clientes_com_concluidos ?></h2>
                            <span class="text-muted" style="font-size: 0.75rem; font-weight: 500;">Ativos + Concluídos</span>
                        </div>
                        <div class="stat-icon" style="background: rgba(27, 37, 89, 0.05); color: var(--text-dark);">
                            <i class="bi bi-briefcase-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Clientes Ativos -->
        <div class="col-xl-2 col-lg-4 col-md-6">
            <div class="card stat-card border-primary">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold mb-2" style="letter-spacing: 0.5px;">Ativos</h6>
                            <h2 class="stat-value mb-1 text-primary"><?= $total_clientes ?></h2>
                            <span class="text-muted" style="font-size: 0.75rem; font-weight: 500;">Em Implantação</span>
                        </div>
                        <div class="stat-icon" style="background: var(--primary-light); color: var(--primary);">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vendedores -->
        <div class="col-xl-2 col-lg-4 col-md-6">
            <div class="card stat-card border-warning">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold mb-2" style="letter-spacing: 0.5px;">Vendedores</h6>
                            <h2 class="stat-value mb-1" style="color: var(--warning);"><?= $total_vendedores ?></h2>
                            <span class="text-muted" style="font-size: 0.75rem; font-weight: 500;">Ativos</span>
                        </div>
                        <div class="stat-icon" style="background: var(--warning-light); color: var(--warning);">
                            <i class="bi bi-person-badge-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Servidores -->
        <div class="col-xl-2 col-lg-4 col-md-6">
            <div class="card stat-card border-purple" style="--purple: #8b5cf6;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold mb-2" style="letter-spacing: 0.5px;">Servidores</h6>
                            <h2 class="stat-value mb-1" style="color: #8b5cf6;"><?= $total_servidores ?></h2>
                            <span class="text-muted" style="font-size: 0.75rem; font-weight: 500;">Em uso</span>
                        </div>
                        <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                            <i class="bi bi-server"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Concluídos -->
        <div class="col-xl-2 col-lg-4 col-md-6">
            <div class="card stat-card border-success">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold mb-2" style="letter-spacing: 0.5px;">Concluídos</h6>
                            <h2 class="stat-value mb-1 text-success"><?= $total_concluidos ?></h2>
                            <span class="text-muted" style="font-size: 0.75rem; font-weight: 500;">Finalizados</span>
                        </div>
                        <div class="stat-icon" style="background: var(--success-light); color: var(--success);">
                            <i class="bi bi-check-circle-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cancelados -->
        <div class="col-xl-2 col-lg-4 col-md-6">
            <div class="card stat-card border-danger">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold mb-2" style="letter-spacing: 0.5px;">Cancelados</h6>
                            <h2 class="stat-value mb-1 text-danger"><?= $total_cancelados ?></h2>
                            <span class="text-muted" style="font-size: 0.75rem; font-weight: 500;">Encerrados s/ sucesso</span>
                        </div>
                        <div class="stat-icon" style="background: var(--danger-light); color: var(--danger);">
                            <i class="bi bi-x-circle-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- // section: overview data -->
    <div class="row g-4 mb-4">
        
        <!-- // tabela vendedores -->
        <div class="col-lg-7">
            <div class="table-container d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0 text-dark d-flex align-items-center">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                            <i class="bi bi-award-fill"></i>
                        </div>
                        Performance por Vendedor
                    </h5>
                    <span class="badge" style="background: #f1f5f9; color: var(--text-dark); padding: 0.5rem 1rem; border-radius: 20px; font-weight: 600;">
                        <?= count($vendedores) ?> Vendedores
                    </span>
                </div>
                
                <div class="table-responsive flex-grow-1">
                    <table class="table table-borderless table-hover">
                        <thead>
                            <tr>
                                <th class="ps-2">Vendedor</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Ativos</th>
                                <th class="text-center">Concluídos</th>
                                <th class="text-center">Cancelados</th>
                                <th class="text-end pe-2">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($vendedores)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <div style="background: #f8fafc; width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem;">
                                            <i class="bi bi-emoji-frown" style="font-size: 1.5rem;"></i>
                                        </div>
                                        <span style="font-weight: 500;">Nenhum vendedor encontrado.</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($vendedores as $v):
                                    $percentage = $total_clientes > 0 ? ($v['total_clientes'] / $total_clientes) * 100 : 0;
                                ?>
                                    <tr>
                                        <td class="ps-2">
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-circle avatar-vendedor me-3">
                                                    <?= substr($v['vendedor'], 0, 1) ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark" style="font-size: 0.95rem;"><?= htmlspecialchars($v['vendedor']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center align-middle">
                                            <span class="fw-bold text-dark"><?= $v['total_clientes'] ?></span>
                                        </td>
                                        <td class="text-center align-middle">
                                            <span class="badge-soft badge-soft-info"><?= $v['clientes_ativos'] ?></span>
                                        </td>
                                        <td class="text-center align-middle">
                                            <span class="badge-soft badge-soft-success"><?= $v['clientes_concluidos'] ?></span>
                                        </td>
                                        <td class="text-center align-middle">
                                            <span class="badge-soft badge-soft-danger"><?= $v['clientes_cancelados'] ?></span>
                                        </td>
                                        <td class="pe-2 align-middle">
                                            <div class="d-flex align-items-center justify-content-end">
                                                <div class="progress-thin flex-grow-1 me-3" style="width: 80px; background: #e2e8f0;">
                                                    <div class="progress-bar" style="width: <?= $percentage ?>%"></div>
                                                </div>
                                                <span class="text-dark fw-bold" style="font-size: 0.85rem; width: 35px; text-align: right;"><?= round($percentage) ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- // estatísticas rápidas e destaques -->
        <div class="col-lg-5">
            <div class="table-container">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0 text-dark d-flex align-items-center">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: var(--danger-light); color: var(--danger); display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                            <i class="bi bi-lightning-charge-fill"></i>
                        </div>
                        Insights Rápidos
                    </h5>
                </div>
                
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="quick-stat">
                            <div class="d-flex align-items-center mb-2">
                                <div class="quick-stat-icon" style="background: var(--primary-light); color: var(--primary);">
                                    <i class="bi bi-trophy-fill"></i>
                                </div>
                                <div class="ms-3">
                                    <div class="small text-muted fw-bold text-uppercase" style="font-size: 0.65rem; letter-spacing: 0.5px;">Vendedor Top</div>
                                    <div class="fw-bold text-dark" style="font-size: 0.95rem; line-height: 1.2;">
                                        <?= !empty($vendedores) ? htmlspecialchars($vendedores[0]['vendedor']) : 'N/A' ?>
                                    </div>
                                </div>
                            </div>
                            <small class="text-muted d-block text-end fw-medium"><?= !empty($vendedores) ? $vendedores[0]['total_clientes'] : '0' ?> clientes</small>
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <div class="quick-stat">
                            <div class="d-flex align-items-center mb-2">
                                <div class="quick-stat-icon" style="background: var(--success-light); color: var(--success);">
                                    <i class="bi bi-hdd-network-fill"></i>
                                </div>
                                <div class="ms-3">
                                    <div class="small text-muted fw-bold text-uppercase" style="font-size: 0.65rem; letter-spacing: 0.5px;">Servidor Top</div>
                                    <div class="fw-bold text-dark" style="font-size: 0.95rem; line-height: 1.2;">
                                        <?= !empty($servidores) ? htmlspecialchars($servidores[0]['servidor']) : 'N/A' ?>
                                    </div>
                                </div>
                            </div>
                            <small class="text-muted d-block text-end fw-medium"><?= !empty($servidores) ? $servidores[0]['total_clientes'] : '0' ?> clientes</small>
                        </div>
                    </div>

                    <?php
                        $maior_taxa = 0; $vendedor_maior_taxa = '';
                        $maior_concluidos = 0; $vendedor_maior_concluidos = '';
                        $maior_cancelados = 0; $vendedor_maior_cancelados = '';
                        foreach ($vendedores as $v) {
                            $taxa_a = $v['total_clientes'] > 0 ? ($v['clientes_ativos'] / $v['total_clientes']) * 100 : 0;
                            if ($taxa_a > $maior_taxa) { $maior_taxa = $taxa_a; $vendedor_maior_taxa = $v['vendedor']; }
                            
                            $taxa_c = $v['total_clientes'] > 0 ? ($v['clientes_concluidos'] / $v['total_clientes']) * 100 : 0;
                            if ($taxa_c > $maior_concluidos) { $maior_concluidos = $taxa_c; $vendedor_maior_concluidos = $v['vendedor']; }
                            
                            $taxa_x = $v['total_clientes'] > 0 ? ($v['clientes_cancelados'] / $v['total_clientes']) * 100 : 0;
                            if ($taxa_x > $maior_cancelados) { $maior_cancelados = $taxa_x; $vendedor_maior_cancelados = $v['vendedor']; }
                        }
                    ?>
                    
                    <div class="col-sm-6">
                        <div class="quick-stat">
                            <div class="d-flex align-items-center mb-2">
                                <div class="quick-stat-icon" style="background: var(--info-light); color: var(--info);">
                                    <i class="bi bi-graph-up-arrow"></i>
                                </div>
                                <div class="ms-3">
                                    <div class="small text-muted fw-bold text-uppercase" style="font-size: 0.65rem; letter-spacing: 0.5px;">Maior Retenção</div>
                                    <div class="fw-bold text-dark" style="font-size: 0.95rem; line-height: 1.2;">
                                        <?= htmlspecialchars($vendedor_maior_taxa ?: 'N/A') ?>
                                    </div>
                                </div>
                            </div>
                            <small class="text-muted d-block text-end fw-medium"><?= round($maior_taxa) ?>% ativos</small>
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <div class="quick-stat">
                            <div class="d-flex align-items-center mb-2">
                                <div class="quick-stat-icon" style="background: var(--warning-light); color: var(--warning);">
                                    <i class="bi bi-check2-all"></i>
                                </div>
                                <div class="ms-3">
                                    <div class="small text-muted fw-bold text-uppercase" style="font-size: 0.65rem; letter-spacing: 0.5px;">Mais Entregas</div>
                                    <div class="fw-bold text-dark" style="font-size: 0.95rem; line-height: 1.2;">
                                        <?= htmlspecialchars($vendedor_maior_concluidos ?: 'N/A') ?>
                                    </div>
                                </div>
                            </div>
                            <small class="text-muted d-block text-end fw-medium"><?= round($maior_concluidos) ?>% concluídos</small>
                        </div>
                    </div>
                </div>

                <!-- status distribution -->
                <div class="mt-4 pt-4 border-top" style="border-color: #f1f5f9 !important;">
                    <h6 class="fw-bold text-dark mb-4">Taxa de Conclusão Global</h6>
                    <?php
                        $total_finalizados = $total_concluidos + $total_cancelados;
                        $taxa_sucesso = $total_finalizados > 0 ? ($total_concluidos / $total_finalizados) * 100 : 0;
                    ?>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted fw-medium" style="font-size: 0.9rem;">Sucesso Implantações</span>
                        <span class="badge bg-success" style="border-radius: 20px; font-size: 0.85rem; padding: 0.4em 0.8em;"><?= round($taxa_sucesso) ?>%</span>
                    </div>
                    <div class="progress-thin mb-2" style="height: 12px;">
                        <div class="progress-bar bg-success" style="width: <?= $taxa_sucesso ?>%"></div>
                        <div class="progress-bar bg-danger" style="width: <?= 100 - $taxa_sucesso ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between text-muted" style="font-size: 0.75rem;">
                        <span><i class="bi bi-circle-fill text-success" style="font-size: 0.5rem; vertical-align: middle;"></i> <?= $total_concluidos ?> Sucesso</span>
                        <span><i class="bi bi-circle-fill text-danger" style="font-size: 0.5rem; vertical-align: middle;"></i> <?= $total_cancelados ?> Falhas</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- // section: second row data -->
    <div class="row g-4 mb-5">
        <!-- // tabela servidores -->
        <div class="col-lg-6">
            <div class="table-container d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0 text-dark d-flex align-items-center">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: var(--success-light); color: var(--success); display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                            <i class="bi bi-hdd-fill"></i>
                        </div>
                        Uso por Servidor
                    </h5>
                </div>
                
                <div class="table-responsive flex-grow-1">
                    <table class="table table-borderless table-hover">
                        <thead>
                            <tr>
                                <th class="ps-2">Servidor</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Ativos</th>
                                <th class="text-center">Vendedores</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($servidores)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-5 text-muted">Ainda não há dados de servidores.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($servidores as $s): ?>
                                    <tr>
                                        <td class="ps-2">
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-circle avatar-servidor me-3" style="width: 38px; height: 38px; font-size: 0.9rem;">
                                                    <i class="bi bi-hdd"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark" style="font-size: 0.95rem;"><?= htmlspecialchars($s['servidor']) ?></div>
                                                    <div class="text-muted" style="font-size: 0.7rem; max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= $s['vendedores'] ?>">
                                                        <?= $s['vendedores'] ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center align-middle fw-bold"><?= $s['total_clientes'] ?></td>
                                        <td class="text-center align-middle">
                                            <span class="badge-soft badge-soft-info"><?= $s['clientes_ativos'] ?></span>
                                        </td>
                                        <td class="text-center align-middle">
                                            <span class="badge bg-light text-dark shadow-sm border" style="padding: 0.4em 0.8em; border-radius: 20px;">
                                                <i class="bi bi-people me-1 text-primary"></i> <?= count(explode(', ', $s['vendedores'])) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- // distribuição geral de status -->
        <div class="col-lg-6">
            <div class="table-container" style="background: linear-gradient(180deg, var(--bg-card) 0%, #f8fafc 100%);">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0 text-dark d-flex align-items-center">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(139, 92, 246, 0.1); color: var(--purple); display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                            <i class="bi bi-pie-chart-fill"></i>
                        </div>
                        Composição da Base
                    </h5>
                </div>
                
                <div class="row g-3 mb-5 mt-2">
                    <div class="col-4">
                        <div class="card border-0 shadow-sm" style="border-radius: var(--radius-md);">
                            <div class="card-body text-center p-3">
                                <div class="text-muted fw-bold text-uppercase mb-2" style="font-size: 0.65rem; letter-spacing: 0.5px;">Ativos</div>
                                <div class="fw-black h2 mb-0 text-primary" style="font-family: var(--font-heading);"><?= $total_ativos ?></div>
                                <span class="badge bg-primary bg-opacity-10 text-primary mt-2" style="border-radius: 20px;">
                                    <?= $total_clientes > 0 ? round(($total_ativos / $total_clientes) * 100) : 0 ?>%
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card border-0 shadow-sm" style="border-radius: var(--radius-md);">
                            <div class="card-body text-center p-3">
                                <div class="text-muted fw-bold text-uppercase mb-2" style="font-size: 0.65rem; letter-spacing: 0.5px;">Concluídos</div>
                                <div class="fw-black h2 mb-0 text-success" style="font-family: var(--font-heading);"><?= $total_concluidos ?></div>
                                <span class="badge bg-success bg-opacity-10 text-success mt-2" style="border-radius: 20px;">
                                    <?= $total_clientes > 0 ? round(($total_concluidos / $total_clientes) * 100) : 0 ?>%
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card border-0 shadow-sm" style="border-radius: var(--radius-md);">
                            <div class="card-body text-center p-3">
                                <div class="text-muted fw-bold text-uppercase mb-2" style="font-size: 0.65rem; letter-spacing: 0.5px;">Cancelados</div>
                                <div class="fw-black h2 mb-0 text-danger" style="font-family: var(--font-heading);"><?= $total_cancelados ?></div>
                                <span class="badge bg-danger bg-opacity-10 text-danger mt-2" style="border-radius: 20px;">
                                    <?= $total_clientes > 0 ? round(($total_cancelados / $total_clientes) * 100) : 0 ?>%
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="px-2">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="fw-bold text-dark" style="font-size: 0.9rem;">Distribuição Visual</span>
                        <span class="badge bg-dark text-white rounded-pill px-3"><?= $total_clientes ?> Total</span>
                    </div>
                    <div class="progress" style="height: 24px; border-radius: 12px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);">
                        <div class="progress-bar bg-primary"
                            style="width: <?= $total_clientes > 0 ? ($total_ativos / $total_clientes) * 100 : 0 ?>%"
                            data-bs-toggle="tooltip"
                            title="Ativos: <?= $total_ativos ?> clientes">
                            <?= $total_clientes > 0 && ($total_ativos / $total_clientes) > 0.1 ? round(($total_ativos / $total_clientes) * 100) . '%' : '' ?>
                        </div>
                        <div class="progress-bar bg-success"
                            style="width: <?= $total_clientes > 0 ? ($total_concluidos / $total_clientes) * 100 : 0 ?>%"
                            data-bs-toggle="tooltip"
                            title="Concluídos: <?= $total_concluidos ?> clientes">
                            <?= $total_clientes > 0 && ($total_concluidos / $total_clientes) > 0.1 ? round(($total_concluidos / $total_clientes) * 100) . '%' : '' ?>
                        </div>
                        <div class="progress-bar bg-danger"
                            style="width: <?= $total_clientes > 0 ? ($total_cancelados / $total_clientes) * 100 : 0 ?>%"
                            data-bs-toggle="tooltip"
                            title="Cancelados: <?= $total_cancelados ?> clientes">
                            <?= $total_clientes > 0 && ($total_cancelados / $total_clientes) > 0.1 ? round(($total_cancelados / $total_clientes) * 100) . '%' : '' ?>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-center flex-wrap gap-4 mt-4">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-circle-fill text-primary me-2" style="font-size: 0.6rem;"></i>
                            <span class="text-muted fw-medium" style="font-size: 0.85rem;">Em andamento</span>
                        </div>
                        <div class="d-flex align-items-center">
                            <i class="bi bi-circle-fill text-success me-2" style="font-size: 0.6rem;"></i>
                            <span class="text-muted fw-medium" style="font-size: 0.85rem;">Sucesso</span>
                        </div>
                        <div class="d-flex align-items-center">
                            <i class="bi bi-circle-fill text-danger me-2" style="font-size: 0.6rem;"></i>
                            <span class="text-muted fw-medium" style="font-size: 0.85rem;">Interrompido</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // funcionalidade de exportaçao de XLS mantida e aperfeiçoada
    function exportToExcel() {
        let html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
        html += '<head><meta charset="utf-8"></head><body>';
        html += '<table border="1">';
        html += '<tr><th colspan="6" style="background:#4361ee; color:#fff; font-size:16px;">RELATÓRIO POR VENDEDOR</th></tr>';
        html += '<tr><th style="background:#f1f5f9">Vendedor</th><th style="background:#f1f5f9">Total Clientes</th><th style="background:#f1f5f9">Ativos</th><th style="background:#f1f5f9">Concluídos</th><th style="background:#f1f5f9">Cancelados</th><th style="background:#f1f5f9">% Share</th></tr>';

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

        html += '<tr><td colspan="6"></td></tr>';
        html += '<tr><th colspan="6" style="background:#10b981; color:#fff; font-size:16px;">RELATÓRIO POR SERVIDOR</th></tr>';
        html += '<tr><th style="background:#f1f5f9">Servidor</th><th style="background:#f1f5f9">Total Clientes</th><th style="background:#f1f5f9">Ativos</th><th style="background:#f1f5f9">Concluídos</th><th style="background:#f1f5f9">Cancelados</th><th style="background:#f1f5f9">Vendedores (#)</th></tr>';

        <?php foreach ($servidores as $s): ?>
            html += '<tr>';
            html += '<td><?= addslashes($s['servidor']) ?></td>';
            html += '<td><?= $s['total_clientes'] ?></td>';
            html += '<td><?= $s['clientes_ativos'] ?></td>';
            html += '<td><?= $s['clientes_concluidos'] ?></td>';
            html += '<td><?= $s['clientes_cancelados'] ?></td>';
            html += '<td><?= addslashes($s['vendedores']) ?></td>';
            html += '</tr>';
        <?php endforeach; ?>

        html += '<tr><td colspan="6"></td></tr>';
        html += '<tr><th colspan="6" style="background:#8b5cf6; color:#fff; font-size:16px;">RESUMO GERAL</th></tr>';
        html += '<tr><th style="background:#f1f5f9">Total Clientes</th><th style="background:#f1f5f9">Ativos</th><th style="background:#f1f5f9">Concluídos</th><th style="background:#f1f5f9">Cancelados</th><th style="background:#f1f5f9">Taxa Ativos</th><th style="background:#f1f5f9">Taxa Sucesso Global</th></tr>';
        html += '<tr>';
        html += '<td><?= $total_clientes ?></td>';
        html += '<td><?= $total_ativos ?></td>';
        html += '<td><?= $total_concluidos ?></td>';
        html += '<td><?= $total_cancelados ?></td>';
        html += '<td><?= $total_clientes > 0 ? round(($total_ativos / $total_clientes) * 100) : 0 ?>%</td>';
        html += '<td><?= ($total_concluidos + $total_cancelados) > 0 ? round(($total_concluidos / ($total_concluidos + $total_cancelados)) * 100) : 0 ?>%</td>';
        html += '</tr>';
        html += '</table></body></html>';

        const blob = new Blob([html], {
            type: 'application/vnd.ms-excel'
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'dashboard_operacional_<?= date('Y-m-d') ?>.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // init tooltips
    document.addEventListener('DOMContentLoaded', function() {
        if(typeof bootstrap !== 'undefined') {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        }
    });
</script>

<?php include 'footer.php'; ?>