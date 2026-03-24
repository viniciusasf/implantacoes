<?php
require 'config.php';

try {
    $pdo->beginTransaction();

    // 1. Alterar a coluna para aceitar novos Enums
    $pdo->exec("ALTER TABLE clientes MODIFY COLUMN status ENUM('EM ANDAMENTO', 'CONCLUIDA', 'CANCELADA') COLLATE utf8mb4_unicode_ci DEFAULT 'EM ANDAMENTO'");
    
    echo "Coluna status alterada com sucesso.\n";

    // 2. Atualizar status = CANCELADA onde data_fim não é nula e observacao tem CANCELADO/CANCELADA
    $stmt1 = $pdo->exec("UPDATE clientes SET status = 'CANCELADA' WHERE data_fim IS NOT NULL AND data_fim != '0000-00-00' AND (observacao LIKE '%CANCELADO%' OR observacao LIKE '%CANCELADA%')");
    echo "Registros cancelados atualizados: $stmt1\n";

    // 3. Atualizar status = CONCLUIDA onde data_fim não é nula e NÃO contém a string CANCELAD
    $stmt2 = $pdo->exec("UPDATE clientes SET status = 'CONCLUIDA' WHERE data_fim IS NOT NULL AND data_fim != '0000-00-00' AND status != 'CANCELADA'");
    echo "Registros concluídos atualizados: $stmt2\n";

    // 4. Atualizar status = EM ANDAMENTO para os demais
    $stmt3 = $pdo->exec("UPDATE clientes SET status = 'EM ANDAMENTO' WHERE data_fim IS NULL OR data_fim = '0000-00-00'");
    echo "Registros em andamento atualizados: $stmt3\n";

    $pdo->commit();
    echo "Migração completada com sucesso.\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Erro na migração: " . $e->getMessage();
}

?>
