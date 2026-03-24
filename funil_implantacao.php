<?php
require_once 'config.php';

$total_geral_clientes = $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
$total_vendedores = $pdo->query("SELECT COUNT(DISTINCT vendedor) FROM clientes WHERE vendedor IS NOT NULL AND vendedor != ''")->fetchColumn();
$total_cancelados = $pdo->query("SELECT COUNT(*) FROM clientes WHERE status = 'CANCELADA'")->fetchColumn();
$total_concluidos = $pdo->query("SELECT COUNT(*) FROM clientes WHERE status = 'CONCLUIDA'")->fetchColumn();
$total_ativos = $total_geral_clientes - $total_concluidos - $total_cancelados;

$taxa_andamento   = ($total_geral_clientes > 0) ? round(($total_ativos      / $total_geral_clientes) * 100, 1) : 0;
$taxa_conclusao   = ($total_geral_clientes > 0) ? round(($total_concluidos  / $total_geral_clientes) * 100, 1) : 0;
$taxa_cancelamento = ($total_geral_clientes > 0) ? round(($total_cancelados / $total_geral_clientes) * 100, 1) : 0;

$total_encerrados  = $total_concluidos + $total_cancelados;
$taxa_eficiencia   = ($total_encerrados > 0) ? round(($total_concluidos / $total_encerrados) * 100, 1) : 0;
$taxa_retencao     = ($total_geral_clientes > 0) ? round((($total_geral_clientes - $total_cancelados) / $total_geral_clientes) * 100, 1) : 0;

include 'header.php';
?>
<style>
/* Dashboard Cards */
.card-glass {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 22px;
    padding: 1.5rem;
    height: 100%;
}
/* All Funnel CSS from index.php */
/* Funnel Metric Tooltip */
.funnel-metric-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.funnel-metric-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
}
.funnel-tooltip {
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
}
.funnel-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 7px solid transparent;
}
.funnel-tooltip-success {
    background: #0d2b1e;
    border: 1px solid rgba(16,185,129,0.35);
}
.funnel-tooltip-success::after {
    border-top-color: #0d2b1e;
}
.funnel-tooltip-primary {
    background: #0d1333;
    border: 1px solid rgba(67,97,238,0.35);
}
.funnel-tooltip-primary::after {
    border-top-color: #0d1333;
}
.funnel-metric-card:hover .funnel-tooltip {
    display: block;
}
.funnel-tooltip-title {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: rgba(255,255,255,0.5);
    margin-bottom: 0.4rem;
}
.funnel-tooltip-formula {
    font-size: 0.8rem;
    font-weight: 600;
    color: #ffffff;
    margin-bottom: 0.5rem;
    line-height: 1.4;
}
.funnel-tooltip-divider {
    height: 1px;
    background: rgba(255,255,255,0.1);
    margin-bottom: 0.5rem;
}
.funnel-tooltip-result {
    font-size: 1.4rem;
    font-weight: 900;
    color: #ffffff;
    margin-bottom: 0.2rem;
}
.funnel-tooltip-desc {
    font-size: 0.72rem;
    color: rgba(255,255,255,0.6);
    line-height: 1.4;
}

/* Funnel Styles — CSS Redesign */
.funnel-visual {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0;
    padding: 1rem 0;
}

.funnel-layer {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 72px;
    color: white;
    font-weight: 800;
    transition: transform 0.3s ease, filter 0.3s ease;
    cursor: pointer;
}

.funnel-layer:hover {
    transform: scale(1.03);
    filter: brightness(1.1);
    z-index: 10;
}

.funnel-layer-1 {
    width: 100%;
    background: linear-gradient(135deg, #94a3b8, #64748b);
    clip-path: polygon(0 0, 100% 0, 92% 100%, 8% 100%);
    border-radius: 14px 14px 0 0;
}

.funnel-layer-2 {
    width: 92%;
    background: linear-gradient(135deg, #60a5fa, #2563eb);
    clip-path: polygon(0 0, 100% 0, 88% 100%, 12% 100%);
    margin-top: -2px;
}

.funnel-layer-3 {
    width: 80%;
    background: linear-gradient(135deg, #34d399, #16a34a);
    clip-path: polygon(0 0, 100% 0, 82% 100%, 18% 100%);
    margin-top: -2px;
}

.funnel-layer-exit {
    width: 50%;
    background: linear-gradient(135deg, #f87171, #dc2626);
    height: 52px;
    border-radius: 10px;
    margin-top: 12px;
    clip-path: none;
}

.funnel-layer .fl-label {
    position: absolute;
    left: 18%;
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    opacity: 0.9;
    white-space: nowrap;
}

.funnel-layer .fl-value {
    font-size: 1.6rem;
    font-weight: 900;
    line-height: 1;
    text-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

.funnel-layer .fl-percent {
    position: absolute;
    right: 18%;
    font-size: 0.9rem;
    font-weight: 800;
    opacity: 0.95;
    white-space: nowrap;
}

/* Adjust label positioning for smaller layers */
.funnel-layer-3 .fl-label { left: 22%; font-size: 0.58rem; }
.funnel-layer-3 .fl-percent { right: 22%; font-size: 0.82rem; }

.funnel-layer-exit .fl-label { left: 14px; font-size: 0.55rem; position: absolute; }
.funnel-layer-exit .fl-value { font-size: 1.3rem; }
.funnel-layer-exit .fl-percent { right: 14px; font-size: 0.78rem; position: absolute; }

.funnel-exit-label {
    font-size: 0.6rem;
    font-weight: 900;
    color: #ef4444;
    letter-spacing: 2px;
    margin-top: 4px;
}
.gsap-reveal { opacity: 0; transform: translateY(30px); }
</style>

<div class="container-fluid py-4" style="max-width: 1400px;">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-5 gsap-reveal">
        <div>
            <h1 class="h3 mb-2 fw-900"><i class="bi bi-funnel text-primary me-2"></i> Detalhes do Funil</h1>
            <p class="text-muted mb-0">Visão geral do ciclo de vida dos projetos</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
            <i class="bi bi-arrow-left me-2"></i> Voltar ao Dashboard
        </a>
    </div>

    <!-- Here is the HTML from index.php -->
    <div class="row g-4 mb-5">
        <div class="col-12 gsap-reveal">
            <div class="card-glass">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-900 mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-funnel-fill text-primary"></i>
                        Funil de Implantação
                    </h5>
                    <div class="text-center">
                        <div class="small text-muted fw-bold text-uppercase" style="font-size: 0.65rem;">VENDEDORES</div>
                        <div class="fw-900 text-warning h5 mb-0"><?= $total_vendedores ?></div>
                    </div>
                </div>

                <div class="row align-items-center">
                    <!-- CSS Funnel Visualization -->
                    <div class="col-lg-7">
                        <div class="funnel-visual">
                            <!-- Layer 1: Iniciados -->
                            <div class="funnel-layer funnel-layer-1">
                                <span class="fl-label">Iniciados</span>
                                <span class="fl-value"><?= $total_geral_clientes ?></span>
                                <span class="fl-percent">100%</span>
                            </div>

                            <!-- Layer 2: Em Andamento -->
                            <div class="funnel-layer funnel-layer-2">
                                <span class="fl-label">Em Andamento</span>
                                <span class="fl-value"><?= $total_ativos ?></span>
                                <span class="fl-percent"><?= $taxa_andamento ?>%</span>
                            </div>

                            <!-- Layer 3: Concluídos -->
                            <div class="funnel-layer funnel-layer-3">
                                <span class="fl-label">Concluídos</span>
                                <span class="fl-value"><?= $total_concluidos ?></span>
                                <span class="fl-percent"><?= $taxa_conclusao ?>%</span>
                            </div>

                            <!-- Layer 4: Cancelados (saída) -->
                            <div class="funnel-layer funnel-layer-exit">
                                <span class="fl-label">Cancelados</span>
                                <span class="fl-value"><?= $total_cancelados ?></span>
                                <span class="fl-percent"><?= $taxa_cancelamento ?>%</span>
                            </div>
                            <div class="funnel-exit-label">▼ SAÍDA</div>
                        </div>
                    </div>

                    <!-- Funnel Info Cards -->
                    <div class="col-lg-5">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="p-3 rounded-4 border border-secondary border-opacity-10 bg-secondary bg-opacity-10 text-center">
                                    <div class="small fw-bold text-muted mb-1">TOTAL</div>
                                    <div class="h3 fw-900 mb-0 text-secondary"><?= $total_geral_clientes ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 rounded-4 border border-primary border-opacity-10 bg-primary bg-opacity-10 text-center">
                                    <div class="small fw-bold text-primary mb-1">ATIVOS</div>
                                    <div class="h3 fw-900 mb-0 text-primary"><?= $total_ativos ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 rounded-4 border border-success border-opacity-10 bg-success bg-opacity-10 text-center">
                                    <div class="small fw-bold text-success mb-1">SUCESSO</div>
                                    <div class="h3 fw-900 mb-0 text-success"><?= $total_concluidos ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 rounded-4 border border-danger border-opacity-10 bg-danger bg-opacity-10 text-center">
                                    <div class="small fw-bold text-danger mb-1">PERDA</div>
                                    <div class="h3 fw-900 mb-0 text-danger"><?= $total_cancelados ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 row g-2">
                            <!-- Card Eficiência -->
                            <div class="col-6">
                                <div class="funnel-metric-card p-3 rounded-4 border border-success border-opacity-25 text-center position-relative" style="background: rgba(16,185,129,0.07); cursor: help;">
                                    <div class="funnel-tooltip funnel-tooltip-success">
                                        <div class="funnel-tooltip-title"><i class="bi bi-calculator me-1"></i>Cálculo</div>
                                        <div class="funnel-tooltip-formula">Concluídos &divide; (Concluídos + Cancelados)</div>
                                        <div class="funnel-tooltip-divider"></div>
                                        <div class="funnel-tooltip-result text-success"><?= $taxa_eficiencia ?>%</div>
                                        <div class="funnel-tooltip-desc"><?= $total_encerrados ?> encerraram &rarr; <?= $total_concluidos ?> foram sucesso</div>
                                    </div>
                                    <div class="small fw-bold text-muted mb-1" style="font-size: 0.65rem;">EFICIÊNCIA <i class="bi bi-info-circle text-success opacity-75" style="font-size: 0.6rem;"></i></div>
                                    <div class="h4 fw-900 mb-0 text-success"><?= $taxa_eficiencia ?>%</div>
                                    <div class="text-muted" style="font-size: 0.62rem;">Dos encerramentos</div>
                                </div>
                            </div>
                            <!-- Card Retenção -->
                            <div class="col-6">
                                <div class="funnel-metric-card p-3 rounded-4 border border-primary border-opacity-25 text-center position-relative" style="background: rgba(67,97,238,0.07); cursor: help;">
                                    <div class="funnel-tooltip funnel-tooltip-primary">
                                        <div class="funnel-tooltip-title"><i class="bi bi-calculator me-1"></i>Cálculo</div>
                                        <div class="funnel-tooltip-formula">(Total &minus; Cancelados) &divide; Total</div>
                                        <div class="funnel-tooltip-divider"></div>
                                        <div class="funnel-tooltip-result text-primary"><?= $taxa_retencao ?>%</div>
                                        <div class="funnel-tooltip-desc">Apenas <?= $total_cancelados ?> cancelaram da base de <?= $total_geral_clientes ?></div>
                                    </div>
                                    <div class="small fw-bold text-muted mb-1" style="font-size: 0.65rem;">RETENÇÃO <i class="bi bi-info-circle text-primary opacity-75" style="font-size: 0.6rem;"></i></div>
                                    <div class="h4 fw-900 mb-0 text-primary"><?= $taxa_retencao ?>%</div>
                                    <div class="text-muted" style="font-size: 0.62rem;">Da base total</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    gsap.fromTo(".gsap-reveal", 
        { opacity: 0, y: 30 }, 
        { duration: 0.8, opacity: 1, y: 0, stagger: 0.1, ease: "power3.out" }
    );
});
</script>

<?php include 'footer.php'; ?>
