<?php
require "config.php"; 
$where_conditions = ["(c.data_fim IS NULL OR CAST(c.data_fim AS CHAR) = '0000-00-00' OR CAST(c.data_fim AS CHAR) = '')"];
$where_conditions[] = "UPPER(t.status) = 'PENDENTE'";
$where_clause = "WHERE " . implode(" AND ", $where_conditions);
$sql_base = "
    SELECT t.*, c.fantasia as cliente_nome, co.nome as contato_nome
    FROM treinamentos t
    LEFT JOIN clientes c ON t.id_cliente = c.id_cliente
    LEFT JOIN contatos co ON t.id_contato = co.id_contato
";
$sql_base .= " " . $where_clause;
$sql_base .= " ORDER BY c.fantasia ASC LIMIT 0, 20";

$stmt = $pdo->prepare($sql_base);
$stmt->execute([]);
$res = $stmt->fetchAll();
echo "Records found: " . count($res) . "\n";
?>
