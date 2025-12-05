<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    header("Location: /dashboard");
    exit;
}

// Processar ações
$action = $_GET['action'] ?? '';
$item_id = $_GET['id'] ?? 0;

// Atualizar status da troca
if (isset($_POST['update_status'])) {
    $troca_id = $_POST['troca_id'];
    $novo_status = $_POST['novo_status'];
    $observacoes = $_POST['observacoes'] ?? '';
    
    try {
        $stmt = $pdo->prepare("UPDATE trocas_xp SET status = ?, observacoes = ?, atualizado_em = NOW() WHERE id = ?");
        $stmt->execute([$novo_status, $observacoes, $troca_id]);
        
        // Notificar usuário sobre mudança de status
        if (isset($_POST['notificar_usuario'])) {
            $stmt_user = $pdo->prepare("
                SELECT u.id, u.nome, u.email, l.nome as item_nome 
                FROM trocas_xp t
                JOIN usuarios u ON t.usuario_id = u.id
                JOIN loja_xp l ON t.item_id = l.id
                WHERE t.id = ?
            ");
            $stmt_user->execute([$troca_id]);
            $troca_info = $stmt_user->fetch(PDO::FETCH_ASSOC);
            
            // Aqui você pode implementar envio de email ou notificação interna
        }
        
        $_SESSION['success'] = "Status atualizado com sucesso!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao atualizar status: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_item'])) {
        // Adicionar novo item
        $nome = $_POST['nome'];
        $descricao = $_POST['descricao'];
        $custo_xp = $_POST['custo_xp'];
        $estoque = $_POST['estoque'];
        $categoria = $_POST['categoria'];
        $status = $_POST['status'];
        $tipo_entrega = $_POST['tipo_entrega'];
        
        $imagem = '';
        if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === 0) {
            $uploadDir = '../curso_agrodash/uploads/loja/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $ext = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($ext, $allowed)) {
                $filename = uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['imagem']['tmp_name'], $uploadDir . $filename);
                $imagem = 'curso_agrodash/uploads/loja/' . $filename;
            }
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO loja_xp (nome, descricao, imagem, custo_xp, estoque, categoria, status, tipo_entrega) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nome, $descricao, $imagem, $custo_xp, $estoque, $categoria, $status, $tipo_entrega]);
            $_SESSION['success'] = "Item adicionado com sucesso!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao adicionar item: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['edit_item'])) {
        // Editar item existente
        $item_id = $_POST['item_id'];
        $nome = $_POST['nome'];
        $descricao = $_POST['descricao'];
        $custo_xp = $_POST['custo_xp'];
        $estoque = $_POST['estoque'];
        $categoria = $_POST['categoria'];
        $status = $_POST['status'];
        $tipo_entrega = $_POST['tipo_entrega'];
        
        $imagem = $_POST['imagem_atual'];
        if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === 0) {
            $uploadDir = '../uploads/loja/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            // Remover imagem antiga se existir
            if ($imagem && file_exists('../' . $imagem)) {
                unlink('../' . $imagem);
            }
            
            $ext = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($ext, $allowed)) {
                $filename = uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['imagem']['tmp_name'], $uploadDir . $filename);
                $imagem = 'uploads/loja/' . $filename;
            }
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE loja_xp SET nome = ?, descricao = ?, imagem = ?, custo_xp = ?, estoque = ?, categoria = ?, status = ?, tipo_entrega = ? WHERE id = ?");
            $stmt->execute([$nome, $descricao, $imagem, $custo_xp, $estoque, $categoria, $status, $tipo_entrega, $item_id]);
            $_SESSION['success'] = "Item atualizado com sucesso!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao atualizar item: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_item'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM loja_xp WHERE id = ?");
            $stmt->execute([$_POST['item_id']]);
            $_SESSION['success'] = "Item excluído com sucesso!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao excluir item: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['gerar_codigos'])) {
        // Gerar códigos de resgate para estoque
        $item_id = $_POST['item_id'];
        $quantidade = $_POST['quantidade_codigos'];
        $tipo_codigo = $_POST['tipo_codigo'];
        
        try {
            for ($i = 0; $i < $quantidade; $i++) {
                $codigo = 'AGR' . strtoupper(substr(md5(uniqid()), 0, 10));
                $stmt = $pdo->prepare("INSERT INTO estoque_itens (item_id, codigo_resgate, tipo) VALUES (?, ?, ?)");
                $stmt->execute([$item_id, $codigo, $tipo_codigo]);
            }
            $_SESSION['success'] = "$quantidade códigos gerados com sucesso!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao gerar códigos: " . $e->getMessage();
        }
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Buscar estatísticas em tempo real
try {
    // Estatísticas gerais
    $stmt_stats = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM loja_xp) as total_itens,
            (SELECT COUNT(*) FROM loja_xp WHERE status = 'ativo') as ativos,
            (SELECT COUNT(*) FROM loja_xp WHERE status = 'esgotado') as esgotados,
            (SELECT SUM(custo_xp) FROM trocas_xp WHERE status = 'concluido') as total_xp_gasto,
            (SELECT COUNT(DISTINCT usuario_id) FROM trocas_xp WHERE status = 'concluido') as usuarios_ativos,
            (SELECT COUNT(*) FROM trocas_xp WHERE status = 'pendente') as trocas_pendentes,
            (SELECT COUNT(*) FROM trocas_xp WHERE DATE(data_troca) = CURDATE()) as trocas_hoje
    ");
    $stmt_stats->execute();
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    
    // Buscar notificações de novas trocas
    $stmt_notificacoes = $pdo->prepare("
        SELECT t.*, u.nome as usuario_nome, l.nome as item_nome, l.imagem
        FROM trocas_xp t
        JOIN usuarios u ON t.usuario_id = u.id
        JOIN loja_xp l ON t.item_id = l.id
        WHERE t.status = 'pendente'
        ORDER BY t.data_troca DESC
        LIMIT 10
    ");
    $stmt_notificacoes->execute();
    $notificacoes = $stmt_notificacoes->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar todas as trocas para dashboard
    $stmt_trocas_dash = $pdo->prepare("
        SELECT t.*, u.nome as usuario_nome, u.email, l.nome as item_nome, l.categoria, l.imagem,
               CASE 
                   WHEN t.status = 'pendente' THEN 1
                   WHEN t.status = 'processando' THEN 2
                   WHEN t.status = 'separacao' THEN 3
                   WHEN t.status = 'transporte' THEN 4
                   WHEN t.status = 'entrega' THEN 5
                   WHEN t.status = 'concluido' THEN 6
                   ELSE 7
               END as status_order
        FROM trocas_xp t
        JOIN usuarios u ON t.usuario_id = u.id
        JOIN loja_xp l ON t.item_id = l.id
        ORDER BY status_order, t.data_troca DESC
        LIMIT 50
    ");
    $stmt_trocas_dash->execute();
    $trocas_dash = $stmt_trocas_dash->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar todos os itens
    $stmt = $pdo->prepare("SELECT * FROM loja_xp ORDER BY status, custo_xp ASC");
    $stmt->execute();
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $itens = [];
    $stats = [];
    $notificacoes = [];
    $trocas_dash = [];
}

// Buscar item para edição
$item_edit = null;
if ($item_id && $action === 'edit') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM loja_xp WHERE id = ?");
        $stmt->execute([$item_id]);
        $item_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao carregar item: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Loja XP - AgroDash</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #7c3aed;
            --primary-dark: #5b21b6;
            --primary-light: #8b5cf6;
            --secondary: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #f1f5f9;
            --border: #e2e8f0;
            --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius: 12px;
            --transition: all 0.3s ease;
        }

        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }

        body { 
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--dark);
        }

        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-right: 1px solid var(--border);
            padding: 25px 0;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            position: fixed;
            height: 100vh;
            z-index: 100;
        }

        .sidebar-header {
            padding: 0 25px 25px;
            border-bottom: 1px solid var(--border);
        }

        .sidebar-header h2 {
            color: var(--primary);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-menu {
            padding: 25px 0;
            flex-grow: 1;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 25px;
            color: var(--gray);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
        }

        .nav-item:hover, .nav-item.active {
            background: var(--gray-light);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .nav-item i {
            width: 20px;
            text-align: center;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            background: var(--gray-light);
            min-height: 100vh;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .notifications {
            position: relative;
        }

        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
        }

        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 400px;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            display: none;
            z-index: 1000;
        }

        .notifications:hover .notification-dropdown {
            display: block;
            animation: fadeInDown 0.3s;
        }

        .notification-item {
            padding: 15px;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .notification-item.unread {
            background: #f0f9ff;
        }

        .notification-item:hover {
            background: var(--gray-light);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border-top: 4px solid var(--primary);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card.danger { border-color: var(--danger); }
        .stat-card.success { border-color: var(--secondary); }
        .stat-card.warning { border-color: var(--warning); }
        .stat-card.info { border-color: var(--info); }

        .stat-card h3 {
            font-size: 14px;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .stat-change {
            font-size: 14px;
            color: var(--gray);
        }

        .card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }

        .card-body {
            padding: 20px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: var(--gray-light);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--border);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table td {
            padding: 15px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .table tr:hover {
            background: var(--gray-light);
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-processing { background: #dbeafe; color: #1e40af; }
        .badge-separacao { background: #f3e8ff; color: #5b21b6; }
        .badge-transporte { background: #f0f9ff; color: #0c4a6e; }
        .badge-entrega { background: #f0fdf4; color: #166534; }
        .badge-concluido { background: #dcfce7; color: #166534; }
        .badge-cancelado { background: #fee2e2; color: #991b1b; }
        .badge-ativo { background: #dcfce7; color: #166534; }
        .badge-inativo { background: #f1f5f9; color: #64748b; }
        .badge-esgotado { background: #fef3c7; color: #92400e; }

        .status-selector {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .status-option {
            padding: 8px 16px;
            border: 2px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: var(--transition);
        }

        .status-option:hover, .status-option.active {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            justify-content: center;
            border-radius: 50%;
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .modal.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius);
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: var(--transition);
        }

        .modal.active .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .item-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid var(--border);
        }

        .chart-container {
            height: 300px;
            margin-top: 20px;
        }

        .progress-bar {
            height: 6px;
            background: var(--border);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            transition: width 0.3s ease;
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 1024px) {
            .sidebar {
                width: 80px;
            }
            
            .sidebar-header h2 span,
            .nav-item span {
                display: none;
            }
            
            .main-content {
                margin-left: 80px;
            }
            
            .notification-dropdown {
                width: 350px;
                right: -50px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .top-bar {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .notification-dropdown {
                width: 300px;
                right: -100px;
            }
        }

        @media (max-width: 480px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .table th, .table td {
                padding: 10px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-store"></i> <span>Loja XP Admin</span></h2>
            </div>
            
            <nav class="nav-menu">
                <a href="#" class="nav-item active" onclick="showSection('dashboard')">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="#" class="nav-item" onclick="showSection('itens')">
                    <i class="fas fa-box"></i>
                    <span>Itens da Loja</span>
                </a>
                <a href="#" class="nav-item" onclick="showSection('trocas')">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Trocas</span>
                </a>
                <a href="#" class="nav-item" onclick="showSection('estoque')">
                    <i class="fas fa-warehouse"></i>
                    <span>Estoque & Códigos</span>
                </a>
                <a href="#" class="nav-item" onclick="showSection('relatorios')">
                    <i class="fas fa-chart-bar"></i>
                    <span>Relatórios</span>
                </a>
            </nav>
            
            <div style="padding: 25px; margin-top: auto;">
                <div class="card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <div style="padding: 15px;">
                        <h3 style="font-size: 14px; margin-bottom: 10px;">Total XP Gastos</h3>
                        <div style="font-size: 24px; font-weight: 700;"><?php echo number_format($stats['total_xp_gasto'] ?? 0); ?> XP</div>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <h1 id="section-title" style="font-size: 24px; font-weight: 700; color: var(--dark);">
                    Dashboard da Loja XP
                </h1>
                
                <div class="notifications">
                    <button class="btn btn-icon btn-primary" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if (count($notificacoes) > 0): ?>
                            <span class="notification-badge"><?php echo count($notificacoes); ?></span>
                        <?php endif; ?>
                    </button>
                    
                    <div class="notification-dropdown">
                        <div class="card-header">
                            <span>Notificações</span>
                            <small><?php echo count($notificacoes); ?> novas</small>
                        </div>
                        <div class="card-body" style="padding: 0;">
                            <?php if (empty($notificacoes)): ?>
                                <div style="padding: 20px; text-align: center; color: var(--gray);">
                                    <i class="fas fa-bell-slash" style="font-size: 24px; margin-bottom: 10px;"></i>
                                    <p>Nenhuma notificação</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($notificacoes as $notif): ?>
                                    <div class="notification-item unread" onclick="openTrocaModal(<?php echo $notif['id']; ?>)">
                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary-light); display: flex; align-items: center; justify-content: center; color: white;">
                                            <i class="fas fa-gift"></i>
                                        </div>
                                        <div style="flex: 1;">
                                            <strong><?php echo htmlspecialchars($notif['usuario_nome']); ?></strong>
                                            <p style="font-size: 13px; color: var(--gray); margin-top: 2px;">
                                                Solicitou: <?php echo htmlspecialchars($notif['item_nome']); ?>
                                            </p>
                                            <small style="color: var(--gray);"><?php echo date('H:i', strtotime($notif['data_troca'])); ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success animate__animated animate__fadeIn">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error animate__animated animate__fadeIn">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                </div>
            <?php endif; ?>

            <!-- Dashboard Section -->
            <section id="dashboard" class="content-section">
                <div class="stats-grid">
                    <div class="stat-card success">
                        <h3>Total de Itens</h3>
                        <div class="stat-value"><?php echo $stats['total_itens'] ?? 0; ?></div>
                        <div class="stat-change"><?php echo $stats['ativos'] ?? 0; ?> ativos</div>
                    </div>
                    
                    <div class="stat-card warning">
                        <h3>Trocas Pendentes</h3>
                        <div class="stat-value"><?php echo $stats['trocas_pendentes'] ?? 0; ?></div>
                        <div class="stat-change"><?php echo $stats['trocas_hoje'] ?? 0; ?> hoje</div>
                    </div>
                    
                    <div class="stat-card info">
                        <h3>Usuários Ativos</h3>
                        <div class="stat-value"><?php echo $stats['usuarios_ativos'] ?? 0; ?></div>
                        <div class="stat-change"><?php echo $stats['total_itens'] ?? 0; ?> itens</div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>XP Total Gastos</h3>
                        <div class="stat-value"><?php echo number_format($stats['total_xp_gasto'] ?? 0); ?></div>
                        <div class="stat-change">XP gastos na plataforma</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Trocas Recentes</div>
                        <div class="status-selector" id="statusFilter">
                            <span class="status-option active" onclick="filterTrocas('all')">Todas</span>
                            <span class="status-option" onclick="filterTrocas('pendente')">Pendente</span>
                            <span class="status-option" onclick="filterTrocas('processando')">Processando</span>
                            <span class="status-option" onclick="filterTrocas('separacao')">Separação</span>
                            <span class="status-option" onclick="filterTrocas('transporte')">Transporte</span>
                            <span class="status-option" onclick="filterTrocas('entrega')">Entrega</span>
                            <span class="status-option" onclick="filterTrocas('concluido')">Concluído</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Usuário</th>
                                        <th>Item</th>
                                        <th>XP</th>
                                        <th>Data</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="trocasTable">
                                    <?php foreach ($trocas_dash as $troca): ?>
                                    <tr class="troca-row" data-status="<?php echo $troca['status']; ?>">
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary-light); display: flex; align-items: center; justify-content: center; color: white;">
                                                    <?php echo strtoupper(substr($troca['usuario_nome'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($troca['usuario_nome']); ?></div>
                                                    <small style="color: var(--gray); font-size: 12px;"><?php echo $troca['email']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <?php if ($troca['imagem']): ?>
                                                    <img src="../<?php echo $troca['imagem']; ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px;">
                                                <?php endif; ?>
                                                <div>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($troca['item_nome']); ?></div>
                                                    <small style="color: var(--gray); font-size: 12px;"><?php echo $troca['categoria']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><strong style="color: var(--primary);"><?php echo number_format($troca['custo_xp']); ?> XP</strong></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($troca['data_troca'])); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $troca['status']; ?>">
                                                <?php 
                                                $status_names = [
                                                    'pendente' => 'Pendente',
                                                    'processando' => 'Processando',
                                                    'separacao' => 'Separação',
                                                    'transporte' => 'Transporte',
                                                    'entrega' => 'Em Entrega',
                                                    'concluido' => 'Concluído',
                                                    'cancelado' => 'Cancelado'
                                                ];
                                                echo $status_names[$troca['status']] ?? ucfirst($troca['status']);
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="openTrocaModal(<?php echo $troca['id']; ?>)">
                                                <i class="fas fa-eye"></i> Ver
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Itens Section -->
            <section id="itens" class="content-section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Gerenciar Itens da Loja</div>
                        <button class="btn btn-primary" onclick="openItemModal()">
                            <i class="fas fa-plus"></i> Novo Item
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Imagem</th>
                                        <th>Nome</th>
                                        <th>Categoria</th>
                                        <th>Custo XP</th>
                                        <th>Estoque</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($itens as $item): ?>
                                    <tr>
                                        <td>
                                            <?php if ($item['imagem']): ?>
                                                <img src="../<?php echo $item['imagem']; ?>" class="item-image">
                                            <?php else: ?>
                                                <div style="width: 60px; height: 60px; background: var(--gray-light); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-image" style="color: var(--gray);"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($item['nome']); ?></div>
                                            <small style="color: var(--gray);"><?php echo substr($item['descricao'], 0, 50); ?>...</small>
                                        </td>
                                        <td>
                                            <span class="badge" style="background: var(--gray-light); color: var(--dark);">
                                                <?php echo htmlspecialchars($item['categoria']); ?>
                                            </span>
                                        </td>
                                        <td><strong style="color: var(--primary);"><?php echo number_format($item['custo_xp']); ?> XP</strong></td>
                                        <td>
                                            <?php if ($item['estoque'] == -1): ?>
                                                <span style="color: var(--secondary);">Ilimitado</span>
                                            <?php else: ?>
                                                <div>
                                                    <?php echo $item['estoque']; ?> unidades
                                                    <?php if ($item['estoque'] < 10 && $item['estoque'] > 0): ?>
                                                        <div class="progress-bar">
                                                            <div class="progress-fill" style="width: <?php echo ($item['estoque'] / 10) * 100; ?>%; background: var(--warning);"></div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $item['status']; ?>">
                                                <?php echo ucfirst($item['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 5px;">
                                                <button class="btn btn-sm btn-warning" onclick="editItem(<?php echo $item['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['nome']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Trocas Section -->
            <section id="trocas" class="content-section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Histórico de Trocas</div>
                        <div style="display: flex; gap: 10px;">
                            <input type="date" class="form-control" style="width: auto;" onchange="filterByDate(this.value)">
                            <input type="text" class="form-control" style="width: 200px;" placeholder="Buscar usuário ou item..." onkeyup="searchTrocas(this.value)">
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Trocas são carregadas dinamicamente -->
                        <div id="trocasList"></div>
                    </div>
                </div>
            </section>

            <!-- Estoque Section -->
            <section id="estoque" class="content-section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Gerenciar Estoque e Códigos</div>
                        <button class="btn btn-success" onclick="openCodigosModal()">
                            <i class="fas fa-key"></i> Gerar Códigos
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Estoque Atual</th>
                                        <th>Códigos Gerados</th>
                                        <th>Códigos Usados</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($itens as $item): 
                                        try {
                                            $stmt_total = $pdo->prepare("SELECT COUNT(*) as total FROM estoque_itens WHERE item_id = ?");
                                            $stmt_total->execute([$item['id']]);
                                            $total_codigos = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];
                                            
                                            $stmt_used = $pdo->prepare("SELECT COUNT(*) as total FROM estoque_itens WHERE item_id = ? AND status = 'resgatado'");
                                            $stmt_used->execute([$item['id']]);
                                            $used_codigos = $stmt_used->fetch(PDO::FETCH_ASSOC)['total'];
                                        } catch (PDOException $e) {
                                            $total_codigos = $used_codigos = 0;
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($item['nome']); ?></div>
                                            <small style="color: var(--gray);">Categoria: <?php echo $item['categoria']; ?></small>
                                        </td>
                                        <td>
                                            <?php if ($item['estoque'] == -1): ?>
                                                <span style="color: var(--secondary); font-weight: 600;">Ilimitado</span>
                                            <?php else: ?>
                                                <div>
                                                    <span style="font-weight: 600;"><?php echo $item['estoque']; ?></span> unidades
                                                    <div class="progress-bar">
                                                        <div class="progress-fill" style="width: <?php echo min(100, ($item['estoque'] / 50) * 100); ?>%;"></div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; color: var(--primary);"><?php echo $total_codigos; ?></span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; color: var(--<?php echo $used_codigos > 0 ? 'secondary' : 'gray'; ?>);">
                                                <?php echo $used_codigos; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="viewCodigos(<?php echo $item['id']; ?>)">
                                                <i class="fas fa-eye"></i> Ver Códigos
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Relatórios Section -->
            <section id="relatorios" class="content-section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Relatórios e Análises</div>
                        <div style="display: flex; gap: 10px;">
                            <select class="form-control" style="width: auto;">
                                <option>Últimos 7 dias</option>
                                <option>Últimos 30 dias</option>
                                <option>Últimos 90 dias</option>
                                <option>Este ano</option>
                            </select>
                            <button class="btn btn-primary">
                                <i class="fas fa-download"></i> Exportar
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Modal Adicionar/Editar Item -->
    <div id="itemModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Adicionar Novo Item</h3>
                <button class="btn btn-icon btn-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="itemForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" id="itemId" name="item_id">
                    <input type="hidden" id="formAction" name="add_item" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Nome do Item *</label>
                        <input type="text" class="form-control" name="nome" id="itemNome" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Descrição *</label>
                        <textarea class="form-control" name="descricao" id="itemDescricao" rows="3" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Imagem</label>
                        <div style="border: 2px dashed var(--border); border-radius: 8px; padding: 20px; text-align: center; margin-bottom: 10px;">
                            <i class="fas fa-cloud-upload-alt" style="font-size: 24px; color: var(--gray); margin-bottom: 10px;"></i>
                            <div style="margin-bottom: 10px;">Arraste a imagem ou clique para selecionar</div>
                            <input type="file" class="form-control" name="imagem" id="itemImagem" accept="image/*" onchange="previewImage(this)">
                        </div>
                        <div id="imagePreview" style="display: none;">
                            <img id="previewImg" style="max-width: 100px; max-height: 100px; border-radius: 8px; margin-top: 10px;">
                        </div>
                        <input type="hidden" id="imagemAtual" name="imagem_atual">
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label class="form-label">Custo XP *</label>
                            <input type="number" class="form-control" name="custo_xp" id="itemCustoXp" min="0" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Estoque</label>
                            <input type="number" class="form-control" name="estoque" id="itemEstoque" min="-1" value="-1">
                            <small style="color: var(--gray); font-size: 12px;">-1 = estoque ilimitado</small>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label class="form-label">Categoria *</label>
                            <select class="form-control" name="categoria" id="itemCategoria" required>
                                <option value="geral">Geral</option>
                                <option value="digital">Digital</option>
                                <option value="fisico">Físico</option>
                                <option value="curso">Curso</option>
                                <option value="certificado">Certificado</option>
                                <option value="brinde">Brinde</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Tipo de Entrega</label>
                            <select class="form-control" name="tipo_entrega" id="itemTipoEntrega">
                                <option value="digital">Digital (Email)</option>
                                <option value="fisico">Físico (Correios)</option>
                                <option value="retirada">Retirada no Local</option>
                                <option value="codigo">Código de Resgate</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status *</label>
                        <select class="form-control" name="status" id="itemStatus" required>
                            <option value="ativo">Ativo</option>
                            <option value="inativo">Inativo</option>
                            <option value="esgotado">Esgotado</option>
                        </select>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Salvar Item
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Gerenciar Troca -->
    <div id="trocaModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Detalhes da Troca</h3>
                <button class="btn btn-icon btn-secondary" onclick="closeTrocaModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="trocaDetails">
                <!-- Detalhes da troca serão carregados aqui -->
            </div>
        </div>
    </div>

    <!-- Modal Gerar Códigos -->
    <div id="codigosModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Gerar Códigos de Resgate</h3>
                <button class="btn btn-icon btn-secondary" onclick="closeCodigosModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="codigosForm" method="POST">
                    <input type="hidden" id="codigoItemId" name="item_id">
                    <input type="hidden" name="gerar_codigos" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Item</label>
                        <select class="form-control" name="item_id" id="codigosItemSelect" onchange="updateItemInfo(this.value)" required>
                            <option value="">Selecione um item</option>
                            <?php foreach ($itens as $item): ?>
                                <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Tipo de Código</label>
                        <select class="form-control" name="tipo_codigo" required>
                            <option value="unico">Uso Único</option>
                            <option value="multiplo">Uso Múltiplo</option>
                            <option value="temporario">Temporário</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Quantidade *</label>
                        <input type="number" class="form-control" name="quantidade_codigos" min="1" max="1000" value="10" required>
                        <small style="color: var(--gray); font-size: 12px;">Máximo 1000 códigos por vez</small>
                    </div>
                    
                    <div id="itemInfo" class="alert" style="background: var(--gray-light);">
                        Selecione um item para ver as informações
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-key"></i> Gerar Códigos
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeCodigosModal()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Funções principais
        function showSection(sectionId) {
            // Esconder todas as seções
            document.querySelectorAll('.content-section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Mostrar seção selecionada
            document.getElementById(sectionId).style.display = 'block';
            
            // Atualizar título
            const titles = {
                'dashboard': 'Dashboard da Loja XP',
                'itens': 'Gerenciar Itens da Loja',
                'trocas': 'Histórico de Trocas',
                'estoque': 'Gerenciar Estoque e Códigos',
                'relatorios': 'Relatórios e Análises'
            };
            document.getElementById('section-title').textContent = titles[sectionId] || 'Dashboard';
            
            // Atualizar menu ativo
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            event.currentTarget.classList.add('active');
            
            // Se for a seção de trocas, carregar dados
            if (sectionId === 'trocas') {
                loadTrocas();
            }
        }
        
        function openItemModal(editId = null) {
            const modal = document.getElementById('itemModal');
            const form = document.getElementById('itemForm');
            
            if (editId) {
                // Carregar dados do item para edição
                fetch(`ajax/get_item.php?id=${editId}`)
                    .then(response => response.json())
                    .then(data => {
                        document.getElementById('modalTitle').textContent = 'Editar Item';
                        document.getElementById('itemId').value = data.id;
                        document.getElementById('itemNome').value = data.nome;
                        document.getElementById('itemDescricao').value = data.descricao;
                        document.getElementById('itemCustoXp').value = data.custo_xp;
                        document.getElementById('itemEstoque').value = data.estoque;
                        document.getElementById('itemCategoria').value = data.categoria;
                        document.getElementById('itemTipoEntrega').value = data.tipo_entrega || 'digital';
                        document.getElementById('itemStatus').value = data.status;
                        document.getElementById('formAction').name = 'edit_item';
                        document.getElementById('formAction').value = '1';
                        document.getElementById('imagemAtual').value = data.imagem || '';
                        
                        if (data.imagem) {
                            document.getElementById('imagePreview').style.display = 'block';
                            document.getElementById('previewImg').src = '../' + data.imagem;
                        }
                    })
                    .catch(error => console.error('Erro:', error));
            } else {
                // Novo item
                document.getElementById('modalTitle').textContent = 'Adicionar Novo Item';
                form.reset();
                document.getElementById('itemId').value = '';
                document.getElementById('formAction').name = 'add_item';
                document.getElementById('formAction').value = '1';
                document.getElementById('imagePreview').style.display = 'none';
            }
            
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function editItem(id) {
            openItemModal(id);
        }
        
        function closeModal() {
            document.getElementById('itemModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        function closeCodigosModal() {
            document.getElementById('codigosModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        function openCodigosModal(itemId = null) {
            const modal = document.getElementById('codigosModal');
            if (itemId) {
                document.getElementById('codigosItemSelect').value = itemId;
                updateItemInfo(itemId);
            }
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function updateItemInfo(itemId) {
            if (!itemId) return;
            
            fetch(`ajax/get_item_info.php?id=${itemId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('itemInfo').innerHTML = `
                        <strong>${data.nome}</strong><br>
                        Estoque atual: ${data.estoque == -1 ? 'Ilimitado' : data.estoque + ' unidades'}<br>
                        Custo: ${data.custo_xp} XP<br>
                        Status: ${data.status}
                    `;
                });
        }
        
        function deleteItem(id, nome) {
            if (confirm(`Tem certeza que deseja excluir o item "${nome}"?\nEsta ação não pode ser desfeita.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'item_id';
                inputId.value = id;
                
                const inputAction = document.createElement('input');
                inputAction.type = 'hidden';
                inputAction.name = 'delete_item';
                inputAction.value = '1';
                
                form.appendChild(inputId);
                form.appendChild(inputAction);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function filterTrocas(status) {
            const rows = document.querySelectorAll('.troca-row');
            const filterOptions = document.querySelectorAll('.status-option');
            
            filterOptions.forEach(option => option.classList.remove('active'));
            event.target.classList.add('active');
            
            rows.forEach(row => {
                if (status === 'all' || row.dataset.status === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function loadTrocas() {
            fetch('ajax/get_trocas.php')
                .then(response => response.json())
                .then(data => {
                    let html = '<div class="table-responsive"><table class="table"><thead><tr><th>Usuário</th><th>Item</th><th>XP</th><th>Data</th><th>Status</th><th>Ações</th></tr></thead><tbody>';
                    
                    data.forEach(troca => {
                        html += `
                            <tr>
                                <td>${troca.usuario_nome}</td>
                                <td>${troca.item_nome}</td>
                                <td><strong>${troca.custo_xp} XP</strong></td>
                                <td>${new Date(troca.data_troca).toLocaleDateString('pt-BR')}</td>
                                <td><span class="badge badge-${troca.status}">${troca.status}</span></td>
                                <td><button class="btn btn-sm btn-primary" onclick="openTrocaModal(${troca.id})">Ver</button></td>
                            </tr>
                        `;
                    });
                    
                    html += '</tbody></table></div>';
                    document.getElementById('trocasList').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('trocasList').innerHTML = '<p>Erro ao carregar trocas</p>';
                });
        }
        
        function openTrocaModal(trocaId) {
            fetch(`ajax/get_troca_details.php?id=${trocaId}`)
                .then(response => response.json())
                .then(troca => {
                    const modal = document.getElementById('trocaModal');
                    const detailsDiv = document.getElementById('trocaDetails');
                    
                    let statusOptions = '';
                    const statuses = {
                        'pendente': 'Pendente',
                        'processando': 'Processando',
                        'separacao': 'Em Separação',
                        'transporte': 'Em Transporte',
                        'entrega': 'Em Entrega',
                        'concluido': 'Concluído',
                        'cancelado': 'Cancelado'
                    };
                    
                    for (const [value, label] of Object.entries(statuses)) {
                        statusOptions += `<option value="${value}" ${troca.status === value ? 'selected' : ''}>${label}</option>`;
                    }
                    
                    detailsDiv.innerHTML = `
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                            <div>
                                <strong>Usuário:</strong><br>
                                ${troca.usuario_nome}<br>
                                <small>${troca.usuario_email}</small>
                            </div>
                            <div>
                                <strong>Item:</strong><br>
                                ${troca.item_nome}<br>
                                <small>Categoria: ${troca.categoria}</small>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                            <div>
                                <strong>Valor:</strong><br>
                                ${troca.custo_xp} XP
                            </div>
                            <div>
                                <strong>Data:</strong><br>
                                ${new Date(troca.data_troca).toLocaleDateString('pt-BR')} ${new Date(troca.data_troca).toLocaleTimeString('pt-BR')}
                            </div>
                        </div>
                        
                        <form method="POST" onsubmit="return confirm('Atualizar status da troca?')">
                            <input type="hidden" name="troca_id" value="${troca.id}">
                            
                            <div class="form-group">
                                <label class="form-label">Status da Troca</label>
                                <select class="form-control" name="novo_status" required>
                                    ${statusOptions}
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Observações</label>
                                <textarea class="form-control" name="observacoes" rows="3" placeholder="Adicione observações sobre a troca...">${troca.observacoes || ''}</textarea>
                            </div>
                            
                            <div class="form-group" style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" id="notificar" name="notificar_usuario" checked>
                                <label for="notificar" class="form-label" style="margin: 0;">Notificar usuário sobre a mudança</label>
                            </div>
                            
                            <div style="display: flex; gap: 10px; margin-top: 20px;">
                                <button type="submit" name="update_status" value="1" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Atualizar Status
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="closeTrocaModal()">
                                    <i class="fas fa-times"></i> Fechar
                                </button>
                            </div>
                        </form>
                    `;
                    
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                })
                .catch(error => {
                    alert('Erro ao carregar detalhes da troca');
                });
        }
        
        function closeTrocaModal() {
            document.getElementById('trocaModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        function viewCodigos(itemId) {
            fetch(`ajax/get_codigos.php?item_id=${itemId}`)
                .then(response => response.json())
                .then(data => {
                    let html = '<div class="table-responsive"><table class="table"><thead><tr><th>Código</th><th>Tipo</th><th>Status</th><th>Usuário</th><th>Data</th></tr></thead><tbody>';
                    
                    data.forEach(codigo => {
                        html += `
                            <tr>
                                <td><code>${codigo.codigo_resgate}</code></td>
                                <td>${codigo.tipo || 'unico'}</td>
                                <td><span class="badge ${codigo.status === 'resgatado' ? 'badge-success' : 'badge-info'}">${codigo.status}</span></td>
                                <td>${codigo.usuario_nome || '-'}</td>
                                <td>${codigo.data_resgate ? new Date(codigo.data_resgate).toLocaleDateString('pt-BR') : '-'}</td>
                            </tr>
                        `;
                    });
                    
                    html += '</tbody></table></div>';
                    
                    const modal = document.getElementById('trocaModal');
                    const detailsDiv = document.getElementById('trocaDetails');
                    
                    detailsDiv.innerHTML = `
                        <h4>Códigos de Resgate</h4>
                        ${html}
                        <div style="margin-top: 20px;">
                            <button class="btn btn-secondary" onclick="closeTrocaModal()">Fechar</button>
                        </div>
                    `;
                    
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                });
        }
        
        function previewImage(input) {
            const preview = document.getElementById('previewImg');
            const previewDiv = document.getElementById('imagePreview');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    previewDiv.style.display = 'block';
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar gráfico
            const ctx = document.getElementById('salesChart')?.getContext('2d');
            if (ctx) {
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun'],
                        datasets: [{
                            label: 'Trocas Realizadas',
                            data: [12, 19, 3, 5, 2, 3],
                            borderColor: '#7c3aed',
                            backgroundColor: 'rgba(124, 58, 237, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            }
            
            // Se houver ação de edição, abrir modal
            <?php if ($item_edit): ?>
            editItem(<?php echo $item_edit['id']; ?>);
            <?php endif; ?>
        });
        
        // Atualizar notificações periodicamente
        setInterval(() => {
            fetch('ajax/get_notificacoes.php')
                .then(response => response.json())
                .then(data => {
                    const badge = document.querySelector('.notification-badge');
                    if (badge) {
                        badge.textContent = data.count;
                    }
                });
        }, 30000); // A cada 30 segundos
    </script>
</body>
</html>