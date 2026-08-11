<?php
require_once 'config.php';
try {
    $stmt = $pdo->prepare('SHOW COLUMNS FROM chamados_espelho_local LIKE ?');
    $stmt->execute(['drive_file_id']);
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    var_export($cols);
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
