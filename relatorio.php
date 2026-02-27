<?php
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

function garantirColunaGoogleAgenda(PDO $pdo)
{
    if (treinamentosTemColuna($pdo, 'google_agenda_link')) {
        return true;
    }

    $comandos = [
        "ALTER TABLE treinamentos ADD COLUMN google_agenda_link VARCHAR(500) NULL AFTER google_event_link",
        "ALTER TABLE treinamentos ADD COLUMN google_agenda_link VARCHAR(500) NULL",
        "ALTER TABLE treinamentos ADD COLUMN IF NOT EXISTS google_agenda_link VARCHAR(500) NULL"
    ];

    foreach ($comandos as $sql) {
        try {
            $pdo->exec($sql);
            break;
        } catch (Throwable $e) {
            // tenta próximo comando
        }
    }

    return treinamentosTemColuna($pdo, 'google_agenda_link', true);
}

function salvarGoogleAgendaLink(PDO $pdo, $idTreinamento, $linkAgenda)
{
    $valor = $linkAgenda !== '' ? $linkAgenda : null;

    try {
        $stmt = $pdo->prepare("UPDATE treinamentos SET google_agenda_link = ? WHERE id_treinamento = ?");
        $stmt->execute([$valor, $idTreinamento]);
        return [true, null];
    } catch (Throwable $e) {
        if (!garantirColunaGoogleAgenda($pdo)) {
            return [false, "não foi possível criar/usar a coluna google_agenda_link."];
        }

        try {
            $stmt = $pdo->prepare("UPDATE treinamentos SET google_agenda_link = ? WHERE id_treinamento = ?");
            $stmt->execute([$valor, $idTreinamento]);
            return [true, null];
        } catch (Throwable $e2) {
            return [false, $e2->getMessage()];
        }
    }
}

function garantirColunaMarcacaoPendencia(PDO $pdo)
{
    if (treinamentosTemColuna($pdo, 'tipo_pendencia_encerramento')) {
        return true;
    }

    $comandos = [
        "ALTER TABLE treinamentos ADD COLUMN tipo_pendencia_encerramento VARCHAR(20) NULL AFTER observacoes",
        "ALTER TABLE treinamentos ADD COLUMN tipo_pendencia_encerramento VARCHAR(20) NULL",
        "ALTER TABLE treinamentos ADD COLUMN IF NOT EXISTS tipo_pendencia_encerramento VARCHAR(20) NULL"
    ];

    foreach ($comandos as $sql) {
        try {
            $pdo->exec($sql);
            break;
        } catch (Throwable $e) {
            // tenta proximo comando
        }
    }

    return treinamentosTemColuna($pdo, 'tipo_pendencia_encerramento', true);
}

function garantirTabelaPendenciasTreinamentos(PDO $pdo)
{
    $sql = "CREATE TABLE IF NOT EXISTS pendencias_treinamentos (
        id_pendencia INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_treinamento INT NOT NULL,
        id_cliente INT NULL,
        status_pendencia VARCHAR(20) NOT NULL DEFAULT 'ABERTA',
        observacao_finalizacao TEXT NULL,
        referencia_chamado VARCHAR(255) NULL,
        observacao_conclusao TEXT NULL,
        data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        data_atualizacao DATETIME NULL,
        data_conclusao DATETIME NULL,
        UNIQUE KEY uq_pendencia_treinamento (id_treinamento),
        KEY idx_status_pendencia (status_pendencia),
        KEY idx_cliente_pendencia (id_cliente)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($sql);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function criarServicoGoogleCalendarStatus()
{
    $autoloadPath = __DIR__ . '/vendor/autoload.php';
    $credentialsPath = __DIR__ . '/credentials.json';
    $tokenPath = __DIR__ . '/token.json';

    if (!file_exists($autoloadPath) || !file_exists($credentialsPath) || !file_exists($tokenPath)) {
        return [null, 'integracao_google_nao_configurada'];
    }

    require_once $autoloadPath;

    $tokenData = json_decode(file_get_contents($tokenPath), true);
    if (!is_array($tokenData)) {
        return [null, 'token_google_invalido'];
    }

    try {
        $client = new Google\Client();
        $client->setAuthConfig($credentialsPath);
        $client->addScope(Google\Service\Calendar::CALENDAR);
        $client->setAccessType('offline');
        $client->setAccessToken($tokenData);

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $client->getRefreshToken();
            if (empty($refreshToken)) {
                return [null, 'token_expirado_sem_refresh'];
            }

            $novoToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            if (isset($novoToken['error'])) {
                if ((string)$novoToken['error'] === 'invalid_grant') {
                    return [null, 'token_revogado'];
                }
                return [null, 'falha_renovar_token'];
            }
            $tokenAtualizado = $client->getAccessToken();
            if (empty($tokenAtualizado['refresh_token']) && !empty($refreshToken)) {
                $tokenAtualizado['refresh_token'] = $refreshToken;
            }
            file_put_contents($tokenPath, json_encode($tokenAtualizado));
        }

        return [new Google\Service\Calendar($client), null];
    } catch (Throwable $e) {
        return [null, 'erro_inicializar_google'];
    }
}

function obterStatusConvitesGoogle(array $treinamentos)
{
    $statusPorTreinamento = [];
    $itensComEvento = [];

    foreach ($treinamentos as $t) {
        $idTreinamento = (int)($t['id_treinamento'] ?? 0);
        if ($idTreinamento <= 0) {
            continue;
        }

        $googleEventId = trim((string)($t['google_event_id'] ?? ''));
        if ($googleEventId === '') {
            $statusPorTreinamento[$idTreinamento] = [
                'tipo' => 'sem_evento',
                'label' => 'Sem evento',
                'badge' => 'bg-secondary'
            ];
            continue;
        }

        $statusPorTreinamento[$idTreinamento] = [
            'tipo' => 'pendente_verificacao',
            'label' => 'Verificando',
            'badge' => 'bg-light text-dark border'
        ];
        $itensComEvento[$idTreinamento] = $googleEventId;
    }

    if (empty($itensComEvento)) {
        return $statusPorTreinamento;
    }

    [$service, $erroServico] = criarServicoGoogleCalendarStatus();
    if (!$service) {
        $labelErro = 'Sem validacao';
        if ($erroServico === 'token_revogado' || $erroServico === 'token_expirado_sem_refresh') {
            $labelErro = 'Reautenticar Google';
        }

        foreach ($itensComEvento as $idTreinamento => $googleEventId) {
            $statusPorTreinamento[$idTreinamento] = [
                'tipo' => 'erro',
                'label' => $labelErro,
                'badge' => 'bg-warning text-dark'
            ];
        }
        return $statusPorTreinamento;
    }

    foreach ($itensComEvento as $idTreinamento => $googleEventId) {
        try {
            $evento = $service->events->get('primary', $googleEventId);
            $attendees = $evento->getAttendees();
            $qtdConvidados = is_array($attendees) ? count($attendees) : 0;

            if ($qtdConvidados > 0) {
                $statusPorTreinamento[$idTreinamento] = [
                    'tipo' => 'enviado',
                    'label' => 'Enviado (' . $qtdConvidados . ')',
                    'badge' => 'bg-success'
                ];
            } else {
                $statusPorTreinamento[$idTreinamento] = [
                    'tipo' => 'nao_enviado',
                    'label' => 'Nao enviado',
                    'badge' => 'bg-danger'
                ];
            }
        } catch (Throwable $e) {
            $codigo = (int)$e->getCode();
            if ($codigo === 404) {
                $statusPorTreinamento[$idTreinamento] = [
                    'tipo' => 'evento_inexistente',
                    'label' => 'Evento nao existe',
                    'badge' => 'bg-danger'
                ];
            } else {
                $statusPorTreinamento[$idTreinamento] = [
                    'tipo' => 'erro',
                    'label' => 'Erro validacao',
                    'badge' => 'bg-warning text-dark'
                ];
            }
        }
    }

    return $statusPorTreinamento;
}

// 1. LÃ“GICA DE PROCESSAMENTO: Encerrar treinamento com ObservaÃ§Ã£o
// Deve vir antes de qualquer saÃ­da HTML
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirmar_encerramento'])) {
    $id = (int)($_POST['id_treinamento'] ?? 0);
    $obs = trim((string)($_POST['observacoes'] ?? ''));
    $temPendencia = trim((string)($_POST['tem_pendencia'] ?? ''));
    $referenciaChamado = trim((string)($_POST['referencia_chamado'] ?? ''));
    $tipoPendencia = null;
    if ($temPendencia === 'sim') {
        $tipoPendencia = 'COM_PENDENCIA';
    } elseif ($temPendencia === 'nao') {
        $tipoPendencia = 'SEM_PENDENCIA';
    }
    $data_hoje = date('Y-m-d H:i:s');

    if ($id <= 0 || $obs === '' || $tipoPendencia === null) {
        header("Location: relatorio.php?msg=" . urlencode("Preencha os campos obrigatorios para encerrar o treinamento."));
        exit;
    }

    $colunaMarcacaoDisponivel = garantirColunaMarcacaoPendencia($pdo);
    $tabelaPendenciasDisponivel = garantirTabelaPendenciasTreinamentos($pdo);
    $mensagemRetorno = "Treinamento encerrado com sucesso.";

    try {
        $pdo->beginTransaction();

        if ($colunaMarcacaoDisponivel) {
            $stmt = $pdo->prepare("UPDATE treinamentos SET status = 'Resolvido', data_treinamento_encerrado = ?, observacoes = ?, tipo_pendencia_encerramento = ? WHERE id_treinamento = ?");
            $stmt->execute([$data_hoje, $obs, $tipoPendencia, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE treinamentos SET status = 'Resolvido', data_treinamento_encerrado = ?, observacoes = ? WHERE id_treinamento = ?");
            $stmt->execute([$data_hoje, $obs, $id]);
        }

        $stmtCliente = $pdo->prepare("SELECT id_cliente FROM treinamentos WHERE id_treinamento = ?");
        $stmtCliente->execute([$id]);
        $idCliente = (int)($stmtCliente->fetchColumn() ?: 0);

        if ($tipoPendencia === 'COM_PENDENCIA' && $tabelaPendenciasDisponivel) {
            $stmtPendencia = $pdo->prepare(
                "INSERT INTO pendencias_treinamentos
                    (id_treinamento, id_cliente, status_pendencia, observacao_finalizacao, referencia_chamado, observacao_conclusao, data_criacao, data_atualizacao, data_conclusao)
                 VALUES (?, ?, 'ABERTA', ?, ?, NULL, ?, NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    id_cliente = VALUES(id_cliente),
                    status_pendencia = 'ABERTA',
                    observacao_finalizacao = VALUES(observacao_finalizacao),
                    referencia_chamado = VALUES(referencia_chamado),
                    observacao_conclusao = NULL,
                    data_atualizacao = VALUES(data_criacao),
                    data_conclusao = NULL"
            );
            $stmtPendencia->execute([
                $id,
                $idCliente > 0 ? $idCliente : null,
                $obs,
                $referenciaChamado !== '' ? $referenciaChamado : null,
                $data_hoje
            ]);
        } elseif ($tipoPendencia === 'SEM_PENDENCIA' && $tabelaPendenciasDisponivel) {
            $stmtPendencia = $pdo->prepare(
                "UPDATE pendencias_treinamentos
                 SET status_pendencia = 'CONCLUIDA',
                     data_conclusao = ?,
                     data_atualizacao = ?,
                     observacao_conclusao = COALESCE(observacao_conclusao, 'Encerrado sem pendencia no treinamento.')
                 WHERE id_treinamento = ? AND status_pendencia = 'ABERTA'"
            );
            $stmtPendencia->execute([$data_hoje, $data_hoje, $id]);
        } elseif (!$tabelaPendenciasDisponivel && $tipoPendencia === 'COM_PENDENCIA') {
            $mensagemRetorno = "Treinamento encerrado, mas nao foi possivel registrar a pendencia (tabela indisponivel).";
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: relatorio.php?msg=" . urlencode("Erro ao encerrar treinamento: " . $e->getMessage()));
        exit;
    }

    header("Location: relatorio.php?msg=" . urlencode($mensagemRetorno));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['salvar_link_google'])) {
    $id = $_POST['id_treinamento'] ?? null;
    $google_agenda_link = trim((string)($_POST['google_event_link'] ?? ''));
    $mensagem_retorno = "Link do Google Agenda salvo com sucesso";

    if (!empty($id)) {
        [$salvou, $erro] = salvarGoogleAgendaLink($pdo, $id, $google_agenda_link);
        if (!$salvou) {
            $mensagem_retorno = "Link Google Agenda não salvo: " . $erro;
        }
    }

    header("Location: relatorio.php?msg=" . urlencode($mensagem_retorno));
    exit;
}

// Consulta para clientes sem interaÃ§Ã£o hÃ¡ mais de 3 dias
$sql_inatividade = "
    SELECT c.id_cliente, c.fantasia, MAX(t.data_treinamento) as Ãºltima_data, c.data_inicio
    FROM clientes c
    LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
    WHERE (c.data_fim IS NULL OR c.data_fim = '0000-00-00')
    AND c.id_cliente NOT IN (
        SELECT DISTINCT id_cliente FROM treinamentos WHERE status = 'PENDENTE'
    )
    GROUP BY c.id_cliente, c.data_inicio
    HAVING 
        (MAX(t.data_treinamento) < DATE_SUB(CURDATE(), INTERVAL 3 DAY)) OR 
        (MAX(t.data_treinamento) IS NULL AND c.data_inicio < DATE_SUB(CURDATE(), INTERVAL 3 DAY))
    ORDER BY Ãºltima_data ASC";

$clientes_inativos = $pdo->query($sql_inatividade)->fetchAll();

include 'header.php';

// 2. Buscar estatÃ­sticas para os cards
$total_clientes = $pdo->query("SELECT COUNT(*) FROM clientes WHERE (data_fim IS NULL OR data_fim = '0000-00-00')")->fetchColumn();
$total_treinamentos = $pdo->query("SELECT COUNT(*) FROM treinamentos")->fetchColumn();
$treinamentos_pendentes = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE status = 'PENDENTE'")->fetchColumn();
$treinamentos_resolvidos = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE status = 'Resolvido'")->fetchColumn();
$total_pendencias_treinamentos = 0;
try {
    $total_pendencias_treinamentos = (int)$pdo->query("SELECT COUNT(*) FROM pendencias_treinamentos WHERE status_pendencia = 'ABERTA'")->fetchColumn();
} catch (Throwable $e) {
    $total_pendencias_treinamentos = 0;
}

// 3. Consulta de treinamentos pendentes
$sql = "SELECT t.*, c.fantasia as cliente_nome, c.servidor, co.nome as contato_nome, co.telefone_ddd as contato_telefone, c.telefone_ddd as cliente_telefone
        FROM treinamentos t
        JOIN clientes c ON t.id_cliente = c.id_cliente
        LEFT JOIN contatos co ON t.id_contato = co.id_contato
        WHERE t.status = 'PENDENTE'
        ORDER BY t.data_treinamento ASC 
        LIMIT 10";

$proximos_atendimentos = $pdo->query($sql)->fetchAll();
$status_convites = obterStatusConvitesGoogle($proximos_atendimentos);
$hoje_data = date('Y-m-d');
?>

<style>
    .page-title {
        font-size: 1.6rem;
        letter-spacing: 0.2px;
    }

    .totalizador-card {
        transition: transform 0.25s ease, box-shadow 0.25s ease !important;
        cursor: pointer;
    }

    .totalizador-card:hover {
        transform: translateY(-5px) !important;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12) !important;
    }

    .report-modal .form-control,
    .report-modal .form-select {
        border-radius: 10px;
        border: 2px solid #e9ecef;
        transition: all 0.25s;
    }

    .report-modal .form-control:focus,
    .report-modal .form-select:focus {
        border-color: #4361ee;
        box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.15);
    }
</style>

<div class="mb-4">
    <h3 class="page-title fw-bold"><i class="bi bi-calendar2-week me-2 text-primary"></i>Agendamentos</h3>
    <p class="text-muted">Gestão de Agendamentos.</p>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle me-2"></i> <?= htmlspecialchars($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-5">
    <div class="col-md-3">
        <div class="card totalizador-card h-100 p-3 border-0 shadow-sm border-start border-primary border-4">
            <div class="d-flex align-items-center">
                <div class="bg-primary bg-opacity-10 p-3 rounded-3 me-3">
                    <i class="bi bi-building text-primary fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-1 small fw-bold">CLIENTES</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $total_clientes; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card totalizador-card h-100 p-3 border-0 shadow-sm border-start border-info border-4">
            <div class="d-flex align-items-center">
                <div class="bg-info bg-opacity-10 p-3 rounded-3 me-3">
                    <i class="bi bi-mortarboard text-info fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-1 small fw-bold">TREINAMENTOS</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $total_treinamentos; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card totalizador-card h-100 p-3 border-0 shadow-sm border-start border-warning border-4">
            <div class="d-flex align-items-center">
                <div class="bg-warning bg-opacity-10 p-3 rounded-3 me-3">
                    <i class="bi bi-clock-history text-warning fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-1 small fw-bold">PENDENTES</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $treinamentos_pendentes; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card totalizador-card h-100 p-3 border-0 shadow-sm border-start border-success border-4">
            <div class="d-flex align-items-center">
                <div class="bg-success bg-opacity-10 p-3 rounded-3 me-3">
                    <i class="bi bi-check2-circle text-success fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-1 small fw-bold">RESOLVIDOS</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $treinamentos_resolvidos; ?></h3>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-12">
        <div class="card shadow-sm border-0 rounded-3 overflow-hidden">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                <h5 class="mb-0 fw-bold text-dark">Próximos Atendimentos (Pendentes)</h5>
                <div class="d-flex gap-2">
                    <a href="pendencias_treinamentos.php" class="btn btn-sm btn-outline-danger fw-bold">
                        Pendencias de Treinamentos
                        <span class="badge rounded-pill bg-danger ms-1"><?= $total_pendencias_treinamentos ?></span>
                    </a>
                    <a href="treinamentos.php" class="btn btn-sm btn-light text-primary fw-bold">Ver todos</a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">Data Agendada</th>
                                <th>Cliente</th>
                                <th>Servidor</th>
                                <th>Contato</th>
                                <th>Tema</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Convite</th>
                                <th class="text-end pe-4">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($proximos_atendimentos)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">Nenhum treinamento pendente.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($proximos_atendimentos as $t): 
                                    $data_treino = date('Y-m-d', strtotime($t['data_treinamento']));
                                    $e_hoje = ($data_treino == $hoje_data);
                                    $bg_class = $e_hoje ? 'table-info' : '';
                                    $nome_contato = trim((string)($t['contato_nome'] ?? ''));
                                    $telefone_contato = trim((string)($t['contato_telefone'] ?? ''));
                                    $telefone_cliente = trim((string)($t['cliente_telefone'] ?? ''));
                                    $telefone_exibicao = $telefone_contato !== '' ? $telefone_contato : $telefone_cliente;
                                    $telefone_whatsapp = preg_replace('/\D+/', '', $telefone_exibicao);
                                    if ($telefone_whatsapp !== '') {
                                        if (strpos($telefone_whatsapp, '55') !== 0 && (strlen($telefone_whatsapp) === 10 || strlen($telefone_whatsapp) === 11)) {
                                            $telefone_whatsapp = '55' . $telefone_whatsapp;
                                        }
                                        if (strlen($telefone_whatsapp) < 12 || strlen($telefone_whatsapp) > 13) {
                                            $telefone_whatsapp = '';
                                        }
                                    }
                                    $google_meet_link = trim((string)($t['google_event_link'] ?? ''));
                                    $google_agenda_link = trim((string)($t['google_agenda_link'] ?? ''));
                                    $nome_whatsapp = $nome_contato !== '' ? $nome_contato : $t['cliente_nome'];
                                    $data_treinamento_formatada = date('d/m/Y', strtotime($t['data_treinamento']));
                                    $horario_treinamento_formatado = date('H:i', strtotime($t['data_treinamento']));
                                    $linhas_mensagem_whatsapp = [
                                        "Olá, " . $nome_whatsapp . "!",
                                        "",
                                        "_" . "\u{2705}" . " Treinamento GestãoPRO agendado com sucesso!_",
                                        "*" . "\u{1F4C5}" . " Data: " . $data_treinamento_formatada . "*",
                                        "*" . "\u{1F552}" . " Horário: " . $horario_treinamento_formatado . "*",
                                        "\u{1F3AF}" . " Tema: " . $t['tema'],
                                        "",
                                        "\u{1F4BB}" . " Acesse a reunião pelo Google Meet:",
                                        ($google_meet_link !== '' ? $google_meet_link : 'não informado'),
                                    ];
                                    if ($google_agenda_link !== '') {
                                        $linhas_mensagem_whatsapp[] = "";
                                        $linhas_mensagem_whatsapp[] = "\u{1F4C6} Adicione ao Google Agenda:";
                                        $linhas_mensagem_whatsapp[] = $google_agenda_link;
                                    }
                                    $linhas_mensagem_whatsapp = array_merge($linhas_mensagem_whatsapp, [
                                        "",
                                        "Caso precise alterar a data ou o horário ou tenha alguma dúvida, é só me enviar uma mensagem.",
                                        "No horário do treinamento vou precisar do TEAMVER ou ANYDESK para acesso remoto ao computador.",
                                        "",
                                        "Agradeço e nos vemos em breve! " . "\u{1F44B}"
                                    ]);
                                    $mensagem_whatsapp = implode("\n", $linhas_mensagem_whatsapp);
                                    if (!preg_match('//u', $mensagem_whatsapp)) {
                                        if (function_exists('mb_convert_encoding')) {
                                            $mensagem_whatsapp = mb_convert_encoding($mensagem_whatsapp, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
                                        } elseif (function_exists('iconv')) {
                                            $convertida = @iconv('Windows-1252', 'UTF-8//IGNORE', $mensagem_whatsapp);
                                            if ($convertida !== false) {
                                                $mensagem_whatsapp = $convertida;
                                            }
                                        }
                                    }
                                    $mensagem_whatsapp_attr = htmlspecialchars($mensagem_whatsapp, ENT_QUOTES, 'UTF-8');
                                    if ($nome_contato !== '' && $telefone_exibicao !== '') {
                                        $contato_exibicao = $nome_contato . ' - ' . $telefone_exibicao;
                                    } elseif ($nome_contato !== '') {
                                        $contato_exibicao = $nome_contato;
                                    } elseif ($telefone_exibicao !== '') {
                                        $contato_exibicao = $telefone_exibicao;
                                    } else {
                                        $contato_exibicao = '---';
                                    }
                                    $id_treinamento_linha = (int)($t['id_treinamento'] ?? 0);
                                    $convite_status = $status_convites[$id_treinamento_linha] ?? [
                                        'label' => 'Sem validacao',
                                        'badge' => 'bg-warning text-dark'
                                    ];
                                ?>
                                <tr class="<?= $bg_class ?>">
                                    <td class="ps-4">
                                        <div class="small fw-bold">
                                            <?= date('d/m/Y H:i', strtotime($t['data_treinamento'])) ?>
                                            <?php if($e_hoje): ?>
                                                <span class="badge bg-primary text-white ms-1">HOJE</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="fw-bold"><?= htmlspecialchars($t['cliente_nome']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['servidor'] ?? '---') ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($contato_exibicao) ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['tema']) ?></span></td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border">
                                            <?= $t['status'] ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= htmlspecialchars($convite_status['badge']) ?>">
                                            <?= htmlspecialchars($convite_status['label']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button"
                                           class="btn btn-sm btn-outline-success me-1 copy-whatsapp-message"
                                           data-message="<?= $mensagem_whatsapp_attr ?>"
                                           title="Copiar mensagem para WhatsApp">
                                            <i class="bi bi-whatsapp"></i>
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary me-1 open-google-link-modal"
                                                data-id="<?= $t['id_treinamento'] ?>"
                                                data-cliente="<?= htmlspecialchars($t['cliente_nome']) ?>"
                                                data-google-link="<?= htmlspecialchars((string)($t['google_agenda_link'] ?? '')) ?>"
                                                title="Gerenciar link Google Agenda">
                                            <i class="bi bi-calendar-check"></i>
                                        </button>
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-success open-finish-modal"
                                                data-id="<?= $t['id_treinamento'] ?>"
                                                data-cliente="<?= htmlspecialchars($t['cliente_nome']) ?>"
                                                data-tema="<?= htmlspecialchars($t['tema']) ?>">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEncerrar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg report-modal" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold text-dark"><i class="bi bi-journal-check me-2 text-success"></i>Finalizar Treinamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <input type="hidden" name="id_treinamento" id="modal_id_treinamento">
                <input type="hidden" name="confirmar_encerramento" value="1">
                
                <div class="mb-3 p-3 bg-light rounded-3">
                    <div class="small text-muted mb-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Informações:</div>
                    <div class="fw-bold text-primary" id="modal_cliente_info"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">O que ficou acordado com o cliente?</label>
                    <textarea name="observacoes" class="form-control" rows="4" placeholder="Descreva os detalhes da sessão..." required></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase d-block">Pendencias relacionadas a este encerramento</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input pendencia-opcao" type="radio" name="tem_pendencia" id="tem_pendencia_nao" value="nao" required>
                        <label class="form-check-label" for="tem_pendencia_nao">Sem pendencia</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input pendencia-opcao" type="radio" name="tem_pendencia" id="tem_pendencia_sim" value="sim" required>
                        <label class="form-check-label" for="tem_pendencia_sim">Com pendencia</label>
                    </div>
                </div>
                <div class="mb-2 d-none" id="referencia_chamado_wrapper">
                    <label class="form-label small fw-bold text-muted text-uppercase">Referencia do chamado externo (opcional)</label>
                    <input type="text" class="form-control" name="referencia_chamado" id="referencia_chamado" maxlength="255" placeholder="Ex: SUP-12345, DEV-90210">
                </div>
                <div class="form-text">A marcacao com/sem pendencia e obrigatoria para concluir o treinamento.</div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm">Encerrar e Salvar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalGoogleLink" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg report-modal" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold text-dark"><i class="bi bi-calendar-event me-2 text-primary"></i>Link Google Agenda</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <input type="hidden" name="id_treinamento" id="google_modal_id_treinamento">
                <input type="hidden" name="salvar_link_google" value="1">

                <div class="mb-3 p-3 bg-light rounded-3">
                    <div class="small text-muted mb-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Treinamento:</div>
                    <div class="fw-bold text-primary" id="google_modal_cliente_info"></div>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Convite Google Agenda</label>
                    <input type="url"
                           name="google_event_link"
                           id="google_event_link_relatorio"
                           class="form-control"
                           placeholder="https://calendar.app.google/...">
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="gerarLinkCurtoRelatorio()">
                        <i class="bi bi-magic me-1"></i>Gerar link curto
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="colarLinkCurtoRelatorio()">
                        <i class="bi bi-clipboard-check me-1"></i>Colar
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copiarLinkRelatorio()">
                        <i class="bi bi-clipboard me-1"></i>Copiar
                    </button>
                </div>
                <div class="form-text mt-2">
                    Cole manualmente o link "Convidar por link" do Google Agenda.
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">Salvar Link</button>
            </div>
        </form>
    </div>
</div>

<script>
    function atualizarCampoReferenciaPendencia() {
        const radioComPendencia = document.getElementById('tem_pendencia_sim');
        const wrapper = document.getElementById('referencia_chamado_wrapper');
        const input = document.getElementById('referencia_chamado');
        if (!radioComPendencia || !wrapper || !input) return;

        if (radioComPendencia.checked) {
            wrapper.classList.remove('d-none');
        } else {
            wrapper.classList.add('d-none');
            input.value = '';
        }
    }

    document.querySelectorAll('.pendencia-opcao').forEach(radio => {
        radio.addEventListener('change', atualizarCampoReferenciaPendencia);
    });

    document.querySelectorAll('.open-finish-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const cliente = this.dataset.cliente;
            const tema = this.dataset.tema;
            
            document.getElementById('modal_id_treinamento').value = id;
            document.getElementById('modal_cliente_info').innerText = cliente + " | " + tema;
            document.querySelectorAll('.pendencia-opcao').forEach(radio => {
                radio.checked = false;
            });
            const referenciaInput = document.getElementById('referencia_chamado');
            if (referenciaInput) referenciaInput.value = '';
            atualizarCampoReferenciaPendencia();
            
            const myModal = new bootstrap.Modal(document.getElementById('modalEncerrar'));
            myModal.show();
        });
    });

    document.querySelectorAll('.open-google-link-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('google_modal_id_treinamento').value = this.dataset.id;
            document.getElementById('google_modal_cliente_info').innerText = this.dataset.cliente;
            document.getElementById('google_event_link_relatorio').value = this.dataset.googleLink || '';
            new bootstrap.Modal(document.getElementById('modalGoogleLink')).show();
        });
    });

    document.querySelectorAll('.copy-whatsapp-message').forEach(btn => {
        btn.addEventListener('click', async function() {
            const mensagem = this.dataset.message || '';

            if (!mensagem.trim()) {
                alert('Não há mensagem para copiar.');
                return;
            }

            try {
                await navigator.clipboard.writeText(mensagem);
                alert('Mensagem copiada. Agora cole no WhatsApp do cliente.');
            } catch (error) {
                alert('Não foi possível copiar automaticamente. Copie manualmente.');
            }
        });
    });

    function gerarLinkCurtoRelatorio() {
        const input = document.getElementById('google_event_link_relatorio');
        const link = input ? input.value.trim() : '';

        if (!link) {
            alert('Primeiro informe ou sincronize o link do evento Google.');
            return;
        }

        window.open(link, '_blank', 'noopener');

        if (!link.includes('calendar.app.google/')) {
            alert('No Google Agenda, use "Convidar por link", copie o link curto e depois clique em "Colar".');
        }
    }

    async function colarLinkCurtoRelatorio() {
        const input = document.getElementById('google_event_link_relatorio');
        if (!input) return;

        try {
            const texto = (await navigator.clipboard.readText()).trim();
            if (!texto) {
                alert('A Ã¡rea de transferÃªncia estÃ¡ vazia.');
                return;
            }
            if (!texto.startsWith('http://') && !texto.startsWith('https://')) {
                alert('O conteÃºdo copiado nÃ£o parece um link vÃ¡lido.');
                return;
            }
            input.value = texto;
        } catch (error) {
            alert('NÃ£o foi possÃ­vel ler a Ã¡rea de transferÃªncia. Cole manualmente no campo.');
        }
    }

    function copiarLinkRelatorio() {
        const input = document.getElementById('google_event_link_relatorio');
        const link = input ? input.value.trim() : '';

        if (!link) {
            alert('NÃ£o hÃ¡ link preenchido para copiar.');
            return;
        }

        navigator.clipboard.writeText(link)
            .then(() => alert('Link copiado com sucesso.'))
            .catch(() => alert('NÃ£o foi possÃ­vel copiar automaticamente. Copie manualmente.'));
    }
</script>

<?php include 'footer.php'; ?>
