<?php
require_once 'config.php';
$stmt = $pdo->query('SHOW COLUMNS FROM chamados_espelho_local');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
    echo $col['Field'] . "\t" . $col['Type'] . "\n";
}
