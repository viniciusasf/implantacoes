<?php
require 'config.php';
try {
    $pdo->exec("ALTER TABLE pendencias_treinamentos MODIFY id_treinamento INT NULL");
    echo "Modified id_treinamento to NULL\n";
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

try {
    $pdo->exec("ALTER TABLE pendencias_treinamentos DROP INDEX uq_pendencia_treinamento");
    echo "Dropped index uq_pendencia_treinamento\n";
} catch (Exception $e) { echo $e->getMessage() . "\n"; }
