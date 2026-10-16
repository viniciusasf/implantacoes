<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function responderAtualizacao(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderAtualizacao(405, ['sucesso' => false, 'erro' => 'Método não permitido.']);
}

$entrada = json_decode(file_get_contents('php://input'), true);
$idApi = trim((string)($entrada['id_cliente_api'] ?? ''));
$dados = $entrada['dados'] ?? null;
if (!ctype_digit($idApi) || !is_array($dados)) {
    responderAtualizacao(400, ['sucesso' => false, 'erro' => 'Dados do cliente inválidos.']);
}

$stmtCliente = $pdo->prepare('SELECT id_cliente FROM clientes WHERE id_cliente_api = ? LIMIT 1');
$stmtCliente->execute([$idApi]);
$idClienteLocal = $stmtCliente->fetchColumn();
if (!$idClienteLocal) {
    responderAtualizacao(404, ['sucesso' => false, 'erro' => 'Cliente local não encontrado para este ID da API.']);
}

$camposTexto = [
    'fantasia' => ['fantasia', 255],
    'vendedor' => ['vendedor', 150],
    'servidor' => ['servidor', 100],
    'serial' => ['serial', 100],
    'cnpj' => ['cnpj', 30],
];
$atualizacoes = [];
$valores = [];
foreach ($camposTexto as $chave => [$coluna, $limite]) {
    if (!array_key_exists($chave, $dados) || !is_scalar($dados[$chave])) continue;
    $valor = trim((string)$dados[$chave]);
    if ($valor === '') continue;
    $atualizacoes[] = "`{$coluna}` = ?";
    $valores[] = function_exists('mb_substr') ? mb_substr($valor, 0, $limite, 'UTF-8') : substr($valor, 0, $limite);
}

if (array_key_exists('num_licencas', $dados) && is_scalar($dados['num_licencas']) && preg_match('/^\d+$/', (string)$dados['num_licencas'])) {
    $atualizacoes[] = '`num_licencas` = ?';
    $valores[] = max(0, (int)$dados['num_licencas']);
}

if (!$atualizacoes) {
    responderAtualizacao(400, ['sucesso' => false, 'erro' => 'A API não forneceu campos para atualizar.']);
}

try {
    $valores[] = (int)$idClienteLocal;
    $stmtUpdate = $pdo->prepare('UPDATE clientes SET ' . implode(', ', $atualizacoes) . ' WHERE id_cliente = ?');
    $stmtUpdate->execute($valores);
    responderAtualizacao(200, ['sucesso' => true, 'mensagem' => 'Cadastro local atualizado com os dados disponíveis da API GestãoPro.']);
} catch (Throwable $e) {
    error_log('Falha ao sincronizar cliente GestãoPro: ' . $e->getMessage());
    responderAtualizacao(500, ['sucesso' => false, 'erro' => 'Não foi possível salvar os dados no cadastro local.']);
}
