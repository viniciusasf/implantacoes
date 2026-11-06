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
            'descricao' => 'Mede o cadastro de clientes reais com os campos minimos necessarios.',
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
            'descricao' => 'Valida emissao fiscal correta, incluindo tratamento de rejeicoes simples.',
            'meta' => 2,
            'regra' => 'Processo concluido apenas quando a nota sair corretamente e o cliente entender correcoes basicas.'
        ],
        [
            'slug' => 'customizacoes',
            'titulo' => 'Customizações Solicitadas',
            'icone' => 'bi-tools',
            'descricao' => 'Acompanha as customizacoes solicitadas pelo cliente e a validacao do que foi entregue.',
            'meta' => 5,
            'regra' => 'Registrar e validar as customizacoes solicitadas, com minimo de cinco itens para a validacao.'
        ],
    ];
}

function garantirTabelaTreinamentoEfetivo(PDO $pdo)
{
    $sql = "CREATE TABLE IF NOT EXISTS treinamento_efetivo_cliente (
        id_avaliacao INT AUTO_INCREMENT PRIMARY KEY,
        id_cliente INT NOT NULL,
        processo VARCHAR(50) NOT NULL,
        utiliza_modulo TINYINT(1) NOT NULL DEFAULT 1,
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

    $columnStmt = $pdo->query("SHOW COLUMNS FROM treinamento_efetivo_cliente LIKE 'utiliza_modulo'");
    if (!$columnStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE treinamento_efetivo_cliente ADD COLUMN utiliza_modulo TINYINT(1) NOT NULL DEFAULT 1 AFTER processo");
    }
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
            'utiliza_modulo' => 1,
            'nivel' => 0,
            'meta_minima' => (int) $processo['meta'],
            'uso_real' => 0,
            'operacoes_suporte' => 0,
            'erros_retrabalho' => 0,
            'updated_at' => null,
        ];
    }

    $stmt = $pdo->prepare("SELECT processo, utiliza_modulo, nivel, meta_minima, uso_real, operacoes_suporte, erros_retrabalho, updated_at
                           FROM treinamento_efetivo_cliente
                           WHERE id_cliente = ?");
    $stmt->execute([$idCliente]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slug = (string) ($row['processo'] ?? '');
        if (!isset($avaliacoes[$slug])) {
            continue;
        }

        $avaliacoes[$slug]['utiliza_modulo'] = isset($row['utiliza_modulo']) ? (int) $row['utiliza_modulo'] : 1;
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

$stmtCliente = $pdo->prepare("SELECT id_cliente, id_cliente_api, fantasia, servidor, vendedor, recursos FROM clientes WHERE id_cliente = ?");
$stmtCliente->execute([$id_cliente]);
$cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    header("Location: clientes.php");
    exit;
}

$recursosUtilizados = array_values(array_filter(array_map('trim', explode(',', (string) ($cliente['recursos'] ?? '')))));
$stmtTemas = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(tema), ''), 'Sem tema informado') AS tema, COUNT(*) AS quantidade FROM treinamentos WHERE id_cliente = ? GROUP BY COALESCE(NULLIF(TRIM(tema), ''), 'Sem tema informado') ORDER BY quantidade DESC, tema ASC");
$stmtTemas->execute([$id_cliente]);
$treinamentosPorTema = $stmtTemas->fetchAll(PDO::FETCH_ASSOC);
$totalTreinamentos = array_sum(array_map(static function ($linha) { return (int) $linha['quantidade']; }, $treinamentosPorTema));

$totalChamadosCliente = null;
$cacheChamadosAtualizadoEm = null;
$idClienteApi = trim((string) ($cliente['id_cliente_api'] ?? ''));
$arquivoCacheChamados = __DIR__ . '/logs/gp_cache_chamados.json';
if ($idClienteApi !== '' && is_file($arquivoCacheChamados)) {
    $conteudoCacheChamados = @file_get_contents($arquivoCacheChamados);
    $cacheChamados = is_string($conteudoCacheChamados) ? json_decode($conteudoCacheChamados, true) : null;
    if (is_array($cacheChamados) && isset($cacheChamados['chamados']) && is_array($cacheChamados['chamados'])) {
        $totalChamadosCliente = 0;
        foreach ($cacheChamados['chamados'] as $chamadoCache) {
            if (isset($chamadoCache['ID_CLIENTE']) && (string) $chamadoCache['ID_CLIENTE'] === $idClienteApi) {
                $totalChamadosCliente++;
            }
        }
        $cacheChamadosAtualizadoEm = date('d/m/Y H:i', filemtime($arquivoCacheChamados));
    }
}

try {
    garantirTabelaTreinamentoEfetivo($pdo);
} catch (Throwable $e) {
    header("Location: treinamento_efetivo.php?id_cliente=" . $id_cliente . "&msg=" . urlencode("Nao foi possivel preparar a estrutura do treinamento efetivo: " . $e->getMessage()) . "&tipo=danger");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nivelPost = $_POST['nivel'] ?? [];
    $metaPost = $_POST['meta_minima'] ?? [];
    $usoPost = $_POST['uso_real'] ?? [];
    $suportePost = $_POST['operacoes_suporte'] ?? [];
    $errosPost = $_POST['erros_retrabalho'] ?? [];
    $utilizaModuloPost = $_POST['utiliza_modulo'] ?? [];

    try {
        $pdo->beginTransaction();

        $sql = "INSERT INTO treinamento_efetivo_cliente
                    (id_cliente, processo, utiliza_modulo, nivel, meta_minima, uso_real, operacoes_suporte, erros_retrabalho)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    utiliza_modulo = VALUES(utiliza_modulo),
                    nivel = VALUES(nivel),
                    meta_minima = VALUES(meta_minima),
                    uso_real = VALUES(uso_real),
                    operacoes_suporte = VALUES(operacoes_suporte),
                    erros_retrabalho = VALUES(erros_retrabalho)";
        $stmtSave = $pdo->prepare($sql);

        foreach ($processos as $processo) {
            $slug = $processo['slug'];
            $utilizaModulo = isset($utilizaModuloPost[$slug]) ? ((string) $utilizaModuloPost[$slug] === '0' ? 0 : 1) : 1;
            $nivel = max(0, min(4, (int) ($nivelPost[$slug] ?? 0)));
            $meta = max(0, (int) ($metaPost[$slug] ?? $processo['meta']));
            $uso = max(0, (int) ($usoPost[$slug] ?? 0));
            $suporte = max(0, min($uso, (int) ($suportePost[$slug] ?? 0)));
            $erros = max(0, min($uso, (int) ($errosPost[$slug] ?? 0)));

            $stmtSave->execute([$id_cliente, $slug, $utilizaModulo, $nivel, $meta, $uso, $suporte, $erros]);
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

// Exportacao do documento de validacao no formato de dashboard.
if (isset($_GET['exportar']) && $_GET['exportar'] === 'pdf') {
    require_once __DIR__ . '/vendor/autoload.php';

    $esc = static function ($valor) {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $nivelTexto = [0 => 'Não treinado', 1 => 'Com ajuda total', 2 => 'Com pouca ajuda', 3 => 'Executa sozinho', 4 => 'Executa com consistência'];
    $linhas = '';
    $etapas = '';
    $totalAplicaveis = 0;
    $prontos = 0;
    $pendentes = [];

    foreach ($processos as $processo) {
        $a = $avaliacoes[$processo['slug']];
        $aplicavel = (int) $a['utiliza_modulo'] === 1;
        $titulo = $esc($processo['titulo']);
        $descricao = $esc($processo['descricao']);

        if ($aplicavel) {
            $totalAplicaveis++;
            $nivel = max(0, min(4, (int) $a['nivel']));
            $uso = max(0, (int) $a['uso_real']);
            $meta = max(0, (int) $a['meta_minima']);
            $suporte = min($uso, max(0, (int) $a['operacoes_suporte']));
            $erros = min($uso, max(0, (int) $a['erros_retrabalho']));
            $autonomia = $uso > 0 ? max(0, 100 * (1 - ($suporte / $uso))) : 0;
            $qualidade = $uso > 0 ? max(0, 100 * (1 - ($erros / $uso))) : 0;
            $pronto = $nivel >= 3 && $uso >= $meta && $autonomia >= 80 && $qualidade >= 85;
            if ($pronto) {
                $prontos++;
                $classe = 'ready';
                $status = 'Pronto para valida??o';
            } else {
                $classe = 'pending';
                $status = 'Em desenvolvimento';
                $pendentes[] = $processo['titulo'];
            }

            $nivelHtml = $esc($nivelTexto[$nivel]) . '<br><small>nivel ' . $nivel . ' de 4</small>';
            $usoHtml = $uso . ' / ' . $meta . '<br><small>' . ($uso >= $meta ? 'meta atingida' : 'abaixo da meta') . '</small>';
            $autonomiaHtml = number_format($autonomia, 0) . '%<br><small>' . $suporte . ' com suporte</small>';
            $qualidadeHtml = number_format($qualidade, 0) . '%<br><small>' . $erros . ' com erro</small>';
            $linhas .= '<tr class="' . $classe . '"><td class="process-name"><strong>' . $titulo . '</strong><small>' . $descricao . '</small></td><td>' . $nivelHtml . '</td><td>' . $usoHtml . '</td><td><strong>' . $autonomiaHtml . '</strong></td><td><strong>' . $qualidadeHtml . '</strong></td><td class="status">' . $esc($status) . '</td></tr>';
            $etapaClasse = $pronto ? 'step-ready' : 'step-pending';
        } else {
            $classe = 'not-applicable';
            $linhas .= '<tr class="' . $classe . '"><td class="process-name"><strong>' . $titulo . '</strong><small>' . $descricao . '</small></td><td colspan="4" class="na-note">Módulo não utilizado pelo cliente.</td><td class="status">Nao se aplica</td></tr>';
            $etapaClasse = 'step-na';
        }
        $etapas .= '<td class="' . $etapaClasse . '">' . $titulo . '</td>';
    }

    $dataEmissao = date('d/m/Y');
    $clienteNome = $esc($cliente['fantasia']);
    $statusGeral = $totalAplicaveis > 0 && $prontos === $totalAplicaveis
        ? 'Validação concluída.'
        : 'Validação em andamento.';
    $pendentesTexto = $pendentes
        ? '<br><span class="pending-label">Pendente: ' . $esc(implode(', ', $pendentes)) . '.</span>'
        : '';
    $progressoHtml = $totalAplicaveis > 0
        ? '<div class="summary-title">' . $prontos . ' de ' . $totalAplicaveis . ' processos aplicáveis prontos. ' . $esc($statusGeral) . '</div>'
        : '<div class="summary-title">Nenhum processo aplicável para validação.</div>';

    $html = '<!doctype html><html><head><meta charset="UTF-8"><style>
        @page{margin:30px 32px 42px}*{box-sizing:border-box}body{font-family:DejaVu Sans,sans-serif;color:#24343a;font-size:8.3px;line-height:1.4}
        .header{width:100%;border-bottom:2px solid #08778a;padding-bottom:8px;margin-bottom:10px}.header td{border:0;padding:0;vertical-align:top}.brand{color:#08778a;font-size:8px;font-weight:bold;letter-spacing:1.1px;text-transform:uppercase}.title{font-size:20px;font-weight:bold;margin-top:7px}.client{text-align:right;font-size:8px;color:#60727a}.client strong{display:block;color:#24343a;font-size:10px;margin-bottom:4px}.intro{margin:0 0 10px;color:#52646b}
        .summary{background:#eef5f6;border:1px solid #cfdddf;border-left:4px solid #08778a;padding:8px 10px;margin:0 0 10px}.summary-title{font-size:11px;font-weight:bold;color:#086679}.pending-label{font-size:8px;color:#52646b}.meta{margin-top:4px;color:#52646b;font-size:7.5px}
        .steps{width:100%;border-collapse:separate;border-spacing:3px 0;margin:0 0 12px}.steps td{border:0;border-top:3px solid #71818a;text-align:center;padding:4px 2px 0;font-size:6.8px;font-weight:bold;color:#31434a;vertical-align:top}.steps .step-ready{border-color:#32815d}.steps .step-pending{border-color:#c27a00}.steps .step-na{border-color:#71818a;color:#52646b}
        h2{font-size:11px;color:#086679;margin:0 0 6px}.report{width:100%;border-collapse:collapse;table-layout:fixed}.report th{background:#e6eff0;color:#086679;text-align:left;font-size:7px}.report th,.report td{border:1px solid #d3dddf;padding:5px 5px;vertical-align:middle;overflow-wrap:break-word}.report td{text-align:center}.report .process-name{text-align:left;width:30%}.process-name strong{display:block;font-size:8px;color:#263a41}.process-name small,td small{display:block;font-size:6.6px;color:#74838a;line-height:1.25;margin-top:2px}.report th:nth-child(1){width:30%}.report th:nth-child(2){width:16%}.report th:nth-child(3){width:13%}.report th:nth-child(4){width:13%}.report th:nth-child(5){width:13%}.report th:nth-child(6){width:15%}.report .status{text-align:left;font-weight:bold}.report .ready td:first-child{border-left:3px solid #32815d}.report .ready .status{color:#278054}.report .pending td:first-child{border-left:3px solid #c27a00}.report .pending .status{color:#bc6e00}.report .not-applicable{background:#f5f7f7;color:#74838a}.report .not-applicable td:first-child{border-left:3px solid #71818a}.report .na-note{text-align:left;color:#74838a}.legend{font-size:6.8px;color:#74838a;margin-top:7px}
    </style></head><body>
    <table class="header"><tr><td><div class="brand">Implanta &nbsp;|&nbsp; Documento de validação</div><div class="title">Validação da implantação</div></td><td class="client"><strong>' . $clienteNome . '</strong>ID #' . (int) $cliente['id_cliente'] . ' &nbsp;|&nbsp; Emissão: ' . $dataEmissao . '<br>Servidor: ' . $esc($cliente['servidor'] ?: 'Não informado') . ' &nbsp;|&nbsp; Comercial: ' . $esc($cliente['vendedor'] ?: 'Não informado') . '</td></tr></table>
    <p class="intro">Este documento registra os processos configurados e praticados durante a implantação. Confira as informações, valide os fluxos aplicáveis e registre eventuais ajustes antes do aceite.</p>
    <div class="summary">' . $progressoHtml . $pendentesTexto . '<div class="meta">Um processo fica pronto com nível 3 ou mais, uso igual ou maior que a meta, autonomia de 80% ou mais e qualidade de 85% ou mais.</div></div>
    <table class="steps"><tr>' . $etapas . '</tr></table>
    <h2>Processos e critérios de validação</h2><table class="report"><thead><tr><th>Processo</th><th>Nível</th><th>Uso real / meta</th><th>Autonomia</th><th>Qualidade</th><th>Situação</th></tr></thead><tbody>' . $linhas . '</tbody></table>
    <div class="legend">Os processos marcados como "Nao se aplica" foram indicados como não utilizados na avaliação atual.</div>
    </body></html>';

    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $canvas = $dompdf->getCanvas();
    $rodape = 'Validação da implantação | ' . html_entity_decode((string) $cliente['fantasia'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' | Gerado em ' . $dataEmissao;
    $canvas->page_text(36, 810, $rodape, null, 7, [0.44, 0.51, 0.55]);
    $canvas->page_text(515, 810, 'Página {PAGE_NUM} de {PAGE_COUNT}', null, 7, [0.44, 0.51, 0.55]);
    $nomeArquivo = 'validacao-implantacao-' . preg_replace('/[^A-Za-z0-9_-]/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $cliente['fantasia']) ?: 'cliente') . '.pdf';
    $dompdf->stream($nomeArquivo, ['Attachment' => true]);
    exit;
}

$flashMensagem = isset($_GET['msg']) ? trim((string) $_GET['msg']) : '';
$flashTipo = isset($_GET['tipo']) ? trim((string) $_GET['tipo']) : 'success';

include 'header.php';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&display=swap">

<style>
.te {
    --te-ink: var(--text-dark, #1b2a30);
    --te-muted: var(--text-muted, #5b6b72);
    --te-line: var(--border-color, #d9e1e4);
    --te-surface: var(--bg-card, #fff);
    --te-wash: var(--bg-body, #f3f6f7);
    --te-petrol: #0f5c6e;   --te-petrol-soft: rgba(15, 92, 110, .12);
    --te-amber: #b86e00;    --te-amber-soft: rgba(184, 110, 0, .12);
    --te-green: #23704f;    --te-green-soft: rgba(35, 112, 79, .13);
    --te-slate: #6f7f88;    --te-slate-soft: rgba(111, 127, 136, .13);
    --te-display: 'Bricolage Grotesque', var(--bs-body-font-family, system-ui), sans-serif;
    max-width: 1320px;
    color: var(--te-ink);
}
[data-theme="dark"] .te {
    --te-petrol: #66bdd0;   --te-petrol-soft: rgba(102, 189, 208, .16);
    --te-amber: #e8ae45;    --te-amber-soft: rgba(232, 174, 69, .15);
    --te-green: #62c795;    --te-green-soft: rgba(98, 199, 149, .16);
    --te-slate: #8f9ea8;    --te-slate-soft: rgba(143, 158, 168, .16);
}
.te a:focus-visible, .te button:focus-visible, .te .form-control:focus-visible, .te .form-select:focus-visible {
    outline: 3px solid var(--te-petrol);
    outline-offset: 2px;
}

/* Cabeçalho: o veredito é o título */
.te-head { padding: .5rem 0 1.75rem; }
.te-toolbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .75rem; margin-bottom: 1.5rem; }
.te-client { font-size: 1rem; font-weight: 600; margin: 0; }
.te-meta { color: var(--te-muted); font-size: .88rem; display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; margin-top: .25rem; }
.te-verdict {
    font-family: var(--te-display);
    font-weight: 700;
    font-size: clamp(1.9rem, 4.2vw, 3.1rem);
    line-height: 1.08;
    letter-spacing: -.025em;
    max-width: 22ch;
    margin: 0 0 .6rem;
}
.te-verdict-copy { color: var(--te-muted); max-width: 62ch; margin: 0 0 1.75rem; line-height: 1.6; }

/* Trilho de implantação: o elemento memorável */
.rail { list-style: none; margin: 0; padding: 0 0 .5rem; display: grid; grid-auto-flow: column; grid-auto-columns: minmax(96px, 1fr); overflow-x: auto; }
.rail-item { position: relative; text-align: center; padding: 0 .25rem; }
.rail-item + .rail-item::before { content: ''; position: absolute; top: 22px; right: 50%; width: 100%; height: 2px; background: var(--te-line); transform-origin: left; }
.rail-node { position: relative; z-index: 1; display: flex; flex-direction: column; align-items: center; gap: .35rem; color: inherit; text-decoration: none; padding-bottom: .25rem; }
.rail-dot { position: relative; width: 46px; height: 46px; border-radius: 50%; display: grid; place-items: center; font-size: 1.15rem; background: var(--te-surface); border: 2px solid var(--c, var(--te-amber)); color: var(--c, var(--te-amber)); }
.rail-name { font-weight: 600; font-size: .9rem; line-height: 1.2; }
.rail-tag { font-size: .78rem; color: var(--te-muted); }
.rail-node[data-state="pending"]  { --c: var(--te-amber); }
.rail-node[data-state="progress"] { --c: var(--te-petrol); }
.rail-node[data-state="progress"] .rail-dot { background: var(--te-petrol-soft); }
.rail-node[data-state="ready"]    { --c: var(--te-green); }
.rail-node[data-state="ready"] .rail-dot { background: var(--te-green); color: #fff; }
.rail-node[data-state="ready"] .rail-dot::after { content: '✓'; position: absolute; right: -5px; top: -5px; width: 20px; height: 20px; border-radius: 50%; background: var(--te-surface); color: var(--te-green); border: 2px solid var(--te-green); font-size: .7rem; font-weight: 800; display: grid; place-items: center; }
.rail-node[data-state="na"]       { --c: var(--te-slate); opacity: .75; }
.rail-node[data-state="na"] .rail-dot { border-style: dashed; }
.rail-node:hover .rail-name { text-decoration: underline; }

/* Única sequência de entrada, só no carregamento */
@media (prefers-reduced-motion: no-preference) {
    .rail-item { animation: te-in .5s cubic-bezier(.2, .7, .2, 1) both; animation-delay: calc(var(--i) * 70ms); }
    .rail-item + .rail-item::before { animation: te-line .5s ease-out both; animation-delay: calc(var(--i) * 70ms); }
    @keyframes te-in { from { opacity: 0; transform: translateY(10px); } }
    @keyframes te-line { from { transform: scaleX(0); } }
    .te-bar-fill { transition: width .35s ease; }
}

.te-totals { display: flex; flex-wrap: wrap; gap: .75rem 2.5rem; margin: 1.5rem 0 0; padding-top: 1.25rem; border-top: 1px solid var(--te-line); }
.te-totals div { min-width: 120px; }
.te-totals dt { font-size: .85rem; font-weight: 500; color: var(--te-muted); }
.te-totals dd { margin: 0; font-family: var(--te-display); font-size: 1.6rem; font-weight: 700; line-height: 1.2; }
.te-totals dd.is-text { font-size: 1.1rem; }

/* Corpo: processos em lista contínua + contexto lateral */
.te-body { display: grid; grid-template-columns: minmax(0, 1fr) 330px; gap: 2.5rem; align-items: start; }
.te-aside { position: sticky; top: 16px; display: grid; gap: 1.5rem; }
.te-aside h2, .te-section-title { font-family: var(--te-display); font-size: 1.15rem; font-weight: 700; margin: 0 0 .75rem; }
.te-aside section { padding-bottom: 1.5rem; border-bottom: 1px solid var(--te-line); }
.te-aside section:last-child { border-bottom: 0; padding-bottom: 0; }
.te-chips { display: flex; flex-wrap: wrap; gap: .4rem; }
.te-chip { background: var(--te-petrol-soft); color: var(--te-petrol); border-radius: 6px; padding: .25rem .6rem; font-size: .85rem; font-weight: 600; }
.te-muted { color: var(--te-muted); font-size: .88rem; }
.te-count-row { display: flex; justify-content: space-between; gap: 1rem; padding: .5rem 0; border-bottom: 1px solid var(--te-line); font-size: .92rem; }
.te-count-row:last-child { border-bottom: 0; }
.te-tickets { font-family: var(--te-display); font-size: 2.2rem; font-weight: 700; line-height: 1; color: var(--te-petrol); }

.proc { position: relative; padding: 1.5rem 0 1.5rem 1.25rem; border-bottom: 1px solid var(--te-line); border-left: 4px solid var(--te-amber); scroll-margin-top: 16px; }
.proc:first-child { padding-top: .5rem; }
.proc[data-state="progress"] { border-left-color: var(--te-petrol); }
.proc[data-state="ready"]    { border-left-color: var(--te-green); }
.proc[data-state="na"]       { border-left-color: var(--te-slate); border-left-style: dashed; }
.proc-top { display: flex; align-items: flex-start; gap: 1rem; }
.proc-icon { flex: none; width: 42px; height: 42px; border-radius: 10px; display: grid; place-items: center; background: var(--te-wash); color: var(--te-petrol); font-size: 1.2rem; }
.proc-id { flex: 1; min-width: 0; }
.proc-title { font-family: var(--te-display); font-size: 1.25rem; font-weight: 700; margin: 0; }
.proc-copy { color: var(--te-muted); font-size: .93rem; line-height: 1.55; margin: .2rem 0 0; max-width: 70ch; }
.proc-state { text-align: right; }
.proc-state .score-processo { display: block; font-family: var(--te-display); font-size: 1.6rem; line-height: 1.1; margin-top: .35rem; }
.proc-rule { margin: .9rem 0 1rem; padding-left: .85rem; border-left: 2px solid var(--te-line); color: var(--te-muted); font-size: .86rem; max-width: 70ch; }

.status-chip { display: inline-block; border-radius: 6px; padding: .25rem .6rem; font-size: .82rem; font-weight: 700; }
.status-pending  { background: var(--te-amber-soft);  color: var(--te-amber); }
.status-progress { background: var(--te-petrol-soft); color: var(--te-petrol); }
.status-ready    { background: var(--te-green-soft);  color: var(--te-green); }
.status-na       { background: var(--te-slate-soft);  color: var(--te-slate); }

.proc-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)); gap: .85rem; }
.field-block label { display: block; font-size: .83rem; font-weight: 600; color: var(--te-muted); margin-bottom: .3rem; }
.field-block .form-control, .field-block .form-select { min-height: 44px; border-radius: 8px; border-color: var(--te-line); background-color: var(--te-surface); }
.field-block .form-control:disabled, .field-block .form-select:disabled { background-color: var(--te-wash); opacity: .6; }

.te-bar { height: 6px; background: var(--te-wash); border-radius: 3px; overflow: hidden; margin-top: 1.1rem; }
.te-bar-fill { height: 100%; width: 0; background: var(--te-petrol); border-radius: inherit; }
.proc[data-state="ready"] .te-bar-fill { background: var(--te-green); }
.proc[data-state="pending"] .te-bar-fill { background: var(--te-amber); }
.stats-line { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; margin-top: .5rem; font-size: .86rem; color: var(--te-muted); }

/* Scorecard e orientações */
.te-section { margin-top: 3rem; }
.table-shell { overflow-x: auto; border-top: 2px solid var(--te-ink); }
.table-scorecard { width: 100%; min-width: 720px; border-collapse: collapse; }
.table-scorecard th, .table-scorecard td { padding: .75rem .5rem; border-bottom: 1px solid var(--te-line); text-align: left; }
.table-scorecard th { font-size: .85rem; font-weight: 600; color: var(--te-muted); }
.score-cell { font-weight: 700; }
.te-guide { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 2rem; padding: 1.5rem 0; }
.te-scale { list-style: none; margin: 0; padding: 0; counter-reset: lvl -1; }
.te-scale li { counter-increment: lvl; display: grid; grid-template-columns: 2rem 1fr; gap: .5rem; padding: .5rem 0; border-bottom: 1px solid var(--te-line); }
.te-scale li::before { content: counter(lvl); font-family: var(--te-display); font-weight: 700; font-size: 1.2rem; color: var(--te-petrol); }
.te-scale small { display: block; color: var(--te-muted); }
.te-rules { margin: 0; padding: 0; list-style: none; }
.te-rules li { padding: .5rem 0; border-bottom: 1px solid var(--te-line); }
.te-rules strong { display: block; }

.sticky-save { position: sticky; bottom: calc(12px + env(safe-area-inset-bottom, 0px)); z-index: 10; margin-top: 2rem; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .75rem; padding: .75rem 1rem; background: var(--te-surface); border: 1px solid var(--te-line); border-radius: 12px; box-shadow: 0 8px 24px rgba(0, 0, 0, .12); }

@media (max-width: 1100px) {
    .te-body { grid-template-columns: 1fr; gap: 1.5rem; }
    .te-aside { position: static; order: -1; }
}
@media (max-width: 575px) {
    .proc { padding-left: .9rem; }
    .proc-top { flex-wrap: wrap; }
    .proc-state { width: 100%; display: flex; justify-content: space-between; align-items: center; text-align: left; }
    .proc-state .score-processo { margin-top: 0; }
    .sticky-save .btn { flex: 1; }
}
</style>

<?php
$tagsTrilho = [
    'produtos' => 'Obrigatório', 'clientes' => 'Obrigatório', 'boleto' => 'Obrigatório', 'nota_fiscal' => 'Obrigatório',
    'orcamento' => 'Ou PDV', 'pdv' => 'Ou orçamento', 'customizacoes' => 'Acompanhamento',
];
$niveisTexto = [
    ['Não treinado', 'Ainda não executou o processo com segurança.'],
    ['Com ajuda total', 'Executa apenas com condução direta do implantador.'],
    ['Com pouca ajuda', 'Já faz parte do fluxo, mas ainda depende de validação.'],
    ['Executa sozinho', 'Conclui o processo sem intervenção externa.'],
    ['Executa com consistência', 'Repete corretamente, com baixo retrabalho e previsibilidade.'],
];
?>

<div class="container-fluid py-4 te">
    <header class="te-head">
        <div class="te-toolbar">
            <div>
                <p class="te-client"><?= htmlspecialchars($cliente['fantasia'], ENT_QUOTES, 'UTF-8') ?></p>
                <div class="te-meta">
                    <span>ID #<?= (int) $cliente['id_cliente'] ?></span>
                    <span>Servidor: <?= htmlspecialchars((string) ($cliente['servidor'] ?: '---'), ENT_QUOTES, 'UTF-8') ?></span>
                    <span>Vendedor: <?= htmlspecialchars((string) ($cliente['vendedor'] ?: '---'), ENT_QUOTES, 'UTF-8') ?></span>
                    <span>Última atualização: <?= $ultimaAtualizacao ? date('d/m/Y H:i', strtotime($ultimaAtualizacao)) : 'não registrada' ?></span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="treinamentos.php" class="btn btn-outline-secondary fw-semibold"><i class="bi bi-arrow-left me-2"></i>Voltar</a>
                <a href="treinamento_efetivo.php?id_cliente=<?= (int) $id_cliente ?>&amp;exportar=pdf" class="btn btn-outline-secondary fw-semibold"><i class="bi bi-file-earmark-pdf me-2"></i>Exportar validação em PDF</a>
            </div>
        </div>

        <h1 class="te-verdict" id="leitura-geral" aria-live="polite">Cliente ainda depende de treinamento assistido.</h1>
        <p class="te-verdict-copy" id="leitura-geral-copy">Preencha os processos abaixo para identificar onde está o gargalo da implantação.</p>

        <ol class="rail" aria-label="Situação de cada processo">
            <?php foreach ($processos as $i => $p): ?>
                <li class="rail-item" style="--i:<?= (int) $i ?>">
                    <a class="rail-node" href="#proc-<?= htmlspecialchars($p['slug'], ENT_QUOTES, 'UTF-8') ?>" data-node="<?= htmlspecialchars($p['slug'], ENT_QUOTES, 'UTF-8') ?>" data-state="pending" title="<?= htmlspecialchars($p['titulo'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="rail-dot"><i class="bi <?= htmlspecialchars($p['icone'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
                        <span class="rail-name"><?= htmlspecialchars($p['titulo'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="rail-tag"><?= htmlspecialchars($tagsTrilho[$p['slug']] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>

        <dl class="te-totals">
            <div><dt>Score geral</dt><dd id="score-geral">0%</dd></div>
            <div><dt>Processos prontos</dt><dd id="processos-prontos">0/<?= count($processos) ?></dd></div>
            <div><dt>Autonomia média</dt><dd id="autonomia-media">0%</dd></div>
            <div><dt>Pronto para encerrar?</dt><dd id="status-global">Nao</dd></div>
        </dl>
        <p class="te-muted mt-2 mb-0" id="status-global-copy">Ainda existem etapas criticas sem validacao suficiente.</p>
    </header>

    <?php if ($flashMensagem !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($flashTipo, ENT_QUOTES, 'UTF-8') ?> border-0 rounded-3 mb-4" role="status">
            <?= htmlspecialchars($flashMensagem, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="treinamento_efetivo.php?id_cliente=<?= (int) $id_cliente ?>" id="form-treinamento-efetivo">
        <input type="hidden" name="id_cliente" value="<?= (int) $id_cliente ?>">

        <div class="te-body" id="conteudo-avaliacao">
            <div>
                <?php foreach ($processos as $processo):
                    $avaliacao = $avaliacoes[$processo['slug']];
                    $s = htmlspecialchars($processo['slug'], ENT_QUOTES, 'UTF-8');
                ?>
                    <section class="proc process-eval" id="proc-<?= $s ?>" data-state="pending" aria-labelledby="titulo-<?= $s ?>"
                        data-processo="<?= $s ?>" data-titulo="<?= htmlspecialchars($processo['titulo'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="proc-top">
                            <span class="proc-icon"><i class="bi <?= htmlspecialchars($processo['icone'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
                            <div class="proc-id">
                                <h3 class="proc-title" id="titulo-<?= $s ?>"><?= htmlspecialchars($processo['titulo'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="proc-copy"><?= htmlspecialchars($processo['descricao'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div class="proc-state">
                                <span class="status-chip status-pending status-processo">Sem evidencia</span>
                                <strong class="score-processo">0%</strong>
                            </div>
                        </div>

                        <p class="proc-rule"><?= htmlspecialchars($processo['regra'], ENT_QUOTES, 'UTF-8') ?></p>

                        <div class="proc-fields">
                            <div class="field-block">
                                <label for="utiliza_<?= $s ?>">Usa este módulo?</label>
                                <select class="form-select campo-utiliza" id="utiliza_<?= $s ?>" name="utiliza_modulo[<?= $s ?>]">
                                    <option value="1" <?= ((int) $avaliacao['utiliza_modulo']) === 1 ? 'selected' : '' ?>>Sim</option>
                                    <option value="0" <?= ((int) $avaliacao['utiliza_modulo']) === 0 ? 'selected' : '' ?>>Não</option>
                                </select>
                            </div>
                            <div class="field-block">
                                <label for="nivel_<?= $s ?>">Nível atual</label>
                                <select class="form-select campo-nivel" id="nivel_<?= $s ?>" name="nivel[<?= $s ?>]">
                                    <?php foreach ($niveisTexto as $n => $t): ?>
                                        <option value="<?= $n ?>" <?= (int) $avaliacao['nivel'] === $n ? 'selected' : '' ?>><?= $n ?> - <?= htmlspecialchars($t[0], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field-block">
                                <label for="meta_<?= $s ?>">Mínimo para validar</label>
                                <input type="number" min="0" step="1" inputmode="numeric" class="form-control campo-meta" id="meta_<?= $s ?>" name="meta_minima[<?= $s ?>]" value="<?= (int) $avaliacao['meta_minima'] ?>">
                            </div>
                            <div class="field-block">
                                <label for="uso_<?= $s ?>">Uso real</label>
                                <input type="number" min="0" step="1" inputmode="numeric" class="form-control campo-uso" id="uso_<?= $s ?>" name="uso_real[<?= $s ?>]" value="<?= (int) $avaliacao['uso_real'] ?>">
                            </div>
                            <div class="field-block">
                                <label for="suporte_<?= $s ?>">Feitas com suporte</label>
                                <input type="number" min="0" step="1" inputmode="numeric" class="form-control campo-suporte" id="suporte_<?= $s ?>" name="operacoes_suporte[<?= $s ?>]" value="<?= (int) $avaliacao['operacoes_suporte'] ?>">
                            </div>
                            <div class="field-block">
                                <label for="erros_<?= $s ?>">Erros ou retrabalho</label>
                                <input type="number" min="0" step="1" inputmode="numeric" class="form-control campo-erros" id="erros_<?= $s ?>" name="erros_retrabalho[<?= $s ?>]" value="<?= (int) $avaliacao['erros_retrabalho'] ?>">
                            </div>
                        </div>

                        <div class="te-bar" role="presentation"><div class="te-bar-fill mini-progress-bar"></div></div>
                        <div class="stats-line">
                            <span class="uso-processo">Uso: 0%</span>
                            <span class="autonomia-processo">Autonomia: 0%</span>
                            <span class="qualidade-processo">Qualidade: 0%</span>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <aside class="te-aside" aria-label="Contexto do cliente">
                <section>
                    <h2>Recursos que o cliente usa</h2>
                    <?php if ($recursosUtilizados): ?>
                        <div class="te-chips">
                            <?php foreach ($recursosUtilizados as $recurso): ?>
                                <span class="te-chip"><?= htmlspecialchars($recurso, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="te-muted mb-0">Nenhum recurso informado em clientes.php para este cliente.</p>
                    <?php endif; ?>
                </section>

                <section>
                    <h2>Treinamentos por tema <span class="te-muted">(<?= (int) $totalTreinamentos ?>)</span></h2>
                    <?php if ($treinamentosPorTema): ?>
                        <?php foreach ($treinamentosPorTema as $linhaTema): ?>
                            <div class="te-count-row">
                                <span><?= htmlspecialchars($linhaTema['tema'], ENT_QUOTES, 'UTF-8') ?></span>
                                <strong><?= (int) $linhaTema['quantidade'] ?></strong>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="te-muted mb-0">Ainda não há treinamentos cadastrados para este cliente.</p>
                    <?php endif; ?>
                </section>

                <section>
                    <h2>Chamados de suporte</h2>
                    <div class="d-flex align-items-baseline gap-3">
                        <span class="te-tickets"><?= $totalChamadosCliente !== null ? (int) $totalChamadosCliente : '—' ?></span>
                        <span class="te-muted">
                            <?= $cacheChamadosAtualizadoEm
                                ? 'Cache de ' . htmlspecialchars($cacheChamadosAtualizadoEm, ENT_QUOTES, 'UTF-8')
                                : 'Contagem baseada no cache local da API.' ?>
                        </span>
                    </div>
                    <?php if ($totalChamadosCliente === null): ?>
                        <p class="te-muted mt-2 mb-0">
                            <?= $idClienteApi === ''
                                ? 'Este cliente não tem ID da API GestaoPro vinculado.'
                                : 'Cache de chamados indisponível ou sem dados.' ?>
                        </p>
                    <?php endif; ?>
                </section>
            </aside>
        </div>

        <section class="te-section" aria-labelledby="titulo-scorecard">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2">
                <div>
                    <h2 class="te-section-title mb-1" id="titulo-scorecard">Resumo do scorecard</h2>
                    <p class="te-muted mb-0">Use esta tabela como pauta do acompanhamento semanal com o cliente.</p>
                </div>
                <span class="badge bg-body border text-muted px-3 py-2 rounded-pill" id="fluxo-comercial-badge">Fluxo comercial nao validado</span>
            </div>
            <div class="table-shell">
                <table class="table-scorecard">
                    <thead>
                        <tr><th>Processo</th><th>Nível</th><th>Uso real</th><th>Autonomia</th><th>Qualidade</th><th>Score</th><th>Status</th></tr>
                    </thead>
                    <tbody id="tbody-scorecard">
                        <?php foreach ($processos as $processo):
                            $avaliacao = $avaliacoes[$processo['slug']];
                        ?>
                            <tr data-row="<?= htmlspecialchars($processo['slug'], ENT_QUOTES, 'UTF-8') ?>" <?= ((int) $avaliacao['utiliza_modulo']) === 1 ? '' : 'hidden' ?>>
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
        </section>

        <details class="te-section">
            <summary class="te-section-title" style="cursor:pointer">Como preencher e quando fechar a implantação</summary>
            <div class="te-guide">
                <div>
                    <h3 class="h6 fw-bold">Régua de evolução</h3>
                    <ol class="te-scale">
                        <?php foreach ($niveisTexto as $t): ?>
                            <li><div><strong><?= htmlspecialchars($t[0], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($t[1], ENT_QUOTES, 'UTF-8') ?></small></div></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
                <div>
                    <h3 class="h6 fw-bold">Critérios para fechar 100%</h3>
                    <ul class="te-rules">
                        <li><strong>Produtos, Clientes, Boleto e Nota fiscal</strong><span class="te-muted">Todos precisam estar prontos.</span></li>
                        <li><strong>Orçamento ou PDV</strong><span class="te-muted">Basta um deles validado em uso real.</span></li>
                        <li><strong>Autonomia mínima de 80%</strong><span class="te-muted">No máximo 20% das operações com ajuda.</span></li>
                        <li><strong>Qualidade mínima de 85%</strong><span class="te-muted">Erros e retrabalho precisam ficar em nível baixo.</span></li>
                    </ul>
                </div>
                <div>
                    <h3 class="h6 fw-bold">Quando salvar</h3>
                    <p class="te-muted">Salve ao final de cada bloco de treinamento, fechamento semanal ou validação em ambiente real. Módulos marcados como “Não” saem do cálculo.</p>
                </div>
            </div>
        </details>

        <div class="sticky-save">
            <span class="te-muted">As alterações só são gravadas ao salvar.</span>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary fw-semibold" id="limpar-avaliacao"><i class="bi bi-eraser me-2"></i>Limpar tela</button>
                <button type="submit" class="btn btn-primary fw-semibold px-4"><i class="bi bi-save me-2"></i>Salvar avaliação</button>
            </div>
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

    function setState(card, slug, cls, text) {
        const state = cls.replace('status-', '');
        card.dataset.state = state;
        const node = document.querySelector(`[data-node="${slug}"]`);
        if (node) {
            node.dataset.state = state;
            node.title = `${card.dataset.titulo}: ${text}`;
        }
    }

    function formatPercent(value) {
        return `${Math.round(value)}%`;
    }

    function getStatusConfig(pronto, score, aplicavel) {
        if (!aplicavel) {
            return { text: 'Nao aplicavel', className: 'status-na' };
        }
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
        const utilizaModulo = card.querySelector('.campo-utiliza').value === '1';
        const fields = card.querySelectorAll('input, select');

        fields.forEach(field => {
            if (field.classList.contains('campo-utiliza')) {
                return;
            }
            field.disabled = !utilizaModulo;
        });

        const row = document.querySelector(`[data-row="${slug}"]`);
        if (!utilizaModulo) {
            const chip = card.querySelector('.status-processo');
            chip.textContent = 'Nao aplicavel';
            chip.className = 'status-chip status-na status-processo';
            setState(card, slug, 'status-na', 'Nao aplicavel');

            card.querySelector('.score-processo').textContent = 'N/A';
            card.querySelector('.mini-progress-bar').style.width = '0%';
            card.querySelector('.uso-processo').textContent = 'Uso: N/A';
            card.querySelector('.autonomia-processo').textContent = 'Autonomia: N/A';
            card.querySelector('.qualidade-processo').textContent = 'Qualidade: N/A';

            if (row) {
                row.hidden = true;
                row.querySelector('.row-nivel').textContent = '—';
                row.querySelector('.row-uso').textContent = 'N/A';
                row.querySelector('.row-autonomia').textContent = 'N/A';
                row.querySelector('.row-qualidade').textContent = 'N/A';
                row.querySelector('.row-score').textContent = 'N/A';
                row.querySelector('.row-status').textContent = 'Nao aplicavel';
            }

            return { slug, title, score: 0, autonomia: 0, pronto: true, aplicavel: false };
        }

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
        const status = getStatusConfig(pronto, score, true);

        const chip = card.querySelector('.status-processo');
        chip.textContent = status.text;
        chip.className = `status-chip ${status.className} status-processo`;
        setState(card, slug, status.className, status.text);

        card.querySelector('.score-processo').textContent = formatPercent(score);
        card.querySelector('.mini-progress-bar').style.width = `${score}%`;
        card.querySelector('.uso-processo').textContent = `Uso: ${formatPercent(usoScore)}`;
        card.querySelector('.autonomia-processo').textContent = `Autonomia: ${formatPercent(autonomia)}`;
        card.querySelector('.qualidade-processo').textContent = `Qualidade: ${formatPercent(qualidade)}`;

        if (row) {
            row.hidden = false;
            row.querySelector('.row-nivel').textContent = nivel;
            row.querySelector('.row-uso').textContent = `${uso}/${meta}`;
            row.querySelector('.row-autonomia').textContent = formatPercent(autonomia);
            row.querySelector('.row-qualidade').textContent = formatPercent(qualidade);
            row.querySelector('.row-score').textContent = formatPercent(score);
            row.querySelector('.row-status').textContent = status.text;
        }

        return { slug, title, score, autonomia, pronto, aplicavel: true };
    }

    function updateSummary() {
        const results = cards.map(computeCard);
        const aplicaveis = results.filter(item => item.aplicavel);
        const total = aplicaveis.length;
        const averageScore = total > 0 ? aplicaveis.reduce((sum, item) => sum + item.score, 0) / total : 0;
        const averageAutonomy = total > 0 ? aplicaveis.reduce((sum, item) => sum + item.autonomia, 0) / total : 0;
        const readyCount = aplicaveis.filter(item => item.pronto).length;

        const coreEligible = targets.core.filter(slug => aplicaveis.some(item => item.slug === slug));
        const commercialEligible = targets.commercial.filter(slug => aplicaveis.some(item => item.slug === slug));

        const coreReady = coreEligible.length === 0 ? true : coreEligible.every(slug => {
            const item = aplicaveis.find(result => result.slug === slug);
            return item && item.pronto;
        });

        const commercialReady = commercialEligible.length === 0 ? true : commercialEligible.some(slug => {
            const item = aplicaveis.find(result => result.slug === slug);
            return item && item.pronto;
        });

        const globalReady = coreReady && commercialReady;

        document.getElementById('score-geral').textContent = total > 0 ? formatPercent(averageScore) : 'N/A';
        document.getElementById('processos-prontos').textContent = total > 0 ? `${readyCount}/${total}` : '0/0';
        document.getElementById('autonomia-media').textContent = total > 0 ? formatPercent(averageAutonomy) : 'N/A';
        document.getElementById('status-global').textContent = total === 0 ? 'N/A' : (globalReady ? 'Sim' : 'Nao');
        document.getElementById('status-global-copy').textContent = total === 0
            ? 'Nenhum módulo está ativo para este cliente.'
            : (globalReady ? 'Todos os obrigatorios estao validados e o fluxo comercial foi testado.' : 'Ainda existem etapas criticas sem validacao suficiente.');

        const fluxoBadge = document.getElementById('fluxo-comercial-badge');
        fluxoBadge.textContent = total === 0 ? 'Sem módulos ativos' : (commercialReady ? 'Fluxo comercial validado' : 'Fluxo comercial nao validado');
        fluxoBadge.className = total === 0
            ? 'badge bg-body border text-muted px-3 py-2 rounded-pill'
            : (commercialReady ? 'badge bg-success-subtle text-success-emphasis border border-success-subtle px-3 py-2 rounded-pill' : 'badge bg-body border text-muted px-3 py-2 rounded-pill');

        const leituraGeral = document.getElementById('leitura-geral');
        const leituraCopy = document.getElementById('leitura-geral-copy');

        if (total === 0) {
            leituraGeral.textContent = 'Nenhum módulo ativo para este cliente.';
            leituraCopy.textContent = 'Use o flag “Sim/Não” para marcar os módulos que realmente farão parte do processo.';
            return;
        }

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

        const criticalGaps = aplicaveis
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
            card.querySelector('.campo-utiliza').value = '1';
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
