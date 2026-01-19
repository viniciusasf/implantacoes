<?php
// Configuração de Erros
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

function returnError($msg)
{
    die(json_encode(['success' => false, 'message' => $msg]));
}

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        echo json_encode(['success' => false, 'message' => 'Erro Fatal PHP: ' . $error['message'] . ' em ' . $error['file'] . ' linha ' . $error['line']]);
    }
});

try {
    // Verificação de Arquivos Essenciais
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        returnError("Pasta VENDOR não encontrada. Verifique se ela está na raiz do sistema.");
    }
    require_once $autoload;

    if (!file_exists('config.php')) {
        returnError("Arquivo config.php não encontrado.");
    }
    require_once 'config.php';

    if (!file_exists('credentials.json')) {
        returnError("Arquivo credentials.json não encontrado na raiz.");
    }

    // Lógica da API do Google
    if (!isset($_GET['id_treinamento'])) {
        returnError("ID do treinamento não fornecido.");
    }

    $id_treinamento = $_GET['id_treinamento'];

    // Buscar dados do treinamento
    $stmt = $pdo->prepare("SELECT t.*, c.fantasia as cliente_nome FROM treinamentos t JOIN clientes c ON t.id_cliente = c.id_cliente WHERE t.id_treinamento = ?");
    $stmt->execute([$id_treinamento]);
    $treinamento = $stmt->fetch();

    if (!$treinamento)
        returnError("Treinamento não encontrado no banco.");
    if (!$treinamento['data_treinamento'])
        returnError("Treinamento sem data agendada.");

    // Verificar se já existe um evento criado
    if (!empty($treinamento['google_event_id'])) {
        echo json_encode(['success' => true, 'message' => 'Este treinamento já está sincronizado com o Google Agenda.', 'already_synced' => true]);
        exit;
    }

    // Configurar Google Client
    $client = new \Google\Client();
    $client->setApplicationName('Implantacao Pro');
    $client->setScopes(\Google\Service\Calendar::CALENDAR_EVENTS);
    $client->setAuthConfig('credentials.json');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    $client->setRedirectUri('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);

    $calendarId = 'gestaoprovideossuporte@gmail.com';
    $tokenPath = 'token.json';

    if (file_exists($tokenPath)) {
        $client->setAccessToken(json_decode(file_get_contents($tokenPath), true));
    }

    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        } else {
            returnError("Autenticação necessária. URL: " . $client->createAuthUrl());
        }
    }

    $service = new \Google\Service\Calendar($client);

    // Define o fuso horário
    $timezone = new DateTimeZone('America/Sao_Paulo');
    $startDate = new DateTime($treinamento['data_treinamento'], $timezone);
    $start = $startDate->format(DateTime::RFC3339);

    $endDate = clone $startDate;
    $endDate->modify('+1 hour');
    $end = $endDate->format(DateTime::RFC3339);

    $event = new \Google\Service\Calendar\Event([
        'summary' => 'Treinamento: ' . $treinamento['cliente_nome'],
        'description' => 'Tema: ' . $treinamento['tema'] . "\nContato: " . $treinamento['nome_contato'] . "\nTelefone: " . $treinamento['telefone_contato'],
        'start' => [
            'dateTime' => $start,
            'timeZone' => 'America/Sao_Paulo'
        ],
        'end' => [
            'dateTime' => $end,
            'timeZone' => 'America/Sao_Paulo'
        ],
        'reminders' => [
            'useDefault' => false,
            'overrides' => [
                ['method' => 'popup', 'minutes' => 30],
                ['method' => 'email', 'minutes' => 60]
            ]
        ]
    ]);

    // Inserir evento no Google Agenda
    $createdEvent = $service->events->insert($calendarId, $event);
    
    // Obter o link do evento
    $eventLink = $createdEvent->htmlLink;
    
    // Salvar o ID e o LINK do evento no banco de dados
    $stmt = $pdo->prepare("UPDATE treinamentos SET google_event_id = ?, google_event_link = ? WHERE id_treinamento = ?");
    $stmt->execute([$createdEvent->id, $eventLink, $id_treinamento]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Sincronizado com sucesso!',
        'event_id' => $createdEvent->id,
        'event_link' => $eventLink
    ]);

} catch (Exception $e) {
    returnError("Erro de Execução: " . $e->getMessage());
}