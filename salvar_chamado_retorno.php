<?php
require_once 'config.php';

header('Content-Type: application/json');

$id_chamado = $_POST['id_chamado'] ?? null;
$acao = $_POST['acao'] ?? null;

if (!$id_chamado || !is_numeric($id_chamado)) {
    echo json_encode(['sucesso' => false, 'erro' => 'ID do chamado inválido.']);
    exit;
}

if (!in_array($acao, ['dar_baixa', 'remover_baixa'])) {
    echo json_encode(['sucesso' => false, 'erro' => 'Ação inválida.']);
    exit;
}

try {
    if ($acao === 'dar_baixa') {
        $stmt = $pdo->prepare("INSERT IGNORE INTO chamados_retornos (id_chamado, data_retorno) VALUES (:id_chamado, NOW())");
        $stmt->execute([':id_chamado' => $id_chamado]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM chamados_retornos WHERE id_chamado = :id_chamado");
        $stmt->execute([':id_chamado' => $id_chamado]);
    }
    
    echo json_encode(['sucesso' => true]);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao salvar no banco de dados.']);
}
