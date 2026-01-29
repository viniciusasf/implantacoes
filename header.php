<!DOCTYPE html>
<html lang="pt-br">

<?php
require_once 'config.php'; // Garante a conexão para as consultas do header


// --- LÓGICA DO CONTADOR DE PENDÊNCIAS CORRIGIDA ---
$sql_count_pendencias = "
    SELECT c.id_cliente
    FROM clientes c
    LEFT JOIN treinamentos t ON c.id_cliente = t.id_cliente
    WHERE (c.data_fim IS NULL OR c.data_fim = '0000-00-00')
    AND c.id_cliente NOT IN (SELECT id_cliente FROM treinamentos WHERE status = 'PENDENTE')
    GROUP BY c.id_cliente, c.data_inicio
    HAVING (MAX(t.data_treinamento) < DATE_SUB(CURDATE(), INTERVAL 3 DAY)) 
       OR (MAX(t.data_treinamento) IS NULL AND c.data_inicio < DATE_SUB(CURDATE(), INTERVAL 3 DAY))";

$res_count = $pdo->query($sql_count_pendencias);
$total_atrasos = $res_count ? $res_count->rowCount() : 0;
?>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Implantação Pro | Gestão de Treinamentos</title>
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
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            overflow-x: hidden;
        }

        /* Sidebar Style */
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
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            background: rgba(0, 0, 0, 0.1);
        }

        .sidebar-header h4 {
            font-weight: 700;
            letter-spacing: -0.5px;
            margin: 0;
            color: #fff;
        }

        #sidebar ul.components {
            padding: 1.5rem 0;
        }

        #sidebar ul li a {
            padding: 0.8rem 1.5rem;
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: 0.2s;
            border-left: 4px solid transparent;
        }

        #sidebar ul li a i {
            margin-right: 12px;
            font-size: 1.2rem;
        }

        #sidebar ul li a:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.05);
            border-left-color: var(--primary-color);
        }

        #sidebar ul li.active>a {
            color: #fff;
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--primary-color);
            font-weight: 600;
        }

        /* Content Style */
        #content {
            width: calc(100% - var(--sidebar-width));
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: all 0.3s;
            padding: 2rem;
        }

        .top-nav {
            background: #fff;
            padding: 1rem 2rem;
            margin: -2rem -2rem 2rem -2rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            display: flex;
            justify-content: flex-end;
            align-items: center;
        }

        /* Notificação Badge */
        .badge-notify {
            font-size: 0.7rem;
            padding: 4px 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        @media (max-width: 768px) {
            #sidebar { margin-left: calc(-1 * var(--sidebar-width)); }
            #content { width: 100%; margin-left: 0; }
            #sidebar.active { margin-left: 0; }
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
                
                <li class="<?= $current_page == 'index.php' ? 'active' : '' ?>">
                    <a href="index.php"><i class="bi bi-grid-1x2"></i> Dashboard</a>
                </li>
                
                <li class="<?= $current_page == 'clientes.php' ? 'active' : '' ?>">
                    <a href="clientes.php"><i class="bi bi-building"></i> Clientes</a>
                </li>
                
                <li class="<?= $current_page == 'contatos.php' ? 'active' : '' ?>">
                    <a href="contatos.php"><i class="bi bi-person-badge"></i> Contatos</a>
                </li>
                
                <li class="<?= $current_page == 'treinamentos.php' ? 'active' : '' ?>">
                    <a href="treinamentos.php"><i class="bi bi-mortarboard"></i> Treinamentos</a>
                </li>

                <li class="<?= $current_page == 'pendencias.php' ? 'active' : '' ?>">
                    <a href="pendencias.php" class="d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-exclamation-octagon"></i> Pendências</span>
                        <?php if ($total_atrasos > 0): ?>
                            <span class="badge rounded-pill bg-danger badge-notify">
                                <?= $total_atrasos ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="<?= $current_page == 'tarefas.php' ? 'active' : '' ?>">
                    <a href="tarefas.php"><i class="bi bi-list-check"></i> Tarefas</a>
                </li>
                
                <li class="<?= $current_page == 'orientacoes.php' ? 'active' : '' ?>">
                    <a href="orientacoes.php"><i class="bi bi-question-circle"></i> Orientações</a>
                </li>
            </ul>
        </nav>

        <div id="content">
            <div class="top-nav">
                <div class="user-info d-flex align-items-center">
                    <span class="me-2 fw-medium">Administrador</span>
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 35px; height: 35px;">
                        <i class="bi bi-person"></i>
                    </div>
                </div>
            </div>