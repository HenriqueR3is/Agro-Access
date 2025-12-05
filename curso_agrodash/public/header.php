<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    header("Location: /dashboard");
    exit;
}



// Buscar estatísticas
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos");
    $total_cursos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos WHERE status = 'ativo'");
    $cursos_ativos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $total_usuarios = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM modulos");
    $total_modulos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT * FROM cursos ORDER BY created_at DESC LIMIT 5");
    $cursos_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-leaf"></i>
                <div class="logo-text">
                    <h1>AgroDash</h1>
                    <p>Painel Admin</p>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-title">Principal</div>
                <a href="/adminpainel" class="nav-item active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Conteúdo</div>
                <a href="/curso_agrodash/admincursos" class="nav-item">
                    <i class="fas fa-book"></i>
                    <span class="nav-text">Gerenciar Cursos</span>
                    <span class="nav-badge"><?= $total_cursos ?? 0 ?></span>
                </a>
                <a href="admin_modulos.php" class="nav-item">
                    <i class="fas fa-layer-group"></i>
                    <span class="nav-text">Gerenciar Módulos</span>
                    <span class="nav-badge"><?= $total_modulos ?? 0 ?></span>
                </a>
                <a href="admin_conteudos.php" class="nav-item">
                    <i class="fas fa-file-alt"></i>
                    <span class="nav-text">Gerenciar Conteúdos</span>
                </a>


                <a href="/curso_agrodash/loja" class="nav-item">
                    <i class="fas fa-home"></i>
                    <span class="nav-text">Loja de XP</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Usuários</div>
                <a href="admin_usuarios.php" class="nav-item">
                    <i class="fas fa-users"></i>
                    <span class="nav-text">Gerenciar Usuários</span>
                    <span class="nav-badge"><?= $total_usuarios ?? 0 ?></span>
                </a>
                <a href="admin_matriculas.php" class="nav-item">
                    <i class="fas fa-user-graduate"></i>
                    <span class="nav-text">Matrículas</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Relatórios</div>
                <a href="admin_relatorios.php" class="nav-item">
                    <i class="fas fa-chart-bar"></i>
                    <span class="nav-text">Relatórios</span>
                </a>
                <a href="admin_estatisticas.php" class="nav-item">
                    <i class="fas fa-chart-line"></i>
                    <span class="nav-text">Estatísticas</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Sistema</div>
                <a href="admin_configuracoes.php" class="nav-item">
                    <i class="fas fa-cog"></i>
                    <span class="nav-text">Configurações</span>
                </a>
                <a href="/dashboard" class="nav-item">
                    <i class="fas fa-arrow-left"></i>
                    <span class="nav-text">Voltar ao Site</span>
                </a>
                <a href="../logout.php" class="nav-item logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="nav-text">Sair</span>
                </a>
            </div>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <?= strtoupper(substr($_SESSION['usuario_nome'], 0, 1)) ?>
                </div>
                <div class="user-details">
                    <h4><?= htmlspecialchars($_SESSION['usuario_nome']) ?></h4>
                    <p><?= htmlspecialchars($_SESSION['usuario_email']) ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Header -->
        <header class="main-header">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Dashboard Administrativo</h1>
                    <p>Bem-vindo ao painel de controle da plataforma</p>
                </div>
            </div>
            
            <div class="header-right">
                <div class="header-actions">
                    <a href="admin_cursos.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        <span>Novo Curso</span>
                    </a>
                    <div class="notifications">
                        <button class="btn-icon" id="notificationsBtn">
                            <i class="fas fa-bell"></i>
                            <span class="notification-badge">3</span>
                        </button>
                    </div>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Pesquisar...">
                    </div>
                </div>
            </div>
        </header>