<?php
require_once 'config.php';

function treinamentoEfetivoDefinicoes()
{
    return [
        [
            'slug' => 'produtos',
            'titulo' => 'Produtos',
            'icone' => 'bi-box-seam',
            'descricao' => 'Avalia se o cliente consegue cadastrar produtos reais com dados completos e sem retrabalho.',
            'meta' => 20,
            'regra' => 'Cadastro consistente de itens reais com preco, unidade e tributacao basica revisados.'
        ],
        [
            'slug' => 'clientes',
            'titulo' => 'Clientes',
            'icone' => 'bi-people',
            'descricao' => 'Mede o cadastro de clientes reais com os campos minimos necessarios para venda e faturamento.',
            'meta' => 10,
            'regra' => 'Cadastro completo de clientes reais, incluindo dados de contato e fiscais quando aplicavel.'
        ],
        [
            'slug' => 'orcamento',
            'titulo' => 'Orcamento',
            'icone' => 'bi-file-earmark-text',
            'descricao' => 'Verifica se o cliente gera propostas reais sozinho e converte o fluxo comercial em rotina.',
            'meta' => 3,
            'regra' => 'Faz parte do fluxo comercial. Pode ser substituido por PDV se esse for o processo principal.'
        ],
        [
            'slug' => 'pdv',
            'titulo' => 'Venda no PDV',
            'icone' => 'bi-upc-scan',
            'descricao' => 'Confirma se o cliente registra vendas reais no caixa sem depender do implantador.',
            'meta' => 5,
            'regra' => 'Faz parte do fluxo comercial. Basta Orcamento ou PDV estar pronto para cumprir essa etapa.'
        ],
        [
            'slug' => 'boleto',
            'titulo' => 'Boleto',
            'icone' => 'bi-receipt',
            'descricao' => 'Apura emissao de boleto valida, com conferencia do retorno e sem bloqueios operacionais.',
            'meta' => 2,
            'regra' => 'O cliente deve emitir boletos reais ou de homologacao sem ajuda direta.'
        ],
        [
            'slug' => 'nota_fiscal',
            'titulo' => 'Nota fiscal',
            'icone' => 'bi-file-earmark-medical',
            'descricao' => 'Valida emissao fiscal correta, incluindo tratamento de rejeicoes simples e conferencia do XML ou DANFE.',
            'meta' => 2,
            'regra' => 'Processo concluido apenas quando a nota sair corretamente e o cliente entender correcoes basicas.'
        ],
    ];
}

function garantirTabelaTreinamentoEfetivo(PDO $pdo)
{
    $sql = "CREATE TABLE IF NOT EXISTS treinamento_efetivo_cliente (
        id_avaliacao INT AUTO_INCREMENT PRIMARY KEY,
        id_cliente INT NOT NULL,
        processo VARCHAR(50) NOT NULL,
        nivel TINYINT(1) NOT NULL DEFAULT 0,
        meta_minima INT NOT NULL DEFAULT 0,
        uso_real INT NOT NULL DEFAULT 0,
        operacoes_suporte INT NOT NULL DEFAULT 0,
        erros_retrabalho INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_treinamento_efetivo_cliente_processo (id_cliente, processo),
        KEY idx_treinamento_efetivo_cliente (id_cliente)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $pdo->exec($sql);
}

function carregarAvaliacaoTreinamentoEfetivo(PDO $pdo, $idCliente, array $processos)
{
    $avaliacoes = [];
    foreach ($processos as $processo) {
        $avaliacoes[$processo['slug']] = [
            'slug' => $processo['slug'],
            'titulo' => $processo['titulo'],
            'icone' => $processo['icone'],
            'descricao' => $processo['descricao'],
            'regra' => $processo['regra'],
            'nivel' => 0,
            'meta_minima' => (int) $processo['meta'],
            'uso_real' => 0,
            'operacoes_suporte' => 0,
            'erros_retrabalho' => 0,
            'updated_at' => null,
        ];
    }

    $stmt = $pdo->prepare("SELECT processo, nivel, meta_minima, uso_real, operacoes_suporte, erros_retrabalho, updated_at
                           FROM treinamento_efetivo_cliente
                           WHERE id_cliente = ?");
    $stmt->execute([$idCliente]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slug = (string) ($row['processo'] ?? '');
        if (!isset($avaliacoes[$slug])) {
            continue;
        }

        $avaliacoes[$slug]['nivel'] = (int) ($row['nivel'] ?? 0);
        $avaliacoes[$slug]['meta_minima'] = (int) ($row['meta_minima'] ?? $avaliacoes[$slug]['meta_minima']);
        $avaliacoes[$slug]['uso_real'] = (int) ($row['uso_real'] ?? 0);
        $avaliacoes[$slug]['operacoes_suporte'] = (int) ($row['operacoes_suporte'] ?? 0);
        $avaliacoes[$slug]['erros_retrabalho'] = (int) ($row['erros_retrabalho'] ?? 0);
        $avaliacoes[$slug]['updated_at'] = $row['updated_at'] ?? null;
    }

    return $avaliacoes;
}

$processos = treinamentoEfetivoDefinicoes();
$id_cliente = isset($_REQUEST['id_cliente']) ? (int) $_REQUEST['id_cliente'] : 0;

if ($id_cliente <= 0) {
    header("Location: clientes.php");
    exit;
}

$stmtCliente = $pdo->prepare("SELECT id_cliente, fantasia, servidor, vendedor FROM clientes WHERE id_cliente = ?");
$stmtCliente->execute([$id_cliente]);
$cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    header("Location: clientes.php");
    exit;
}

try {
    garantirTabelaTreinamentoEfetivo($pdo);
} catch (Throwable $e) {
    header("Location: treinamentos_cliente.php?id_cliente=" . $id_cliente . "&msg=" . urlencode("Nao foi possivel preparar a estrutura do treinamento efetivo.") . "&tipo=danger");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nivelPost = $_POST['nivel'] ?? [];
    $metaPost = $_POST['meta_minima'] ?? [];
    $usoPost = $_POST['uso_real'] ?? [];
    $suportePost = $_POST['operacoes_suporte'] ?? [];
    $errosPost = $_POST['erros_retrabalho'] ?? [];

    try {
        $pdo->beginTransaction();

        $sql = "INSERT INTO treinamento_efetivo_cliente
                    (id_cliente, processo, nivel, meta_minima, uso_real, operacoes_suporte, erros_retrabalho)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    nivel = VALUES(nivel),
                    meta_minima = VALUES(meta_minima),
                    uso_real = VALUES(uso_real),
                    operacoes_suporte = VALUES(operacoes_suporte),
                    erros_retrabalho = VALUES(erros_retrabalho)";
        $stmtSave = $pdo->prepare($sql);

        foreach ($processos as $processo) {
            $slug = $processo['slug'];
            $nivel = max(0, min(4, (int) ($nivelPost[$slug] ?? 0)));
            $meta = max(0, (int) ($metaPost[$slug] ?? $processo['meta']));
            $uso = max(0, (int) ($usoPost[$slug] ?? 0));
            $suporte = max(0, min($uso, (int) ($suportePost[$slug] ?? 0)));
            $erros = max(0, min($uso, (int) ($errosPost[$slug] ?? 0)));

            $stmtSave->execute([$id_cliente, $slug, $nivel, $meta, $uso, $suporte, $erros]);
        }

        $pdo->commit();
        header("Location: treinamento_efetivo.php?id_cliente=" . $id_cliente . "&msg=" . urlencode("Avaliacao salva com sucesso.") . "&tipo=success");
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        header("Location: treinamento_efetivo.php?id_cliente=" . $id_cliente . "&msg=" . urlencode("Erro ao salvar a avaliacao: " . $e->getMessage()) . "&tipo=danger");
        exit;
    }
}

$avaliacoes = carregarAvaliacaoTreinamentoEfetivo($pdo, $id_cliente, $processos);
$ultimaAtualizacao = null;
foreach ($avaliacoes as $avaliacao) {
    if (!empty($avaliacao['updated_at']) && ($ultimaAtualizacao === null || strtotime($avaliacao['updated_at']) > strtotime($ultimaAtualizacao))) {
        $ultimaAtualizacao = $avaliacao['updated_at'];
    }
}

$flashMensagem = isset($_GET['msg']) ? trim((string) $_GET['msg']) : '';
$flashTipo = isset($_GET['tipo']) ? trim((string) $_GET['tipo']) : 'success';

include 'header.php';
?>

<style>
.training-shell {
    max-width: 1480px;
}

.hero-panel {
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.25), transparent 32%),
        radial-gradient(circle at bottom left, rgba(67, 97, 238, 0.22), transparent 30%),
        linear-gradient(145deg, #0f172a, #182848 58%, #223a7a);
    color: #fff;
    border-radius: 28px;
    padding: 2rem;
    box-shadow: 0 28px 60px rgba(15, 23, 42, 0.28);
}

.hero-panel::after {
    content: '';
    position: absolute;
    inset: auto -80px -80px auto;
    width: 220px;
    height: 220px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.05);
}

.hero-kicker {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    border: 1px solid rgba(255, 255, 255, 0.16);
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.88);
    padding: 0.45rem 0.9rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.hero-title {
    font-size: clamp(2rem, 3vw, 2.8rem);
    font-weight: 800;
    letter-spacing: -0.03em;
    margin: 1rem 0 0.75rem;
    color: #fff;
}

.hero-copy {
    max-width: 760px;
    font-size: 1rem;
    line-height: 1.7;
    color: rgba(255, 255, 255, 0.82);
    margin-bottom: 0;
}

.hero-meta {
    color: rgba(255, 255, 255, 0.78);
    font-size: 0.92rem;
}

.metric-tile,
.guide-card,
.process-card,
.summary-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 22px;
    box-shadow: var(--shadow-sm);
}

.metric-tile {
    padding: 1.35rem 1.4rem;
    height: 100%;
}

.metric-label {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    font-weight: 700;
}

.metric-value {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
    color: var(--text-dark);
    margin: 0.7rem 0 0.35rem;
}

.metric-helper {
    color: var(--text-muted);
    margin: 0;
    font-size: 0.92rem;
}

.guide-card,
.summary-card {
    padding: 1.5rem;
    height: 100%;
}

.guide-card h3,
.summary-card h3 {
    font-size: 1rem;
    font-weight: 800;
    margin-bottom: 1rem;
}

.scale-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    background: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 0.85rem 1rem;
    margin-bottom: 0.75rem;
}

.scale-badge {
    min-width: 44px;
    height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    font-size: 1rem;
    font-weight: 800;
    color: #fff;
}

.scale-0 { background: #6b7280; }
.scale-1 { background: #f59e0b; }
.scale-2 { background: #f97316; }
.scale-3 { background: #2563eb; }
.scale-4 { background: #10b981; }

.summary-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.summary-box {
    background: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: 18px;
    padding: 1rem;
}

.summary-box strong {
    display: block;
    font-size: 1.25rem;
    color: var(--text-dark);
}

.process-card {
    padding: 1.45rem;
    height: 100%;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.process-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-hover);
    border-color: rgba(67, 97, 238, 0.28);
}

.process-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.process-icon {
    width: 52px;
    height: 52px;
    border-radius: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--primary-light);
    color: var(--primary);
    font-size: 1.35rem;
}

.process-title {
    font-size: 1.15rem;
    font-weight: 800;
    margin-bottom: 0.25rem;
}

.process-copy {
    font-size: 0.92rem;
    color: var(--text-muted);
    line-height: 1.65;
    margin-bottom: 1rem;
}

.process-rule {
    font-size: 0.82rem;
    color: var(--text-muted);
    background: var(--bg-body);
    border: 1px dashed var(--border-color);
    border-radius: 16px;
    padding: 0.85rem 1rem;
    margin-bottom: 1rem;
}

.process-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.9rem;
}

.field-block label {
    display: block;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    margin-bottom: 0.45rem;
    font-weight: 700;
}

.field-block .form-control,
.field-block .form-select {
    border-radius: 14px;
    min-height: 48px;
    border-color: var(--border-color);
    background: var(--bg-body);
}

.process-footer {
    margin-top: 1.2rem;
}

.mini-progress {
    height: 10px;
    background: var(--bg-body);
    border-radius: 999px;
    overflow: hidden;
    border: 1px solid var(--border-color);
}

.mini-progress-bar {
    height: 100%;
    width: 0;
    border-radius: inherit;
    background: linear-gradient(90deg, #2563eb, #10b981);
    transition: width 0.25s ease;
}

.stats-line {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    margin-top: 0.9rem;
}

.stat-pill {
    border-radius: 999px;
    padding: 0.4rem 0.75rem;
    font-size: 0.8rem;
    font-weight: 700;
    background: var(--bg-body);
    color: var(--text-muted);
    border: 1px solid var(--border-color);
}

.status-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    border-radius: 999px;
    padding: 0.45rem 0.8rem;
    font-size: 0.78rem;
    font-weight: 800;
}

.status-pending {
    background: rgba(245, 158, 11, 0.14);
    color: #b45309;
}

.status-progress {
    background: rgba(37, 99, 235, 0.14);
    color: #1d4ed8;
}

.status-ready {
    background: rgba(16, 185, 129, 0.14);
    color: #047857;
}

.table-shell {
    overflow-x: auto;
}

.table-scorecard {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.table-scorecard th,
.table-scorecard td {
    padding: 0.95rem 1rem;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}

.table-scorecard th {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    font-weight: 800;
}

.score-cell {
    font-weight: 800;
    color: var(--text-dark);
}

.action-strip {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.sticky-save {
    position: sticky;
    bottom: 16px;
    z-index: 10;
}

@media (max-width: 991px) {
    .summary-grid,
    .process-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="container-fluid py-4 training-shell">
    <div class="hero-panel mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <span class="hero-kicker"><i class="bi bi-activity"></i> Treinamento efetivo por cliente</span>
                <h1 class="hero-title"><?= htmlspecialchars($cliente['fantasia'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="hero-copy">
                    Registre a maturidade operacional do cliente nos processos criticos que determinam o encerramento da implantacao.
                    O foco e evidenciar uso real, autonomia e qualidade, nao apenas carga horaria de treinamento.
                </p>
                <div class="d-flex flex-wrap gap-4 mt-3 hero-meta">
                    <span><i class="bi bi-fingerprint me-1"></i>ID #<?= (int) $cliente['id_cliente'] ?></span>
                    <span><i class="bi bi-cpu me-1"></i>Servidor: <?= htmlspecialchars((string) ($cliente['servidor'] ?: '---'), ENT_QUOTES, 'UTF-8') ?></span>
                    <span><i class="bi bi-person-badge me-1"></i>Vendedor: <?= htmlspecialchars((string) ($cliente['vendedor'] ?: '---'), ENT_QUOTES, 'UTF-8') ?></span>
                    <span><i class="bi bi-clock-history me-1"></i>Ultima atualizacao: <?= $ultimaAtualizacao ? date('d/m/Y H:i', strtotime($ultimaAtualizacao)) : 'Nao registrada' ?></span>
                </div>
            </div>
            <div class="action-strip">
                <a href="treinamentos_cliente.php?id_cliente=<?= (int) $id_cliente ?>" class="btn btn-outline-light px-4 fw-bold">
                    <i class="bi bi-arrow-left me-2"></i>Voltar para ficha
                </a>
                <button type="submit" form="form-treinamento-efetivo" class="btn btn-warning px-4 fw-bold text-dark">
                    <i class="bi bi-save me-2"></i>Salvar avaliacao
                </button>
            </div>
        </div>
    </div>

    <?php if ($flashMensagem !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($flashTipo, ENT_QUOTES, 'UTF-8') ?> border-0 rounded-4 shadow-sm mb-4">
            <?= htmlspecialchars($flashMensagem, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="treinamento_efetivo.php?id_cliente=<?= (int) $id_cliente ?>" id="form-treinamento-efetivo">
        <input type="hidden" name="id_cliente" value="<?= (int) $id_cliente ?>">

        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="metric-tile">
                    <div class="metric-label">Score geral</div>
                    <div class="metric-value" id="score-geral">0%</div>
                    <p class="metric-helper">Media ponderada dos processos monitorados.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="metric-tile">
                    <div class="metric-label">Processos prontos</div>
                    <div class="metric-value" id="processos-prontos">0/6</div>
                    <p class="metric-helper">Considera nivel, volume minimo, autonomia e qualidade.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="metric-tile">
                    <div class="metric-label">Autonomia media</div>
                    <div class="metric-value" id="autonomia-media">0%</div>
                    <p class="metric-helper">Quanto menor o suporte, maior a autonomia operacional.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="metric-tile">
                    <div class="metric-label">Pronto para encerrar</div>
                    <div class="metric-value" id="status-global">Nao</div>
                    <p class="metric-helper" id="status-global-copy">Ainda existem etapas criticas sem validacao suficiente.</p>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-4">
                <div class="guide-card">
                    <h3><i class="bi bi-signpost-split me-2 text-primary"></i>Regua de evolucao</h3>
                    <div class="scale-item">
                        <span class="scale-badge scale-0">0</span>
                        <div>
                            <strong class="d-block">Nao treinado</strong>
                            <small class="text-muted">Ainda nao executou o processo com seguranca.</small>
                        </div>
                    </div>
                    <div class="scale-item">
                        <span class="scale-badge scale-1">1</span>
                        <div>
                            <strong class="d-block">Com ajuda total</strong>
                            <small class="text-muted">Executa apenas com conducao direta do implantador.</small>
                        </div>
                    </div>
                    <div class="scale-item">
                        <span class="scale-badge scale-2">2</span>
                        <div>
                            <strong class="d-block">Com pouca ajuda</strong>
                            <small class="text-muted">Ja faz parte do fluxo, mas ainda depende de validacao.</small>
                        </div>
                    </div>
                    <div class="scale-item">
                        <span class="scale-badge scale-3">3</span>
                        <div>
                            <strong class="d-block">Executa sozinho</strong>
                            <small class="text-muted">Conclui o processo sem intervencao externa.</small>
                        </div>
                    </div>
                    <div class="scale-item mb-0">
                        <span class="scale-badge scale-4">4</span>
                        <div>
                            <strong class="d-block">Executa com consistencia</strong>
                            <small class="text-muted">Repete corretamente, com baixo retrabalho e previsibilidade.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="summary-card">
                    <h3><i class="bi bi-check2-square me-2 text-success"></i>Criterio para fechar 100%</h3>
                    <div class="summary-grid">
                        <div class="summary-box">
                            <small class="text-muted d-block mb-2">Obrigatorios</small>
                            <strong>Produtos, Clientes, Boleto e NF</strong>
                            <span class="text-muted small">Todos precisam estar prontos.</span>
                        </div>
                        <div class="summary-box">
                            <small class="text-muted d-block mb-2">Fluxo comercial</small>
                            <strong>Orcamento ou PDV</strong>
                            <span class="text-muted small">Basta um deles validado em uso real.</span>
                        </div>
                        <div class="summary-box">
                            <small class="text-muted d-block mb-2">Autonomia minima</small>
                            <strong>80%</strong>
                            <span class="text-muted small">No maximo 20% das operacoes com ajuda.</span>
                        </div>
                        <div class="summary-box">
                            <small class="text-muted d-block mb-2">Qualidade minima</small>
                            <strong>85%</strong>
                            <span class="text-muted small">Erros e retrabalho precisam cair para baixo nivel.</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="summary-card">
                    <h3><i class="bi bi-sliders2 me-2 text-warning"></i>Orientacao de preenchimento</h3>
                    <p class="text-muted mb-3">
                        Use esta avaliacao sempre em contexto do cliente atual. Salve ao final de cada bloco de treinamento,
                        fechamento semanal ou validacao operacional em ambiente real.
                    </p>
                    <div class="summary-box mb-3">
                        <small class="text-muted d-block mb-2">Leitura sugerida</small>
                        <strong id="leitura-geral">Cliente ainda depende de treinamento assistido.</strong>
                        <span class="text-muted small d-block mt-2" id="leitura-geral-copy">
                            Preencha os processos abaixo para identificar onde esta o gargalo de implantacao.
                        </span>
                    </div>
                    <div class="action-strip">
                        <button type="button" class="btn btn-outline-secondary px-4 fw-bold" id="limpar-avaliacao">
                            <i class="bi bi-eraser me-2"></i>Limpar tela
                        </button>
                        <button type="submit" class="btn btn-primary px-4 fw-bold">
                            <i class="bi bi-save me-2"></i>Salvar agora
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <?php foreach ($processos as $processo):
                $avaliacao = $avaliacoes[$processo['slug']];
            ?>
                <div class="col-xl-6">
                    <div class="process-card process-eval"
                        data-processo="<?= htmlspecialchars($processo['slug'], ENT_QUOTES, 'UTF-8') ?>"
                        data-titulo="<?= htmlspecialchars($processo['titulo'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="process-head">
                            <div class="d-flex gap-3">
                                <div class="process-icon">
                                    <i class="bi <?= htmlspecialchars($processo['icone'], ENT_QUOTES, 'UTF-8') ?>"></i>
                                </div>
                                <div>
                                    <div class="process-title"><?= htmlspecialchars($processo['titulo'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-muted small">Qtd. minima para validar: <strong><?= (int) $avaliacao['meta_minima'] ?></strong> operacoes</div>
                                </div>
                            </div>
                            <span class="status-chip status-pending status-processo">Sem evidencia</span>
                        </div>

                        <p class="process-copy"><?= htmlspecialchars($processo['descricao'], ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="process-rule"><?= htmlspecialchars($processo['regra'], ENT_QUOTES, 'UTF-8') ?></div>

                        <div class="process-grid">
                            <div class="field-block">
                                <label for="nivel_<?= $processo['slug'] ?>">Nivel atual</label>
                                <select class="form-select campo-nivel" id="nivel_<?= $processo['slug'] ?>" name="nivel[<?= htmlspecialchars($processo['slug'], ENT_QUOTES, 'UTF-8') ?>]">
                                    <option value="0" <?= (int) $avaliacao['nivel'] === 0 ? 'selected' : '' ?>>0 - Nao treinado</option>
                                    <option value="1" <?= (int) $avaliacao['nivel'] === 1 ? 'selected' : '' ?>>1 - Com ajuda total</option>
                                    <option value="2" <?= (int) $avaliacao['nivel'] === 2 ? 'selected' : '' ?>>2 - Com pouca ajuda</option>
                                    <option value="3" <?= (int) $avaliacao['nivel'] === 3 ? 'selected' : '' ?>>3 - Executa sozinho</option>
                                    <option value="4" <?= (int) $avaliacao['nivel'] === 4 ? 'selected' : '' ?>>4 - Executa com consistencia</option>
                                </select>
                            </div>

                            <div class="field-block">
                                <label for="meta_<?= $processo['slug'] ?>">Qtd. minima para validar</label>
                                <input type="number" min="0" step="1" class="form-control campo-meta" id="meta_<?= $processo['slug'] ?>" name="meta_minima[<?= htmlspecialchars($processo['slug'], ENT_QUOTES, 'UTF-8') ?>]" value="<?= (int) $avaliacao['meta_minima'] ?>">
                            </div>

                            <div class="field-block">
                                <label for="uso_<?= $processo['slug'] ?>">Uso real</label>
                                <input type="number" min="0" step="1" class="form-control campo-uso" id="uso_<?= $processo['slug'] ?>" name="uso_real[<?= htmlspecialchars($processo['slug'], ENT_QUOTES, 'UTF-8') ?>]" value="<?= (int) $avaliacao['uso_real'] ?>">
                            </div>

                            <div class="field-block">
                                <label for="suporte_<?= $processo['slug'] ?>">Operacoes com suporte</label>
                                <input type="number" min="0" step="1" class="form-control campo-suporte" id="suporte_<?= $processo['slug'] ?>" name="operacoes_suporte[<?= htmlspecialchars($processo['slug'], ENT_QUOTES, 'UTF-8') ?>]" value="<?= (int) $avaliacao['operacoes_suporte'] ?>">
                            </div>

                            <div class="field-block">
                                <label for="erros_<?= $processo['slug'] ?>">Erros ou retrabalho</label>
                                <input type="number" min="0" step="1" class="form-control campo-erros" id="erros_<?= $processo['slug'] ?>" name="erros_retrabalho[<?= htmlspecialchars($processo['slug'], ENT_QUOTES, 'UTF-8') ?>]" value="<?= (int) $avaliacao['erros_retrabalho'] ?>">
                            </div>
                        </div>

                        <div class="process-footer">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <small class="text-muted fw-bold text-uppercase">Prontidao do processo</small>
                                <strong class="score-processo">0%</strong>
                            </div>
                            <div class="mini-progress">
                                <div class="mini-progress-bar"></div>
                            </div>
                            <div class="stats-line">
                                <span class="stat-pill uso-processo">Uso: 0%</span>
                                <span class="stat-pill autonomia-processo">Autonomia: 0%</span>
                                <span class="stat-pill qualidade-processo">Qualidade: 0%</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="summary-card mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div>
                    <h3 class="mb-1"><i class="bi bi-table me-2 text-primary"></i>Resumo do scorecard</h3>
                    <p class="text-muted mb-0">Use esta tabela como pauta de follow-up semanal com o cliente.</p>
                </div>
                <span class="badge bg-body border text-muted px-3 py-2 rounded-pill" id="fluxo-comercial-badge">Fluxo comercial nao validado</span>
            </div>

            <div class="table-shell">
                <table class="table-scorecard">
                    <thead>
                        <tr>
                            <th>Processo</th>
                            <th>Nivel</th>
                            <th>Uso real</th>
                            <th>Autonomia</th>
                            <th>Qualidade</th>
                            <th>Score</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-scorecard">
                        <?php foreach ($processos as $processo):
                            $avaliacao = $avaliacoes[$processo['slug']];
                        ?>
                            <tr data-row="<?= htmlspecialchars($processo['slug'], ENT_QUOTES, 'UTF-8') ?>">
                                <td><strong><?= htmlspecialchars($processo['titulo'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td class="row-nivel"><?= (int) $avaliacao['nivel'] ?></td>
                                <td class="row-uso"><?= (int) $avaliacao['uso_real'] ?>/<?= (int) $avaliacao['meta_minima'] ?></td>
                                <td class="row-autonomia">0%</td>
                                <td class="row-qualidade">0%</td>
                                <td class="row-score score-cell">0%</td>
                                <td class="row-status">Sem evidencia</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="sticky-save text-end">
            <button type="submit" class="btn btn-primary btn-lg px-5 fw-bold shadow">
                <i class="bi bi-save me-2"></i>Salvar avaliacao do cliente
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const cards = Array.from(document.querySelectorAll('.process-eval'));
    const targets = {
        core: ['produtos', 'clientes', 'boleto', 'nota_fiscal'],
        commercial: ['orcamento', 'pdv']
    };

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function formatPercent(value) {
        return `${Math.round(value)}%`;
    }

    function getStatusConfig(pronto, score) {
        if (pronto) {
            return { text: 'Pronto para operacao', className: 'status-ready' };
        }
        if (score >= 45) {
            return { text: 'Em evolucao', className: 'status-progress' };
        }
        return { text: 'Sem evidencia', className: 'status-pending' };
    }

    function computeCard(card) {
        const slug = card.dataset.processo;
        const title = card.dataset.titulo;
        const nivel = parseInt(card.querySelector('.campo-nivel').value || '0', 10);
        const meta = Math.max(0, parseInt(card.querySelector('.campo-meta').value || '0', 10));
        const uso = Math.max(0, parseInt(card.querySelector('.campo-uso').value || '0', 10));
        const suporte = clamp(parseInt(card.querySelector('.campo-suporte').value || '0', 10), 0, uso);
        const erros = clamp(parseInt(card.querySelector('.campo-erros').value || '0', 10), 0, uso);

        if (parseInt(card.querySelector('.campo-suporte').value || '0', 10) !== suporte) {
            card.querySelector('.campo-suporte').value = suporte;
        }
        if (parseInt(card.querySelector('.campo-erros').value || '0', 10) !== erros) {
            card.querySelector('.campo-erros').value = erros;
        }

        const nivelScore = (nivel / 4) * 100;
        const usoScore = meta > 0 ? clamp((uso / meta) * 100, 0, 100) : 100;
        const autonomia = uso > 0 ? clamp(((uso - suporte) / uso) * 100, 0, 100) : 0;
        const qualidade = uso > 0 ? clamp(((uso - erros) / uso) * 100, 0, 100) : 0;
        const score = (nivelScore * 0.4) + (usoScore * 0.2) + (autonomia * 0.2) + (qualidade * 0.2);
        const pronto = nivel >= 3 && uso >= meta && autonomia >= 80 && qualidade >= 85;
        const status = getStatusConfig(pronto, score);

        const chip = card.querySelector('.status-processo');
        chip.textContent = status.text;
        chip.className = `status-chip ${status.className} status-processo`;

        card.querySelector('.score-processo').textContent = formatPercent(score);
        card.querySelector('.mini-progress-bar').style.width = `${score}%`;
        card.querySelector('.uso-processo').textContent = `Uso: ${formatPercent(usoScore)}`;
        card.querySelector('.autonomia-processo').textContent = `Autonomia: ${formatPercent(autonomia)}`;
        card.querySelector('.qualidade-processo').textContent = `Qualidade: ${formatPercent(qualidade)}`;

        const row = document.querySelector(`[data-row="${slug}"]`);
        if (row) {
            row.querySelector('.row-nivel').textContent = nivel;
            row.querySelector('.row-uso').textContent = `${uso}/${meta}`;
            row.querySelector('.row-autonomia').textContent = formatPercent(autonomia);
            row.querySelector('.row-qualidade').textContent = formatPercent(qualidade);
            row.querySelector('.row-score').textContent = formatPercent(score);
            row.querySelector('.row-status').textContent = status.text;
        }

        return { slug, title, score, autonomia, pronto };
    }

    function updateSummary() {
        const results = cards.map(computeCard);
        const total = results.length;
        const averageScore = total > 0 ? results.reduce((sum, item) => sum + item.score, 0) / total : 0;
        const averageAutonomy = total > 0 ? results.reduce((sum, item) => sum + item.autonomia, 0) / total : 0;
        const readyCount = results.filter(item => item.pronto).length;

        const coreReady = targets.core.every(slug => {
            const item = results.find(result => result.slug === slug);
            return item && item.pronto;
        });

        const commercialReady = targets.commercial.some(slug => {
            const item = results.find(result => result.slug === slug);
            return item && item.pronto;
        });

        const globalReady = coreReady && commercialReady;

        document.getElementById('score-geral').textContent = formatPercent(averageScore);
        document.getElementById('processos-prontos').textContent = `${readyCount}/${total}`;
        document.getElementById('autonomia-media').textContent = formatPercent(averageAutonomy);
        document.getElementById('status-global').textContent = globalReady ? 'Sim' : 'Nao';
        document.getElementById('status-global-copy').textContent = globalReady
            ? 'Todos os obrigatorios estao validados e o fluxo comercial foi testado.'
            : 'Ainda existem etapas criticas sem validacao suficiente.';

        const fluxoBadge = document.getElementById('fluxo-comercial-badge');
        fluxoBadge.textContent = commercialReady ? 'Fluxo comercial validado' : 'Fluxo comercial nao validado';
        fluxoBadge.className = commercialReady
            ? 'badge bg-success-subtle text-success-emphasis border border-success-subtle px-3 py-2 rounded-pill'
            : 'badge bg-body border text-muted px-3 py-2 rounded-pill';

        const leituraGeral = document.getElementById('leitura-geral');
        const leituraCopy = document.getElementById('leitura-geral-copy');

        if (globalReady) {
            leituraGeral.textContent = 'Cliente pronto para encerrar a implantacao.';
            leituraCopy.textContent = 'O cenario indica operacao real com autonomia, baixo retrabalho e repeticao suficiente.';
            return;
        }

        if (coreReady && !commercialReady) {
            leituraGeral.textContent = 'Base operacional pronta, mas falta validar o fluxo comercial.';
            leituraCopy.textContent = 'Orcamento ou PDV ainda nao atingiu o nivel minimo para fechamento seguro.';
            return;
        }

        const criticalGaps = results
            .filter(item => (targets.core.includes(item.slug) || targets.commercial.includes(item.slug)) && !item.pronto)
            .slice(0, 2)
            .map(item => item.title);

        leituraGeral.textContent = 'Cliente ainda depende de treinamento assistido.';
        leituraCopy.textContent = criticalGaps.length > 0
            ? `Os gargalos mais criticos agora sao: ${criticalGaps.join(' e ')}.`
            : 'Preencha os processos abaixo para identificar onde esta o gargalo de implantacao.';
    }

    cards.forEach(card => {
        card.querySelectorAll('input, select').forEach(field => {
            field.addEventListener('input', updateSummary);
            field.addEventListener('change', updateSummary);
        });
    });

    document.getElementById('limpar-avaliacao').addEventListener('click', function () {
        cards.forEach(card => {
            card.querySelector('.campo-nivel').value = '0';
            card.querySelector('.campo-uso').value = '0';
            card.querySelector('.campo-suporte').value = '0';
            card.querySelector('.campo-erros').value = '0';
        });

        updateSummary();
    });

    updateSummary();
});
</script>

<?php include 'footer.php'; ?>
