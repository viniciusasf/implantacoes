<?php
require_once 'config.php';

$sql = "
CREATE TABLE IF NOT EXISTS chamados_espelho_local (
    id_chamado_api INT PRIMARY KEY,
    id_cliente_api INT,
    nome_fantasia VARCHAR(255),
    status_chamado VARCHAR(100),
    tipo_acompanhamento VARCHAR(150),
    descricao_problema TEXT,
    data_prev_retorno DATETIME,
    responsavel VARCHAR(100),
    data_importacao DATETIME DEFAULT CURRENT_TIMESTAMP,
    notificado TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $pdo->exec($sql);
    echo "Tabela chamados_espelho_local criada com sucesso.";
} catch (PDOException $e) {
    echo "Erro ao criar tabela: " . $e->getMessage();
}
