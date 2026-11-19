<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/ufs_brasil.php';

header('Content-Type: application/json; charset=utf-8');

function responderConsultaSintegra(int $status, array $resposta): void
{
    http_response_code($status);
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function obterMensagemErroSintegra(array $dados, int $status): string
{
    foreach (['error', 'error_message', 'message', 'mensagem', 'detail'] as $campo) {
        if (!isset($dados[$campo])) {
            continue;
        }
        $valor = $dados[$campo];
        if (is_string($valor) && trim($valor) !== '') {
            return trim($valor);
        }
        if (is_array($valor)) {
            foreach (['message', 'mensagem', 'detail'] as $campoMensagem) {
                if (isset($valor[$campoMensagem]) && is_string($valor[$campoMensagem]) && trim($valor[$campoMensagem]) !== '') {
                    return trim($valor[$campoMensagem]);
                }
            }
        }
    }

    return 'A SintegrAPI respondeu com HTTP ' . $status . '.';
}

function respostaConsultaSintegraValida(int $status, ?array $dados): bool
{
    if ($status < 200 || $status >= 300 || $dados === null) {
        return false;
    }
    if (array_key_exists('success', $dados) && $dados['success'] === false) {
        return false;
    }
    if (array_key_exists('error', $dados) && $dados['error'] !== false && $dados['error'] !== null && $dados['error'] !== '') {
        return false;
    }
    return true;
}

function executarConsultasSintegra(string $cnpj, string $uf, string $apiKey): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('A extensão cURL do PHP é necessária para consultar a SintegrAPI.');
    }

    $cnpjCodificado = rawurlencode($cnpj);
    $sintegraQuery = http_build_query([
        'uf' => $uf,
        'cache' => 7,
        'cache_strategy' => 'ONLINE_PREFERENCIAL',
        'error_fallback' => 'true',
    ]);
    $urls = [
        'sintegra' => 'https://api.sintegrapi.com.br/consultas/v2/sintegra/' . $cnpjCodificado . '?' . $sintegraQuery,
        'simples' => 'https://api.sintegrapi.com.br/consultas/v2/simples/' . $cnpjCodificado . '?cache=25',
    ];

    $multiHandle = curl_multi_init();
    $handles = [];
    foreach ($urls as $chave => $url) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'x-api-key: ' . $apiKey,
            ],
        ]);
        curl_multi_add_handle($multiHandle, $handle);
        $handles[$chave] = $handle;
    }

    $estado = CURLM_OK;
    do {
        $estado = curl_multi_exec($multiHandle, $ativos);
        if ($estado !== CURLM_OK) {
            break;
        }
        if ($ativos > 0 && curl_multi_select($multiHandle, 1.0) === -1) {
            usleep(100000);
        }
    } while ($ativos > 0);

    $respostas = [];
    foreach ($handles as $chave => $handle) {
        $corpo = curl_multi_getcontent($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($handle);
        $dados = is_string($corpo) ? json_decode($corpo, true) : null;
        $dados = is_array($dados) ? $dados : null;
        $valida = $estado === CURLM_OK && $erroCurl === '' && respostaConsultaSintegraValida($status, $dados);
        $respostas[$chave] = [
            'sucesso' => $valida,
            'http_status' => $status,
            'dados' => $dados,
            'erro' => $valida ? null : ($erroCurl !== '' ? 'Falha de comunicação com a SintegrAPI.' : obterMensagemErroSintegra($dados ?? [], $status)),
        ];
        curl_multi_remove_handle($multiHandle, $handle);
        curl_close($handle);
    }
    curl_multi_close($multiHandle);

    return $respostas;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderConsultaSintegra(405, ['sucesso' => false, 'erro' => 'Método não permitido.']);
}
if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    responderConsultaSintegra(400, ['sucesso' => false, 'erro' => 'Requisição inválida.']);
}

$entrada = json_decode(file_get_contents('php://input'), true);
$idCliente = is_array($entrada) ? trim((string)($entrada['id_cliente'] ?? '')) : '';
if (!ctype_digit($idCliente) || (int)$idCliente <= 0) {
    responderConsultaSintegra(400, ['sucesso' => false, 'erro' => 'Cliente inválido.']);
}

try {
    $stmt = $pdo->prepare('SELECT fantasia, cnpj, uf FROM clientes WHERE id_cliente = ? LIMIT 1');
    $stmt->execute([(int)$idCliente]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cliente) {
        responderConsultaSintegra(404, ['sucesso' => false, 'erro' => 'Cliente não encontrado.']);
    }

    $cnpj = preg_replace('/\D+/', '', (string)($cliente['cnpj'] ?? ''));
    if (!is_string($cnpj) || strlen($cnpj) !== 14) {
        responderConsultaSintegra(422, ['sucesso' => false, 'erro' => 'O cadastro precisa ter um CNPJ válido antes da consulta.']);
    }

    $uf = strtoupper(trim((string)($cliente['uf'] ?? '')));
    $uf = $uf !== '' && array_key_exists($uf, listarUfsBrasil()) ? $uf : 'BR';
    try {
        $apiKey = (string)appRequiredConfig('sintegra', 'api_key');
    } catch (Throwable $erroConfiguracao) {
        error_log('Chave da SintegrAPI não configurada: ' . $erroConfiguracao->getMessage());
        responderConsultaSintegra(503, ['sucesso' => false, 'erro' => 'A chave da SintegrAPI ainda não está configurada no servidor.']);
    }

    $chaveCache = hash('sha256', $cnpj . '|' . $uf);
    $cacheSessao = $_SESSION['sintegra_consultas'][$chaveCache] ?? null;
    if (is_array($cacheSessao) && isset($cacheSessao['expira_em'], $cacheSessao['resultado']) && $cacheSessao['expira_em'] > time()) {
        $resultado = $cacheSessao['resultado'];
        $resultado['cache_sessao'] = true;
        $resultado['cliente'] = $cliente['fantasia'];
        session_write_close();
        responderConsultaSintegra(200, ['sucesso' => true, 'dados' => $resultado]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $consultas = executarConsultasSintegra($cnpj, $uf, $apiKey);
    if (!$consultas['sintegra']['sucesso'] && !$consultas['simples']['sucesso']) {
        $mensagem = $consultas['sintegra']['erro'] ?: $consultas['simples']['erro'];
        responderConsultaSintegra(502, [
            'sucesso' => false,
            'erro' => $mensagem ?: 'Não foi possível concluir as consultas na SintegrAPI.',
            'consultas' => $consultas,
        ]);
    }

    $resultado = [
        'cliente' => $cliente['fantasia'],
        'cnpj' => $cnpj,
        'uf_consultada' => $uf,
        'consultado_em' => date(DATE_ATOM),
        'cache_sessao' => false,
        'sintegra' => $consultas['sintegra'],
        'simples' => $consultas['simples'],
    ];

    if ($consultas['sintegra']['sucesso'] && $consultas['simples']['sucesso']) {
        session_start();
        if (!isset($_SESSION['sintegra_consultas']) || !is_array($_SESSION['sintegra_consultas'])) {
            $_SESSION['sintegra_consultas'] = [];
        }
        $_SESSION['sintegra_consultas'][$chaveCache] = [
            'expira_em' => time() + 86400,
            'resultado' => $resultado,
        ];
        if (count($_SESSION['sintegra_consultas']) > 10) {
            uasort($_SESSION['sintegra_consultas'], static function ($a, $b) {
                return ($a['expira_em'] ?? 0) <=> ($b['expira_em'] ?? 0);
            });
            $_SESSION['sintegra_consultas'] = array_slice($_SESSION['sintegra_consultas'], -10, null, true);
        }
        session_write_close();
    }

    responderConsultaSintegra(200, ['sucesso' => true, 'dados' => $resultado]);
} catch (Throwable $erro) {
    error_log('Falha ao consultar SintegrAPI para cliente #' . (int)$idCliente . ': ' . $erro->getMessage());
    responderConsultaSintegra(500, ['sucesso' => false, 'erro' => 'Ocorreu uma falha ao consultar os dados fiscais.']);
}
