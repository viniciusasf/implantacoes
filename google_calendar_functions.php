<?php
/**
 * google_calendar_functions.php
 * Funções auxiliares reutilizáveis de integração com Google Calendar/Meet.
 * Incluir este arquivo onde precisar de sincronização Google.
 * Usa function_exists() em todas as funções para evitar redeclaração
 * caso o arquivo seja incluído junto com treinamentos.php.
 */

if (!function_exists('treinamentosTemColuna')) {
    function treinamentosTemColuna(PDO $pdo, $coluna, $forceRefresh = false)
    {
        static $cache = [];
        if (!$forceRefresh && isset($cache[$coluna])) {
            return $cache[$coluna];
        }

        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM treinamentos LIKE ?");
            $stmt->execute([$coluna]);
            $cache[$coluna] = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $cache[$coluna] = false;
        }

        return $cache[$coluna];
    }
}

if (!function_exists('obterColunaEmailContato')) {
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
            $mapa[strtolower((string) $colunaReal)] = (string) $colunaReal;
        }
        foreach ($candidatas as $coluna) {
            $chave = strtolower($coluna);
            if (isset($mapa[$chave])) {
                return $mapa[$chave];
            }
        }

        return null;
    }
}

if (!function_exists('extrairEmailsValidos')) {
    function extrairEmailsValidos($valor)
    {
        $valor = trim((string) $valor);
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
}

if (!function_exists('obterConvidadosCliente')) {
    function obterConvidadosCliente(PDO $pdo, $idCliente)
    {
        $colunaEmailContato = obterColunaEmailContato($pdo);
        if (!$colunaEmailContato) {
            return [];
        }

        $stmt = $pdo->prepare("SELECT nome, `{$colunaEmailContato}` as contato_email FROM contatos WHERE id_cliente = ? ORDER BY nome ASC");
        $stmt->execute([$idCliente]);
        $contatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $convidados    = [];
        $emailsUnicos  = [];

        foreach ($contatos as $contato) {
            $nome   = trim((string) ($contato['nome'] ?? ''));
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
}

if (!function_exists('sincronizarGoogleMeetAutomatico')) {
    function sincronizarGoogleMeetAutomatico(PDO $pdo, $idTreinamento)
    {
        $authStartUrl = 'google_calendar_sync.php?id_treinamento=' . (int) $idTreinamento . '&start_auth=1';

        try {
            $autoloadPath    = __DIR__ . '/vendor/autoload.php';
            $credentialsPath = __DIR__ . '/credentials.json';

            if (!file_exists($autoloadPath) || !file_exists($credentialsPath)) {
                return ['success' => false, 'message' => 'Integração Google não configurada.'];
            }

            require_once $autoloadPath;

            // Carrega o helper de token (googleLoadTokenData, googlePersistToken, googleIsInvalidGrantError, googleTokenPath)
            if (!function_exists('googleTokenPath')) {
                require_once __DIR__ . '/google_oauth_token_helper.php';
            }

            $tokenPath = googleTokenPath();

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
                        return ['success' => false, 'message' => 'Sessão Google expirada. Faça login novamente.', 'needs_auth' => true, 'auth_start_url' => $authStartUrl];
                    }
                    throw $e;
                }

                if (isset($novoToken['error'])) {
                    if (googleIsInvalidGrantError($novoToken)) {
                        googleForgetToken($tokenPath);
                        return ['success' => false, 'message' => 'Sessão Google expirada. Faça login novamente.', 'needs_auth' => true, 'auth_start_url' => $authStartUrl];
                    }
                    return ['success' => false, 'message' => 'Falha ao renovar token Google.', 'needs_auth' => true, 'auth_start_url' => $authStartUrl];
                }

                googlePersistToken($client, $novoToken, $tokenPath);
            }

            // Busca dados do treinamento
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

            $service   = new Google\Service\Calendar($client);
            $startDate = new DateTime($treinamento['data_treinamento'], new DateTimeZone('America/Sao_Paulo'));
            $endDate   = clone $startDate;
            $endDate->modify('+60 minutes');

            $convidados = obterConvidadosCliente($pdo, (int) $treinamento['id_cliente']);
            $descricaoConvidados = 'Sem e-mail cadastrado';
            if (!empty($convidados)) {
                $itensDescricao = [];
                foreach ($convidados as $convidado) {
                    $displayName = trim((string) ($convidado['displayName'] ?? ''));
                    $email       = trim((string) ($convidado['email'] ?? ''));
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
                'summary'     => '#' . $treinamento['id_treinamento'] . ' Treinamento: ' . ($treinamento['cliente_nome'] ?? 'Cliente'),
                'description' => "Tema: " . ($treinamento['tema'] ?? '') . "\nConvidados: " . $descricaoConvidados,
                'start'       => ['dateTime' => $startDate->format('Y-m-d\TH:i:s'), 'timeZone' => 'America/Sao_Paulo'],
                'end'         => ['dateTime' => $endDate->format('Y-m-d\TH:i:s'), 'timeZone' => 'America/Sao_Paulo'],
                'conferenceData' => [
                    'createRequest' => [
                        'requestId'           => 'treino-' . $treinamento['id_treinamento'] . '-' . time(),
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet']
                    ]
                ],
                'reminders' => ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 5]]]
            ];

            if (!empty($convidados)) {
                $eventData['attendees'] = $convidados;
            }

            $event = new Google\Service\Calendar\Event($eventData);
            $googleEventIdExistente = trim((string) ($treinamento['google_event_id'] ?? ''));

            if ($googleEventIdExistente !== '') {
                // Atualiza evento existente (sem recriar o Meet)
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

            $googleEventId    = $createdEvent->getId();
            $googleMeetLink   = $createdEvent->getHangoutLink();
            $googleAgendaLink = trim((string) $createdEvent->htmlLink);

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

            // Salva no banco (suporta coluna google_agenda_link opcional)
            if (treinamentosTemColuna($pdo, 'google_agenda_link')) {
                $stmtUpdate = $pdo->prepare("UPDATE treinamentos SET google_event_id = ?, google_event_link = ?, google_agenda_link = ? WHERE id_treinamento = ?");
                $stmtUpdate->execute([$googleEventId, $googleMeetLink, $googleAgendaLink, $idTreinamento]);
            } else {
                $stmtUpdate = $pdo->prepare("UPDATE treinamentos SET google_event_id = ?, google_event_link = ? WHERE id_treinamento = ?");
                $stmtUpdate->execute([$googleEventId, $googleMeetLink, $idTreinamento]);
            }

            return [
                'success'           => true,
                'message'           => 'Google Meet criado com sucesso.',
                'google_event_link' => $googleMeetLink,
                'google_agenda_link' => $googleAgendaLink,
            ];

        } catch (Throwable $e) {
            if (function_exists('googleIsInvalidGrantError') && googleIsInvalidGrantError($e)) {
                if (function_exists('googleForgetToken') && function_exists('googleTokenPath')) {
                    googleForgetToken(googleTokenPath());
                }
                return ['success' => false, 'message' => 'Sessão Google expirada. Faça login novamente.', 'needs_auth' => true, 'auth_start_url' => $authStartUrl];
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
