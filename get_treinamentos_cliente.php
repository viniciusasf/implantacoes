<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

$idClienteApi = trim((string) ($_GET['id_cliente_api'] ?? ''));
$idClienteSolicitado = trim((string) ($_GET['id_cliente'] ?? ''));
$idClienteLocal = null;

if ($idClienteApi !== '') {
    $stmtCliente = $pdo->prepare('SELECT id_cliente FROM clientes WHERE ID_CLIENTE_API = ? LIMIT 1');
    $stmtCliente->execute([$idClienteApi]);
    $idClienteLocal = $stmtCliente->fetchColumn();
} elseif ($idClienteSolicitado !== '') {
    $stmtCliente = $pdo->prepare('SELECT id_cliente FROM clientes WHERE id_cliente = ? LIMIT 1');
    $stmtCliente->execute([$idClienteSolicitado]);
    $idClienteLocal = $stmtCliente->fetchColumn();

    if ($idClienteLocal === false) {
        $stmtCliente = $pdo->prepare('SELECT id_cliente FROM clientes WHERE ID_CLIENTE_API = ? LIMIT 1');
        $stmtCliente->execute([$idClienteSolicitado]);
        $idClienteLocal = $stmtCliente->fetchColumn();
    }
}

if ($idClienteLocal !== false && $idClienteLocal !== null) {
    $stmt = $pdo->prepare("SELECT tema, data_treinamento, status, data_treinamento_encerrado 
                           FROM treinamentos 
                           WHERE id_cliente = ? 
                           ORDER BY data_treinamento DESC");
    $stmt->execute([$idClienteLocal]);
    $treinamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $treinamentos = [];
}

echo json_encode($treinamentos, JSON_UNESCAPED_UNICODE);
?>
