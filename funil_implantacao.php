<?php
require_once 'config.php';

// CÁLCULO DAS MÉTRICAS DO FUNIL
$sql = "SELECT
    COUNT(*) AS total_iniciados,
    SUM(CASE WHEN (data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao NOT LIKE '%CANCELADO%') THEN 1 ELSE 0 END) as total_concluidos,
    SUM(CASE WHEN (data_fim IS NULL OR data_fim = '0000-00-00') THEN 1 ELSE 0 END) as total_andamento,
    SUM(CASE WHEN (data_fim IS NOT NULL AND data_fim != '0000-00-00' AND observacao LIKE '%CANCELADO%') THEN 1 ELSE 0 END) as total_cancelados
FROM clientes";

$stmt = $pdo->query($sql);
$dados = $stmt->fetch(PDO::FETCH_ASSOC);

$total_iniciados = (int)$dados['total_iniciados'];
$total_concluidos = (int)$dados['total_concluidos'];
$total_andamento = (int)$dados['total_andamento'];
$total_cancelados = (int)$dados['total_cancelados'];

// Cálculo das taxas
$taxa_conclusao   = ($total_iniciados > 0) ? round(($total_concluidos  / $total_iniciados) * 100, 1) : 0;
$taxa_andamento   = ($total_iniciados > 0) ? round(($total_andamento   / $total_iniciados) * 100, 1) : 0;
$taxa_cancelamento = ($total_iniciados > 0) ? round(($total_cancelados / $total_iniciados) * 100, 1) : 0;

include 'header.php';
?>

<style>
/* Design Premium para o Funil */
.funnel-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 2rem;
    background: var(--bg-card);
    border-radius: 24px;
    border: 1px solid var(--border-color);
    box-shadow: 0 15px 35px rgba(0,0,0,0.05);
}

.funnel-stage {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 5px;
    position: relative;
    transition: transform 0.3s ease;
}

.funnel-stage:hover {
    transform: scale(1.02);
    z-index: 10;
}

.funnel-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 800;
    font-size: 1.25rem;
    height: 80px;
    border-radius: 12px;
    position: relative;
    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    overflow: visible;
}

.funnel-stage:nth-child(1) .funnel-bar {
    width: 100%;
    background: linear-gradient(135deg, #94a3b8, #64748b);
    clip-path: polygon(0 0, 100% 0, 95% 100%, 5% 100%);
}

.funnel-stage:nth-child(2) .funnel-bar {
    width: 85%;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    clip-path: polygon(0 0, 100% 0, 88% 100%, 12% 100%);
}

.funnel-stage:nth-child(3) .funnel-bar {
    width: 60%;
    background: linear-gradient(135deg, #22c55e, #16a34a);
    clip-path: polygon(0 0, 100% 0, 85% 100%, 15% 100%);
}

.funnel-stage-lost {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 2px dashed var(--border-color);
}

.funnel-bar-lost {
    width: 40%;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 800;
    font-size: 1.1rem;
    box-shadow: 0 10px 20px rgba(239,68,68,0.2);
}

.funnel-label {
    position: absolute;
    left: -170px;
    width: 150px;
    text-align: right;
    font-weight: 700;
    color: var(--text-muted);
    font-size: 0.9rem;
    text-transform: uppercase;
    top: 50%;
    transform: translateY(-50%);
}

.funnel-percent {
    position: absolute;
    right: -110px;
    width: 100px;
    text-align: left;
    font-weight: 900;
    font-size: 1.3rem;
    color: var(--text-main);
    top: 50%;
    transform: translateY(-50%);
}

.kpi-card-funnel {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 1.5rem;
    text-align: center;
    transition: all 0.3s ease;
    height: 100%;
}
.kpi-card-funnel:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.08);
}
.kpi-value {
    font-size: 2.5rem;
    font-weight: 900;
    margin-bottom: 0.2rem;
}
.kpi-title {
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-muted);
    font-weight: 700;
}

@media(max-width: 900px) {
    .funnel-label, .funnel-percent {
        position: static;
        transform: none;
        width: auto;
        text-align: center;
        margin: 5px 0;
    }
    .funnel-stage {
        margin-bottom: 2rem;
    }
}
</style>

<div class="container-fluid py-4" style="max-width: 1400px;">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-5 gsap-reveal">
        <div>
            <h1 class="h3 mb-2 fw-900"><i class="bi bi-funnel text-primary me-2"></i> Funil de Implantação</h1>
            <p class="text-muted mb-0">Visão geral do ciclo de vida dos projetos</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
            <i class="bi bi-arrow-left me-2"></i> Voltar ao Dashboard
        </a>
    </div>

    <!-- KPIs -->
    <div class="row g-4 mb-5">
        <div class="col-md-3 gsap-reveal" style="transition-delay: 0.1s">
            <div class="kpi-card-funnel" style="border-top: 4px solid #94a3b8">
                <div class="kpi-title mb-3">Total Iniciados</div>
                <div class="kpi-value text-secondary"><?= $total_iniciados ?></div>
                <div class="small text-muted">100% da base</div>
            </div>
        </div>
        <div class="col-md-3 gsap-reveal" style="transition-delay: 0.2s">
            <div class="kpi-card-funnel" style="border-top: 4px solid #3b82f6">
                <div class="kpi-title mb-3">Em Andamento</div>
                <div class="kpi-value text-primary"><?= $taxa_andamento ?>%</div>
                <div class="small text-muted"><?= $total_andamento ?> clientes</div>
            </div>
        </div>
        <div class="col-md-3 gsap-reveal" style="transition-delay: 0.3s">
            <div class="kpi-card-funnel" style="border-top: 4px solid #22c55e">
                <div class="kpi-title mb-3">Concluídos (Sucesso)</div>
                <div class="kpi-value text-success"><?= $taxa_conclusao ?>%</div>
                <div class="small text-muted"><?= $total_concluidos ?> clientes</div>
            </div>
        </div>
        <div class="col-md-3 gsap-reveal" style="transition-delay: 0.4s">
            <div class="kpi-card-funnel" style="border-top: 4px solid #ef4444">
                <div class="kpi-title mb-3">Cancelados (Perda)</div>
                <div class="kpi-value text-danger"><?= $taxa_cancelamento ?>%</div>
                <div class="small text-muted"><?= $total_cancelados ?> clientes</div>
            </div>
        </div>
    </div>

    <!-- Representação Visual do Funil -->
    <div class="row mb-5">
        <div class="col-12 gsap-reveal" style="transition-delay: 0.5s">
            <div class="funnel-container text-center pt-5 pb-5">
                <div class="fw-bold text-muted mb-5 text-uppercase letter-spacing-2">Representação Visual do Fluxo</div>
                
                <div class="funnel-stage" title="<?= $total_iniciados ?> clientes entraram no funil">
                    <span class="funnel-label">Iniciados</span>
                    <div class="funnel-bar">
                        <?= $total_iniciados ?> <i class="bi bi-people-fill ms-2"></i>
                    </div>
                    <span class="funnel-percent">100%</span>
                </div>
                
                <div class="funnel-stage" title="<?= $total_andamento ?> clientes estão em processo de implantação">
                    <span class="funnel-label text-primary">Em Andamento</span>
                    <div class="funnel-bar">
                        <?= $total_andamento ?> <i class="bi bi-arrow-repeat ms-2"></i>
                    </div>
                    <span class="funnel-percent text-primary"><?= $taxa_andamento ?>%</span>
                </div>

                <div class="funnel-stage" title="<?= $total_concluidos ?> clientes finalizaram com sucesso">
                    <span class="funnel-label text-success">Concluídos</span>
                    <div class="funnel-bar">
                        <?= $total_concluidos ?> <i class="bi bi-check-circle-fill ms-2"></i>
                    </div>
                    <span class="funnel-percent text-success"><?= $taxa_conclusao ?>%</span>
                </div>

                <div class="funnel-stage-lost" title="<?= $total_cancelados ?> clientes cancelaram a implantação">
                     <span class="fw-bold text-muted small text-uppercase mb-2">Saída (Lost)</span>
                     <div class="funnel-bar-lost">
                        <?= $total_cancelados ?> <i class="bi bi-x-circle-fill ms-2"></i>
                     </div>
                     <div class="mt-2 fw-900 text-danger" style="font-size: 1.2rem;"><?= $taxa_cancelamento ?>%</div>
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
