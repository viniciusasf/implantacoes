<?php
require_once 'config.php';

try {
    // Tabela Snapshot
    $pdo->exec("CREATE TABLE IF NOT EXISTS monitor_chamados_snapshot (
        codigo_chamado INT NOT NULL PRIMARY KEY,
        cliente VARCHAR(255) NULL,
        tipo VARCHAR(255) NULL,
        status_atual VARCHAR(100) NULL,
        data_chamado VARCHAR(20) NULL,
        responsavel VARCHAR(255) NULL,
        previsao VARCHAR(20) NULL,
        responsavel_tecnico VARCHAR(255) NULL,
        descricao LONGTEXT NULL,
        retorno LONGTEXT NULL,
        hash_linha CHAR(64) NOT NULL,
        ultima_leitura DATETIME NOT NULL,
        ultima_mudanca DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tabela Histórico
    $pdo->exec("CREATE TABLE IF NOT EXISTS monitor_chamados_historico (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        codigo_chamado INT NOT NULL,
        evento VARCHAR(30) NOT NULL,
        status_anterior VARCHAR(100) NULL,
        status_novo VARCHAR(100) NULL,
        previsao_anterior VARCHAR(20) NULL,
        previsao_nova VARCHAR(20) NULL,
        resp_tecnico_anterior VARCHAR(255) NULL,
        resp_tecnico_novo VARCHAR(255) NULL,
        payload_anterior LONGTEXT NULL,
        payload_novo LONGTEXT NULL,
        data_evento DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "Tabelas criadas com sucesso!\n";
} catch (PDOException $e) {
    die("Erro ao criar tabelas: " . $e->getMessage() . "\n");
}
?>
