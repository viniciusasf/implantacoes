<?php
require_once 'config.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->query("SELECT id_chamado_api, notificado FROM chamados_espelho_local");
    $locais = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $locais[$row['id_chamado_api']] = (int)$row['notificado'];
    }
    echo json_encode(['sucesso' => true, 'dados' => $locais]);
} catch (PDOException $e) {
    echo json_encode(['sucesso' => false, 'erro' => 'Erro BD: ' . $e->getMessage()]);
}
