<?php
require_once 'config.php';

header('Content-Type: application/json');

function garantirDescricaoLongText(PDO $pdo): void
{
    try {
        $col = $pdo->query("SHOW COLUMNS FROM chamados_espelho_local LIKE 'descricao_problema'")->fetch(PDO::FETCH_ASSOC);

        if (!$col) {
            $pdo->exec("ALTER TABLE chamados_espelho_local ADD COLUMN descricao_problema LONGTEXT NULL AFTER tipo_acompanhamento");
            return;
        }

        $tipo = strtoupper((string) $col['Type']);
        $temTextoLongo = preg_match('/(TEXT|MEDIUMTEXT|LONGTEXT)/i', $tipo) === 1;

        if (!$temTextoLongo) {
            $pdo->exec("ALTER TABLE chamados_espelho_local MODIFY descricao_problema LONGTEXT NULL");
        }
    } catch (Throwable $e) {
        // Ignora falha de schema para preservar o fluxo principal.
    }
}

$json = file_get_contents('php://input');
$dados = json_decode($json, true);

if (!$dados || empty($dados['id'])) {
    echo json_encode(['sucesso' => false, 'erro' => 'Dados inválidos.']);
    exit;
}

try {
    garantirDescricaoLongText($pdo);

    // Insere ou Atualiza o chamado no banco local
    $stmt = $pdo->prepare("
        INSERT INTO chamados_espelho_local 
        (id_chamado_api, id_cliente_api, nome_fantasia, status_chamado, tipo_acompanhamento, descricao_problema, data_prev_retorno, responsavel, data_importacao, notificado) 
        VALUES 
        (:id, :id_cliente, :fantasia, :status, :tipo, :descricao, :dataprev, :responsavel, NOW(), 0)
        ON DUPLICATE KEY UPDATE 
        id_cliente_api = VALUES(id_cliente_api),
        status_chamado = VALUES(status_chamado),
        tipo_acompanhamento = VALUES(tipo_acompanhamento),
        descricao_problema = IF(CHAR_LENGTH(VALUES(descricao_problema)) >= CHAR_LENGTH(descricao_problema), VALUES(descricao_problema), descricao_problema),
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
        ':descricao' => (string) ($dados['descricao'] ?? ''),
        ':dataprev' => (!empty($dados['dataprev'])) ? $dados['dataprev'] : null,
        ':responsavel' => $dados['responsavel'] ?? ''
    ]);
    
    echo json_encode(['sucesso' => true]);
} catch (PDOException $e) {
    echo json_encode(['sucesso' => false, 'erro' => 'Erro BD: ' . $e->getMessage()]);
}
