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
/* // Design System Clean & Modern (Perplexity Style) */
:root {
    --glass-bg: rgba(255, 255, 255, 0.03);
    --glass-border: rgba(255, 255, 255, 0.08);
}

[data-theme="dark"] {
    --bg-body: #0d0e12;
    --bg-card: #14151a;
    --border-color: #2b2e35;
    --text-main: #e2e8f0;
    --text-muted: #94a3b8;
    --primary-light: rgba(67, 97, 238, 0.15);
    --success-light: rgba(16, 185, 129, 0.15);
    --warning-light: rgba(245, 158, 11, 0.15);
    --danger-light: rgba(239, 68, 68, 0.15);
    --info-light: rgba(6, 182, 212, 0.15);
}

/* Page Header */
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

/* Stats Cards Premium */
.stats-card-premium {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    height: 100%;
    position: relative;
    overflow: hidden;
}

.stats-card-premium:hover {
    transform: translateY(-5px);
    border-color: var(--primary);
    box-shadow: var(--shadow-md);
}

.stats-icon-box {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    margin-bottom: 1.25rem;
}

.stats-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-muted);
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.stats-number {
    font-size: 2rem;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1;
}

/* Table Sections */
.dashboard-section {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    height: 100%;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--text-main);
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 12px;
}

.table-dashboard {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.table-dashboard thead th {
    background: var(--bg-body);
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border-color);
    position: sticky;
    top: 0;
    z-index: 10;
}

.table-dashboard tbody td {
    padding: 1rem;
    border-bottom: 1px solid var(--border-color);
    font-size: 0.9rem;
    color: var(--text-main);
}

.table-dashboard tbody tr:last-child td {
    border-bottom: none;
}

/* Scrollbar Custom */
.custom-scroll {
    scrollbar-width: thin;
    scrollbar-color: var(--border-color) transparent;
}
.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }

/* GSAP */
.gsap-reveal {
    opacity: 0;
    transform: translateY(20px);
}

.fw-800 { font-weight: 800; }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

<div class="container-fluid px-0">
    <!-- Modern Header -->
    <div class="modern-header d-flex justify-content-between align-items-end gsap-reveal">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.8rem;">
                    <li class="breadcrumb-item active" aria-current="page">Dashboard</li>
                </ol>
            </nav>
            <h2 class="fw-800 mb-0">Visão <span class="title-accent">Operacional</span></h2>
            <p class="text-muted small mb-0">Acompanhamento em tempo real das implantações e performance.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary px-4 fw-bold shadow-sm" onclick="window.print()">
                <i class="bi bi-printer me-2"></i>Imprimir
            </button>
            <button class="btn btn-primary px-4 shadow-sm fw-bold" onclick="exportToExcel()">
                <i class="bi bi-download me-2"></i>Exportar
            </button>
        </div>
    </div>

    <!-- Stats Cards Premium -->
    <div class="row g-4 mb-5 gsap-reveal">
        <!-- Totais -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="stats-card-premium">
                <div class="stats-icon-box text-dark" style="background: var(--bg-body);"><i class="bi bi-briefcase"></i></div>
                <div class="stats-label">Efetivados</div>
                <div class="stats-number"><?= $total_clientes_com_concluidos ?></div>
                <div class="text-muted small mt-1">Ativos + Concl.</div>
            </div>
        </div>
        <!-- Ativos -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="stats-card-premium">
                <div class="stats-icon-box text-primary" style="background: var(--primary-light);"><i class="bi bi-person-check"></i></div>
                <div class="stats-label">Ativos</div>
                <div class="stats-number text-primary"><?= $total_clientes ?></div>
                <div class="text-muted small mt-1">Em implantação</div>
            </div>
        </div>
        <!-- Vendedores -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="stats-card-premium">
                <div class="stats-icon-box text-warning" style="background: var(--warning-light);"><i class="bi bi-person-badge"></i></div>
                <div class="stats-label">Vendedores</div>
                <div class="stats-number text-warning"><?= $total_vendedores ?></div>
                <div class="text-muted small mt-1">Colaboradores</div>
            </div>
        </div>
        <!-- Servidores -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="stats-card-premium">
                <div class="stats-icon-box text-info" style="background: var(--info-light);"><i class="bi bi-server"></i></div>
                <div class="stats-label">Servidores</div>
                <div class="stats-number text-info"><?= $total_servidores ?></div>
                <div class="text-muted small mt-1">Ativos em uso</div>
            </div>
        </div>
        <!-- Concluídos -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="stats-card-premium">
                <div class="stats-icon-box text-success" style="background: var(--success-light);"><i class="bi bi-check-all"></i></div>
                <div class="stats-label">Concluídos</div>
                <div class="stats-number text-success"><?= $total_concluidos ?></div>
                <div class="text-muted small mt-1">Sucesso total</div>
            </div>
        </div>
        <!-- Cancelados -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="stats-card-premium">
                <div class="stats-icon-box text-danger" style="background: var(--danger-light);"><i class="bi bi-x-circle"></i></div>
                <div class="stats-label">Cancelados</div>
                <div class="stats-number text-danger"><?= $total_cancelados ?></div>
                <div class="text-muted small mt-1">Interrompidos</div>
            </div>
        </div>
    </div>

    <!-- Overview Data -->
    <div class="row g-4 mb-4 gsap-reveal">
        <!-- Vendedores Section -->
        <div class="col-lg-7">
            <div class="dashboard-section d-flex flex-column">
                <div class="section-title">
                    <div style="width: 32px; height: 32px; border-radius: 8px; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center;">
                        <i class="bi bi-award"></i>
                    </div>
                    Performance por Vendedor
                </div>
                
                <div class="table-responsive flex-grow-1 custom-scroll" style="max-height: 480px;">
                    <table class="table-dashboard">
                        <thead>
                            <tr>
                                <th>Vendedor</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Ativos</th>
                                <th class="text-center">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vendedores as $v): 
                                $percentage = $total_clientes > 0 ? ($v['total_clientes'] / $total_clientes) * 100 : 0;
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="stats-icon-box mb-0 me-3" style="width: 36px; height: 36px; font-size: 0.9rem; background: var(--bg-body); color: var(--primary);">
                                                <?= substr($v['vendedor'], 0, 1) ?>
                                            </div>
                                            <span class="fw-bold"><?= htmlspecialchars($v['vendedor']) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center fw-bold"><?= $v['total_clientes'] ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 rounded-pill"><?= $v['clientes_ativos'] ?></span>
                                    </td>
                                    <td style="min-width: 120px;">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height: 6px; border-radius: 10px; background: var(--bg-body);">
                                                <div class="progress-bar" style="width: <?= $percentage ?>%; border-radius: 10px;"></div>
                                            </div>
                                            <span class="small fw-bold" style="width: 35px;"><?= round($percentage) ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- // estatísticas rápidas e destaques -->
        <div class="col-lg-5">
            <div class="dashboard-section h-100">
                <div class="section-title">
                    <div style="width: 32px; height: 32px; border-radius: 8px; background: var(--danger-light); color: var(--danger); display: flex; align-items: center; justify-content: center;">
                        <i class="bi bi-lightning"></i>
                    </div>
                    Insights Rápidos
                </div>
                
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="p-3 bg-light bg-opacity-10 rounded-3 border border-1 border-opacity-10">
                            <div class="d-flex align-items-center mb-2">
                                <div class="stats-icon-box mb-0 me-3" style="width: 32px; height: 32px; font-size: 0.8rem; background: var(--primary-light); color: var(--primary);">
                                    <i class="bi bi-trophy"></i>
                                </div>
                                <div>
                                    <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.65rem;">Vendedor Top</div>
                                    <div class="fw-bold fs-6"><?= !empty($vendedores) ? htmlspecialchars($vendedores[0]['vendedor']) : 'N/A' ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <div class="p-3 bg-light bg-opacity-10 rounded-3 border border-1 border-opacity-10">
                            <div class="d-flex align-items-center mb-2">
                                <div class="stats-icon-box mb-0 me-3" style="width: 32px; height: 32px; font-size: 0.8rem; background: var(--success-light); color: var(--success);">
                                    <i class="bi bi-hdd"></i>
                                </div>
                                <div>
                                    <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.65rem;">Servidor Top</div>
                                    <div class="fw-bold fs-6"><?= !empty($servidores) ? htmlspecialchars($servidores[0]['servidor']) : 'N/A' ?></div>
                                </div>
                            </div>
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
                    
                    <div class="col-12">
                        <div class="p-3 bg-primary bg-opacity-10 rounded-3 border border-1 border-opacity-10 d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center">
                                <div class="stats-icon-box mb-0 me-3" style="width: 32px; height: 32px; font-size: 0.8rem; background: var(--info-light); color: var(--info);">
                                    <i class="bi bi-graph-up"></i>
                                </div>
                                <div>
                                    <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.65rem;">Maior Retenção</div>
                                    <div class="fw-bold fs-6 text-primary"><?= htmlspecialchars($vendedor_maior_taxa ?: 'N/A') ?></div>
                                </div>
                            </div>
                            <div class="text-primary fw-bold fs-5"><?= round($maior_taxa) ?>%</div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-4 border-top border-opacity-10">
                    <h6 class="fw-800 text-main mb-3">Implantado com Sucesso</h6>
                    <?php
                        $total_finalizados = $total_concluidos + $total_cancelados;
                        $taxa_sucesso = $total_finalizados > 0 ? ($total_concluidos / $total_finalizados) * 100 : 0;
                    ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Taxa de Sucesso Global</span>
                        <span class="badge bg-success bg-opacity-10 text-success"><?= round($taxa_sucesso) ?>%</span>
                    </div>
                    <div class="progress" style="height: 10px; border-radius: 20px; background: var(--bg-body);">
                        <div class="progress-bar bg-success" style="width: <?= $taxa_sucesso ?>%"></div>
                        <div class="progress-bar bg-danger" style="width: <?= 100 - $taxa_sucesso ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-2 small text-muted">
                        <span><i class="bi bi-circle-fill text-success" style="font-size: 0.5rem;"></i> <?= $total_concluidos ?> Sucesso</span>
                        <span><i class="bi bi-circle-fill text-danger" style="font-size: 0.5rem;"></i> <?= $total_cancelados ?> Falha</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- // section: second row data -->
    <div class="row g-4 mb-5 gsap-reveal">
        <div class="col-lg-6">
            <div class="dashboard-section h-100">
                <div class="section-title">
                    <div style="width: 32px; height: 32px; border-radius: 8px; background: var(--success-light); color: var(--success); display: flex; align-items: center; justify-content: center;">
                        <i class="bi bi-hdd"></i>
                    </div>
                    Uso por Servidor
                </div>
                <div class="table-responsive custom-scroll" style="max-height: 400px;">
                    <table class="table-dashboard">
                        <thead>
                            <tr>
                                <th>Servidor</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Ativos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($servidores as $s): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="stats-icon-box mb-0 me-3" style="width: 32px; height: 32px; font-size: 0.8rem; background: var(--bg-body); color: var(--success);">
                                                <i class="bi bi-hdd"></i>
                                            </div>
                                            <span class="fw-bold"><?= htmlspecialchars($s['servidor']) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center fw-bold"><?= $s['total_clientes'] ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-success bg-opacity-10 text-success px-3 rounded-pill"><?= $s['clientes_ativos'] ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="dashboard-section h-100">
                <div class="section-title">
                    <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(139, 92, 246, 0.1); color: var(--purple); display: flex; align-items: center; justify-content: center;">
                        <i class="bi bi-pie-chart"></i>
                    </div>
                    Composição da Base
                </div>
                
                <div class="row g-3 mb-4">
                    <div class="col-4">
                        <div class="text-center p-3 bg-light bg-opacity-10 rounded-3 border">
                            <div class="text-muted small fw-bold text-uppercase mb-1">ATIVOS</div>
                            <div class="h3 fw-800 text-primary mb-0"><?= $total_ativos ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="text-center p-3 bg-light bg-opacity-10 rounded-3 border">
                            <div class="text-muted small fw-bold text-uppercase mb-1">SUCESSO</div>
                            <div class="h3 fw-800 text-success mb-0"><?= $total_concluidos ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="text-center p-3 bg-light bg-opacity-10 rounded-3 border">
                            <div class="text-muted small fw-bold text-uppercase mb-1">CANCEL.</div>
                            <div class="h3 fw-800 text-danger mb-0"><?= $total_cancelados ?></div>
                        </div>
                    </div>
                </div>

                <div class="px-2">
                    <div class="progress" style="height: 32px; border-radius: 12px; overflow: hidden; background: var(--bg-body);">
                        <div class="progress-bar bg-primary" 
                             style="width: <?= $total_clientes > 0 ? ($total_ativos / $total_clientes) * 100 : 0 ?>%"
                             data-bs-toggle="tooltip" title="Ativos: <?= $total_ativos ?>"></div>
                        <div class="progress-bar bg-success" 
                             style="width: <?= $total_clientes > 0 ? ($total_concluidos / $total_clientes) * 100 : 0 ?>%"
                             data-bs-toggle="tooltip" title="Concluídos: <?= $total_concluidos ?>"></div>
                        <div class="progress-bar bg-danger" 
                             style="width: <?= $total_clientes > 0 ? ($total_cancelados / $total_clientes) * 100 : 0 ?>%"
                             data-bs-toggle="tooltip" title="Cancelados: <?= $total_cancelados ?>"></div>
                    </div>
                    <div class="d-flex justify-content-center gap-4 mt-4 small text-muted fw-bold">
                        <span><i class="bi bi-circle-fill text-primary me-1"></i> ATIVOS</span>
                        <span><i class="bi bi-circle-fill text-success me-1"></i> SUCESSO</span>
                        <span><i class="bi bi-circle-fill text-danger me-1"></i> CANCEL.</span>
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

    // GSAP Entrance
    gsap.to(".gsap-reveal", { duration: 0.6, opacity: 1, y: 0, stagger: 0.1, ease: "power2.out" });

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