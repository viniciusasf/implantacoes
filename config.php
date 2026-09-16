<?php
require_once __DIR__ . '/app_config.php';
$host = appRequiredConfig('db', 'host');
$db   = appRequiredConfig('db', 'name');
$user = appRequiredConfig('db', 'user');
$pass = appConfig('db', 'pass');
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

require_once __DIR__ . '/auth.php';
?>
