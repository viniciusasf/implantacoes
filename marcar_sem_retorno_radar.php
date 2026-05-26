<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: radar_emergencia.php");
    exit;
}

$id_cliente = (int)($_POST['id_cliente'] ?? 0);
$registrado_por = $_SESSION['usuario_nome'] ?? 'Sistema (Radar)';

if ($id_cliente <= 0) {
    $_SESSION['error'] = 'Cliente invalido para marcacao de sem retorno.';
    header("Location: radar_emergencia.php");
    exit;
}

try {
    $stmtCheck = $pdo->query("SHOW TABLES LIKE 'observacoes_cliente'");
    if (!$stmtCheck->rowCount()) {
        $pdo->exec("CREATE TABLE observacoes_cliente (
            id_observacao INT PRIMARY KEY AUTO_INCREMENT,
            id_cliente INT NOT NULL,
            titulo VARCHAR(100) NOT NULL,
            conteudo TEXT NOT NULL,
            tipo ENUM('INFORMAÇÃO', 'AJUSTE', 'PROBLEMA', 'MELHORIA', 'ATUALIZAÇÃO', 'CONTATO') DEFAULT 'INFORMAÇÃO',
            tags VARCHAR(255),
            registrado_por VARCHAR(100) DEFAULT 'Sistema',
            data_observacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cliente (id_cliente),
            INDEX idx_tipo (tipo),
            INDEX idx_data (data_observacao),
            FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente) ON DELETE CASCADE
        )");
    }

    $stmtCliente = $pdo->prepare("SELECT fantasia FROM clientes WHERE id_cliente = ?");
    $stmtCliente->execute([$id_cliente]);
    $cliente_nome = (string)($stmtCliente->fetchColumn() ?: 'Cliente');

    $stmtDatas = $pdo->prepare("
        SELECT
            (
                SELECT MAX(data_observacao)
                FROM observacoes_cliente
                WHERE id_cliente = ?
                  AND tipo = 'CONTATO'
            ) AS ultimo_contato_data,
            (
                SELECT MAX(data_observacao)
                FROM observacoes_cliente
                WHERE id_cliente = ?
                  AND tags LIKE '%SEM_RETORNO%'
            ) AS ultimo_sem_retorno_data
    ");
    $stmtDatas->execute([$id_cliente, $id_cliente]);
    $datas = $stmtDatas->fetch(PDO::FETCH_ASSOC) ?: [];

    $ultimo_contato = !empty($datas['ultimo_contato_data']) ? strtotime($datas['ultimo_contato_data']) : 0;
    $ultimo_sem_retorno = !empty($datas['ultimo_sem_retorno_data']) ? strtotime($datas['ultimo_sem_retorno_data']) : 0;

    if ($ultimo_sem_retorno >= $ultimo_contato && $ultimo_sem_retorno > 0) {
        $_SESSION['error'] = 'Este cliente ja esta marcado como sem retorno.';
        header("Location: radar_emergencia.php");
        exit;
    }

    $titulo = 'Cliente sem retorno no Radar de Emergencia';
    $conteudo = 'Cliente marcado como sem retorno para repasse ao responsavel.';
    if ($ultimo_contato > 0) {
        $conteudo .= ' Ultimo contato registrado em ' . date('d/m/Y H:i', $ultimo_contato) . '.';
    }
    $conteudo .= ' Cliente: ' . $cliente_nome . '.';

    $stmtInsert = $pdo->prepare("
        INSERT INTO observacoes_cliente (id_cliente, titulo, conteudo, tipo, tags, registrado_por)
        VALUES (?, ?, ?, 'PROBLEMA', 'RADAR,SEM_RETORNO', ?)
    ");
    $stmtInsert->execute([$id_cliente, $titulo, $conteudo, $registrado_por]);

    $_SESSION['success'] = 'Cliente marcado como sem retorno e movido para a aba correspondente.';
} catch (PDOException $e) {
    $_SESSION['error'] = 'Erro ao marcar sem retorno: ' . $e->getMessage();
}

header("Location: radar_emergencia.php");
exit;
