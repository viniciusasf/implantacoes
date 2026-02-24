<?php
// Configurar timezone corretamente (Brasil)
date_default_timezone_set('America/Sao_Paulo');

require_once 'config.php';

function treinamentosTemColuna(PDO $pdo, $coluna, $forceRefresh = false)
{
    static $cache = [];
    if (!$forceRefresh && isset($cache[$coluna])) {
        return $cache[$coluna];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM treinamentos LIKE ?");
        $stmt->execute([$coluna]);
        $cache[$coluna] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$coluna] = false;
    }

    return $cache[$coluna];
}

function sincronizarGoogleMeetAutomatico($pdo, $idTreinamento)
{
    try {
        $autoloadPath = __DIR__ . '/vendor/autoload.php';
        $credentialsPath = __DIR__ . '/credentials.json';
        $tokenPath = __DIR__ . '/token.json';

        if (!file_exists($autoloadPath) || !file_exists($credentialsPath)) {
            return ['success' => false, 'message' => 'Integração Google não configurada.'];
        }

        require_once $autoloadPath;

        if (!file_exists($tokenPath)) {
            return ['success' => false, 'message' => 'Token Google ausente.'];
        }

        $tokenData = json_decode(file_get_contents($tokenPath), true);
        if (!is_array($tokenData)) {
            return ['success' => false, 'message' => 'Token Google inválido.'];
        }

        $client = new Google\Client();
        $client->setAuthConfig($credentialsPath);
        $client->addScope(Google\Service\Calendar::CALENDAR);
        $client->setAccessType('offline');
        $client->setAccessToken($tokenData);

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $client->getRefreshToken();
            if (empty($refreshToken)) {
                return ['success' => false, 'message' => 'Token expirado sem refresh token.'];
            }

            $novoToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            if (isset($novoToken['error'])) {
                return ['success' => false, 'message' => 'Falha ao renovar token Google.'];
            }

            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }

        $stmt = $pdo->prepare("SELECT t.*, c.fantasia as cliente_nome, co.nome as contato_nome
                               FROM treinamentos t
                               LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
                               LEFT JOIN contatos co ON t.id_contato = co.id_contato
                               WHERE t.id_treinamento = ?");
        $stmt->execute([$idTreinamento]);
        $treinamento = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$treinamento || empty($treinamento['data_treinamento'])) {
            return ['success' => false, 'message' => 'Treinamento inválido para sincronização.'];
        }

        $service = new Google\Service\Calendar($client);
        $startDate = new DateTime($treinamento['data_treinamento'], new DateTimeZone('America/Sao_Paulo'));
        $endDate = clone $startDate;
        $endDate->modify('+60 minutes');

        $event = new Google\Service\Calendar\Event([
            'summary' => '#' . $treinamento['id_treinamento'] . ' Treinamento: ' . ($treinamento['cliente_nome'] ?? 'Cliente'),
            'description' => "Tema: " . ($treinamento['tema'] ?? '') . "\nContato: " . ($treinamento['contato_nome'] ?? ''),
            'start' => ['dateTime' => $startDate->format(DateTime::RFC3339), 'timeZone' => 'America/Sao_Paulo'],
            'end' => ['dateTime' => $endDate->format(DateTime::RFC3339), 'timeZone' => 'America/Sao_Paulo'],
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => 'treino-' . $treinamento['id_treinamento'] . '-' . time(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet']
                ]
            ]
        ]);

        $createdEvent = $service->events->insert('primary', $event, ['conferenceDataVersion' => 1]);

        $googleEventId = $createdEvent->getId();
        $googleMeetLink = $createdEvent->getHangoutLink();

        if (empty($googleMeetLink)) {
            $conferenceData = $createdEvent->getConferenceData();
            if ($conferenceData && $conferenceData->getEntryPoints()) {
                foreach ($conferenceData->getEntryPoints() as $entryPoint) {
                    if ($entryPoint->getEntryPointType() === 'video' && !empty($entryPoint->getUri())) {
                        $googleMeetLink = $entryPoint->getUri();
                        break;
                    }
                }
            }
        }

        if (empty($googleMeetLink)) {
            $googleMeetLink = $createdEvent->htmlLink;
        }

        $stmtUpdate = $pdo->prepare("UPDATE treinamentos SET google_event_id = ?, google_event_link = ? WHERE id_treinamento = ?");
        $stmtUpdate->execute([$googleEventId, $googleMeetLink, $idTreinamento]);

        return ['success' => true, 'message' => 'Google Meet criado com sucesso.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// --- LÓGICA DE ALERTA: CLIENTES SEM AGENDAMENTO (PRÓXIMOS 3 DIAS) ---
$sql_alerta = "SELECT fantasia FROM clientes 
               WHERE (data_fim IS NULL OR data_fim = '0000-00-00') 
               AND id_cliente NOT IN (
                   SELECT DISTINCT id_cliente FROM treinamentos 
                   WHERE data_treinamento >= CURDATE() 
                   AND data_treinamento <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
               )";
$clientes_sem_agenda = $pdo->query($sql_alerta)->fetchAll(PDO::FETCH_COLUMN);

// --- CONTAGENS PARA OS CARDS ---
$total_pendentes = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE status = 'PENDENTE'")->fetchColumn();
$total_hoje = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE DATE(data_treinamento) = CURDATE()")->fetchColumn();

// --- FILTRO POR CLIENTE ---
$filtro_cliente = isset($_GET['filtro_cliente']) ? trim($_GET['filtro_cliente']) : '';
$data_inicio_export = isset($_GET['data_inicio']) ? trim($_GET['data_inicio']) : '';
$data_fim_export = (isset($_GET['data_fim']) && trim($_GET['data_fim']) !== '') ? trim($_GET['data_fim']) : date('Y-m-d');
$erro_exportacao = '';
$filtros_ativos = !empty($filtro_cliente) || !empty($data_inicio_export) || (isset($_GET['data_fim']) && trim($_GET['data_fim']) !== '');
$where_conditions = [];
$params = []; // Array para parâmetros posicionais

if (!empty($filtro_cliente)) {
    $where_conditions[] = "c.fantasia LIKE ?";
    $params[] = "%{$filtro_cliente}%";
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Exportação XLS de treinamentos resolvidos por período
if (isset($_GET['exportar_xls'])) {
    if (empty($data_inicio_export) || empty($data_fim_export)) {
        $erro_exportacao = "Informe data início e data fim para exportar.";
    } else {
        $data_inicio_obj = DateTime::createFromFormat('Y-m-d', $data_inicio_export);
        $data_fim_obj = DateTime::createFromFormat('Y-m-d', $data_fim_export);

        $data_inicio_valida = $data_inicio_obj && $data_inicio_obj->format('Y-m-d') === $data_inicio_export;
        $data_fim_valida = $data_fim_obj && $data_fim_obj->format('Y-m-d') === $data_fim_export;

        if (!$data_inicio_valida || !$data_fim_valida) {
            $erro_exportacao = "Datas inválidas para exportação.";
        } else {
            $data_inicio_sql = $data_inicio_obj->format('Y-m-d') . ' 00:00:00';
            $data_fim_sql = $data_fim_obj->format('Y-m-d') . ' 23:59:59';

            if ($data_inicio_sql > $data_fim_sql) {
                $erro_exportacao = "A data início não pode ser maior que a data fim.";
            } else {
                $sql_export = "
                    SELECT
                        t.data_treinamento,
                        c.fantasia,
                        c.vendedor,
                        co.nome AS nome_contato,
                        t.tema,
                        t.observacoes
                    FROM treinamentos t
                    LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
                    LEFT JOIN contatos co ON t.id_contato = co.id_contato
                    WHERE UPPER(t.status) = 'RESOLVIDO'
                      AND (c.data_fim IS NULL OR c.data_fim = '0000-00-00')
                      AND t.data_treinamento BETWEEN ? AND ?
                ";

                $params_export = [$data_inicio_sql, $data_fim_sql];

                if (!empty($filtro_cliente)) {
                    $sql_export .= " AND c.fantasia LIKE ?";
                    $params_export[] = "%{$filtro_cliente}%";
                }

                $sql_export .= " ORDER BY t.data_treinamento ASC";

                $stmt_export = $pdo->prepare($sql_export);
                $stmt_export->execute($params_export);
                $dados_export = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

                $nome_arquivo = 'relatorio_treinamentos_' . date('Ymd_His') . '.xls';
                header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
                header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
                header('Pragma: no-cache');
                header('Expires: 0');

                echo "\xEF\xBB\xBF";
                echo "<table border='1'>";
                echo "<tr>";
                echo "<th>data_treinamento</th>";
                echo "<th>fantasia</th>";
                echo "<th>vendedor</th>";
                echo "<th>nome_contato</th>";
                echo "<th>tema</th>";
                echo "<th>observacoes</th>";
                echo "</tr>";

                foreach ($dados_export as $linha) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars(!empty($linha['data_treinamento']) ? date('d/m/Y H:i', strtotime($linha['data_treinamento'])) : '', ENT_QUOTES, 'UTF-8') . "</td>";
                    echo "<td>" . htmlspecialchars($linha['fantasia'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                    echo "<td>" . htmlspecialchars($linha['vendedor'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                    echo "<td>" . htmlspecialchars($linha['nome_contato'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                    echo "<td>" . htmlspecialchars($linha['tema'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                    echo "<td>" . htmlspecialchars($linha['observacoes'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                    echo "</tr>";
                }

                echo "</table>";
                exit;
            }
        }
    }
}

// Lógica para Deletar
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM treinamentos WHERE id_treinamento = ?");
    $stmt->execute([$id]);
    header("Location: treinamentos.php?msg=Removido com sucesso");
    exit;
}

// Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_cliente = $_POST['id_cliente'];
    $id_contato = $_POST['id_contato'];
    $tema = $_POST['tema'];
    $status = $_POST['status'];
    $data_treinamento = !empty($_POST['data_treinamento']) ? $_POST['data_treinamento'] : null;
    $has_google_event_link = array_key_exists('google_event_link', $_POST);
    $has_google_agenda_link = array_key_exists('google_agenda_link', $_POST);
    $google_event_link = $has_google_event_link && !empty($_POST['google_event_link']) ? trim($_POST['google_event_link']) : null;
    $google_agenda_link = $has_google_agenda_link && !empty($_POST['google_agenda_link']) ? trim($_POST['google_agenda_link']) : null;
    $tem_coluna_google_agenda = treinamentosTemColuna($pdo, 'google_agenda_link');

    if (isset($_POST['id_treinamento']) && !empty($_POST['id_treinamento'])) {
        $campos_update = [
            "id_cliente=?",
            "id_contato=?",
            "tema=?",
            "status=?",
            "data_treinamento=?"
        ];
        $params_update = [$id_cliente, $id_contato, $tema, $status, $data_treinamento];

        if ($has_google_event_link) {
            $campos_update[] = "google_event_link=?";
            $params_update[] = $google_event_link;
        }
        if ($has_google_agenda_link && $tem_coluna_google_agenda) {
            $campos_update[] = "google_agenda_link=?";
            $params_update[] = $google_agenda_link;
        }

        $params_update[] = $_POST['id_treinamento'];
        $stmt = $pdo->prepare("UPDATE treinamentos SET " . implode(", ", $campos_update) . " WHERE id_treinamento=?");
        $stmt->execute($params_update);
        $msg = "Treinamento atualizado com sucesso";
    } else {
        $colunas_insert = ["id_cliente", "id_contato", "tema", "status", "data_treinamento", "google_event_link"];
        $params_insert = [$id_cliente, $id_contato, $tema, $status, $data_treinamento, $google_event_link];

        if ($has_google_agenda_link && $tem_coluna_google_agenda) {
            $colunas_insert[] = "google_agenda_link";
            $params_insert[] = $google_agenda_link;
        }

        $placeholders = implode(", ", array_fill(0, count($colunas_insert), "?"));
        $stmt = $pdo->prepare("INSERT INTO treinamentos (" . implode(", ", $colunas_insert) . ") VALUES ($placeholders)");
        $stmt->execute($params_insert);
        $novo_id_treinamento = (int)$pdo->lastInsertId();

        $syncResult = ['success' => false];
        if ($google_event_link === null || $google_event_link === '') {
            $syncResult = sincronizarGoogleMeetAutomatico($pdo, $novo_id_treinamento);
        }

        if (!empty($syncResult['success'])) {
            $msg = "Treinamento adicionado e sincronizado com Google Meet";
        } elseif (!empty($syncResult['message'])) {
            $msg = "Treinamento adicionado. Link Google Meet não gerado automaticamente: " . $syncResult['message'];
        } else {
            $msg = "Treinamento adicionado com sucesso";
        }
    }
    header("Location: treinamentos.php?msg=" . urlencode($msg) . "&tipo=success");
    exit;
}

// Ordenação
$ordenacao = isset($_GET['ordenacao']) ? $_GET['ordenacao'] : 'data_treinamento';
$direcao = isset($_GET['direcao']) ? $_GET['direcao'] : 'desc';

// Validação da ordenação para segurança
$colunas_permitidas = ['cliente_nome', 'data_treinamento', 'tema', 'status'];
$ordenacao = in_array($ordenacao, $colunas_permitidas) ? $ordenacao : 'data_treinamento';
$direcao = $direcao === 'desc' ? 'desc' : 'asc';

// Paginação
$por_pagina = 8;
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina - 1) * $por_pagina;

// Query principal com contagem para paginação
$sql_base = "
    SELECT t.*, c.fantasia as cliente_nome, co.nome as contato_nome 
    FROM treinamentos t
    LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
    LEFT JOIN contatos co ON t.id_contato = co.id_contato
";

$sql_contagem = "
    SELECT COUNT(*) as total 
    FROM treinamentos t
    LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
    LEFT JOIN contatos co ON t.id_contato = co.id_contato
";

if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    $sql_base .= " " . $where_clause;
    $sql_contagem .= " " . $where_clause;
}

// Executar contagem
$stmt_contagem = $pdo->prepare($sql_contagem);
$stmt_contagem->execute($params);
$total_registros = $stmt_contagem->fetchColumn();

// Calcular total de páginas
$total_paginas = ceil($total_registros / $por_pagina);

// Adicionar ordenação e paginação à query principal
$ordenacao_sql = '';
switch ($ordenacao) {
    case 'cliente_nome':
        $ordenacao_sql = 'c.fantasia';
        break;
    default:
        $ordenacao_sql = 't.' . $ordenacao;
}

// Usar placeholders ? para paginação também
$sql_base .= " ORDER BY $ordenacao_sql $direcao LIMIT ?, ?";

// Adicionar parâmetros de paginação ao array
$params[] = $offset;
$params[] = $por_pagina;

// Preparar e executar query principal
$stmt = $pdo->prepare($sql_base);
$stmt->execute($params);
$treinamentos = $stmt->fetchAll();

$total_resultados = count($treinamentos);

$clientes_list = $pdo->query("
    SELECT id_cliente, fantasia 
    FROM clientes 
    WHERE (data_fim IS NULL OR data_fim = '0000-00-00' OR data_fim > NOW())
    ORDER BY fantasia ASC
")->fetchAll();


include 'header.php';
?>

<style>
    /* Estilos adicionais para os botões do Google Agenda */
    .btn-google-link {
        min-width: 40px;
    }

    .copy-link-btn:hover {
        background-color: #198754 !important;
        color: white !important;
    }

    .open-link-btn:hover {
        background-color: #0d6efd !important;
        color: white !important;
    }

    /* Toast de confirmação */
    .toast-success {
        background-color: #198754 !important;
        color: white !important;
    }

    /* Campo de link no modal */
    .link-input-group .form-control:read-only {
        background-color: #f8f9fa;
        cursor: default;
    }

    /* Ordenação nas colunas */
    .sortable-header {
        cursor: pointer;
        transition: all 0.2s;
    }

    .sortable-header:hover {
        background-color: rgba(0, 0, 0, 0.03);
    }

    .totalizador-card {
        transition: transform 0.25s ease, box-shadow 0.25s ease !important;
        cursor: pointer;
    }

    .totalizador-card:hover {
        transform: translateY(-5px) !important;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12) !important;
    }
</style>

<div class="container-fluid py-4 bg-light min-vh-100">
    <div class="row align-items-center mb-4">
        <div class="col">
            <h4 class="fw-bold text-dark mb-1">Agenda de Treinamentos</h4>
            <p class="text-muted small">Gestão de capacitação técnica dos clientes</p>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary px-4 fw-bold shadow-sm d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
                <i class="bi bi-plus-lg me-2"></i>Novo Agendamento
            </button>
        </div>
    </div>

    <!-- FILTRO POR CLIENTE -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body p-3">
                    <form method="GET" action="" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="bi bi-search text-muted"></i>
                                </span>
                                <input type="text"
                                    name="filtro_cliente"
                                    class="form-control border-start-0"
                                    placeholder="Buscar por nome do cliente..."
                                    value="<?= htmlspecialchars($filtro_cliente) ?>"
                                    style="height: 45px;">
                                <!-- Parâmetros de ordenação e paginação ocultos -->
                                <input type="hidden" name="ordenacao" value="<?= htmlspecialchars($ordenacao) ?>">
                                <input type="hidden" name="direcao" value="<?= htmlspecialchars($direcao) ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted mb-1">Data início</label>
                            <input type="date"
                                name="data_inicio"
                                class="form-control"
                                value="<?= htmlspecialchars($data_inicio_export) ?>"
                                style="height: 45px;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted mb-1">Data fim</label>
                            <input type="date"
                                name="data_fim"
                                class="form-control"
                                value="<?= htmlspecialchars($data_fim_export) ?>"
                                style="height: 45px;">
                        </div>
                        <div class="col-md-2">
                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary flex-grow-1 d-flex align-items-center justify-content-center" style="height: 45px;">
                                    <i class="bi bi-funnel me-2"></i>Filtrar
                                </button>
                                <button type="submit" name="exportar_xls" value="1" class="btn btn-success flex-grow-1 d-flex align-items-center justify-content-center" style="height: 45px;">
                                    <i class="bi bi-file-earmark-excel me-2"></i>exportar xls
                                </button>
                                <?php if ($filtros_ativos): ?>
                                    <a href="treinamentos.php" class="btn btn-outline-secondary d-flex align-items-center" style="height: 45px;">
                                        <i class="bi bi-x-lg me-2"></i>Limpar
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($erro_exportacao)): ?>
                        <div class="mt-3 alert alert-warning py-2 mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($erro_exportacao) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($filtro_cliente)): ?>
                        <div class="mt-3 alert alert-info py-2">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-info-circle me-2"></i>
                                <div>
                                    <small class="fw-bold">Filtro ativo:</small>
                                    Mostrando <?= $total_resultados ?> treinamento(s) para
                                    <strong>"<?= htmlspecialchars($filtro_cliente) ?>"</strong>
                                    <?php if ($total_resultados == 0): ?>
                                        - Nenhum resultado encontrado.
                                    <?php endif; ?>
                                </div>
                                <?php if ($total_resultados > 0): ?>
                                    <div class="ms-auto">
                                        <a href="treinamentos.php" class="btn btn-sm btn-outline-info d-flex align-items-center">
                                            <i class="bi bi-list-ul me-1"></i>Ver todos os treinamentos
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- CARDS DE ESTATÍSTICAS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card totalizador-card border-0 shadow-sm rounded-3 border-start border-warning border-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase">Pendentes</span>
                            <h2 class="fw-bold my-1 text-dark"><?= $total_pendentes ?></h2>
                        </div>
                        <div class="bg-warning bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-clock text-warning" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card totalizador-card border-0 shadow-sm rounded-3 border-start border-primary border-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase">Hoje</span>
                            <h2 class="fw-bold my-1 text-dark"><?= $total_hoje ?></h2>
                        </div>
                        <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-calendar-check text-primary" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card totalizador-card border-0 shadow-sm rounded-3 border-start border-danger border-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase">Críticos s/ Agenda</span>
                            <h2 class="fw-bold my-1 text-dark"><?= count($clientes_sem_agenda) ?></h2>
                        </div>
                        <div class="bg-danger bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-exclamation-triangle text-danger" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card totalizador-card border-0 shadow-sm rounded-3 border-start border-success border-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase">Total Geral</span>
                            <h2 class="fw-bold my-1 text-dark"><?= $total_registros ?></h2>
                        </div>
                        <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-list-check text-success" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABELA DE TREINAMENTOS -->
    <div class="card shadow-sm border-0 rounded-3 overflow-hidden">
        <div class="card-header bg-white border-bottom py-3">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-table text-primary me-2"></i>
                        <span class="text-muted small">
                            <?php if ($total_registros > 0): ?>
                                Exibindo <strong><?= min($por_pagina, count($treinamentos)) ?></strong> de <strong><?= $total_registros ?></strong> treinamentos
                                <?php if (!empty($filtro_cliente)): ?>
                                    <span class="text-primary">(filtrados)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                Nenhum treinamento encontrado
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="btn-group" role="group">
                        <a href="treinamentos.php?ordenacao=<?= $ordenacao ?>&direcao=<?= $direcao == 'asc' ? 'desc' : 'asc' ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>"
                            class="btn btn-outline-secondary btn-sm d-flex align-items-center">
                            <i class="bi bi-sort-<?= $direcao == 'asc' ? 'down' : 'up' ?> me-1"></i>
                            Ordenar
                        </a>
                        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                            <?php
                            $nomes_colunas = [
                                'cliente_nome' => 'Cliente',
                                'data_treinamento' => 'Data',
                                'tema' => 'Tema',
                                'status' => 'Status'
                            ];
                            echo $nomes_colunas[$ordenacao] ?? 'Data';
                            ?>
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="treinamentos.php?ordenacao=cliente_nome&direcao=asc&filtro_cliente=<?= urlencode($filtro_cliente) ?>">Cliente</a></li>
                            <li><a class="dropdown-item" href="treinamentos.php?ordenacao=data_treinamento&direcao=desc&filtro_cliente=<?= urlencode($filtro_cliente) ?>">Data</a></li>
                            <li><a class="dropdown-item" href="treinamentos.php?ordenacao=tema&direcao=asc&filtro_cliente=<?= urlencode($filtro_cliente) ?>">Tema</a></li>
                            <li><a class="dropdown-item" href="treinamentos.php?ordenacao=status&direcao=asc&filtro_cliente=<?= urlencode($filtro_cliente) ?>">Status</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4 py-3 border-bottom-0">
                            <a href="treinamentos.php?ordenacao=cliente_nome&direcao=<?= ($ordenacao == 'cliente_nome' && $direcao == 'asc') ? 'desc' : 'asc' ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>"
                                class="text-decoration-none text-dark d-flex align-items-center sortable-header">
                                <span class="text-muted small fw-bold text-uppercase">Cliente / Tema</span>
                                <?php if ($ordenacao == 'cliente_nome'): ?>
                                    <i class="bi bi-caret-<?= $direcao == 'asc' ? 'up' : 'down' ?>-fill ms-1 small text-primary"></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="py-3 border-bottom-0">
                            <a href="treinamentos.php?ordenacao=data_treinamento&direcao=<?= ($ordenacao == 'data_treinamento' && $direcao == 'asc') ? 'desc' : 'asc' ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>"
                                class="text-decoration-none text-dark d-flex align-items-center sortable-header">
                                <span class="text-muted small fw-bold text-uppercase">Data Agendada</span>
                                <?php if ($ordenacao == 'data_treinamento'): ?>
                                    <i class="bi bi-caret-<?= $direcao == 'asc' ? 'up' : 'down' ?>-fill ms-1 small text-primary"></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="py-3 border-bottom-0 text-muted small fw-bold text-uppercase">Contato</th>
                        <th class="py-3 border-bottom-0 text-muted small fw-bold text-uppercase">Link Google Agenda</th>
                        <th class="py-3 border-bottom-0">
                            <a href="treinamentos.php?ordenacao=status&direcao=<?= ($ordenacao == 'status' && $direcao == 'asc') ? 'desc' : 'asc' ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>"
                                class="text-decoration-none text-dark d-flex align-items-center sortable-header justify-content-center">
                                <span class="text-muted small fw-bold text-uppercase">Status</span>
                                <?php if ($ordenacao == 'status'): ?>
                                    <i class="bi bi-caret-<?= $direcao == 'asc' ? 'up' : 'down' ?>-fill ms-1 small text-primary"></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="border-0 text-muted small fw-bold text-uppercase text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($total_resultados > 0): ?>
                        <?php
                        // Obter data/hora atual
                        $data_atual = new DateTime();
                        $timestamp_atual = $data_atual->getTimestamp();

                        foreach ($treinamentos as $t):
                            $link_google_agenda = trim((string)($t['google_agenda_link'] ?? ''));
                            $link_google_agenda_exibicao = $link_google_agenda !== '' ? $link_google_agenda : trim((string)($t['google_event_link'] ?? ''));
                            // LÓGICA CORRIGIDA PARA VERIFICAR VENCIMENTO
                            $isVencido = false;

                            if ($t['status'] == 'PENDENTE' && !empty($t['data_treinamento'])) {
                                $data_treinamento = new DateTime($t['data_treinamento']);
                                $timestamp_treinamento = $data_treinamento->getTimestamp();

                                // Verifica se a data/hora do treinamento já passou
                                if ($timestamp_treinamento < $timestamp_atual) {
                                    $isVencido = true;
                                }
                            }
                        ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark d-flex align-items-center">
                                        <i class="bi bi-building text-primary me-2"></i>
                                        <?= htmlspecialchars($t['cliente_nome']) ?>
                                    </div>
                                    <span class="badge bg-light text-dark border fw-normal">
                                        <i class="bi bi-tag me-1"></i><?= htmlspecialchars($t['tema']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="<?= $isVencido ? 'text-danger fw-bold' : 'text-muted' ?> small">
                                        <i class="bi bi-calendar3 me-1"></i>
                                        <?= $t['data_treinamento'] ? date('d/m/Y H:i', strtotime($t['data_treinamento'])) : '---' ?>
                                        <?php if ($isVencido): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger ms-2">VENCIDO</span>
                                        <?php elseif ($t['data_treinamento'] && strtotime($t['data_treinamento']) > $timestamp_atual): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success ms-2">AGENDADO</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="small text-muted d-flex align-items-center">
                                        <i class="bi bi-person me-1"></i>
                                        <?= htmlspecialchars($t['contato_nome'] ?? '---') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($link_google_agenda_exibicao !== ''): ?>
                                        <div class="d-flex gap-1">
                                            <!-- Botão para abrir link -->
                                            <a href="<?= htmlspecialchars($link_google_agenda_exibicao) ?>"
                                                target="_blank"
                                                class="btn btn-sm btn-outline-primary open-link-btn btn-google-link"
                                                data-bs-toggle="tooltip"
                                                data-bs-title="Abrir no Google Agenda">
                                                <i class="bi bi-calendar-check"></i>
                                            </a>

                                            <!-- Botão para copiar link -->
                                            <button type="button"
                                                class="btn btn-sm btn-outline-success copy-link-btn btn-google-link"
                                                data-bs-toggle="tooltip"
                                                data-bs-title="Copiar link do Google Agenda"
                                                onclick="copiarLinkAgenda('<?= htmlspecialchars($link_google_agenda_exibicao) ?>', '<?= htmlspecialchars($t['cliente_nome']) ?>')">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border d-flex align-items-center">
                                            <i class="bi bi-calendar-x me-1"></i>Sem link
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $t['status'] == 'Resolvido' ? 'bg-success-subtle text-success border border-success' : 'bg-warning-subtle text-warning border border-warning' ?> px-3 py-2">
                                        <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i>
                                        <?= $t['status'] ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-group shadow-sm">
                                        <!-- 1. BOTÃO LUPA (OBSERVAÇÕES) -->
                                        <?php if (!empty($t['observacoes'])): ?>
                                            <button class="btn btn-sm btn-light border text-info view-obs-btn"
                                                data-bs-toggle="tooltip"
                                                data-bs-title="Ver Observação"
                                                data-obs="<?= htmlspecialchars($t['observacoes']) ?>"
                                                data-cliente="<?= htmlspecialchars($t['cliente_nome']) ?>">
                                                <i class="bi bi-search"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-light border text-muted" disabled
                                                data-bs-toggle="tooltip"
                                                data-bs-title="Sem observações">
                                                <i class="bi bi-search"></i>
                                            </button>
                                        <?php endif; ?>

                                        <!-- 2. BOTÃO GOOGLE (SINCRONIZAR/REMOVER) -->
                                        <?php if (empty($t['google_event_id'])): ?>
                                            <button class="btn btn-sm btn-light border text-danger sync-google-btn"
                                                data-bs-toggle="tooltip"
                                                data-bs-title="Sincronizar com Google Agenda"
                                                data-id="<?= $t['id_treinamento'] ?>">
                                                <i class="bi bi-google"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-light border text-primary delete-google-btn"
                                                data-bs-toggle="tooltip"
                                                data-bs-title="Remover do Google Agenda"
                                                data-id="<?= $t['id_treinamento'] ?>"
                                                data-event-id="<?= $t['google_event_id'] ?>">
                                                <i class="bi bi-calendar-x"></i>
                                            </button>
                                        <?php endif; ?>

                                        <!-- 3. BOTÃO EDITAR -->
                                        <button class="btn btn-sm btn-light border edit-btn"
                                            data-bs-toggle="tooltip"
                                            data-bs-title="Editar Treinamento"
                                            data-id="<?= $t['id_treinamento'] ?>"
                                            data-cliente="<?= $t['id_cliente'] ?>"
                                            data-contato="<?= $t['id_contato'] ?>"
                                            data-tema="<?= htmlspecialchars($t['tema']) ?>"
                                            data-status="<?= $t['status'] ?>"
                                            data-data="<?= $t['data_treinamento'] ? date('Y-m-d\TH:i', strtotime($t['data_treinamento'])) : '' ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <!-- 4. BOTÃO EXCLUIR -->
                                        <a href="?delete=<?= $t['id_treinamento'] ?>&pagina=<?= $pagina ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>"
                                            class="btn btn-sm btn-light border text-danger"
                                            data-bs-toggle="tooltip"
                                            data-bs-title="Excluir Treinamento"
                                            onclick="return confirm('Tem certeza que deseja excluir este treinamento? Esta ação não pode ser desfeita.')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <div class="mb-3">
                                    <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                                </div>
                                <h5 class="text-muted mb-2">Nenhum treinamento encontrado</h5>
                                <p class="text-muted mb-4">
                                    <?php if (!empty($filtro_cliente)): ?>
                                        Não foram encontrados treinamentos para o cliente "<?= htmlspecialchars($filtro_cliente) ?>"
                                    <?php else: ?>
                                        Você ainda não possui treinamentos cadastrados.
                                    <?php endif; ?>
                                </p>
                                <button class="btn btn-primary d-flex align-items-center mx-auto" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
                                    <i class="bi bi-plus-lg me-2"></i>Criar Primeiro Treinamento
                                </button>
                                <?php if (!empty($filtro_cliente)): ?>
                                    <a href="treinamentos.php" class="btn btn-outline-secondary ms-2 d-flex align-items-center">
                                        <i class="bi bi-x-lg me-1"></i>Limpar Filtro
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginação -->
        <?php if ($total_paginas > 1): ?>
            <div class="card-footer bg-white border-0 py-3">
                <nav aria-label="Navegação de páginas">
                    <ul class="pagination justify-content-center mb-0">
                        <?php if ($pagina > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="treinamentos.php?pagina=<?= $pagina - 1 ?>&ordenacao=<?= $ordenacao ?>&direcao=<?= $direcao ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php
                        $inicio = max(1, $pagina - 2);
                        $fim = min($total_paginas, $pagina + 2);

                        if ($inicio > 1) {
                            echo '<li class="page-item"><a class="page-link" href="treinamentos.php?pagina=1&ordenacao=' . $ordenacao . '&direcao=' . $direcao . '&filtro_cliente=' . urlencode($filtro_cliente) . '">1</a></li>';
                            if ($inicio > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }

                        for ($i = $inicio; $i <= $fim; $i++):
                        ?>
                            <li class="page-item <?= ($i == $pagina) ? 'active' : '' ?>">
                                <a class="page-link" href="treinamentos.php?pagina=<?= $i ?>&ordenacao=<?= $ordenacao ?>&direcao=<?= $direcao ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php
                        if ($fim < $total_paginas) {
                            if ($fim < $total_paginas - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            echo '<li class="page-item"><a class="page-link" href="treinamentos.php?pagina=' . $total_paginas . '&ordenacao=' . $ordenacao . '&direcao=' . $direcao . '&filtro_cliente=' . urlencode($filtro_cliente) . '">' . $total_paginas . '</a></li>';
                        }
                        ?>

                        <?php if ($pagina < $total_paginas): ?>
                            <li class="page-item">
                                <a class="page-link" href="treinamentos.php?pagina=<?= $pagina + 1 ?>&ordenacao=<?= $ordenacao ?>&direcao=<?= $direcao ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- TOAST PARA CONFIRMAÇÃO DE CÓPIA -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="copyToast" class="toast toast-success border-0" role="alert">
        <div class="toast-header bg-success text-white border-0">
            <i class="bi bi-check-circle-fill me-2"></i>
            <strong class="me-auto">Sucesso!</strong>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body bg-white text-dark">
            <span id="toastMessage">Link copiado para a área de transferência!</span>
        </div>
    </div>
</div>

<!-- MODAL PARA VER OBSERVAÇÕES -->
<div class="modal fade" id="modalViewObs" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold text-dark"><i class="bi bi-chat-left-text me-2 text-info"></i>Observações do Treinamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pb-4">
                <div class="mb-3">
                    <div class="small text-muted mb-2 text-uppercase fw-bold">
                        <i class="bi bi-building me-1"></i>Cliente:
                        <span id="view_obs_cliente" class="text-primary"></span>
                    </div>
                </div>
                <div class="p-3 bg-light rounded-3 border">
                    <h6 class="fw-bold text-muted mb-2">Observações da Finalização:</h6>
                    <div id="view_obs_text" class="mb-0 text-dark" style="white-space: pre-wrap; line-height: 1.6;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Agendar/Editar Treinamento -->
<div class="modal fade" id="modalTreinamento" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold" id="modalTitle">Agendar Treinamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <input type="hidden" name="id_treinamento" id="id_treinamento">

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Cliente</label>
                    <select name="id_cliente" id="id_cliente" class="form-select" required onchange="filterContatos(this.value)">
                        <option value="">Selecione o cliente...</option>
                        <?php
                        // CONSULTA MODIFICADA: Apenas clientes ativos
                        foreach ($clientes_list as $c): ?>

                            <option value="<?= $c['id_cliente'] ?>"><?= htmlspecialchars($c['fantasia']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Contato</label>
                    <select name="id_contato" id="id_contato" class="form-select" required disabled>
                        <option value="">Aguardando cliente...</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Tema</label>
                    <select name="tema" id="tema" class="form-select" required>
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

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Data/Hora</label>
                        <input type="datetime-local" name="data_treinamento" id="data_treinamento" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="PENDENTE">PENDENTE</option>
                            <option value="Resolvido">Resolvido</option>
                        </select>
                    </div>
                </div>

            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Fechar</button>
                <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Inicializar tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        })

        // Auto-focus no campo de busca
        const searchField = document.querySelector('input[name="filtro_cliente"]');
        if (searchField && !searchField.value) {
            searchField.focus();
        }

        // Configurar data/hora atual no modal para novo agendamento
        const dataTreinamentoInput = document.getElementById('data_treinamento');
        if (dataTreinamentoInput && !dataTreinamentoInput.value) {
            const now = new Date();
            // Ajustar para o timezone do Brasil
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            dataTreinamentoInput.value = now.toISOString().slice(0, 16);
        }
    });

    // AJAX Contatos
    function filterContatos(id_cliente, selected_contato = null) {
        const contatoSelect = document.getElementById('id_contato');
        if (!id_cliente) {
            contatoSelect.innerHTML = '<option value="">Aguardando cliente...</option>';
            contatoSelect.disabled = true;
            return;
        }
        fetch('get_contatos_cliente.php?id_cliente=' + id_cliente)
            .then(r => r.json())
            .then(data => {
                contatoSelect.innerHTML = '<option value="">Selecione o contato...</option>';
                data.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.id_contato;
                    opt.textContent = c.nome;
                    if (selected_contato == c.id_contato) opt.selected = true;
                    contatoSelect.appendChild(opt);
                });
                contatoSelect.disabled = false;
            });
    }

    // Função para copiar link do Google Agenda (na tabela)
    function copiarLinkAgenda(link, clienteNome = '') {
        navigator.clipboard.writeText(link).then(() => {
            // Atualizar mensagem do toast
            let message = 'Link copiado para a área de transferência!';
            if (clienteNome) {
                message = `Link do treinamento (${clienteNome}) copiado!`;
            }
            document.getElementById('toastMessage').textContent = message;

            // Mostrar toast
            const toast = new bootstrap.Toast(document.getElementById('copyToast'));
            toast.show();
        }).catch(err => {
            console.error('Erro ao copiar: ', err);
            // Fallback para navegadores mais antigos
            const textArea = document.createElement('textarea');
            textArea.value = link;
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                document.getElementById('toastMessage').textContent = 'Link copiado!';
                const toast = new bootstrap.Toast(document.getElementById('copyToast'));
                toast.show();
            } catch (err) {
                alert('Não foi possível copiar o link. Por favor, copie manualmente.');
            }
            document.body.removeChild(textArea);
        });
    }

    // Função para copiar link do modal
    function copiarLinkModal() {
        const linkInput = document.getElementById('google_event_link');
        if (linkInput && linkInput.value) {
            copiarLinkAgenda(linkInput.value);
        } else {
            alert('Não há link para copiar. Cole primeiro o link do Google Agenda.');
        }
    }


    // 1. Modal Observação
    document.querySelectorAll('.view-obs-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('view_obs_cliente').innerText = this.dataset.cliente;
            document.getElementById('view_obs_text').innerText = this.dataset.obs;
            new bootstrap.Modal(document.getElementById('modalViewObs')).show();
        });
    });

    // 2. Sincronização Google
    document.querySelectorAll('.sync-google-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const icon = this.querySelector('i');
            const originalClass = icon.className;
            icon.className = 'spinner-border spinner-border-sm';

            fetch('google_calendar_sync.php?id_treinamento=' + id)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        if (data.already_synced) {
                            alert('Este treinamento já está sincronizado com o Google Agenda.');
                        } else {
                            alert('Sincronizado com sucesso! Evento criado no Google Agenda.');
                        }
                        location.reload();
                    } else if (data.auth_url) {
                        window.location.href = data.auth_url;
                    } else {
                        alert('Erro: ' + data.message);
                        icon.className = originalClass;
                    }
                })
                .catch(error => {
                    alert('Erro na comunicação com o servidor.');
                    icon.className = originalClass;
                });
        });
    });

    // 3. Remover do Google Agenda (Função adicional se necessário)
    document.querySelectorAll('.delete-google-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (confirm('Deseja remover este evento do Google Agenda?')) {
                const id = this.dataset.id;
                const eventId = this.dataset.eventId;
                const icon = this.querySelector('i');
                const originalClass = icon.className;
                icon.className = 'spinner-border spinner-border-sm';

                // Você precisará criar um arquivo google_calendar_delete.php para esta função
                fetch('google_calendar_delete.php?id_treinamento=' + id + '&event_id=' + eventId)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            alert('Evento removido do Google Agenda com sucesso!');
                            location.reload();
                        } else {
                            alert('Erro: ' + data.message);
                            icon.className = originalClass;
                        }
                    })
                    .catch(error => {
                        alert('Erro na comunicação com o servidor.');
                        icon.className = originalClass;
                    });
            }
        });
    });

    // 4. Editar Agendamento - ATUALIZADO COM GOOGLE LINK
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Editar Treinamento';
            document.getElementById('id_treinamento').value = this.dataset.id;
            document.getElementById('id_cliente').value = this.dataset.cliente;
            document.getElementById('tema').value = this.dataset.tema;
            document.getElementById('status').value = this.dataset.status;
            document.getElementById('data_treinamento').value = this.dataset.data;

            filterContatos(this.dataset.cliente, this.dataset.contato);
            new bootstrap.Modal(document.getElementById('modalTreinamento')).show();
        });
    });

    // Reset modal quando fechado
    document.getElementById('modalTreinamento').addEventListener('hidden.bs.modal', function() {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-calendar-plus me-2"></i>Agendar Treinamento';
        document.getElementById('id_treinamento').value = '';
        this.querySelector('form').reset();

        // Resetar contatos
        const contatoSelect = document.getElementById('id_contato');
        contatoSelect.innerHTML = '<option value="">Aguardando cliente...</option>';
        contatoSelect.disabled = true;

        // Resetar data/hora para agora
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        document.getElementById('data_treinamento').value = now.toISOString().slice(0, 16);
    });
</script>

<?php include 'footer.php'; ?>
