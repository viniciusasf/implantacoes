<?php
require_once 'config.php';


$id_cliente = isset($_GET['id_cliente']) ? $_GET['id_cliente'] : null;

if (!$id_cliente) {
    header("Location: clientes.php");
    exit;
}

// 1. Busca os dados do cliente para o cabeçalho
$stmtCli = $pdo->prepare("SELECT fantasia FROM clientes WHERE id_cliente = ?");
$stmtCli->execute([$id_cliente]);
$cliente = $stmtCli->fetch();

// 2. BUSCA OS TREINAMENTOS (Aqui estava o erro: faltava definir o $stmt)
// Certifique-se de que os nomes das colunas abaixo batem com seu banco
$sql = "SELECT * FROM treinamentos WHERE id_cliente = ? ORDER BY data_treinamento DESC";
$stmt = $pdo->prepare($sql); // <--- ESTA LINHA É ESSENCIAL
$stmt->execute([$id_cliente]);
$treinamentos = $stmt->fetchAll();

// 3. CONTAGEM
$total_treinamentos = count($treinamentos);


$id_cliente = isset($_GET['id_cliente']) ? $_GET['id_cliente'] : null;

if (!$id_cliente) {
    header("Location: clientes.php");
    exit;
}

// Busca os dados do cliente para o cabeçalho
$stmtCli = $pdo->prepare("SELECT fantasia FROM clientes WHERE id_cliente = ?");
$stmtCli->execute([$id_cliente]);
$cliente = $stmtCli->fetch();

// Busca todos os treinamentos realizados, organizados pela data (mais recente primeiro)
// Ajuste os nomes das colunas 'treinamentos' conforme sua tabela real
$stmt = $pdo->prepare("SELECT * FROM treinamentos WHERE id_cliente = ? ORDER BY data_treinamento DESC");
$stmt->execute([$id_cliente]);
$treinamentos = $stmt->fetchAll();

include 'header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold">
                Histórico de Treinamentos
                <span class="badge bg-primary rounded-pill ms-2" style="font-size: 0.5em; vertical-align: middle;">
                    <?= $total_treinamentos ?>
                </span>
            </h4>
            <p class="text-muted">Cliente: <?= htmlspecialchars($cliente['fantasia']) ?></p>
        </div>
        <a href="clientes.php" class="btn btn-outline-secondary">Voltar</a>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>Data Treinamento</th>
                        <th>Tema</th>
                        <th>Observações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($treinamentos) > 0): ?>
                        <?php foreach ($treinamentos as $t): ?>
                            <tr>
                                <td class="fw-bold"><?= date('d/m/Y', strtotime($t['data_treinamento'])) ?></td>
                                <td><?= htmlspecialchars($t['tema']) ?></td>
                                <td class="small text-muted"><?= nl2br(htmlspecialchars($t['observacoes'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center py-4 text-muted">Nenhum treinamento registrado para este cliente.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>