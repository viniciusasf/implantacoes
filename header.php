<!DOCTYPE html>
<html lang="pt-br">

<?php
require_once 'config.php'; // Garante a conexão para as consultas do header

// 1. LÓGICA DO CONTADOR DE PENDÊNCIAS (Clientes parados há > 3 dias)
$sql_count_pendencias = "
    SELECT c.id_cliente
    FROM clientes c
    LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
    WHERE (c.data_fim IS NULL OR c.data_fim = '0000-00-00')
    AND c.id_cliente NOT IN (SELECT id_cliente FROM treinamentos WHERE status = 'PENDENTE')
    GROUP BY c.id_cliente, c.data_inicio
    HAVING (MAX(t.data_treinamento) < DATE_SUB(CURDATE(), INTERVAL 3 DAY)) 
       OR (MAX(t.data_treinamento) IS NULL AND c.data_inicio < DATE_SUB(CURDATE(), INTERVAL 3 DAY))";
$res_pendencias = $pdo->query($sql_count_pendencias);
$total_pendencias = $res_pendencias ? $res_pendencias->rowCount() : 0;

// 2. CONTADOR DE CLIENTES ATIVOS (Em implantação)
$total_clientes = $pdo->query("SELECT COUNT(*) FROM clientes WHERE (data_fim IS NULL OR data_fim = '0000-00-00')")->fetchColumn();

// 3. CONTADOR DE CONTATOS TOTAIS
$total_contatos = $pdo->query("SELECT COUNT(*) FROM contatos")->fetchColumn();

// 4. CONTADOR DE AGENDAMENTOS PARA HOJE (Apenas os que ainda estão PENDENTES)
$total_hoje = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE DATE(data_treinamento) = CURDATE() AND status = 'PENDENTE'")->fetchColumn();

// 5. CONTADOR DE TAREFAS PENDENTES (para o menu Tarefas)
$sql_tarefas = "SELECT COUNT(*) FROM tarefas WHERE status = 'pendente' OR status = 'PENDENTE'";
$total_tarefas = $pdo->query($sql_tarefas)->fetchColumn();

// 6. CONTADOR PARA DASHBOARD - TREINAMENTOS COM STATUS PENDENTES
// Contar todos os treinamentos com status 'PENDENTE' (não importa a data)
$sql_treinamentos_pendentes = "SELECT COUNT(*) FROM treinamentos WHERE status = 'PENDENTE'";
$total_treinamentos_pendentes = $pdo->query($sql_treinamentos_pendentes)->fetchColumn();

// 7. CONTADOR DE RELATÓRIOS (opcional - pode ser usado para notificações futuras)
$total_relatorios = 0; // Pode ser adaptado para contar relatórios pendentes, etc.

// 8. CONTADOR DE PENDENCIAS DE TREINAMENTOS ENCERRADOS
$total_pendencias_treinamentos = 0;
try {
    $sql_pendencias_treinamentos = "SELECT COUNT(*) FROM pendencias_treinamentos WHERE status_pendencia = 'ABERTA'";
    $total_pendencias_treinamentos = (int)$pdo->query($sql_pendencias_treinamentos)->fetchColumn();
} catch (Throwable $e) {
    $total_pendencias_treinamentos = 0;
}
?>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Implantação | Gestão de Treinamentos</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            --sidebar-width: 260px;
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --bg-light: #f8f9fc;
            --text-dark: #2d3436;
            --sidebar-bg: #1e293b;
            --dashboard-color: #10b981;
            /* Cor para Dashboard */
            --report-color: #8b5cf6;
            /* Cor para Relatórios */
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            overflow-x: hidden;
        }

        .card {
            border-radius: 12px !important;
        }

        #sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: var(--sidebar-bg);
            color: #fff;
            transition: all 0.3s;
            z-index: 1000;
            box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            background: rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h4 {
            font-weight: 700;
            letter-spacing: -0.5px;
            margin: 0;
            color: #fff;
            display: flex;
            align-items: center;
        }

        #sidebar ul.components {
            padding: 1rem 0;
        }

        #sidebar ul li a {
            padding: 0.8rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: 0.2s;
            border-left: 4px solid transparent;
            margin: 0.2rem 0.5rem;
            border-radius: 6px;
        }

        #sidebar ul li a i {
            margin-right: 12px;
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
        }

        #sidebar ul li a:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.08);
            border-left-color: var(--primary-color);
        }

        #sidebar ul li.active>a {
            color: #fff;
            background: rgba(255, 255, 255, 0.12);
            border-left-color: var(--primary-color);
            font-weight: 600;
        }

        /* Cor específica para o item de Dashboard (antigo relatorio.php) */
        #sidebar ul li a[href="index.php"] i {
            color: var(--dashboard-color);
        }

        #sidebar ul li.active>a[href="index.php"] {
            border-left-color: var(--dashboard-color);
        }

        /* Cor específica para o item de Relatórios (antigo index.php) */
        #sidebar ul li a[href="relatorio.php"] i {
            color: var(--report-color);
        }

        #sidebar ul li.active>a[href="relatorio.php"] {
            border-left-color: var(--report-color);
        }

        #content {
            width: calc(100% - var(--sidebar-width));
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: all 0.3s;
            padding: 2rem;
        }

        .badge-notify {
            font-size: 0.7rem;
            padding: 4px 8px;
            min-width: 20px;
            text-align: center;
            font-weight: 600;
        }

        /* Badge especial para Dashboard (antigo relatorio.php) */
        .badge-dashboard {
            background-color: var(--dashboard-color);
            color: white;
        }

        /* Badge para Relatórios (antigo index.php) */
        .badge-report {
            background-color: var(--report-color);
            color: white;
        }

        /* Separador visual no menu */
        .menu-separator {
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
            margin: 1rem 1.5rem;
        }

        @media (max-width: 768px) {
            #sidebar {
                margin-left: calc(-1 * var(--sidebar-width));
            }

            #content {
                width: 100%;
                margin-left: 0;
            }

            #sidebar.active {
                margin-left: 0;
            }
        }

        /* Adicionar ao CSS global */
        .text-purple {
            color: #7209b7 !important;
        }

        .btn-purple {
            background-color: #7209b7;
            border-color: #7209b7;
            color: white;
        }

        .btn-purple:hover {
            background-color: #5a08a5;
            border-color: #5a08a5;
            color: white;
        }

        .stat-icon.text-purple {
            color: #7209b7 !important;
        }
    </style>
</head>

<body>
    <div class="wrapper">
        <nav id="sidebar">
            <div class="sidebar-header">
                <h4><i class="bi bi-rocket-takeoff me-2"></i>Implantação</h4>
            </div>

            <ul class="list-unstyled components">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>

                <!-- Dashboard (antigo relatorio.php) -->
                <li class="<?= $current_page == 'index.php' ? 'active' : '' ?>">
                    <a href="index.php">
                        <span><i class="bi bi-grid-1x2"></i> Dashboard</span>
                        <!-- Se quiser adicionar contadores futuros no dashboard -->
                    </a>
                </li>
                <!-- Relatórios (antigo index.php) -->
                <li class="<?= $current_page == 'relatorio.php' ? 'active' : '' ?>">
                    <a href="relatorio.php">
                        <span><i class="bi bi-bar-chart-line"></i> Agendamentos</span>
                        <?php if ($total_treinamentos_pendentes > 0): ?>
                            <span class="badge rounded-pill badge-dashboard badge-notify" title="Treinamentos pendentes"><?= $total_treinamentos_pendentes ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="<?= $current_page == 'pendencias_treinamentos.php' ? 'active' : '' ?>">
                    <a href="pendencias_treinamentos.php">
                        <span><i class="bi bi-journal-x"></i> Acompanhamentos</span>
                        <?php if ($total_pendencias_treinamentos > 0): ?>
                            <span class="badge rounded-pill bg-danger badge-notify"><?= $total_pendencias_treinamentos ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="<?= $current_page == 'clientes.php' ? 'active' : '' ?>">
                    <a href="clientes.php">
                        <span><i class="bi bi-building"></i> Clientes</span>
                        <span class="badge rounded-pill bg-primary badge-notify"><?= $total_clientes ?></span>
                    </a>
                </li>

                <li class="<?= $current_page == 'contatos.php' ? 'active' : '' ?>">
                    <a href="contatos.php">
                        <span><i class="bi bi-person-badge"></i> Contatos</span>
                        <span class="badge rounded-pill bg-secondary badge-notify"><?= $total_contatos ?></span>
                    </a>
                </li>

                <li class="<?= $current_page == 'treinamentos.php' ? 'active' : '' ?>">
                    <a href="treinamentos.php">
                        <span><i class="bi bi-mortarboard"></i> Treinamentos</span>
                        <?php if ($total_hoje > 0): ?>
                            <span class="badge rounded-pill bg-info text-dark badge-notify" title="Para hoje"><?= $total_hoje ?></span>
                        <?php endif; ?>
                    </a>
                </li>


                <!-- Separador visual para agrupar item de suporte -->
                <div class="menu-separator"></div>



                <li class="<?= $current_page == 'pendencias.php' ? 'active' : '' ?>">
                    <a href="pendencias.php">
                        <span><i class="bi bi-exclamation-octagon"></i> Pendências</span>
                        <?php if ($total_pendencias > 0): ?>
                            <span class="badge rounded-pill bg-danger badge-notify"><?= $total_pendencias ?></span>
                        <?php endif; ?>
                    </a>
                </li>


                <li class="<?= $current_page == 'tarefas.php' ? 'active' : '' ?>">
                    <a href="tarefas.php">
                        <span><i class="bi bi-list-check"></i> Tarefas</span>
                        <?php if ($total_tarefas > 0): ?>
                            <span class="badge rounded-pill bg-warning text-dark badge-notify"><?= $total_tarefas ?></span>
                        <?php endif; ?>
                    </a>
                </li>


                <li class="<?= $current_page == 'orientacoes.php' ? 'active' : '' ?>">
                    <a href="orientacoes.php">
                        <span><i class="bi bi-question-circle"></i> Orientações</span>
                    </a>
                </li>

            </ul>
        </nav>

        <div id="content">