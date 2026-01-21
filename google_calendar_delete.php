<?php
session_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once __DIR__ . '/vendor/autoload.php';
require_once 'config.php';

function returnResponse($success, $message) {
    die(json_encode(['success' => $success, 'message' => $message]));
}

if (!isset($_GET['id_treinamento'])) {
    returnResponse(false, "ID do treinamento não fornecido.");
}

$id_treinamento = $_GET['id_treinamento'];

try {
    // 1. Busca o google_event_id no banco de dados
    $stmt = $pdo->prepare("SELECT google_event_id FROM treinamentos WHERE id_treinamento = ?");
    $stmt->execute([$id_treinamento]);
    $treinamento = $stmt->fetch();

    if (!$treinamento || empty($treinamento['google_event_id'])) {
        returnResponse(false, "Este treinamento não possui um agendamento vinculado no Google.");
    }

    $google_event_id = $treinamento['google_event_id'];

    // 2. Configura o Cliente Google
    $client = new Google\Client();
    $client->setAuthConfig('credentials.json');
    $client->addScope(Google\Service\Calendar::CALENDAR);

    if (!file_exists('token.json')) {
        returnResponse(false, "Token não encontrado. Faça login sincronizando um evento primeiro.");
    }

    $client->setAccessToken(json_decode(file_get_contents('token.json'), true));

    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents('token.json', json_encode($client->getAccessToken()));
        } else {
            returnResponse(false, "Sessão expirada. Reautentique sincronizando um evento.");
        }
    }

    // 3. Deleta o evento na API do Google
    $service = new Google\Service\Calendar($client);
    $service->events->delete('primary', $google_event_id);

    // 4. Limpa os campos no banco de dados local
    $stmtUpdate = $pdo->prepare("UPDATE treinamentos SET google_event_id = NULL, google_event_link = NULL WHERE id_treinamento = ?");
    $stmtUpdate->execute([$id_treinamento]);

    returnResponse(true, "Agendamento removido do Google Agenda com sucesso.");

} catch (Exception $e) {
    // Se o evento já foi deletado manualmente na agenda, o Google retorna 404 ou 410.
    // Nesses casos, apenas limpamos o banco local.
    if (strpos($e->getMessage(), '404') !== false || strpos($e->getMessage(), '410') !== false) {
        $stmtUpdate = $pdo->prepare("UPDATE treinamentos SET google_event_id = NULL, google_event_link = NULL WHERE id_treinamento = ?");
        $stmtUpdate->execute([$id_treinamento]);
        returnResponse(true, "O evento não existia na agenda, os registros locais foram limpos.");
    }
    returnResponse(false, "Erro ao deletar: " . $e->getMessage());
}