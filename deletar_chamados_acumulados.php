<?php
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = isset($_POST['ids']) ? json_decode($_POST['ids'], true) : [];

    if (empty($ids) || !is_array($ids)) {
        echo json_encode(['sucesso' => false, 'erro' => 'Nenhum ID informado para deleção.']);
        exit;
    }

    try {
        $in  = str_repeat('?,', count($ids) - 1) . '?';
        $sql = "DELETE FROM chamados_espelho_local WHERE id_chamado_api IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        
        echo json_encode(['sucesso' => true]);
    } catch (PDOException $e) {
        echo json_encode(['sucesso' => false, 'erro' => 'Erro ao deletar: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['sucesso' => false, 'erro' => 'Método inválido.']);
}
