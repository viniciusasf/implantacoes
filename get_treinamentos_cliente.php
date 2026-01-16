<?php
require_once 'config.php';

if (isset($_GET['id_cliente'])) {
    $id_cliente = $_GET['id_cliente'];
    
    $stmt = $pdo->prepare("SELECT tema, data_treinamento, status, data_treinamento_encerrado 
                           FROM treinamentos 
                           WHERE id_cliente = ? 
                           ORDER BY data_treinamento DESC");
    $stmt->execute([$id_cliente]);
    $treinamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($treinamentos);
}
?>
