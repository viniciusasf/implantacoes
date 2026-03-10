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
$sql_treinamentos_pendentes = "SELECT COUNT(*) FROM treinamentos WHERE status = 'PENDENTE'";
$total_treinamentos_pendentes = $pdo->query($sql_treinamentos_pendentes)->fetchColumn();

// 7. CONTADOR DE RELATÓRIOS (opcional)
$total_relatorios = 0; 

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
    <title>Implantação | Dashboard Gestão</title>
    
    <!-- // web fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/dark-mode.css">
    
    <!-- Script FOUC Prevention para Modo Escuro -->
    <script>
        (function() {
            var storedTheme = localStorage.getItem('theme');
            var systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (storedTheme === 'dark' || (!storedTheme && systemPrefersDark)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    
    <style>
        /* // design tokens */
        :root {
            --primary: #4361ee;       
            --primary-hover: #3a56d4;
            --primary-light: rgba(67, 97, 238, 0.1);
            
            --secondary: #2b2d42;     
            
            --success: #10b981;
            --success-light: rgba(16, 185, 129, 0.1);
            
            --warning: #f59e0b;
            --warning-light: rgba(245, 158, 11, 0.1);
            
            --danger: #ef4444;
            --danger-light: rgba(239, 68, 68, 0.1);
            
            --info: #0ea5e9;
            --info-light: rgba(14, 165, 233, 0.1);
            
            --purple: #8b5cf6;
            --purple-light: rgba(139, 92, 246, 0.1);

            --bg-body: #f4f7fe;       
            --bg-card: #ffffff;
            
            --sidebar-bg: #0b1437;    
            --sidebar-text: #a3aedd;  
            --sidebar-text-hover: #ffffff;
            --sidebar-active-bg: rgba(67, 97, 238, 0.15);
            --sidebar-active-border: #4361ee;
            
            --text-main: #2b3674;     
            --text-muted: #a3aedd;    
            --text-dark: #1b2559;

            --font-heading: 'Poppins', sans-serif;
            --font-body: 'Inter', sans-serif;

            --sidebar-width: 270px;
            
            --shadow-sm: 0px 2px 4px rgba(0, 0, 0, 0.02);
            --shadow-card: 0px 18px 40px rgba(112, 144, 176, 0.12);
            --shadow-hover: 0px 20px 45px rgba(112, 144, 176, 0.2);
            --shadow-glow: 0px 0px 15px rgba(67, 97, 238, 0.4);
            
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 20px;
            --radius-pill: 50px;
        }

        /* // global resets */
        body {
            font-family: var(--font-body);
            background-color: var(--bg-body);
            color: var(--text-dark);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            letter-spacing: -0.01em;
        }

        h1, h2, h3, h4, h5, h6, .navbar-brand {
            font-family: var(--font-heading);
            color: var(--text-dark);
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        /* // sidebar */
        #sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
            z-index: 1000;
            overflow-y: auto;
            border-right: 1px solid rgba(255,255,255,0.05);
        }

        #sidebar::-webkit-scrollbar {
            width: 6px;
        }
        #sidebar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
        }

        .sidebar-header {
            padding: 2.2rem 1.8rem 1.2rem;
            display: flex;
            align-items: center;
        }

        .sidebar-header h4 {
            font-weight: 800;
            font-size: 1.4rem;
            margin: 0;
            color: #ffffff;
            display: flex;
            align-items: center;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .sidebar-header h4 i {
            color: var(--primary);
            filter: drop-shadow(0 0 8px rgba(67, 97, 238, 0.6));
            margin-right: 10px;
        }

        .menu-separator {
            height: 1px;
            background: rgba(255, 255, 255, 0.06);
            margin: 1.2rem 1.8rem;
        }

        .menu-group-title {
            padding: 0.5rem 1.8rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: rgba(255, 255, 255, 0.4);
            font-weight: 700;
            font-family: var(--font-heading);
            margin-top: 0.5rem;
        }

        #sidebar ul.components {
            padding: 0 0 6rem 0; /* extra padding to prevent footer overlap */
        }

        #sidebar ul li a {
            padding: 0.85rem 1.5rem;
            margin: 0.3rem 1.2rem;
            display: flex;
            align-items: center;
            color: var(--sidebar-text);
            text-decoration: none;
            border-radius: var(--radius-md);
            transition: all 0.3s ease;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        #sidebar ul li a i {
            margin-right: 14px;
            font-size: 1.25rem;
            width: 24px;
            text-align: center;
            transition: all 0.3s ease;
            color: var(--sidebar-text);
        }

        #sidebar ul li a:hover {
            color: var(--sidebar-text-hover);
            background: rgba(255, 255, 255, 0.04);
            transform: translateX(4px);
        }
        
        #sidebar ul li a:hover i {
            color: var(--primary);
            transform: scale(1.1);
        }

        #sidebar ul li.active>a {
            color: var(--sidebar-text-hover);
            background: var(--sidebar-active-bg);
            font-weight: 600;
            box-shadow: inset 3px 0 0 var(--sidebar-active-border);
        }
        
        #sidebar ul li.active>a i {
            color: var(--primary);
            filter: drop-shadow(0 0 5px rgba(67, 97, 238, 0.5));
        }

        .badge-notify {
            font-family: var(--font-body);
            font-size: 0.75rem;
            font-weight: 700;
            margin-left: auto;
        }

        /* // Sidebar Highlights for Conversions */
        .highlight-item>a {
            background: linear-gradient(90deg, rgba(16,185,129,0.05) 0%, transparent 100%);
        }
        .highlight-item.active>a {
            box-shadow: inset 3px 0 0 var(--success);
            background: rgba(16, 185, 129, 0.15);
        }
        .highlight-item.active>a i, .highlight-item>a:hover i {
            color: var(--success) !important;
            filter: drop-shadow(0 0 5px rgba(16, 185, 129, 0.5)) !important;
        }

        .highlight-item-2>a {
            background: linear-gradient(90deg, rgba(67,97,238,0.05) 0%, transparent 100%);
        }

        /* // main content */
        #content {
            width: calc(100% - var(--sidebar-width));
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
            padding: 2.5rem 3rem;
        }

        @media (max-width: 992px) {
            #sidebar {
                margin-left: calc(-1 * var(--sidebar-width));
            }
            #content {
                width: 100%;
                margin-left: 0;
                padding: 1.5rem;
            }
        }
        
        /* // generic UI components */
        .card {
            border: none;
            border-radius: var(--radius-lg);
            background: var(--bg-card);
            box-shadow: var(--shadow-card);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: var(--shadow-hover);
        }

        .btn {
            font-weight: 600;
            font-family: var(--font-body);
            border-radius: var(--radius-sm);
            padding: 0.6rem 1.2rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
            color: #fff;
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.2);
        }
        
        .btn-primary:hover, .btn-primary:focus {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            box-shadow: 0 6px 15px rgba(67, 97, 238, 0.3);
            transform: translateY(-2px);
        }

        .btn-outline-primary {
            border: 2px solid var(--primary);
            color: var(--primary);
            background: transparent;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .form-control, .form-select {
            border-radius: var(--radius-md);
            border: 1px solid #e2e8f0;
            padding: 0.6rem 1rem;
            font-size: 0.95rem;
            color: var(--text-dark);
            background-color: #f8fafc;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            background-color: #fff;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-light);
        }

        .text-purple { color: var(--purple) !important; }
        .btn-purple {
            background-color: var(--purple); border-color: var(--purple); color: white;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.2);
        }
        .btn-purple:hover {
            background-color: #7c3aed; border-color: #7c3aed; color: white;
            box-shadow: 0 6px 15px rgba(139, 92, 246, 0.3); transform: translateY(-2px);
        }
    </style>
</head>

<body>
    <div class="wrapper">
        <!-- // sidebar hero -->
        <nav id="sidebar">
            <div class="sidebar-header" style="justify-content: space-between;">
                <h4><i class="bi bi-hexagon-fill"></i> </h4>
                <button id="theme-toggle" class="btn btn-sm text-white" style="background: rgba(255,255,255,0.1); border:none; padding: 0.4rem 0.6rem; border-radius: 8px;" aria-label="Toggle Dark Mode" title="Alternar Tema Escuro">
                    <i id="theme-toggle-icon" class="bi bi-moon-stars" style="margin: 0; filter: drop-shadow(0 0 5px rgba(255,255,255,0.5));"></i>
                </button>
            </div>

            <ul class="list-unstyled components">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>

                <!-- // section: operação -->
                <li class="menu-group-title">Operação</li>

                <li class="<?= $current_page == 'treinamentos.php' ? 'active' : '' ?>">
                    <a href="treinamentos.php">
                        <i class="bi bi-calendar-check text-primary"></i> <span>Treinamentos</span>
                        <?php if ($total_treinamentos_pendentes > 0): ?>
                            <span class="badge-notify text-white ms-auto" title="Pendentes"><?= $total_treinamentos_pendentes ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <div class="menu-separator"></div>

                <!-- // section: relacionamento -->
                <li class="menu-group-title">Relacionamento</li>

                <li class="highlight-item-2 <?= $current_page == 'clientes.php' ? 'active' : '' ?>">
                    <a href="clientes.php">
                        <i class="bi bi-buildings"></i> <span>Clientes</span>
                        <span class="badge-notify text-white ms-auto"><?= $total_clientes ?></span>
                    </a>
                </li>

                <li class="<?= $current_page == 'contatos.php' ? 'active' : '' ?>">
                    <a href="contatos.php">
                        <i class="bi bi-people"></i> <span>Contatos</span>
                        <span class="badge-notify text-white ms-auto"><?= $total_contatos ?></span>
                    </a>
                </li>

                <div class="menu-separator"></div>

                <!-- // section: gestão -->
                <li class="menu-group-title">Gestão</li>

                <li class="<?= $current_page == 'index.php' ? 'active' : '' ?>">
                    <a href="index.php">
                        <i class="bi bi-pie-chart-fill"></i> <span>Dashboard</span>
                    </a>
                </li>


                <!-- // section: apoio -->
                <li class="menu-group-title">Apoio</li>

                <li class="<?= $current_page == 'orientacoes.php' ? 'active' : '' ?>">
                    <a href="orientacoes.php">
                        <i class="bi bi-info-circle"></i> <span>Orientações</span>
                    </a>
                </li>

            </ul>
            
            <!-- // rodapé usuario -->
            <div style="position: fixed; bottom: 0; width: var(--sidebar-width); padding: 1.5rem; border-top: 1px solid rgba(255,255,255,0.05); background: var(--sidebar-bg); z-index: 10;">
                <div class="d-flex align-items-center">
                    <div style="width: 36px; height: 36px; border-radius: 12px; background: linear-gradient(135deg, var(--primary), var(--purple)); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white;">
                        <i class="bi bi-person"></i>
                    </div>
                    <div class="ms-3">
                        <h6 class="mb-0 text-white" style="font-size: 0.85rem; font-weight: 600;">Monitoramento</h6>
                        <small style="font-size: 0.7rem; color: var(--sidebar-text);">Admin Implantação</small>
                    </div>
                </div>
            </div>
        </nav>

        <!-- // area principal -->
        <main id="content">
