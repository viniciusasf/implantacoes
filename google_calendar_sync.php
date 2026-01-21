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

try {
    $client = new Google\Client();
    $client->setAuthConfig('credentials.json');
    $client->addScope(Google\Service\Calendar::CALENDAR);
    $client->setAccessType('offline');
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

    $startDate = new DateTime($treinamento['data_treinamento'], new DateTimeZone('America/Sao_Paulo'));
    $endDate = clone $startDate;
    $endDate->modify('+60 minutes');

    $service = new Google\Service\Calendar($client);
    $event = new Google\Service\Calendar\Event([
        'summary' => 'Treinamento: ' . $treinamento['cliente_nome'],
        'description' => "Tema: " . $treinamento['tema'] . "\nContato: " . $treinamento['contato_nome'],
        'start' => ['dateTime' => $startDate->format(DateTime::RFC3339), 'timeZone' => 'America/Sao_Paulo'],
        'end' => ['dateTime' => $endDate->format(DateTime::RFC3339), 'timeZone' => 'America/Sao_Paulo'],
        'reminders' => ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 5]]]
    ]);

    $createdEvent = $service->events->insert('primary', $event);

    // CAPTURA O ID GERADO PELO GOOGLE
    $google_id = $createdEvent->getId();

    // SALVA NO BANCO
    $stmtUpdate = $pdo->prepare("UPDATE treinamentos SET google_event_id = ?, google_event_link = ? WHERE id_treinamento = ?");
    $stmtUpdate->execute([$google_id, $createdEvent->htmlLink, $id_treinamento]);

    unset($_SESSION['sync_training_id']);

    returnResponse(true, "Operação realizada com sucesso! ID Google: " . $google_id, ['google_id' => $google_id]);

} catch (Exception $e) {
    returnResponse(false, "Erro: " . $e->getMessage());
}