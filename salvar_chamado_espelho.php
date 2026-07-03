<?php
require_once 'config.php';

header('Content-Type: application/json');

$json = file_get_contents('php://input');
$dados = json_decode($json, true);

if (!$dados || empty($dados['id'])) {
    echo json_encode(['sucesso' => false, 'erro' => 'Dados inválidos.']);
    exit;
}

try {
    // Insere ou Atualiza o chamado no banco local
    $stmt = $pdo->prepare("
        INSERT INTO chamados_espelho_local 
        (id_chamado_api, id_cliente_api, nome_fantasia, status_chamado, tipo_acompanhamento, descricao_problema, data_prev_retorno, responsavel, data_importacao, notificado) 
        VALUES 
        (:id, :id_cliente, :fantasia, :status, :tipo, :descricao, :dataprev, :responsavel, NOW(), 0)
        ON DUPLICATE KEY UPDATE 
        status_chamado = VALUES(status_chamado),
        tipo_acompanhamento = VALUES(tipo_acompanhamento),
        descricao_problema = VALUES(descricao_problema),
        data_prev_retorno = VALUES(data_prev_retorno),
        responsavel = VALUES(responsavel),
        data_importacao = NOW()
    ");

    $stmt->execute([
        ':id' => $dados['id'],
        ':id_cliente' => $dados['id_cliente'] ?? null,
        ':fantasia' => $dados['fantasia'] ?? '',
        ':status' => $dados['status'] ?? '',
        ':tipo' => $dados['tipo'] ?? '',
        ':descricao' => $dados['descricao'] ?? '',
        ':dataprev' => (!empty($dados['dataprev'])) ? $dados['dataprev'] : null,
        ':responsavel' => $dados['responsavel'] ?? ''
    ]);
    
    echo json_encode(['sucesso' => true]);
} catch (PDOException $e) {
    echo json_encode(['sucesso' => false, 'erro' => 'Erro BD: ' . $e->getMessage()]);
}
