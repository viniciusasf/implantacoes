<?php
require_once 'config.php';

// --- DATA FETCHING ---

// 1. Relatório por Vendedor
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

// 2. Relatório por Servidor
$sql_servidores = "SELECT 
                    servidor,
                    COUNT(*) as total_clientes,
                    SUM(CASE WHEN (data_fim IS NULL OR data_fim = '0000-00-00') THEN 1 ELSE 0 END) as clientes_ativos,
                    SUM(CASE WHEN (data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao NOT LIKE '%CANCELADO%') THEN 1 ELSE 0 END) as clientes_concluidos,
                    SUM(CASE WHEN (data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao LIKE '%CANCELADO%') THEN 1 ELSE 0 END) as clientes_cancelados
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
$total_cancelados = $pdo->query("SELECT COUNT(*) FROM clientes WHERE data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao LIKE '%CANCELADO%'")->fetchColumn();
$total_concluidos = $pdo->query("SELECT COUNT(*) FROM clientes WHERE data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao NOT LIKE '%CANCELADO%'")->fetchColumn();
$total_ativos = $total_clientes - $total_concluidos - $total_cancelados;

// 4. Atividade de Treinamentos (Últimos 15 dias para o gráfico)
$sql_treinamentos_graph = "SELECT DATE(data_treinamento) as dia, COUNT(*) as total 
                           FROM treinamentos 
                           WHERE data_treinamento >= DATE_SUB(DATE(NOW()), INTERVAL 15 DAY)
                           GROUP BY dia 
                           ORDER BY dia ASC";
$treinamentos_graph = $pdo->query($sql_treinamentos_graph)->fetchAll(PDO::FETCH_ASSOC);

$graph_labels = [];
$graph_data = [];
foreach($treinamentos_graph as $row) {
    if (!empty($row['dia'])) {
        $graph_labels[] = date('d/m', strtotime($row['dia']));
        $graph_data[] = (int)$row['total'];
    }
}

// 5. Clientes Críticos e Total de Inativos (Sem treinamento/interação há mais de 5 dias)
$sql_inativos_base = "
    FROM clientes c
    LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
    WHERE (c.data_fim IS NULL OR c.data_fim = '0000-00-00')
    AND c.id_cliente NOT IN (
        SELECT DISTINCT id_cliente FROM treinamentos WHERE status = 'PENDENTE'
    )
    GROUP BY c.id_cliente, c.fantasia, c.vendedor, c.data_inicio
    HAVING 
        (MAX(t.data_treinamento) < DATE_SUB(CURDATE(), INTERVAL 5 DAY)) OR 
        (MAX(t.data_treinamento) IS NULL AND c.data_inicio < DATE_SUB(CURDATE(), INTERVAL 5 DAY))";

// Total para o badge/alerta
$total_inativos = $pdo->query("SELECT COUNT(*) FROM (SELECT c.id_cliente " . $sql_inativos_base . ") as total")->fetchColumn();

// Top 5 para exibição no card
$clientes_criticos = $pdo->query("SELECT c.id_cliente, c.fantasia, MAX(t.data_treinamento) as ultima_data, c.vendedor " . $sql_inativos_base . " ORDER BY ultima_data ASC LIMIT 5")->fetchAll();

// Cálculo da Meta Mensal baseada na média histórica de atendimentos realizados
$sql_media_historica = "SELECT AVG(total_mensal) as media 
                        FROM (
                            SELECT COUNT(*) as total_mensal 
                            FROM treinamentos 
                            WHERE status IN ('REALIZADO', 'RESOLVIDO') 
                            AND data_treinamento IS NOT NULL 
                            AND data_treinamento > '2000-01-01'
                            GROUP BY YEAR(data_treinamento), MONTH(data_treinamento)
                        ) as historico";

$media_calculada = $pdo->query($sql_media_historica)->fetchColumn();
$meta_mensal = $media_calculada ? (int)round($media_calculada) : 100;

$realizado_mes = $pdo->query("SELECT COUNT(*) FROM treinamentos 
                              WHERE status IN ('REALIZADO', 'RESOLVIDO') 
                              AND MONTH(data_treinamento) = MONTH(CURRENT_DATE) 
                              AND YEAR(data_treinamento) = YEAR(CURRENT_DATE)")->fetchColumn();

// Debug (Visualizar código fonte para ver valores se necessário)
echo "<!-- DEBUG: Meta=$meta_mensal | Realizado=$realizado_mes | Media=$media_calculada -->";

$percent_meta = 0;
if($meta_mensal > 0) {
    $percent_meta = round(($realizado_mes / $meta_mensal) * 100);
}

include 'header.php';
?>

<style>
/* // Design System Clean & Modern */
:root {
    /* Light Mode (Padrão) */
    --bg-body: #f8fafc;
    --bg-card: #ffffff;
    --border-color: #e2e8f0;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --primary: #4361ee;
    --primary-light: rgba(67, 97, 238, 0.08);
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --purple: #7209b7;
}

[data-theme="dark"] {
    /* Dark Mode */
    --bg-body: #0d0e12;
    --bg-card: #14151a;
    --border-color: #2b2e35;
    --text-main: #f1f5f9;
    --text-muted: #94a3b8;
    --primary-light: rgba(67, 97, 238, 0.15);
}

body {
    background-color: var(--bg-body) !important;
    color: var(--text-main) !important;
}

/* Premium Dashboard Container */
.dashboard-wrapper {
    padding: 2.5rem;
    max-width: 1600px;
    margin: 0 auto;
}

/* Glass Header */
.premium-header {
    background: var(--bg-card);
    backdrop-filter: blur(12px);
    border: 1px solid var(--border-color);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 10px 30px rgba(0,0,0,0.05);
}

.title-accent {
    background: linear-gradient(120deg, #4361ee, #7209b7);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-weight: 900;
}

/* Dashboard Cards */
.card-glass {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 22px;
    padding: 1.5rem;
    height: 100%;
    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

.card-glass:hover {
    transform: translateY(-5px);
    border-color: var(--primary);
    box-shadow: 0 12px 24px rgba(0,0,0,0.1);
}

/* Indicator Box */
.indicator-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

/* Charts and Tables inherit from card-glass for consistency */
.card-glass-chart {
    padding: 1.75rem;
}

/* Dynamic Tables */
.table-premium {
    width: 100%;
}
.table-premium th {
    background: var(--bg-body);
    color: var(--text-muted);
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 1rem;
    border-bottom: 1px solid var(--border-color);
}
.table-premium td {
    padding: 1.25rem 1rem;
    border-bottom: 1px solid var(--border-color);
    font-size: 0.9rem;
    vertical-align: middle;
}

/* Critical Badge */
.badge-critical {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
    border: 1px solid rgba(239, 68, 68, 0.2);
    padding: 0.5rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 700;
}

/* Custom Scroll */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-thumb { background: #2b2e35; border-radius: 10px; }

/* GSAP */
.gsap-reveal { opacity: 0; transform: translateY(30px); }
</style>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

<div class="dashboard-wrapper">
    <!-- Header Premium -->
    <div class="premium-header gsap-reveal">
        <div>
            <h1 class="h3 mb-1 fw-900">Mesa de <span class="title-accent">Comando</span></h1>
            <p class="text-muted small mb-0"><i class="bi bi-clock-history me-1"></i> Dados atualizados em tempo real: <?= date('d/m/Y H:i') ?></p>
        </div>
        <div class="d-flex gap-3">
            <button class="btn btn-dark border-secondary rounded-4 px-4 py-2 fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-2"></i> Relatório
            </button>
            <button class="btn btn-primary rounded-4 px-4 py-2 fw-bold shadow-sm" onclick="exportToExcel()">
                <i class="bi bi-file-earmark-excel me-2"></i> Exportar
            </button>
        </div>
    </div>

    <!-- Main KPIs -->
    <div class="row g-4 mb-5">
        <div class="col-xl-3 col-md-6 gsap-reveal">
            <div class="card-glass">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="indicator-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-people"></i>
                    </div>
                    <span class="text-muted small fw-bold">CLIENTES ATIVOS</span>
                </div>
                <h2 class="h1 fw-900 mb-1 text-primary"><?= $total_ativos ?></h2>
                <div class="small text-muted">Implantações em andamento</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 gsap-reveal" style="transition-delay: 0.1s">
            <div class="card-glass border-success border-opacity-10">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="indicator-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check2-circle"></i>
                    </div>
                    <span class="text-muted small fw-bold">CONCLUÍDOS</span>
                </div>
                <h2 class="h1 fw-900 mb-1 text-success"><?= $total_concluidos ?></h2>
                <div class="small text-muted">Sucessos registrados</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 gsap-reveal" style="transition-delay: 0.2s">
            <div class="card-glass border-warning border-opacity-10">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="indicator-icon bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-person-workspace"></i>
                    </div>
                    <span class="text-muted small fw-bold">VENDEDORES</span>
                </div>
                <h2 class="h1 fw-900 mb-1 text-warning"><?= $total_vendedores ?></h2>
                <div class="small text-muted">Vendedores em campo</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 gsap-reveal" style="transition-delay: 0.3s">
            <div class="card-glass border-danger border-opacity-10">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="indicator-icon bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-x-octagon"></i>
                    </div>
                    <span class="text-muted small fw-bold">CLIENTES CANCELADOS</span>
                </div>
                <h2 class="h1 fw-900 mb-1 text-danger"><?= $total_cancelados ?></h2>
                <div class="small text-muted">Adesões interrompidas</div>
            </div>
        </div>
    </div>

    <!-- Charts & Insights -->
    <div class="row g-4 mb-5">
        <div class="col-lg-8 gsap-reveal">
            <div class="card-glass card-glass-chart">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-900 mb-0"><i class="bi bi-pulse text-primary me-2"></i> Fluxo de Treinamentos</h5>
                    <span class="badge bg-primary bg-opacity-10 text-primary border-primary border-opacity-10 px-3 py-2">Últimos 15 dias</span>
                </div>
                <div id="chart-timeline" style="min-height: 350px;"></div>
            </div>
        </div>
        <div class="col-lg-4 gsap-reveal">
            <div class="card-glass card-glass-chart text-center">
                <h5 class="fw-900 mb-4"><i class="bi bi-bullseye text-danger me-2"></i> Meta de Treinamento Mês</h5>
                <div id="chart-goal"></div>
                <div class="mt-2">
                    <div class="h3 fw-900 mb-0"><?= $realizado_mes ?> / <?= $meta_mensal ?></div>
                    <div class="text-muted small">Realizados vs. Média Histórica</div>
                </div>
                <div class="mt-4 p-3 bg-light bg-opacity-10 rounded-4 border border-1 border-opacity-10">
                    <div class="small fw-bold text-uppercase text-muted mb-2">Previsão de Entrega</div>
                    <div class="h5 fw-900 mb-0 text-success"><?= $percent_meta >= 90 ? 'Excelente' : ($percent_meta >= 70 ? 'No Caminho' : 'Atenção') ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Secondary Row: Critical and Performance -->
    <div class="row g-4 mb-5">
        <!-- Critical Clients -->
        <div class="col-lg-5 gsap-reveal">
            <div class="card-glass">
                <h5 class="fw-900 mb-4 d-flex align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                        Radar de Emergência
                    </div>
                    <?php if($total_inativos > 0): ?>
                        <span class="badge rounded-pill bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" style="font-size: 0.7rem; padding: 0.4rem 0.8rem;">
                            <?= $total_inativos ?> INATIVOS
                        </span>
                    <?php endif; ?>
                </h5>

                <?php if($total_inativos > 0): ?>
                    <div class="alert alert-warning border-0 p-3 mb-4 rounded-4 small d-flex align-items-center gap-3" style="background: var(--warning-light); color: var(--warning);">
                        <i class="bi bi-shield-exclamation fs-5"></i>
                        <div>
                            <div class="fw-bold">Atenção Necessária</div>
                            <div class="opacity-75">Existem clientes aguardando contato há mais de 5 dias.</div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="list-group list-group-flush bg-transparent">
                    <?php if(!empty($clientes_criticos)): ?>
                        <?php foreach($clientes_criticos as $cli): 
                            $dias = $cli['ultima_data'] ? floor((time() - strtotime($cli['ultima_data'])) / 86400) : '∞';
                        ?>
                            <div class="list-group-item bg-transparent border-opacity-10 px-0 py-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="bg-danger bg-opacity-10 text-danger p-2 rounded-3" style="font-size: 1.2rem;">
                                            <i class="bi bi-person-x"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold mb-0"><?= htmlspecialchars($cli['fantasia']) ?></div>
                                            <div class="text-muted small">Resp: <?= htmlspecialchars($cli['vendedor'] ?: 'N/A') ?></div>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <div class="badge-critical"><?= $dias ?> dias inativo</div>
                                        <a href="treinamentos_cliente.php?id_cliente=<?= $cli['id_cliente'] ?>" class="text-decoration-none small text-primary mt-1 d-block">Ver Ficha <i class="bi bi-arrow-right"></i></a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-5 opacity-50">
                            <i class="bi bi-shield-check display-4"></i>
                            <p class="mt-2">Todos os clientes em dia!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Performance Vendedores -->
        <div class="col-lg-7 gsap-reveal">
            <div class="card-glass">
                <h5 class="fw-900 mb-4 d-flex align-items-center gap-2">
                    <i class="bi bi-award text-warning"></i>
                    Métricas de Performance
                </h5>
                <div class="table-responsive">
                    <table class="table-premium">
                        <thead>
                            <tr>
                                <th>Vendedor</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Ativos</th>
                                <th class="text-center">Sucesso</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach(array_slice($vendedores, 0, 5) as $v): 
                                $success_rate = $v['total_clientes'] > 0 ? round(($v['clientes_concluidos'] / $v['total_clientes']) * 100) : 0;
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="bg-primary bg-opacity-10 text-primary fw-bold p-2 px-3 rounded-pill" style="font-size: 0.8rem;">
                                                <?= substr($v['vendedor'], 0, 1) ?>
                                            </div>
                                            <span class="fw-bold"><?= htmlspecialchars($v['vendedor']) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center fw-900"><?= $v['total_clientes'] ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3"><?= $v['clientes_ativos'] ?></span>
                                    </td>
                                    <td style="min-width: 150px;">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height: 6px; border-radius: 10px; background: rgba(255,255,255,0.05);">
                                                <div class="progress-bar bg-success" style="width: <?= $success_rate ?>%; border-radius: 10px;"></div>
                                            </div>
                                            <span class="small fw-bold"><?= $success_rate ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // --- APEX CHARTS SETUP ---

    // 1. Timeline Chart
    const timelineOptions = {
        series: [{
            name: 'Sessões',
            data: <?= json_encode($graph_data) ?>
        }],
        chart: {
            type: 'area',
            height: 350,
            toolbar: { show: false },
            zoom: { enabled: false },
            foreColor: 'var(--text-muted)',
            background: 'transparent'
        },
        colors: ['#4361ee'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 3 },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.45,
                opacityTo: 0.05,
                stops: [20, 100, 100, 100]
            }
        },
        xaxis: {
            categories: <?= json_encode($graph_labels) ?>,
            axisBorder: { show: false },
            axisTicks: { show: false }
        },
        yaxis: { labels: { offsetX: -10 } },
        grid: {
            borderColor: 'rgba(255,255,255,0.05)',
            strokeDashArray: 4,
            xaxis: { lines: { show: true } }
        },
        tooltip: { theme: 'dark' }
    };
    new ApexCharts(document.querySelector("#chart-timeline"), timelineOptions).render();

    // 2. Monthly Goal Chart
    const goalOptions = {
        series: [<?= $percent_meta ?>],
        chart: {
            height: 280,
            type: 'radialBar',
        },
        plotOptions: {
            radialBar: {
                hollow: { size: '70%' },
                track: { background: 'var(--primary-light)' },
                dataLabels: {
                    name: { show: false },
                    value: {
                        color: 'var(--text-main)',
                        fontSize: '30px',
                        show: true,
                        fontWeight: 900,
                        offsetY: 10
                    }
                }
            }
        },
        colors: [<?= $percent_meta >= 90 ? "'#10b981'" : ($percent_meta >= 70 ? "'#f59e0b'" : "'#ef4444'") ?>],
        stroke: { lineCap: 'round' }
    };
    new ApexCharts(document.querySelector("#chart-goal"), goalOptions).render();

    // --- UTILS ---
    function exportToExcel() {
        // Logica simplificada de exportação (pode ser expandida)
        window.location.href = 'relatorio.php?export=xls';
    }

    // --- ANIMATIONS ---
    document.addEventListener('DOMContentLoaded', () => {
        gsap.to(".gsap-reveal", {
            duration: 0.8,
            opacity: 1,
            y: 0,
            stagger: 0.1,
            ease: "power3.out"
        });
    });
</script>

<?php include 'footer.php'; ?>