<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agro-Dash Admin</title>
    <link rel="stylesheet" href="/public/static/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="header">
        <button class="menu-toggle d-lg-none">
    <i class="fas fa-bars"></i>
</button>
        <div class="logo">Agro-Dash Admin</div>
        <div class="user-info">
            <span>Olá, <?php echo htmlspecialchars($_SESSION['usuario_nome']); ?></span>
                <a href="/" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                </a>

            
        </div>
    </div>
    <div class="main-content">
        <div class="sidebar">
            <div class="sidebar-nav">
                
                <a href="admin_dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : ''; ?>">Dashboard</a>
                <a href="admin_users" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_users.php' ? 'active' : ''; ?>">Gestão de Usuários</a>
                <a href="admin_fleet" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_fleet.php' ? 'active' : ''; ?>">Controle de Frota</a>
                <a href="fazendas" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_farms.php' ? 'active' : ''; ?>">Fazendas e Unidades</a>
            </div>
        </div>
        <div class="container">