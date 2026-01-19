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
        echo json_encode(['success' => false, 'message' => 'Erro Fatal PHP: ' . $error['message']]);
    }
});

try {
    // Verificação de Arquivos Essenciais
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        returnError("Pasta VENDOR não encontrada.");
    }
    require_once $autoload;

    if (!file_exists('config.php')) {
        returnError("Arquivo config.php não encontrado.");
    }
    require_once 'config.php';

    if (!file_exists('credentials.json')) {
        returnError("Arquivo credentials.json não encontrado.");
    }

    // Verificar se foi passado o ID do treinamento
    if (!isset($_GET['id_treinamento'])) {
        returnError("ID do treinamento não fornecido.");
    }

    $id_treinamento = $_GET['id_treinamento'];

    // Buscar dados do treinamento incluindo o event_id do Google
    $stmt = $pdo->prepare("SELECT google_event_id FROM treinamentos WHERE id_treinamento = ?");
    $stmt->execute([$id_treinamento]);
    $treinamento = $stmt->fetch();

    if (!$treinamento) {
        returnError("Treinamento não encontrado no banco.");
    }

    // Verificar se existe um event_id vinculado
    if (empty($treinamento['google_event_id'])) {
        returnError("Este treinamento não possui evento vinculado no Google Agenda.");
    }

    // Configurar Google Client
    $client = new \Google\Client();
    $client->setApplicationName('Implantacao Pro');
    $client->setScopes(\Google\Service\Calendar::CALENDAR_EVENTS);
    $client->setAuthConfig('credentials.json');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

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
            returnError("Autenticação necessária. Por favor, sincronize um evento primeiro.");
        }
    }

    $service = new \Google\Service\Calendar($client);

    // Deletar o evento do Google Agenda
    try {
        $service->events->delete($calendarId, $treinamento['google_event_id']);
        
        // Limpar o google_event_id e google_event_link do banco de dados
        $stmt = $pdo->prepare("UPDATE treinamentos SET google_event_id = NULL, google_event_link = NULL WHERE id_treinamento = ?");
        $stmt->execute([$id_treinamento]);
        
        echo json_encode(['success' => true, 'message' => 'Evento removido do Google Agenda com sucesso!']);
    } catch (\Google\Service\Exception $e) {
        // Evento pode não existir mais no Google
        if ($e->getCode() == 404) {
            // Limpa o ID e o LINK do banco mesmo assim
            $stmt = $pdo->prepare("UPDATE treinamentos SET google_event_id = NULL, google_event_link = NULL WHERE id_treinamento = ?");
            $stmt->execute([$id_treinamento]);
            echo json_encode(['success' => true, 'message' => 'Evento já havia sido removido do Google Agenda.']);
        } else {
            returnError("Erro ao deletar evento: " . $e->getMessage());
        }
    }

} catch (Exception $e) {
    returnError("Erro de Execução: " . $e->getMessage());
}