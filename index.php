<?php
require_once 'config.php';


// Consulta para clientes sem interação há mais de 3 dias
// Critério: (Data do último treinamento OU data_inicio) < (Hoje - 3 dias) 
// E não possui nenhum treinamento PENDENTE futuro.
$sql_inatividade = "
    SELECT c.id_cliente, c.fantasia, MAX(t.data_treinamento) as última_data, c.data_inicio
    FROM clientes c
    LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
    WHERE (c.data_fim IS NULL OR c.data_fim = '0000-00-00')
    AND c.id_cliente NOT IN (
        SELECT DISTINCT id_cliente FROM treinamentos WHERE status = 'PENDENTE'
    )
    GROUP BY c.id_cliente
    HAVING 
        (MAX(t.data_treinamento) < DATE_SUB(CURDATE(), INTERVAL 3 DAY)) OR 
        (MAX(t.data_treinamento) IS NULL AND c.data_inicio < DATE_SUB(CURDATE(), INTERVAL 3 DAY))
    ORDER BY última_data ASC";

$clientes_inativos = $pdo->query($sql_inatividade)->fetchAll();



// 1. Lógica de processamento (deve vir antes de qualquer HTML/Header)
if (isset($_GET['encerrar_id'])) {
    $id = $_GET['encerrar_id'];
    $data_hoje = date('Y-m-d H:i:s');
    
    $stmt = $pdo->prepare("UPDATE treinamentos SET status = 'Resolvido', data_treinamento_encerrado = ? WHERE id_treinamento = ?");
    $stmt->execute([$data_hoje, $id]);
    
    header("Location: index.php?msg=Treinamento encerrado com sucesso");
    exit;
}




include 'header.php';

// 2. Buscar estatísticas para os cards
$total_clientes = $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
$total_treinamentos = $pdo->query("SELECT COUNT(*) FROM treinamentos")->fetchColumn();
$treinamentos_pendentes = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE status = 'PENDENTE'")->fetchColumn();
$treinamentos_resolvidos = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE status = 'Resolvido'")->fetchColumn();

// 3. Consulta de treinamentos pendentes (incluindo Servidor e Vendedor)
$sql = "SELECT t.*, c.fantasia as cliente_nome, c.servidor, c.vendedor 
        FROM treinamentos t 
        JOIN clientes c ON t.id_cliente = c.id_cliente 
        WHERE t.status = 'PENDENTE'
        ORDER BY t.data_treinamento ASC, t.data_criacao DESC 
        LIMIT 10";

$proximos_atendimentos = $pdo->query($sql)->fetchAll();
$hoje_data = date('Y-m-d');
?>

<div class="mb-4">
    <h2 class="fw-bold">Dashboard</h2>
    <p class="text-muted">Bem-vindo ao sistema de gestão de implantações.</p>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle me-2"></i> <?= htmlspecialchars($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-5">
    <div class="col-md-3">
        <div class="card h-100 p-3 border-0 shadow-sm">
            <div class="d-flex align-items-center">
                <div class="bg-primary bg-opacity-10 p-3 rounded-3 me-3">
                    <i class="bi bi-building text-primary fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-1 small fw-bold">CLIENTES</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $total_clientes; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 p-3 border-0 shadow-sm">
            <div class="d-flex align-items-center">
                <div class="bg-info bg-opacity-10 p-3 rounded-3 me-3">
                    <i class="bi bi-mortarboard text-info fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-1 small fw-bold">TREINAMENTOS</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $total_treinamentos; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 p-3 border-0 shadow-sm">
            <div class="d-flex align-items-center">
                <div class="bg-warning bg-opacity-10 p-3 rounded-3 me-3">
                    <i class="bi bi-clock-history text-warning fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-1 small fw-bold">PENDENTES</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $treinamentos_pendentes; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 p-3 border-0 shadow-sm">
            <div class="d-flex align-items-center">
                <div class="bg-success bg-opacity-10 p-3 rounded-3 me-3">
                    <i class="bi bi-check2-circle text-success fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-1 small fw-bold">RESOLVIDOS</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $treinamentos_resolvidos; ?></h3>
                </div>
            </div>
        </div>
    </div>
</div>








<div class="row">
    <div class="col-lg-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                <h5 class="mb-0 fw-bold text-dark">Próximos Atendimentos (Pendentes)</h5>
                <a href="treinamentos.php" class="btn btn-sm btn-light text-primary fw-bold">Ver todos</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">Data Agendada</th>
                                <th>Cliente</th>
                                <th>Servidor</th>
                                <th>Vendedor</th>
                                <th>Tema</th>
                                <th class="text-center">Status</th>
                                <th class="text-end pe-4">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($proximos_atendimentos)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">Nenhum treinamento pendente.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($proximos_atendimentos as $t): 
                                    $data_treino = date('Y-m-d', strtotime($t['data_treinamento']));
                                    $e_hoje = ($data_treino == $hoje_data);
                                    $bg_class = $e_hoje ? 'table-info' : '';
                                ?>
                                <tr class="<?= $bg_class ?>">
                                    <td class="ps-4">
                                        <div class="small fw-bold">
                                            <?= date('d/m/Y H:i', strtotime($t['data_treinamento'])) ?>
                                            <?php if($e_hoje): ?>
                                                <span class="badge bg-primary-subtle text-primary badge-border">HOJE</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="fw-bold"><?= htmlspecialchars($t['cliente_nome']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['servidor'] ?? '---') ?></span></td>
                                    <td><span class="small text-muted"><?= htmlspecialchars($t['vendedor'] ?? '---') ?></span></td>
                                    <td><span class="small text-muted"><?= htmlspecialchars($t['tema']) ?></span></td>
                                    <td class="text-center">
                                        <span class="badge bg-primary-subtle text-primary badge-border">
                                            <?= $t['status'] ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="index.php?encerrar_id=<?= $t['id_treinamento'] ?>" 
                                           class="btn btn-sm btn-outline-success" 
                                           onclick="return confirm('Deseja marcar como RESOLVIDO?')">
                                            <i class="bi bi-check-lg"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>