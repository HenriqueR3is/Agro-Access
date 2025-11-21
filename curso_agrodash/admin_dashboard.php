<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    header("Location: dashboard.php");
    exit;
}

// Buscar estatísticas
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
    
    // Cursos recentes
    $stmt = $pdo->query("SELECT * FROM cursos ORDER BY created_at DESC LIMIT 5");
    $cursos_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Usuários recentes
    $stmt = $pdo->query("SELECT nome, email, created_at FROM usuarios ORDER BY created_at DESC LIMIT 5");
    $usuarios_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #32CD32;
            --primary-dark: #228B22;
            --secondary: #667eea;
            --dark: #2c3e50;
            --light: #f8f9fa;
            --gray: #6c757d;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--dark);
            color: white;
            height: 100vh;
            position: fixed;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 30px 25px;
            background: var(--primary);
            text-align: center;
        }

        .sidebar-header h1 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .sidebar-nav {
            padding: 20px 0;
        }

        .nav-section {
            margin-bottom: 25px;
        }

        .nav-title {
            padding: 0 25px 10px 25px;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: var(--gray);
            font-weight: 500;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 10px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            border-left-color: var(--primary);
        }

        .nav-item.active {
            background: rgba(50, 205, 50, 0.1);
            color: white;
            border-left-color: var(--primary);
        }

        .nav-item i {
            width: 20px;
            margin-right: 12px;
            font-size: 1.1rem;
        }

        .nav-badge {
            background: var(--primary);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: auto;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
        }

        .content-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header-title h1 {
            font-size: 2rem;
            color: white;
            margin-bottom: 5px;
        }

        .header-title p {
            color: rgba(255,255,255,0.8);
        }

        .header-actions {
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.3);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            font-size: 1.5rem;
        }

        .icon-primary { background: rgba(50, 205, 50, 0.1); color: var(--primary); }
        .icon-secondary { background: rgba(102, 126, 234, 0.1); color: var(--secondary); }
        .icon-success { background: rgba(40, 167, 69, 0.1); color: var(--success); }
        .icon-warning { background: rgba(255, 193, 7, 0.1); color: var(--warning); }

        .stat-info h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .stat-info p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 1.2rem;
            color: var(--dark);
            font-weight: 600;
        }

        .card-header a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .card-body {
            padding: 25px;
        }

        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .table th {
            background: var(--light);
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .table tr:hover {
            background: var(--light);
        }

        .curso-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .curso-item:last-child {
            border-bottom: none;
        }

        .curso-imagem {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            background: var(--primary);
            margin-right: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        .curso-info {
            flex: 1;
        }

        .curso-info h4 {
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .curso-info p {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .curso-status {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-ativo { background: #d4edda; color: #155724; }
        .status-inativo { background: #f8d7da; color: #721c24; }

        /* User List */
        .user-item {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .user-item:last-child {
            border-bottom: none;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--secondary);
            margin-right: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .user-info {
            flex: 1;
        }

        .user-info h4 {
            font-size: 0.95rem;
            color: var(--dark);
            margin-bottom: 3px;
        }

        .user-info p {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .user-date {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 20px;
        }

        .action-btn {
            background: var(--light);
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--dark);
        }

        .action-btn:hover {
            border-color: var(--primary);
            background: rgba(50, 205, 50, 0.05);
        }

        .action-icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .action-btn span {
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 250px;
            }
            .main-content {
                margin-left: 250px;
            }
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .mobile-menu-btn {
                display: block;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h1><i class="fas fa-graduation-cap"></i> AgroDash Admin</h1>
            <p>Painel de Controle</p>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-title">Principal</div>
                <a href="admin_dashboard.php" class="nav-item active">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Conteúdo</div>
                <a href="admin_cursos.php" class="nav-item">
                    <i class="fas fa-book"></i>
                    Gerenciar Cursos
                    <span class="nav-badge"><?= $total_cursos ?? 0 ?></span>
                </a>
                <a href="admin_modulos.php" class="nav-item">
                    <i class="fas fa-layer-group"></i>
                    Gerenciar Módulos
                    <span class="nav-badge"><?= $total_modulos ?? 0 ?></span>
                </a>
                <a href="admin_conteudos.php" class="nav-item">
                    <i class="fas fa-file-alt"></i>
                    Gerenciar Conteúdos
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Usuários</div>
                <a href="admin_usuarios.php" class="nav-item">
                    <i class="fas fa-users"></i>
                    Gerenciar Usuários
                    <span class="nav-badge"><?= $total_usuarios ?? 0 ?></span>
                </a>
                <a href="admin_matriculas.php" class="nav-item">
                    <i class="fas fa-user-graduate"></i>
                    Matrículas
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Relatórios</div>
                <a href="admin_relatorios.php" class="nav-item">
                    <i class="fas fa-chart-bar"></i>
                    Relatórios
                </a>
                <a href="admin_estatisticas.php" class="nav-item">
                    <i class="fas fa-chart-line"></i>
                    Estatísticas
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Sistema</div>
                <a href="admin_configuracoes.php" class="nav-item">
                    <i class="fas fa-cog"></i>
                    Configurações
                </a>
                <a href="../dashboard.php" class="nav-item">
                    <i class="fas fa-arrow-left"></i>
                    Voltar ao Site
                </a>
                <a href="../logout.php" class="nav-item">
                    <i class="fas fa-sign-out-alt"></i>
                    Sair
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="content-header">
            <div class="header-title">
                <h1>Dashboard Administrativo</h1>
                <p>Bem-vindo ao painel de controle da plataforma</p>
            </div>
            <div class="header-actions">
                <a href="admin_cursos.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Novo Curso
                </a>
                <button class="btn btn-secondary" id="mobile-menu-btn">
                    <i class="fas fa-bars"></i> Menu
                </button>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-primary">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $total_cursos ?? 0 ?></h3>
                    <p>Total de Cursos</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-secondary">
                    <i class="fas fa-play-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $cursos_ativos ?? 0 ?></h3>
                    <p>Cursos Ativos</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-success">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $total_usuarios ?? 0 ?></h3>
                    <p>Usuários Cadastrados</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-warning">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $total_modulos ?? 0 ?></h3>
                    <p>Módulos Criados</p>
                </div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Left Column -->
            <div class="left-column">
                <!-- Cursos Recentes -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> Cursos Recentes</h3>
                        <a href="admin_cursos.php">Ver Todos</a>
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
                                    <p><?= htmlspecialchars($curso['descricao']) ?></p>
                                </div>
                                <span class="curso-status status-<?= $curso['status'] ?>">
                                    <?= ucfirst($curso['status']) ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: var(--gray); padding: 20px;">
                                Nenhum curso encontrado
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Ações Rápidas -->
                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h3><i class="fas fa-bolt"></i> Ações Rápidas</h3>
                    </div>
                    <div class="card-body">
                        <div class="quick-actions">
                            <a href="admin_cursos.php" class="action-btn">
                                <div class="action-icon">
                                    <i class="fas fa-book"></i>
                                </div>
                                <span>Novo Curso</span>
                            </a>
                            <a href="admin_modulos.php" class="action-btn">
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
            </div>

            <!-- Right Column -->
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
                                    <?= strtoupper(substr($usuario['nome'], 0, 1)) ?>
                                </div>
                                <div class="user-info">
                                    <h4><?= htmlspecialchars($usuario['nome']) ?></h4>
                                    <p><?= htmlspecialchars($usuario['email']) ?></p>
                                </div>
                                <div class="user-date">
                                    <?= date('d/m', strtotime($usuario['created_at'])) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: var(--gray); padding: 20px;">
                                Nenhum usuário encontrado
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Status do Sistema -->
                <div class="card" style="margin-top: 30px;">
                    <div class="card-header">
                        <h3><i class="fas fa-server"></i> Status do Sistema</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #e9ecef;">
                            <span>Servidor</span>
                            <span style="color: var(--success); font-weight: 500;">Online</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #e9ecef;">
                            <span>Banco de Dados</span>
                            <span style="color: var(--success); font-weight: 500;">Conectado</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #e9ecef;">
                            <span>Uploads</span>
                            <span style="color: var(--success); font-weight: 500;">Ativo</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0;">
                            <span>Última Atualização</span>
                            <span style="color: var(--gray); font-size: 0.9rem;"><?= date('d/m/Y H:i') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-btn').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.sidebar');
            const mobileBtn = document.getElementById('mobile-menu-btn');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !mobileBtn.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });

        // Auto-refresh stats every 5 minutes
        setInterval(() => {
            // You can implement AJAX refresh here if needed
            console.log('Stats auto-refresh');
        }, 300000);
    </script>
</body>
</html>