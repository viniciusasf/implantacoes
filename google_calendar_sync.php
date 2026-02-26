<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once __DIR__ . '/vendor/autoload.php';
require_once 'config.php';

function returnResponse($success, $message, $data = [])
{
    die(json_encode(array_merge(['success' => $success, 'message' => $message], $data)));
}

function obterColunaEmailContato(PDO $pdo)
{
    $candidatas = ['email', 'email_contato', 'e_mail'];
    $stmt = $pdo->query("SHOW COLUMNS FROM contatos");
    $colunas = $stmt->fetchAll(PDO::FETCH_COLUMN);
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

try {
    $client = new Google\Client();
    $client->setAuthConfig('credentials.json');
    $client->addScope(Google\Service\Calendar::CALENDAR);
    $client->setAccessType('offline'); // Essencial para receber o Refresh Token
    $client->setApprovalPrompt('force'); // Força o prompt de consentimento para garantir novo token
    $client->setPrompt('consent');

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $redirectUri = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
    $client->setRedirectUri($redirectUri);

    if (isset($_GET['id_treinamento'])) {
        $_SESSION['sync_training_id'] = $_GET['id_treinamento'];
    }

    if (isset($_GET['code'])) {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        file_put_contents('token.json', json_encode($token));
        header("Location: " . $redirectUri);
        exit;
    }

    if (file_exists('token.json')) {
        $client->setAccessToken(json_decode(file_get_contents('token.json'), true));
    }

    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents('token.json', json_encode($client->getAccessToken()));
        } else {
            die(json_encode(['success' => false, 'auth_url' => $client->createAuthUrl()]));
        }
    }

    $id_treinamento = $_SESSION['sync_training_id'] ?? null;
    if (!$id_treinamento)
        returnResponse(false, "ID do treinamento não encontrado na sessão.");

    $stmt = $pdo->prepare("SELECT t.*, c.fantasia as cliente_nome, co.nome as contato_nome, co.telefone_ddd as contato_tel
                           FROM treinamentos t
                           LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
                           LEFT JOIN contatos co ON t.id_contato = co.id_contato
                           WHERE t.id_treinamento = ?");
    $stmt->execute([$id_treinamento]);
    $treinamento = $stmt->fetch();

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

    $startDate = new DateTime($treinamento['data_treinamento'], new DateTimeZone('America/Sao_Paulo'));
    $endDate = clone $startDate;
    $endDate->modify('+60 minutes');

    $eventData = [
        'summary' => '#' . $treinamento['id_treinamento'] . ' Treinamento: ' . $treinamento['cliente_nome'],
        'description' => "Tema: " . $treinamento['tema'] . "\nConvidados: " . $descricaoConvidados,
        'start' => ['dateTime' => $startDate->format('Y-m-d\TH:i:s'), 'timeZone' => 'America/Sao_Paulo'],
        'end' => ['dateTime' => $endDate->format('Y-m-d\TH:i:s'), 'timeZone' => 'America/Sao_Paulo'],
        'conferenceData' => [
            'createRequest' => [
                'requestId' => 'treino-' . $treinamento['id_treinamento'] . '-' . time(),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet']
            ]
        ],
        'reminders' => ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 5]]]
    ];

    if (!empty($convidados)) {
        $eventData['attendees'] = $convidados;
    }

    $service = new Google\Service\Calendar($client);
    $event = new Google\Service\Calendar\Event($eventData);
    $googleEventIdExistente = trim((string)($treinamento['google_event_id'] ?? ''));

    if ($googleEventIdExistente !== '') {
        $eventDataUpdate = $eventData;
        unset($eventDataUpdate['conferenceData']);
        $eventUpdate = new Google\Service\Calendar\Event($eventDataUpdate);

        try {
            $createdEvent = $service->events->patch('primary', $googleEventIdExistente, $eventUpdate, ['sendUpdates' => 'all']);
        } catch (Exception $e) {
            $createdEvent = $service->events->insert('primary', $event, ['conferenceDataVersion' => 1, 'sendUpdates' => 'all']);
        }
    } else {
        $createdEvent = $service->events->insert('primary', $event, ['conferenceDataVersion' => 1, 'sendUpdates' => 'all']);
    }

    // CAPTURA O ID GERADO PELO GOOGLE
    $google_id = $createdEvent->getId();

    // Captura o link de convite (Meet). Fallback: link do evento.
    $google_link = $createdEvent->getHangoutLink();
    if (empty($google_link)) {
        $conferenceData = $createdEvent->getConferenceData();
        if ($conferenceData && $conferenceData->getEntryPoints()) {
            foreach ($conferenceData->getEntryPoints() as $entryPoint) {
                if ($entryPoint->getEntryPointType() === 'video' && !empty($entryPoint->getUri())) {
                    $google_link = $entryPoint->getUri();
                    break;
                }
            }
        }
    }
    if (empty($google_link)) {
        $google_link = $createdEvent->htmlLink;
    }

    // Se já existir um link curto calendar.app.google salvo manualmente, preserva.
    $link_existente = trim((string)($treinamento['google_event_link'] ?? ''));
    if ($link_existente !== '' && strpos($link_existente, 'calendar.app.google/') !== false) {
        $google_link = $link_existente;
    }

    // SALVA NO BANCO
    $stmtUpdate = $pdo->prepare("UPDATE treinamentos SET google_event_id = ?, google_event_link = ? WHERE id_treinamento = ?");
    $stmtUpdate->execute([$google_id, $google_link, $id_treinamento]);

    unset($_SESSION['sync_training_id']);

    returnResponse(true, "Operação realizada com sucesso! ID Google: " . $google_id, ['google_id' => $google_id]);
} catch (Exception $e) {
    returnResponse(false, "Erro: " . $e->getMessage());
}
