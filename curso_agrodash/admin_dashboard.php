<?php
// admin_dashboard.php - Dashboard Principal do Painel Admin

// 1. Inicie a sessão no TOPO do arquivo
session_start();

// 2. Verifique se o usuário está logado e é admin
if (!isset($_SESSION['usuario_tipo']) || ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev')) {
    header("Location: /curso_agrodash/dashboard");
    exit;
}

// 3. Conecte ao banco de dados
require_once __DIR__ . '/../config/db/conexao.php';

// 4. Buscar estatísticas
try {
    // Total de cursos
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos");
    $total_cursos = $stmt->fetchColumn();
    
    // Cursos ativos
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos WHERE status = 'ativo'");
    $cursos_ativos = $stmt->fetchColumn();
    
    // Total de usuários
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $total_usuarios = $stmt->fetchColumn();
    
    // Total de módulos
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM modulos");
    $total_modulos = $stmt->fetchColumn();
    

    // Cursos recentes (últimos 5)
    $stmt = $pdo->query("SELECT * FROM cursos ORDER BY data_criacao DESC LIMIT 5");
    $cursos_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    

    

    
} catch (PDOException $e) {
    $error = "Erro ao carregar estatísticas: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - AgroDash</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Variáveis de Cores - Tema Cinza e Verde */
        :root {
            /* Cores principais */
            --primary-50: #f0f9f0;
            --primary-100: #dcf2dc;
            --primary-200: #b8e5b8;
            --primary-300: #94d894;
            --primary-400: #70cb70;
            --primary-500: #4cbe4c; /* Verde principal */
            --primary-600: #3d983d;
            --primary-700: #2e722e;
            --primary-800: #1f4c1f;
            --primary-900: #102610;
            
            /* Cores neutras */
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            
            /* Cores de estado */
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
            
            /* Sombras */
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            
            /* Bordas */
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            
            /* Transições */
            --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-normal: 300ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: 500ms cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Reset e Estilos Base */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-800);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-left var(--transition-normal);
        }

        /* Header Principal */
        .main-header {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-md);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--gray-200);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .menu-toggle {
            background: none;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--gray-600);
            transition: all var(--transition-fast);
        }

        .menu-toggle:hover {
            background: var(--gray-100);
            color: var(--gray-900);
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        /* Header Direito */
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Botões */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all var(--transition-fast);
            border: none;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-500);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-600);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-800);
        }

        .btn-secondary:hover {
            background: var(--gray-300);
        }

        .btn-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: none;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--gray-600);
            position: relative;
            transition: all var(--transition-fast);
        }

        .btn-icon:hover {
            background: var(--gray-100);
            color: var(--gray-900);
        }

        /* Badge de Notificações */
        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* Content Area */
        .content-area {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
        }

        /* Alertas */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-left: 4px solid transparent;
        }

        .alert-error {
            background: #fee;
            color: #dc3545;
            border-left-color: #dc3545;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #155724;
        }

        .alert i {
            font-size: 1.25rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
            transition: all var(--transition-normal);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-200);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
        }

        .stat-icon.cursos {
            background: linear-gradient(135deg, var(--primary-100), var(--primary-300));
            color: var(--primary-700);
        }

        .stat-icon.ativos {
            background: linear-gradient(135deg, var(--success), rgba(16, 185, 129, 0.2));
            color: var(--success);
        }

        .stat-icon.usuarios {
            background: linear-gradient(135deg, var(--info), rgba(59, 130, 246, 0.2));
            color: var(--info);
        }

        .stat-icon.modulos {
            background: linear-gradient(135deg, var(--purple), rgba(139, 92, 246, 0.2));
            color: var(--purple);
        }

        .stat-icon.matriculas {
            background: linear-gradient(135deg, var(--warning), rgba(245, 158, 11, 0.2));
            color: var(--warning);
        }

        .stat-info {
            flex: 1;
        }

        .stat-info h3 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .stat-info p {
            font-size: 0.875rem;
            color: var(--gray-600);
            margin-bottom: 0.5rem;
        }

        .stat-trend {
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .trend-up {
            color: var(--success);
        }

        .trend-down {
            color: var(--danger);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header a {
            font-size: 0.875rem;
            color: var(--primary-600);
            text-decoration: none;
            font-weight: 500;
            transition: color var(--transition-fast);
        }

        .card-header a:hover {
            color: var(--primary-700);
            text-decoration: underline;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Lista de Cursos Recentes */
        .curso-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .curso-item:last-child {
            border-bottom: none;
        }

        .curso-imagem {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            background: linear-gradient(135deg, var(--primary-400), var(--primary-600));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .curso-info {
            flex: 1;
        }

        .curso-info h4 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .curso-info p {
            font-size: 0.75rem;
            color: var(--gray-600);
            line-height: 1.4;
        }

        .curso-status {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-ativo {
            background: var(--success);
            color: white;
        }

        .status-inativo {
            background: var(--gray-400);
            color: white;
        }

        .status-nao_iniciado, .status-rascunho {
            background: var(--warning);
            color: white;
        }

        /* Lista de Usuários Recentes */
        .user-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .user-item:last-child {
            border-bottom: none;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .user-info {
            flex: 1;
        }

        .user-info h4 {
            font-size: 0.875rem;
            font-weight: 600;

            margin-bottom: 0.125rem;
        }

        .user-info p {
            font-size: 0.75rem;

        }

        .user-date {
            font-size: 0.75rem;
            color: var(--gray-500);
            white-space: nowrap;
        }

        /* Ações Rápidas */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            border: 2px dashed var(--gray-300);
            border-radius: var(--radius-md);
            background: var(--gray-50);
            color: var(--gray-700);
            text-decoration: none;
            transition: all var(--transition-fast);
        }

        .action-btn:hover {
            border-color: var(--primary-500);
            background: var(--primary-50);
            color: var(--primary-700);
            transform: translateY(-2px);
        }

        .action-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            background: white;
            border: 2px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all var(--transition-fast);
        }

        .action-btn:hover .action-icon {
            border-color: var(--primary-500);
            background: var(--primary-500);
            color: white;
        }

        .action-btn span {
            font-size: 0.875rem;
            font-weight: 500;
            text-align: center;
        }

        /* Progresso dos Cursos */
        .progress-item {
            margin-bottom: 1rem;
        }

        .progress-item:last-child {
            margin-bottom: 0;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .progress-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-900);
        }

        .progress-stats {
            font-size: 0.75rem;
            color: var(--gray-600);
        }

        .progress-bar {
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-500), var(--primary-600));
            border-radius: 4px;
            transition: width 1s ease-in-out;
        }

        /* Status do Sistema */
        .system-status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .system-status-item:last-child {
            border-bottom: none;
        }

        .system-status-item span:first-child {
            font-size: 0.875rem;
            color: var(--gray-700);
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
        }

        .status-dot.warning {
            background: var(--warning);
        }

        .status-dot.danger {
            background: var(--danger);
        }

        /* Welcome Card */
        .welcome-card {
            background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
            color: white;
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
        }

        .welcome-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .welcome-header h2 {
            font-size: 1.75rem;
            font-weight: 700;
        }

        .welcome-time {
            font-size: 1rem;
            opacity: 0.9;
        }

        .welcome-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            align-items: center;
        }

        .welcome-text p {
            font-size: 1.125rem;
            margin-bottom: 1rem;
            opacity: 0.95;
        }

        .welcome-stats {
            display: flex;
            gap: 2rem;
        }

        .welcome-stat {
            text-align: center;
        }

        .welcome-stat h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .welcome-stat p {
            font-size: 0.875rem;
            opacity: 0.8;
        }

        .welcome-illustration {
            text-align: center;
        }

        .welcome-illustration i {
            font-size: 6rem;
            opacity: 0.2;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray-500);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--gray-300);
        }

        .empty-state h4 {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--gray-700);
        }

        /* Responsividade */
        @media (max-width: 1024px) {
            .sidebar {
                width: 240px;
            }
            
            .main-content {
                margin-left: 240px;
            }
            
            .welcome-content {
                grid-template-columns: 1fr;
            }
            
            .welcome-illustration {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .main-header {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }
            
            .header-left,
            .header-right {
                width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .welcome-stats {
                flex-direction: column;
                gap: 1rem;
            }
        }

        /* Animações */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stat-card, .card, .welcome-card {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>
    <!-- Inclui o sidebar via header.php -->
    <?php 
    // Defina qual aba está ativa
    $active_tab = 'dashboard';
    require_once __DIR__ . '/public/header.php'; 
    ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Header -->
        <header class="main-header">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Dashboard Admin</h1>
                    <p id="welcomeMessage">Bem-vindo de volta, <?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Administrador') ?>!</p>
                </div>
            </div>
            
            <div class="header-right">
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="window.location.href='/curso_agrodash/admincursos'">
                        <i class="fas fa-plus"></i>
                        <span>Novo Curso</span>
                    </button>
                    <button class="btn-icon" id="notificationsBtn">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge">3</span>
                    </button>
                    <button class="btn-icon" onclick="window.location.href='admin_configuracoes.php'">
                        <i class="fas fa-cog"></i>
                    </button>
                </div>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Card de Boas-vindas -->
            <div class="welcome-card">
                <div class="welcome-header">
                    <h2>Painel de Controle</h2>
                    <div class="welcome-time" id="currentDateTime"></div>
                </div>
                <div class="welcome-content">
                    <div class="welcome-text">
                        <p>Gerencie seus cursos, usuários e acompanhe o desempenho da plataforma.</p>
                        <div class="welcome-stats">
                            <div class="welcome-stat">
                                <h3><?= $total_cursos ?? 0 ?></h3>
                                <p>Cursos</p>
                            </div>
                            <div class="welcome-stat">
                                <h3><?= $total_usuarios ?? 0 ?></h3>
                                <p>Usuários</p>
                            </div>
                            <div class="welcome-stat">
                                <h3><?= $matriculas_ativas ?? 0 ?></h3>
                                <p>Matrículas Ativas</p>
                            </div>
                        </div>
                    </div>
                    <div class="welcome-illustration">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
            </div>

            <!-- Grid de Estatísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon cursos">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $total_cursos ?? 0 ?></h3>
                        <p>Total de Cursos</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-arrow-up"></i>
                            <span>+12% este mês</span>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon ativos">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $cursos_ativos ?? 0 ?></h3>
                        <p>Cursos Ativos</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-arrow-up"></i>
                            <span>+8% este mês</span>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon usuarios">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $total_usuarios ?? 0 ?></h3>
                        <p>Usuários</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-arrow-up"></i>
                            <span>+24% este mês</span>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon matriculas">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $matriculas_ativas ?? 0 ?></h3>
                        <p>Matrículas Ativas</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-arrow-up"></i>
                            <span>+18% este mês</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grid de Conteúdo -->
            <div class="content-grid">
                <!-- Coluna Esquerda -->
                <div class="left-column">
                    <!-- Cursos Recentes -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-clock"></i> Cursos Recentes</h3>
                            <a href="/curso_agrodash/admincursos">Ver Todos</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($cursos_recentes)): ?>
                                <?php foreach ($cursos_recentes as $curso): ?>
                                <div class="curso-item">
                                    <div class="curso-imagem">
                                        <i class="fas fa-graduation-cap"></i>
                                    </div>
                                    <div class="curso-info">
                                        <h4><?= htmlspecialchars($curso['titulo']) ?></h4>
                                        <p><?= htmlspecialchars(substr($curso['descricao'] ?? '', 0, 60)) ?>...</p>
                                    </div>
                                    <span class="curso-status status-<?= str_replace(' ', '_', strtolower($curso['status'] ?? 'nao_iniciado')) ?>">
                                        <?= ucfirst($curso['status'] ?? 'Não Iniciado') ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-book"></i>
                                    <h4>Nenhum curso encontrado</h4>
                                    <p>Crie seu primeiro curso para começar!</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Progresso dos Cursos -->
                    <div class="card" style="margin-top: 1.5rem;">
                        <div class="card-header">
                            <h3><i class="fas fa-chart-line"></i> Cursos Populares</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($cursos_populares)): ?>
                                <?php foreach ($cursos_populares as $curso): ?>
                                <div class="progress-item">
                                    <div class="progress-header">
                                        <span class="progress-title"><?= htmlspecialchars($curso['titulo']) ?></span>
                                        <span class="progress-stats">
                                            <?= intval($curso['alunos'] ?? 0) ?> alunos • 
                                            <?= intval($curso['progresso_medio'] ?? 0) ?>%
                                        </span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?= min(100, intval($curso['progresso_medio'] ?? 0)) ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-chart-bar"></i>
                                    <h4>Sem dados de progresso</h4>
                                    <p>Os dados aparecerão quando houver matrículas</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Coluna Direita -->
                <div class="right-column">
                    <!-- Usuários Recentes -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-users"></i> Usuários Recentes</h3>
                            <a href="admin_usuarios.php">Ver Todos</a>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($usuarios_recentes)): ?>
                                <?php foreach ($usuarios_recentes as $usuario): ?>
                                <div class="user-item">
                                    <div class="user-avatar">
                                        <?= strtoupper(substr($usuario['nome'] ?? 'U', 0, 1)) ?>
                                    </div>
                                    <div class="user-info">
                                        <h4><?= htmlspecialchars($usuario['nome'] ?? 'Usuário') ?></h4>
                                        <p><?= htmlspecialchars($usuario['email'] ?? 'email@exemplo.com') ?></p>
                                    </div>
                                    <div class="user-date">
                                        <?= date('d/m', strtotime($usuario['data_criacao'] ?? 'now')) ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-user"></i>
                                    <h4>Nenhum usuário encontrado</h4>
                                    <p>Os usuários aparecerão aqui quando se cadastrarem</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Ações Rápidas -->
                    <div class="card" style="margin-top: 1.5rem;">
                        <div class="card-header">
                            <h3><i class="fas fa-bolt"></i> Ações Rápidas</h3>
                        </div>
                        <div class="card-body">
                            <div class="quick-actions">
                                <a href="/curso_agrodash/admincursos" class="action-btn">
                                    <div class="action-icon">
                                        <i class="fas fa-book"></i>
                                    </div>
                                    <span>Novo Curso</span>
                                </a>
                                <a href="/curso_agrodash/adminmodulos" class="action-btn">
                                    <div class="action-icon">
                                        <i class="fas fa-layer-group"></i>
                                    </div>
                                    <span>Novo Módulo</span>
                                </a>
                                <a href="admin_usuarios.php" class="action-btn">
                                    <div class="action-icon">
                                        <i class="fas fa-user-plus"></i>
                                    </div>
                                    <span>Novo Usuário</span>
                                </a>
                                <a href="admin_relatorios.php" class="action-btn">
                                    <div class="action-icon">
                                        <i class="fas fa-chart-bar"></i>
                                    </div>
                                    <span>Relatórios</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Status do Sistema -->
                    <div class="card" style="margin-top: 1.5rem;">
                        <div class="card-header">
                            <h3><i class="fas fa-server"></i> Status do Sistema</h3>
                        </div>
                        <div class="card-body">
                            <div class="system-status-item">
                                <span>Servidor Web</span>
                                <div class="status-indicator">
                                    <div class="status-dot"></div>
                                    <span style="color: var(--success); font-weight: 600;">Online</span>
                                </div>
                            </div>
                            <div class="system-status-item">
                                <span>Banco de Dados</span>
                                <div class="status-indicator">
                                    <div class="status-dot"></div>
                                    <span style="color: var(--success); font-weight: 600;">Conectado</span>
                                </div>
                            </div>
                            <div class="system-status-item">
                                <span>Armazenamento</span>
                                <div class="status-indicator">
                                    <div class="status-dot warning"></div>
                                    <span style="color: var(--warning); font-weight: 600;">85% Livre</span>
                                </div>
                            </div>
                            <div class="system-status-item">
                                <span>Última Atualização</span>
                                <span id="lastUpdate" style="color: var(--gray-600); font-weight: 500; font-size: 0.875rem;">
                                    <?= date('d/m/Y H:i') ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Atualizar data e hora
        function updateDateTime() {
            const now = new Date();
            const dateTimeElement = document.getElementById('currentDateTime');
            const lastUpdateElement = document.getElementById('lastUpdate');
            
            const date = now.toLocaleDateString('pt-BR', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            const time = now.toLocaleTimeString('pt-BR', {
                hour: '2-digit',
                minute: '2-digit'
            });
            
            if (dateTimeElement) {
                dateTimeElement.textContent = `${date} • ${time}`;
            }
            
            if (lastUpdateElement) {
                lastUpdateElement.textContent = now.toLocaleTimeString('pt-BR', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }
        }

        // Atualizar mensagem de boas-vindas com base na hora
        function updateWelcomeMessage() {
            const hour = new Date().getHours();
            let greeting;
            
            if (hour < 12) {
                greeting = 'Bom dia';
            } else if (hour < 18) {
                greeting = 'Boa tarde';
            } else {
                greeting = 'Boa noite';
            }
            
            const welcomeElement = document.getElementById('welcomeMessage');
            const userName = '<?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Administrador') ?>';
            
            if (welcomeElement) {
                welcomeElement.textContent = `${greeting}, ${userName}!`;
            }
        }

        // Menu Mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });

        // Fechar sidebar ao clicar fora (mobile)
        document.addEventListener('click', function(e) {
            const sidebar = document.querySelector('.sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 768 && 
                sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && 
                !menuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        });

        // Notificações
        document.getElementById('notificationsBtn').addEventListener('click', function() {
            showNotification('Sistema de notificações em desenvolvimento!', 'info');
        });

        // Sistema de notificação (mesmo do admin_cursos)
        function showNotification(message, type = 'info') {
            // Remove notificações existentes
            const existing = document.querySelector('.custom-notification');
            if (existing) existing.remove();
            
            // Cria a notificação
            const notification = document.createElement('div');
            notification.className = `custom-notification ${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            `;
            
            document.body.appendChild(notification);
            
            // Adiciona estilos
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
                color: white;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                display: flex;
                align-items: center;
                gap: 0.75rem;
                box-shadow: 0 10px 25px rgba(0,0,0,0.2);
                z-index: 9999;
                animation: slideIn 0.3s ease;
                max-width: 400px;
            `;
            
            // Adiciona animação
            if (!document.querySelector('#slideInAnimation')) {
                const style = document.createElement('style');
                style.id = 'slideInAnimation';
                style.textContent = `
                    @keyframes slideIn {
                        from { transform: translateX(100%); opacity: 0; }
                        to { transform: translateX(0); opacity: 1; }
                    }
                    @keyframes slideOut {
                        from { transform: translateX(0); opacity: 1; }
                        to { transform: translateX(100%); opacity: 0; }
                    }
                `;
                document.head.appendChild(style);
            }
            
            // Remove automaticamente após 5 segundos
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Atualizar progress bars com animação
        document.addEventListener('DOMContentLoaded', function() {
            updateDateTime();
            updateWelcomeMessage();
            
            // Atualizar data/hora a cada minuto
            setInterval(updateDateTime, 60000);
            
            // Animar barras de progresso
            const progressBars = document.querySelectorAll('.progress-fill');
            progressBars.forEach(bar => {
                const originalWidth = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = originalWidth;
                }, 300);
            });
        });

        // Auto-refresh stats (simulado)
        setInterval(() => {
            console.log('Dashboard atualizado às', new Date().toLocaleTimeString());
        }, 300000); // 5 minutos
    </script>
</body>
</html>