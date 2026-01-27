<?php
require_once 'config.php';
include 'header.php';

// Buscar estatísticas
$total_clientes = $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
$total_treinamentos = $pdo->query("SELECT COUNT(*) FROM treinamentos")->fetchColumn();
$treinamentos_pendentes = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE status = 'PENDENTE'")->fetchColumn();
$treinamentos_resolvidos = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE status = 'RESOLVIDO'")->fetchColumn();

// Consulta focada apenas em treinamentos PENDENTES para o Dashboard
// Ordenados do mais próximo (mais cedo) para o mais distante.
// 3. Consulta Ajustada: Trazendo Servidor e Vendedor da tabela clientes
$sql = "SELECT t.*, c.fantasia as cliente_nome, c.servidor, c.vendedor 
        FROM treinamentos t 
        JOIN clientes c ON t.id_cliente = c.id_cliente 
        WHERE t.status = 'PENDENTE'
        ORDER BY t.data_treinamento ASC, t.data_criacao DESC 
        LIMIT 10";

$proximos_atendimentos = $pdo->query($sql)->fetchAll();

$hoje = date('Y-m-d');
?>

<div class="mb-4">
    <h2 class="fw-bold">Dashboard</h2>
    <p class="text-muted">Bem-vindo ao sistema de gestão de implantações.</p>
</div>

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
                <div class="table-responsive table-scroll-container">
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
                                    <td colspan="4" class="text-center py-4 text-muted">
                                        <i class="bi bi-check-all fs-2 d-block mb-2"></i>
                                        Nenhum treinamento pendente para os próximos dias.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($proximos_atendimentos as $t):
                                    $data_agendada = $t['data_treinamento'] ? date('Y-m-d', strtotime($t['data_treinamento'])) : null;
                                    $is_hoje = ($data_agendada == $hoje);
                                    $row_class = $is_hoje ? 'table-primary bg-opacity-10' : '';
                                ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="small fw-bold text-nowrap">
                                                <i class="bi bi-calendar-event me-1 text-primary"></i>
                                                <?= date('d/m/Y H:i', strtotime($t['data_treinamento'])) ?>
                                            </div>
                                        </td>
                                        <td class="fw-bold"><?= htmlspecialchars($t['cliente_nome']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['servidor'] ?? '---') ?></span></td>
                                        <td><span class="small text-muted"><?= htmlspecialchars($t['vendedor'] ?? '---') ?></span></td>
                                        <td><span class="small"><?= htmlspecialchars($t['tema']) ?></span></td>
                                        <td class="text-center">
                                            <span class="badge rounded-pill bg-warning-subtle text-warning px-3">
                                                <?= $t['status'] ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-group shadow-sm">
                                                <a href="?encerrar_id=<?= $t['id_treinamento'] ?>"
                                                    class="btn btn-sm btn-outline-success"
                                                    onclick="return confirm('Deseja marcar este treinamento como RESOLVIDO?')"
                                                    title="Encerrar Treinamento">
                                                    <i class="bi bi-check-lg"></i>
                                                </a>
                                                <a href="treinamentos.php" class="btn btn-sm btn-outline-primary" title="Ver Detalhes">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </div>
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

<script>
    document.querySelectorAll('.sync-calendar').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const icon = this.querySelector('i');
            const originalClass = icon.className;

            // Feedback visual de carregamento
            icon.className = 'spinner-border spinner-border-sm';
            this.disabled = true;

            fetch(`google_calendar_sync.php?id_treinamento=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + data.message);
                        this.classList.remove('btn-outline-danger');
                        this.classList.add('btn-success');
                        icon.className = 'bi bi-check-lg';
                    } else {
                        if (data.auth_url) {
                            if (confirm('Autenticação necessária. Deseja abrir a página de autorização do Google?')) {
                                window.open(data.auth_url, '_blank');
                            }
                        } else {
                            alert('❌ Erro: ' + data.message);
                        }
                        icon.className = originalClass;
                        this.disabled = false;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('❌ Erro ao conectar com o servidor.');
                    icon.className = originalClass;
                    this.disabled = false;
                });
        });
    });
</script>

<?php include 'footer.php'; ?>