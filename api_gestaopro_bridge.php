<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/app_config.php';
// A ponte pode levar vários segundos consultando a API externa. Libere a sessão
// para que as outras requisições AJAX da mesma página não fiquem bloqueadas.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
/**
 * bridge: API GestãoPro → JSON
 * Suporta múltiplos endpoints: implantacoes | chamados | chamados_cliente
 * Uso: api_gestaopro_bridge.php?endpoint=implantacoes
 *      api_gestaopro_bridge.php?endpoint=chamados
 *      Adicionar &forcar=1 para atualizar os dados pela API.
 *      Adicionar &somente_cache=1 para retornar a ultima leitura, mesmo expirada.
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

// ── Configurações ─────────────────────────────────────────────────────────────
define('GP_BASE_URL',  appRequiredConfig('gestaopro', 'base_url'));
define('GP_ACTION_ID', appRequiredConfig('gestaopro', 'action_id'));
define('GP_LOGIN',     appRequiredConfig('gestaopro', 'login'));
define('GP_SENHA',     appRequiredConfig('gestaopro', 'password'));
define('GP_CACHE_TTL', 300); // 5 minutos

$ENDPOINTS_VALIDOS = ['implantacoes', 'chamados', 'chamados_cliente', 'uso', 'mesa'];
$endpoint = $_GET['endpoint'] ?? 'implantacoes';
$base_endpoint = explode('/', explode('?', $endpoint)[0])[0];

if (!in_array($base_endpoint, $ENDPOINTS_VALIDOS, true)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => "Endpoint '$endpoint' inválido."]);
    exit;
}

$cacheFile = __DIR__ . "/logs/gp_cache_{$endpoint}.json";

// ── Cache simples em arquivo ──────────────────────────────────────────────────
function garantirDiretorioCache(string $caminhoArquivo): void {
    $diretorio = dirname($caminhoArquivo);
    if (is_dir($diretorio)) {
        return;
    }

    if (!@mkdir($diretorio, 0777, true) && !is_dir($diretorio)) {
        throw new RuntimeException("Não foi possível criar o diretório de cache: {$diretorio}");
    }
}

function lerCache(string $file, bool $aceitarExpirado = false): ?array {
    if (!file_exists($file)) return null;
    if (!$aceitarExpirado && (time() - filemtime($file)) > GP_CACHE_TTL) return null;
    $dados = @json_decode(file_get_contents($file), true);
    return is_array($dados) ? $dados : null;
}

function salvarCache(string $file, array $dados): void {
    garantirDiretorioCache($file);
    @file_put_contents($file, json_encode($dados, JSON_UNESCAPED_UNICODE));
}

function mesclarChamados(array $chamados): array {
    $porId = [];
    foreach ($chamados as $chamado) {
        $id = (string) ($chamado['ID'] ?? '');
        if ($id === '') {
            $porId[] = $chamado;
            continue;
        }

        if (!isset($porId[$id])) {
            $porId[$id] = $chamado;
            continue;
        }

        $descricaoAtual = (string) ($porId[$id]['DESCRICAO'] ?? '');
        $descricaoNova = (string) ($chamado['DESCRICAO'] ?? '');
        if (strlen($descricaoNova) > strlen($descricaoAtual)) {
            $porId[$id]['DESCRICAO'] = $chamado['DESCRICAO'];
        }
    }

    return array_values($porId);
}

/** Busca todas as páginas de um endpoint que retorna uma coleção paginada. */
function buscarTodasPaginas(string $path, string $cookieStr, string $chavePrincipal = ''): ?array {
    $separador = strpos($path, '?') === false ? '?' : '&';
    $primeira = buscarEndpoint($path, $cookieStr);
    if ($primeira === null) {
        return null;
    }

    if ($chavePrincipal === '') {
        $chavesCandidatas = [];
        foreach (['chamados', 'implantacao', 'implantacoes', 'tickets', 'data', 'dados', 'items', 'registros', 'results'] as $chave) {
            if (isset($primeira[$chave]) && is_array($primeira[$chave])) {
                $chavesCandidatas[] = $chave;
            }
        }
        foreach ($primeira as $chave => $valor) {
            if (
                is_array($valor)
                && !in_array($chave, ['total', 'page', 'count', 'totalPages'], true)
                && !in_array((string) $chave, $chavesCandidatas, true)
            ) {
                $chavesCandidatas[] = (string) $chave;
            }
        }
        foreach ($chavesCandidatas as $chave) {
            if (!empty($primeira[$chave])) {
                $chavePrincipal = $chave;
                break;
            }
        }
        if ($chavePrincipal === '' && !empty($chavesCandidatas)) {
            $chavePrincipal = $chavesCandidatas[0];
        }
    }

    if ($chavePrincipal === '' || !isset($primeira[$chavePrincipal]) || !is_array($primeira[$chavePrincipal])) {
        return $primeira;
    }

    $registros = $primeira[$chavePrincipal];
    // Sem totalPages não há um fim confiável para a consulta. Algumas consultas
    // por status continuam retornando páginas indefinidamente e estouram o tempo do PHP.
    if (!isset($primeira['totalPages'])) {
        return $primeira;
    }

    $vistos = [];
    foreach ($registros as $registro) {
        $chave = is_array($registro) && isset($registro['ID'])
            ? 'id:' . (string) $registro['ID']
            : 'row:' . hash('sha256', serialize($registro));
        $vistos[$chave] = true;
    }
    $totalPaginas = max(1, (int) $primeira['totalPages']);
    $pagina = max(1, (int) ($primeira['page'] ?? 1));

    $tamanhoLote = 8;
    for ($inicioPagina = $pagina + 1; $inicioPagina <= $totalPaginas; $inicioPagina += $tamanhoLote) {
        $fimPagina = min($totalPaginas, $inicioPagina + $tamanhoLote - 1);
        $paths = [];
        for ($numeroPagina = $inicioPagina; $numeroPagina <= $fimPagina; $numeroPagina++) {
            $paths[$numeroPagina] = $path . $separador . 'page=' . $numeroPagina;
        }
        $paginas = buscarEndpointsEmLote($paths, $cookieStr);

        foreach ($paginas as $numeroPagina => $dadosPagina) {
            if (!$dadosPagina || !isset($dadosPagina[$chavePrincipal]) || !is_array($dadosPagina[$chavePrincipal])) {
                throw new RuntimeException("Falha ao carregar a página {$numeroPagina} de {$path}.");
            }
            $itensPagina = $dadosPagina[$chavePrincipal];
            if (!$itensPagina) continue;
            foreach ($itensPagina as $registro) {
                $chave = is_array($registro) && isset($registro['ID'])
                    ? 'id:' . (string) $registro['ID']
                    : 'row:' . hash('sha256', serialize($registro));
                if (isset($vistos[$chave])) continue;
                $vistos[$chave] = true;
                $registros[] = $registro;
            }
        }
    }

    $primeira[$chavePrincipal] = $registros;
    $primeira['count'] = count($registros);
    $primeira['page'] = 'all';
    $primeira['totalPages'] = 1;
    return $primeira;
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
    garantirDiretorioCache($hashFile);
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
    global $ultimoErroBuscaEndpoint;
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
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($response === false) {
        $ultimoErroBuscaEndpoint = 'falha de conexão cURL (' . $curlErrno . ')';
        return null;
    }
    if ($httpCode !== 200) {
        $ultimoErroBuscaEndpoint = 'a API respondeu HTTP ' . $httpCode;
        return null;
    }
    if ($response === '') {
        $ultimoErroBuscaEndpoint = 'a API respondeu sem conteúdo (HTTP 200)';
        return null;
    }
    $dados = @json_decode($response, true);
    if (is_array($dados)) {
        $ultimoErroBuscaEndpoint = null;
        return $dados;
    }
    $ultimoErroBuscaEndpoint = 'JSON inválido (' . json_last_error_msg() . ')';
    return null;
}

/** Busca várias páginas em paralelo para manter a atualização dentro do tempo do PHP. */
function buscarEndpointsEmLote(array $paths, string $cookieStr): array {
    $multi = curl_multi_init();
    $handles = [];
    foreach ($paths as $chave => $path) {
        $ch = curl_init(GP_BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_COOKIE => $cookieStr,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$chave] = $ch;
    }

    do {
        $status = curl_multi_exec($multi, $ativos);
        if ($ativos) curl_multi_select($multi, 1.0);
    } while ($ativos && $status === CURLM_OK);

    $resultados = [];
    foreach ($handles as $chave => $ch) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $response = curl_multi_getcontent($ch);
        $dados = $httpCode === 200 && $response ? json_decode($response, true) : null;
        $resultados[$chave] = is_array($dados) ? $dados : null;
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $resultados;
}

// ── Fluxo principal ───────────────────────────────────────────────────────────
try {
    if ($base_endpoint === 'chamados_cliente') {
        $idClienteApi = trim((string) ($_GET['id_cliente_api'] ?? ''));
        if ($idClienteApi === '' || !ctype_digit($idClienteApi) || (int) $idClienteApi < 1) {
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => 'ID Cliente API inválido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $chamadosCacheFile = __DIR__ . '/logs/gp_cache_chamados_cliente_' . $idClienteApi . '.json';
        $forcar = !empty($_GET['forcar']);
        $somenteCache = !empty($_GET['somente_cache']);
        $cached = lerCache($chamadosCacheFile, $somenteCache);

        if (!$forcar && $cached !== null) {
            echo json_encode([
                'sucesso' => true,
                'origem' => 'cache',
                'gerado_em' => date('d/m/Y H:i:s', filemtime($chamadosCacheFile)),
                'id_cliente_api' => $idClienteApi,
                'dados' => $cached,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($somenteCache) {
            http_response_code(404);
            echo json_encode(['sucesso' => false, 'erro' => 'Nenhum chamado deste cliente está disponível em cache.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $cookieChamados = fazerLogin();
        if (!$cookieChamados) {
            http_response_code(401);
            echo json_encode(['sucesso' => false, 'erro' => 'Falha na autenticação com a API GestãoPro.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $dadosChamados = buscarTodasPaginas('/api/clientes/implantacao/' . rawurlencode($idClienteApi), $cookieChamados);
        if ($dadosChamados === null) {
            $detalhe = $ultimoErroBuscaEndpoint ?? 'a API não retornou uma resposta válida';
            $cacheContingencia = lerCache($chamadosCacheFile, true);
            if ($cacheContingencia !== null) {
                echo json_encode([
                    'sucesso' => true,
                    'origem' => 'cache',
                    'contingencia' => true,
                    'aviso' => "A API externa respondeu com erro ({$detalhe}). Exibindo a última leitura disponível em cache.",
                    'gerado_em' => date('d/m/Y H:i:s', filemtime($chamadosCacheFile)),
                    'id_cliente_api' => $idClienteApi,
                    'dados' => $cacheContingencia,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            http_response_code(502);
            echo json_encode(['sucesso' => false, 'erro' => "Não foi possível obter os chamados do cliente: {$detalhe}."], JSON_UNESCAPED_UNICODE);
            exit;
        }

        salvarCache($chamadosCacheFile, $dadosChamados);
        echo json_encode([
            'sucesso' => true,
            'origem' => 'api',
            'gerado_em' => date('d/m/Y H:i:s'),
            'id_cliente_api' => $idClienteApi,
            'dados' => $dadosChamados,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($base_endpoint === 'uso') {
        require_once __DIR__ . '/config.php';
        $dias = filter_var($_GET['dias'] ?? 30, FILTER_VALIDATE_INT);
        if (!$dias || $dias < 1 || $dias > 90) {
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => 'O período deve ser de 1 a 90 dias.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $usoCacheFile = __DIR__ . '/logs/gp_cache_uso_' . $dias . '.json';
        $forcar = !empty($_GET['forcar']);
        $somenteCache = !empty($_GET['somente_cache']);
        $idClienteApi = !empty($_GET['id_cliente_api']) ? trim((string)$_GET['id_cliente_api']) : null;

        // --- Consulta individual de um cliente por ID API ---
        if ($idClienteApi !== null && ctype_digit($idClienteApi)) {
            $cliCacheFile = __DIR__ . '/logs/gp_cache_uso_cli_' . $idClienteApi . '_' . $dias . '.json';
            if ($forcar && file_exists($cliCacheFile)) @unlink($cliCacheFile);

            $cliCached = lerCache($cliCacheFile, $somenteCache);
            $dadosGerais = lerCache($usoCacheFile, true);

            // 1. Se não estamos forçando e temos cache individual
            if (!$forcar && $cliCached !== null) {
                echo json_encode([
                    'sucesso'   => true,
                    'origem'    => 'cache',
                    'gerado_em' => date('d/m/Y H:i:s', filemtime($cliCacheFile)),
                    'id_cliente_api' => $idClienteApi,
                    'dados'     => $cliCached,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 2. Se não estamos forçando, podemos verificar se os dados existem no cache geral
            if (!$forcar && $dadosGerais !== null && isset($dadosGerais['clientes']) && is_array($dadosGerais['clientes'])) {
                foreach ($dadosGerais['clientes'] as $clItem) {
                    if ((string)($clItem['id_cliente_api'] ?? '') === $idClienteApi && !empty($clItem['dados'])) {
                        salvarCache($cliCacheFile, $clItem['dados']);
                        echo json_encode([
                            'sucesso'   => true,
                            'origem'    => 'cache',
                            'gerado_em' => date('d/m/Y H:i:s', filemtime($usoCacheFile)),
                            'id_cliente_api' => $idClienteApi,
                            'dados'     => $clItem['dados'],
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }
            }

            if ($somenteCache) {
                http_response_code(404);
                echo json_encode(['sucesso' => false, 'erro' => 'Nenhum dado em cache para este cliente.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 3. Consultar diretamente a API GestãoPro
            $cookieUso = fazerLogin();
            if (!$cookieUso) {
                http_response_code(401);
                echo json_encode(['sucesso' => false, 'erro' => 'Falha na autenticação com a API GestãoPro.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $dadosApiCli = buscarEndpoint('/api/clientes/' . rawurlencode($idClienteApi) . '/uso?' . http_build_query(['dias' => $dias]), $cookieUso);
            if ($dadosApiCli === null) {
                http_response_code(502);
                echo json_encode(['sucesso' => false, 'erro' => 'Não foi possível obter dados de uso do cliente.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            salvarCache($cliCacheFile, $dadosApiCli);

            // Atualiza também no cache geral se existir
            if ($dadosGerais !== null && isset($dadosGerais['clientes']) && is_array($dadosGerais['clientes'])) {
                $encontrou = false;
                foreach ($dadosGerais['clientes'] as &$clItem) {
                    if ((string)($clItem['id_cliente_api'] ?? '') === $idClienteApi) {
                        $clItem['dados'] = $dadosApiCli;
                        $encontrou = true;
                        break;
                    }
                }
                unset($clItem);
                if (!$encontrou) {
                    $dadosGerais['clientes'][] = [
                        'id_cliente' => 0,
                        'fantasia' => '',
                        'id_cliente_api' => $idClienteApi,
                        'serial_cadastro' => '',
                        'dados' => $dadosApiCli,
                    ];
                }
                salvarCache($usoCacheFile, $dadosGerais);
            }

            echo json_encode([
                'sucesso'   => true,
                'origem'    => 'api',
                'gerado_em' => date('d/m/Y H:i:s'),
                'id_cliente_api' => $idClienteApi,
                'dados'     => $dadosApiCli,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // --- Consulta geral de todos os clientes ---
        if ($forcar && file_exists($usoCacheFile)) @unlink($usoCacheFile);
        $usoCached = lerCache($usoCacheFile, $somenteCache);
        if ($usoCached !== null) {
            echo json_encode(['sucesso' => true, 'origem' => 'cache', 'gerado_em' => date('d/m/Y H:i:s', filemtime($usoCacheFile)), 'dados' => $usoCached], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($somenteCache) {
            http_response_code(404);
            echo json_encode(['sucesso' => false, 'erro' => 'Ainda não há uma consulta de uso em cache.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $clientesUso = $pdo->query("SELECT id_cliente, fantasia, id_cliente_api, serial FROM clientes WHERE UPPER(TRIM(status)) = 'EM ANDAMENTO' AND id_cliente_api IS NOT NULL AND id_cliente_api <> '' ORDER BY fantasia")->fetchAll(PDO::FETCH_ASSOC);
        $cookieUso = fazerLogin();
        if (!$cookieUso) {
            http_response_code(401);
            echo json_encode(['sucesso' => false, 'erro' => 'Falha na autenticação com a API GestãoPro.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pathsUso = [];
        foreach ($clientesUso as $clienteUso) {
            $idApi = trim((string)$clienteUso['id_cliente_api']);
            if (ctype_digit($idApi)) $pathsUso[$idApi] = '/api/clientes/' . rawurlencode($idApi) . '/uso?' . http_build_query(['dias' => $dias]);
        }
        $respostasUso = buscarEndpointsEmLote($pathsUso, $cookieUso);
        $registrosUso = [];
        foreach ($clientesUso as $clienteUso) {
            $idApi = (string)$clienteUso['id_cliente_api'];
            $registrosUso[] = [
                'id_cliente' => (int)$clienteUso['id_cliente'],
                'fantasia' => $clienteUso['fantasia'],
                'id_cliente_api' => $idApi,
                'serial_cadastro' => $clienteUso['serial'],
                'dados' => $respostasUso[$idApi] ?? null,
            ];
        }
        $payloadUso = ['dias' => $dias, 'clientes' => $registrosUso];
        salvarCache($usoCacheFile, $payloadUso);
        echo json_encode(['sucesso' => true, 'origem' => 'api', 'gerado_em' => date('d/m/Y H:i:s'), 'dados' => $payloadUso], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($base_endpoint === 'mesa') {
        $usuario = filter_var($_GET['usuario'] ?? 53, FILTER_VALIDATE_INT) ?: 53;
        $secoes = !empty($_GET['secoes']) ? trim((string)$_GET['secoes']) : 'chamados,clientes';
        $mesaCacheFile = __DIR__ . "/logs/gp_cache_mesa_{$usuario}.json";
        $forcar = !empty($_GET['forcar']);
        $somenteCache = !empty($_GET['somente_cache']);

        if ($forcar && file_exists($mesaCacheFile)) {
            @unlink($mesaCacheFile);
        }

        $cached = lerCache($mesaCacheFile, $somenteCache);
        if (!$forcar && $cached !== null) {
            echo json_encode([
                'sucesso'   => true,
                'origem'    => 'cache',
                'endpoint'  => 'mesa',
                'gerado_em' => date('d/m/Y H:i:s', filemtime($mesaCacheFile)),
                'dados'     => $cached,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($somenteCache && $cached === null) {
            http_response_code(404);
            echo json_encode(['sucesso' => false, 'erro' => 'Nenhuma leitura anterior da mesa está disponível.']);
            exit;
        }

        $cookieMesa = fazerLogin();
        if (!$cookieMesa) {
            http_response_code(401);
            echo json_encode(['sucesso' => false, 'erro' => 'Falha na autenticação com a API GestãoPro.']);
            exit;
        }

        $secoesArray = array_unique(array_filter(array_merge(explode(',', $secoes), ['clientes', 'chamados'])));
        $queryMesa = http_build_query([
            'usuario' => $usuario,
            'secoes'  => implode(',', $secoesArray),
        ]);
        $dadosMesa = buscarEndpoint('/api/mesa?' . $queryMesa, $cookieMesa);

        if ($dadosMesa === null) {
            $detalhe = $ultimoErroBuscaEndpoint ?? 'a API não retornou dados válidos';
            $cacheContingencia = lerCache($mesaCacheFile, true);
            if ($cacheContingencia !== null) {
                echo json_encode([
                    'sucesso'   => true,
                    'origem'    => 'cache',
                    'contingencia' => true,
                    'aviso'     => "A API da mesa respondeu com erro ({$detalhe}). Exibindo última leitura em cache.",
                    'endpoint'  => 'mesa',
                    'gerado_em' => date('d/m/Y H:i:s', filemtime($mesaCacheFile)),
                    'dados'     => $cacheContingencia,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            http_response_code(502);
            echo json_encode(['sucesso' => false, 'erro' => "Endpoint /api/mesa falhou: {$detalhe}."]);
            exit;
        }

        if (!isset($dadosMesa['chamados']) || !is_array($dadosMesa['chamados'])) {
            $dadosMesa['chamados'] = [];
        }

        salvarCache($mesaCacheFile, $dadosMesa);

        echo json_encode([
            'sucesso'   => true,
            'origem'    => 'api',
            'endpoint'  => 'mesa',
            'gerado_em' => date('d/m/Y H:i:s'),
            'dados'     => $dadosMesa,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($base_endpoint === 'chamados' && isset($_GET['detalhe'])) {
        $idDetalhe = filter_var($_GET['detalhe'], FILTER_VALIDATE_INT);
        if (!$idDetalhe || $idDetalhe < 1) {
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => 'ID do chamado inválido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $cookieDetalhe = fazerLogin();
        if (!$cookieDetalhe) {
            http_response_code(401);
            echo json_encode(['sucesso' => false, 'erro' => 'Falha na autenticação com a API GestãoPro.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $detalhe = buscarEndpoint('/api/chamados/' . rawurlencode((string) $idDetalhe), $cookieDetalhe);
        if (!$detalhe) {
            http_response_code(502);
            echo json_encode(['sucesso' => false, 'erro' => 'Não foi possível carregar os detalhes do chamado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['sucesso' => true, 'dados' => ['chamado' => $detalhe['chamado'] ?? $detalhe]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $somenteCache = !empty($_GET['somente_cache']);

    $forcar = !empty($_GET['forcar']);

    // 1. Cache
    $cached = lerCache($cacheFile, $somenteCache);
    if (!$forcar && $cached !== null && ($base_endpoint !== 'chamados' || isset($cached['chamados']))) {
        echo json_encode([
            'sucesso'   => true,
            'origem'    => 'cache',
            'endpoint'  => $endpoint,
            'gerado_em' => date('d/m/Y H:i:s', filemtime($cacheFile)),
            'dados'     => $cached,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // A abertura da tela deve usar somente a ultima leitura ja armazenada.
    if ($somenteCache && $cached === null) {
        http_response_code(404);
        echo json_encode([
            'sucesso' => false,
            'erro' => 'Nenhuma leitura anterior dos chamados esta disponivel. Use Atualizar para consultar a API.',
        ]);
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
    $dados = $base_endpoint === 'chamados'
        ? buscarTodasPaginas("/api/{$endpoint}", $cookieStr, 'chamados')
        : buscarEndpoint("/api/{$endpoint}", $cookieStr);

    // Uma resposta JSON vazia ([]) e valida; apenas falha de HTTP ou JSON
    // invalido retorna null em buscarEndpoint/buscarTodasPaginas.
    if ($dados === null) {
        $detalhe = $ultimoErroBuscaEndpoint ?? 'a API não retornou uma coleção JSON válida';

        // Se houver leitura anterior em cache, utilize-a como contingência
        $cacheContingencia = lerCache($cacheFile, true);
        if ($cacheContingencia !== null) {
            echo json_encode([
                'sucesso'   => true,
                'origem'    => 'cache',
                'contingencia' => true,
                'aviso'     => "A API externa respondeu com erro ({$detalhe}). Exibindo última leitura disponível em cache.",
                'endpoint'  => $endpoint,
                'gerado_em' => date('d/m/Y H:i:s', filemtime($cacheFile)),
                'dados'     => $cacheContingencia,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(502);
        echo json_encode(['sucesso' => false, 'erro' => "Endpoint /api/{$endpoint} falhou: {$detalhe}."]);
        exit;
    }

    // Se o endpoint retorna dados paginados, buscar todas as páginas
    if ($base_endpoint !== 'chamados' && isset($dados['totalPages']) && $dados['totalPages'] > 1) {
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
            $dados[$chavePrincipal] = $chavePrincipal === 'chamados'
                ? mesclarChamados($todosRegistros)
                : $todosRegistros;
            $dados['count'] = count($todosRegistros);
            $dados['page'] = 'all';
            $dados['totalPages'] = 1;
        }
    }

    // Alguns status não vêm na consulta padrão da API; busque-os separadamente.
    if ($base_endpoint === 'chamados') {
        foreach (['Resolvido', 'Enviado Atualização'] as $status) {
            $query = http_build_query(['status' => $status]);
            $dadosStatus = buscarTodasPaginas('/api/chamados?' . $query, $cookieStr, 'chamados');

            if ($dadosStatus && isset($dadosStatus['chamados']) && is_array($dadosStatus['chamados'])) {
                $dados['chamados'] = mesclarChamados(array_merge($dados['chamados'] ?? [], $dadosStatus['chamados']));
            }
        }

        $dados['count'] = count($dados['chamados'] ?? []);
        $dados['__lista_completa'] = true;
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
