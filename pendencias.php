<?php
// Configurar timezone
date_default_timezone_set('America/Sao_Paulo');

require_once 'config.php';

// --- LÓGICA PARA MARCAR/REMOVER TRATATIVA ---
if (isset($_GET['marcar_tratativa'])) {
    $id_cliente = $_GET['marcar_tratativa'];
    
    $stmt = $pdo->prepare("UPDATE clientes SET 
                          status_tratativa = 'em_tratativa', 
                          data_inicio_tratativa = NOW() 
                          WHERE id_cliente = ?");
    $stmt->execute([$id_cliente]);
    
    header("Location: pendencias.php?msg=Cliente marcado como em tratativa");
    exit;
}

if (isset($_GET['remover_tratativa'])) {
    $id_cliente = $_GET['remover_tratativa'];
    
    $stmt = $pdo->prepare("UPDATE clientes SET 
                          status_tratativa = 'pendente', 
                          data_inicio_tratativa = NULL 
                          WHERE id_cliente = ?");
    $stmt->execute([$id_cliente]);
    
    header("Location: pendencias.php?msg=Cliente retornado a pendente");
    exit;
}

// --- ATUALIZAR STATUS AUTOMATICAMENTE (3 dias) ---
$pdo->exec("UPDATE clientes SET 
           status_tratativa = 'pendente', 
           data_inicio_tratativa = NULL 
           WHERE status_tratativa = 'em_tratativa' 
           AND data_inicio_tratativa < DATE_SUB(NOW(), INTERVAL 3 DAY)");

// --- LÓGICA DE ALERTAS DE INATIVIDADE ---
$sql_inatividade = "
    SELECT 
        c.id_cliente, 
        c.fantasia, 
        c.data_inicio,
        c.vendedor,
        c.status_tratativa,
        c.data_inicio_tratativa,
        MAX(t.data_treinamento) as data_ultimo_treino
    FROM clientes c
    LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
    WHERE (c.data_fim IS NULL OR c.data_fim = '0000-00-00')
    AND c.id_cliente NOT IN (
        SELECT DISTINCT id_cliente FROM treinamentos WHERE status = 'PENDENTE'
    )
    AND (c.status_tratativa IS NULL OR c.status_tratativa != 'em_tratativa')    
    GROUP BY c.id_cliente
    HAVING 
        (data_ultimo_treino < DATE_SUB(CURDATE(), INTERVAL 3 DAY)) OR 
        (data_ultimo_treino IS NULL AND c.data_inicio < DATE_SUB(CURDATE(), INTERVAL 3 DAY))
    ORDER BY data_ultimo_treino ASC";

$clientes_inativos = $pdo->query($sql_inatividade)->fetchAll();

// --- CLIENTES EM TRATATIVA ---
$sql_tratativa = "
    SELECT 
        c.id_cliente, 
        c.fantasia, 
        c.vendedor,
        c.data_inicio_tratativa,
        DATEDIFF(NOW(), c.data_inicio_tratativa) as dias_em_tratativa
    FROM clientes c
    WHERE c.status_tratativa = 'em_tratativa'
      AND (c.data_fim IS NULL OR c.data_fim = '0000-00-00')
    ORDER BY c.data_inicio_tratativa ASC";

$clientes_tratativa = $pdo->query($sql_tratativa)->fetchAll();

include 'header.php';
?>

<style>
    .page-title {
        font-size: 1.6rem;
        letter-spacing: 0.2px;
    }

    .card-alert { 
        border: none !important; 
        border-radius: 12px; 
        transition: transform 0.2s ease;
    }
    .card-alert:hover {
        transform: translateY(-2px);
    }
    .table thead th { 
        background-color: #f8f9fa; 
        color: #6c757d; 
        font-weight: 600; 
        text-transform: uppercase; 
        font-size: 0.75rem; 
        letter-spacing: 0.5px; 
        border-top: none; 
    }
    .badge-days { 
        font-size: 0.85rem; 
        padding: 0.5em 1em; 
        border-radius: 20px;
    }
    .status-badge {
        font-size: 0.8rem;
        padding: 0.4em 0.8em;
        border-radius: 10px;
    }
    .progress-bar-custom {
        height: 6px;
        border-radius: 3px;
    }
    .tab-content {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .nav-tabs .nav-link {
        border: none;
        color: #6c757d;
        font-weight: 500;
        padding: 12px 24px;
    }
    .nav-tabs .nav-link.active {
        color: #4361ee;
        border-bottom: 3px solid #4361ee;
        background: transparent;
    }
    .empty-state {
        padding: 4rem 2rem;
        text-align: center;
        color: #6c757d;
    }
    .count-badge {
        font-size: 0.7rem;
        margin-left: 6px;
    }

    .summary-card {
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .summary-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.08) !important;
    }

    .pendencia-action-btn {
        border-radius: 10px;
        font-weight: 700;
        min-height: 38px;
    }
</style>

<div class="container-fluid py-4 bg-light min-vh-100">
    <!-- Cabeçalho -->
    <div class="row align-items-center mb-4">
        <div class="col">
            <h3 class="page-title fw-bold text-dark mb-1"><i class="bi bi-clipboard-data me-2 text-primary"></i>Controle de Pendências</h3>
            <p class="text-muted small">Gerencie clientes sem treinamentos pendentes e em tratativa paralela</p>
        </div>
        <div class="col-auto">
            <div class="d-flex gap-2">
                <span class="badge bg-danger rounded-pill px-3 py-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <?= count($clientes_inativos) ?> Pendentes
                </span>
                <span class="badge bg-warning rounded-pill px-3 py-2">
                    <i class="bi bi-clock-history me-1"></i>
                    <?= count($clientes_tratativa) ?> Em Tratativa
                </span>
            </div>
        </div>
    </div>

    <!-- Cards de Resumo -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card summary-card border-0 shadow-sm rounded-3 border-start border-danger border-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase">Clientes Pendentes</span>
                            <h2 class="fw-bold my-1 text-dark"><?= count($clientes_inativos) ?></h2>
                            <small class="text-muted">Sem interação há 3+ dias</small>
                        </div>
                        <div class="bg-danger bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-exclamation-triangle text-danger" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card summary-card border-0 shadow-sm rounded-3 border-start border-warning border-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase">Em Tratativa</span>
                            <h2 class="fw-bold my-1 text-dark"><?= count($clientes_tratativa) ?></h2>
                            <small class="text-muted">Ação paralela em andamento</small>
                        </div>
                        <div class="bg-warning bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-clock-history text-warning" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card summary-card border-0 shadow-sm rounded-3 border-start border-success border-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase">Próxima Expiração</span>
                            <h2 class="fw-bold my-1 text-dark">
                                <?php 
                                if (!empty($clientes_tratativa)) {
                                    $dias_restantes = 3 - min($clientes_tratativa[0]['dias_em_tratativa'], 3);
                                    echo $dias_restantes;
                                } else {
                                    echo '-';
                                }
                                ?> dias
                            </h2>
                            <small class="text-muted">Retorno automático para pendente</small>
                        </div>
                        <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-calendar-x text-success" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Abas de Navegação -->
    <div class="row mb-4">
        <div class="col-12">
            <nav>
                <div class="nav nav-tabs border-0" id="nav-tab" role="tablist">
                    <button class="nav-link active" id="nav-pendentes-tab" data-bs-toggle="tab" data-bs-target="#nav-pendentes" type="button" role="tab">
                        <i class="bi bi-exclamation-circle me-2"></i>Pendentes
                        <span class="badge bg-danger count-badge"><?= count($clientes_inativos) ?></span>
                    </button>
                    <button class="nav-link" id="nav-tratativa-tab" data-bs-toggle="tab" data-bs-target="#nav-tratativa" type="button" role="tab">
                        <i class="bi bi-clock-history me-2"></i>Em Tratativa
                        <span class="badge bg-warning count-badge"><?= count($clientes_tratativa) ?></span>
                    </button>
                </div>
            </nav>
            
            <div class="tab-content p-0 border-0" id="nav-tabContent">
                <!-- ABA: PENDENTES -->
                <div class="tab-pane fade show active" id="nav-pendentes" role="tabpanel">
                    <div class="card shadow-sm border-0 rounded-3 rounded-top-start-0">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th class="ps-4">Empresa / Vendedor</th>
                                            <th>Último Treinamento</th>
                                            <th>Tempo de Inatividade</th>
                                            <th class="text-end pe-4">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody class="border-top-0">
                                        <?php if (empty($clientes_inativos)): ?>
                                            <tr>
                                                <td colspan="4" class="text-center py-5">
                                                    <div class="empty-state">
                                                        <i class="bi bi-emoji-smile text-success fs-1 d-block mb-2"></i>
                                                        <h5 class="fw-bold mb-2">Nenhuma pendência encontrada!</h5>
                                                        <p class="text-muted">Todos os clientes estão em dia ou em tratativa paralela.</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($clientes_inativos as $ci): 
                                                $data_referencia = $ci['data_ultimo_treino'] ?? $ci['data_inicio'];
                                                $dias_parado = (new DateTime($data_referencia))->diff(new DateTime())->days;
                                            ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($ci['fantasia']) ?></div>
                                                    <small class="text-muted">
                                                        <i class="bi bi-person me-1"></i>Vendedor: <?= htmlspecialchars($ci['vendedor']) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="small fw-bold text-secondary">
                                                        <i class="bi bi-calendar-check me-1"></i>
                                                        <?= $ci['data_ultimo_treino'] ? date('d/m/Y', strtotime($ci['data_ultimo_treino'])) : '<span class="text-warning">Aguardando 1º Treino</span>' ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger badge-days">
                                                        <i class="bi bi-clock-history me-1"></i> <?= $dias_parado ?> dias parado
                                                    </span>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <div class="btn-group">
                                                        <a href="treinamentos.php?id_cliente=<?= $ci['id_cliente'] ?>" 
                                                           class="btn btn-primary btn-sm px-3 pendencia-action-btn rounded-start-pill"
                                                           data-bs-toggle="tooltip"
                                                           data-bs-title="Agendar treinamento">
                                                            <i class="bi bi-calendar-plus me-1"></i> Agendar
                                                        </a>
                                                        <a href="?marcar_tratativa=<?= $ci['id_cliente'] ?>" 
                                                           class="btn btn-warning btn-sm px-3 pendencia-action-btn rounded-end-pill"
                                                           data-bs-toggle="tooltip"
                                                           data-bs-title="Marcar como em tratativa (3 dias)"
                                                           onclick="return confirm('Marcar cliente como EM TRATATIVA? Ele retornará automaticamente após 3 dias.')">
                                                            <i class="bi bi-clock me-1"></i> Tratativa
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
                
                <!-- ABA: EM TRATATIVA -->
                <div class="tab-pane fade" id="nav-tratativa" role="tabpanel">
                    <div class="card shadow-sm border-0 rounded-3 rounded-top-start-0">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th class="ps-4">Empresa / Vendedor</th>
                                            <th>Início da Tratativa</th>
                                            <th>Progresso / Expira em</th>
                                            <th class="text-end pe-4">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody class="border-top-0">
                                        <?php if (empty($clientes_tratativa)): ?>
                                            <tr>
                                                <td colspan="4" class="text-center py-5">
                                                    <div class="empty-state">
                                                        <i class="bi bi-inbox text-muted fs-1 d-block mb-2"></i>
                                                        <h5 class="fw-bold mb-2">Nenhum cliente em tratativa</h5>
                                                        <p class="text-muted">Use a aba "Pendentes" para marcar clientes como em tratativa.</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($clientes_tratativa as $ct): 
                                                $dias_em_tratativa = $ct['dias_em_tratativa'];
                                                $progresso = min(($dias_em_tratativa / 3) * 100, 100);
                                                $dias_restantes = max(3 - $dias_em_tratativa, 0);
                                                
                                                // Cor baseada no progresso
                                                if ($dias_em_tratativa >= 3) {
                                                    $badge_class = 'bg-danger';
                                                    $progress_class = 'bg-danger';
                                                } elseif ($dias_em_tratativa >= 2) {
                                                    $badge_class = 'bg-warning';
                                                    $progress_class = 'bg-warning';
                                                } else {
                                                    $badge_class = 'bg-success';
                                                    $progress_class = 'bg-success';
                                                }
                                            ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($ct['fantasia']) ?></div>
                                                    <small class="text-muted">
                                                        <i class="bi bi-person me-1"></i>Vendedor: <?= htmlspecialchars($ct['vendedor']) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="small fw-bold text-secondary">
                                                        <i class="bi bi-calendar-plus me-1"></i>
                                                        <?= date('d/m/Y H:i', strtotime($ct['data_inicio_tratativa'])) ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        Há <?= $dias_em_tratativa ?> dia(s)
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="flex-grow-1 me-3">
                                                            <div class="progress progress-bar-custom mb-1">
                                                                <div class="progress-bar <?= $progress_class ?>" 
                                                                     role="progressbar" 
                                                                     style="width: <?= $progresso ?>%">
                                                                </div>
                                                            </div>
                                                            <small class="text-muted">
                                                                <?= $dias_restantes ?> dia(s) restante(s)
                                                            </small>
                                                        </div>
                                                        <span class="badge <?= $badge_class ?> status-badge">
                                                            <?php if($dias_em_tratativa >= 3): ?>
                                                                EXPIRADO
                                                            <?php else: ?>
                                                                <?= $dias_restantes ?>D
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <div class="btn-group">
                                                        <a href="treinamentos.php?id_cliente=<?= $ct['id_cliente'] ?>" 
                                                           class="btn btn-primary btn-sm px-3 pendencia-action-btn rounded-start-pill"
                                                           data-bs-toggle="tooltip"
                                                           data-bs-title="Agendar treinamento">
                                                            <i class="bi bi-calendar-plus me-1"></i> Agendar
                                                        </a>
                                                        <a href="?remover_tratativa=<?= $ct['id_cliente'] ?>" 
                                                           class="btn btn-secondary btn-sm px-3 pendencia-action-btn rounded-end-pill"
                                                           data-bs-toggle="tooltip"
                                                           data-bs-title="Retornar para pendente"
                                                           onclick="return confirm('Retornar cliente para PENDENTE?')">
                                                            <i class="bi bi-arrow-counterclockwise me-1"></i> Retornar
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
        </div>
    </div>
</div>

<!-- Script para inicializar tooltips -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Auto-refresh para status expirado (opcional)
    setInterval(function() {
        const expiredBadges = document.querySelectorAll('.badge.bg-danger:contains("EXPIRADO")');
        if (expiredBadges.length > 0) {
            location.reload();
        }
    }, 300000); // 5 minutos
});
</script>

<?php include 'footer.php'; ?>
