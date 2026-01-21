<?php
require_once 'config.php';

if (isset($_GET['id_cliente'])) {
    $id_cliente = $_GET['id_cliente'];

    // O segredo está aqui: filtrar pelo id_cliente que vem do JavaScript
    $stmt = $pdo->prepare("SELECT id_contato, nome FROM contatos WHERE id_cliente = ? ORDER BY nome ASC");
    $stmt->execute([$id_cliente]);
    $contatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($contatos);
    exit;
}
echo json_encode([]);