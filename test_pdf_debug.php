<?php
require_once 'config.php';

// Pega o primeiro chamado disponível na tabela
$stmt = $pdo->query('SELECT id_chamado_api FROM chamados_espelho_local LIMIT 1');
$chamado = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$chamado) {
    die('Nenhum chamado encontrado na base de dados.');
}

$idChamado = $chamado['id_chamado_api'];

// Simula uma requisição GET
$_GET['id'] = $idChamado;

header('Content-Type: text/plain; charset=utf-8');

echo "=== TESTE DE GERAÇÃO DE PDF ===\n";
echo "ID do Chamado: " . $idChamado . "\n\n";

// Simula a requisição
ob_start();
include 'gerar_pdf_chamado.php';
$response = ob_get_clean();

echo "Resposta do servidor:\n";
echo $response . "\n";

$decoded = json_decode($response, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "\n=== RESPOSTA DECODIFICADA ===\n";
    echo "Sucesso: " . ($decoded['sucesso'] ? 'SIM' : 'NÃO') . "\n";
    echo "Link: " . ($decoded['link'] ?? 'VAZIO') . "\n";
    echo "Folder Link: " . ($decoded['folder_link'] ?? 'VAZIO') . "\n";
} else {
    echo "\nErro ao decodificar JSON: " . json_last_error_msg() . "\n";
}
