<?php
// Endpoint para servir PDF do Google Drive com headers corretos para visualização
require_once 'config.php';
require_once 'vendor/autoload.php';
require_once 'google_drive_functions.php';

if (empty($_GET['id'])) {
    http_response_code(400);
    die('ID inválido');
}

$fileId = $_GET['id'];

try {
    $service = driveGetService();
    
    // Obtém arquivo do Google Drive
    $file = $service->files->get($fileId, ['alt' => 'media']);
    $content = $file->getBody();
    
    // Headers para VISUALIZAR no navegador (não fazer download)
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="comprovante_chamado.pdf"');
    header('Cache-Control: public, max-age=3600');
    header('Pragma: public');
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 3600));
    
    // Força a visualização, não o download
    header('X-Content-Type-Options: nosniff');
    
    echo $content;
    exit;
    
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('Erro ao baixar PDF: ' . $e->getMessage());
}
