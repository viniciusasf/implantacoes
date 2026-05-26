<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: radar_emergencia.php");
    exit;
}

$id_cliente = (int)($_POST['id_cliente'] ?? 0);
$registrado_por = $_SESSION['usuario_nome'] ?? 'Sistema (Radar)';

if ($id_cliente <= 0) {
    $_SESSION['error'] = 'Cliente invalido para retirada de sem retorno.';
    header("Location: radar_emergencia.php");
    exit;
}

try {
    $stmtCliente = $pdo->prepare("SELECT fantasia FROM clientes WHERE id_cliente = ?");
    $stmtCliente->execute([$id_cliente]);
    $cliente_nome = (string)($stmtCliente->fetchColumn() ?: 'Cliente');

    $stmtDatas = $pdo->prepare("
        SELECT
            (
                SELECT MAX(data_observacao)
                FROM observacoes_cliente
                WHERE id_cliente = ?
                  AND tags LIKE '%SEM_RETORNO%'
            ) AS ultimo_sem_retorno_data,
            (
                SELECT MAX(data_observacao)
                FROM observacoes_cliente
                WHERE id_cliente = ?
                  AND tags LIKE '%RETIRADO_SEM_RETORNO%'
            ) AS ultimo_retirado_sem_retorno_data
    ");
    $stmtDatas->execute([$id_cliente, $id_cliente]);
    $datas = $stmtDatas->fetch(PDO::FETCH_ASSOC) ?: [];

    $ultimo_sem_retorno = !empty($datas['ultimo_sem_retorno_data']) ? strtotime($datas['ultimo_sem_retorno_data']) : 0;
    $ultimo_retirado = !empty($datas['ultimo_retirado_sem_retorno_data']) ? strtotime($datas['ultimo_retirado_sem_retorno_data']) : 0;

    if ($ultimo_sem_retorno <= 0 || $ultimo_retirado >= $ultimo_sem_retorno) {
        $_SESSION['error'] = 'Este cliente nao esta com sem retorno ativo.';
        header("Location: radar_emergencia.php");
        exit;
    }

    $titulo = 'Cliente retirado da fila de sem retorno';
    $conteudo = 'Cliente removido da fila de sem retorno no Radar de Emergencia.';
    $conteudo .= ' Cliente: ' . $cliente_nome . '.';

    $stmtInsert = $pdo->prepare("
        INSERT INTO observacoes_cliente (id_cliente, titulo, conteudo, tipo, tags, registrado_por)
        VALUES (?, ?, ?, 'AJUSTE', 'RADAR,RETIRADO_SEM_RETORNO', ?)
    ");
    $stmtInsert->execute([$id_cliente, $titulo, $conteudo, $registrado_por]);

    $_SESSION['success'] = 'Cliente retirado da fila de sem retorno.';
} catch (PDOException $e) {
    $_SESSION['error'] = 'Erro ao retirar sem retorno: ' . $e->getMessage();
}

header("Location: radar_emergencia.php");
exit;
