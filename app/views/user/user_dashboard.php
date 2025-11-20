<?php
date_default_timezone_set('America/Sao_Paulo');

session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';
$stmt = $pdo->query("SELECT manutencao_ativa FROM configuracoes_sistema WHERE id = 1");
$manutencao = $stmt->fetchColumn();
if ($manutencao) {
    header("Location: /maintenance");
    exit;
}

// Segurança básica de sessão
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login");
    exit();
}

$feedback_message = '';
if (isset($_SESSION['feedback_message'])) {
    $feedback_message = $_SESSION['feedback_message'];
    unset($_SESSION['feedback_message']);
}

// Processar edição de apontamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entry_id'])) {
    try {
        $entry_id = (int)$_POST['entry_id'];
        $hectares = (float)$_POST['hectares'];
        $hora_selecionada = $_POST['hora_selecionada'];
        $observacoes = $_POST['observacoes'] ?? '';
        
        // Verificar se o apontamento pertence ao usuário e é do dia atual
        $stmt_check = $pdo->prepare("SELECT usuario_id, DATE(data_hora) as data_apontamento FROM apontamentos WHERE id = ?");
        $stmt_check->execute([$entry_id]);
        $apontamento = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        $hoje = date('Y-m-d');
        
        if (!$apontamento || $apontamento['usuario_id'] != $_SESSION['usuario_id']) {
            echo json_encode(['success' => false, 'message' => 'Apontamento não encontrado ou acesso negado']);
            exit;
        }
        
        // Verificar se o apontamento é do dia atual
        if ($apontamento['data_apontamento'] != $hoje) {
            echo json_encode(['success' => false, 'message' => 'Edição permitida apenas para apontamentos do dia atual']);
            exit;
        }
        
        // Atualizar o apontamento
        $stmt_update = $pdo->prepare("
            UPDATE apontamentos 
            SET hectares = ?, hora_selecionada = ?, observacoes = ?
            WHERE id = ?
        ");
        
        $stmt_update->execute([$hectares, $hora_selecionada, $observacoes, $entry_id]);
        
        echo json_encode(['success' => true, 'message' => 'Apontamento atualizado com sucesso!']);
        exit;
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar: ' . $e->getMessage()]);
        exit;
    }
}

// Endpoint para buscar apontamentos (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['date']) && isset($_GET['usuario_id'])) {
    try {
        $date = $_GET['date'];
        $usuario_id = (int)$_GET['usuario_id'];
        $hoje = date('Y-m-d');
        
        $stmt_apontamentos = $pdo->prepare("
            SELECT 
                a.id,
                a.hectares,
                a.hora_selecionada,
                a.data_hora,
                a.observacoes,
                e.nome as equipamento_nome,
                o.nome as operacao_nome,
                u.nome as unidade_nome,
                DATE(a.data_hora) as data_apontamento
            FROM apontamentos a
            LEFT JOIN equipamentos e ON a.equipamento_id = e.id
            LEFT JOIN tipos_operacao o ON a.operacao_id = o.id
            LEFT JOIN unidades u ON a.unidade_id = u.id
            WHERE a.usuario_id = ? AND DATE(a.data_hora) = ?
            ORDER BY a.data_hora DESC
        ");
        
        $stmt_apontamentos->execute([$usuario_id, $date]);
        $apontamentos = $stmt_apontamentos->fetchAll(PDO::FETCH_ASSOC);
        
        // Adicionar flag para indicar se pode editar (apenas apontamentos do dia atual)
        foreach ($apontamentos as &$apontamento) {
            $apontamento['pode_editar'] = ($apontamento['data_apontamento'] == $hoje);
        }
        
        header('Content-Type: application/json');
        echo json_encode($apontamentos);
        exit;
        
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }
}

try {
    // ====== Dados do usuário ======
    $usuario_id = $_SESSION['usuario_id'];
    $stmt_user = $pdo->prepare("SELECT unidade_id, tipo, nome FROM usuarios WHERE id = ?");
    $stmt_user->execute([$usuario_id]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    $unidade_do_usuario = $user_data['unidade_id'] ?? null;
    $user_role          = $user_data['tipo']       ?? ($_SESSION['usuario_tipo'] ?? 'operador');
    $username           = $user_data['nome']       ?? ($_SESSION['usuario_nome'] ?? 'Usuário');

    // Coleções usadas na tela
    $tipos_operacao = [];
    $metas_por_operacao = [];            // [operacao_id] => ['meta_diaria'=>..., 'meta_mensal'=>...]
    $dados_por_operacao_diario = [];     // [operacao_id] => ['total_ha'=>..., 'total_entries'=>...]
    $dados_por_operacao_mensal = [];     // [operacao_id] => ['total_ha'=>..., 'total_entries'=>...]
    $unidade_do_usuario_nome = "Unidade Desconhecida";

    if (!$unidade_do_usuario) {
        $feedback_message = "Usuário não está associado a uma unidade, Contate um Administrador.";
    } else {
        // Nome da unidade
        $stmt_unidade_nome = $pdo->prepare("SELECT nome FROM unidades WHERE id = ?");
        $stmt_unidade_nome->execute([$unidade_do_usuario]);
        $unidade_do_usuario_nome = $stmt_unidade_nome->fetchColumn() ?: "Unidade Desconhecida";

        // Operações permitidas por vínculo (usuario_operacao). Se não houver, libera todas.
        $stmt_operacoes = $pdo->prepare("
            SELECT t.id, t.nome 
            FROM tipos_operacao t
            INNER JOIN usuario_operacao uo ON t.id = uo.operacao_id
            WHERE uo.usuario_id = ?
            ORDER BY t.nome
        ");
        $stmt_operacoes->execute([$usuario_id]);
        $tipos_operacao = $stmt_operacoes->fetchAll(PDO::FETCH_ASSOC);

        if (empty($tipos_operacao)) {
            $tipos_operacao = $pdo->query("SELECT id, nome FROM tipos_operacao ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
        }
        $allowed_operations_ids = array_map(function($r) { return (int)$r['id']; }, $tipos_operacao);

        // Data/Período corrente
        $hoje_brt   = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $data_hoje  = $hoje_brt->format('Y-m-d');
        $mes_atual  = $hoje_brt->format('m');
        $ano_atual  = $hoje_brt->format('Y');

        // Metas (por operação) da unidade do usuário
        if (!empty($allowed_operations_ids)) {
            $in_clause = implode(',', array_fill(0, count($allowed_operations_ids), '?'));
            $params_metas = array_merge([$unidade_do_usuario], $allowed_operations_ids);

            $stmt_metas = $pdo->prepare("
                SELECT operacao_id, meta_diaria, meta_mensal 
                FROM metas_unidade_operacao
                WHERE unidade_id = ? AND operacao_id IN ($in_clause)
            ");
            $stmt_metas->execute($params_metas);
            while ($row = $stmt_metas->fetch(PDO::FETCH_ASSOC)) {
                $op_id = (int)$row['operacao_id'];
                $metas_por_operacao[$op_id] = [
                    'meta_diaria' => (float)$row['meta_diaria'],
                    'meta_mensal' => (float)$row['meta_mensal'],
                ];
            }
        }

        // Progresso diário por operação (na unidade do usuário)
        if (!empty($allowed_operations_ids)) {
            $in_clause = implode(',', array_fill(0, count($allowed_operations_ids), '?'));
            $params_diario = array_merge([$unidade_do_usuario, $data_hoje], $allowed_operations_ids);

            $stmt_diario = $pdo->prepare("
                SELECT operacao_id, SUM(hectares) AS total_ha, COUNT(*) AS total_entries
                FROM apontamentos
                WHERE unidade_id = ? 
                  AND DATE(data_hora) = ?
                  AND operacao_id IN ($in_clause)
                GROUP BY operacao_id
            ");
            $stmt_diario->execute($params_diario);
            while ($row = $stmt_diario->fetch(PDO::FETCH_ASSOC)) {
                $op_id = (int)$row['operacao_id'];
                $dados_por_operacao_diario[$op_id] = [
                    'total_ha'      => (float)($row['total_ha'] ?? 0),
                    'total_entries' => (int)($row['total_entries'] ?? 0),
                ];
            }
        }

        // Progresso mensal por operação (na unidade do usuário)
        if (!empty($allowed_operations_ids)) {
            $in_clause = implode(',', array_fill(0, count($allowed_operations_ids), '?'));
            $params_mensal = array_merge([$unidade_do_usuario, $mes_atual, $ano_atual], $allowed_operations_ids);

            $stmt_mensal = $pdo->prepare("
                SELECT operacao_id, SUM(hectares) AS total_ha, COUNT(*) AS total_entries
                FROM apontamentos
                WHERE unidade_id = ?
                  AND MONTH(data_hora) = ?
                  AND YEAR(data_hora)  = ?
                  AND operacao_id IN ($in_clause)
                GROUP BY operacao_id
            ");
            $stmt_mensal->execute($params_mensal);
            while ($row = $stmt_mensal->fetch(PDO::FETCH_ASSOC)) {
                $op_id = (int)$row['operacao_id'];
                $dados_por_operacao_mensal[$op_id] = [
                    'total_ha'      => (float)($row['total_ha'] ?? 0),
                    'total_entries' => (int)($row['total_entries'] ?? 0),
                ];
            }
        }
    }

    // Dropdowns do formulário
    $todas_operacoes = $pdo->query("SELECT id, nome FROM tipos_operacao ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

    // Fazendas da unidade do usuário (para o select)
    if ($unidade_do_usuario) {
        $stmt_fazendas = $pdo->prepare("SELECT id, nome, codigo_fazenda FROM fazendas WHERE unidade_id = ? ORDER BY nome");
        $stmt_fazendas->execute([$unidade_do_usuario]);
        $fazendas = $stmt_fazendas->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $fazendas = $pdo->query("SELECT id, nome, codigo_fazenda FROM fazendas ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Dados fake para a aba de comparativos (placeholder)
    $comparison_data = [];
    $current_date = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    for ($i = 6; $i >= 0; $i--) {
        $date = clone $current_date;
        $date->modify("-$i days");
        $ha_manual = rand(15, 35);
        $ha_solinftec = $ha_manual + rand(-8, 8);
        $comparison_data[] = [
            'data' => $date->format('Y-m-d'),
            'ha_manual' => $ha_manual,
            'ha_solinftec' => $ha_solinftec
        ];
    }

} catch (PDOException $e) {
    $feedback_message = "Erro de conexão com o banco de dados: " . $e->getMessage();
    $tipos_operacao = [];
    $metas_por_operacao = [];
    $dados_por_operacao_diario = [];
    $dados_por_operacao_mensal = [];
    $fazendas = [];
    $comparison_data = [];
}

// Horas fixas do dropdown
$report_hours = ['02:00','04:00','06:00','08:00','10:00','12:00','14:00','16:00','18:00','20:00','22:00','00:00'];

// Salvar novo apontamento (salvando data_hora em BRT/local)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario_id']) && !isset($_POST['entry_id'])) {
    try {
        $usuario_id    = (int)$_POST['usuario_id'];
        $report_time   = $_POST['report_time'];          // "HH:MM"
        $status        = $_POST['status'];               // ativo | parado
        $fazenda_id    = (int)$_POST['fazenda_id'];
        $equipamento_id= (int)$_POST['equipamento_id'];
        $operacao_id   = (int)$_POST['operacao_id'];
        $hectares      = ($status === 'ativo') ? (float)$_POST['hectares'] : 0;
        $observacoes   = ($status === 'parado') ? ($_POST['observacoes'] ?? '') : '';

        // Unidade da fazenda selecionada (garante consistência)
        $stmt_unidade = $pdo->prepare("SELECT unidade_id FROM fazendas WHERE id = ?");
        $stmt_unidade->execute([$fazenda_id]);
        $fazenda_data = $stmt_unidade->fetch(PDO::FETCH_ASSOC);
        $unidade_id = $fazenda_data['unidade_id'] ?? $unidade_do_usuario;

        // Usa a data do "hoje" em Brasília + hora selecionada (00:00 pertence ao mesmo dia)
        $hoje_brt = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $data_hora_local = (new DateTime($hoje_brt->format('Y-m-d') . ' ' . $report_time . ':00', new DateTimeZone('America/Sao_Paulo')))
                           ->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            INSERT INTO apontamentos
            (usuario_id, unidade_id, equipamento_id, operacao_id, hectares, data_hora, hora_selecionada, observacoes, fazenda_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $usuario_id, $unidade_id, $equipamento_id, $operacao_id,
            $hectares, $data_hora_local, $report_time, $observacoes, $fazenda_id
        ]);

        $_SESSION['feedback_message'] = "Apontamento salvo com sucesso para às " . substr($report_time,0,5) . "!";
        header("Location: /user_dashboard");
        exit;

    } catch (PDOException $e) {
        $feedback_message = "Erro ao salvar apontamento: " . $e->getMessage();
    }
}

// Totais para os cards (somatórios por operação)
$total_daily_hectares   = array_sum(array_map(function($r) { return $r['total_ha'] ?? 0; }, $dados_por_operacao_diario));
$total_daily_goal       = array_sum(array_map(function($r) { return $r['meta_diaria'] ?? 0; }, $metas_por_operacao));
$total_daily_entries    = array_sum(array_map(function($r) { return $r['total_entries'] ?? 0; }, $dados_por_operacao_diario));

$total_monthly_hectares = array_sum(array_map(function($r) { return $r['total_ha'] ?? 0; }, $dados_por_operacao_mensal));
$total_monthly_goal     = array_sum(array_map(function($r) { return $r['meta_mensal'] ?? 0; }, $metas_por_operacao));
$total_monthly_entries  = array_sum(array_map(function($r) { return $r['total_entries'] ?? 0; }, $dados_por_operacao_mensal));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars($user_role); ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- CSS personalizado -->
    <link rel="stylesheet" href="/public/static/css/ctt.css">
    <style>
        /* Estilos para responsividade mobile */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .header-info {
                flex-direction: column;
                gap: 10px;
            }
            
            .ctt-tabs .nav-tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
                overflow-y: hidden;
                white-space: nowrap;
                -webkit-overflow-scrolling: touch;
            }
            
            .ctt-tabs .nav-link {
                white-space: nowrap;
            }
            
            .stat-card .card-content h3 {
                font-size: 1.5rem;
            }
            
            .progress-stats .stat {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }
            
            .progress-section h6 {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }
            
            .frente-card .card-body {
                padding: 1rem;
            }
            
            .mobile-stack {
                flex-direction: column;
            }
            
            .mobile-full-width {
                width: 100% !important;
            }
            
            .mobile-btn-group {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            
            .mobile-btn-group .btn {
                width: 100%;
            }
            
            .mobile-hidden {
                display: none !important;
            }
            
            .mobile-visible {
                display: block !important;
            }
            
            .mobile-text-center {
                text-align: center;
            }
            
            .mobile-padding {
                padding: 0.5rem;
            }
            
            .mobile-margin-bottom {
                margin-bottom: 1rem;
            }
        }
        
        @media (max-width: 576px) {
            .container {
                padding-left: 10px;
                padding-right: 10px;
            }
            
            .ctt-card {
                margin-bottom: 1rem;
            }
            
            .btn-lg {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
            
            .form-control, .form-select {
                font-size: 16px; /* Evita zoom no iOS */
            }
            
            .select2-container {
                width: 100% !important;
            }
        }
        
        /* Estilos para o botão de edição */
        .edit-btn {
            background: none;
            border: none;
            color: #007bff;
            cursor: pointer;
            font-size: 1rem;
            padding: 5px;
            transition: color 0.2s;
        }
        
        .edit-btn:hover {
            color: #0056b3;
        }
        
        .edit-btn.disabled {
            color: #6c757d;
            cursor: not-allowed;
            opacity: 0.5;
        }
        
        .tractor-icon {
            font-size: 1.2rem;
            margin-right: 5px;
        }
        
        .entry-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
        }
        
        .entry-item:last-child {
            border-bottom: none;
        }
        
        .entry-details {
            flex: 1;
        }
        
        .entry-actions {
            display: flex;
            gap: 5px;
        }
        
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        
        .progress-bar-striped {
            background-image: linear-gradient(45deg, rgba(255,255,255,0.15) 25%, transparent 25%, transparent 50%, rgba(255,255,255,0.15) 50%, rgba(255,255,255,0.15) 75%, transparent 75%, transparent);
            background-size: 1rem 1rem;
        }
        
        .progress-bar-animated {
            animation: progress-bar-stripes 1s linear infinite;
        }
        
        @keyframes progress-bar-stripes {
            0% {
                background-position: 1rem 0;
            }
            100% {
                background-position: 0 0;
            }
        }
        
        .progress-section {
            margin-bottom: 1.5rem;
        }
        
        .progress-section:last-child {
            margin-bottom: 0;
        }
        
        .operacao-name {
            font-weight: 600;
            flex: 1;
            font-size: 0.9rem;
        }
        
        .operacao-progress {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            background: rgba(0,0,0,0.05);
        }
        
        .status-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
        }
        
        .status-item:last-child {
            border-bottom: none;
        }
        
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #fff;
        }
        
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }
            
            .header-info .admin-actions,
            .header-info .btn-outline-light:not(.mobile-visible) {
                display: none;
            }
            
            .mobile-menu-open .header-info .admin-actions,
            .mobile-menu-open .header-info .btn-outline-light {
                display: block;
                width: 100%;
                margin-bottom: 5px;
            }
        }

        /* Auto-hide para alertas */
        .alert-auto-hide {
            animation: fadeOut 10s forwards;
        }
        
        @keyframes fadeOut {
            0% { opacity: 1; }
            80% { opacity: 1; }
            100% { opacity: 0; display: none; }
        }
        
        .no-edit-tooltip {
            position: relative;
        }
        
        .no-edit-tooltip::after {
            content: "Edição permitida apenas para apontamentos do dia atual";
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
            z-index: 1000;
        }
        
        .no-edit-tooltip:hover::after {
            opacity: 1;
        }
    </style>
</head>
<body>

<!-- Header -->
<header class="ctt-header">
    <div class="container">
        
        <div class="header-content">
            
            <div class="header-brand">

                <img src="/public/static/img/logousa.png" alt="CTT Logo" class="header-logo">
                                <i class="fas fa-tractor"></i>
                <h1>Agro-Dash</h1>
                <span class="badge bg-warning">v2.0</span>
            </div>
            <button class="mobile-menu-btn" id="mobileMenuBtn">
        
            </button>
            <div class="header-info" id="headerInfo">
                <div class="user-info">
                    <i class="fas fa-user"></i>
                    <span><?= htmlspecialchars($username) ?> (<?= htmlspecialchars($user_role) ?>)</span>
                </div>
                <div class="time-info" id="current-time">
                    <i class="fas fa-clock"></i>
                    <span>Carregando...</span>
                </div>
                <?php if ($user_role === 'admin' || $user_role === 'cia_dev'): ?>
                    <div class="admin-actions">
                        <a href="/metas" class="btn btn-sm btn-outline-light me-2">Metas</a>
                        <a href="/dashboard" class="btn btn-sm btn-outline-light">Back</a>
                    </div>
                <?php endif; ?>
                <a href="/logout" class="btn btn-sm btn-outline-light">
                    <i class="fas fa-sign-out-alt"></i> Sair
                </a>
            </div>
        </div>
    </div>
</header>

<!-- Navegação em Abas -->
<nav class="ctt-tabs">
    <div class="container">
        <div class="nav nav-tabs" id="nav-tab" role="tablist">
            <button class="nav-link active" id="nav-dashboard-tab" data-bs-toggle="tab" data-bs-target="#nav-dashboard" type="button" role="tab">
                <i class="fas fa-chart-line"></i> <span class="mobile-hidden">Dashboard</span>
            </button>
            <button class="nav-link" id="nav-metas-tab" data-bs-toggle="tab" data-bs-target="#nav-metas" type="button" role="tab">
                <i class="fas fa-bullseye"></i> <span class="mobile-hidden">Metas Detalhadas</span>
            </button>

        </div>
    </div>
</nav>

<div class="container mt-4">
    <!-- Mensagens de feedback -->
    <?php if ($feedback_message): ?>
        <div class="alert alert-<?php echo (stripos($feedback_message,'Erro') !== false ? 'danger' : 'success'); ?> alert-dismissible fade show alert-auto-hide" role="alert" id="autoHideAlert">
            <i class="fas <?php echo (stripos($feedback_message,'Erro') !== false ? 'fa-exclamation-triangle' : 'fa-check-circle'); ?>"></i>
            <?= $feedback_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Conteúdo das Abas -->
    <div class="tab-content" id="nav-tabContent">
        
        <!-- ABA DASHBOARD -->
        <div class="tab-pane fade show active" id="nav-dashboard" role="tabpanel">
            <div class="row g-4">
                <!-- Cards de Estatísticas -->
                <div class="col-xl-3 col-md-6">
                    <div class="ctt-card stat-card">
                        <div class="card-icon bg-primary">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div class="card-content">
                            <h3><?= number_format($total_daily_hectares, 2, ',', '.') ?></h3>
                            <p>Ha Hoje</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="ctt-card stat-card">
                        <div class="card-icon bg-success">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <div class="card-content">
                            <h3><?= number_format($total_daily_goal, 0, ',', '.') ?></h3>
                            <p>Meta Diária</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="ctt-card stat-card">
                        <div class="card-icon bg-warning">
                            <i class="fas fa-calendar"></i>
                        </div>
                        <div class="card-content">
                            <h3><?= number_format($total_monthly_hectares, 2, ',', '.') ?></h3>
                            <p>Realizado Mensal</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="ctt-card stat-card">
                        <div class="card-icon bg-info">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <div class="card-content">
                            <h3><?= number_format($total_monthly_goal, 0, ',', '.') ?></h3>
                            <p>Meta Mensal</p>
                        </div>
                    </div>
                </div>

                <!-- Progresso Diário -->
                <div class="col-xl-6">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-line"></i> Progresso Diário</h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $daily_progress_percentage = ($total_daily_goal > 0) ? min(100, ($total_daily_hectares / $total_daily_goal) * 100) : 0;
                            ?>
                            <div class="progress mb-3" style="height: 25px;">
                                <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" 
                                     style="width: <?= $daily_progress_percentage ?>%">
                                    <?= number_format($daily_progress_percentage, 2, ',', '.') ?>%
                                </div>
                            </div>
                            <div class="progress-stats">
                                <div class="stat">
                                    <span class="label">Realizado:</span>
                                    <span class="value"><?= number_format($total_daily_hectares, 2, ',', '.') ?> ha</span>
                                </div>
                                <div class="stat">
                                    <span class="label">Meta:</span>
                                    <span class="value"><?= number_format($total_daily_goal, 0, ',', '.') ?> ha</span>
                                </div>
                                <div class="stat">
                                    <span class="label">Apontamentos:</span>
                                    <span class="value"><?= (int)$total_daily_entries ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Progresso Mensal -->
                <div class="col-xl-6">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-line"></i> Progresso Mensal</h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $monthly_progress_percentage = ($total_monthly_goal > 0) ? min(100, ($total_monthly_hectares / $total_monthly_goal) * 100) : 0;
                            ?>
                            <div class="progress mb-3" style="height: 25px;">
                                <div class="progress-bar bg-info progress-bar-striped progress-bar-animated" role="progressbar" 
                                     style="width: <?= $monthly_progress_percentage ?>%">
                                    <?= number_format($monthly_progress_percentage, 2, ',', '.') ?>%
                                </div>
                            </div>
                            <div class="progress-stats">
                                <div class="stat">
                                    <span class="label">Realizado:</span>
                                    <span class="value"><?= number_format($total_monthly_hectares, 2, ',', '.') ?> ha</span>
                                </div>
                                <div class="stat">
                                    <span class="label">Meta:</span>
                                    <span class="value"><?= number_format($total_monthly_goal, 0, ',', '.') ?> ha</span>
                                </div>
                                <div class="stat">
                                    <span class="label">Apontamentos:</span>
                                    <span class="value"><?= (int)$total_monthly_entries ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Formulário de Apontamento -->
                <div class="col-xl-8">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-plus-circle"></i> Novo Apontamento</h5>
                        </div>
                        <form action="/user_dashboard" method="POST" class="card-body" id="entryForm">
                            <input type="hidden" name="usuario_id" value="<?= (int)$usuario_id ?>">

                            <div class="row g-3">
                                <!-- LINHA 1: FAZENDA + HORÁRIO -->
                                <div class="col-md-6">
                                    <label class="form-label">Fazenda *</label>
                                    <select id="fazenda_id" name="fazenda_id" class="form-select select-search" required>
                                        <option value="">Selecione uma fazenda...</option>
                                        <?php foreach ($fazendas as $f): ?>
                                        <option value="<?= (int)$f['id'] ?>">
                                            <?= htmlspecialchars($f['nome']) ?> (<?= htmlspecialchars($f['codigo_fazenda']) ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Horário *</label>
                                    <select id="report_time" name="report_time" class="form-select" required>
                                        <?php foreach ($report_hours as $hour): ?>
                                            <option value="<?= $hour ?>" <?= $hour === '08:00' ? 'selected' : '' ?>>
                                                <?= $hour ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- LINHA 2: STATUS -->
                                <div class="col-md-6">
                                    <label class="form-label">Status *</label>
                                    <select id="status" name="status" class="form-select" required>
                                        <option value="ativo" selected>Ativo</option>
                                        <option value="parado">Parado</option>
                                    </select>
                                </div>

                                <!-- LINHA 3: OPERAÇÃO -->
                                <div class="col-md-6">
                                    <label class="form-label">Operação *</label>
                                    <select id="operation" name="operacao_id" class="form-select select-search" required>
                                        <option value="">Selecione a operação...</option>
                                        <?php foreach ($tipos_operacao as $op): ?>
                                        <option value="<?= (int)$op['id'] ?>" data-nome="<?= htmlspecialchars($op['nome']) ?>">
                                            <?= htmlspecialchars($op['nome']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- LINHA 4: EQUIPAMENTO -->
                                <div class="col-md-6">
                                    <label class="form-label">Equipamento *</label>
                                    <select id="equipment" name="equipamento_id" class="form-select select-search" required disabled>
                                        <option value="">Primeiro selecione uma operação</option>
                                    </select>
                                </div>

                                <!-- LINHA 5: HECTARES -->
                                <div class="col-md-6" id="hectares-group">
                                    <label class="form-label">Hectares *</label>
                                    <input type="number" step="0.01" id="hectares" name="hectares" class="form-control" placeholder="Ex: 15.5" required>
                                </div>

                                <!-- MOTIVO PARADA -->
                                <div class="col-12" id="reason-group" style="display:none;">
                                    <label class="form-label">Motivo da Parada</label>
                                    <textarea id="reason" name="observacoes" class="form-control" rows="3" placeholder="Descreva o motivo da parada..."></textarea>
                                </div>

                                <div class="col-12 text-end mobile-btn-group">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-paper-plane"></i> Salvar Apontamento
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Últimos Lançamentos -->
                <div class="col-xl-4">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-history"></i> Últimos Lançamentos</h5>
                        </div>
                        <div class="card-body">
                            <div class="date-filter mb-3">
                                <input type="date" id="filter-date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="alert alert-info" id="edit-info-message">
                                <small><i class="fas fa-info-circle"></i> A edição é permitida apenas para apontamentos do dia atual.</small>
                            </div>
                            <div id="no-entries-message" style="display: none; text-align: center; color: #777;">
                                Nenhum apontamento encontrado para esta data.
                            </div>
                            <ul id="recent-entries-list" class="status-list"></ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ABA METAS DETALHADAS -->
        <div class="tab-pane fade" id="nav-metas" role="tabpanel">
            <div class="row g-4">
                <?php foreach ($tipos_operacao as $operacao):
                    $op_id = (int)$operacao['id'];
                    $op_nome = $operacao['nome'];

                    $meta_diaria       = (float)($metas_por_operacao[$op_id]['meta_diaria'] ?? 0);
                    $meta_mensal       = (float)($metas_por_operacao[$op_id]['meta_mensal'] ?? 0);
                    $realizado_diario  = (float)($dados_por_operacao_diario[$op_id]['total_ha'] ?? 0);
                    $realizado_mensal  = (float)($dados_por_operacao_mensal[$op_id]['total_ha'] ?? 0);
                    $falta_diario      = max(0, $meta_diaria - $realizado_diario);
                    $falta_mensal      = max(0, $meta_mensal - $realizado_mensal);
                    
                    $progresso_diario = ($meta_diaria > 0) ? min(100, ($realizado_diario / $meta_diaria) * 100) : 0;
                    $progresso_mensal = ($meta_mensal > 0) ? min(100, ($realizado_mensal / $meta_mensal) * 100) : 0;
                ?>
                <div class="col-xl-6 col-lg-6">
                    <div class="ctt-card frente-card">
                        <div class="card-header">
                            <h6><i class="fas fa-tractor tractor-icon"></i> <?= htmlspecialchars($op_nome) ?></h6>
                        </div>
                        <div class="card-body">
                            <!-- Progresso Diário -->
                            <div class="progress-section">
                                <h6 class="mb-3">
                                    <i class="fas fa-sun text-warning"></i> Progresso Diário
                                    <span class="badge bg-<?= $progresso_diario >= 100 ? 'success' : ($progresso_diario >= 80 ? 'warning' : 'danger') ?> float-end">
                                        <?= number_format($progresso_diario, 1) ?>%
                                    </span>
                                </h6>
                                <div class="progress mb-3" style="height: 20px;">
                                    <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" 
                                         style="width: <?= $progresso_diario ?>%">
                                        <?= number_format($realizado_diario, 1) ?> ha
                                    </div>
                                </div>
                                <div class="frente-stats">
                                    <div class="stat">
                                        <span class="label">Meta:</span>
                                        <span class="value"><?= number_format($meta_diaria, 2, ',', '.') ?> ha</span>
                                    </div>
                                    <div class="stat">
                                        <span class="label">Realizado:</span>
                                        <span class="value text-success"><?= number_format($realizado_diario, 2, ',', '.') ?> ha</span>
                                    </div>
                                    <div class="stat">
                                        <span class="label">Falta:</span>
                                        <span class="value text-warning"><?= number_format($falta_diario, 2, ',', '.') ?> ha</span>
                                    </div>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            
                            <!-- Progresso Mensal -->
                            <div class="progress-section">
                                <h6 class="mb-3">
                                    <i class="fas fa-calendar text-info"></i> Progresso Mensal
                                    <span class="badge bg-<?= $progresso_mensal >= 100 ? 'success' : ($progresso_mensal >= 80 ? 'warning' : 'danger') ?> float-end">
                                        <?= number_format($progresso_mensal, 1) ?>%
                                    </span>
                                </h6>
                                <div class="progress mb-3" style="height: 20px;">
                                    <div class="progress-bar bg-info progress-bar-striped progress-bar-animated" role="progressbar" 
                                         style="width: <?= $progresso_mensal ?>%">
                                        <?= number_format($realizado_mensal, 1) ?> ha
                                    </div>
                                </div>
                                <div class="frente-stats">
                                    <div class="stat">
                                        <span class="label">Meta:</span>
                                        <span class="value"><?= number_format($meta_mensal, 2, ',', '.') ?> ha</span>
                                    </div>
                                    <div class="stat">
                                        <span class="label">Realizado:</span>
                                        <span class="value text-success"><?= number_format($realizado_mensal, 2, ',', '.') ?> ha</span>
                                    </div>
                                    <div class="stat">
                                        <span class="label">Falta:</span>
                                        <span class="value text-warning"><?= number_format($falta_mensal, 2, ',', '.') ?> ha</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ABA COMPARATIVOS -->
        <div class="tab-pane fade" id="nav-comparison" role="tabpanel">
            <div class="row g-4">
                <div class="col-12">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-bar"></i> Comparativo: Manual vs Solinftec</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="comparisonChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Edição de Apontamento -->
<div class="modal fade" id="editEntryModal" tabindex="-1" aria-labelledby="editEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editEntryModalLabel">
                    <i class="fas fa-edit"></i> Editar Apontamento
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editEntryForm">
                    <input type="hidden" id="edit_entry_id" name="entry_id">
                    
                    <div class="mb-3">
                        <label for="edit_equipamento" class="form-label">Equipamento</label>
                        <input type="text" class="form-control" id="edit_equipamento" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_operacao" class="form-label">Operação</label>
                        <input type="text" class="form-control" id="edit_operacao" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_hectares" class="form-label">Hectares</label>
                        <input type="number" step="0.01" class="form-control" id="edit_hectares" name="hectares" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_hora" class="form-label">Horário</label>
                        <select class="form-select" id="edit_hora" name="hora_selecionada" required>
                            <?php foreach ($report_hours as $hour): ?>
                                <option value="<?= $hour ?>"><?= $hour ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_observacoes" class="form-label">Observações</label>
                        <textarea class="form-control" id="edit_observacoes" name="observacoes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer mobile-btn-group">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="saveEditBtn">Salvar Alterações</button>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Inicialização do Select2
$(document).ready(function() {
    $('.select-search').select2({
        placeholder: "Selecione...",
        allowClear: true,
        width: '100%'
    });

    // Auto-hide para alertas
    setTimeout(function() {
        $('.alert-auto-hide').alert('close');
    }, 10000); // 10 segundos

    // Menu mobile
    $('#mobileMenuBtn').click(function() {
        $('#headerInfo').toggleClass('mobile-visible');
        $('body').toggleClass('mobile-menu-open');
    });

    // Atualizar relógio em tempo real
    function updateClock() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('pt-BR', { 
            timeZone: 'America/Sao_Paulo',
            hour12: false,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        $('#current-time span').text(timeString);
    }
    updateClock();
    setInterval(updateClock, 1000);

    // Carregar equipamentos baseado na operação selecionada
    $('#operation').change(function() {
        const operationId = $(this).val();
        const equipmentSelect = $('#equipment');
        
        if (!operationId) {
            equipmentSelect.prop('disabled', true).html('<option value="">Primeiro selecione uma operação</option>');
            return;
        }

        equipmentSelect.prop('disabled', true).html('<option value="">Carregando equipamentos...</option>');

        $.ajax({
            url: '/equipamentos',
            type: 'GET',
            data: { operacao_id: operationId },
            dataType: 'json'
        }).done(function(response) {
            if (response.success) {
                equipmentSelect.html('<option value="">Selecione um equipamento...</option>');
                if (response.equipamentos && Array.isArray(response.equipamentos)) {
                    response.equipamentos.forEach(function(e) {
                        equipmentSelect.append('<option value="' + e.id + '">' + e.nome + '</option>');
                    });
                }
                equipmentSelect.prop('disabled', false).trigger('change');
            } else {
                equipmentSelect.html('<option value="">Erro ao carregar equipamentos</option>').prop('disabled', true);
            }
        }).fail(function() {
            equipmentSelect.html('<option value="">Erro ao carregar equipamentos</option>').prop('disabled', true);
        });
    });

    // Mostrar/ocultar campos baseado no status
    $('#status').change(function() {
        const isActive = $(this).val() === 'ativo';
        $('#hectares-group').toggle(isActive);
        $('#reason-group').toggle(!isActive);
        $('#hectares').prop('required', isActive);
        $('#reason').prop('required', !isActive);
    }).trigger('change');

    // Carregar últimos lançamentos
    function loadRecentEntries(date) {
        const userId = <?php echo json_encode($_SESSION['usuario_id']); ?>;
        const list = $('#recent-entries-list');
        const noEntries = $('#no-entries-message');
        
        list.html('<div style="text-align:center;padding:1rem;">Carregando...</div>');
        noEntries.hide();

        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { 
                date: date, 
                usuario_id: userId 
            },
            dataType: 'json'
        }).done(function(response) {
            list.empty();
            
            if (Array.isArray(response) && response.length) {
                response.forEach(function(entry) {
                    const horaTexto = entry.hora_selecionada
                        ? String(entry.hora_selecionada).slice(0,5)
                        : (entry.data_hora ? String(entry.data_hora).replace('T',' ').slice(11,16) : '--:--');

                    const item = $('<li class="entry-item">');
                    
                    const details = $('<div class="entry-details">').append(
                        $('<div>').append(
                            $('<span class="status-indicator">').css('background-color', entry.hectares > 0 ? '#28a745' : '#dc3545'),
                            $('<strong>').text(entry.equipamento_nome || 'Equipamento')
                        ),
                        $('<small class="text-muted">').text(
                            (entry.unidade_nome || '') + ' - ' + (entry.operacao_nome || '')
                        ),
                        $('<br>'),
                        $('<small>').html(
                            entry.hectares > 0 
                                ? '<span class="text-success">' + parseFloat(entry.hectares).toFixed(2) + ' ha | Hora: ' + horaTexto + '</span>'
                                : '<span class="text-danger">Parado | Hora: ' + horaTexto + '</span>'
                        )
                    );
                    
                    const actions = $('<div class="entry-actions">');
                    
                    // Botão de edição - SOMENTE para apontamentos do dia atual
                    const editBtn = $('<button type="button" class="edit-btn" title="' + (entry.pode_editar ? 'Editar apontamento' : 'Edição permitida apenas para apontamentos do dia atual') + '">')
                        .html('<i class="fas fa-edit"></i>');
                    
                    if (entry.pode_editar) {
                        editBtn.click(function() {
                            openEditModal(entry);
                        });
                    } else {
                        editBtn.addClass('disabled no-edit-tooltip');
                        editBtn.prop('disabled', true);
                    }
                    
                    actions.append(editBtn);
                    
                    item.append(details, actions);
                    list.append(item);
                });
            } else {
                noEntries.show();
            }
        }).fail(function() {
            list.html('<li class="text-danger text-center">Erro ao carregar os apontamentos.</li>');
        });
    }

    // Abrir modal de edição
    function openEditModal(entry) {
        console.log('Dados do apontamento:', entry); // Para debug
        
        $('#edit_entry_id').val(entry.id);
        $('#edit_equipamento').val(entry.equipamento_nome || '');
        $('#edit_operacao').val(entry.operacao_nome || '');
        $('#edit_hectares').val(entry.hectares || '0');
        
        // Converter formato 00:00:00 para 00:00
        let horaCorreta = '08:00'; // Valor padrão
        
        if (entry.hora_selecionada) {
            // Se estiver no formato 00:00:00, converte para 00:00
            if (entry.hora_selecionada.includes(':')) {
                const partes = entry.hora_selecionada.split(':');
                if (partes.length >= 2) {
                    horaCorreta = partes[0] + ':' + partes[1];
                } else {
                    horaCorreta = entry.hora_selecionada;
                }
            } else {
                horaCorreta = entry.hora_selecionada;
            }
            
            // Garante formato HH:MM
            if (horaCorreta.length === 5 && horaCorreta.includes(':')) {
                // Já está no formato correto
            } else if (horaCorreta.length === 4 && !horaCorreta.includes(':')) {
                // Formato HHMM, adiciona os dois pontos
                horaCorreta = horaCorreta.substring(0, 2) + ':' + horaCorreta.substring(2, 4);
            } else if (horaCorreta.length === 4 && horaCorreta.includes(':')) {
                // Formato H:MM, adiciona zero à esquerda
                horaCorreta = '0' + horaCorreta;
            }
        } 
        // Se não tiver hora_selecionada, tenta extrair de data_hora
        else if (entry.data_hora) {
            try {
                const dataHora = new Date(entry.data_hora);
                if (!isNaN(dataHora.getTime())) {
                    const horas = String(dataHora.getHours()).padStart(2, '0');
                    const minutos = String(dataHora.getMinutes()).padStart(2, '0');
                    horaCorreta = horas + ':' + minutos;
                }
            } catch (e) {
                console.error('Erro ao extrair hora de data_hora:', e);
            }
        }
        
        console.log('Hora convertida:', horaCorreta); // Para debug
        
        // Define o valor no select
        $('#edit_hora').val(horaCorreta);
        $('#edit_observacoes').val(entry.observacoes || '');
        
        const modal = new bootstrap.Modal(document.getElementById('editEntryModal'));
        modal.show();
    }

    // Salvar edição
    $('#saveEditBtn').click(function() {
        const entryId = $('#edit_entry_id').val();
        const hectares = parseFloat($('#edit_hectares').val()) || 0;
        const horaSelecionada = $('#edit_hora').val();
        const observacoes = $('#edit_observacoes').val();
        
        // Validação básica
        if (!entryId) {
            showAlert('ID do apontamento não encontrado', 'danger');
            return;
        }
        
        if (hectares < 0) {
            showAlert('Hectares não pode ser negativo', 'danger');
            return;
        }
        
        // Mostrar loading no botão
        const saveBtn = $(this);
        const originalText = saveBtn.html();
        saveBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Salvando...');
        
        // Preparar dados para envio
        const formData = new FormData();
        formData.append('entry_id', entryId);
        formData.append('hectares', hectares);
        formData.append('hora_selecionada', horaSelecionada);
        formData.append('observacoes', observacoes);
        
        // Fazer requisição AJAX
        $.ajax({
            url: window.location.href, // Envia para a mesma página
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function(response) {
            if (response.success) {
                // Fechar modal
                bootstrap.Modal.getInstance(document.getElementById('editEntryModal')).hide();
                
                // Recarregar lista de apontamentos
                loadRecentEntries($('#filter-date').val());
                
                // Mostrar mensagem de sucesso que some automaticamente
                showAlertAutoHide(response.message || 'Apontamento atualizado com sucesso!', 'success');
            } else {
                showAlert(response.message || 'Erro ao atualizar apontamento', 'danger');
            }
        }).fail(function(xhr, status, error) {
            console.error('Erro na requisição:', error);
            showAlert('Erro de comunicação com o servidor: ' + error, 'danger');
        }).always(function() {
            // Restaurar botão
            saveBtn.prop('disabled', false).html(originalText);
        });
    });

    // Função para mostrar alertas normais
    function showAlert(message, type) {
        // Remover alertas existentes
        $('.alert-dismissible').alert('close');
        
        // Criar novo alerta
        const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
        
        const alertDiv = $('<div class="alert ' + alertClass + ' alert-dismissible fade show" role="alert">')
            .html('<i class="fas ' + iconClass + '"></i> ' + message + 
                  '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>');
        
        $('.container').prepend(alertDiv);
        
        // Auto-remover após 5 segundos
        setTimeout(function() {
            alertDiv.alert('close');
        }, 5000);
    }

    // Função para mostrar alertas que somem automaticamente após 10 segundos
    function showAlertAutoHide(message, type) {
        // Remover alertas existentes
        $('.alert-dismissible').alert('close');
        
        // Criar novo alerta com classe auto-hide
        const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
        
        const alertDiv = $('<div class="alert ' + alertClass + ' alert-dismissible fade show alert-auto-hide" role="alert">')
            .html('<i class="fas ' + iconClass + '"></i> ' + message + 
                  '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>');
        
        $('.container').prepend(alertDiv);
        
        // Auto-remover após 10 segundos
        setTimeout(function() {
            alertDiv.alert('close');
        }, 10000);
    }

    // Carregar lançamentos ao mudar data
    $('#filter-date').change(function() {
        loadRecentEntries($(this).val());
    });

    // Carregar lançamentos iniciais
    loadRecentEntries($('#filter-date').val());

    // Gráfico de comparativos
    const comparisonCtx = document.getElementById('comparisonChart').getContext('2d');
    const comparisonChart = new Chart(comparisonCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(function($d) { return date('d/m', strtotime($d['data'])); }, $comparison_data)) ?>,
            datasets: [
                {
                    label: 'Manual (ha)',
                    data: <?= json_encode(array_column($comparison_data, 'ha_manual')) ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.8)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Solinftec (ha)',
                    data: <?= json_encode(array_column($comparison_data, 'ha_solinftec')) ?>,
                    backgroundColor: 'rgba(255, 99, 132, 0.8)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Hectares (ha)'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Data'
                    }
                }
            }
        }
    });
});
</script>

</body>
</html>