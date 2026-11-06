<?php
/**
 * criar_pasta_drive.php
 * Endpoint AJAX: cria uma pasta no Google Drive com o nome do SERIAL do cliente
 * e retorna o link webViewLink da pasta criada.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_drive_functions.php';

header('Content-Type: application/json; charset=utf-8');

// Aceita apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido.']);
    exit;
}

$serial    = trim($_POST['serial']    ?? '');
$id_cliente = intval($_POST['id_cliente'] ?? 0);

if ($serial === '') {
    echo json_encode(['sucesso' => false, 'erro' => 'SERIAL não informado.']);
    exit;
}

try {
    $service = driveGetService();

    // 1. Usa a pasta pai "CLIENTES" pelo ID fixo fornecido (evita duplicidade de pastas)
    $pastaClientesId = '1ruGWgy-v_0w0CF1f3CT8zbHnJc_NkJOa';

    // 2. Cria (ou encontra) a subpasta com o nome do SERIAL dentro de "CLIENTES"
    $folderId = driveFindOrCreateFolder($service, $serial, $pastaClientesId);

    // 3. Aplica permissão: "Qualquer pessoa com o link" como Editor (writer)
    try {
        $permission = new Google\Service\Drive\Permission([
            'type' => 'anyone',
            'role' => 'writer',
        ]);
        $service->permissions->create($folderId, $permission, ['fields' => 'id']);
    } catch (Throwable $permEx) {
        // Ignora erro de permissão duplicada, lança os demais
        $msg = strtolower($permEx->getMessage());
        if (strpos($msg, 'duplicate') === false && strpos($msg, 'already exists') === false) {
            throw $permEx;
        }
    }

    // Obtém o link webViewLink da pasta
    $folderFile = $service->files->get($folderId, ['fields' => 'id,webViewLink,name']);
    $link = $folderFile->getWebViewLink() ?? 'https://drive.google.com/drive/folders/' . $folderId;

    // Se tiver id_cliente, salva o link no campo Link Vídeos Passo a Passo
    if ($id_cliente > 0) {
        $stmt = $pdo->prepare("UPDATE clientes SET link_videos = ? WHERE id_cliente = ?");
        $stmt->execute([$link, $id_cliente]);
    }

    echo json_encode([
        'sucesso'   => true,
        'folder_id' => $folderId,
        'link'      => $link,
    ]);

} catch (RuntimeException $e) {
    if ($e->getMessage() === 'NO_DRIVE_TOKEN') {
        echo json_encode(['sucesso' => false, 'erro' => 'Token do Google Drive não configurado. Acesse google_drive_auth.php para autenticar.']);
    } else {
        echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
    }
} catch (Throwable $e) {
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao criar pasta: ' . $e->getMessage()]);
}
