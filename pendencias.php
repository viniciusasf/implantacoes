<?php
require_once 'config.php';

// --- LÓGICA DE ALERTAS DE INATIVIDADE ---
// Busca clientes que não possuem agendamentos futuros e cujo último contato foi há mais de 3 dias
$sql_inatividade = "
    SELECT 
        c.id_cliente, 
        c.fantasia, 
        c.data_inicio,
        c.vendedor,
        MAX(t.data_treinamento) as data_ultimo_treino
    FROM clientes c
    LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
    WHERE (c.data_fim IS NULL OR c.data_fim = '0000-00-00')
    AND c.id_cliente NOT IN (
        SELECT DISTINCT id_cliente FROM treinamentos WHERE status = 'PENDENTE'
    )
    GROUP BY c.id_cliente
    HAVING 
        (data_ultimo_treino < DATE_SUB(CURDATE(), INTERVAL 3 DAY)) OR 
        (data_ultimo_treino IS NULL AND c.data_inicio < DATE_SUB(CURDATE(), INTERVAL 3 DAY))
    ORDER BY data_ultimo_treino ASC";

$clientes_inativos = $pdo->query($sql_inatividade)->fetchAll();

include 'header.php';
?>

<style>
    .card-alert { border: none !important; border-radius: 12px; }
    .table thead th { background-color: #f8f9fa; color: #6c757d; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; border-top: none; }
    .badge-days { font-size: 0.85rem; padding: 0.5em 1em; }
</style>

<div class="container-fluid py-4 bg-light min-vh-100">
    <div class="row align-items-center mb-4">
        <div class="col">
            <h4 class="fw-bold text-dark mb-1">Pendências de Treinamentos</h4>
            <p class="text-muted small">Clientes com mais de 3 dias sem interação no treinamento</p>
        </div>
        <div class="col-auto">
            <span class="badge bg-danger rounded-pill px-3"><?= count($clientes_inativos) ?> Clientes em atraso</span>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-3">
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
                                <i class="bi bi-emoji-smile text-success fs-1 d-block mb-2"></i>
                                <span class="text-muted fw-bold">Nenhuma pendência encontrada. Todos os clientes estão em dia!</span>
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
                                <small class="text-muted">Vendedor: <?= htmlspecialchars($ci['vendedor']) ?></small>
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
                                <a href="treinamentos.php?id_cliente=<?= $ci['id_cliente'] ?>" class="btn btn-primary btn-sm px-4 fw-bold rounded-pill shadow-sm">
                                    <i class="bi bi-calendar-plus me-1"></i> Agendar Agora
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

<?php include 'footer.php'; ?>