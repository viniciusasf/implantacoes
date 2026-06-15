<?php
/**
 * bridge: API GestãoPro → JSON
 * Suporta múltiplos endpoints: implantacoes | chamados
 * Uso: api_gestaopro_bridge.php?endpoint=implantacoes
 *      api_gestaopro_bridge.php?endpoint=chamados
 *      Adicionar &forcar=1 para ignorar o cache.
 */

header('Content-Type: application/json; charset=utf-8');

// ── Configurações ─────────────────────────────────────────────────────────────
define('GP_BASE_URL',  'https://interno.gestaopro.srv.br');
define('GP_ACTION_ID', '40e819adfdcc24d2250d7204df521f9f64c086cfb8');
define('GP_LOGIN',     'vinicius');
define('GP_SENHA',     'codigoc123');
define('GP_CACHE_TTL', 300); // 5 minutos

$ENDPOINTS_VALIDOS = ['implantacoes', 'chamados'];
$endpoint = $_GET['endpoint'] ?? 'implantacoes';

if (!in_array($endpoint, $ENDPOINTS_VALIDOS, true)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => "Endpoint '$endpoint' inválido."]);
    exit;
}

$cacheFile = __DIR__ . "/logs/gp_cache_{$endpoint}.json";

// ── Cache simples em arquivo ──────────────────────────────────────────────────
function lerCache(string $file): ?array {
    if (!file_exists($file)) return null;
    if ((time() - filemtime($file)) > GP_CACHE_TTL) return null;
    $dados = @json_decode(file_get_contents($file), true);
    return is_array($dados) ? $dados : null;
}

function salvarCache(string $file, array $dados): void {
    @file_put_contents($file, json_encode($dados, JSON_UNESCAPED_UNICODE));
}

// ── Login → obtém string de cookies ──────────────────────────────────────────
function fazerLogin(): ?string {
    $body = json_encode([['login' => GP_LOGIN, 'senha' => GP_SENHA, 'from' => null]]);

    $ch = curl_init(GP_BASE_URL . '/login');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/plain;charset=UTF-8',
            'Next-Action: ' . GP_ACTION_ID,
            'Accept: */*',
        ],
    ]);

    $response   = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if (!in_array($httpCode, [200, 302, 303])) return null;

    preg_match_all('/Set-Cookie:\s*([^;\r\n]+)/i', substr($response, 0, $headerSize), $m);
    return empty($m[1]) ? null : implode('; ', $m[1]);
}

// ── Buscar qualquer endpoint autenticado ──────────────────────────────────────
function buscarEndpoint(string $path, string $cookieStr): ?array {
    $ch = curl_init(GP_BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_COOKIE         => $cookieStr,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;
    $dados = @json_decode($response, true);
    return is_array($dados) ? $dados : null;
}

// ── Fluxo principal ───────────────────────────────────────────────────────────
try {
    // Invalidar cache se solicitado
    if (!empty($_GET['forcar']) && file_exists($cacheFile)) {
        @unlink($cacheFile);
    }

    // 1. Cache
    $cached = lerCache($cacheFile);
    if ($cached !== null) {
        echo json_encode([
            'sucesso'   => true,
            'origem'    => 'cache',
            'endpoint'  => $endpoint,
            'gerado_em' => date('d/m/Y H:i:s', filemtime($cacheFile)),
            'dados'     => $cached,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Login
    $cookieStr = fazerLogin();
    if (!$cookieStr) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'erro' => 'Falha na autenticação com a API GestãoPro.']);
        exit;
    }

    // 3. Buscar dados
    $dados = buscarEndpoint("/api/{$endpoint}", $cookieStr);
    if (!$dados) {
        http_response_code(502);
        echo json_encode(['sucesso' => false, 'erro' => "Endpoint /api/{$endpoint} retornou resposta inválida."]);
        exit;
    }

    // 4. Cache + retorno
    salvarCache($cacheFile, $dados);

    echo json_encode([
        'sucesso'   => true,
        'origem'    => 'api',
        'endpoint'  => $endpoint,
        'gerado_em' => date('d/m/Y H:i:s'),
        'dados'     => $dados,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno: ' . $e->getMessage()]);
}
