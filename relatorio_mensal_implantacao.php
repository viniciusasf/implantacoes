<?php
date_default_timezone_set('America/Sao_Paulo');
require_once 'config.php';

// --- FILTROS ---
$mes_atual = date('m');
$ano_atual = date('Y');

$filtro_mes = isset($_GET['mes']) ? $_GET['mes'] : $mes_atual;
$filtro_ano = isset($_GET['ano']) ? $_GET['ano'] : $ano_atual;

$data_inicio = "$filtro_ano-$filtro_mes-01 00:00:00";
$data_fim    = date("Y-m-t 23:59:59", strtotime($data_inicio));

// --- QUERY PRINCIPAL ---
$sql = "SELECT fantasia, status, data_inicio, data_fim, vendedor, servidor, id_cliente
        FROM clientes 
        WHERE status IN ('CONCLUIDA', 'CANCELADA')
          AND data_fim BETWEEN :di AND :df
        ORDER BY data_fim DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([':di' => $data_inicio, ':df' => $data_fim]);
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- CLIENTES ATIVOS COM PREVISÃO DE ENCERRAMENTO ---
$sql_previsao = "SELECT fantasia, data_inicio, data_previsao_encerramento, vendedor, servidor, id_cliente
               FROM clientes 
               WHERE (data_fim IS NULL OR data_fim = '0000-00-00')
                 AND data_previsao_encerramento IS NOT NULL
                 AND data_previsao_encerramento != '0000-00-00'
                 AND data_previsao_encerramento BETWEEN :di AND :df
               ORDER BY data_previsao_encerramento ASC";

$stmtPrev = $pdo->prepare($sql_previsao);
$stmtPrev->execute([':di' => $data_inicio, ':df' => $data_fim]);
$clientes_previsao = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);

// --- KPIs ---
$kpi_concluidas = 0;
$kpi_canceladas = 0;
foreach ($clientes as $c) {
    if ($c['status'] === 'CONCLUIDA') $kpi_concluidas++;
    elseif ($c['status'] === 'CANCELADA') $kpi_canceladas++;
}
$total_periodo = count($clientes);

// --- EVOLUÇÃO 12 MESES ---
$sql_evolucao = "SELECT 
    DATE_FORMAT(data_fim, '%Y-%m') AS mes,
    SUM(CASE WHEN status = 'CONCLUIDA' THEN 1 ELSE 0 END) AS concluidas,
    SUM(CASE WHEN status = 'CANCELADA' THEN 1 ELSE 0 END) AS canceladas
FROM clientes
WHERE status IN ('CONCLUIDA', 'CANCELADA')
  AND data_fim >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
GROUP BY mes
ORDER BY mes ASC";
$evolucao_data = $pdo->query($sql_evolucao)->fetchAll(PDO::FETCH_ASSOC);

$evolucao_labels = [];
$evolucao_concluidas = [];
$evolucao_canceladas = [];

$meses_pt = ['01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun',
             '07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez'];

foreach ($evolucao_data as $row) {
    $partes = explode('-', $row['mes']);
    $label  = ($meses_pt[$partes[1]] ?? $partes[1]) . '/' . substr($partes[0], 2);
    $evolucao_labels[]      = $label;
    $evolucao_concluidas[]  = (int)$row['concluidas'];
    $evolucao_canceladas[]  = (int)$row['canceladas'];
}

include 'header.php';
?>

<style>
.report-wrapper { max-width: 1400px; margin: 0 auto; }
.title-accent {
    background: linear-gradient(120deg, #4361ee, #7209b7);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-weight: 900;
}
.kpi-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 1.5rem;
    position: relative;
    overflow: hidden;
    transition: transform 0.3s ease;
}
.kpi-card:hover { transform: translateY(-5px); }
.kpi-card::before {
    content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%;
}
.kpi-concluidas::before { background: #10b981; }
.kpi-canceladas::before { background: #ef4444; }
.kpi-total::before { background: #4361ee; }

.kpi-value { font-size: 2.2rem; font-weight: 800; line-height: 1; }
.kpi-label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); }

.chart-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 22px;
    padding: 1.5rem;
}
.filter-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 15px;
    padding: 1rem;
    margin-bottom: 2rem;
}
.status-pill {
    padding: 0.3rem 0.8rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
}
.status-concluida { background: rgba(16, 185, 129, 0.1); color: #10b981; }
.status-cancelada { background: rgba(239, 68, 68, 0.1); color: #ef4444; }

.gsap-reveal { opacity: 0; transform: translateY(20px); }
</style>

<div class="report-wrapper">
    <div class="d-flex justify-content-between align-items-end mb-4 gsap-reveal">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1" style="font-size: 0.8rem;">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">Dashboard</a></li>
                    <li class="breadcrumb-item active">Relatório Mensal de Encerramento</li>
                </ol>
            </nav>
            <h2 class="fw-900 mb-0">Relatório de <span class="title-accent">Encerramentos</span></h2>
            <p class="text-muted small">Monitoramento mensal de implantações concluídas e canceladas.</p>
        </div>
        
        <div class="filter-panel mb-0">
            <form method="GET" class="d-flex gap-2 align-items-center">
                <select name="mes" class="form-select form-select-sm" style="width: 130px;">
                    <?php foreach ($meses_pt as $num => $nome): ?>
                        <option value="<?= $num ?>" <?= $filtro_mes == $num ? 'selected' : '' ?>><?= $nome ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="ano" class="form-select form-select-sm" style="width: 100px;">
                    <?php for($i=2024; $i<=date('Y')+1; $i++): ?>
                        <option value="<?= $i ?>" <?= $filtro_ano == $i ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm px-3">Filtrar</button>
            </form>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4 gsap-reveal">
            <div class="kpi-card kpi-concluidas">
                <div class="kpi-label mb-2">Concluídas</div>
                <div class="d-flex justify-content-between align-items-end">
                    <div class="kpi-value text-success"><?= $kpi_concluidas ?></div>
                    <div class="text-success small fw-bold">Implantações</div>
                </div>
            </div>
        </div>
        <div class="col-md-4 gsap-reveal">
            <div class="kpi-card kpi-canceladas">
                <div class="kpi-label mb-2">Canceladas</div>
                <div class="d-flex justify-content-between align-items-end">
                    <div class="kpi-value text-danger"><?= $kpi_canceladas ?></div>
                    <div class="text-danger small fw-bold">Desistências</div>
                </div>
            </div>
        </div>
        <div class="col-md-4 gsap-reveal">
            <div class="kpi-card kpi-total">
                <div class="kpi-label mb-2">Total Movimentado</div>
                <div class="d-flex justify-content-between align-items-end">
                    <div class="kpi-value text-primary"><?= $total_periodo ?></div>
                    <div class="text-primary small fw-bold">No período</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4 gsap-reveal">
        <div class="col-12">
            <div class="chart-card">
                <h6 class="fw-800 mb-4"><i class="bi bi-graph-up text-primary me-2"></i>Evolução de Encerramentos (12 meses)</h6>
                <div id="chart-evolucao" style="min-height: 350px;"></div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm gsap-reveal mb-4">
        <div class="card-header bg-transparent border-0 pt-4 px-4">
            <h6 class="fw-800 mb-0">Detalhamento dos Encerramentos</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 py-3 text-muted small fw-bold">CLIENTE</th>
                            <th class="py-3 text-muted small fw-bold">VENDEDOR / SERVIDOR</th>
                            <th class="py-3 text-muted small fw-bold">INÍCIO</th>
                            <th class="py-3 text-muted small fw-bold">FIM</th>
                            <th class="py-3 text-muted small fw-bold">DURAÇÃO</th>
                            <th class="py-3 text-muted small fw-bold text-center">STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clientes)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    Nenhum encerramento registrado para este período.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($clientes as $c): 
                                $inicio = new DateTime($c['data_inicio']);
                                $fim = new DateTime($c['data_fim']);
                                $dias = $inicio->diff($fim)->days;
                                $status_class = $c['status'] === 'CONCLUIDA' ? 'status-concluida' : 'status-cancelada';
                            ?>
                                <tr>
                                    <td class="ps-4 fw-bold"><?= htmlspecialchars($c['fantasia']) ?></td>
                                    <td>
                                        <div class="small fw-bold"><?= htmlspecialchars($c['vendedor']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($c['servidor']) ?></div>
                                    </td>
                                    <td class="small"><?= date('d/m/Y', strtotime($c['data_inicio'])) ?></td>
                                    <td class="small fw-bold"><?= date('d/m/Y', strtotime($c['data_fim'])) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= $dias ?> dias</span></td>
                                    <td class="text-center">
                                        <span class="status-pill <?= $status_class ?>"><?= $c['status'] ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm gsap-reveal">
        <div class="card-header bg-transparent border-0 pt-4 px-4">
            <h6 class="fw-800 mb-0"><i class="bi bi-calendar-check text-warning me-2"></i>Clientes Ativos com Previsão de Encerramento</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 py-3 text-muted small fw-bold">CLIENTE</th>
                            <th class="py-3 text-muted small fw-bold">VENDEDOR / SERVIDOR</th>
                            <th class="py-3 text-muted small fw-bold">INÍCIO</th>
                            <th class="py-3 text-muted small fw-bold">PREVISÃO ENCERRAMENTO</th>
                            <th class="py-3 text-muted small fw-bold">DIAS ATÉ ENCERRAR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clientes_previsao)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    Nenhum cliente ativo com previsão de encerramento.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($clientes_previsao as $cp): 
                                $dataPrev = new DateTime($cp['data_previsao_encerramento']);
                                $diasPrev = (new DateTime())->diff($dataPrev)->days;
                                $diasClasse = $diasPrev <= 15 ? 'bg-danger text-white' : ($diasPrev <= 30 ? 'bg-warning text-dark' : 'bg-light text-dark');
                            ?>
                                <tr>
                                    <td class="ps-4 fw-bold"><?= htmlspecialchars($cp['fantasia']) ?></td>
                                    <td>
                                        <div class="small fw-bold"><?= htmlspecialchars($cp['vendedor']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($cp['servidor']) ?></div>
                                    </td>
                                    <td class="small"><?= date('d/m/Y', strtotime($cp['data_inicio'])) ?></td>
                                    <td class="small fw-bold text-warning"><?= date('d/m/Y', strtotime($cp['data_previsao_encerramento'])) ?></td>
                                    <td><span class="badge <?= $diasClasse ?>"><?= $diasPrev ?> dias</span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const labelColor = isDark ? '#cbd5e1' : '#8899a6';

    const options = {
        series: [
            { name: 'Concluídas', data: <?= json_encode($evolucao_concluidas) ?> },
            { name: 'Canceladas', data: <?= json_encode($evolucao_canceladas) ?> }
        ],
        chart: {
            type: 'area',
            height: 350,
            toolbar: { show: false },
            zoom: { enabled: false },
            fontFamily: 'Inter, sans-serif'
        },
        colors: ['#10b981', '#ef4444'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 3 },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.45,
                opacityTo: 0.05,
                stops: [20, 100]
            }
        },
        xaxis: {
            categories: <?= json_encode($evolucao_labels) ?>,
            axisBorder: { show: false },
            axisTicks: { show: false },
            labels: {
                style: {
                    colors: labelColor
                }
            }
        },
        yaxis: { 
            labels: { 
                style: { 
                    colors: labelColor 
                } 
            } 
        },
        grid: { 
            borderColor: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)', 
            strokeDashArray: 4 
        },
        legend: { 
            position: 'top', 
            horizontalAlign: 'right',
            labels: {
                colors: isDark ? '#f1f5f9' : '#2b3674'
            }
        },
        tooltip: { theme: 'dark' }
    };

    if (document.querySelector("#chart-evolucao")) {
        new ApexCharts(document.querySelector("#chart-evolucao"), options).render();
    }

    gsap.to(".gsap-reveal", {
        duration: 0.6,
        opacity: 1,
        y: 0,
        stagger: 0.1,
        ease: "power2.out"
    });
});
</script>

<?php include 'footer.php'; ?>
