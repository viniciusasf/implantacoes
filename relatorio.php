<?php
require_once 'config.php';

// 1. LÓGICA DE PROCESSAMENTO: Encerrar treinamento com Observação
// Deve vir antes de qualquer saída HTML
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirmar_encerramento'])) {
    $id = $_POST['id_treinamento'];
    $obs = $_POST['observacoes'];
    $data_hoje = date('Y-m-d H:i:s');
    
    // Esta query requer que a coluna 'observacoes' exista na tabela 'treinamentos'
    $stmt = $pdo->prepare("UPDATE treinamentos SET status = 'Resolvido', data_treinamento_encerrado = ?, observacoes = ? WHERE id_treinamento = ?");
    $stmt->execute([$data_hoje, $obs, $id]);
    
    header("Location: index.php?msg=Treinamento encerrado com sucesso");
    exit;
}

// Consulta para clientes sem interação há mais de 3 dias
$sql_inatividade = "
    SELECT c.id_cliente, c.fantasia, MAX(t.data_treinamento) as última_data, c.data_inicio
    FROM clientes c
    LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
    WHERE (c.data_fim IS NULL OR c.data_fim = '0000-00-00')
    AND c.id_cliente NOT IN (
        SELECT DISTINCT id_cliente FROM treinamentos WHERE status = 'PENDENTE'
    )
    GROUP BY c.id_cliente, c.data_inicio
    HAVING 
        (MAX(t.data_treinamento) < DATE_SUB(CURDATE(), INTERVAL 3 DAY)) OR 
        (MAX(t.data_treinamento) IS NULL AND c.data_inicio < DATE_SUB(CURDATE(), INTERVAL 3 DAY))
    ORDER BY última_data ASC";

$clientes_inativos = $pdo->query($sql_inatividade)->fetchAll();

include 'header.php';

// 2. Buscar estatísticas para os cards
$total_clientes = $pdo->query("SELECT COUNT(*) FROM clientes WHERE (data_fim IS NULL OR data_fim = '0000-00-00')")->fetchColumn();
$total_treinamentos = $pdo->query("SELECT COUNT(*) FROM treinamentos")->fetchColumn();
$treinamentos_pendentes = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE status = 'PENDENTE'")->fetchColumn();
$treinamentos_resolvidos = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE status = 'Resolvido'")->fetchColumn();

// 3. Consulta de treinamentos pendentes
$sql = "SELECT t.*, c.fantasia as cliente_nome, c.servidor, COALESCE(co.nome, c.telefone_ddd) as contato_cliente
        FROM treinamentos t
        JOIN clientes c ON t.id_cliente = c.id_cliente
        LEFT JOIN contatos co ON t.id_contato = co.id_contato
        WHERE t.status = 'PENDENTE'
        ORDER BY t.data_treinamento ASC 
        LIMIT 10";

$proximos_atendimentos = $pdo->query($sql)->fetchAll();
$hoje_data = date('Y-m-d');
?>

<style>
    .totalizador-card {
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .totalizador-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12) !important;
    }
</style>

<div class="mb-4">
    <h2 class="fw-bold">Agendamentos</h2>
    <p class="text-muted">Gestão de Agendamentos.</p>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle me-2"></i> <?= htmlspecialchars($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-5">
    <div class="col-md-3">
        <div class="card totalizador-card h-100 p-3 border-0 shadow-sm border-start border-primary border-4">
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
        <div class="card totalizador-card h-100 p-3 border-0 shadow-sm border-start border-info border-4">
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
        <div class="card totalizador-card h-100 p-3 border-0 shadow-sm border-start border-warning border-4">
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
        <div class="card totalizador-card h-100 p-3 border-0 shadow-sm border-start border-success border-4">
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
        <div class="card shadow-sm border-0 rounded-3 overflow-hidden">
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
                                <th>Contato do cliente</th>
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
                                                <span class="badge bg-primary text-white ms-1">HOJE</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="fw-bold"><?= htmlspecialchars($t['cliente_nome']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['servidor'] ?? '---') ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['contato_cliente'] ?? '---') ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['tema']) ?></span></td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border">
                                            <?= $t['status'] ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-success open-finish-modal"
                                                data-id="<?= $t['id_treinamento'] ?>"
                                                data-cliente="<?= htmlspecialchars($t['cliente_nome']) ?>"
                                                data-tema="<?= htmlspecialchars($t['tema']) ?>">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
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

<div class="modal fade" id="modalEncerrar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold text-dark"><i class="bi bi-journal-check me-2 text-success"></i>Finalizar Atendimento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <input type="hidden" name="id_treinamento" id="modal_id_treinamento">
                <input type="hidden" name="confirmar_encerramento" value="1">
                
                <div class="mb-3 p-3 bg-light rounded-3">
                    <div class="small text-muted mb-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Informações:</div>
                    <div class="fw-bold text-primary" id="modal_cliente_info"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">O que ficou acordado com o cliente?</label>
                    <textarea name="observacoes" class="form-control" rows="4" placeholder="Descreva os detalhes da sessão..." required></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm">Encerrar e Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('.open-finish-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const cliente = this.dataset.cliente;
            const tema = this.dataset.tema;
            
            document.getElementById('modal_id_treinamento').value = id;
            document.getElementById('modal_cliente_info').innerText = cliente + " | " + tema;
            
            const myModal = new bootstrap.Modal(document.getElementById('modalEncerrar'));
            myModal.show();
        });
    });
</script>

<?php include 'footer.php'; ?>
