<?php
require_once 'config.php';
require_once 'header.php';

// 1. DADOS: SLA / Tempo Médio de Implantação (Lead Time) por Mês de Finalização (Últimos 6 meses)
$sql_sla = "
    SELECT 
        DATE_FORMAT(data_fim, '%m/%Y') as mes_formatado,
        DATE_FORMAT(data_fim, '%Y-%m') as mes_sort,
        AVG(DATEDIFF(data_fim, IFNULL(NULLIF(data_inicio, '0000-00-00'), data_fim))) as tempo_medio
    FROM clientes 
    WHERE data_fim > '0000-00-00' 
    AND status NOT IN ('CANCELADA', 'EM ANDAMENTO')
    GROUP BY mes_sort, mes_formatado
    ORDER BY mes_sort DESC
    LIMIT 12
";
$stmt = $pdo->query($sql_sla);
$sla_dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Reverter array para o gráfico ficar cronológico (esquerda pra direita)
$sla_dados = array_reverse($sla_dados);

$sla_labels = [];
$sla_series = [];
foreach ($sla_dados as $row) {
    $sla_labels[] = $row['mes_formatado'];
    $sla_series[] = (int)$row['tempo_medio'];
}

// 2. DADOS: Gargalos de Responsabilidade em Tarefas (Para onde está pendendo o gargalo)
$sql_resp = "
    SELECT responsavel, COUNT(*) as qtd 
    FROM tarefas 
    WHERE status != 'Concluída' 
    GROUP BY responsavel
";
$stmt = $pdo->query($sql_resp);
$resp_dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$resp_labels = [];
$resp_series = [];
foreach ($resp_dados as $row) {
    if (empty($row['responsavel'])) continue;
    $resp_labels[] = $row['responsavel'];
    $resp_series[] = (int)$row['qtd'];
}

// 3. DADOS: Módulos / Recursos mais utilizados pelos clientes (Heatmap conceitual/Bar Chart)
$sql_rec = "SELECT recursos FROM clientes WHERE recursos IS NOT NULL AND recursos != ''";
$stmt = $pdo->query($sql_rec);
$rec_counts = [];
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Trata tanto se for JSON quanto texto
    $decoded = json_decode($r['recursos'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $item) {
            $val = trim($item);
            if (!empty($val)) {
                $rec_counts[$val] = ($rec_counts[$val] ?? 0) + 1;
            }
        }
    } else {
        // Fallback caso não seja json, tenta quebrar por vírgula
        $partes = explode(',', $r['recursos']);
        foreach ($partes as $p) {
            $val = trim($p);
            if (!empty($val)) {
                $rec_counts[$val] = ($rec_counts[$val] ?? 0) + 1;
            }
        }
    }
}
// Ordena do maior pro menor e pega top 7
arsort($rec_counts);
$top_recursos = array_slice($rec_counts, 0, 8, true);

$rec_labels = array_keys($top_recursos);
$rec_series = array_values($top_recursos);

// Total de Dinheiro Parado no Funil (Receita Represada)
// Assumindo campo 'valor_contrato' se houver. Caso contrário usaremos a qtd de Licenças.
$sql_represado = "SELECT SUM(num_licencas) as total_licencas_paradas FROM clientes WHERE (data_fim IS NULL OR data_fim = '0000-00-00') AND status != 'CANCELADA'";
$licencas_paradas = $pdo->query($sql_represado)->fetchColumn();
$licencas_paradas = $licencas_paradas ?: 0;
?>

<style>
/* Dashboard Styles Premium */
.bi-wrapper {
    max-width: 1600px;
    margin: 0 auto;
    padding-bottom: 2rem;
}

.premium-header {
    background: var(--bg-card);
    backdrop-filter: blur(12px);
    border: 1px solid var(--border-color);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 10px 30px rgba(0,0,0,0.03);
}

.title-accent-bi {
    background: linear-gradient(120deg, #10b981, #0ea5e9);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-weight: 900;
}

.chart-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 22px;
    padding: 1.5rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.02);
    height: 100%;
    transition: transform 0.3s;
}
.chart-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 30px rgba(0,0,0,0.08);
}

.metric-highlight {
    font-size: 2.2rem;
    font-weight: 900;
    line-height: 1;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.metric-subtitle {
    font-size: 0.8rem;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
</style>

<!-- Load ApexCharts -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

<div class="bi-wrapper">
    <!-- Header Premium -->
    <div class="premium-header">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1" style="font-size: 0.8rem;">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">B.I Gerencial</li>
                </ol>
            </nav>
            <h2 class="fw-900 mb-0">Inteligência Estratégica <span class="title-accent-bi">B.I</span></h2>
            <p class="text-muted small mt-1 mb-0">Métricas puras e duras sobre gargalos, prazos e fluxo operacional.</p>
        </div>
        <div>
            <div class="metric-highlight text-end">
                <i class="bi bi-clock-history text-success" style="font-size: 1.8rem; opacity: 0.2"></i>
                <div>
                    <div><?= empty($sla_series) ? 'N/A' : number_format(array_sum($sla_series)/count($sla_series), 1, ',', '.') ?> <span style="font-size: 1rem;">dias</span></div>
                    <div class="metric-subtitle">SLA Médio Geral</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Gráfico 1: SLA Lead Time -->
        <div class="col-lg-8">
            <div class="chart-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-graph-up text-primary me-2"></i>Curva de Lead Time (Tempo de Onboarding)</h5>
                        <p class="text-muted small mb-0">Média de dias desde a entrada do cliente até o fim da implantação. Ideal: Curva descendente.</p>
                    </div>
                </div>
                <div id="chartSla" style="min-height: 280px;"></div>
            </div>
        </div>

        <!-- KPI Rápido -->
        <div class="col-lg-4">
            <div class="chart-card" style="background: linear-gradient(145deg, var(--bg-card), var(--primary-light)); border: 1px solid var(--primary);">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <h5 class="fw-bold text-primary mb-0"><i class="bi bi-cash-stack me-2"></i>Expansão Represada</h5>
                    <i class="bi bi-lightning-charge-fill text-warning fs-4"></i>
                </div>
                <p class="text-muted small mt-2">Licenças atualmente "paradas" no funil esperando conclusão do projeto, logo representam faturamento pendente.</p>
                <div class="mt-4 text-center">
                    <h1 class="display-3 fw-900" style="color: var(--text-main); letter-spacing: -2px;"><?= $licencas_paradas ?></h1>
                    <div class="fw-bold text-uppercase text-muted" style="letter-spacing: 2px; font-size: 0.8rem;">Licenças Estáveis</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Gráfico 2: Tarefas Pendentes Responsabilidade -->
        <div class="col-lg-5">
            <div class="chart-card">
                <div class="mb-3">
                    <h5 class="fw-bold mb-1"><i class="bi bi-pie-chart text-warning me-2"></i>Gargalos: Quem está bloqueando?</h5>
                    <p class="text-muted small mb-0">Divisão de responsabilidade de todas as tarefas que ainda não foram concluídas hoje.</p>
                </div>
                <div id="chartResp" class="d-flex justify-content-center" style="min-height: 250px;"></div>
            </div>
        </div>

        <!-- Gráfico 3: Recursos (Módulos) mais exigidos -->
        <div class="col-lg-7">
            <div class="chart-card">
                <div class="mb-3">
                    <h5 class="fw-bold mb-1"><i class="bi bi-layers text-purple me-2"></i>Módulos Analíticos (Top 8 Recursos)</h5>
                    <p class="text-muted small mb-0">O que os clientes mais contratam e requerem parametrização em seus ambientes.</p>
                </div>
                <div id="chartRecursos" style="min-height: 250px;"></div>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Configurações Globais Apex
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const textColor = isDark ? '#94a3b8' : '#64748b';
    const gridColor = isDark ? '#2b2e35' : '#e2e8f0';

    // 1. Gráfico SLA
    var optionsSla = {
        series: [{
            name: 'Média de Dias',
            data: <?= json_encode($sla_series) ?>
        }],
        chart: {
            height: 280,
            type: 'area', // Area chart gives a very premium look
            fontFamily: 'inherit',
            toolbar: { show: false },
            zoom: { enabled: false }
        },
        colors: ['#4361ee'],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.4,
                opacityTo: 0.05,
                stops: [0, 90, 100]
            }
        },
        dataLabels: { enabled: true, offsetY: -5 },
        stroke: { curve: 'smooth', width: 3 },
        xaxis: {
            categories: <?= json_encode($sla_labels) ?>,
            labels: { style: { colors: textColor } },
            axisBorder: { show: false },
            axisTicks: { show: false }
        },
        yaxis: {
            labels: { style: { colors: textColor } },
        },
        grid: {
            borderColor: gridColor,
            strokeDashArray: 4,
            yaxis: { lines: { show: true } }
        },
        theme: { mode: isDark ? 'dark' : 'light' }
    };
    new ApexCharts(document.querySelector("#chartSla"), optionsSla).render();

    // 2. Gráfico Responsáveis (Gargalo)
    var optionsResp = {
        series: <?= json_encode($resp_series) ?>,
        labels: <?= json_encode($resp_labels) ?>,
        chart: {
            type: 'donut',
            height: 280,
            fontFamily: 'inherit',
        },
        colors: ['#ef4444', '#4361ee', '#f59e0b', '#10b981'],
        plotOptions: {
            pie: {
                donut: {
                    size: '70%',
                    labels: {
                        show: true,
                        name: { show: true },
                        value: { show: true, fontSize: '1.5rem', fontWeight: 'bold' },
                        total: { show: true, showAlways: true, label: 'Pendências' }
                    }
                }
            }
        },
        stroke: { show: true, colors: 'transparent' },
        dataLabels: { enabled: false },
        legend: { position: 'bottom', labels: { colors: textColor } },
        theme: { mode: isDark ? 'dark' : 'light' }
    };
    new ApexCharts(document.querySelector("#chartResp"), optionsResp).render();

    // 3. Gráfico de Módulos
    var optionsRec = {
        series: [{
            name: 'Projetos Exigindo',
            data: <?= json_encode($rec_series) ?>
        }],
        chart: {
            type: 'bar',
            height: 280,
            fontFamily: 'inherit',
            toolbar: { show: false }
        },
        colors: ['#8b5cf6'],
        plotOptions: {
            bar: {
                borderRadius: 6,
                horizontal: true,
                distributed: true,
                dataLabels: { position: 'bottom' }
            }
        },
        dataLabels: {
            enabled: true,
            textAnchor: 'start',
            style: { colors: ['#fff'] },
            formatter: function (val, opt) {
                return opt.w.globals.labels[opt.dataPointIndex] + ":  " + val
            },
            offsetX: 0,
        },
        legend: { show: false },
        xaxis: {
            categories: <?= json_encode($rec_labels) ?>,
            labels: { show: false }, // ocultar para deixar o design mais clean, a info ja ta na barra
            axisBorder: { show: false },
            axisTicks: { show: false }
        },
        yaxis: {
            show: false, // Esconde os rótulos gigantes repetidos do lado
        },
        grid: { show: false },
        theme: { mode: isDark ? 'dark' : 'light' }
    };
    new ApexCharts(document.querySelector("#chartRecursos"), optionsRec).render();
});
</script>

<?php include 'footer.php'; ?>
