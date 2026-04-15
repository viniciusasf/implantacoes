<?php
date_default_timezone_set('America/Sao_Paulo');
require_once 'config.php';

// 1. DEFINIR VARIÁVEIS COM VALORES PADRÃO
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'cards';
$filtro = isset($_GET['filtro']) ? trim($_GET['filtro']) : '';
$estagio = isset($_GET['estagio']) ? $_GET['estagio'] : 'integracao';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$mostrar_encerrados = isset($_GET['mostrar_encerrados']) ? $_GET['mostrar_encerrados'] : '0';

// Ao realizar uma busca, queremos procurar em TODOS os estágios simultaneamente
if (!empty($busca)) {
    $estagio = '';
}

function encerrarImplantacaoCliente(PDO $pdo, $idCliente, $cancelada = false)
{
    $idCliente = (int)$idCliente;
    if ($idCliente <= 0) {
        return;
    }

    $dataAtual = date('Y-m-d');
    $dataHoraAtual = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        if ($cancelada) {
            $stmtCliente = $pdo->prepare(
                "UPDATE clientes
                 SET status = 'CANCELADA',
                     data_fim = ?,
                     observacao = CONCAT(IFNULL(observacao, ''), ' [IMPLANTACAO CANCELADA EM ', CURDATE(), ']'),
                     status_tratativa = 'pendente',
                     data_inicio_tratativa = NULL
                 WHERE id_cliente = ?"
            );
            $stmtCliente->execute([$dataAtual, $idCliente]);
        } else {
            $stmtCliente = $pdo->prepare(
                "UPDATE clientes
                 SET status = 'CONCLUIDA',
                     data_fim = ?,
                     status_tratativa = 'pendente',
                     data_inicio_tratativa = NULL
                 WHERE id_cliente = ?"
            );
            $stmtCliente->execute([$dataAtual, $idCliente]);
        }

        // Fecha treinamentos pendentes para remover o cliente dos fluxos de atendimento.
        $observacaoFechamento = $cancelada
            ? '[Encerrado automaticamente por cancelamento da implantacao em ' . $dataAtual . ']'
            : '[Encerrado automaticamente por conclusao da implantacao em ' . $dataAtual . ']';

        $stmtTreinamentos = $pdo->prepare(
            "UPDATE treinamentos
             SET status = 'Resolvido',
                 data_treinamento_encerrado = ?,
                 observacoes = CONCAT(IFNULL(observacoes, ''), CASE WHEN IFNULL(observacoes, '') = '' THEN '' ELSE ' ' END, ?)
             WHERE id_cliente = ?
               AND status = 'PENDENTE'"
        );
        $stmtTreinamentos->execute([$dataHoraAtual, $observacaoFechamento, $idCliente]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// 2. Logica para Acoes Rapidas: Concluir e Cancelar
if (isset($_GET['concluir'])) {
    $id = $_GET['concluir'];
    encerrarImplantacaoCliente($pdo, $id, false);
    header("Location: clientes.php?msg=Implantacao+concluida+com+sucesso&view=" . $view_mode);
    exit;
}

if (isset($_GET['cancelar'])) {
    $id = $_GET['cancelar'];
    encerrarImplantacaoCliente($pdo, $id, true);
    header("Location: clientes.php?msg=Implantacao+cancelada+com+sucesso&view=" . $view_mode);
    exit;
}
// 3. Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM clientes WHERE id_cliente = ?");
    $stmt->execute([$id]);
    header("Location: clientes.php?msg=Cliente+removido+com+sucesso&view=" . $view_mode);
    exit;
}

// 4. Lógica para Adicionar/Editar - ATUALIZADO COM NOVOS CAMPOS
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fantasia = $_POST['fantasia'];
    $servidor = $_POST['servidor'];
    $vendedor = $_POST['vendedor'];
    $telefone = $_POST['telefone'] ?? '';
    $data_inicio = $_POST['data_inicio'];
    $data_fim = (!empty($_POST['data_fim']) && $_POST['data_fim'] !== '0000-00-00') ? $_POST['data_fim'] : null;
    $observacao = $_POST['observacao'] ?? '';
    $emitir_nf = $_POST['emitir_nf'] ?? 'Não';
    $configurado = $_POST['configurado'] ?? 'Não';

    // NOVOS CAMPOS
    $num_licencas = $_POST['num_licencas'] ?? 0;
    $anexo = $_POST['anexo'] ?? '';
    
    // CAMPO DE RECURSOS
    $recursos_arr = $_POST['recursos'] ?? [];
    $recursos = is_array($recursos_arr) ? implode(', ', $recursos_arr) : (string)$recursos_arr;

    if (isset($_POST['id_cliente']) && !empty($_POST['id_cliente'])) {
        // UPDATE com novos campos
        $stmt = $pdo->prepare("UPDATE clientes SET 
            fantasia=?, servidor=?, vendedor=?, telefone_ddd=?, 
            data_inicio=?, data_fim=?, observacao=?, 
            emitir_nf=?, configurado=?, num_licencas=?, anexo=?, recursos=? 
            WHERE id_cliente=?");
        $stmt->execute([
            $fantasia,
            $servidor,
            $vendedor,
            $telefone,
            $data_inicio,
            $data_fim,
            $observacao,
            $emitir_nf,
            $configurado,
            $num_licencas,
            $anexo,
            $recursos,
            $_POST['id_cliente']
        ]);
    } else {
        // INSERT com novos campos
        $stmt = $pdo->prepare("INSERT INTO clientes (
            fantasia, servidor, vendedor, telefone_ddd, 
            data_inicio, data_fim, observacao, 
            emitir_nf, configurado, num_licencas, anexo, recursos
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $fantasia,
            $servidor,
            $vendedor,
            $telefone,
            $data_inicio,
            $data_fim,
            $observacao,
            $emitir_nf,
            $configurado,
            $num_licencas,
            $anexo,
            $recursos
        ]);
    }
    header("Location: clientes.php?msg=Dados+atualizados&view=" . $view_mode);
    exit;
}

// 5. Consulta de Dados e Filtros - FILTRAR CLIENTES NÃO ENCERRADOS
$sql = "SELECT c.*, 
               COUNT(t.id_treinamento) as total_treinamentos,
               MAX(t.data_treinamento) as ultimo_treinamento,
               SUM(CASE WHEN t.status = 'PENDENTE' THEN 1 ELSE 0 END) as treinamentos_pendentes
        FROM clientes c
        LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
        WHERE 1=1";
$params = [];

// Por padrão, não mostrar clientes com implantação encerrada
// Exceto quando há uma busca ativa (nesse caso, mostrar todos, inclusive encerrados)
if ($mostrar_encerrados != '1' && empty($busca)) {
    $sql .= " AND (c.data_fim IS NULL OR c.data_fim = '0000-00-00')";
}

// Busca por nome do cliente
if (!empty($busca)) {
    $sql .= " AND c.fantasia LIKE ?";
    $params[] = "%$busca%";
}

$sql .= " GROUP BY c.id_cliente ORDER BY c.fantasia ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$todos_clientes = $stmt->fetchAll();

// Buscar contatos de todos os clientes listados (uma query só, sem AJAX)
$ids_clientes = array_column($todos_clientes, 'id_cliente');
$contatos_por_cliente = [];
if (!empty($ids_clientes)) {
    $placeholders_c = implode(',', array_fill(0, count($ids_clientes), '?'));
    $stmtCt = $pdo->prepare("SELECT id_contato, id_cliente, nome FROM contatos WHERE id_cliente IN ($placeholders_c) ORDER BY nome ASC");
    $stmtCt->execute($ids_clientes);
    foreach ($stmtCt->fetchAll() as $ct) {
        $contatos_por_cliente[$ct['id_cliente']][] = ['id' => $ct['id_contato'], 'nome' => $ct['nome']];
    }
}

// 6. Lógica de Contagem para os CARDS - SOMENTE CLIENTES ATIVOS
$integracao = 0;
$operacional = 0;
$finalizacao = 0;
$critico = 0;
$encerrados = 0;
$clientes_filtrados = [];

foreach ($todos_clientes as $cl) {
    // Verificar se o cliente está encerrado
    $cliente_encerrado = false;
    if (!empty($cl['data_fim']) && $cl['data_fim'] !== '0000-00-00') {
        $data_fim = trim($cl['data_fim']);
        if ($data_fim !== '' && $data_fim !== '0000-00-00') {
            $cliente_encerrado = true;
        }
    }

    // Contar clientes encerrados
    if ($cliente_encerrado) {
        $encerrados++;
    }

    $status_cl = "concluido";
    if (!$cliente_encerrado) {
        $d = (new DateTime($cl['data_inicio']))->diff(new DateTime())->days;
        if ($d <= 15) {
            $integracao++;
            $status_cl = "integracao";
        } elseif ($d <= 30) {
            $operacional++;
            $status_cl = "operacional";
        } elseif ($d <= 60) {
            $finalizacao++;
            $status_cl = "finalizacao";
        } else {
            $critico++;
            $status_cl = "critico";
        }
    } else {
        $status_cl = "encerrado";
    }

    // Adiciona à lista filtrada se passar pelo filtro de estágio
    // Quando há busca ativa, exibe todos os resultados (inclusive encerrados)
    if (!empty($busca)) {
        $clientes_filtrados[] = $cl;
    } elseif ($estagio == '') {
        if ($status_cl != 'encerrado') {
            $clientes_filtrados[] = $cl;
        }
    } elseif ($estagio == $status_cl) {
        $clientes_filtrados[] = $cl;
    }
}

include 'header.php';
?>

<style>
/* Design System Clean & Modern (Perplexity Style) */
:root {
    --glass-bg: rgba(255, 255, 255, 0.03);
    --glass-border: rgba(255, 255, 255, 0.08);
}

[data-theme="dark"] {
    --bg-body: #0d0e12;
    --bg-card: #16171d;
    --border-color: #2b2e35;
    --text-main: #f1f5f9;
    --text-muted: #cbd5e1; /* Cor mais clara para melhor legibilidade */
    --primary-light: rgba(67, 97, 238, 0.2);
    --success-light: rgba(16, 185, 129, 0.2);
    --warning-light: rgba(245, 158, 11, 0.2);
    --danger-light: rgba(239, 68, 68, 0.2);
    --info-light: rgba(6, 182, 212, 0.2);
}

body, html {
    background-color: var(--bg-body);
    color: var(--text-main);
}

/* Modern Page Header */
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

/* Status Filter Cards */
.status-pill-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 1rem;
    margin-bottom: 2.5rem;
}

.status-pill-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    padding: 1.25rem;
    border-radius: var(--radius-lg);
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.status-pill-card:hover {
    transform: translateY(-4px);
    border-color: var(--primary);
    box-shadow: var(--shadow-md);
}

.status-pill-card.active {
    background: var(--primary-light);
    border-color: var(--primary);
}

.status-pill-card h2 {
    font-size: 1.75rem;
    font-weight: 800;
    margin-bottom: 0.25rem;
}

.status-pill-card span {
    font-size: 0.7rem;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: var(--text-muted);
}

/* Client Card Refined */
.client-card-premium {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    transition: all 0.3s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.client-card-premium:hover {
    transform: translateY(-5px);
    border-color: var(--primary-light);
    box-shadow: var(--shadow-lg);
}

.client-name {
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--text-main);
    margin-bottom: 0.5rem;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.client-meta {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin-bottom: 1rem;
    font-weight: 500;
}

.client-badge-soft {
    font-size: 0.65rem;
    font-weight: 800;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    text-transform: uppercase;
}

/* Controls */
.control-bar {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 1rem;
    margin-bottom: 2rem;
    display: flex;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.search-input-modern {
    background: var(--bg-body) !important;
    border: 1px solid var(--border-color) !important;
    border-radius: 10px !important;
    color: var(--text-main) !important;
    padding-left: 2.5rem !important;
}

.btn-modern {
    height: 42px;
    border-radius: 10px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}

/* Action Buttons stylized */
.btn-action {
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    background: rgba(255,255,255,0.05);
    color: var(--text-muted);
    border: 1px solid var(--border-color);
    text-decoration: none;
}

.btn-action:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}

.btn-action.edit { color: #4361ee; background: rgba(67, 97, 238, 0.15); border-color: rgba(67, 97, 238, 0.3); }
.btn-action.edit:hover { background: #4361ee; color: white; }

.btn-action.treinamentos { color: #4cc9f0; background: rgba(76, 201, 240, 0.15); border-color: rgba(76, 201, 240, 0.3); }
.btn-action.treinamentos:hover { background: #4cc9f0; color: white; }

.btn-action.concluir { color: #10b981; background: rgba(16, 185, 129, 0.15); border-color: rgba(16, 185, 129, 0.3); }
.btn-action.concluir:hover { background: #10b981; color: white; }

.btn-action.cancelar { color: #f59e0b; background: rgba(245, 158, 11, 0.15); border-color: rgba(245, 158, 11, 0.3); }
.btn-action.cancelar:hover { background: #f59e0b; color: white; }

.btn-action.delete { color: #ef4444; background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); }
.btn-action.delete:hover { background: #ef4444; color: white; }

.btn-action.agendar { color: #7c3aed; background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
.btn-action.agendar:hover { background: #7c3aed; color: white; }

/* Grid & Table Animations */
.gsap-reveal {
    opacity: 0;
    transform: translateY(20px);
}

.custom-scroll {
    scrollbar-width: thin;
    scrollbar-color: var(--border-color) transparent;
}
.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }

</style>

<div class="container-fluid px-lg-5 pt-4">

    <!-- Modern Header -->
    <div class="modern-header d-flex justify-content-between align-items-end gsap-reveal">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.8rem;">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Clientes</li>
                </ol>
            </nav>
            <h2 class="fw-800 mb-0">Gestão de <span class="title-accent">Clientes</span></h2>
            <p class="text-muted small mb-0">Controle total do fluxo de implantação.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="clientes.php?mostrar_encerrados=<?= $mostrar_encerrados == '1' ? '0' : '1' ?>&view=<?= urlencode($view_mode) ?>&busca=<?= urlencode($busca) ?>&estagio="
               class="btn btn-outline-secondary btn-modern px-3">
                <i class="bi <?= $mostrar_encerrados == '1' ? 'bi-eye-slash text-danger' : 'bi-eye text-success' ?>"></i>
                Ver Ativos/Encerrados
            </a>
            <button class="btn btn-primary btn-modern px-4" data-bs-toggle="modal" data-bs-target="#modalCliente">
                <i class="bi bi-plus-lg"></i> Novo Cliente
            </button>
        </div>
    </div>

    <!-- Status Filter Grid -->
    <div class="status-pill-grid gsap-reveal">
        <?php if ($mostrar_encerrados == '1'): ?>
        <div class="status-pill-card <?= $estagio == '' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=&view=<?= $view_mode ?>&mostrar_encerrados=<?= $mostrar_encerrados ?>'">
            <span>Ativos</span>
            <h2 class="text-main"><?= $integracao + $operacional + $finalizacao + $critico ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(255, 255, 255, 0.05);">
                <div class="progress-bar bg-primary" style="width: 100%"></div>
            </div>
        </div>
        <?php endif; ?>
        <div class="status-pill-card <?= $estagio == 'integracao' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=integracao&view=<?= $view_mode ?>&mostrar_encerrados=<?= $mostrar_encerrados ?>'">
            <span>Integração | 0 a 15 dias</span>
            <h2 class="text-primary"><?= $integracao ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(67, 97, 238, 0.1);">
                <div class="progress-bar bg-primary" style="width: 100%"></div>
            </div>
        </div>
        <div class="status-pill-card <?= $estagio == 'operacional' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=operacional&view=<?= $view_mode ?>&mostrar_encerrados=<?= $mostrar_encerrados ?>'">
            <span>Operacional | 16 a 30 dias</span>
            <h2 class="text-info"><?= $operacional ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(6, 182, 212, 0.1);">
                <div class="progress-bar bg-info" style="width: 100%"></div>
            </div>
        </div>
        <div class="status-pill-card <?= $estagio == 'finalizacao' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=finalizacao&view=<?= $view_mode ?>&mostrar_encerrados=<?= $mostrar_encerrados ?>'">
            <span>Finalização | 31 a 60 dias</span>
            <h2 class="text-success"><?= $finalizacao ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(16, 185, 129, 0.1);">
                <div class="progress-bar bg-success" style="width: 100%"></div>
            </div>
        </div>
        <div class="status-pill-card <?= $estagio == 'critico' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=critico&view=<?= $view_mode ?>&mostrar_encerrados=<?= $mostrar_encerrados ?>'">
            <span>Atenção | 61 a 90 dias</span>
            <h2 class="text-danger"><?= $critico ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(239, 68, 68, 0.1);">
                <div class="progress-bar bg-danger" style="width: 100%"></div>
            </div>
        </div>
        <?php if ($mostrar_encerrados == '1'): ?>
        <div class="status-pill-card <?= $estagio == 'encerrado' ? 'active' : '' ?>" onclick="window.location.href='clientes.php?estagio=encerrado&view=<?= $view_mode ?>&mostrar_encerrados=1'">
            <span>Encerrados</span>
            <h2 class="text-muted"><?= $encerrados ?></h2>
            <div class="progress mt-2" style="height: 4px; background: rgba(148, 163, 184, 0.1);">
                <div class="progress-bar bg-secondary" style="width: 100%"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Controls Bar -->
    <div class="control-bar gsap-reveal">
        <div class="search-container position-relative flex-grow-1" style="max-width: 500px;">
            <i class="bi bi-search position-absolute text-muted" style="left: 1rem; top: 50%; transform: translateY(-50%);"></i>
            <form method="GET" action="clientes.php" id="searchForm">
                <input type="text" name="busca" id="searchInput" class="form-control search-input-modern w-100" 
                       placeholder="Buscar cliente por nome ou fantasia..." value="<?= htmlspecialchars($busca) ?>">
                <input type="hidden" name="view" value="<?= $view_mode ?>">
                <input type="hidden" name="estagio" value="<?= $estagio ?>">
                <input type="hidden" name="mostrar_encerrados" value="<?= $mostrar_encerrados ?>">
            </form>
        </div>
        <div class="d-flex gap-2 ms-auto">
            <div class="bg-body p-1 rounded-3 d-flex border">
                <button onclick="changeViewMode('cards')" class="btn btn-sm <?= $view_mode == 'cards' ? 'btn-primary shadow-sm' : 'btn-link text-muted' ?> bx-button px-3">
                    <i class="bi bi-grid-3x3-gap"></i>
                </button>
                <button onclick="changeViewMode('list')" class="btn btn-sm <?= $view_mode == 'list' ? 'btn-primary shadow-sm' : 'btn-link text-muted' ?> bx-button px-3">
                    <i class="bi bi-list"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Main View Content -->
    <div class="row g-4 mb-5">
        <?php if ($view_mode == 'cards'): ?>
            <?php if (empty($clientes_filtrados)): ?>
                <div class="col-12 text-center py-5">
                    <i class="bi bi-search display-1 text-muted opacity-25 mb-4 d-block"></i>
                    <h4 class="text-muted">Nenhum cliente encontrado nesta categoria.</h4>
                </div>
            <?php endif; ?>

            <?php foreach ($clientes_filtrados as $index => $c): 
                $d = (new DateTime($c['data_inicio']))->diff(new DateTime())->days;
                $cliente_encerrado = (!empty($c['data_fim']) && $c['data_fim'] !== '0000-00-00');
                
                $status_config = [
                    'integracao' => ['label' => 'Integração', 'class' => 'bg-info bg-opacity-10 text-info', 'icon' => 'bi-rocket-takeoff'],
                    'operacional' => ['label' => 'Operacional', 'class' => 'bg-primary bg-opacity-10 text-primary', 'icon' => 'bi-gear'],
                    'finalizacao' => ['label' => 'Finalização', 'class' => 'bg-success bg-opacity-10 text-success', 'icon' => 'bi-flag'],
                    'critico' => ['label' => 'Atenção', 'class' => 'bg-danger bg-opacity-10 text-danger', 'icon' => 'bi-exclamation-triangle'],
                    'encerrado' => ['label' => 'Encerrado', 'class' => 'bg-secondary bg-opacity-10 text-secondary', 'icon' => 'bi-archive']
                ];

                $current_status = 'integracao';
                if ($cliente_encerrado) $current_status = 'encerrado';
                elseif ($d > 60) $current_status = 'critico';
                elseif ($d > 30) $current_status = 'finalizacao';
                elseif ($d > 15) $current_status = 'operacional';

                $cfg = $status_config[$current_status];
            ?>
            <div class="col-md-6 col-xl-4 gsap-reveal">
                <div class="client-card-premium">
                    <div class="client-name">
                        <span class="text-truncate" title="<?= htmlspecialchars($c['fantasia']) ?>"><?= htmlspecialchars($c['fantasia']) ?></span>
                        <span class="client-badge-soft <?= $cfg['class'] ?>">
                            <i class="bi <?= $cfg['icon'] ?> me-1"></i> <?= $cfg['label'] ?>
                        </span>
                    </div>
                    
                    <div class="client-meta">
                        <div class="d-flex align-items-center mb-1">
                            <i class="bi bi-person-badge me-2 text-muted"></i> <?= htmlspecialchars($c['vendedor']) ?>
                        </div>
                        <div class="d-flex align-items-center">
                            <i class="bi bi-server me-2 text-muted"></i> <?= htmlspecialchars($c['servidor']) ?>
                        </div>
                    </div>

                    <div class="mt-auto">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted">Início: <?= date('d/m/Y', strtotime($c['data_inicio'])) ?></span>
                            <span class="fw-bold text-main"><?= $d ?> dias</span>
                        </div>
                        <div class="progress mb-3" style="height: 6px; border-radius: 10px; background: var(--bg-body);">
                            <?php 
                                $pct = min(100, ($d / 60) * 100);
                                $bar_class = $d > 60 ? 'bg-danger' : ($d > 30 ? 'bg-success' : ($d > 15 ? 'bg-primary' : 'bg-info'));
                                if ($cliente_encerrado) { $pct = 100; $bar_class = 'bg-success'; }
                            ?>
                            <div class="progress-bar <?= $bar_class ?>" style="width: <?= $pct ?>%"></div>
                        </div>

                        <?php if ($c['treinamentos_pendentes'] > 0): ?>
                            <div class="alert alert-danger py-2 px-3 mb-3 border-0 small d-flex align-items-center rounded-3">
                                <i class="bi bi-exclamation-circle-fill me-2"></i>
                                <span><b><?= $c['treinamentos_pendentes'] ?></b> treinamento(s) pendente(s)</span>
                            </div>
                        <?php endif; ?>

                        <div class="control-bar p-2 mb-0" style="background: var(--bg-body); border-radius: 12px; border: none; gap: 4px;">
                            <button class="btn btn-sm btn-action edit edit-trigger" 
                                    data-id="<?= $c['id_cliente'] ?>" 
                                    data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>" 
                                    data-servidor="<?= $c['servidor'] ?>" 
                                    data-vendedor="<?= $c['vendedor'] ?>" 
                                    data-telefone="<?= $c['telefone_ddd'] ?>" 
                                    data-inicio="<?= $c['data_inicio'] ?>" 
                                    data-fim="<?= $c['data_fim'] ?>" 
                                    data-obs="<?= htmlspecialchars($c['observacao']) ?>" 
                                    data-nf="<?= $c['emitir_nf'] ?>" 
                                    data-cfg="<?= $c['configurado'] ?>" 
                                    data-licencas="<?= $c['num_licencas'] ?>" 
                                    data-anexo="<?= $c['anexo'] ?>" 
                                    data-recursos="<?= htmlspecialchars($c['recursos'] ?? '') ?>" 
                                    title="Editar" onclick="openEditModal(this)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <a href="treinamentos_cliente.php?id_cliente=<?= $c['id_cliente'] ?>" class="btn btn-sm btn-action treinamentos" title="Ver Treinamentos">
                                <i class="bi bi-calendar-check"></i>
                            </a>
                            <button class="btn btn-sm btn-action agendar novo-treino-trigger"
                                    data-id="<?= $c['id_cliente'] ?>"
                                    data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>"
                                    data-contatos="<?= htmlspecialchars(json_encode($contatos_por_cliente[$c['id_cliente']] ?? []), ENT_QUOTES) ?>"
                                    title="Agendar Novo Treinamento"
                                    onclick="openNovoTreinamentoModal(this)">
                                <i class="bi bi-calendar-plus"></i>
                            </button>
                            <?php if (!$cliente_encerrado): ?>
                                <a href="?concluir=<?= $c['id_cliente'] ?>&view=<?= $view_mode ?>" class="btn btn-sm btn-action concluir" title="Concluir" onclick="return confirm('Concluir implantação?')">
                                    <i class="bi bi-check-lg"></i>
                                </a>
                                <a href="?cancelar=<?= $c['id_cliente'] ?>&view=<?= $view_mode ?>" class="btn btn-sm btn-action cancelar" title="Cancelar" onclick="return confirm('Cancelar implantação?')">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            <?php endif; ?>
                            <a href="?delete=<?= $c['id_cliente'] ?>&view=<?= $view_mode ?>" class="btn btn-sm btn-action delete ms-auto" title="Excluir" onclick="return confirm('Excluir cliente?')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <!-- Table View Modernized -->
            <div class="col-12 gsap-reveal">
                <div class="dashboard-section p-0 overflow-hidden">
                    <div class="table-responsive custom-scroll" style="max-height: 700px;">
                        <table class="table-dashboard">
                            <thead>
                                <tr>
                                    <th class="ps-4">Cliente / Início</th>
                                    <th>Vendedor / Servidor</th>
                                    <th class="text-center">Duração</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-end pe-4">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($clientes_filtrados)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5">
                                            <h5 class="text-muted">Nenhum cliente encontrado.</h5>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($clientes_filtrados as $c): 
                                    $d = (new DateTime($c['data_inicio']))->diff(new DateTime())->days;
                                    $cliente_encerrado = (!empty($c['data_fim']) && $c['data_fim'] !== '0000-00-00');
                                    
                                    $status_config = [
                                        'integracao' => ['label' => 'Integração', 'class' => 'bg-info bg-opacity-10 text-info', 'icon' => 'bi-rocket-takeoff'],
                                        'operacional' => ['label' => 'Operacional', 'class' => 'bg-primary bg-opacity-10 text-primary', 'icon' => 'bi-gear'],
                                        'finalizacao' => ['label' => 'Finalização', 'class' => 'bg-success bg-opacity-10 text-success', 'icon' => 'bi-flag'],
                                        'critico' => ['label' => 'Atenção', 'class' => 'bg-danger bg-opacity-10 text-danger', 'icon' => 'bi-exclamation-triangle'],
                                        'encerrado' => ['label' => 'Encerrado', 'class' => 'bg-secondary bg-opacity-10 text-secondary', 'icon' => 'bi-archive']
                                    ];

                                    $current_status = 'integracao';
                                    if ($cliente_encerrado) $current_status = 'encerrado';
                                    elseif ($d > 60) $current_status = 'critico';
                                    elseif ($d > 30) $current_status = 'finalizacao';
                                    elseif ($d > 15) $current_status = 'operacional';
                                    $cfg = $status_config[$current_status] ?? ['label' => 'Desconhecido', 'class' => 'bg-secondary', 'icon' => 'bi-question'];
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold"><?= htmlspecialchars($c['fantasia']) ?></div>
                                        <div class="text-muted small"><?= date('d/m/Y', strtotime($c['data_inicio'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-bold"><?= htmlspecialchars($c['vendedor']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($c['servidor']) ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark rounded-pill px-3 shadow-sm"><?= $d ?> dias</span>
                                    </td>
                                    <td class="text-center">
                                        <span class="client-badge-soft <?= $cfg['class'] ?>"><?= $cfg['label'] ?></span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-flex justify-content-end gap-1">
                                            <button class="btn btn-sm btn-action edit edit-trigger" 
                                                    data-id="<?= $c['id_cliente'] ?>" 
                                                    data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>" 
                                                    data-servidor="<?= $c['servidor'] ?>" 
                                                    data-vendedor="<?= $c['vendedor'] ?>" 
                                                    data-telefone="<?= $c['telefone_ddd'] ?>" 
                                                    data-inicio="<?= $c['data_inicio'] ?>" 
                                                    data-fim="<?= $c['data_fim'] ?>" 
                                                    data-obs="<?= htmlspecialchars($c['observacao']) ?>" 
                                                    data-nf="<?= $c['emitir_nf'] ?>" 
                                                    data-cfg="<?= $c['configurado'] ?>" 
                                                    data-licencas="<?= $c['num_licencas'] ?>" 
                                                    data-anexo="<?= $c['anexo'] ?>" 
                                                    data-recursos="<?= htmlspecialchars($c['recursos'] ?? '') ?>" 
                                                    onclick="openEditModal(this)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <a href="treinamentos_cliente.php?id_cliente=<?= $c['id_cliente'] ?>" class="btn btn-sm btn-action treinamentos" title="Ver Treinamentos">
                                                <i class="bi bi-calendar-check"></i>
                                            </a>
                                            <button class="btn btn-sm btn-action agendar novo-treino-trigger"
                                                    data-id="<?= $c['id_cliente'] ?>"
                                                    data-fantasia="<?= htmlspecialchars($c['fantasia']) ?>"
                                                    data-contatos="<?= htmlspecialchars(json_encode($contatos_por_cliente[$c['id_cliente']] ?? []), ENT_QUOTES) ?>"
                                                    title="Agendar Novo Treinamento"
                                                    onclick="openNovoTreinamentoModal(this)">
                                                <i class="bi bi-calendar-plus"></i>
                                            </button>
                                            <a href="?delete=<?= $c['id_cliente'] ?>&view=<?= $view_mode ?>" class="btn btn-sm btn-action delete" onclick="return confirm('Excluir cliente?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de Cliente Premium -->
<div class="modal fade" id="modalCliente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 24px; overflow: hidden;">
            <div class="modal-header p-4 border-0 bg-main text-white position-relative">
                <div class="position-relative z-1">
                    <h5 class="modal-title fw-bold d-flex align-items-center" id="modalTitle">
                        <i class="bi bi-person-plus-fill me-3 fs-3"></i> Ficha do Cliente
                    </h5>
                    <p class="mb-0 opacity-75 small">Gerencie as informações e configurações da implantação</p>
                </div>
                <button type="button" class="btn-close btn-close-white position-relative z-1" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="position-absolute top-0 start-0 w-100 h-100" style="background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);"></div>
            </div>

            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="id_cliente" id="id_cliente">
                
                <div class="dashboard-section p-4 mb-4">
                    <h6 class="text-main fw-bold mb-3 d-flex align-items-center">
                        <i class="bi bi-info-circle me-2"></i> Identificação Principal
                    </h6>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted">Nome Fantasia / Empresa</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-building text-primary"></i></span>
                                <input type="text" name="fantasia" id="fantasia" class="form-control border-start-0 ps-0" required placeholder="Ex: Panificadora Silva">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Vendedor Responsável</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-person-badge text-primary"></i></span>
                                <input type="text" name="vendedor" id="vendedor" class="form-control border-start-0 ps-0" placeholder="Nome do vendedor">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Servidor</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-server text-primary"></i></span>
                                <input type="text" name="servidor" id="servidor" class="form-control border-start-0 ps-0" placeholder="Ex: LOCAL / NUVEM">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-7">
                        <div class="dashboard-section p-4 h-100">
                            <h6 class="text-main fw-bold mb-3 d-flex align-items-center">
                                <i class="bi bi-gear-wide-connected me-2"></i> Configurações Técnicas
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Data de Início</label>
                                    <input type="date" name="data_inicio" id="data_inicio" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Data de Conclusão</label>
                                    <input type="date" name="data_fim" id="id_data_fim" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Emitir NF?</label>
                                    <select name="emitir_nf" id="emitir_nf" class="form-select" onchange="toggleConfigurado(this.value)">
                                        <option value="Não">Não</option>
                                        <option value="Sim">Sim</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Nº de Licenças</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-key text-primary"></i></span>
                                        <input type="number" name="num_licencas" id="num_licencas" class="form-control border-start-0 ps-0" placeholder="0">
                                    </div>
                                </div>
                                <div class="col-md-6" id="div_configurado" style="display: none;">
                                    <label class="form-label small fw-bold text-muted">Já Configurado?</label>
                                    <select name="configurado" id="configurado" class="form-select bg-warning bg-opacity-10 border-warning border-opacity-25">
                                        <option value="Não">Não</option>
                                        <option value="Sim">Sim</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-bold text-muted">Google Drive / Anexo</label>
                                    <div class="input-group mb-1">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-link-45deg text-primary"></i></span>
                                        <input type="text" name="anexo" id="anexo" class="form-control border-start-0 ps-0" placeholder="Link do Drive">
                                        <button class="btn btn-primary" type="button" onclick="abrirAnexo()">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" style="font-size: 0.7rem;">Link do contrato ou documentos</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="dashboard-section p-4 h-100">
                            <h6 class="text-main fw-bold mb-3 d-flex align-items-center">
                                <i class="bi bi-plus-circle me-2"></i> Extras
                            </h6>
                            <div class="mb-0">
                                <label class="form-label small fw-bold text-muted">Recursos Utilizados</label>
                                <div class="p-2 border rounded bg-white">
                                    <?php 
                                    $lista_recursos = ['ORÇAMENTO', 'CATÁLOGO', 'GESTAOWEB', 'SERVIÇO/OS', 'PRODUÇÃO/OS', 'PDV'];
                                    foreach($lista_recursos as $rec): ?>
                                    <div class="form-check mb-1">
                                      <input class="form-check-input recurso-checkbox" type="checkbox" name="recursos[]" value="<?= $rec ?>" id="rec_<?= md5($rec) ?>">
                                      <label class="form-check-label small" for="rec_<?= md5($rec) ?>"><?= $rec ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="dashboard-section p-4">
                            <h6 class="text-main fw-bold mb-3 d-flex align-items-center">
                                <i class="bi bi-chat-right-text me-2"></i> Observações Internas
                            </h6>
                            <textarea name="observacao" id="observacao" class="form-control" rows="3" placeholder="Informações relevantes para a equipe técnica..."></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer p-4 border-0 bg-white">
                <button type="button" class="btn btn-link text-muted fw-bold text-decoration-none me-auto" data-bs-dismiss="modal">Descartar</button>
                <button type="submit" class="btn btn-primary px-5 fw-bold" style="border-radius: 12px; height: 50px;">
                    <i class="bi bi-check-lg me-2"></i> Salvar Cliente
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Rápido: Novo Treinamento -->
<div class="modal fade" id="modalNovoTreinamento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="salvar_treinamento.php" class="modal-content border-0 shadow-lg" style="border-radius: 24px; overflow: hidden;">
            <input type="hidden" name="id_cliente" id="nt_id_cliente">
            <input type="hidden" name="redirect_to" value="clientes.php">
            <div class="modal-header p-4 border-0" style="background: linear-gradient(135deg, #7c3aed, #4361ee);">
                <div>
                    <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                        <i class="bi bi-calendar-plus fs-4"></i> Agendar Treinamento
                    </h5>
                    <p class="mb-0 text-white opacity-75 small" id="nt_cliente_label">Cliente</p>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Cliente</label>
                    <div class="input-group">
                        <span class="input-group-text" style="background:rgba(124,58,237,0.1); border-color:rgba(124,58,237,0.3);"><i class="bi bi-building text-purple" style="color:#7c3aed"></i></span>
                        <input type="text" class="form-control fw-bold" id="nt_cliente_display" readonly
                               style="background:rgba(124,58,237,0.05); border-color:rgba(124,58,237,0.3); cursor:default;">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Contato / Interlocutor</label>
                    <input type="text" name="nome_contato" id="nt_nome_contato" class="form-control" placeholder="Digite o nome do contato..." required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Módulo / Tema</label>
                    <select name="tema" id="nt_tema" class="form-select" required>
                        <option value="INSTALAÇÃO SISTEMA">INSTALAÇÃO SISTEMA</option>
                        <option value="CADASTROS/ESTOQUE">CADASTROS/ESTOQUE</option>
                        <option value="VENDAS">VENDAS</option>
                        <option value="COMPRAS">COMPRAS</option>
                        <option value="FATURAMENTO/NF">FATURAMENTO/NF</option>
                        <option value="FINANCEIRO/CAIXA">FINANCEIRO/CAIXA</option>
                        <option value="PRODUÇÃO/OS">PRODUÇÃO/OS</option>
                        <option value="RELATÓRIOS">RELATÓRIOS</option>
                        <option value="ATENDIMENTOS">ATENDIMENTOS</option>
                        <option value="DUVIDAS">DUVIDAS</option>
                    </select>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-7">
                        <label class="form-label small fw-bold text-muted">Data e Horário</label>
                        <input type="datetime-local" name="data_treinamento" id="nt_data_treinamento" class="form-control" required>
                    </div>
                    <div class="col-5">
                        <label class="form-label small fw-bold text-muted">Status</label>
                        <select name="status" id="nt_status" class="form-select">
                            <option value="PENDENTE">PENDENTE</option>
                            <option value="AGENDADO">AGENDADO</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-link text-muted fw-bold text-decoration-none me-auto" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary px-5 fw-bold" style="border-radius: 12px; height: 46px;">
                    <i class="bi bi-check-lg me-2"></i> Agendar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Inicializar tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Revelação progressiva com GSAP
        if (typeof gsap !== 'undefined') {
            gsap.to(".gsap-reveal", {
                opacity: 1,
                y: 0,
                duration: 0.6,
                stagger: 0.1,
                ease: "power2.out"
            });
        } else {
            document.querySelectorAll(".gsap-reveal").forEach(el => el.style.opacity = 1);
        }

        // Lógica de busca automática com delay (debounce)
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            // Colocar foco e mover cursor para o final
            const val = searchInput.value;
            searchInput.value = '';
            searchInput.focus();
            searchInput.value = val;

            let timeout = null;
            searchInput.addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    document.getElementById('searchForm').submit();
                }, 500); // 500ms de delay
            });
        }
    });

    function changeViewMode(mode) {
        const url = new URL(window.location.href);
        url.searchParams.set('view', mode);
        window.location.href = url.toString();
    }

    function openEditModal(button) {
        const d = button.dataset;
        document.getElementById('modalTitle').innerText = 'Editar Cliente';
        document.getElementById('id_cliente').value = d.id;
        document.getElementById('fantasia').value = d.fantasia || '';
        document.getElementById('servidor').value = d.servidor || '';
        document.getElementById('vendedor').value = d.vendedor || '';
        document.getElementById('data_inicio').value = d.inicio || '';
        document.getElementById('id_data_fim').value = d.fim || '';
        document.getElementById('observacao').value = d.obs || '';
        document.getElementById('emitir_nf').value = d.nf || 'Não';
        document.getElementById('configurado').value = d.cfg || 'Não';
        document.getElementById('num_licencas').value = d.licencas || 0;
        document.getElementById('anexo').value = d.anexo || '';
        
        // Limpar e preencher checkbox de recursos
        document.querySelectorAll('.recurso-checkbox').forEach(cb => cb.checked = false);
        if (d.recursos) {
            const recursosArray = d.recursos.split(',').map(r => r.trim());
            document.querySelectorAll('.recurso-checkbox').forEach(cb => {
                if(recursosArray.includes(cb.value)) {
                    cb.checked = true;
                }
            });
        }
        
        toggleConfigurado(d.nf);
        
        const modal = new bootstrap.Modal(document.getElementById('modalCliente'));
        modal.show();
    }

    function toggleConfigurado(valor) {
        const div = document.getElementById('div_configurado');
        if (div) div.style.display = (valor === 'Sim') ? 'block' : 'none';
    }

    function abrirAnexo() {
        const link = document.getElementById('anexo').value.trim();
        if (!link) {
            Swal.fire({ icon: 'warning', title: 'Atenção', text: 'Nenhum link cadastrado.' });
            return;
        }
        window.open(link.startsWith('http') ? link : 'https://' + link, '_blank');
    }

    // --- Modal Novo Treinamento Rápido ---
    function openNovoTreinamentoModal(btn) {
        const idCliente = btn.dataset.id;
        const nomeCliente = btn.dataset.fantasia;
        const contatos = JSON.parse(btn.dataset.contatos || '[]');

        document.getElementById('nt_id_cliente').value = idCliente;
        document.getElementById('nt_cliente_label').textContent = nomeCliente;
        document.getElementById('nt_cliente_display').value = nomeCliente;
        document.getElementById('nt_nome_contato').value = '';
        document.getElementById('nt_tema').selectedIndex = 0;
        document.getElementById('nt_status').value = 'PENDENTE';
        document.getElementById('nt_data_treinamento').value = '';

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNovoTreinamento')).show();
    }
</script>

<?php include 'footer.php'; ?>
