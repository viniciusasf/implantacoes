<?php
require 'config.php';
$stmt = $pdo->query("DESCRIBE chamados_espelho_local");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c) {
    if ($c['Field'] === 'descricao_problema') {
        echo "Tipo: " . $c['Type'] . "\n";
    }
}
