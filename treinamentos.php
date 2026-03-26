<?php
// Configurar timezone corretamente (Brasil)
date_default_timezone_set('America/Sao_Paulo');

require_once 'config.php';
require_once __DIR__ . '/google_oauth_token_helper.php';

// FUNÇÃO PARA GARANTIR QUE A TABELA DE OBSERVAÇÕES EXISTA
function garantirTabelaObservacoes($pdo)
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'observacoes_cliente'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS observacoes_cliente (
                id_observacao INT PRIMARY KEY AUTO_INCREMENT,
                id_cliente INT NOT NULL,
                titulo VARCHAR(100) NOT NULL,
                conteudo TEXT NOT NULL,
                tipo ENUM('INFORMAÇÃO', 'AJUSTE', 'PROBLEMA', 'MELHORIA', 'ATUALIZAÇÃO', 'CONTATO') DEFAULT 'INFORMAÇÃO',
                tags VARCHAR(255),
                registrado_por VARCHAR(100) DEFAULT 'Sistema',
                data_observacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cliente (id_cliente),
                INDEX idx_tipo (tipo),
                INDEX idx_data (data_observacao),
                FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (PDOException $e) {
        error_log("Erro ao verificar/criar tabela observacoes_cliente: " . $e->getMessage());
    }
}
garantirTabelaObservacoes($pdo);



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

function obterColunaEmailContato(PDO $pdo)
{
    $candidatas = ['email', 'email_contato', 'e_mail'];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM contatos");
        $colunas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return null;
    }

    $mapa = [];
    foreach ($colunas as $colunaReal) {
        $mapa[strtolower((string)$colunaReal)] = (string)$colunaReal;
    }
    foreach ($candidatas as $coluna) {
        $chave = strtolower($coluna);
        if (isset($mapa[$chave])) {
            return $mapa[$chave];
        }
    }

    return null;
}

function extrairEmailsValidos($valor)
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return [];
    }

    $partes = preg_split('/[,\s;]+/', $valor);
    $emails = [];
    foreach ($partes as $parte) {
        $email = trim($parte);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    return $emails;
}

function normalizarDataTreinamento($valor)
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return null;
    }

    $timezone = new DateTimeZone('America/Sao_Paulo');
    $formatos = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];

    foreach ($formatos as $formato) {
        $dt = DateTime::createFromFormat($formato, $valor, $timezone);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    try {
        $dt = new DateTime($valor, $timezone);
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function existeConflitoHorarioTreinamento(PDO $pdo, $dataTreinamento, $idTreinamentoAtual = null)
{
    $dataTreinamento = trim((string)$dataTreinamento);
    if ($dataTreinamento === '') {
        return false;
    }

    try {
        $inicio = new DateTime($dataTreinamento, new DateTimeZone('America/Sao_Paulo'));
    } catch (Throwable $e) {
        return false;
    }

    $inicio->setTime((int)$inicio->format('H'), (int)$inicio->format('i'), 0);
    $fim = clone $inicio;
    $fim->modify('+1 minute');

    $sql = "SELECT id_treinamento
            FROM treinamentos
            WHERE data_treinamento >= ?
              AND data_treinamento < ?";
    $params = [$inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')];

    $idTreinamentoAtual = (int)$idTreinamentoAtual;
    if ($idTreinamentoAtual > 0) {
        $sql .= " AND id_treinamento <> ?";
        $params[] = $idTreinamentoAtual;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function obterConvidadosCliente(PDO $pdo, $idCliente)
{
    $colunaEmailContato = obterColunaEmailContato($pdo);
    if (!$colunaEmailContato) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT nome, `{$colunaEmailContato}` as contato_email FROM contatos WHERE id_cliente = ? ORDER BY nome ASC");
    $stmt->execute([$idCliente]);
    $contatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $convidados = [];
    $emailsUnicos = [];

    foreach ($contatos as $contato) {
        $nome = trim((string)($contato['nome'] ?? ''));
        $emails = extrairEmailsValidos($contato['contato_email'] ?? '');

        foreach ($emails as $email) {
            $emailKey = strtolower($email);
            if (isset($emailsUnicos[$emailKey])) {
                continue;
            }
            $emailsUnicos[$emailKey] = true;

            $convidado = ['email' => $email];
            if ($nome !== '') {
                $convidado['displayName'] = $nome;
            }
            $convidados[] = $convidado;
        }
    }

    return $convidados;
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
        $client->addScope(\Google\Service\Calendar::CALENDAR);
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

function obterStatusConvitesGoogle(array $listaTreinamentos)
{
    $statusPorTreinamento = [];
    $itensComEvento = [];

    foreach ($listaTreinamentos as $t) {
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

function sincronizarGoogleMeetAutomatico($pdo, $idTreinamento)
{
    try {
        $autoloadPath = __DIR__ . '/vendor/autoload.php';
        $credentialsPath = __DIR__ . '/credentials.json';
        $tokenPath = googleTokenPath();
        $authStartUrl = 'google_calendar_sync.php?id_treinamento=' . (int)$idTreinamento . '&start_auth=1';

        if (!file_exists($autoloadPath) || !file_exists($credentialsPath)) {
            return ['success' => false, 'message' => 'Integração Google não configurada.'];
        }

        require_once $autoloadPath;

        if (!file_exists($tokenPath)) {
            return ['success' => false, 'message' => 'Autenticação Google necessária.', 'needs_auth' => true, 'auth_start_url' => $authStartUrl];
        }

        $tokenData = googleLoadTokenData($tokenPath);
        if (!is_array($tokenData)) {
            return ['success' => false, 'message' => 'Token Google inválido.', 'needs_auth' => true, 'auth_start_url' => $authStartUrl];
        }

        $client = new Google\Client();
        $client->setAuthConfig($credentialsPath);
        $client->addScope(Google\Service\Calendar::CALENDAR);
        $client->setAccessType('offline');
        $client->setAccessToken($tokenData);

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $client->getRefreshToken();
            if (empty($refreshToken)) {
                return ['success' => false, 'message' => 'Sessão Google expirada. Faça login novamente.', 'needs_auth' => true, 'auth_start_url' => $authStartUrl];
            }

            try {
                $novoToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            } catch (Throwable $e) {
                if (googleIsInvalidGrantError($e)) {
                    googleForgetToken($tokenPath);
                    return ['success' => false, 'message' => 'Sessao Google expirada. Faca login novamente.', 'needs_auth' => true, 'auth_start_url' => $authStartUrl];
                }
                throw $e;
            }
            if (isset($novoToken['error'])) {
                if (googleIsInvalidGrantError($novoToken)) {
                    googleForgetToken($tokenPath);
                    return ['success' => false, 'message' => 'Sessao Google expirada. Faca login novamente.', 'needs_auth' => true, 'auth_start_url' => $authStartUrl];
                }
                return ['success' => false, 'message' => 'Falha ao renovar token Google.', 'needs_auth' => true, 'auth_start_url' => $authStartUrl];
            }

            googlePersistToken($client, $novoToken, $tokenPath);
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

        $convidados = obterConvidadosCliente($pdo, (int)$treinamento['id_cliente']);
        $descricaoConvidados = 'Sem e-mail cadastrado';
        if (!empty($convidados)) {
            $itensDescricao = [];
            foreach ($convidados as $convidado) {
                $displayName = trim((string)($convidado['displayName'] ?? ''));
                $email = trim((string)($convidado['email'] ?? ''));
                if ($email === '') {
                    continue;
                }
                $itensDescricao[] = $displayName !== '' ? ($displayName . ' (' . $email . ')') : $email;
            }
            if (!empty($itensDescricao)) {
                $descricaoConvidados = implode(', ', $itensDescricao);
            }
        }

        $eventData = [
            'summary' => '#' . $treinamento['id_treinamento'] . ' Treinamento: ' . ($treinamento['cliente_nome'] ?? 'Cliente'),
            'description' => "Tema: " . ($treinamento['tema'] ?? '') . "\nConvidados: " . $descricaoConvidados,
            'start' => ['dateTime' => $startDate->format('Y-m-d\TH:i:s'), 'timeZone' => 'America/Sao_Paulo'],
            'end' => ['dateTime' => $endDate->format('Y-m-d\TH:i:s'), 'timeZone' => 'America/Sao_Paulo'],
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => 'treino-' . $treinamento['id_treinamento'] . '-' . time(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet']
                ]
            ]
        ];

        if (!empty($convidados)) {
            $eventData['attendees'] = $convidados;
        }

        $event = new Google\Service\Calendar\Event($eventData);
        $googleEventIdExistente = trim((string)($treinamento['google_event_id'] ?? ''));

        if ($googleEventIdExistente !== '') {
            $eventDataUpdate = $eventData;
            unset($eventDataUpdate['conferenceData']);
            $eventUpdate = new Google\Service\Calendar\Event($eventDataUpdate);

            try {
                $createdEvent = $service->events->patch('primary', $googleEventIdExistente, $eventUpdate, ['sendUpdates' => 'all']);
            } catch (Throwable $e) {
                $createdEvent = $service->events->insert('primary', $event, ['conferenceDataVersion' => 1, 'sendUpdates' => 'all']);
            }
        } else {
            $createdEvent = $service->events->insert('primary', $event, ['conferenceDataVersion' => 1, 'sendUpdates' => 'all']);
        }

        $googleEventId = $createdEvent->getId();
        $googleMeetLink = $createdEvent->getHangoutLink();
        $googleAgendaLink = trim((string)$createdEvent->htmlLink);

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
            $googleMeetLink = $googleAgendaLink;
        }

        if (treinamentosTemColuna($pdo, 'google_agenda_link')) {
            $stmtUpdate = $pdo->prepare("UPDATE treinamentos SET google_event_id = ?, google_event_link = ?, google_agenda_link = ? WHERE id_treinamento = ?");
            $stmtUpdate->execute([$googleEventId, $googleMeetLink, $googleAgendaLink, $idTreinamento]);
        } else {
            $stmtUpdate = $pdo->prepare("UPDATE treinamentos SET google_event_id = ?, google_event_link = ? WHERE id_treinamento = ?");
            $stmtUpdate->execute([$googleEventId, $googleMeetLink, $idTreinamento]);
        }

        return [
            'success' => true,
            'message' => 'Google Meet criado com sucesso.',
            'google_event_link' => $googleMeetLink,
            'google_agenda_link' => $googleAgendaLink
        ];
    } catch (Throwable $e) {
        if (googleIsInvalidGrantError($e)) {
            googleForgetToken($tokenPath ?? googleTokenPath());
            return ['success' => false, 'message' => 'Sessao Google expirada. Faca login novamente.', 'needs_auth' => true, 'auth_start_url' => 'google_calendar_sync.php?id_treinamento=' . (int)$idTreinamento . '&start_auth=1'];
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// --- LÓGICA DE ALERTA: CLIENTES SEM AGENDAMENTO (PRÓXIMOS 3 DIAS) ---
$sql_alerta = "SELECT fantasia FROM clientes 
               WHERE (data_fim IS NULL OR data_fim = '0000-00-00') 
               AND id_cliente NOT IN (
                   SELECT DISTINCT id_cliente FROM treinamentos 
                   WHERE data_treinamento >= CURDATE() 
                   AND data_treinamento <= DATE_ADD(CURDATE(), INTERVAL 5 DAY)
               )";
$clientes_sem_agenda = $pdo->query($sql_alerta)->fetchAll(PDO::FETCH_COLUMN);

// --- CONTAGENS PARA OS CARDS ---
$total_clientes = (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE (data_fim IS NULL OR data_fim = '0000-00-00')")->fetchColumn();
$total_treinamentos = (int)$pdo->query("SELECT COUNT(*) FROM treinamentos")->fetchColumn();
$treinamentos_pendentes = (int)$pdo->query("SELECT COUNT(*) FROM treinamentos WHERE UPPER(status) = 'PENDENTE'")->fetchColumn();
$treinamentos_resolvidos = (int)$pdo->query("SELECT COUNT(*) FROM treinamentos WHERE UPPER(status) = 'RESOLVIDO'")->fetchColumn();
$total_pendencias_treinamentos = 0;
try {
    $total_pendencias_treinamentos = (int)$pdo->query("SELECT COUNT(*) FROM pendencias_treinamentos WHERE status_pendencia = 'ABERTA'")->fetchColumn();
} catch (Throwable $e) {
    $total_pendencias_treinamentos = 0;
}
$total_hoje = (int)$pdo->query("SELECT COUNT(*) FROM treinamentos WHERE DATE(data_treinamento) = CURDATE() AND UPPER(status) = 'PENDENTE'")->fetchColumn();

// --- PÁGINA DE TREINAMENTOS ---
// Removida lógica de inatividade local (agora no Dashboard)

// Filtros de busca por cliente removidos da UI - mantendo apenas ordenação e paginação
$filtro_cliente = ''; // Desativado para simplificação via treinamentos.php
$mostrar_todos = isset($_GET['mostrar_todos']) ? true : false;
$where_conditions = ["(c.data_fim IS NULL OR c.data_fim = '0000-00-00')"]; // Apenas clientes ativos
$params = [];

// Por padrão, mostramos apenas pendentes
if (!$mostrar_todos) {
    $where_conditions[] = "UPPER(t.status) = 'PENDENTE'";
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
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM treinamentos WHERE id_treinamento = ?");
    $stmt->execute([$id]);
    header("Location: treinamentos.php?msg=Removido com sucesso");
    exit;
}

// Lógica para Adicionar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['confirmar_encerramento'])) {
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
            header("Location: treinamentos.php?msg=" . urlencode("Preencha os campos obrigatorios para encerrar o treinamento.") . "&tipo=warning");
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
            }

            $pdo->commit();
            header("Location: treinamentos.php?msg=" . urlencode($mensagemRetorno) . "&tipo=success");
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header("Location: treinamentos.php?msg=" . urlencode("Erro ao encerrar treinamento: " . $e->getMessage()) . "&tipo=danger");
            exit;
        }
    }

    if (isset($_POST['salvar_link_google'])) {
        $id = $_POST['id_treinamento'] ?? null;
        $google_event_link_input = trim((string)($_POST['google_event_link'] ?? ''));
        $mensagem_retorno = "Link do Google Agenda salvo com sucesso";

        if (!empty($id)) {
            [$salvou, $erro] = salvarGoogleAgendaLink($pdo, $id, $google_event_link_input);
            if (!$salvou) {
                $mensagem_retorno = "Link Google Agenda não salvo: " . $erro;
                header("Location: treinamentos.php?msg=" . urlencode($mensagem_retorno) . "&tipo=danger");
            } else {
                header("Location: treinamentos.php?msg=" . urlencode($mensagem_retorno) . "&tipo=success");
            }
        }
        exit;
    }

    $abrirGoogleAgendaLink = '';
    $abrirGoogleAgendaTreinamentoId = 0;
    $id_cliente = $_POST['id_cliente'];
    $id_contato = $_POST['id_contato'];
    $tema = $_POST['tema'];
    $status = $_POST['status'];
    $data_treinamento = !empty($_POST['data_treinamento']) ? normalizarDataTreinamento($_POST['data_treinamento']) : null;
    $id_treinamento_atual = isset($_POST['id_treinamento']) ? (int)$_POST['id_treinamento'] : 0;
    $has_google_event_link = array_key_exists('google_event_link', $_POST);
    $has_google_agenda_link = array_key_exists('google_agenda_link', $_POST);
    $google_event_link = $has_google_event_link && !empty($_POST['google_event_link']) ? trim($_POST['google_event_link']) : null;
    $google_agenda_link = $has_google_agenda_link && !empty($_POST['google_agenda_link']) ? trim($_POST['google_agenda_link']) : null;
    $tem_coluna_google_agenda = treinamentosTemColuna($pdo, 'google_agenda_link');

    if (!empty($data_treinamento) && existeConflitoHorarioTreinamento($pdo, $data_treinamento, $id_treinamento_atual)) {
        $dataFormatada = date('d/m/Y H:i', strtotime($data_treinamento));
        $msgConflito = "Ja existe treinamento agendado para {$dataFormatada}. Escolha outra data/hora.";
        header("Location: treinamentos.php?msg=" . urlencode($msgConflito) . "&tipo=warning");
        exit;
    }

    if (isset($_POST['id_treinamento']) && !empty($_POST['id_treinamento'])) {

        // --- 1. ATUALIZAÇÃO DE TREINAMENTO EXISTENTE ---
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
        $stmt_up = $pdo->prepare("UPDATE treinamentos SET " . implode(", ", $campos_update) . " WHERE id_treinamento=?");
        $stmt_up->execute($params_update);
        $msg = "Treinamento atualizado com sucesso";
        $abrirGoogleAgendaTreinamentoId = (int)$_POST['id_treinamento'];
        
        $redirectUrl = "treinamentos.php?msg=" . urlencode($msg) . "&tipo=success";
        header("Location: " . $redirectUrl);
        exit;

    } elseif (isset($_POST['action']) && $_POST['action'] === 'save_client_observation') {
        // --- 2. SALVAR NOVO HISTÓRICO (OBSERVAÇÃO) ---
        $id_c = (int)$_POST['id_cliente'];
        $titulo = $_POST['titulo'] ?? 'Observação Geral';
        $conteudo = $_POST['conteudo'] ?? '';
        $tipo = $_POST['tipo'] ?? 'INFORMAÇÃO'; 
        $tags = $_POST['tags'] ?? '';
        $autor = $_POST['autor'] ?: 'Sistema';

        if ($id_c > 0) {
            $stmtObsAdd = $pdo->prepare("INSERT INTO observacoes_cliente (id_cliente, titulo, conteudo, tipo, tags, registrado_por) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtObsAdd->execute([$id_c, $titulo, $conteudo, $tipo, $tags, $autor]);
            
            header("Location: treinamentos.php?msg=Historico+registrado+com+sucesso&id_cliente_ref=$id_c");
            exit;
        }
    } else {
        // --- 3. INSERÇÃO DE NOVO TREINAMENTO ---
        $colunas_insert = ["id_cliente", "id_contato", "tema", "status", "data_treinamento", "google_event_link"];
        $params_insert = [$id_cliente, $id_contato, $tema, $status, $data_treinamento, $google_event_link];

        if ($has_google_agenda_link && $tem_coluna_google_agenda) {
            $colunas_insert[] = "google_agenda_link";
            $params_insert[] = $google_agenda_link;
        }

        $placeholders = implode(", ", array_fill(0, count($colunas_insert), "?"));
        $stmt_ins = $pdo->prepare("INSERT INTO treinamentos (" . implode(", ", $colunas_insert) . ") VALUES ($placeholders)");
        $stmt_ins->execute($params_insert);
        $novo_id_treinamento = (int)$pdo->lastInsertId();

        $syncResult = ['success' => false];
        $manual_link_provided = (!empty($google_event_link) || !empty($google_agenda_link));

        if (!$manual_link_provided) {
            $syncResult = sincronizarGoogleMeetAutomatico($pdo, $novo_id_treinamento);
        }

        if (!$manual_link_provided && !empty($syncResult['needs_auth']) && !empty($syncResult['auth_start_url'])) {
            header("Location: " . $syncResult['auth_start_url']);
            exit;
        }

        $forcarModalGoogle = false;

        if ($manual_link_provided) {
            $msg = "Treinamento adicionado com link salvo manualmente";
        } elseif (!empty($syncResult['success'])) {
            $msg = "Treinamento adicionado e sincronizado com Google Meet";
            if (!empty($syncResult['google_agenda_link'])) {
                $abrirGoogleAgendaLink = trim((string)$syncResult['google_agenda_link']);
                $abrirGoogleAgendaTreinamentoId = $novo_id_treinamento;
            }
        } elseif (!empty($syncResult['message'])) {
            $msg = "Treinamento adicionado. Link Google Meet não gerado: " . $syncResult['message'];
            $forcarModalGoogle = true;
            $abrirGoogleAgendaTreinamentoId = $novo_id_treinamento;
        } else {
            $msg = "Treinamento adicionado com sucesso";
            $forcarModalGoogle = true;
            $abrirGoogleAgendaTreinamentoId = $novo_id_treinamento;
        }

        $tipo_msg = $forcarModalGoogle ? "warning" : "success";
        $redirectUrl = "treinamentos.php?msg=" . urlencode($msg) . "&tipo=" . $tipo_msg;
        
        if ($abrirGoogleAgendaLink !== '') {
            $redirectUrl .= "&open_google_agenda=" . urlencode($abrirGoogleAgendaLink);
            $redirectUrl .= "&open_google_agenda_treinamento_id=" . (int)$abrirGoogleAgendaTreinamentoId;
            $redirectUrl .= "&open_google_modal_novo=1";
        } elseif ($forcarModalGoogle && $abrirGoogleAgendaTreinamentoId > 0) {
            $redirectUrl .= "&open_google_modal_id=" . (int)$abrirGoogleAgendaTreinamentoId;
        }
        
        header("Location: " . $redirectUrl);
        exit;
    }
}

// --- ENDPOINT PARA BUSCAR OBSERVAÇÕES VIA AJAX (GET) ---
if (isset($_GET['get_observations']) && isset($_GET['id_cliente'])) {
    $id_c = (int)$_GET['id_cliente'];
    $stmtAjax = $pdo->prepare("SELECT * FROM observacoes_cliente WHERE id_cliente = ? ORDER BY data_observacao DESC");
    $stmtAjax->execute([$id_c]);
    header('Content-Type: application/json');
    echo json_encode($stmtAjax->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// --- ENDPOINT PARA O FULLCALENDAR ---
if (isset($_GET['api_calendario'])) {
    $sql_cal = "SELECT t.id_treinamento as id, t.tema, t.data_treinamento as start, 
                       DATE_ADD(t.data_treinamento, INTERVAL 1 HOUR) as end,
                       t.status, c.fantasia as cliente, co.nome as contato, co.telefone_ddd as telefone 
                FROM treinamentos t 
                LEFT JOIN clientes c ON t.id_cliente = c.id_cliente 
                LEFT JOIN contatos co ON t.id_contato = co.id_contato
                WHERE t.data_treinamento IS NOT NULL";
    
    // Filtro por cliente na query do calendário
    $filtro_cliente_cal = $_GET['filtro_cliente'] ?? '';
    $params_cal = [];
    if (!empty($filtro_cliente_cal)) {
        $sql_cal .= " AND c.fantasia LIKE ?";
        $params_cal[] = "%{$filtro_cliente_cal}%";
    }

    $stmtCal = $pdo->prepare($sql_cal);
    $stmtCal->execute($params_cal);
    
    $events = [];
    foreach ($stmtCal->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $isResolvido = (strtoupper($row['status']) === 'RESOLVIDO');
        // Cores do sistema: verde para resolvido, azul para pendente
        $color = $isResolvido ? '#10b981' : '#4361ee'; 
        
        // Forçamos o cliente em caixa alta para o título
        $title = mb_convert_case($row['cliente'], MB_CASE_UPPER, "UTF-8");

        $events[] = [
            'id' => $row['id'],
            'title' => trim((string)$title),
            'start' => $row['start'],
            'end' => $row['end'],
            'backgroundColor' => $color,
            'borderColor' => 'transparent',
            'textColor' => '#ffffff',
            'extendedProps' => [
                'tema' => $row['tema'],
                'contato' => $row['contato'],
                'telefone' => $row['telefone'],
                'status' => $row['status'],
                'cliente' => $row['cliente']
            ]
        ];
    }
    header('Content-Type: application/json');
    echo json_encode($events);
    exit;
}




$open_google_agenda = '';
$open_google_agenda_treinamento_id = 0;
if (!empty($_GET['open_google_agenda'])) {
    $open_google_agenda_candidate = trim((string)$_GET['open_google_agenda']);
    if (filter_var($open_google_agenda_candidate, FILTER_VALIDATE_URL)) {
        $parsedAgendaUrl = parse_url($open_google_agenda_candidate);
        $agendaScheme = strtolower((string)($parsedAgendaUrl['scheme'] ?? ''));
        $agendaHost = strtolower((string)($parsedAgendaUrl['host'] ?? ''));
        $agendaPath = (string)($parsedAgendaUrl['path'] ?? '');

        $hostValido = in_array($agendaHost, ['calendar.google.com', 'www.google.com', 'google.com'], true);
        $pathValido = ($agendaHost === 'calendar.google.com') || (stripos($agendaPath, '/calendar/') === 0);

        if ($agendaScheme === 'https' && $hostValido && $pathValido) {
            $open_google_agenda = $open_google_agenda_candidate;
        }
    }
}
if (!empty($_GET['open_google_agenda_treinamento_id'])) {
    $open_google_agenda_treinamento_id = (int)$_GET['open_google_agenda_treinamento_id'];
}

// Visualização (lista ou calendario)
$view_mode = $_GET['view'] ?? 'list';

// Ordenação
$ordenacao = isset($_GET['ordenacao']) ? $_GET['ordenacao'] : 'data_treinamento';
$direcao = isset($_GET['direcao']) ? $_GET['direcao'] : 'asc';

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
    SELECT t.*, c.fantasia as cliente_nome, c.servidor, co.nome as contato_nome, co.telefone_ddd as contato_telefone, c.vendedor, c.num_licencas
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
$status_convites = obterStatusConvitesGoogle($treinamentos);
$hoje_data = date('Y-m-d');

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
/* // Design System Clean & Modern (Perplexity Style) */
:root {
    --glass-bg: rgba(255, 255, 255, 0.03);
    --glass-border: rgba(255, 255, 255, 0.08);
}

[data-theme="dark"] {
    --bg-body: #0d0e12;
    --bg-card: #14151a;
    --border-color: #2b2e35;
    --text-main: #e2e8f0;
    --text-muted: #94a3b8;
    --primary-light: rgba(67, 97, 238, 0.15);
    --warning-light: rgba(255, 193, 7, 0.1);
    --danger-light: rgba(220, 53, 69, 0.1);
    --info-light: rgba(13, 202, 240, 0.1);
}

/* License Badges Styles */
.badge-license {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0.35rem 0.65rem;
    font-size: 0.72rem;
    font-weight: 800;
    border-radius: 50px;
    border: 1px solid transparent;
}
.badge-license-1 { 
    background: #fff7ed; 
    color: #c2410c; 
    border-color: #fdba74; 
    font-weight: 900; 
    box-shadow: 0 0 10px rgba(249, 115, 22, 0.1);
    transform: scale(1.02); /* Leve destaque de escala */
}
[data-theme="dark"] .badge-license-1 { 
    background: rgba(249, 115, 22, 0.25); 
    color: #ffd8a8; 
    border-color: rgba(249, 115, 22, 0.5); 
    box-shadow: 0 0 15px rgba(249, 115, 22, 0.15);
}
.badge-license-2 { background: rgba(111, 66, 193, 0.1); color: #6f42c1; border-color: rgba(111, 66, 193, 0.2); }
[data-theme="dark"] .badge-license-2 { background: rgba(111, 66, 193, 0.2); color: #a389f4; border-color: rgba(111, 66, 193, 0.35); }
.badge-license-3 { background: rgba(16, 185, 129, 0.1); color: #10b981; border-color: rgba(16, 185, 129, 0.2); }
[data-theme="dark"] .badge-license-3 { background: rgba(16, 185, 129, 0.2); color: #34d399; border-color: rgba(16, 185, 129, 0.35); }

/* Page Header - Simplificado */
.modern-header {
    padding: 1rem 0 2rem 0;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 2rem;
}

.title-accent {
    color: var(--primary);
    background: linear-gradient(120deg, var(--primary), var(--purple));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

/* Dashboard Cards - Refinados */
.stats-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    transition: all 0.25s ease;
    height: 100%;
}

.stats-card:hover {
    border-color: var(--primary);
    transform: translateY(-3px);
}

.stats-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    margin-bottom: 1rem;
}

/* Table Style */
.table-premium {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: 3rem;
}

.table-premium thead th {
    background: var(--bg-body);
    font-size: 0.75rem !important;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted) !important;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color) !important;
}

.table-premium tbody td {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color) !important;
    font-size: 0.9rem;
    color: var(--text-main);
}

/* Animations */
.gsap-reveal {
    opacity: 0;
    transform: translateY(15px);
}

.fw-800 { font-weight: 800; }

/* Overrides para visibilidade de botões */
.btn-outline-primary { border-color: var(--primary); color: var(--primary); }
.btn-outline-primary:hover { background: var(--primary); color: #fff; }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.11/locales/pt-br.global.min.js'></script>

<div class="container-fluid px-0">
    <!-- Modern Header -->
    <div class="modern-header d-flex justify-content-between align-items-end gsap-reveal">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.8rem;">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Treinamentos</li>
                </ol>
            </nav>
            <h2 class="fw-800 mb-0">Agenda de <span class="title-accent">Treinamentos</span></h2>
            <p class="text-muted small mb-0">Gestão de capacitação técnica dos clientes.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <!-- Botões de alternância de visualização -->
            <div class="bg-body p-1 rounded-3 d-flex border me-2">
                <a href="treinamentos.php?view=list&filtro_cliente=<?= urlencode($filtro_cliente) ?>" class="btn btn-sm <?= $view_mode == 'list' ? 'btn-primary shadow-sm' : 'btn-link text-muted' ?> bx-button px-3">
                    <i class="bi bi-list"></i>
                </a>
                <a href="treinamentos.php?view=calendar&filtro_cliente=<?= urlencode($filtro_cliente) ?>" class="btn btn-sm <?= $view_mode == 'calendar' ? 'btn-primary shadow-sm' : 'btn-link text-muted' ?> bx-button px-3">
                    <i class="bi bi-calendar3"></i>
                </a>
            </div>
            
            <button type="button" class="btn btn-outline-success px-4 fw-bold shadow-sm d-flex align-items-center" id="btn_copiar_disponibilidade" disabled>
                <i class="bi bi-whatsapp me-2"></i>Copiar Horas
            </button>
            <button class="btn btn-primary px-4 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
                <i class="bi bi-calendar-plus me-2"></i>Novo Agendamento
            </button>
        </div>
    </div>

    <!-- Alertas de Inatividade -->
    <!-- Listagem Principal -->

    <!-- Main Content Area -->
    <?php if ($view_mode === 'list'): ?>
    <div class="table-premium gsap-reveal">
        <div class="p-4 border-bottom d-flex justify-content-between align-items-center bg-white">
            <h5 class="fw-bold mb-0">Listagem de Treinamentos</h5>
            <div class="d-flex gap-2">
                <a href="treinamentos.php?mostrar_todos=1" class="btn btn-sm btn-light border px-3">Ver Resolvidos</a>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Ordenar</button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="treinamentos.php?ordenacao=data_treinamento&direcao=asc"><i class="bi bi-calendar3 me-2"></i>Data</a></li>
                        <li><a class="dropdown-item py-2" href="treinamentos.php?ordenacao=cliente_nome&direcao=asc"><i class="bi bi-building me-2"></i>Cliente</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">
                            <a href="treinamentos.php?ordenacao=data_treinamento&direcao=<?= ($ordenacao == 'data_treinamento' && $direcao == 'asc') ? 'desc' : 'asc' ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>"
                                class="text-decoration-none d-flex align-items-center">
                                <span>Data Agendada</span>
                                <?php if ($ordenacao == 'data_treinamento'): ?>
                                    <i class="bi bi-caret-<?= $direcao == 'asc' ? 'up' : 'down' ?>-fill ms-1 small text-primary"></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>Cliente</th>
                        <th>Servidor</th>
                        <th>Contato</th>
                        <th>Tema</th>
                        <th>Vendedor</th>
                        <th class="text-center">Convite</th>
                        <th class="text-end pe-4">Ações</th>
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
                            <?php
                                $data_t = $t['data_treinamento'] ? strtotime($t['data_treinamento']) : 0;
                                $e_hoje = date('Y-m-d', $data_t) === date('Y-m-d');
                                $bg_class = $e_hoje ? 'bg-primary bg-opacity-10' : '';
                                
                                $contato_exibicao = $t['contato_nome'] ?: '---';
                                if (!empty($t['contato_telefone'])) {
                                    $contato_exibicao .= " - " . $t['contato_telefone'];
                                }
                            ?>
                            <tr class="<?= $bg_class ?>">
                                <td class="ps-4 fw-bold">
                                    <div class="text-dark">
                                        <?= $data_t ? date('d/m/Y H:i', $data_t) : '---' ?>
                                        <?php if($e_hoje): ?>
                                            <span class="badge bg-primary text-white ms-1" style="font-size: 0.6rem;">HOJE</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="fw-bold">
                                    <?php
                                        $numLics = (int)($t['num_licencas'] ?? 0);
                                        $badgeClass = '';
                                        if ($numLics == 1) {
                                            $badgeClass = 'badge-license-1';
                                        } elseif ($numLics == 2) {
                                            $badgeClass = 'badge-license-2';
                                        } elseif ($numLics >= 3) {
                                            $badgeClass = 'badge-license-3';
                                        }
                                    ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <span style="color: var(--text-main);"><?= htmlspecialchars($t['cliente_nome']) ?></span>
                                        <?php if ($numLics > 0): ?>
                                            <span class="badge-license <?= $badgeClass ?>" title="<?= $numLics ?> licença(s)">
                                                <?= $numLics ?> <i class="bi bi-pc-display"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="fw-bold">
                                    <div class=""><?= htmlspecialchars($t['servidor'] ?: '---') ?></div>
                                </td>
                                <td class="fw-bold">
                                    <div class=""><?= htmlspecialchars($contato_exibicao) ?></div>
                                </td>
                                <td class="fw-bold">
                                    <div class=""><?= htmlspecialchars($t['tema']) ?></div>
                                </td>
                                <td class="fw-bold">
                                    <div class=" text-uppercase"><?= htmlspecialchars($t['vendedor'] ?? '---') ?></div>
                                </td>
                                <td class="text-center fw-bold">
                                    <?php 
                                        $id_tr = (int)$t['id_treinamento'];
                                        $conv_status = $status_convites[$id_tr] ?? ['label' => 'Sem validacao', 'badge' => 'bg-warning text-dark'];
                                    ?>
                                    <div class="" style="font-size: 0.85rem;">
                                        <?= htmlspecialchars($conv_status['label']) ?>
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-1 flex-wrap">
                                        <!-- 1. HISTÓRICO (CRM) -->
                                        <button class="btn btn-sm btn-outline-primary btn-history-client"
                                            data-bs-toggle="tooltip"
                                            data-bs-title="Ver Histórico/CRM"
                                            data-id="<?= $t['id_cliente'] ?>"
                                            data-nome="<?= htmlspecialchars($t['cliente_nome']) ?>">
                                            <i class="bi bi-journal-text"></i>
                                        </button>

                                        <!-- 2. LUPA (OBSERVAÇÕES DO AGENDAMENTO) -->
                                        <?php if (!empty($t['observacoes'])): ?>
                                            <button class="btn btn-sm btn-outline-info view-obs-btn"
                                                data-bs-toggle="tooltip"
                                                data-bs-title="Ver Obs. Agendamento"
                                                data-obs="<?= htmlspecialchars($t['observacoes']) ?>"
                                                data-cliente="<?= htmlspecialchars($t['cliente_nome']) ?>">
                                                <i class="bi bi-search"></i>
                                            </button>
                                        <?php endif; ?>

                                         <?php
                                            $nome_contato_wp = trim((string)($t['contato_nome'] ?? $t['cliente_nome']));
                                            $dt_treino = new DateTime($t['data_treinamento'], new DateTimeZone('America/Sao_Paulo'));
                                            $dias_semana_wp = ['1' => 'Segunda-Feira', '2' => 'Terça-Feira', '3' => 'Quarta-Feira', '4' => 'Quinta-Feira', '5' => 'Sexta-Feira', '6' => 'Sábado', '7' => 'Domingo'];
                                            $data_f  = $dt_treino->format('d/m') . ' ' . ($dias_semana_wp[$dt_treino->format('N')] ?? '');
                                            $dt_treino_fim = clone $dt_treino;
                                            $dt_treino_fim->modify('+1 hour');
                                            $hora_inicio = $dt_treino->format('H:i');
                                            $hora_fim = $dt_treino_fim->format('H:i');
                                            $tema_f  = mb_strtoupper(trim((string)($t['tema'] ?? '')));
                                            $link_google_meet   = trim((string)($t['google_event_link'] ?? ''));
                                            $link_google_agenda = trim((string)($t['google_agenda_link'] ?? ''));

                                            $msg_wp  = "Olá, {$nome_contato_wp}! 👋\n\n✅ Seu treinamento GestãoPRO está confirmado!\n\n";
                                            $msg_wp .= "🔢 ID do treinamento: #{$t['id_treinamento']}\n";
                                            $msg_wp .= "📅 *Data:* {$data_f}\n";
                                            $msg_wp .= "🕒 Horário do treinamento: das {$hora_inicio}h às {$hora_fim}h, horário de Brasília.\n";
                                            $msg_wp .= "🎯 Tema: {$tema_f}\n";
                                            $msg_wp .= "\n💻 Acesse pelo Google Meet:\n" . ($link_google_meet ?: 'não informado') . "\n";
                                            if ($link_google_agenda !== '') {
                                                $msg_wp .= "\n📆 Adicione à sua agenda:\n" . $link_google_agenda . "\n";
                                            }
                                            $msg_wp .= "\n📌 *Lembrete importante:* no momento do treinamento, tenha o *TeamViewer* ou *AnyDesk* instalado e pronto para uso.\n\nQualquer dúvida, é só me chamar. Até lá! 🚀";
                                            $msg_wp_attr = htmlspecialchars($msg_wp, ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <button class="btn btn-sm btn-outline-success copy-whatsapp-message"
                                            data-bs-toggle="tooltip"
                                            data-bs-title="Copiar WhatsApp"
                                            data-message="<?= $msg_wp_attr ?>">
                                            <i class="bi bi-whatsapp"></i>
                                        </button>

                                        <!-- 3. GOOGLE AGENDA (LINK OU SYNC) -->
                                        <?php if ($link_google_agenda_exibicao !== ''): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-primary open-google-link-modal"
                                                    data-id="<?= $id_tr ?>"
                                                    data-cliente="<?= htmlspecialchars($t['cliente_nome']) ?>"
                                                    data-google-link="<?= htmlspecialchars($link_google_agenda) ?>"
                                                    title="Link Google Agenda"
                                                    data-bs-toggle="tooltip">
                                                <i class="bi bi-calendar-check"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-danger sync-google-btn"
                                                data-bs-toggle="tooltip"
                                                data-bs-title="Sincronizar Google"
                                                data-id="<?= $id_tr ?>"
                                                data-cliente="<?= htmlspecialchars($t['cliente_nome']) ?>">
                                                <i class="bi bi-google"></i>
                                            </button>
                                        <?php endif; ?>

                                        <!-- 4. EDITAR -->
                                        <button class="btn btn-sm btn-outline-secondary edit-btn"
                                            data-bs-toggle="tooltip"
                                            data-bs-title="Editar"
                                            data-id="<?= $id_tr ?>"
                                            data-cliente="<?= $t['id_cliente'] ?>"
                                            data-contato="<?= $t['id_contato'] ?>"
                                            data-tema="<?= htmlspecialchars($t['tema']) ?>"
                                            data-status="<?= $t['status'] ?>"
                                            data-google-link="<?= htmlspecialchars($link_google_agenda_exibicao) ?>"
                                            data-data="<?= $t['data_treinamento'] ? date('Y-m-d\TH:i', strtotime($t['data_treinamento'])) : '' ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <!-- 5. FINALIZAR -->
                                        <?php if (strtoupper($t['status']) == 'PENDENTE'): ?>
                                            <button class="btn btn-sm btn-outline-success open-finish-modal"
                                                data-id="<?= $id_tr ?>"
                                                data-cliente="<?= htmlspecialchars($t['cliente_nome']) ?>"
                                                data-tema="<?= htmlspecialchars($t['tema']) ?>"
                                                title="Finalizar"
                                                data-bs-toggle="tooltip">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        <?php endif; ?>

                                        <!-- 6. EXCLUIR -->
                                        <a href="?delete=<?= $id_tr ?>&pagina=<?= $pagina ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>"
                                            class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="tooltip"
                                            data-bs-title="Excluir"
                                            onclick="return confirm('Excluir este treinamento?')">
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
            <div class="p-4 border-top">
                <nav aria-label="Navegação de páginas">
                    <ul class="pagination justify-content-center mb-0 gap-1">
                        <?php if ($pagina > 1): ?>
                            <li class="page-item">
                                <a class="page-link rounded-3 border-0 bg-light" href="treinamentos.php?pagina=<?= $pagina - 1 ?>&ordenacao=<?= $ordenacao ?>&direcao=<?= $direcao ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php
                        $inicio = max(1, $pagina - 2);
                        $fim = min($total_paginas, $pagina + 2);

                        for ($i = $inicio; $i <= $fim; $i++):
                        ?>
                            <li class="page-item <?= ($i == $pagina) ? 'active' : '' ?>">
                                <a class="page-link rounded-3 border-0 <?= ($i == $pagina) ? 'bg-primary text-white' : 'bg-light text-muted' ?>" href="treinamentos.php?pagina=<?= $i ?>&ordenacao=<?= $ordenacao ?>&direcao=<?= $direcao ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($pagina < $total_paginas): ?>
                            <li class="page-item">
                                <a class="page-link rounded-3 border-0 bg-light" href="treinamentos.php?pagina=<?= $pagina + 1 ?>&ordenacao=<?= $ordenacao ?>&direcao=<?= $direcao ?>&filtro_cliente=<?= urlencode($filtro_cliente) ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?> <!-- /Fim VIEW LIST -->

    <?php if ($view_mode === 'calendar'): ?>
    <div class="dashboard-section gsap-reveal">
        <div id="calendar-container" style="background: var(--bg-card); padding: 1rem; border-radius: var(--radius-lg); border: 1px solid var(--border-color); overflow: hidden;">
            <div id="calendar"></div>
        </div>
    </div>
    <style>
        /* Container e Estrutura Base */
        .fc-theme-standard {
            background: var(--bg-card);
            border-radius: 16px; /* Borda bem arredondada para todo o calendário */
            border: 1px solid var(--border-color);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .fc-theme-standard td, .fc-theme-standard th { 
            border-color: var(--border-color); 
        }
        
        /* Toolbar e Botões */
        .fc-toolbar {
            padding: 1rem;
            margin-bottom: 0 !important;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-body);
        }
        .fc-toolbar-title { 
            color: var(--text-main); 
            font-family: var(--font-heading); 
            font-weight: 800; 
            font-size: 1.4rem !important;
            letter-spacing: -0.02em;
        }
        .fc .fc-button-primary { 
            background-color: var(--bg-card); 
            border: 1px solid var(--border-color); 
            color: var(--text-main); 
            font-weight: 600; 
            border-radius: 8px; /* Arredondamento suave nos botões */
            text-transform: capitalize; 
            padding: 0.4rem 1rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
        }
        .fc .fc-button-primary:hover { 
            background-color: var(--primary-light); 
            border-color: var(--primary); 
            color: var(--primary); 
        }
        .fc .fc-button-primary:not(:disabled).fc-button-active, 
        .fc .fc-button-primary:not(:disabled):active { 
            background-color: var(--primary); 
            border-color: var(--primary); 
            color: white; 
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* Cabeçalho dos Dias (Dom, Seg, Ter...) */
        .fc-col-header-cell {
            background: var(--bg-body);
            padding: 12px 0;
        }
        .fc-theme-standard .fc-scrollgrid {
            border: none;
        }
        .fc-col-header-cell-cushion { 
            color: var(--text-muted); 
            text-transform: uppercase; 
            font-size: 0.75rem; 
            font-weight: 700; 
            letter-spacing: 0.05em; 
            text-decoration: none;
        }
        .fc-col-header-cell-cushion:hover {
            color: var(--text-main);
            text-decoration: none;
        }

        /* Eventos - Design Técnico e Preciso */
        .fc-event {
            border: none;
            border-radius: 2px !important; /* Bordas nítidas para visual premium/técnico */
            padding: 0 !important;
            font-size: 0.75rem; 
            font-family: var(--font-body); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); 
            cursor: pointer; 
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 2px;
            border-left: 4px solid rgba(255,255,255,0.3) !important; /* Indicador lateral de status */
            min-height: 60px !important; /* Garante espaço para Horário, Cliente, Contato e Telefone */
            overflow: visible !important;
        }
        .fc-v-event { border: none !important; }
        .fc-timegrid-event { margin-bottom: 2px !important; }
        .fc-event:hover { 
            transform: scale(1.02); 
            filter: brightness(1.15); 
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
            z-index: 10;
        }
        .fc-event-time { 
            font-weight: 800; 
            background: rgba(0,0,0,0.1);
            padding: 1px 4px;
            border-radius: 0 0 4px 0;
            font-size: 0.65rem;
        }
        .fc-event-title { font-weight: 700; }

        /* Células de Dias Gerais */
        .fc-daygrid-day-number { 
            color: var(--text-main); 
            font-weight: 600; 
            font-size: 0.85rem; 
            padding: 8px 12px; 
            text-decoration: none;
            opacity: 0.7;
            transition: opacity 0.2s ease;
        }
        .fc-daygrid-day-number:hover {
            opacity: 1;
            text-decoration: none;
        }
        .fc-day-today { 
            background-color: rgba(67, 97, 238, 0.05) !important; 
        }
        
        /* TimeGrid (Semana/Dia) Específico */
        .fc-timegrid-slot-label-cushion {
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 600;
        }
        .fc-timegrid-slot {
            height: 2.0rem !important; /* Aumenta a altura de cada slot de tempo para evitar sobreposição */
        }
        .fc-timegrid-axis-cushion {
            color: var(--text-muted);
            font-size: 0.75rem;
        }

        /* Mais/Popover */
        .fc-daygrid-more-link {
            color: var(--primary);
            font-weight: 700;
            font-size: 0.75rem;
            padding: 2px 4px;
            border-radius: 4px;
        }
        .fc-daygrid-more-link:hover {
            background: var(--primary-light);
            text-decoration: none;
        }
        .fc .fc-popover { 
            background: var(--bg-card); 
            border: 1px solid var(--border-color); 
            border-radius: 12px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.5); 
            overflow: hidden; 
        }
        .fc .fc-popover-header { 
            background: var(--bg-body); 
            padding: 10px 15px; 
        }
        .fc .fc-popover-title { 
            color: var(--text-main); 
            font-weight: 700; 
            font-size: 0.9rem; 
            letter-spacing: -0.01em; 
        }
        .fc .fc-popover-close {
            color: var(--text-muted);
            opacity: 0.7;
        }
        
        /* Ajuste do dia de Hoje (Header e Corpo) */
        th.fc-col-header-cell.fc-day-today {
            background-color: var(--primary) !important;
        }
        th.fc-col-header-cell.fc-day-today .fc-col-header-cell-cushion {
            color: #ffffff !important;
        }
        /* Ajuste do Eixo de Tempo e Canto Superior Esquerdo Branco */
        .fc-theme-standard .fc-timegrid-axis {
            background: var(--bg-body) !important;
        }
        th.fc-timegrid-axis {
            background-color: var(--bg-body) !important; 
        }
        .fc-timegrid-axis-cushion, .fc-timegrid-slot-label-cushion {
            color: var(--text-muted) !important;
            font-size: 0.75rem;
            font-weight: 600;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            if(calendarEl) {
                var calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'timeGridWeek',
                    locale: 'pt-br',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay'
                    },
                    events: 'treinamentos.php?api_calendario=1&filtro_cliente=<?= urlencode($filtro_cliente) ?>',
                    buttonText: {
                        today:    'Hoje',
                        month:    'Mês',
                        week:     'Semana',
                        day:      'Dia'
                    },
                    height: 'auto',
                    expandRows: true, // Garante que as linhas ocupem o espaço disponível
                    dayMaxEvents: true,
                    allDaySlot: false,
                    slotMinTime: '08:00:00',
                    slotMaxTime: '17:30:00',
                    hiddenDays: [0, 6],
                    displayEventTime: false,
                    eventContent: function(arg) {
                        let client = arg.event.extendedProps.cliente || '';
                        let contact = arg.event.extendedProps.contato || '';
                        let phone = arg.event.extendedProps.telefone || '';
                        
                        // Formatação manual do horário (ex: 08:30)
                        let startTime = "";
                        if (arg.event.start) {
                            let h = arg.event.start.getHours().toString().padStart(2, '0');
                            let m = arg.event.start.getMinutes().toString().padStart(2, '0');
                            startTime = h + ":" + m;
                        }
                        
                        let html = `
                            <div class="fc-event-main-frame d-flex flex-column" style="padding: 4px 6px; line-height: 1.2; height: 100%; border-radius: 2px;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div class="fw-900" style="font-size: 0.7rem; color: #fff;">${startTime}</div>
                                    ${phone ? `<div style="font-size: 0.65rem; color: #fff; background: rgba(0,0,0,0.3); padding: 1px 5px; border-radius: 10px; font-weight: 700;"><i class="bi bi-telephone"></i> ${phone}</div>` : ''}
                                </div>
                                <div class="fc-event-title-container">
                                    <div class="fw-800 text-truncate" style="font-size: 0.75rem; color: #fff; margin-bottom: 2px;">${client.toUpperCase()}</div>
                                    <div class="d-flex align-items-center gap-1" style="font-size: 0.65rem; color: rgba(255,255,255,0.9); white-space: nowrap; overflow: hidden;">
                                        <i class="bi bi-person-fill"></i> ${contact}
                                    </div>
                                </div>
                            </div>
                        `;
                        return { html: html };
                    },
                    eventClick: function(info) {
                        try {
                            if (info.event.extendedProps && info.event.extendedProps.cliente) {
                                window.location.href = 'treinamentos.php?view=list&filtro_cliente=' + encodeURIComponent(info.event.extendedProps.cliente);
                            }
                        } catch(e) {}
                    }
                });
                calendar.render();
            }
        });
    </script>
    <?php endif; ?>

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

                <div class="mt-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <label class="form-label small fw-bold text-muted mb-0">Horários disponíveis (hoje, amanhã e depois)</label>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btn_buscar_disponibilidade">
                                <i class="bi bi-calendar-week me-1"></i>Atualizar
                            </button>
                        </div>
                    </div>
                    <div id="disponibilidade_resultado" class="border rounded-3 p-2 bg-light small text-muted">
                        Clique em "Atualizar" para listar os horarios livres.
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

<!-- Modal Finalizar Treinamento -->
<div class="modal fade" id="modalEncerrar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold text-dark"><i class="bi bi-journal-check me-2 text-success"></i>Finalizar Treinamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 text-start">
                <input type="hidden" name="id_treinamento" id="modal_id_treinamento">
                <input type="hidden" name="confirmar_encerramento" value="1">
                
                <div class="mb-3 p-3 bg-light rounded-3 border">
                    <div class="small text-muted mb-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Informações:</div>
                    <div class="fw-bold text-primary" id="modal_cliente_info"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">O que ficou acordado com o cliente?</label>
                    <textarea name="observacoes" class="form-control" rows="4" placeholder="Descreva os detalhes da sessão..." required></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase d-block mb-2">Pendencias relacionadas a este encerramento</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input pendencia-opcao" type="radio" name="tem_pendencia" id="tem_pendencia_nao" value="nao" required>
                        <label class="form-check-label text-dark" for="tem_pendencia_nao">Sem pendencia</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input pendencia-opcao" type="radio" name="tem_pendencia" id="tem_pendencia_sim" value="sim" required>
                        <label class="form-check-label text-dark" for="tem_pendencia_sim">Com pendencia</label>
                    </div>
                </div>
                <div class="mb-2 d-none" id="referencia_chamado_wrapper">
                    <label class="form-label small fw-bold text-muted text-uppercase">Referencia do chamado externo (opcional)</label>
                    <input type="text" class="form-control" name="referencia_chamado" id="referencia_chamado" maxlength="255" placeholder="Ex: SUP-12345, DEV-90210">
                </div>
                <div class="form-text small opacity-75">A marcacao com/sem pendencia e obrigatoria para concluir o treinamento.</div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm">Encerrar e Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Gerenciar Link Manual -->
<div class="modal fade" id="modalGoogleLink" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold text-dark"><i class="bi bi-link-45deg me-2 text-primary"></i>Link Google Agenda</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 text-start">
                <input type="hidden" name="id_treinamento" id="google_modal_id_treinamento">
                <input type="hidden" name="salvar_link_google" value="1">

                <div class="mb-3 p-3 bg-light rounded-3 border">
                    <div class="small text-muted mb-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Treinamento:</div>
                    <div class="fw-bold text-primary" id="google_modal_cliente_info"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Convite Google Agenda</label>
                    <input type="url"
                           name="google_event_link"
                           id="google_event_link_field"
                           class="form-control mb-2"
                           placeholder="https://calendar.app.google/...">
                    
                    <div class="d-flex gap-2 flex-wrap mb-3">
                        <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="window.open(document.getElementById('google_event_link_field').value || 'https://calendar.google.com', '_blank')">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Testar Link
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm px-3" onclick="colarLinkManual()">
                            <i class="bi bi-clipboard-check me-1"></i>Colar
                        </button>
                    </div>
                </div>
                <div class="form-text small opacity-75">
                    Cole o link de convite do Google Agenda caso a sincronização automática não seja possível.
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">Salvar Link</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL PARA VISUALIZAR HISTÓRICO DO CLIENTE (AJAX) -->
<div class="modal fade" id="modalHistoricoCliente" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 p-4 pb-0">
                <h5 class="fw-800 mb-0">
                    <i class="bi bi-journal-text me-2 text-primary"></i> Históricos: <span id="hist_cliente_nome" class="text-primary"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="hist_obs_container">
                    <div class="text-center py-5">
                        <div class="spinner-border" role="status" style="color: #7209b7;"></div>
                        <p class="mt-2 text-muted">Carregando históricos...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-premium w-100" onclick="abrirModalNovaObs()" style="background: linear-gradient(135deg, var(--primary) 0%, #1e293b 100%); color: white; border-radius: 12px; font-weight: 700; padding: 12px;">
                    <i class="bi bi-plus-circle me-2"></i> Adicionar Novo Registro Agora
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL PARA ADICIONAR NOVA OBSERVAÇÃO (HISTÓRICO) -->
<div class="modal fade" id="modalNovaObsCliente" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <input type="hidden" name="action" value="save_client_observation">
            <input type="hidden" name="id_cliente" id="nova_obs_id_cliente">
            <div class="modal-header border-0 p-4 pb-0">
                <h5 class="fw-800 mb-0">Novo Registro de <span class="text-primary">Histórico</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Título do Evento</label>
                    <input type="text" name="titulo" class="form-control" placeholder="Ex: Contato via WhatsApp" required>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold text-muted">Tipo</label>
                        <select name="tipo" class="form-select">
                            <option value="INFORMAÇÃO">Informação</option>
                            <option value="CONTATO">Contato Extra</option>
                            <option value="AJUSTE">Ajuste Técnico</option>
                            <option value="PROBLEMA">Problema / Bug</option>
                            <option value="MELHORIA">Sugestão / Melhoria</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold text-muted">Autor</label>
                        <input type="text" name="autor" class="form-control" value="Suporte" placeholder="Seu nome">
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label small fw-bold text-muted">Conteúdo Detalhado</label>
                    <textarea name="conteudo" class="form-control" rows="5" placeholder="Descreva o que foi feito ou conversado..." required></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="submit" class="btn btn-premium w-100 shadow-sm" style="background: linear-gradient(135deg, var(--primary) 0%, #1e293b 100%); color: white; border-radius: 12px; font-weight: 700; padding: 12px;">Salvar no Histórico Permanente</button>
            </div>
        </form>
    </div>
</div>


<script>
    // Inicializar tooltips
    document.addEventListener('DOMContentLoaded', function() {
        const openGoogleAgendaLink = <?= json_encode($open_google_agenda, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const openGoogleAgendaTreinamentoId = <?= (int)$open_google_agenda_treinamento_id ?>;
        if (openGoogleAgendaLink) {
            const win = window.open(openGoogleAgendaLink, '_blank', 'noopener');
            if (!win) {
                alert('O navegador bloqueou a abertura em nova guia. Libere pop-ups para este site e tente novamente.');
            }
            if (openGoogleAgendaTreinamentoId > 0) {
                const redirectUrl = 'treinamentos.php?msg=Link+do+Google+Agenda+copiado.+Cole+no+campo+manual+abaixo.&tipo=info&open_google_modal_id=' + openGoogleAgendaTreinamentoId;
                setTimeout(function() {
                    window.location.href = redirectUrl;
                }, 450);
            }
        }

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
            const pad = (n) => String(n).padStart(2, '0');
            dataTreinamentoInput.value = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
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
    let diasDisponibilidadeAtual = [];
    let disponibilidadeFoiCarregada = false;

    let mensagemErroGlobal = null;

    function atualizarBotaoCopiarDisponibilidade() {
        const botao = document.getElementById('btn_copiar_disponibilidade');
        if (!botao) return;
        
        if (mensagemErroGlobal) {
            botao.disabled = true;
            botao.classList.add('opacity-50');
            botao.title = "Erro Google: " + mensagemErroGlobal;
            botao.innerHTML = '<i class="bi bi-exclamation-octagon me-2"></i>Erro Google';
            return;
        }

        botao.disabled = !disponibilidadeFoiCarregada;
        botao.innerHTML = '<i class="bi bi-whatsapp me-2"></i>Copiar Horas';
        
        if (botao.disabled) {
            botao.classList.add('opacity-50');
            botao.title = "Carregando disponibilidade do Google...";
        } else {
            botao.classList.remove('opacity-50');
            botao.title = "Copiar horários para o cliente";
        }
    }

    function copiarTextoAreaTransferencia(texto, mensagemSucesso) {
        navigator.clipboard.writeText(texto).then(() => {
            document.getElementById('toastMessage').textContent = mensagemSucesso;
            const toast = new bootstrap.Toast(document.getElementById('copyToast'));
            toast.show();
        }).catch(() => {
            const textArea = document.createElement('textarea');
            textArea.value = texto;
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                document.getElementById('toastMessage').textContent = mensagemSucesso;
                const toast = new bootstrap.Toast(document.getElementById('copyToast'));
                toast.show();
            } catch (err) {
                alert('Nao foi possivel copiar. Tente novamente.');
            }
            document.body.removeChild(textArea);
        });
    }

    function montarMensagemDisponibilidadeCliente(diasDisponiveis) {
        const clienteSelect = document.getElementById('id_cliente');
        const clienteNome = clienteSelect && clienteSelect.selectedIndex > 0 ?
            clienteSelect.options[clienteSelect.selectedIndex].text : '';

        const linhas = [];
        const diasComSlot = diasDisponiveis.filter(dia => Array.isArray(dia.horarios) && dia.horarios.length > 0);

        linhas.push('Olá' + (clienteNome ? ' ' + clienteNome : '') + '! Tudo bem? 👍');
        linhas.push('');
        
        if (diasComSlot.length === 0) {
            linhas.push('Para agendarmos nosso treinamento, no momento não tenho horários livres para os próximos dias, mas podemos combinar um horário específico se preferir. 😕');
        } else {
            linhas.push('Para agendarmos nosso treinamento, veja os horários que tenho disponíveis:');
            linhas.push('');
            diasComSlot.forEach((dia) => {
                const dataLabel = dia.data_label || dia.data || 'Dia';
                const horas = dia.horarios.map((slot) => slot.hora).filter(Boolean);
                linhas.push('*' + dataLabel + '*: ' + horas.join(', '));
            });
        }

        linhas.push('');
        linhas.push('Qual desses fica melhor para você? Me avisa aqui que eu já reservo! 🚀');
        return linhas.join('\n');
    }

    function copiarTabelaDisponibilidadeCliente() {
        if (!disponibilidadeFoiCarregada) {
            alert('Atualize a disponibilidade antes de copiar a tabela.');
            return;
        }

        const mensagem = montarMensagemDisponibilidadeCliente(diasDisponibilidadeAtual);
        copiarTextoAreaTransferencia(mensagem, 'Tabela de horarios copiada para enviar ao cliente.');
    }

    function renderDisponibilidade(container, diasDisponiveis) {
        container.innerHTML = '';

        if (!Array.isArray(diasDisponiveis) || diasDisponiveis.length === 0) {
            container.innerHTML = '<div class="text-muted">Nenhuma disponibilidade encontrada no periodo.</div>';
            return;
        }

        let totalHorarios = 0;
        let proximoHorario = null;
        diasDisponiveis.forEach((dia) => {
            const bloco = document.createElement('div');
            bloco.className = 'mb-2';

            const titulo = document.createElement('div');
            titulo.className = 'fw-bold text-dark mb-1';
            titulo.textContent = dia.data_label || dia.data || 'Dia';
            bloco.appendChild(titulo);

            const wrap = document.createElement('div');
            wrap.className = 'd-flex flex-wrap gap-1';
            const horarios = Array.isArray(dia.horarios) ? dia.horarios : [];

            if (horarios.length === 0) {
                const vazio = document.createElement('span');
                vazio.className = 'text-muted';
                vazio.textContent = 'Sem horários disponíveis.';
                wrap.appendChild(vazio);
            } else {
                totalHorarios += horarios.length;
                horarios.forEach((slot) => {
                    if (!proximoHorario && slot.datetime_local) {
                        proximoHorario = {
                            datetime_local: slot.datetime_local,
                            data_label: dia.data_label || dia.data || '',
                            hora: slot.hora || '--:--'
                        };
                    }

                    const botao = document.createElement('button');
                    botao.type = 'button';
                    const isProximo = proximoHorario && proximoHorario.datetime_local === slot.datetime_local;
                    botao.className = isProximo ? 'btn btn-sm btn-success' : 'btn btn-sm btn-outline-success';
                    botao.textContent = slot.hora || '--:--';
                    botao.dataset.datetime = slot.datetime_local || '';
                    botao.addEventListener('click', function() {
                        const dataTreinamentoInput = document.getElementById('data_treinamento');
                        if (dataTreinamentoInput && this.dataset.datetime) {
                            dataTreinamentoInput.value = this.dataset.datetime;
                            dataTreinamentoInput.dispatchEvent(new Event('change'));
                        }
                    });
                    wrap.appendChild(botao);
                });
            }

            bloco.appendChild(wrap);
            container.appendChild(bloco);
        });

        if (proximoHorario) {
            const destaque = document.createElement('div');
            destaque.className = 'alert alert-success py-2 px-3 mb-2';
            destaque.innerHTML = '<strong>Proximo horario livre:</strong> ' + proximoHorario.data_label + ' ' + proximoHorario.hora;
            container.insertBefore(destaque, container.firstChild);
        }

        if (totalHorarios === 0) {
            container.insertAdjacentHTML('beforeend', '<div class="text-danger mt-1">Não há horários livres para hoje, amanhã e depois.</div>');
        }
    }

    function carregarDisponibilidadeGoogle() {
        const container = document.getElementById('disponibilidade_resultado');
        const botaoBusca = document.getElementById('btn_buscar_disponibilidade');
        const botaoCopiar = document.getElementById('btn_copiar_disponibilidade');
        
        if (!container) return;

        // Feedback visual de carregamento
        if (botaoBusca) botaoBusca.disabled = true;
        if (botaoCopiar) {
            botaoCopiar.disabled = true;
            botaoCopiar.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Carregando...';
        }
        
        container.innerHTML = '<div class="text-muted"><div class="spinner-border spinner-border-sm me-2"></span>Consultando Google Agenda...</div>';

        fetch('google_calendar_disponibilidade.php?dias=2&duracao_min=60', {
                cache: 'no-store'
            })
            .then(r => r.json())
            .then(data => {
                if (!data || !data.success) {
                    mensagemErroGlobal = data && data.message ? data.message : 'Falha ao carregar disponibilidade.';
                    if (container) container.innerHTML = '<div class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>' + mensagemErroGlobal + '</div>';
                    disponibilidadeFoiCarregada = false;
                    return;
                }
                mensagemErroGlobal = null;
                diasDisponibilidadeAtual = data.dias_disponiveis || [];
                disponibilidadeFoiCarregada = true;
                if (container) renderDisponibilidade(container, diasDisponibilidadeAtual);
            })
            .catch((err) => {
                console.error('Erro Google:', err);
                mensagemErroGlobal = "Erro de conexão";
                if (container) container.innerHTML = '<div class="text-danger">Erro na comunicação com o servidor.</div>';
                disponibilidadeFoiCarregada = false;
            })
            .finally(() => {
                if (botaoBusca) botaoBusca.disabled = false;
                if (botaoCopiar) {
                    botaoCopiar.innerHTML = '<i class="bi bi-whatsapp me-2"></i>Copiar Horas';
                }
                atualizarBotaoCopiarDisponibilidade();
            });
    }

    function copiarLinkAgenda(link, clienteNome = '') {
        let message = 'Link copiado para a area de transferencia!';
        if (clienteNome) {
            message = `Link do treinamento (${clienteNome}) copiado!`;
        }
        copiarTextoAreaTransferencia(link, message);
        return;

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
            carregarDisponibilidadeGoogle();
            new bootstrap.Modal(document.getElementById('modalTreinamento')).show();
        });
    });

    // 5. Finalizar Treinamento (Ex-Relatorio)
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
            
            new bootstrap.Modal(document.getElementById('modalEncerrar')).show();
        });
    });

    // 6. Link Manual Google (Ex-Relatorio)
    document.querySelectorAll('.open-google-link-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('google_modal_id_treinamento').value = this.dataset.id;
            document.getElementById('google_modal_cliente_info').innerText = this.dataset.cliente;
            document.getElementById('google_event_link_field').value = this.dataset.googleLink || '';
            new bootstrap.Modal(document.getElementById('modalGoogleLink')).show();
        });
    });

    async function colarLinkManual() {
        const input = document.getElementById('google_event_link_field');
        if (!input) return;
        try {
            const texto = (await navigator.clipboard.readText()).trim();
            if (texto.startsWith('http')) {
                input.value = texto;
            } else {
                alert('Área de transferência não contém um link válido.');
            }
        } catch (e) {
            alert('Não foi possível ler a área de transferência.');
        }
    }

    // 7. WhatsApp Copy
    document.querySelectorAll('.copy-whatsapp-message').forEach(btn => {
        btn.addEventListener('click', function() {
            const msg = this.dataset.message;
            copiarTextoAreaTransferencia(msg, 'Mensagem WhatsApp copiada!');
        });
    });

    // 8. Auto-abrir modal Google se parametro na URL
    const urlParams = new URLSearchParams(window.location.search);
    const autoId = urlParams.get('open_google_modal_id');
    if (autoId) {
        // Aguarda um pouco para os modais estarem prontos
        setTimeout(() => {
            const btn = document.querySelector(`.open-google-link-modal[data-id="${autoId}"]`);
            if (btn) {
                btn.click();
            } else {
                const syncBtn = document.querySelector(`.sync-google-btn[data-id="${autoId}"]`);
                document.getElementById('google_modal_id_treinamento').value = autoId;
                document.getElementById('google_modal_cliente_info').innerText = syncBtn ? syncBtn.dataset.cliente : 'Treinamento';
                const eventLinkField = document.getElementById('google_event_link_field');
                if(eventLinkField) eventLinkField.value = '';
                new bootstrap.Modal(document.getElementById('modalGoogleLink')).show();
            }
        }, 500);
    }

    const btnBuscarDisponibilidade = document.getElementById('btn_buscar_disponibilidade');
    if (btnBuscarDisponibilidade) {
        btnBuscarDisponibilidade.addEventListener('click', carregarDisponibilidadeGoogle);
    }
    const btnCopiarDisponibilidade = document.getElementById('btn_copiar_disponibilidade');
    if (btnCopiarDisponibilidade) {
        btnCopiarDisponibilidade.addEventListener('click', copiarTabelaDisponibilidadeCliente);
    }
    atualizarBotaoCopiarDisponibilidade();

    const modalTreinamento = document.getElementById('modalTreinamento');
    if (modalTreinamento) {
        modalTreinamento.addEventListener('shown.bs.modal', function() {
            carregarDisponibilidadeGoogle();
        });
    }

    // Kanban removido - centralizado no dashboard principal

    carregarDisponibilidadeGoogle();

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
        const pad = (n) => String(n).padStart(2, '0');
        document.getElementById('data_treinamento').value = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;

        // Nota: Não resetamos disponibilidadeFoiCarregada aqui para manter o botão "Copiar Horas" da listagem ativo
    });

    // GSAP Entrance
    if (typeof gsap !== 'undefined') {
        gsap.to(".gsap-reveal", { duration: 0.6, opacity: 1, y: 0, stagger: 0.1, ease: "power2.out" });
    } else {
        // Fallback caso GSAP falhe
        document.querySelectorAll('.gsap-reveal').forEach(el => el.style.opacity = '1');
    }

    // Lógica para Histórico do Cliente (Camada extra de CRM)
    let currentClientIdForObs = null;
    let currentClientNameForObs = null;

    document.querySelectorAll('.btn-history-client').forEach(btn => {
        btn.addEventListener('click', function() {
            currentClientIdForObs = this.dataset.id;
            currentClientNameForObs = this.dataset.nome;
            abrirModalHistorico(currentClientIdForObs, currentClientNameForObs);
        });
    });

    function abrirModalHistorico(id, nome) {
        document.getElementById('hist_cliente_nome').innerText = nome;
        const container = document.getElementById('hist_obs_container');
        container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Carregando históricos...</p></div>';
        
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHistoricoCliente')).show();

        fetch(`treinamentos.php?get_observations=1&id_cliente=${id}`)
            .then(r => r.json())
            .then(data => {
                if (data.length === 0) {
                    container.innerHTML = '<div class="text-center py-5 opacity-50"><i class="bi bi-journal-x display-4 text-muted"></i><p class="mt-2">Nenhum registro encontrado para este cliente.</p></div>';
                } else {
                    container.innerHTML = '';
                    data.forEach(obs => {
                        const t_color = (obs.tipo == 'PROBLEMA') ? '#ef4444' : ((obs.tipo == 'AJUSTE') ? '#f59e0b' : ((obs.tipo == 'MELHORIA') ? '#10b981' : 'var(--primary)'));
                        const card = `
                            <div class="p-4 mb-3 rounded-4 border" style="background: var(--bg-body); transition: all 0.2s ease;">
                                <div class="d-flex justify-content-between mb-3 align-items-center">
                                    <span class="badge" style="background: ${t_color}15; color: ${t_color}; font-size: 0.65rem; padding: 0.4rem 0.8rem; border-radius: 8px;">${obs.tipo}</span>
                                    <span class="text-muted small" style="font-size: 0.7rem;">${new Date(obs.data_observacao).toLocaleString('pt-BR')}</span>
                                </div>
                                <div class="fw-800 mb-2 title-main" style="font-size: 0.95rem;">${obs.titulo}</div>
                                <div class="text-muted small mb-3" style="line-height: 1.6;">${obs.conteudo.replace(/\n/g, '<br>')}</div>
                                <div class="d-flex justify-content-end align-items-center pt-2 border-top">
                                    <div class="text-muted" style="font-size: 0.65rem;"><i class="bi bi-person me-1"></i>${obs.registrado_por || 'Sistema'}</div>
                                </div>
                            </div>
                        `;
                        container.insertAdjacentHTML('beforeend', card);
                    });
                }
            })
            .catch(err => {
                container.innerHTML = '<div class="alert alert-danger">Erro ao carregar dados.</div>';
            });
    }

    function abrirModalNovaObs() {
        if (!currentClientIdForObs) return;
        document.getElementById('nova_obs_id_cliente').value = currentClientIdForObs;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNovaObsCliente')).show();
    }
</script>

<?php include 'footer.php'; ?>
