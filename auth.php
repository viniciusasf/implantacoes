<?php
/** Controle central de acesso por sessão. */
if (PHP_SAPI === 'cli') {
    return;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('implanta_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function authIsApiRequest(): bool
{
    $file = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    foreach (['api_', 'get_', 'salvar_', 'marcar_', 'retirar_', 'encerrar_', 'google_calendar_'] as $prefix) {
        if (substr($file, 0, strlen($prefix)) === $prefix) {
            return true;
        }
    }
    return strpos(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function authLoginUrl(): string
{
    $directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $basePath = rtrim($directory, '/');
    return ($basePath === '' ? '' : $basePath) . '/login.php';
}

function authRequireLogin(): void
{
    if (!empty($_SESSION['user_id'])) {
        return;
    }
    if (authIsApiRequest()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Autenticação necessária.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $returnTo = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: ' . authLoginUrl() . '?return_to=' . rawurlencode($returnTo));
    exit;
}

if (!defined('AUTH_PUBLIC_PAGE')) {
    authRequireLogin();
}
