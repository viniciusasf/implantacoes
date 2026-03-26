<?php
/**
 * google_auth_reset.php
 * Script auxiliar para resetar e reautorizar o token OAuth do Google Agenda.
 * Acesse via: http://localhost/implanta/google_auth_reset.php
 */
session_start();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/google_oauth_token_helper.php';

// ── helpers ──────────────────────────────────────────────────────────────────

function buildRedirectUri(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    return $protocol . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
}

function makeClient(): Google\Client
{
    $client = new Google\Client();
    $client->setAuthConfig(__DIR__ . '/credentials.json');
    $client->addScope(Google\Service\Calendar::CALENDAR);
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setRedirectUri(buildRedirectUri());
    $client->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
    return $client;
}

// ── lógica principal ──────────────────────────────────────────────────────────

$tokenPath = googleTokenPath();
$message   = '';
$tokenInfo = '';
$tokenValid = false;
$authUrl   = '';

// 1. Apagar token atual (se solicitado)
if (isset($_GET['reset'])) {
    googleForgetToken($tokenPath);
    header('Location: google_auth_reset.php');
    exit;
}

// 2. Trocar code OAuth por token e salvar
if (isset($_GET['code'])) {
    $client = makeClient();
    $token  = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    if (isset($token['error'])) {
        $message = '❌ Erro ao trocar code: ' . ($token['error_description'] ?? $token['error']);
    } else {
        googlePersistToken($client, $token);
        $message = '✅ Token salvo com sucesso! Você já pode fechar esta página e usar o sistema normalmente.';
    }
}

// 3. Verificar token existente e gerar authUrl
$client    = makeClient();
$tokenData = googleLoadTokenData($tokenPath);

if ($tokenData) {
    $client->setAccessToken($tokenData);

    $hasRefresh   = !empty($tokenData['refresh_token']);
    $isExpired    = $client->isAccessTokenExpired();
    $created      = isset($tokenData['created'])
        ? date('d/m/Y H:i:s', $tokenData['created'])
        : 'desconhecido';
    $refreshExpAt = (isset($tokenData['created'], $tokenData['refresh_token_expires_in']))
        ? date('d/m/Y H:i:s', $tokenData['created'] + $tokenData['refresh_token_expires_in'])
        : 'desconhecido';

    if ($hasRefresh && $isExpired) {
        try {
            $newToken = $client->fetchAccessTokenWithRefreshToken($tokenData['refresh_token']);
            if (isset($newToken['error'])) {
                if (googleIsInvalidGrantError($newToken['error'])) {
                    googleForgetToken();
                    $tokenInfo = "⚠️ O acesso ao Google foi revogado ou o token é inválido. O arquivo de token antigo foi removido para sua segurança. Por favor, autorize novamente.";
                } else {
                    $tokenInfo = "⚠️ Erro ao renovar token: " . htmlspecialchars($newToken['error_description'] ?? $newToken['error']);
                }
                $tokenValid = false;
            } else {
                googlePersistToken($client);
                $tokenValid = true;
                $tokenInfo  = "✅ Token renovado automaticamente!<br>Criado em: <strong>$created</strong> | Refresh expira: <strong>$refreshExpAt</strong>";
            }
        } catch (Throwable $e) {
            if (googleIsInvalidGrantError($e)) {
                googleForgetToken();
                $tokenInfo = "⚠️ Falha crítica: O token do Google Agenda foi revogado ou expirou.<br><strong>O arquivo foi limpo. Por favor, clique em Autorizar abaixo.</strong>";
            } else {
                $tokenInfo = "⚠️ Erro técnico ao tentar renovar: " . htmlspecialchars($e->getMessage());
            }
            $tokenValid = false;
        }
    } elseif (!$isExpired) {
        $tokenValid = true;
        $tokenInfo  = "✅ Token válido e ativo.<br>"
                    . "Criado em: <strong>$created</strong> | Refresh expira: <strong>$refreshExpAt</strong><br>"
                    . "Refresh token: " . ($hasRefresh ? '<span class="text-success">presente</span>' : '<span class="text-danger">AUSENTE ⚠️</span>');
    } else {
        $tokenInfo = "❌ Token expirado e sem refresh_token. Reautorize abaixo.<br>Criado em: <strong>$created</strong>";
    }
} else {
    $tokenInfo = '❌ Nenhum token encontrado. Clique em Autorizar para conectar.';
}

$authUrl = $client->createAuthUrl();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Reautorizar Google Agenda</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light py-5">
<div class="container" style="max-width:620px">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">🔑 Gerenciador de Token — Google Agenda</h5>
        </div>
        <div class="card-body">

            <?php if ($message): ?>
                <div class="alert alert-<?= str_contains($message, '✅') ? 'success' : 'danger' ?>">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <p><strong>Status atual:</strong><br><?= $tokenInfo ?></p>

            <hr>

            <?php if (!$tokenValid): ?>
                <p class="mb-3">Clique no botão abaixo para autorizar o acesso ao Google Agenda:</p>
                <a href="<?= htmlspecialchars($authUrl) ?>" class="btn btn-success w-100 mb-3">
                    🔓 Autorizar acesso ao Google Agenda
                </a>
            <?php else: ?>
                <p class="text-success fw-bold">✔ Sistema autorizado. Nenhuma ação necessária.</p>
                <a href="<?= htmlspecialchars($authUrl) ?>" class="btn btn-outline-primary btn-sm mb-2">
                    🔄 Reautorizar mesmo assim
                </a>
            <?php endif; ?>

            <hr>
            <p class="text-muted small mb-1">Apagar token e forçar nova autorização do zero:</p>
            <a href="google_auth_reset.php?reset=1"
               class="btn btn-outline-danger btn-sm"
               onclick="return confirm('Tem certeza? O token atual será removido e você precisará reautorizar.')">
                🗑️ Apagar token e reautorizar
            </a>
        </div>
        <div class="card-footer text-muted small">
            <strong>Arquivo do token:</strong> <?= htmlspecialchars($tokenPath) ?>
        </div>
    </div>
</div>
</body>
</html>
