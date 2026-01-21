<?php
/**
 * Arquivo: encerrar_treinamento.php
 * Função: Encerrar um treinamento específico
 * Autor: Sistema de Gestão de Clientes
 */

require_once 'config.php';

header('Content-Type: application/json');

// Verificar se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido. Use POST.'
    ]);
    exit;
}

// Obter dados do POST
$input = json_decode(file_get_contents('php://input'), true);
$id_treinamento = isset($input['id_treinamento']) ? intval($input['id_treinamento']) : 0;
$observacao_encerramento = isset($input['observacao']) ? trim($input['observacao']) : '';

// Validar ID do treinamento
if ($id_treinamento <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'ID do treinamento inválido.'
    ]);
    exit;
}

try {
    // Verificar se o treinamento existe e não está encerrado
    $stmt = $pdo->prepare("
        SELECT id_treinamento, status, tema, id_cliente 
        FROM treinamentos 
        WHERE id_treinamento = ?
    ");
    $stmt->execute([$id_treinamento]);
    $treinamento = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$treinamento) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Treinamento não encontrado.'
        ]);
        exit;
    }
    
    // Verificar se já está encerrado
    if ($treinamento['status'] === 'RESOLVIDO') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Este treinamento já foi encerrado anteriormente.'
        ]);
        exit;
    }
    
    // Encerrar o treinamento
    $stmt = $pdo->prepare("
        UPDATE treinamentos 
        SET status = 'RESOLVIDO',
            data_treinamento_encerrado = NOW(),
            observacao_encerramento = ?
        WHERE id_treinamento = ?
    ");
    
    $stmt->execute([$observacao_encerramento, $id_treinamento]);
    
    // Buscar dados atualizados
    $stmt = $pdo->prepare("
        SELECT data_treinamento_encerrado 
        FROM treinamentos 
        WHERE id_treinamento = ?
    ");
    $stmt->execute([$id_treinamento]);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Resposta de sucesso
    echo json_encode([
        'success' => true,
        'message' => 'Treinamento encerrado com sucesso!',
        'data' => [
            'id_treinamento' => $id_treinamento,
            'tema' => $treinamento['tema'],
            'data_encerramento' => $updated['data_treinamento_encerrado'],
            'observacao' => $observacao_encerramento
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao encerrar treinamento: ' . $e->getMessage()
    ]);
}