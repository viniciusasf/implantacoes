<?php
require_once 'config.php';

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: clientes.php");
    exit;
}

// Receber dados do formulário
$id_cliente = $_POST['id_cliente'] ?? null;
$id_observacao = $_POST['id_observacao'] ?? null;
$titulo = $_POST['titulo'] ?? '';
$conteudo = $_POST['conteudo'] ?? '';
$tipo = $_POST['tipo'] ?? 'INFORMAÇÃO';
$tags = $_POST['tags'] ?? '';
$registrado_por = $_SESSION['usuario_nome'] ?? 'Sistema';

if (!$id_cliente) {
    header("Location: clientes.php");
    exit;
}

// Validar dados
if (empty($titulo) || empty($conteudo)) {
    $_SESSION['error'] = 'Título e conteúdo são obrigatórios!';
    header("Location: treinamentos_cliente.php?id_cliente=" . $id_cliente);
    exit;
}

try {
    // Verificar se a tabela existe, se não, criar
    $stmtCheck = $pdo->query("SHOW TABLES LIKE 'observacoes_cliente'");
    if (!$stmtCheck->rowCount()) {
        // Criar tabela se não existir
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

    if ($id_observacao) {
        // Atualizar observação existente
        $stmt = $pdo->prepare("
            UPDATE observacoes_cliente 
            SET titulo = ?, conteudo = ?, tipo = ?, tags = ?
            WHERE id_observacao = ? AND id_cliente = ?
        ");
        $stmt->execute([$titulo, $conteudo, $tipo, $tags, $id_observacao, $id_cliente]);
        $_SESSION['success'] = 'Observação atualizada com sucesso!';
    } else {
        // Inserir nova observação
        $stmt = $pdo->prepare("
            INSERT INTO observacoes_cliente (id_cliente, titulo, conteudo, tipo, tags, registrado_por)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id_cliente, $titulo, $conteudo, $tipo, $tags, $registrado_por]);
        $_SESSION['success'] = 'Observação cadastrada com sucesso!';
    }
} catch (PDOException $e) {
    $_SESSION['error'] = 'Erro ao salvar observação: ' . $e->getMessage();
}

// Redirecionar de volta para a página do cliente
header("Location: treinamentos_cliente.php?id_cliente=" . $id_cliente);
exit;
