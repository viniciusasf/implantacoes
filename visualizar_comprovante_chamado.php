<?php
// Exibe o comprovante HTML armazenado no Google Drive diretamente no navegador.
require_once 'config.php';
require_once 'vendor/autoload.php';
require_once 'google_drive_functions.php';

if (empty($_GET['id'])) {
    http_response_code(400);
    exit('ID inválido.');
}

try {
    $service = driveGetService();
    $file = $service->files->get($_GET['id'], ['alt' => 'media']);

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="comprovante_chamado.html"');
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    echo $file->getBody();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Erro ao abrir comprovante: ' . $e->getMessage());
}
