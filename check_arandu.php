<?php
require_once 'config.php';
$stmt = $pdo->prepare("SELECT id_cliente, fantasia, data_inicio, data_fim FROM clientes WHERE fantasia LIKE '%ARANDU%'");
$stmt->execute();
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($res, JSON_PRETTY_PRINT);
?>
