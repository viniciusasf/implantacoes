<?php
require_once 'config.php';

// FUNÇÃO PARA GARANTIR QUE A TABELA DE OBSERVAÇÕES EXISTA
function garantirTabelaObservacoes($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'observacoes_cliente'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS observacoes_cliente (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (PDOException $e) {
        // Log do erro mas não interrompe
        error_log("Erro ao verificar/criar tabela observacoes_cliente: " . $e->getMessage());
    }
}

// Executar a verificação
garantirTabelaObservacoes($pdo);

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

// 2. Buscar contatos deste cliente específico
// PRIMEIRO: Verificar a estrutura da tabela contatos
try {
    // Tentar buscar colunas disponíveis na tabela contatos
    $stmtTest = $pdo->prepare("SHOW COLUMNS FROM contatos");
    $stmtTest->execute();
    $colunas = $stmtTest->fetchAll(PDO::FETCH_COLUMN);

    // Montar query baseada nas colunas disponíveis
    $colunas_disponiveis = [];
    if (in_array('nome', $colunas)) $colunas_disponiveis[] = 'nome';
    if (in_array('telefone', $colunas)) $colunas_disponiveis[] = 'telefone';
    if (in_array('celular', $colunas)) $colunas_disponiveis[] = 'celular';
    if (in_array('email', $colunas)) $colunas_disponiveis[] = 'email';
    if (in_array('telefone_ddd', $colunas)) $colunas_disponiveis[] = 'telefone_ddd';

    if (empty($colunas_disponiveis)) {
        $colunas_disponiveis[] = 'nome'; // Mínimo necessário
    }

    $query_contatos = "SELECT id_contato, " . implode(', ', $colunas_disponiveis) . " FROM contatos WHERE id_cliente = ? ORDER BY nome ASC";
    $stmtContatos = $pdo->prepare($query_contatos);
    $stmtContatos->execute([$id_cliente]);
    $contatos = $stmtContatos->fetchAll();
} catch (PDOException $e) {
    // Se falhar, usar query mais simples
    $stmtContatos = $pdo->prepare("SELECT id_contato, nome FROM contatos WHERE id_cliente = ? ORDER BY nome ASC");
    $stmtContatos->execute([$id_cliente]);
    $contatos = $stmtContatos->fetchAll();
}

// 3. Busca todos os treinamentos
$stmt = $pdo->prepare("SELECT * FROM treinamentos WHERE id_cliente = ? ORDER BY data_treinamento DESC, id_treinamento DESC");
$stmt->execute([$id_cliente]);
$treinamentos = $stmt->fetchAll();

// 4. Buscar observações/históricos manuais
$stmtObs = $pdo->prepare("SELECT * FROM observacoes_cliente WHERE id_cliente = ? ORDER BY data_observacao DESC, id_observacao DESC");
$stmtObs->execute([$id_cliente]);
$observacoes = $stmtObs->fetchAll();

// 5. Estatísticas
$total_treinamentos = count($treinamentos);
$treinamentos_realizados = 0;
$treinamentos_pendentes = 0;
$total_observacoes = count($observacoes);
$ultimo_treinamento = null;
$primeiro_treinamento = null;

if ($total_treinamentos > 0) {
    foreach ($treinamentos as $t) {
        $status = strtoupper($t['status'] ?? '');
        if ($status == 'REALIZADO' || $status == 'RESOLVIDO') {
            $treinamentos_realizados++;
        } elseif ($status == 'PENDENTE') {
            $treinamentos_pendentes++;
        }
    }

    $ultimo_treinamento = $treinamentos[0]['data_treinamento'] ?? null;
    $primeiro_treinamento = end($treinamentos)['data_treinamento'] ?? null;
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
        --purple-color: #7209b7;
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

    /* GRID DE INFORMAÇÕES COM ÍCONES */
    .client-info-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        margin-top: 1rem;
    }

    .info-item {
        background: rgba(255, 255, 255, 0.1);
        padding: 1rem 1.25rem;
        border-radius: 10px;
        backdrop-filter: blur(10px);
        flex: 1;
        min-width: 200px;
        transition: all 0.3s;
        border: 1px solid rgba(255, 255, 255, 0.05);
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .info-item:hover {
        background: rgba(255, 255, 255, 0.15);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .info-icon {
        font-size: 1.5rem;
        opacity: 0.9;
        color: white;
    }

    .info-content {
        flex: 1;
    }

    .info-label {
        font-size: 0.75rem;
        opacity: 0.8;
        margin-bottom: 0.25rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .info-value {
        font-size: 1rem;
        font-weight: 600;
        line-height: 1.3;
    }

    @media (max-width: 768px) {
        .info-item {
            min-width: calc(50% - 0.5rem);
        }
    }

    @media (max-width: 576px) {
        .info-item {
            min-width: 100%;
        }
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

    .timeline-details {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px dashed #e9ecef;
    }

    .timeline-footer {
        padding: 0.75rem 1.25rem;
        background: #f8f9fa;
        border-top: 1px solid #e9ecef;
        font-size: 0.8rem;
        color: #6c757d;
    }

    /* CARD PARA OBSERVAÇÕES/HISTÓRICOS */
    .observation-card {
        background: white;
        border-radius: 12px;
        border: 1px solid #e9ecef;
        margin-bottom: 1rem;
        overflow: hidden;
        transition: all 0.3s;
    }

    .observation-card:hover {
        border-color: var(--purple-color);
        box-shadow: 0 5px 15px rgba(114, 9, 183, 0.08);
    }

    .observation-header {
        padding: 1rem 1.25rem;
        background: linear-gradient(90deg, #f3e5ff, #f8f9fa);
        border-bottom: 1px solid #f1f3f4;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .observation-title {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .observation-title i {
        color: var(--purple-color);
        font-size: 1.2rem;
    }

    .observation-date {
        font-weight: bold;
        color: var(--purple-color);
        font-size: 1rem;
    }

    .observation-type-badge {
        font-size: 0.7rem;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-weight: 500;
        background-color: var(--purple-color);
        color: white;
    }

    .observation-body {
        padding: 1.25rem;
    }

    .observation-content {
        color: #495057;
        line-height: 1.6;
        margin-bottom: 1rem;
        white-space: pre-wrap;
    }

    .observation-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 1rem;
    }

    .observation-tag {
        background: #e9ecef;
        color: #495057;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }

    .observation-footer {
        padding: 0.75rem 1.25rem;
        background: #f8f9fa;
        border-top: 1px solid #e9ecef;
        font-size: 0.8rem;
        color: #6c757d;
        display: flex;
        justify-content: space-between;
        align-items: center;
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

    .btn-add-observation {
        background: linear-gradient(135deg, #7209b7 0%, #5a08a5 100%);
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

    .btn-add-observation:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(114, 9, 183, 0.3);
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

    .btn-edit-observation {
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

    .btn-edit-observation:hover {
        background: #7209b7;
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

        .timeline-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .observation-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }
    }

    /* MODAL STYLES */
    .link-input-group .btn-outline-secondary:hover {
        background-color: #e9ecef;
    }

    .form-text a {
        color: #4361ee;
        transition: color 0.2s;
    }

    .form-text a:hover {
        color: #3a56d4;
        text-decoration: underline;
    }

    /* NOVO: Estilos para modal de observações */
    .modal-purple .modal-header {
        background: linear-gradient(135deg, #7209b7 0%, #5a08a5 100%);
        color: white;
        border-bottom: none;
    }

    .modal-purple .btn-close {
        filter: invert(1);
    }

    .tag-badge {
        background: #e9ecef;
        color: #495057;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        margin: 0.25rem;
        cursor: pointer;
        transition: all 0.2s;
    }

    .tag-badge:hover {
        background: #dee2e6;
    }

    .tag-badge.selected {
        background: var(--purple-color);
        color: white;
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
                    <h4 class="fw-bold mb-0">Histórico do Cliente</h4>
                </div>

                <h2 class="fw-bold mb-3"><?= htmlspecialchars($cliente['fantasia']) ?></h2>

                <div class="client-info-grid">
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-hdd"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Servidor</div>
                            <div class="info-value"><?= htmlspecialchars($cliente['servidor'] ?? 'Não informado') ?></div>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Vendedor</div>
                            <div class="info-value"><?= htmlspecialchars($cliente['vendedor'] ?? 'Não informado') ?></div>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-person-vcard"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">ID Cliente</div>
                            <div class="info-value">#<?= $id_cliente ?></div>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Contatos</div>
                            <div class="info-value"><?= count($contatos) ?> cadastrado(s)</div>
                        </div>
                    </div>
                </div>

                <!-- BOTÕES DE AÇÃO -->
                <div class="d-flex gap-3 mt-4">
                    <button class="btn-add-training" data-bs-toggle="modal" data-bs-target="#modalTreinamento">
                        <i class="bi bi-plus-circle"></i>
                        Novo Treinamento
                    </button>
                    <button class="btn-add-observation" data-bs-toggle="modal" data-bs-target="#modalObservacao">
                        <i class="bi bi-journal-plus"></i>
                        Nova Observação
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
                <div class="stat-icon text-purple">
                    <i class="bi bi-journal-text"></i>
                </div>
                <div class="stat-value"><?= $total_observacoes ?></div>
                <div class="stat-label">Observações</div>
            </div>
        </div>

        <!-- SEÇÃO DE OBSERVAÇÕES/HISTÓRICOS -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="fw-bold mb-0">
                    <i class="bi bi-journal-text me-2 text-purple"></i>
                    Observações e Históricos do Sistema
                </h5>
            </div>
            <div class="card-body">
                <?php if ($total_observacoes > 0): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <p class="text-muted mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                Registros de alterações, ajustes e observações manuais do sistema
                            </p>
                            <button class="btn-add-observation btn-sm" data-bs-toggle="modal" data-bs-target="#modalObservacao">
                                <i class="bi bi-plus-circle"></i>
                                Nova Observação
                            </button>
                        </div>
                    </div>

                    <?php foreach ($observacoes as $index => $obs):
                        $tipo = $obs['tipo'] ?? 'INFORMAÇÃO';

                        // CORREÇÃO APLICADA AQUI:
                        $tipo_color = ($tipo == 'AJUSTE') ? '#ffc107'
                            : (($tipo == 'PROBLEMA') ? '#dc3545'
                                : (($tipo == 'MELHORIA') ? '#06d6a0'
                                    : '#7209b7'));
                    ?>
                        <div class="observation-card fade-in" style="animation-delay: <?= $index * 0.1 ?>s;">
                            <div class="observation-header">
                                
                                <div class="observation-card fade-in" style="animation-delay: <?= $index * 0.1 ?>s;">
                                    <div class="observation-header">
                                        <div class="observation-title">
                                            <i class="bi bi-journal-text"></i>
                                            <div>
                                                <div class="observation-date">
                                                    <?= date('d/m/Y H:i', strtotime($obs['data_observacao'])) ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($obs['titulo']) ?>
                                                </small>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="observation-type-badge" style="background-color: <?= $tipo_color ?>;">
                                                <?= $tipo ?>
                                            </span>
                                            <button class="btn-edit-observation edit-observacao-btn"
                                                data-id="<?= $obs['id_observacao'] ?>"
                                                data-titulo="<?= htmlspecialchars($obs['titulo']) ?>"
                                                data-conteudo="<?= htmlspecialchars($obs['conteudo']) ?>"
                                                data-tipo="<?= $obs['tipo'] ?>"
                                                data-tags="<?= htmlspecialchars($obs['tags'] ?? '') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="observation-body">
                                        <div class="observation-content">
                                            <?= nl2br(htmlspecialchars($obs['conteudo'])) ?>
                                        </div>

                                        <?php if (!empty($obs['tags'])):
                                            $tags_array = explode(',', $obs['tags']);
                                        ?>
                                            <div class="observation-tags">
                                                <?php foreach ($tags_array as $tag):
                                                    if (!empty(trim($tag))): ?>
                                                        <span class="observation-tag">
                                                            <i class="bi bi-tag"></i>
                                                            <?= htmlspecialchars(trim($tag)) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="observation-footer">
                                        <small>
                                            <i class="bi bi-info-circle me-1"></i>
                                            Registro #<?= $obs['id_observacao'] ?>
                                        </small>
                                        <small>
                                            <i class="bi bi-person me-1"></i>
                                            Registrado por: <?= htmlspecialchars($obs['registrado_por'] ?? 'Sistema') ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-journal-x empty-state-icon text-purple"></i>
                                <h5 class="fw-bold mb-3">Nenhuma observação registrada</h5>
                                <p class="mb-4">Este cliente ainda não possui observações ou históricos manuais cadastrados.</p>
                                <button class="btn-add-observation" data-bs-toggle="modal" data-bs-target="#modalObservacao">
                                    <i class="bi bi-plus-circle me-2"></i>
                                    Cadastrar Primeira Observação
                                </button>
                            </div>
                        <?php endif; ?>
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
                                            $status = strtoupper($t['status'] ?? 'PENDENTE');
                                            $status_color = ($status == 'REALIZADO' || $status == 'RESOLVIDO') ? '#06d6a0' : ($status == 'PENDENTE' ? '#ffc107' : ($status == 'CANCELADO' ? '#dc3545' : '#4361ee'));
                                            $status_text = ($status == 'REALIZADO' || $status == 'RESOLVIDO') ? 'Realizado' : (($status == 'PENDENTE') ? 'Pendente' : (($status == 'CANCELADO') ? 'Cancelado' : 'Agendado'));

                                            // Formatar data
                                            $data_br = date('d/m/Y', strtotime($t['data_treinamento']));
                                            $data_full = date('d/m/Y H:i', strtotime($t['data_treinamento']));

                                            // Buscar nome do contato se existir
                                            $nome_contato = '';
                                            if (!empty($t['id_contato'])) {
                                                $stmtContatoNome = $pdo->prepare("SELECT nome FROM contatos WHERE id_contato = ?");
                                                $stmtContatoNome->execute([$t['id_contato']]);
                                                $contato_nome = $stmtContatoNome->fetch();
                                                $nome_contato = $contato_nome['nome'] ?? '';
                                            }
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
                                                                data-status="<?= $t['status'] ?>"
                                                                data-id_contato="<?= $t['id_contato'] ?? '' ?>"
                                                                data-google_event_link="<?= htmlspecialchars($t['google_event_link'] ?? '') ?>"
                                                                data-observacoes="<?= htmlspecialchars($t['observacoes'] ?? '') ?>">
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

                                                        <div class="timeline-details">
                                                            <?php if (!empty($nome_contato)): ?>
                                                                <div class="d-flex align-items-center gap-2 mb-1">
                                                                    <i class="bi bi-person text-muted"></i>
                                                                    <small class="text-muted">
                                                                        <strong>Contato:</strong> <?= htmlspecialchars($nome_contato) ?>
                                                                    </small>
                                                                </div>
                                                            <?php endif; ?>

                                                            <?php if (!empty($t['google_event_link'])): ?>
                                                                <div class="d-flex align-items-center gap-2 mb-1">
                                                                    <i class="bi bi-google text-primary"></i>
                                                                    <small>
                                                                        <a href="<?= htmlspecialchars($t['google_event_link']) ?>"
                                                                            target="_blank"
                                                                            class="text-decoration-none">
                                                                            <strong>Link Google Agenda</strong>
                                                                        </a>
                                                                    </small>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
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

            <!-- MODAL PARA OBSERVAÇÕES/HISTÓRICOS -->
            <div class="modal fade modal-purple" id="modalObservacao" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <form method="POST" action="salvar_observacao.php" class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
                        <input type="hidden" name="id_cliente" value="<?= $id_cliente ?>">
                        <input type="hidden" name="id_observacao" id="id_observacao">

                        <div class="modal-header border-0 px-4 pt-4 pb-3">
                            <h5 class="fw-bold text-white" id="modalObservacaoTitle">
                                <i class="bi bi-journal-plus me-2"></i>
                                Nova Observação / Histórico
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body px-4 py-3">
                            <!-- CLIENTE FIXO -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Cliente</label>
                                <input type="text"
                                    class="form-control bg-light"
                                    value="<?= htmlspecialchars($cliente['fantasia']) ?> - ID: <?= $id_cliente ?>"
                                    readonly
                                    style="cursor: not-allowed;">
                                <div class="form-text">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle"></i>
                                        Esta observação será associada ao cliente <?= htmlspecialchars($cliente['fantasia']) ?>
                                    </small>
                                </div>
                            </div>

                            <!-- TÍTULO -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Título</label>
                                <input type="text"
                                    name="titulo"
                                    id="titulo"
                                    class="form-control"
                                    placeholder="Ex: Ajuste no relatório de vendas, Problema na impressora, Atualização do sistema..."
                                    required
                                    maxlength="100">
                                <div class="form-text">
                                    <small class="text-muted">
                                        Descreva brevemente o assunto da observação
                                    </small>
                                </div>
                            </div>

                            <!-- CONTEÚDO -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Conteúdo da Observação</label>
                                <textarea name="conteudo"
                                    id="conteudo"
                                    class="form-control"
                                    rows="5"
                                    placeholder="Descreva em detalhes a observação, histórico, ajuste ou problema registrado..."
                                    required></textarea>
                                <div class="form-text">
                                    <small class="text-muted">
                                        Seja detalhado. Este campo aceita múltiplas linhas.
                                    </small>
                                </div>
                            </div>

                            <!-- TIPO E TAGS -->
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Tipo</label>
                                    <select name="tipo" id="tipo" class="form-select" required>
                                        <option value="INFORMAÇÃO">INFORMAÇÃO</option>
                                        <option value="AJUSTE">AJUSTE</option>
                                        <option value="PROBLEMA">PROBLEMA</option>
                                        <option value="MELHORIA">MELHORIA</option>
                                        <option value="ATUALIZAÇÃO">ATUALIZAÇÃO</option>
                                        <option value="CONTATO">CONTATO</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Tags (opcional)</label>
                                    <input type="text"
                                        name="tags"
                                        id="tags"
                                        class="form-control"
                                        placeholder="Ex: relatório, impressora, nf-e, backup, senha..."
                                        maxlength="100">
                                    <div class="form-text">
                                        <small class="text-muted">
                                            Separe as tags por vírgula. Ex: "relatório, impressora, nf-e"
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <!-- TAGS PRÉ-DEFINIDAS -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Tags Sugeridas</label>
                                <div id="suggested-tags" class="d-flex flex-wrap gap-2 mb-2">
                                    <span class="tag-badge" data-tag="relatório">relatório</span>
                                    <span class="tag-badge" data-tag="impressora">impressora</span>
                                    <span class="tag-badge" data-tag="nf-e">nf-e</span>
                                    <span class="tag-badge" data-tag="backup">backup</span>
                                    <span class="tag-badge" data-tag="senha">senha</span>
                                    <span class="tag-badge" data-tag="atualização">atualização</span>
                                    <span class="tag-badge" data-tag="configuração">configuração</span>
                                    <span class="tag-badge" data-tag="erro">erro</span>
                                    <span class="tag-badge" data-tag="pdv">pdv</span>
                                    <span class="tag-badge" data-tag="financeiro">financeiro</span>
                                </div>
                                <div class="form-text">
                                    <small class="text-muted">
                                        Clique nas tags para adicionar automaticamente
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-0 p-4">
                            <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Fechar</button>
                            <button type="submit" class="btn btn-purple px-4 fw-bold shadow-sm" style="background: linear-gradient(135deg, #7209b7 0%, #5a08a5 100%); color: white;">
                                <i class="bi bi-save me-2"></i>
                                Salvar Observação
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- MODAL PARA AGENDAR/EDITAR TREINAMENTO (JÁ EXISTENTE) -->
            <div class="modal fade" id="modalTreinamento" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <form method="POST" action="salvar_treinamento.php" class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
                        <input type="hidden" name="id_cliente" value="<?= $id_cliente ?>">
                        <input type="hidden" name="id_treinamento" id="id_treinamento">

                        <!-- ... (conteúdo do modal de treinamento existente permanece igual) ... -->
                        <!-- Mantive o modal de treinamento original intacto -->
                        <div class="modal-header border-0 px-4 pt-4">
                            <h5 class="fw-bold" id="modalTitle">Agendar Treinamento</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body px-4">
                            <!-- CLIENTE FIXO (já sabemos qual é) -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Cliente</label>
                                <input type="text"
                                    class="form-control bg-light"
                                    value="<?= htmlspecialchars($cliente['fantasia']) ?> - ID: <?= $id_cliente ?>"
                                    readonly
                                    style="cursor: not-allowed;">
                                <div class="form-text">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle"></i>
                                        Este treinamento será associado ao cliente <?= htmlspecialchars($cliente['fantasia']) ?>
                                    </small>
                                </div>
                            </div>

                            <!-- CONTATO (somente contatos deste cliente) -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Contato</label>
                                <select name="id_contato" id="id_contato" class="form-select" required>
                                    <option value="">Selecione o contato...</option>
                                    <?php if (count($contatos) > 0): ?>
                                        <?php foreach ($contatos as $contato): ?>
                                            <option value="<?= $contato['id_contato'] ?>">
                                                <?= htmlspecialchars($contato['nome']) ?>
                                                <?php
                                                // Mostrar informações de contato disponíveis
                                                $info_extra = [];
                                                if (isset($contato['telefone']) && !empty($contato['telefone'])) {
                                                    $info_extra[] = 'Tel: ' . htmlspecialchars($contato['telefone']);
                                                }
                                                if (isset($contato['celular']) && !empty($contato['celular'])) {
                                                    $info_extra[] = 'Cel: ' . htmlspecialchars($contato['celular']);
                                                }
                                                if (isset($contato['telefone_ddd']) && !empty($contato['telefone_ddd'])) {
                                                    $info_extra[] = 'DDD: ' . htmlspecialchars($contato['telefone_ddd']);
                                                }
                                                if (isset($contato['email']) && !empty($contato['email'])) {
                                                    $info_extra[] = 'Email: ' . htmlspecialchars($contato['email']);
                                                }

                                                if (!empty($info_extra)) {
                                                    echo ' - ' . implode(', ', $info_extra);
                                                }
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="" disabled>Nenhum contato cadastrado para este cliente</option>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text">
                                    <small class="text-muted">
                                        <i class="bi bi-plus-circle"></i>
                                        <a href="contatos.php?cliente=<?= $id_cliente ?>" target="_blank" class="text-decoration-none">
                                            Adicionar novo contato
                                        </a>
                                    </small>
                                </div>
                            </div>

                            <!-- TEMA (mesmo do treinamentos.php) -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Tema</label>
                                <select name="tema" id="tema" class="form-select" required>
                                    <option value="INSTALAÇÃO SISTEMA">INSTALAÇÃO SISTEMA</option>
                                    <option value="CADASTROS">CADASTROS</option>
                                    <option value="ORÇAMENTO DE VENDA">ORÇAMENTO DE VENDA</option>
                                    <option value="ENTRADA DE COMPRA">ENTRADA DE COMPRAS</option>
                                    <option value="FINANCEIRO">FINANCEIRO</option>
                                    <option value="PRODUÇÃO/OS">PRODUÇÃO/OS</option>
                                    <option value="PDV">PDV</option>
                                    <option value="NOTA FISCAL">NOTA FISCAL</option>
                                    <option value="RELATÓRIOS">RELATÓRIOS</option>
                                    <option value="OUTROS">OUTROS</option>
                                </select>
                            </div>

                            <!-- DATA/HORA E STATUS -->
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Data/Hora</label>
                                    <input type="datetime-local" name="data_treinamento" id="data_treinamento" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Status</label>
                                    <select name="status" id="status" class="form-select">
                                        <option value="PENDENTE">PENDENTE</option>
                                        <option value="AGENDADO">AGENDADO</option>
                                        <option value="REALIZADO">REALIZADO</option>
                                        <option value="CANCELADO">CANCELADO</option>
                                        <option value="Resolvido">Resolvido</option>
                                    </select>
                                </div>
                            </div>

                            <!-- LINK DO GOOGLE AGENDA (mesmo do treinamentos.php) -->
                            <div class="mb-3 mt-3">
                                <label class="form-label small fw-bold text-muted">
                                    <i class="bi bi-google me-1"></i>Link do Google Agenda
                                </label>
                                <div class="input-group link-input-group">
                                    <input type="url"
                                        name="google_event_link"
                                        id="google_event_link"
                                        class="form-control"
                                        placeholder="https://calendar.google.com/calendar/u/0/r/event/..."
                                        pattern="https?://.*">
                                    <button type="button"
                                        class="btn btn-outline-secondary"
                                        onclick="copiarLinkModal()"
                                        data-bs-toggle="tooltip"
                                        data-bs-title="Copiar link">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                                <div class="form-text">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle"></i>
                                        Cole o link do evento criado no Google Agenda para compartilhar com o cliente
                                    </small>
                                </div>
                            </div>

                            <!-- OBSERVAÇÕES (campo extra que pode ser útil) -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Observações</label>
                                <textarea name="observacoes" id="observacoes" class="form-control" rows="3" placeholder="Detalhes adicionais sobre o treinamento..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer border-0 p-4">
                            <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Fechar</button>
                            <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">Salvar Treinamento</button>
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

                    // Configurar botões de edição de treinamento
                    document.querySelectorAll('.edit-treinamento-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            openEditTreinamentoModal(this);
                        });
                    });

                    // Configurar botões de edição de observação
                    document.querySelectorAll('.edit-observacao-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            openEditObservacaoModal(this);
                        });
                    });

                    // Configurar tags sugeridas
                    document.querySelectorAll('.tag-badge').forEach(tag => {
                        tag.addEventListener('click', function() {
                            toggleTag(this);
                        });
                    });

                    // Configurar modal de treinamento
                    const modalTreinamento = document.getElementById('modalTreinamento');
                    if (modalTreinamento) {
                        modalTreinamento.addEventListener('show.bs.modal', function(event) {
                            const modalTitle = document.getElementById('modalTitle');
                            const idTreinamento = document.getElementById('id_treinamento');

                            if (!idTreinamento.value) {
                                const now = new Date();
                                const roundedMinutes = Math.ceil(now.getMinutes() / 30) * 30;
                                now.setMinutes(roundedMinutes);
                                now.setSeconds(0);

                                const localDateTime = new Date(now.getTime() - now.getTimezoneOffset() * 60000)
                                    .toISOString()
                                    .slice(0, 16);
                                document.getElementById('data_treinamento').value = localDateTime;

                                modalTitle.innerText = 'Agendar Treinamento';
                                document.getElementById('tema').value = 'INSTALAÇÃO SISTEMA';
                                document.getElementById('status').value = 'PENDENTE';
                                document.getElementById('id_contato').value = '';
                                document.getElementById('google_event_link').value = '';
                                document.getElementById('observacoes').value = '';
                            } else {
                                modalTitle.innerText = 'Editar Treinamento';
                            }
                        });

                        modalTreinamento.addEventListener('hidden.bs.modal', function() {
                            document.getElementById('id_treinamento').value = '';
                            document.getElementById('modalTitle').innerText = 'Agendar Treinamento';
                        });
                    }

                    // Configurar modal de observação
                    const modalObservacao = document.getElementById('modalObservacao');
                    if (modalObservacao) {
                        modalObservacao.addEventListener('show.bs.modal', function(event) {
                            const modalTitle = document.getElementById('modalObservacaoTitle');
                            const idObservacao = document.getElementById('id_observacao');

                            if (!idObservacao.value) {
                                modalTitle.innerHTML = '<i class="bi bi-journal-plus me-2"></i> Nova Observação / Histórico';
                                document.getElementById('titulo').value = '';
                                document.getElementById('conteudo').value = '';
                                document.getElementById('tipo').value = 'INFORMAÇÃO';
                                document.getElementById('tags').value = '';

                                // Resetar tags selecionadas
                                document.querySelectorAll('.tag-badge.selected').forEach(tag => {
                                    tag.classList.remove('selected');
                                });
                            } else {
                                modalTitle.innerHTML = '<i class="bi bi-pencil me-2"></i> Editar Observação';
                            }
                        });

                        modalObservacao.addEventListener('hidden.bs.modal', function() {
                            document.getElementById('id_observacao').value = '';
                            document.getElementById('modalObservacaoTitle').innerText = 'Nova Observação / Histórico';
                        });
                    }
                });

                // Função para abrir modal de edição de treinamento
                function openEditTreinamentoModal(button) {
                    document.getElementById('modalTitle').innerText = 'Editar Treinamento';
                    document.getElementById('id_treinamento').value = button.dataset.id;

                    const dataOriginal = button.dataset.data_treinamento;
                    let dataFormatada = '';

                    if (dataOriginal) {
                        if (dataOriginal.includes(' ')) {
                            dataFormatada = dataOriginal.replace(' ', 'T').slice(0, 16);
                        } else if (dataOriginal.includes('T')) {
                            dataFormatada = dataOriginal.slice(0, 16);
                        } else {
                            const dataObj = new Date(dataOriginal);
                            if (!isNaN(dataObj)) {
                                dataFormatada = dataObj.toISOString().slice(0, 16);
                            }
                        }
                    }

                    if (dataFormatada) {
                        document.getElementById('data_treinamento').value = dataFormatada;
                    }

                    document.getElementById('tema').value = button.dataset.tema || 'INSTALAÇÃO SISTEMA';
                    document.getElementById('status').value = button.dataset.status || 'PENDENTE';
                    document.getElementById('id_contato').value = button.dataset.id_contato || '';
                    document.getElementById('google_event_link').value = button.dataset.google_event_link || '';
                    document.getElementById('observacoes').value = button.dataset.observacoes || '';

                    const modalEl = document.getElementById('modalTreinamento');
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                }

                // Função para abrir modal de edição de observação
                function openEditObservacaoModal(button) {
                    document.getElementById('modalObservacaoTitle').innerText = 'Editar Observação';
                    document.getElementById('id_observacao').value = button.dataset.id;

                    document.getElementById('titulo').value = button.dataset.titulo || '';
                    document.getElementById('conteudo').value = button.dataset.conteudo || '';
                    document.getElementById('tipo').value = button.dataset.tipo || 'INFORMAÇÃO';
                    document.getElementById('tags').value = button.dataset.tags || '';

                    // Processar tags para destacar as selecionadas
                    const tagsField = document.getElementById('tags');
                    const currentTags = tagsField.value.split(',').map(tag => tag.trim().toLowerCase());

                    document.querySelectorAll('.tag-badge').forEach(tagElement => {
                        const tagValue = tagElement.dataset.tag.toLowerCase();
                        if (currentTags.includes(tagValue)) {
                            tagElement.classList.add('selected');
                        } else {
                            tagElement.classList.remove('selected');
                        }
                    });

                    const modalEl = document.getElementById('modalObservacao');
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                }

                // Função para gerenciar tags
                function toggleTag(tagElement) {
                    const tagValue = tagElement.dataset.tag;
                    const tagsField = document.getElementById('tags');
                    let currentTags = tagsField.value.split(',').map(tag => tag.trim()).filter(tag => tag !== '');

                    if (tagElement.classList.contains('selected')) {
                        // Remover tag
                        currentTags = currentTags.filter(tag => tag.toLowerCase() !== tagValue.toLowerCase());
                        tagElement.classList.remove('selected');
                    } else {
                        // Adicionar tag
                        if (!currentTags.some(tag => tag.toLowerCase() === tagValue.toLowerCase())) {
                            currentTags.push(tagValue);
                            tagElement.classList.add('selected');
                        }
                    }

                    tagsField.value = currentTags.join(', ');
                }

                // Função para copiar link (do modal original)
                function copiarLinkModal() {
                    const linkInput = document.getElementById('google_event_link');
                    if (linkInput.value) {
                        linkInput.select();
                        document.execCommand('copy');

                        const copyButton = linkInput.nextElementSibling;
                        const originalTitle = copyButton.getAttribute('data-bs-title');
                        const originalIcon = copyButton.querySelector('i').className;

                        copyButton.setAttribute('data-bs-title', 'Copiado!');
                        copyButton.querySelector('i').className = 'bi bi-check';

                        const tooltip = bootstrap.Tooltip.getInstance(copyButton);
                        if (tooltip) {
                            tooltip.setContent({
                                '.tooltip-inner': 'Copiado!'
                            });
                            tooltip.show();
                        }

                        setTimeout(() => {
                            copyButton.setAttribute('data-bs-title', originalTitle);
                            copyButton.querySelector('i').className = originalIcon;

                            if (tooltip) {
                                tooltip.setContent({
                                    '.tooltip-inner': originalTitle
                                });
                            }
                        }, 2000);
                    }
                }
            </script>

            <?php include 'footer.php'; ?>