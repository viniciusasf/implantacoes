<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Implantação Pro | Gestão de Treinamentos</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
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
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            background: rgba(0,0,0,0.1);
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
            color: rgba(255,255,255,0.7);
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
            background: rgba(255,255,255,0.05);
            border-left-color: var(--primary-color);
        }

        #sidebar ul li.active > a {
            color: #fff;
            background: rgba(255,255,255,0.1);
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
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            justify-content: flex-end;
            align-items: center;
        }

        /* Card Styles */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
            transition: transform 0.2s;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-weight: 500;
        }

        .table thead th {
            background-color: #f8f9fc;
            text-transform: uppercase;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-top: none;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        /* Scrollable Table Container */
        .table-scroll-container {
            max-height: 500px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-color) #f1f1f1;
        }

        .table-scroll-container::-webkit-scrollbar {
            width: 6px;
        }

        .table-scroll-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .table-scroll-container::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 10px;
        }

        .table-scroll-container::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
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
        <!-- Sidebar -->
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
                <li class="<?= $current_page == 'tarefas.php' ? 'active' : '' ?>">
                    <a href="tarefas.php"><i class="bi-list-check"></i> Tarefas</a>
                </li>
            </ul>
        </nav>

        <!-- Page Content -->
        <div id="content">
            <div class="top-nav">
                <div class="user-info d-flex align-items-center">
                    <span class="me-2 fw-medium">Administrador</span>
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 35px; height: 35px;">
                        <i class="bi bi-person"></i>
                    </div>
                </div>
            </div>
