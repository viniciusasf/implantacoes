<?php
/**
 * bridge: API GestãoPro → JSON
 * Suporta múltiplos endpoints: implantacoes | chamados
 * Uso: api_gestaopro_bridge.php?endpoint=implantacoes
 *      api_gestaopro_bridge.php?endpoint=chamados
 *      Adicionar &forcar=1 para ignorar o cache.
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

// ── Configurações ─────────────────────────────────────────────────────────────
define('GP_BASE_URL',  'https://interno.gestaopro.srv.br');
define('GP_ACTION_ID', '40c1193ac08d14a23dc52b4bb1daa769e054ec51bb');
define('GP_LOGIN',     'vinicius');
define('GP_SENHA',     'codigoc123');
define('GP_CACHE_TTL', 300); // 5 minutos

$ENDPOINTS_VALIDOS = ['implantacoes', 'chamados'];
$endpoint = $_GET['endpoint'] ?? 'implantacoes';
$base_endpoint = explode('?', $endpoint)[0];

if (!in_array($base_endpoint, $ENDPOINTS_VALIDOS, true)) {
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

// ── Descobrir Next-Action ID dinamicamente ───────────────────────────────────
function descobrirActionId(): ?string {
    $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $html = @file_get_contents(GP_BASE_URL . '/login', false, $context);
    if (!$html) return null;

    preg_match_all('/src="(\/_next\/static\/chunks\/[^"]+\.js)"/', $html, $matches);
    $scripts = $matches[1];

    $hashes = [];
    preg_match_all('/[a-f0-9]{32,45}/', $html, $m);
    if (!empty($m[0])) $hashes = array_merge($hashes, $m[0]);

    foreach ($scripts as $script) {
        $js = @file_get_contents(GP_BASE_URL . $script, false, $context);
        if ($js) {
            preg_match_all('/[a-f0-9]{32,45}/', $js, $m);
            if (!empty($m[0])) $hashes = array_merge($hashes, $m[0]);
        }
    }

    $hashes = array_unique($hashes);
    $body = json_encode([['login' => GP_LOGIN, 'senha' => GP_SENHA, 'from' => null]]);

    foreach ($hashes as $hash) {
        $ch = curl_init(GP_BASE_URL . '/login');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/plain;charset=UTF-8',
                'Next-Action: ' . $hash,
                'Accept: */*',
                'Origin: ' . GP_BASE_URL,
                'Referer: ' . GP_BASE_URL . '/login',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 302 || $httpCode === 303 || $httpCode === 200) {
            // Sucesso na autenticação
            return $hash;
        }
    }
    return null;
}

// ── Login → obtém string de cookies ──────────────────────────────────────────
function fazerLogin($tentativa = 1): ?string {
    $hashFile = __DIR__ . '/logs/gp_action_hash.txt';
    $actionId = file_exists($hashFile) ? trim(file_get_contents($hashFile)) : GP_ACTION_ID;

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
            'Next-Action: ' . $actionId,
            'Accept: */*',
            'Origin: ' . GP_BASE_URL,
            'Referer: ' . GP_BASE_URL . '/login',
        ],
    ]);

    $response   = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if (!in_array($httpCode, [200, 302, 303])) {
        // Se falhou e é a primeira tentativa, tenta descobrir o novo hash
        if ($tentativa === 1) {
            $novoHash = descobrirActionId();
            if ($novoHash && $novoHash !== $actionId) {
                file_put_contents($hashFile, $novoHash);
                return fazerLogin(2);
            }
        }
        return null;
    }

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

    // 3. Buscar dados (com suporte a paginação para chamados)
    $dados = buscarEndpoint("/api/{$endpoint}", $cookieStr);
    if (!$dados) {
        http_response_code(502);
        echo json_encode(['sucesso' => false, 'erro' => "Endpoint /api/{$endpoint} retornou resposta inválida."]);
        exit;
    }

    // Se o endpoint retorna dados paginados, buscar todas as páginas
    if (isset($dados['totalPages']) && $dados['totalPages'] > 1) {
        // Identificar a chave dos dados (ex: 'chamados', 'clientes')
        $chavesDados = array_diff(array_keys($dados), ['total', 'page', 'count', 'totalPages']);
        $chavePrincipal = reset($chavesDados);

        if ($chavePrincipal && is_array($dados[$chavePrincipal])) {
            $todosRegistros = $dados[$chavePrincipal];
            $totalPages = (int) $dados['totalPages'];

            for ($pg = 2; $pg <= $totalPages; $pg++) {
                $dadosPg = buscarEndpoint("/api/{$endpoint}?page={$pg}", $cookieStr);
                if ($dadosPg && isset($dadosPg[$chavePrincipal]) && is_array($dadosPg[$chavePrincipal])) {
                    $todosRegistros = array_merge($todosRegistros, $dadosPg[$chavePrincipal]);
                }
            }

            // Substituir array parcial pelo completo
            $dados[$chavePrincipal] = $todosRegistros;
            $dados['count'] = count($todosRegistros);
            $dados['page'] = 'all';
            $dados['totalPages'] = 1;
        }
    }

    // Buscar "Resolvidos" separadamente para juntar na base, se o endpoint for chamados
    if ($base_endpoint === 'chamados') {
        $dadosResolvidos = buscarEndpoint("/api/chamados?status=Resolvido", $cookieStr);
        if ($dadosResolvidos && isset($dadosResolvidos['chamados']) && is_array($dadosResolvidos['chamados'])) {
            $dados['chamados'] = array_merge($dados['chamados'] ?? [], $dadosResolvidos['chamados']);
            $dados['count'] = count($dados['chamados']);
        }
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
