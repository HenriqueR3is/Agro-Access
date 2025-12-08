<?php
// header.php - APENAS HTML/CSS do sidebar, sem lógica PHP complexa
// A verificação de autenticação deve ser feita no arquivo principal
?>

<style>
    /* BARRA DE ROLAGEM PERSONALIZADA */
    .sidebar-nav::-webkit-scrollbar {
        width: 8px;
    }
    
    .sidebar-nav::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 4px;
        margin: 4px 0;
    }
    
    .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 4px;
        transition: background var(--transition-fast);
    }
    
    .sidebar-nav::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.3);
    }
    
    .sidebar-nav::-webkit-scrollbar-thumb:active {
        background: rgba(255, 255, 255, 0.4);
    }
    
    /* Para Firefox */
    .sidebar-nav {
        scrollbar-width: thin;
        scrollbar-color: rgba(255, 255, 255, 0.2) rgba(255, 255, 255, 0.05);
    }
    
    /* Smooth scrolling */
    .sidebar-nav {
        scroll-behavior: smooth;
        overflow-y: auto;
        overflow-x: hidden;
    }



            /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--gray-900) 0%, var(--gray-800) 100%);
            color: white;
            height: 100vh;
            position: fixed;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            transition: transform var(--transition-normal);
            box-shadow: var(--shadow-xl);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo i {
            font-size: 2rem;
            color: var(--primary-500);
            background: rgba(76, 190, 76, 0.1);
            width: 48px;
            height: 48px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .logo-text p {
            font-size: 0.75rem;
            opacity: 0.7;
            font-weight: 500;
        }

        /* Navegação */
        .sidebar-nav {
            flex: 1;
            padding: 1rem 0;
            overflow-y: auto;
        }

        .nav-section {
            margin-bottom: 1.5rem;
        }

        .nav-title {
            padding: 0 1.5rem 0.5rem 1.5rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--gray-400);
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: var(--gray-300);
            text-decoration: none;
            transition: all var(--transition-fast);
            position: relative;
            margin: 0.125rem 0;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
            padding-left: 1.75rem;
        }

        .nav-item.active {
            background: rgba(76, 190, 76, 0.1);
            color: white;
            border-left: 3px solid var(--primary-500);
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: var(--primary-500);
        }

        .nav-item i {
            font-size: 1.125rem;
            width: 24px;
            text-align: center;
        }

        .nav-text {
            flex: 1;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .nav-badge {
            background: var(--primary-500);
            color: white;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            min-width: 1.75rem;
            text-align: center;
        }

        .nav-item.logout {
            color: var(--danger);
            margin-top: 0.5rem;
        }

        .nav-item.logout:hover {
            background: rgba(239, 68, 68, 0.1);
        }

        /* Footer da Sidebar */
        .sidebar-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(0, 0, 0, 0.2);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
            font-size: 1rem;
        }

        .user-details h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.125rem;
        }

        .user-details p {
            font-size: 0.75rem;
            opacity: 0.7;
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #0da271;
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

        /* Search Box */
        .search-box {
            position: relative;
            width: 240px;
        }

        .search-box i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
        }
</style>

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
            <a href="/curso_agrodash/adminpainel" class="nav-item <?= (isset($active_tab) && $active_tab == 'dashboard') ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-title">Conteúdo</div>
            <a href="/curso_agrodash/admincursos" class="nav-item <?= (isset($active_tab) && $active_tab == 'admincursos') ? 'active' : '' ?>">
                <i class="fas fa-book"></i>
                <span class="nav-text">Gerenciar Cursos</span>
                <?php if (isset($total_cursos)): ?>
                <span class="nav-badge"><?= $total_cursos ?></span>
                <?php endif; ?>
            </a>


            <a href="/curso_agrodash/adminconteudos" class="nav-item <?= (isset($active_tab) && $active_tab == 'adminconteudos') ? 'active' : '' ?>">
                <i class="fas fa-file-alt"></i>
                <span class="nav-text">Gerenciar Conteúdos</span>
            </a>
            <a href="/curso_agrodash/loja" class="nav-item <?= (isset($active_tab) && $active_tab == 'loja') ? 'active' : '' ?>">
                <i class="fas fa-store"></i>
                <span class="nav-text">Loja de XP</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-title">Usuários</div>
            <a href="admin_usuarios.php" class="nav-item <?= (isset($active_tab) && $active_tab == 'usuarios') ? 'active' : '' ?>">
                <i class="fas fa-users"></i>
                <span class="nav-text">Gerenciar Usuários</span>
                <?php if (isset($total_usuarios)): ?>
                <span class="nav-badge"><?= $total_usuarios ?></span>
                <?php endif; ?>
            </a>
            <a href="admin_matriculas.php" class="nav-item <?= (isset($active_tab) && $active_tab == 'matriculas') ? 'active' : '' ?>">
                <i class="fas fa-user-graduate"></i>
                <span class="nav-text">Matrículas</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-title">Relatórios</div>
            <a href="admin_relatorios.php" class="nav-item <?= (isset($active_tab) && $active_tab == 'relatorios') ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i>
                <span class="nav-text">Relatórios</span>
            </a>
            <a href="admin_estatisticas.php" class="nav-item <?= (isset($active_tab) && $active_tab == 'estatisticas') ? 'active' : '' ?>">
                <i class="fas fa-chart-line"></i>
                <span class="nav-text">Estatísticas</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-title">Sistema</div>
            <a href="admin_configuracoes.php" class="nav-item <?= (isset($active_tab) && $active_tab == 'configuracoes') ? 'active' : '' ?>">
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
                <?php 
                if (isset($_SESSION['usuario_nome'])) {
                    echo strtoupper(substr($_SESSION['usuario_nome'], 0, 1));
                } else {
                    echo 'U';
                }
                ?>
            </div>
            <div class="user-details">
                <h4><?php echo isset($_SESSION['usuario_nome']) ? htmlspecialchars($_SESSION['usuario_nome']) : 'Usuário'; ?></h4>
                <p><?php echo isset($_SESSION['usuario_email']) ? htmlspecialchars($_SESSION['usuario_email']) : 'email@exemplo.com'; ?></p>
            </div>
        </div>
    </div>
</aside>