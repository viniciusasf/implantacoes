<?php
require_once 'config.php';
header('Content-Type: application/json');

$ids_json = $_POST['ids'] ?? '';
$ids = json_decode($ids_json, true);

if (!is_array($ids) || empty($ids)) {
    echo json_encode(['sucesso' => false, 'erro' => 'Nenhum ID fornecido.']);
    exit;
}

try {
    $in = str_repeat('?,', count($ids) - 1) . '?';
    $stmt = $pdo->prepare("UPDATE chamados_espelho_local SET notificado = 1 WHERE id_chamado_api IN ($in)");
    $stmt->execute($ids);
    
    echo json_encode(['sucesso' => true]);
} catch (PDOException $e) {
    echo json_encode(['sucesso' => false, 'erro' => 'Erro BD: ' . $e->getMessage()]);
}
