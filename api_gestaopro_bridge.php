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

$ENDPOINTS_VALIDOS = ['implantacoes', 'chamados', 'chamados_cliente', 'uso', 'mesa', 'cliente_detalhes'];
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

/** Adapta a resposta de /api/mesa/utilizacao ao formato consumido pelo dashboard. */
function normalizarUtilizacaoMesa(array $dados, string $idClienteApi): array {
    if (isset($dados['resumo']) || !isset($dados['clientes']) || !is_array($dados['clientes'])) {
        return $dados;
    }

    $registro = null;
    foreach ($dados['clientes'] as $cliente) {
        if (!is_array($cliente)) continue;
        $id = (string)($cliente['idCliente'] ?? $cliente['ID_CLIENTE'] ?? $cliente['ID_CLIENTE_API'] ?? $cliente['id_cliente_api'] ?? $cliente['id_cliente'] ?? $cliente['id'] ?? '');
        if ($id === $idClienteApi) {
            $registro = $cliente;
            break;
        }
    }
    if ($registro === null && count($dados['clientes']) === 1 && is_array($dados['clientes'][0])) {
        $registro = $dados['clientes'][0];
    }
    if ($registro === null) return $dados;

    $ultimoLogin = $registro['ultimoLogin'] ?? $registro['ULTIMO_LOGIN'] ?? $registro['ultimo_login'] ?? null;
    return [
        'seriais' => $registro['seriais'] ?? [],
        'resumo' => [
            'ULTIMO' => $ultimoLogin,
            'LOGINS' => $ultimoLogin ? 1 : null,
            'PAGEVIEWS' => null,
            'USUARIOS' => null,
            'CLIENTES' => null,
            'ATRITOS' => null,
            'ERROS' => null,
            'DERRUBADAS' => null,
        ],
        'diario' => [],
        'modulos' => [],
        'usuarios' => [],
        'rotas' => [],
        'atritos' => [],
        'erros' => [],
        'utilizacao_mesa' => $registro,
    ];
}

function extrairUltimoLoginUtilizacaoMesa(?array $dados, string $idClienteApi): ?string {
    if (!$dados || !isset($dados['clientes']) || !is_array($dados['clientes'])) return null;
    foreach ($dados['clientes'] as $cliente) {
        if (!is_array($cliente)) continue;
        $id = (string)($cliente['idCliente'] ?? $cliente['ID_CLIENTE'] ?? $cliente['ID_CLIENTE_API'] ?? $cliente['id_cliente_api'] ?? $cliente['id_cliente'] ?? $cliente['id'] ?? '');
        if ($id === $idClienteApi) {
            $ultimo = $cliente['ultimoLogin'] ?? $cliente['ULTIMO_LOGIN'] ?? $cliente['ultimo_login'] ?? null;
            return is_string($ultimo) && trim($ultimo) !== '' ? trim($ultimo) : null;
        }
    }
    return null;
}

function obterUltimoAcessoMaisRecente(array $dadosUso, ?string $ultimoLoginMesa): ?string {
    $candidatos = [$ultimoLoginMesa, $dadosUso['resumo']['ULTIMO'] ?? null];
    foreach (($dadosUso['usuarios'] ?? []) as $usuario) {
        if (is_array($usuario)) {
            $candidatos[] = $usuario['ULTIMO'] ?? $usuario['ultimo'] ?? $usuario['ultimoAcesso'] ?? null;
        }
    }

    $maisRecente = null;
    $maiorTimestamp = null;
    foreach ($candidatos as $valor) {
        if (!is_string($valor) || trim($valor) === '') continue;
        $timestamp = strtotime($valor);
        if ($timestamp !== false && ($maiorTimestamp === null || $timestamp > $maiorTimestamp)) {
            $maisRecente = trim($valor);
            $maiorTimestamp = $timestamp;
        }
    }
    return $maisRecente;
}

function obterMapaStatusImplantacao(?string $cookieStr = null, bool $atualizar = false): array {
    $cacheImplantacoes = __DIR__ . '/logs/gp_cache_implantacoes.json';
    $dados = $atualizar ? null : lerCache($cacheImplantacoes);

    if ($dados === null && $cookieStr !== null) {
        try {
            $dados = buscarTodasPaginas('/api/implantacoes', $cookieStr, 'clientes');
            if (is_array($dados)) {
                salvarCache($cacheImplantacoes, $dados);
            }
        } catch (Throwable $e) {
            $dados = null;
        }
    }

    // Se a API estiver temporariamente indisponível, aproveita a última leitura conhecida.
    if ($dados === null) {
        $dados = lerCache($cacheImplantacoes, true);
    }
    if (!is_array($dados)) {
        return [];
    }

    $registros = $dados['clientes'] ?? $dados['implantacoes'] ?? [];
    if (!is_array($registros)) {
        return [];
    }

    $mapa = [];
    foreach ($registros as $registro) {
        if (!is_array($registro)) {
            continue;
        }
        $idApi = trim((string) ($registro['ID_CLIENTE'] ?? $registro['ID_CLIENTE_API'] ?? ''));
        $status = strtoupper(trim((string) ($registro['STATUS_IMPLANTACAO'] ?? '')));
        if ($idApi !== '' && $status !== '') {
            $mapa[$idApi] = $status;
        }
    }

    return $mapa;
}

function filtrarImplantacoesEncerradas(array $dadosUso, array $statusPorIdApi): array {
    if (!isset($dadosUso['clientes']) || !is_array($dadosUso['clientes']) || !$statusPorIdApi) {
        return $dadosUso;
    }

    $dadosUso['clientes'] = array_values(array_filter($dadosUso['clientes'], static function ($cliente) use ($statusPorIdApi) {
        $idApi = trim((string) ($cliente['id_cliente_api'] ?? ''));
        $status = $statusPorIdApi[$idApi] ?? '';
        return $status === '' || strpos($status, 'ENCERRAD') !== 0;
    }));

    return $dadosUso;
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
if ($base_endpoint === 'cliente_detalhes') {
    $idClienteApi = trim((string) ($_GET['id_cliente_api'] ?? ''));
    if ($idClienteApi === '' || !ctype_digit($idClienteApi)) {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'erro' => 'ID do cliente da API invalido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cacheDetalhes = __DIR__ . '/logs/gp_cache_detalhes_cliente_' . $idClienteApi . '.json';
    $forcarDetalhes = !empty($_GET['forcar']);
    $detalhes = !$forcarDetalhes ? lerCache($cacheDetalhes) : null;
    if ($detalhes !== null) {
        echo json_encode(['sucesso' => true, 'origem' => 'cache', 'dados' => $detalhes], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cookieDetalhes = fazerLogin();
    if (!$cookieDetalhes) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'erro' => 'Falha na autenticacao com a API GestaoPro.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $detalhes = buscarEndpoint('/api/clientes/' . rawurlencode($idClienteApi) . '/detalhes', $cookieDetalhes);
    if ($detalhes === null) {
        http_response_code(502);
        echo json_encode(['sucesso' => false, 'erro' => 'Nao foi possivel consultar os detalhes do cliente na API.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    salvarCache($cacheDetalhes, $detalhes);
    echo json_encode(['sucesso' => true, 'origem' => 'api', 'dados' => $detalhes], JSON_UNESCAPED_UNICODE);
    exit;
}

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
        $consultarApi = !empty($_GET['consultar_api']);
        $somenteCache = !empty($_GET['somente_cache']);
        $apenasMesa = !empty($_GET['apenas_mesa']);
        $idClienteApi = !empty($_GET['id_cliente_api']) ? trim((string)$_GET['id_cliente_api']) : null;

        // --- Consulta individual de um cliente por ID API ---
        if ($idClienteApi !== null && ctype_digit($idClienteApi)) {
            $cliCacheFile = __DIR__ . '/logs/gp_cache_uso_cli_' . $idClienteApi . '_' . $dias . '.json';
            if ($forcar && file_exists($cliCacheFile)) @unlink($cliCacheFile);

            $cliCached = lerCache($cliCacheFile, $somenteCache);
            $dadosGerais = lerCache($usoCacheFile, true);

            // 1. Se não estamos forçando e temos cache individual
            if (!$apenasMesa && !$forcar && !$consultarApi && $cliCached !== null && !isset($cliCached['utilizacao_mesa'])) {
                $cliCached = normalizarUtilizacaoMesa($cliCached, $idClienteApi);
                salvarCache($cliCacheFile, $cliCached);
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
            if (!$apenasMesa && !$forcar && !$consultarApi && $dadosGerais !== null && isset($dadosGerais['clientes']) && is_array($dadosGerais['clientes'])) {
                foreach ($dadosGerais['clientes'] as $clItem) {
                    if ((string)($clItem['id_cliente_api'] ?? '') === $idClienteApi && !empty($clItem['dados']) && !isset($clItem['dados']['utilizacao_mesa'])) {
                        $dadosCacheCliente = normalizarUtilizacaoMesa($clItem['dados'], $idClienteApi);
                        salvarCache($cliCacheFile, $dadosCacheCliente);
                        echo json_encode([
                            'sucesso'   => true,
                            'origem'    => 'cache',
                            'gerado_em' => date('d/m/Y H:i:s', filemtime($usoCacheFile)),
                            'id_cliente_api' => $idClienteApi,
                            'dados'     => $dadosCacheCliente,
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }
            }

            if ($somenteCache && !$apenasMesa) {
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

            // A utilização consolidada (incluindo último login) vem do endpoint da mesa.
            // O parâmetro forcar é encaminhado à API quando o usuário solicita atualização.
            if ($apenasMesa) {
                // Botao Uso: consulta exclusivamente a utilizacao consolidada da mesa, sempre forcada.
                $dadosUtilizacaoMesa = buscarEndpoint('/api/mesa/utilizacao?' . http_build_query([
                    'ids' => $idClienteApi,
                    'forcar' => 1,
                ]), $cookieUso);
                if ($dadosUtilizacaoMesa === null) {
                    http_response_code(502);
                    echo json_encode(['sucesso' => false, 'erro' => 'Nao foi possivel obter os dados de utilizacao da mesa.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                $dadosApiCli = normalizarUtilizacaoMesa($dadosUtilizacaoMesa, $idClienteApi);
                $ultimoLoginMesa = extrairUltimoLoginUtilizacaoMesa($dadosUtilizacaoMesa, $idClienteApi)
                    ?? ($dadosUtilizacaoMesa['ultimoLogin'] ?? $dadosUtilizacaoMesa['ULTIMO_LOGIN'] ?? $dadosUtilizacaoMesa['ultimo_login'] ?? null);
                if (!isset($dadosApiCli['resumo']) || !is_array($dadosApiCli['resumo'])) $dadosApiCli['resumo'] = [];
                if (is_string($ultimoLoginMesa) && trim($ultimoLoginMesa) !== '') {
                    $dadosApiCli['resumo']['ULTIMO'] = trim($ultimoLoginMesa);
                }
                if (!isset($dadosApiCli['utilizacao_mesa'])) {
                    $dadosApiCli['utilizacao_mesa'] = $dadosUtilizacaoMesa;
                }

                // Preserva os indicadores detalhados que ja estao em cache e atualiza o login pela mesa.
                if ($dadosGerais !== null && isset($dadosGerais['clientes']) && is_array($dadosGerais['clientes'])) {
                    foreach ($dadosGerais['clientes'] as $clItem) {
                        if ((string)($clItem['id_cliente_api'] ?? '') !== $idClienteApi || !is_array($clItem['dados'] ?? null)) continue;
                        $dadosAnteriores = $clItem['dados'];
                        $resumoAnterior = is_array($dadosAnteriores['resumo'] ?? null) ? $dadosAnteriores['resumo'] : [];
                        $resumoMesa = is_array($dadosApiCli['resumo'] ?? null) ? $dadosApiCli['resumo'] : [];
                        $dadosApiCli = array_merge($dadosAnteriores, $dadosApiCli);
                        $dadosApiCli['resumo'] = array_merge($resumoAnterior, $resumoMesa);
                        $dadosApiCli['resumo']['ULTIMO'] = obterUltimoAcessoMaisRecente($dadosAnteriores, is_string($ultimoLoginMesa) ? $ultimoLoginMesa : null);
                        break;
                    }
                }
            } else {
                $dadosApiCli = buscarEndpoint('/api/clientes/' . rawurlencode($idClienteApi) . '/uso?' . http_build_query(['dias' => $dias]), $cookieUso);
                if ($dadosApiCli === null) {
                    http_response_code(502);
                    echo json_encode(['sucesso' => false, 'erro' => 'Nao foi possivel obter dados de uso do cliente.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                // Os indicadores detalhados permanecem no endpoint de uso; o login geral vem da mesa.
                $dadosUtilizacaoMesa = buscarEndpoint('/api/mesa/utilizacao?' . http_build_query([
                    'ids' => $idClienteApi,
                    'forcar' => $forcar ? 1 : 0,
                ]), $cookieUso);
                $ultimoLoginMesa = extrairUltimoLoginUtilizacaoMesa($dadosUtilizacaoMesa, $idClienteApi);
                if (!isset($dadosApiCli['resumo']) || !is_array($dadosApiCli['resumo'])) $dadosApiCli['resumo'] = [];
                $dadosApiCli['resumo']['ULTIMO'] = obterUltimoAcessoMaisRecente($dadosApiCli, $ultimoLoginMesa);
                if (isset($dadosUtilizacaoMesa['clientes']) && is_array($dadosUtilizacaoMesa['clientes'])) {
                    foreach ($dadosUtilizacaoMesa['clientes'] as $registroMesa) {
                        if (!is_array($registroMesa)) continue;
                        $idMesa = (string)($registroMesa['idCliente'] ?? $registroMesa['ID_CLIENTE'] ?? $registroMesa['ID_CLIENTE_API'] ?? $registroMesa['id_cliente_api'] ?? $registroMesa['id_cliente'] ?? $registroMesa['id'] ?? '');
                        if ($idMesa === $idClienteApi) {
                            $dadosApiCli['utilizacao_mesa'] = $registroMesa;
                            break;
                        }
                    }
                }
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
        // Recalcula caches antigos para completar o ?ltimo login pelo endpoint da mesa.
        if ($usoCached !== null && empty($usoCached['__mesa_utilizacao_enriquecido'])) {
            $usoCached = null;
        }
        if ($usoCached !== null) {
            $cacheImplantacoes = __DIR__ . '/logs/gp_cache_implantacoes.json';
            $statusCacheAtual = is_file($cacheImplantacoes) && (time() - filemtime($cacheImplantacoes)) <= GP_CACHE_TTL;
            $statusPorIdApi = obterMapaStatusImplantacao(null, false);
            if (!$statusCacheAtual && !$somenteCache) {
                $cookieStatus = fazerLogin();
                if ($cookieStatus) {
                    $statusPorIdApi = obterMapaStatusImplantacao($cookieStatus, true) ?: $statusPorIdApi;
                }
            }
            $usoCached = filtrarImplantacoesEncerradas($usoCached, $statusPorIdApi);
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

        // A API da mesa fornece o ?ltimo login consolidado do cliente, sem separar por usu?rio.
        // Consulta lotes pequenos para manter a URL abaixo do limite dos servidores/proxies.
        $idsUso = array_keys($pathsUso);
        $ultimosLoginsMesa = [];
        $registrosUtilizacaoMesa = [];
        foreach (array_chunk($idsUso, 40) as $indiceLote => $idsLote) {
            $queryUtilizacao = http_build_query([
                'ids' => implode(',', $idsLote),
                'forcar' => 1,
            ]);
            $respostaLote = buscarEndpointsEmLote([
                'lote_' . $indiceLote => '/api/mesa/utilizacao?' . $queryUtilizacao,
            ], $cookieUso)['lote_' . $indiceLote] ?? null;
            if (!$respostaLote || !isset($respostaLote['clientes']) || !is_array($respostaLote['clientes'])) {
                continue;
            }
            foreach ($respostaLote['clientes'] as $registroMesa) {
                if (!is_array($registroMesa)) continue;
                $idMesa = trim((string)($registroMesa['idCliente'] ?? $registroMesa['ID_CLIENTE'] ?? $registroMesa['ID_CLIENTE_API'] ?? $registroMesa['id_cliente_api'] ?? $registroMesa['id_cliente'] ?? $registroMesa['id'] ?? ''));
                if ($idMesa === '') continue;
                $registrosUtilizacaoMesa[$idMesa] = $registroMesa;
                $ultimoLogin = $registroMesa['ultimoLogin'] ?? $registroMesa['ULTIMO_LOGIN'] ?? $registroMesa['ultimo_login'] ?? null;
                if (is_string($ultimoLogin) && trim($ultimoLogin) !== '') {
                    $ultimosLoginsMesa[$idMesa] = trim($ultimoLogin);
                }
            }
        }

        $registrosUso = [];
        foreach ($clientesUso as $clienteUso) {
            $idApi = (string)$clienteUso['id_cliente_api'];
            $dadosCliente = $respostasUso[$idApi] ?? null;
            $ultimoLoginMesa = $ultimosLoginsMesa[$idApi] ?? null;
            if (is_array($dadosCliente)) {
                if (!isset($dadosCliente['resumo']) || !is_array($dadosCliente['resumo'])) $dadosCliente['resumo'] = [];
                $dadosCliente['resumo']['ULTIMO'] = obterUltimoAcessoMaisRecente($dadosCliente, $ultimoLoginMesa);
                if (isset($registrosUtilizacaoMesa[$idApi])) $dadosCliente['utilizacao_mesa'] = $registrosUtilizacaoMesa[$idApi];
            } elseif ($ultimoLoginMesa !== null) {
                // Preserva pelo menos o acesso geral mesmo se /uso estiver sem detalhes.
                $dadosCliente = [
                    'resumo' => ['ULTIMO' => $ultimoLoginMesa],
                    'diario' => [],
                    'modulos' => [],
                    'usuarios' => [],
                    'utilizacao_mesa' => $registrosUtilizacaoMesa[$idApi] ?? [],
                ];
            }
            $registrosUso[] = [
                'id_cliente' => (int)$clienteUso['id_cliente'],
                'fantasia' => $clienteUso['fantasia'],
                'id_cliente_api' => $idApi,
                'serial_cadastro' => $clienteUso['serial'],
                'dados' => $dadosCliente,
            ];
        }
        $payloadUso = ['dias' => $dias, 'clientes' => $registrosUso, '__mesa_utilizacao_enriquecido' => true];
        $statusPorIdApi = obterMapaStatusImplantacao($cookieUso, $forcar);
        $payloadUsoFiltrado = filtrarImplantacoesEncerradas($payloadUso, $statusPorIdApi);
        salvarCache($usoCacheFile, $payloadUso);
        echo json_encode(['sucesso' => true, 'origem' => 'api', 'gerado_em' => date('d/m/Y H:i:s'), 'dados' => $payloadUsoFiltrado], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($base_endpoint === 'mesa') {
        $usuario = filter_var($_GET['usuario'] ?? 53, FILTER_VALIDATE_INT) ?: 53;
        $secoes = !empty($_GET['secoes']) ? trim((string)$_GET['secoes']) : 'chamados,clientes';
        $secoesArray = array_values(array_unique(array_filter(array_merge(explode(',', $secoes), ['clientes', 'chamados']))));
        $usaCacheComAgenda = in_array('agenda', $secoesArray, true);
        $sufixoCache = $usaCacheComAgenda ? '_agenda' : '';
        $mesaCacheFile = __DIR__ . "/logs/gp_cache_mesa_{$usuario}{$sufixoCache}.json";
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
        if ($usaCacheComAgenda && (!isset($dadosMesa['agenda']) || !is_array($dadosMesa['agenda']))) {
            $dadosMesa['agenda'] = [];
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
        foreach (['Resolvido', 'Enviado Atualização', 'Aguardando Cliente'] as $status) {
            try {
                $query = http_build_query(['status' => $status]);
                $dadosStatus = buscarTodasPaginas('/api/chamados?' . $query, $cookieStr, 'chamados');

                if ($dadosStatus && isset($dadosStatus['chamados']) && is_array($dadosStatus['chamados'])) {
                    $dados['chamados'] = mesclarChamados(array_merge($dados['chamados'] ?? [], $dadosStatus['chamados']));
                }
            } catch (Throwable $e) {
                // Uma busca complementar com falha não deve descartar a lista geral válida.
                error_log("Falha ao complementar chamados com status '{$status}': " . $e->getMessage());
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
