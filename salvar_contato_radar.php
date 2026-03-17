<?php
require_once 'config.php';

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: radar_emergencia.php");
    exit;
}

// Receber dados do formulário
$id_cliente = $_POST['id_cliente'] ?? null;
$titulo = $_POST['titulo'] ?? 'Contato Radar de Emergência';
$conteudo = $_POST['conteudo'] ?? '';
$registrado_por = $_SESSION['usuario_nome'] ?? 'Sistema (Radar)';

// Validação básica
if (!$id_cliente) {
    header("Location: radar_emergencia.php");
    exit;
}

if (empty($titulo) || empty($conteudo)) {
    $_SESSION['error'] = 'Título e mensagem são obrigatórios!';
    header("Location: radar_emergencia.php");
    exit;
}

try {
    // 1. Garantir que a tabela existe (fallback de segurança igual ao salvar_observacao)
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
    
    // 2. Inserir a observação como tipo = 'CONTATO' e Tag = 'RADAR'
    $stmt = $pdo->prepare("
        INSERT INTO observacoes_cliente (id_cliente, titulo, conteudo, tipo, tags, registrado_por)
        VALUES (?, ?, ?, 'CONTATO', 'RADAR', ?)
    ");
    $stmt->execute([$id_cliente, $titulo, $conteudo, $registrado_por]);
    
    $_SESSION['success'] = 'Mensagem registrada com sucesso! Cliente transferido para a aba Aguardando Resposta.';

} catch (PDOException $e) {
    $_SESSION['error'] = 'Erro ao salvar o contato: ' . $e->getMessage();
}

// Retornar para o Radar de Emergência
header("Location: radar_emergencia.php");
exit;
