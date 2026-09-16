<?php
/** Execute somente no servidor: php create_admin.php nome@empresa.com "senha" [Nome] */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';
[$script, $email, $password, $name] = array_pad($argv, 4, null);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '') {
    fwrite(STDERR, "Uso: php create_admin.php email senha [Nome]\n");
    exit(1);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS app_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$stmt = $pdo->prepare('INSERT INTO app_users (name, email, password_hash) VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), active = 1');
$stmt->execute([$name ?: $email, $email, password_hash($password, PASSWORD_DEFAULT)]);
fwrite(STDOUT, "Usuário administrador criado/atualizado.\n");
