<?php
require 'config.php';
$stmt = $pdo->query("SHOW COLUMNS FROM clientes");
while($row = $stmt->fetch()) {
    echo $row['Field'] . "\n";
}
