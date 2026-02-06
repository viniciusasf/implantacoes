<?php
require_once 'config.php';

$id_cliente = isset($_GET['id_cliente']) ? $_GET['id_cliente'] : null;

if (!$id_cliente) {
    header("Location: clientes.php");
    exit;
}

// 1. Busca os dados do cliente para o cabeçalho
$stmtCli = $pdo->prepare("SELECT fantasia, servidor, vendedor FROM clientes WHERE id_cliente = ?");
$stmtCli->execute([$id_cliente]);
$cliente = $stmtCli->fetch();

if (!$cliente) {
    header("Location: clientes.php");
    exit;
}

// 2. Busca todos os treinamentos
$stmt = $pdo->prepare("SELECT * FROM treinamentos WHERE id_cliente = ? ORDER BY data_treinamento DESC, id_treinamento DESC");
$stmt->execute([$id_cliente]);
$treinamentos = $stmt->fetchAll();

// 3. Estatísticas
$total_treinamentos = count($treinamentos);
$treinamentos_realizados = 0;
$treinamentos_pendentes = 0;
$ultimo_treinamento = null;
$primeiro_treinamento = null;

if ($total_treinamentos > 0) {
    foreach ($treinamentos as $t) {
        if ($t['status'] == 'REALIZADO' || empty($t['status'])) {
            $treinamentos_realizados++;
        } elseif ($t['status'] == 'PENDENTE') {
            $treinamentos_pendentes++;
        }
    }

    $ultimo_treinamento = $treinamentos[0]['data_treinamento'] ?? null;
    $primeiro_treinamento = $treinamentos[$total_treinamentos - 1]['data_treinamento'] ?? null;
}

include 'header.php';
?>

<style>
    :root {
        --primary-color: #4361ee;
        --success-color: #06d6a0;
        --warning-color: #ffd166;
        --danger-color: #ef476f;
        --info-color: #118ab2;
    }

    body {
        background-color: #f8f9fa;
    }

    /* HEADER STYLES */
    .client-header {
        background: linear-gradient(135deg, #4361ee 0%, #3a56d4 100%);
        color: white;
        padding: 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
        box-shadow: 0 4px 20px rgba(67, 97, 238, 0.15);
    }

    .client-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }

    .info-item {
        background: rgba(255, 255, 255, 0.1);
        padding: 0.75rem;
        border-radius: 8px;
        backdrop-filter: blur(10px);
    }

    .info-label {
        font-size: 0.8rem;
        opacity: 0.8;
        margin-bottom: 0.25rem;
    }

    .info-value {
        font-size: 1rem;
        font-weight: 600;
    }

    /* STATS CARDS */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 1.25rem;
        border: 1px solid #e9ecef;
        transition: all 0.3s;
        text-align: center;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
    }

    .stat-value {
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 0.5rem;
    }

    .stat-label {
        font-size: 0.85rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-icon {
        font-size: 1.5rem;
        margin-bottom: 0.75rem;
        opacity: 0.7;
    }

    /* TIMELINE STYLES */
    .timeline-container {
        position: relative;
        padding: 1rem 0;
    }

    .timeline-line {
        position: absolute;
        left: 20px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: linear-gradient(to bottom, #4361ee, #06d6a0);
        border-radius: 2px;
    }

    .timeline-item {
        position: relative;
        margin-bottom: 1.5rem;
        padding-left: 50px;
        animation: fadeIn 0.5s ease forwards;
    }

    .timeline-dot {
        position: absolute;
        left: 12px;
        top: 0;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: white;
        border: 3px solid var(--primary-color);
        z-index: 2;
    }

    .timeline-card {
        background: white;
        border-radius: 12px;
        border: 1px solid #e9ecef;
        overflow: hidden;
        transition: all 0.3s;
    }

    .timeline-card:hover {
        border-color: var(--primary-color);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        transform: translateX(5px);
    }

    .timeline-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f3f4;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: linear-gradient(90deg, #f8f9fa, white);
    }

    .timeline-date {
        font-weight: bold;
        color: #4361ee;
        font-size: 1.1rem;
    }

    .timeline-badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-weight: 500;
    }

    .timeline-body {
        padding: 1.25rem;
    }

    .timeline-theme {
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 0.75rem;
        color: #212529;
    }

    .timeline-observations {
        color: #6c757d;
        font-size: 0.9rem;
        line-height: 1.5;
        margin-bottom: 1rem;
    }

    .timeline-footer {
        padding: 0.75rem 1.25rem;
        background: #f8f9fa;
        border-top: 1px solid #e9ecef;
        font-size: 0.8rem;
        color: #6c757d;
    }

    /* EMPTY STATE */
    .empty-state {
        padding: 4rem 2rem;
        text-align: center;
        color: #6c757d;
        background: white;
        border-radius: 12px;
        border: 2px dashed #dee2e6;
    }

    .empty-state-icon {
        font-size: 4rem;
        opacity: 0.3;
        margin-bottom: 1.5rem;
        color: #4361ee;
    }

    /* ACTION BUTTONS */
    .action-buttons {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .btn-add-training {
        background: linear-gradient(135deg, #4361ee 0%, #3a56d4 100%);
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 10px;
        font-weight: 600;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-add-training:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
        color: white;
    }

    .btn-edit-training {
        background: #e9ecef;
        border: none;
        color: #6c757d;
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .btn-edit-training:hover {
        background: #4361ee;
        color: white;
        transform: scale(1.1);
    }

    /* ANIMATIONS */
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .fade-in {
        animation: fadeIn 0.5s ease forwards;
    }

    /* RESPONSIVE */
    @media (max-width: 768px) {
        .client-info-grid {
            grid-template-columns: 1fr;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .timeline-item {
            padding-left: 40px;
        }

        .timeline-line {
            left: 15px;
        }

        .timeline-dot {
            left: 7px;
            width: 16px;
            height: 16px;
        }
    }
</style>

<div class="container py-4">
    <!-- HEADER DO CLIENTE -->
    <div class="client-header">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <a href="clientes.php" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-arrow-left me-1"></i>Voltar
                    </a>
                    <h4 class="fw-bold mb-0">Histórico de Treinamentos</h4>
                </div>

                <h2 class="fw-bold mb-3"><?= htmlspecialchars($cliente['fantasia']) ?></h2>

                <div class="client-info-grid">
                    <div class="info-item">
                        <div class="info-label">Servidor</div>
                        <div class="info-value"><?= htmlspecialchars($cliente['servidor'] ?? 'Não informado') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Vendedor</div>
                        <div class="info-value"><?= htmlspecialchars($cliente['vendedor'] ?? 'Não informado') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">ID Cliente</div>
                        <div class="info-value">#<?= $id_cliente ?></div>
                    </div>
                </div>
            </div>

            <div class="text-end">
                <button class="btn-add-training" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
                    <i class="bi bi-plus-circle"></i>
                    Novo Treinamento
                </button>
            </div>
        </div>
    </div>

    <!-- ESTATÍSTICAS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon text-primary">
                <i class="bi bi-journal-check"></i>
            </div>
            <div class="stat-value"><?= $total_treinamentos ?></div>
            <div class="stat-label">Total de Treinamentos</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon text-success">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="stat-value"><?= $treinamentos_realizados ?></div>
            <div class="stat-label">Realizados</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon text-warning">
                <i class="bi bi-clock"></i>
            </div>
            <div class="stat-value"><?= $treinamentos_pendentes ?></div>
            <div class="stat-label">Pendentes</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon text-info">
                <i class="bi bi-calendar-event"></i>
            </div>
            <div class="stat-value">
                <?php if ($ultimo_treinamento): ?>
                    <?= date('d/m/Y', strtotime($ultimo_treinamento)) ?>
                <?php else: ?>
                    --
                <?php endif; ?>
            </div>
            <div class="stat-label">Último Treinamento</div>
        </div>
    </div>

    <!-- TIMELINE DE TREINAMENTOS -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="fw-bold mb-0">
                <i class="bi bi-clock-history me-2"></i>
                Linha do Tempo dos Treinamentos
            </h5>
        </div>
        <div class="card-body">
            <?php if ($total_treinamentos > 0): ?>
                <div class="timeline-container">
                    <div class="timeline-line"></div>

                    <?php foreach ($treinamentos as $index => $t):
                        $status = $t['status'] ?? 'REALIZADO';
                        $status_color = ($status == 'REALIZADO') ? '#06d6a0' : (($status == 'PENDENTE') ? '#ffc107' : '#4361ee');
                        $status_text = ($status == 'REALIZADO') ? 'Realizado' : (($status == 'PENDENTE') ? 'Pendente' : 'Agendado');

                        // Determinar se é o primeiro ou último
                        $is_first = ($index == $total_treinamentos - 1);
                        $is_last = ($index == 0);

                        // Formatar data
                        $data_br = date('d/m/Y', strtotime($t['data_treinamento']));
                        $data_full = date('d/m/Y H:i', strtotime($t['data_treinamento']));
                    ?>
                        <div class="timeline-item fade-in" style="animation-delay: <?= $index * 0.1 ?>s;">
                            <div class="timeline-dot" style="border-color: <?= $status_color ?>;"></div>

                            <div class="timeline-card">
                                <div class="timeline-header">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="timeline-date">
                                            <i class="bi bi-calendar3 me-1"></i>
                                            <?= $data_br ?>
                                        </div>
                                        <span class="timeline-badge" style="background-color: <?= $status_color ?>20; color: <?= $status_color ?>;">
                                            <?= $status_text ?>
                                        </span>
                                    </div>

                                    <div class="action-buttons">
                                        <button class="btn-edit-training edit-treinamento-btn"
                                            data-id="<?= $t['id_treinamento'] ?>"
                                            data-data_treinamento="<?= $t['data_treinamento'] ?>"
                                            data-tema="<?= htmlspecialchars($t['tema']) ?>"
                                            data-observacoes="<?= htmlspecialchars($t['observacoes']) ?>"
                                            data-status="<?= $t['status'] ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="timeline-body">
                                    <h6 class="timeline-theme">
                                        <i class="bi bi-book me-2"></i>
                                        <?= htmlspecialchars($t['tema']) ?>
                                    </h6>

                                    <?php if (!empty($t['observacoes'])): ?>
                                        <div class="timeline-observations">
                                            <strong class="d-block mb-2" style="color: #495057;">Observações:</strong>
                                            <?= nl2br(htmlspecialchars($t['observacoes'])) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($t['instrutor'])): ?>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <i class="bi bi-person-badge text-muted"></i>
                                            <small class="text-muted">
                                                <strong>Instrutor:</strong> <?= htmlspecialchars($t['instrutor']) ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($t['duracao'])): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-clock text-muted"></i>
                                            <small class="text-muted">
                                                <strong>Duração:</strong> <?= htmlspecialchars($t['duracao']) ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="timeline-footer">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small>
                                            <i class="bi bi-info-circle me-1"></i>
                                            Registro #<?= $t['id_treinamento'] ?>
                                        </small>
                                        <small>
                                            <i class="bi bi-calendar-check me-1"></i>
                                            <?= $data_full ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-journal-x empty-state-icon"></i>
                    <h5 class="fw-bold mb-3">Nenhum treinamento registrado</h5>
                    <p class="mb-4">Este cliente ainda não possui treinamentos cadastrados.</p>
                    <button class="btn-add-training" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
                        <i class="bi bi-plus-circle me-2"></i>
                        Cadastrar Primeiro Treinamento
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL DE TREINAMENTO -->
<!-- MODAL DE TREINAMENTO - REPLICADO DE treinamentos.php -->
<div class="modal fade" id="modalTreinamento" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="salvar_treinamento.php" class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <input type="hidden" name="id_cliente" value="<?= $id_cliente ?>">
            <input type="hidden" name="id_treinamento" id="id_treinamento">

            <div class="modal-header border-0 px-4 pt-4">
                <h5 class="fw-bold" id="modalTreinamentoTitle">Novo Treinamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body px-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Cliente</label>
                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($cliente['fantasia']) ?>" readonly>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Data Treinamento <span class="text-danger">*</span></label>
                        <input type="date" name="data_treinamento" id="data_treinamento" class="form-control" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Tema <span class="text-danger">*</span></label>
                        <input type="text" name="tema" id="tema" class="form-control" placeholder="Ex: Treinamento inicial" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="REALIZADO">Realizado</option>
                            <option value="PENDENTE">Pendente</option>
                            <option value="AGENDADO">Agendado</option>
                            <option value="CANCELADO">Cancelado</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Instrutor</label>
                        <input type="text" name="instrutor" id="instrutor" class="form-control" placeholder="Nome do instrutor">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Duração</label>
                        <input type="text" name="duracao" id="duracao" class="form-control" placeholder="Ex: 2 horas">
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-bold">Observações</label>
                        <textarea name="observacoes" id="observacoes" class="form-control" rows="4" placeholder="Detalhes do treinamento..."></textarea>
                    </div>

                    <!-- CAMPOS ADICIONAIS COMUNS EM treinamentos.php -->
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Tipo de Treinamento</label>
                        <select name="tipo" id="tipo" class="form-select">
                            <option value="INICIAL">Inicial</option>
                            <option value="RECICLAGEM">Reciclagem</option>
                            <option value="AVANÇADO">Avançado</option>
                            <option value="PERSONALIZADO">Personalizado</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Participantes</label>
                        <input type="number" name="participantes" id="participantes" class="form-control" min="0" placeholder="Número de participantes">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Satisfação</label>
                        <select name="satisfacao" id="satisfacao" class="form-select">
                            <option value="">Não avaliado</option>
                            <option value="1">⭐ Muito Insatisfeito</option>
                            <option value="2">⭐⭐ Insatisfeito</option>
                            <option value="3">⭐⭐⭐ Neutro</option>
                            <option value="4">⭐⭐⭐⭐ Satisfeito</option>
                            <option value="5">⭐⭐⭐⭐⭐ Muito Satisfeito</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Material Entregue</label>
                        <select name="material" id="material" class="form-select">
                            <option value="NÃO">Não</option>
                            <option value="SIM">Sim</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light fw-bold px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary fw-bold shadow-sm px-4">Salvar Treinamento</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Inicializar tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });

        // Configurar data atual como padrão
        const dataInput = document.getElementById('data_treinamento');
        if (dataInput && !dataInput.value) {
            const now = new Date();
            const localDateTime = new Date(now.getTime() - now.getTimezoneOffset() * 60000)
                .toISOString()
                .slice(0, 16);
            dataInput.value = localDateTime;
        }

        // Configurar botões de edição
        document.querySelectorAll('.edit-treinamento-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                openEditTreinamentoModal(this);
            });
        });
    });

    // Função para abrir modal de edição
    function openEditTreinamentoModal(button) {
        document.getElementById('modalTreinamentoTitle').innerText = 'Editar Treinamento';
        document.getElementById('id_treinamento').value = button.dataset.id;

        // Formatar data para o input datetime-local
        const dataOriginal = button.dataset.data_treinamento;
        const dataFormatada = dataOriginal.replace(' ', 'T');
        document.getElementById('data_treinamento').value = dataFormatada;

        document.getElementById('tema').value = button.dataset.tema;
        document.getElementById('observacoes').value = button.dataset.observacoes;
        document.getElementById('status').value = button.dataset.status || 'REALIZADO';

        // Abrir modal
        const modal = new bootstrap.Modal(document.getElementById('modalTreinamento'));
        modal.show();
    }

    // Reset modal ao fechar
    document.getElementById('modalTreinamento').addEventListener('hidden.bs.modal', function() {
        const form = this.querySelector('form');
        form.reset();
        document.getElementById('id_treinamento').value = '';
        document.getElementById('modalTreinamentoTitle').innerText = 'Novo Treinamento';

        // Resetar data para agora
        const now = new Date();
        const localDateTime = new Date(now.getTime() - now.getTimezoneOffset() * 60000)
            .toISOString()
            .slice(0, 16);
        document.getElementById('data_treinamento').value = localDateTime;

        // Resetar status
        document.getElementById('status').value = 'REALIZADO';
    });

    // Filtro por status (opcional - para futuras implementações)
    function filtrarPorStatus(status) {
        const items = document.querySelectorAll('.timeline-item');
        items.forEach(item => {
            const badge = item.querySelector('.timeline-badge');
            if (status === 'todos' || badge.textContent.trim() === status) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }
</script>

<?php include 'footer.php'; ?>