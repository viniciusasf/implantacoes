<?php
date_default_timezone_set('America/Sao_Paulo');
require_once 'config.php';

// ─── FILTROS ───────────────────────────────────────────────────────────
$filtro_data_inicio   = isset($_GET['data_inicio'])   ? trim($_GET['data_inicio'])   : date('Y-m-01');
$filtro_data_fim      = isset($_GET['data_fim'])       ? trim($_GET['data_fim'])       : date('Y-m-t');
$filtro_vendedor      = isset($_GET['vendedor'])       ? trim($_GET['vendedor'])       : '';
$filtro_servidor      = isset($_GET['servidor'])       ? trim($_GET['servidor'])       : '';
$filtro_status        = isset($_GET['status'])         ? trim($_GET['status'])         : '';

// Validar datas
$data_inicio_obj = DateTime::createFromFormat('Y-m-d', $filtro_data_inicio);
$data_fim_obj    = DateTime::createFromFormat('Y-m-d', $filtro_data_fim);
if (!$data_inicio_obj) $filtro_data_inicio = date('Y-m-01');
if (!$data_fim_obj)    $filtro_data_fim    = date('Y-m-t');

$data_inicio_sql = $filtro_data_inicio . ' 00:00:00';
$data_fim_sql    = $filtro_data_fim . ' 23:59:59';

// ─── LISTAS PARA FILTROS ───────────────────────────────────────────────
$vendedores_lista = $pdo->query("SELECT DISTINCT vendedor FROM clientes WHERE vendedor IS NOT NULL AND vendedor != '' ORDER BY vendedor")->fetchAll(PDO::FETCH_COLUMN);
$servidores_lista = $pdo->query("SELECT DISTINCT servidor FROM clientes WHERE servidor IS NOT NULL AND servidor != '' ORDER BY servidor")->fetchAll(PDO::FETCH_COLUMN);

// ─── QUERY PRINCIPAL ───────────────────────────────────────────────────
$sql = "SELECT 
            t.id_treinamento,
            t.data_treinamento,
            t.data_treinamento_encerrado,
            t.tema,
            t.observacoes,
            t.status,
            c.fantasia,
            c.vendedor,
            c.servidor,
            co.nome AS contato_nome
        FROM treinamentos t
        LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
        LEFT JOIN contatos co ON t.id_contato = co.id_contato
        WHERE t.data_treinamento BETWEEN :di AND :df";
$params = [':di' => $data_inicio_sql, ':df' => $data_fim_sql];

if (!empty($filtro_vendedor)) {
    $sql .= " AND c.vendedor = :vendedor";
    $params[':vendedor'] = $filtro_vendedor;
}

if (!empty($filtro_servidor)) {
    $sql .= " AND c.servidor = :servidor";
    $params[':servidor'] = $filtro_servidor;
}

if (!empty($filtro_status)) {
    $sql .= " AND UPPER(t.status) = UPPER(:status)";
    $params[':status'] = $filtro_status;
}

$sql .= " ORDER BY t.data_treinamento DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$treinamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── KPIs ──────────────────────────────────────────────────────────────
$kpi_total       = count($treinamentos);
$kpi_realizados  = 0;
$kpi_pendentes   = 0;
$kpi_cancelados  = 0;

foreach ($treinamentos as $t) {
    $st = strtoupper($t['status'] ?? '');
    if ($st === 'REALIZADO' || $st === 'RESOLVIDO') $kpi_realizados++;
    elseif ($st === 'PENDENTE') $kpi_pendentes++;
    elseif ($st === 'CANCELADO') $kpi_cancelados++;
}

$kpi_taxa_resolucao = $kpi_total > 0 ? round(($kpi_realizados / $kpi_total) * 100, 1) : 0;

// ─── GRÁFICO: EVOLUÇÃO MENSAL (últimos 12 meses) ──────────────────────
$sql_evolucao = "SELECT 
    DATE_FORMAT(data_treinamento, '%Y-%m') AS mes,
    COUNT(*) AS total,
    SUM(CASE WHEN UPPER(status) IN ('REALIZADO','RESOLVIDO') THEN 1 ELSE 0 END) AS realizados,
    SUM(CASE WHEN UPPER(status) = 'PENDENTE' THEN 1 ELSE 0 END) AS pendentes
FROM treinamentos
WHERE data_treinamento >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
GROUP BY mes
ORDER BY mes ASC";
$evolucao_data = $pdo->query($sql_evolucao)->fetchAll(PDO::FETCH_ASSOC);

$evolucao_labels     = [];
$evolucao_realizados = [];
$evolucao_pendentes  = [];
$evolucao_totais     = [];

$meses_pt = ['01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun',
             '07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez'];

foreach ($evolucao_data as $row) {
    $partes = explode('-', $row['mes']);
    $label  = ($meses_pt[$partes[1]] ?? $partes[1]) . '/' . substr($partes[0], 2);
    $evolucao_labels[]     = $label;
    $evolucao_realizados[] = (int)$row['realizados'];
    $evolucao_pendentes[]  = (int)$row['pendentes'];
    $evolucao_totais[]     = (int)$row['total'];
}

// ─── GRÁFICO: COMPARATIVO POR VENDEDOR (período filtrado) ──────────────
$sql_vendedor_comp = "SELECT 
    c.vendedor,
    COUNT(*) AS total,
    SUM(CASE WHEN UPPER(t.status) IN ('REALIZADO','RESOLVIDO') THEN 1 ELSE 0 END) AS realizados,
    SUM(CASE WHEN UPPER(t.status) = 'PENDENTE' THEN 1 ELSE 0 END) AS pendentes
FROM treinamentos t
LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
WHERE t.data_treinamento BETWEEN :di2 AND :df2
  AND c.vendedor IS NOT NULL AND c.vendedor != ''
GROUP BY c.vendedor
ORDER BY total DESC
LIMIT 10";
$stmt_vend = $pdo->prepare($sql_vendedor_comp);
$stmt_vend->execute([':di2' => $data_inicio_sql, ':df2' => $data_fim_sql]);
$vendedor_comp_data = $stmt_vend->fetchAll(PDO::FETCH_ASSOC);

$vend_labels      = [];
$vend_realizados  = [];
$vend_pendentes   = [];

foreach ($vendedor_comp_data as $v) {
    $vend_labels[]     = $v['vendedor'];
    $vend_realizados[] = (int)$v['realizados'];
    $vend_pendentes[]  = (int)$v['pendentes'];
}

// ─── GRÁFICO: TOP TEMAS ────────────────────────────────────────────────
$sql_temas = "SELECT tema, COUNT(*) AS total
    FROM treinamentos
    WHERE data_treinamento BETWEEN :di3 AND :df3
      AND tema IS NOT NULL AND tema != ''
    GROUP BY tema
    ORDER BY total DESC
    LIMIT 8";
$stmt_temas = $pdo->prepare($sql_temas);
$stmt_temas->execute([':di3' => $data_inicio_sql, ':df3' => $data_fim_sql]);
$temas_data = $stmt_temas->fetchAll(PDO::FETCH_ASSOC);

$temas_labels = [];
$temas_valores = [];
foreach ($temas_data as $tema) {
    $temas_labels[]  = mb_strimwidth($tema['tema'], 0, 30, '…');
    $temas_valores[] = (int)$tema['total'];
}

// ─── EXPORTAÇÃO EXCEL ──────────────────────────────────────────────────
if (isset($_GET['exportar_xls'])) {
    $nome_arquivo = 'relatorio_treinamentos_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Data</th><th>Cliente</th><th>Vendedor</th><th>Servidor</th><th>Contato</th><th>Tema</th><th>Status</th><th>Observação</th></tr>";

    foreach ($treinamentos as $t) {
        echo "<tr>";
        echo "<td>" . (int)$t['id_treinamento'] . "</td>";
        echo "<td>" . (!empty($t['data_treinamento']) ? date('d/m/Y H:i', strtotime($t['data_treinamento'])) : '') . "</td>";
        echo "<td>" . htmlspecialchars($t['fantasia'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($t['vendedor'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($t['servidor'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($t['contato_nome'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($t['tema'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($t['status'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($t['observacoes'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "</tr>";
    }

    echo "</table>";
    exit;
}

// ─── EXPORTAÇÃO CSV ────────────────────────────────────────────────────
if (isset($_GET['exportar_csv'])) {
    $nome_arquivo = 'relatorio_treinamentos_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Data', 'Cliente', 'Vendedor', 'Servidor', 'Contato', 'Tema', 'Status', 'Observação'], ';');

    foreach ($treinamentos as $t) {
        fputcsv($output, [
            (int)$t['id_treinamento'],
            !empty($t['data_treinamento']) ? date('d/m/Y H:i', strtotime($t['data_treinamento'])) : '',
            $t['fantasia'] ?? '',
            $t['vendedor'] ?? '',
            $t['servidor'] ?? '',
            $t['contato_nome'] ?? '',
            $t['tema'] ?? '',
            $t['status'] ?? '',
            $t['observacoes'] ?? ''
        ], ';');
    }

    fclose($output);
    exit;
}

include 'header.php';
?>

<style>
/* Design System — Relatórios */
.report-wrapper {
    max-width: 1600px;
    margin: 0 auto;
}

.report-header {
    padding: 1rem 0 2rem 0;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 2rem;
}

.title-accent {
    background: linear-gradient(120deg, #4361ee, #7209b7);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-weight: 900;
}

/* Filter Panel */
.filter-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.04);
}

.filter-panel .form-control,
.filter-panel .form-select {
    height: 46px;
    border-radius: 12px;
    background: var(--bg-body);
    border: 1px solid var(--border-color);
    color: var(--text-main);
    font-size: 0.9rem;
}

.filter-panel .form-control:focus,
.filter-panel .form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-light);
}

/* KPI Cards */
.kpi-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 1.5rem;
    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    position: relative;
    overflow: hidden;
}

.kpi-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    border-radius: 20px 0 0 20px;
}

.kpi-card.kpi-total::before    { background: var(--primary); }
.kpi-card.kpi-done::before     { background: var(--success); }
.kpi-card.kpi-pending::before  { background: var(--warning); }
.kpi-card.kpi-rate::before     { background: var(--purple); }

.kpi-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 30px rgba(0,0,0,0.08);
}

.kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
}

.kpi-value {
    font-size: 2rem;
    font-weight: 900;
    line-height: 1;
    font-family: var(--font-heading);
}

.kpi-label {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
}

/* Tooltip KPIs (Igual funil_implantacao.php) */
.kpi-card { cursor: help; position: relative; }
.kpi-tooltip {
    display: none;
    position: absolute;
    bottom: calc(100% + 10px);
    left: 50%;
    transform: translateX(-50%);
    width: 210px;
    padding: 0.85rem 1rem;
    border-radius: 14px;
    z-index: 999;
    text-align: left;
    box-shadow: 0 12px 30px rgba(0,0,0,0.2);
    pointer-events: none;
    background: #0d1333;
    border: 1px solid rgba(139,92,246,0.35);
}
.kpi-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 7px solid transparent;
    border-top-color: #0d1333;
}
.kpi-card:hover .kpi-tooltip {
    display: block;
}
.kpi-tooltip-title {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: rgba(255,255,255,0.5);
    margin-bottom: 0.4rem;
}
.kpi-tooltip-formula {
    font-size: 0.8rem;
    font-weight: 600;
    color: #ffffff;
    margin-bottom: 0.5rem;
    line-height: 1.4;
}
.kpi-tooltip-divider {
    height: 1px;
    background: rgba(255,255,255,0.1);
    margin-bottom: 0.5rem;
}
.kpi-tooltip-result {
    font-size: 1.4rem;
    font-weight: 900;
    color: #ffffff;
    margin-bottom: 0.2rem;
}
.kpi-tooltip-desc {
    font-size: 0.72rem;
    color: rgba(255,255,255,0.6);
    line-height: 1.4;
}

/* Chart Cards */
.chart-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 22px;
    padding: 1.75rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.04);
}

.chart-title {
    font-weight: 800;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1.25rem;
}

/* Data Table */
.report-table-container {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.04);
}

.report-table-header {
    padding: 1.25rem 1.75rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.report-table {
    width: 100%;
}

.report-table th {
    background: var(--bg-body);
    color: var(--text-muted);
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 0.9rem 1rem;
    border-bottom: 1px solid var(--border-color);
    font-weight: 700;
    white-space: nowrap;
}

.report-table td {
    padding: 1rem;
    border-bottom: 1px solid var(--border-color);
    font-size: 0.88rem;
    vertical-align: middle;
    color: var(--text-main);
}

.report-table tbody tr {
    transition: background 0.15s ease;
}

.report-table tbody tr:hover {
    background: var(--primary-light);
}

.status-pill {
    font-size: 0.7rem;
    font-weight: 700;
    padding: 0.3rem 0.75rem;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.status-realizado { background: rgba(16,185,129,0.12); color: #10b981; }
.status-pendente  { background: rgba(245,158,11,0.12); color: #f59e0b; }
.status-cancelado { background: rgba(239,68,68,0.12); color: #ef4444; }
.status-outro     { background: rgba(100,116,139,0.12); color: #64748b; }

/* Export Buttons */
.btn-export {
    font-size: 0.82rem;
    font-weight: 700;
    padding: 0.5rem 1.2rem;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    transition: all 0.2s ease;
}

.btn-export:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn-export-excel { background: #10b981; color: white; border: none; }
.btn-export-excel:hover { background: #059669; color: white; }

.btn-export-csv { background: var(--primary); color: white; border: none; }
.btn-export-csv:hover { background: #3a56d4; color: white; }

.btn-export-print { background: var(--bg-body); color: var(--text-main); border: 1px solid var(--border-color); }
.btn-export-print:hover { background: var(--border-color); color: var(--text-main); }

/* Animations */
.gsap-reveal { opacity: 0; transform: translateY(25px); }

/* Responsive Table */
.table-scroll { max-height: 600px; overflow-y: auto; }
.table-scroll::-webkit-scrollbar { width: 6px; }
.table-scroll::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }

/* Period Tag */
.period-tag {
    background: var(--primary-light);
    color: var(--primary);
    font-size: 0.72rem;
    font-weight: 700;
    padding: 0.4rem 1rem;
    border-radius: 8px;
    border: 1px solid rgba(67,97,238,0.15);
}

/* Print Styles */
@media print {
    /* Layout */
    #sidebar, .filter-panel, .btn-export, .report-header .d-flex.gap-2 { display: none !important; }

    #content { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
    body { background: #fff !important; color: #000 !important; }

    /* Corrige título cortado: desativa o gradiente clip que não renderiza em PDF */
    .title-accent {
        background: transparent !important;
        -webkit-text-fill-color: initial !important;
        color: #4361ee !important;
    }

    /* Cards e gráficos (não quebrar no meio) */
    .kpi-card, .chart-card { break-inside: avoid; page-break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd !important; }
    .report-table-container { box-shadow: none !important; border: 1px solid #ddd !important; }

    /* Remove o scroll da tabela para mostrar TODOS os registros na impressão */
    .table-scroll, .table-responsive {
        max-height: none !important;
        overflow: visible !important;
        height: auto !important;
    }

    /* Garante que a tabela completa apareça sem quebra de coluna */
    .report-table-container { break-inside: auto; }
    .report-table { width: 100% !important; }
    .report-table tr { break-inside: avoid; page-break-inside: avoid; }

    /* Rodapé da tabela — ocultar botões de exportação */
    .report-table-header .d-flex { display: none !important; }

    .gsap-reveal { opacity: 1 !important; transform: none !important; }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

<div class="report-wrapper">

    <!-- Header -->
    <div class="report-header d-flex justify-content-between align-items-end flex-wrap gap-3 gsap-reveal">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.8rem;">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Relatórios</li>
                </ol>
            </nav>
            <h2 class="fw-900 mb-1">Central de <span class="title-accent">Relatórios</span></h2>
            <p class="text-muted small mb-0">Análise detalhada de treinamentos, performance e evolução.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <span class="period-tag">
                <i class="bi bi-calendar3 me-1"></i>
                <?= date('d/m/Y', strtotime($filtro_data_inicio)) ?> — <?= date('d/m/Y', strtotime($filtro_data_fim)) ?>
            </span>
        </div>
    </div>

    <!-- Filtros -->
    <div class="filter-panel gsap-reveal">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label small fw-bold text-muted text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.5px;">Data Início</label>
                <input type="date" name="data_inicio" class="form-control" value="<?= htmlspecialchars($filtro_data_inicio) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-muted text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.5px;">Data Fim</label>
                <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($filtro_data_fim) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-muted text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.5px;">Vendedor</label>
                <select name="vendedor" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($vendedores_lista as $v): ?>
                        <option value="<?= htmlspecialchars($v) ?>" <?= $filtro_vendedor === $v ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-muted text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.5px;">Servidor</label>
                <select name="servidor" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($servidores_lista as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= $filtro_servidor === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-muted text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.5px;">Status</label>
                <select name="status" class="form-select">
                    <option value="">Todos</option>
                    <option value="PENDENTE" <?= $filtro_status === 'PENDENTE' ? 'selected' : '' ?>>Pendente</option>
                    <option value="REALIZADO" <?= $filtro_status === 'REALIZADO' ? 'selected' : '' ?>>Realizado</option>
                    <option value="RESOLVIDO" <?= $filtro_status === 'RESOLVIDO' ? 'selected' : '' ?>>Resolvido</option>
                    <option value="CANCELADO" <?= $filtro_status === 'CANCELADO' ? 'selected' : '' ?>>Cancelado</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill fw-bold" style="height: 46px; border-radius: 12px;">
                    <i class="bi bi-funnel me-1"></i> Filtrar
                </button>
                <a href="relatorios.php" class="btn btn-outline-secondary d-flex align-items-center justify-content-center" style="height: 46px; width: 46px; border-radius: 12px;" title="Limpar filtros">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- KPIs -->
    <div class="row g-4 mb-4">
        <div class="col-6 col-lg-3 gsap-reveal">
            <div class="kpi-card kpi-total">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label mb-2">Total no Período</div>
                        <div class="kpi-value text-primary"><?= $kpi_total ?></div>
                    </div>
                    <div class="kpi-icon" style="background: var(--primary-light); color: var(--primary);">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3 gsap-reveal">
            <div class="kpi-card kpi-done">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label mb-2">Realizados</div>
                        <div class="kpi-value text-success"><?= $kpi_realizados ?></div>
                    </div>
                    <div class="kpi-icon" style="background: rgba(16,185,129,0.1); color: #10b981;">
                        <i class="bi bi-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3 gsap-reveal">
            <div class="kpi-card kpi-pending">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label mb-2">Pendentes</div>
                        <div class="kpi-value" style="color: #f59e0b;"><?= $kpi_pendentes ?></div>
                    </div>
                    <div class="kpi-icon" style="background: rgba(245,158,11,0.1); color: #f59e0b;">
                        <i class="bi bi-clock-history"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3 gsap-reveal">
            <div class="kpi-card kpi-rate">
                <div class="kpi-tooltip">
                    <div class="kpi-tooltip-title"><i class="bi bi-calculator me-1"></i>Cálculo</div>
                    <div class="kpi-tooltip-formula">Realizados &divide; Total no Período</div>
                    <div class="kpi-tooltip-divider"></div>
                    <div class="kpi-tooltip-result" style="color: #a78bfa;"><?= $kpi_taxa_resolucao ?>%</div>
                    <div class="kpi-tooltip-desc"><?= $kpi_total ?> treinamentos &rarr; <?= $kpi_realizados ?> concluídos</div>
                </div>
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label mb-2">Taxa de Resolução <i class="bi bi-question-circle ms-1 opacity-50" style="font-size: 0.6rem;"></i></div>
                        <div class="kpi-value" style="color: var(--purple);"><?= $kpi_taxa_resolucao ?>%</div>
                    </div>
                    <div class="kpi-icon" style="background: rgba(139,92,246,0.1); color: #8b5cf6;">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Gráficos -->
    <div class="row g-4 mb-4">
        <!-- Evolução Mensal -->
        <div class="col-lg-8 gsap-reveal">
            <div class="chart-card">
                <div class="chart-title">
                    <i class="bi bi-bar-chart-line text-primary"></i>
                    Evolução Mensal de Treinamentos
                    <span class="badge bg-primary bg-opacity-10 text-primary ms-auto" style="font-size: 0.65rem; font-weight: 700;">Últimos 12 meses</span>
                </div>
                <div id="chart-evolucao" style="min-height: 340px;"></div>
            </div>
        </div>

        <!-- Comparativo por Vendedor -->
        <div class="col-lg-4 gsap-reveal">
            <div class="chart-card">
                <div class="chart-title">
                    <i class="bi bi-people text-success"></i>
                    Por Vendedor
                </div>
                <div id="chart-vendedor" style="min-height: 340px;"></div>
            </div>
        </div>
    </div>

    <!-- Top Temas -->
    <?php if (!empty($temas_labels)): ?>
    <div class="row g-4 mb-4">
        <div class="col-12 gsap-reveal">
            <div class="chart-card">
                <div class="chart-title">
                    <i class="bi bi-bookmark-star text-warning"></i>
                    Temas Mais Abordados
                    <span class="period-tag ms-auto" style="font-size: 0.65rem;">Período filtrado</span>
                </div>
                <div id="chart-temas" style="min-height: 300px;"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabela Detalhada -->
    <div class="report-table-container gsap-reveal">
        <div class="report-table-header">
            <div>
                <h5 class="fw-800 mb-1 d-flex align-items-center gap-2">
                    <i class="bi bi-table text-primary"></i>
                    Detalhamento de Treinamentos
                </h5>
                <span class="text-muted small"><?= $kpi_total ?> registro(s) encontrado(s)</span>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php
                // Montar query string dos filtros atuais para exportação
                $export_params = http_build_query(array_filter([
                    'data_inicio' => $filtro_data_inicio,
                    'data_fim'    => $filtro_data_fim,
                    'vendedor'    => $filtro_vendedor,
                    'servidor'    => $filtro_servidor,
                    'status'      => $filtro_status
                ]));
                ?>
                <a href="relatorios.php?<?= $export_params ?>&exportar_xls=1" class="btn btn-export btn-export-excel">
                    <i class="bi bi-file-earmark-excel"></i> Excel
                </a>
                <a href="relatorios.php?<?= $export_params ?>&exportar_csv=1" class="btn btn-export btn-export-csv">
                    <i class="bi bi-filetype-csv"></i> CSV
                </a>
                <button class="btn btn-export btn-export-print" onclick="window.print()">
                    <i class="bi bi-printer"></i> Imprimir
                </button>
            </div>
        </div>

        <div class="table-responsive table-scroll">
            <table class="report-table">
                <thead>
                    <tr>
                        <th class="ps-4">ID</th>
                        <th>Data</th>
                        <th>Cliente</th>
                        <th>Vendedor</th>
                        <th>Servidor</th>
                        <th>Contato</th>
                        <th>Tema</th>
                        <th>Status</th>
                        <th>Observação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($treinamentos)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <i class="bi bi-inbox display-4 text-muted d-block mb-3 opacity-25"></i>
                                <h6 class="text-muted">Nenhum treinamento encontrado para o período selecionado.</h6>
                                <p class="text-muted small">Ajuste os filtros de data ou limpe os filtros para ver todos os registros.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($treinamentos as $t):
                            $st = strtoupper($t['status'] ?? '');
                            $status_class = 'status-outro';
                            if ($st === 'REALIZADO' || $st === 'RESOLVIDO') $status_class = 'status-realizado';
                            elseif ($st === 'PENDENTE') $status_class = 'status-pendente';
                            elseif ($st === 'CANCELADO') $status_class = 'status-cancelado';
                        ?>
                            <tr>
                                <td class="ps-4"><span class="fw-bold text-muted">#<?= (int)$t['id_treinamento'] ?></span></td>
                                <td>
                                    <div class="fw-bold"><?= !empty($t['data_treinamento']) ? date('d/m/Y', strtotime($t['data_treinamento'])) : '—' ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?= !empty($t['data_treinamento']) ? date('H:i', strtotime($t['data_treinamento'])) : '' ?></div>
                                </td>
                                <td class="fw-bold"><?= htmlspecialchars($t['fantasia'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($t['vendedor'] ?? '—') ?></td>
                                <td><span class="badge bg-light text-dark border" style="font-size: 0.72rem;"><?= htmlspecialchars($t['servidor'] ?? '—') ?></span></td>
                                <td><?= htmlspecialchars($t['contato_nome'] ?? '—') ?></td>
                                <td class="text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($t['tema'] ?? '') ?>"><?= htmlspecialchars($t['tema'] ?? '—') ?></td>
                                <td><span class="status-pill <?= $status_class ?>"><?= htmlspecialchars($t['status'] ?? '—') ?></span></td>
                                <td class="text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($t['observacoes'] ?? '') ?>"><?= htmlspecialchars($t['observacoes'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // ─── CHART 1: Evolução Mensal ──────────────────────────────────────
    const evolucaoOptions = {
        series: [
            { name: 'Realizados', data: <?= json_encode($evolucao_realizados) ?> },
            { name: 'Pendentes',  data: <?= json_encode($evolucao_pendentes) ?> }
        ],
        chart: {
            type: 'bar',
            height: 340,
            stacked: false,
            toolbar: { show: false },
            foreColor: 'var(--text-muted)',
            background: 'transparent'
        },
        colors: ['#10b981', '#f59e0b'],
        plotOptions: {
            bar: {
                borderRadius: 6,
                columnWidth: '55%',
                dataLabels: { position: 'top' }
            }
        },
        dataLabels: {
            enabled: true,
            offsetY: -20,
            style: { fontSize: '11px', fontWeight: 700, colors: ['var(--text-muted)'] }
        },
        stroke: { show: true, width: 2, colors: ['transparent'] },
        xaxis: {
            categories: <?= json_encode($evolucao_labels) ?>,
            axisBorder: { show: false },
            axisTicks: { show: false }
        },
        yaxis: { labels: { offsetX: -10 } },
        grid: {
            borderColor: 'rgba(128,128,128,0.1)',
            strokeDashArray: 4
        },
        legend: {
            position: 'top',
            horizontalAlign: 'right',
            fontSize: '12px',
            fontWeight: 600
        },
        tooltip: { theme: 'dark', y: { formatter: v => v + ' treinamentos' } }
    };

    const evolucaoEl = document.querySelector("#chart-evolucao");
    if (evolucaoEl) new ApexCharts(evolucaoEl, evolucaoOptions).render();

    // ─── CHART 2: Comparativo por Vendedor ─────────────────────────────
    const vendedorOptions = {
        series: [
            { name: 'Realizados', data: <?= json_encode($vend_realizados) ?> },
            { name: 'Pendentes',  data: <?= json_encode($vend_pendentes) ?> }
        ],
        chart: {
            type: 'bar',
            height: 340,
            stacked: true,
            toolbar: { show: false },
            foreColor: 'var(--text-muted)',
            background: 'transparent'
        },
        colors: ['#10b981', '#f59e0b'],
        plotOptions: {
            bar: {
                horizontal: true,
                borderRadius: 5,
                barHeight: '60%'
            }
        },
        dataLabels: { enabled: false },
        xaxis: { categories: <?= json_encode($vend_labels) ?> },
        grid: {
            borderColor: 'rgba(128,128,128,0.1)',
            strokeDashArray: 4
        },
        legend: {
            position: 'top',
            fontSize: '11px',
            fontWeight: 600
        },
        tooltip: { theme: 'dark' }
    };

    const vendedorEl = document.querySelector("#chart-vendedor");
    if (vendedorEl && <?= json_encode(!empty($vend_labels)) ?>) {
        new ApexCharts(vendedorEl, vendedorOptions).render();
    } else if (vendedorEl) {
        vendedorEl.innerHTML = '<div class="text-center py-5 text-muted"><i class="bi bi-bar-chart opacity-25 d-block mb-2" style="font-size: 2rem;"></i><span class="small">Sem dados para o período.</span></div>';
    }

    // ─── CHART 3: Top Temas ────────────────────────────────────────────
    <?php if (!empty($temas_labels)): ?>
    const temasOptions = {
        series: [{ name: 'Ocorrências', data: <?= json_encode($temas_valores) ?> }],
        chart: {
            type: 'bar',
            height: 300,
            toolbar: { show: false },
            foreColor: 'var(--text-muted)',
            background: 'transparent'
        },
        colors: ['#4361ee'],
        plotOptions: {
            bar: {
                borderRadius: 8,
                horizontal: true,
                barHeight: '55%',
                distributed: true
            }
        },
        dataLabels: {
            enabled: true,
            style: { fontSize: '12px', fontWeight: 700 }
        },
        xaxis: { categories: <?= json_encode($temas_labels) ?> },
        yaxis: { labels: { maxWidth: 200 } },
        grid: {
            borderColor: 'rgba(128,128,128,0.1)',
            strokeDashArray: 4
        },
        legend: { show: false },
        tooltip: { theme: 'dark' },
        colors: ['#4361ee','#7209b7','#10b981','#f59e0b','#ef4444','#0ea5e9','#8b5cf6','#06b6d4']
    };

    const temasEl = document.querySelector("#chart-temas");
    if (temasEl) new ApexCharts(temasEl, temasOptions).render();
    <?php endif; ?>

    // ─── GSAP Animations ───────────────────────────────────────────────
    gsap.to(".gsap-reveal", {
        duration: 0.7,
        opacity: 1,
        y: 0,
        stagger: 0.08,
        ease: "power3.out"
    });
});
</script>

<?php include 'footer.php'; ?>
