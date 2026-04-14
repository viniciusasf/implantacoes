<?php
require "config.php"; 
$sql = "SELECT id_treinamento FROM treinamentos LIMIT ?,?"; 
$stmt = $pdo->prepare($sql); 
try { 
    $stmt->execute([0, 20]); 
    var_dump($stmt->fetchAll()); 
} catch(PDOException $e) { 
    echo "PDOException: " . $e->getMessage() . "\n"; 
} catch (Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
?>
