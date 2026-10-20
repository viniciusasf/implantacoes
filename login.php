<?php
define('AUTH_PUBLIC_PAGE', true);
require_once __DIR__ . '/config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['login_csrf'], $csrf)) {
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM app_users WHERE email = ? AND active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
        } catch (PDOException $exception) {
            $user = false;
            $error = 'Acesso ainda não configurado. Crie o primeiro administrador no servidor.';
        }
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            unset($_SESSION['login_csrf']);
            $returnTo = (string)($_POST['return_to'] ?? 'index.php');
            if (substr($returnTo, 0, 1) !== '/' || substr($returnTo, 0, 2) === '//') {
                $returnTo = 'index.php';
            }
            header('Location: ' . $returnTo);
            exit;
        }
        if ($error === '') {
            $error = 'E-mail ou senha inválidos.';
        }
    }
}

$returnTo = (string)($_GET['return_to'] ?? $_POST['return_to'] ?? '');
if (substr($returnTo, 0, 1) !== '/' || substr($returnTo, 0, 2) === '//') {
    $returnTo = '';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acessar | Implantação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
    <main class="card shadow-sm border-0 mx-auto" style="width: min(100% - 2rem, 420px)">
        <div class="card-body p-4 p-md-5">
            <h1 class="h3 mb-2">Acessar sistema</h1>
            <p class="text-muted mb-4">Entre com suas credenciais.</p>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="on">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-3"><label class="form-label" for="email">E-mail</label><input class="form-control" type="email" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required autofocus></div>
                <div class="mb-4"><label class="form-label" for="password">Senha</label><input class="form-control" type="password" id="password" name="password" required></div>
                <button class="btn btn-primary w-100" type="submit">Entrar</button>
            </form>
        </div>
    </main>
</body>
</html>
