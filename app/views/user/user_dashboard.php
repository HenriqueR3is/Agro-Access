<?php
// user_dashboard.php - FUSÃO COMPLETA: CRUD ROBUSTO + COMPARATIVO SGPA
date_default_timezone_set('America/Sao_Paulo');

session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

// 1. Verificação de Manutenção
if (isset($pdo)) {
    $stmt = $pdo->query("SELECT manutencao_ativa FROM configuracoes_sistema WHERE id = 1");
    if ($stmt && $stmt->fetchColumn()) { header("Location: /maintenance"); exit; }
}

// 2. Segurança
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit(); }

$feedback_message = '';
if (isset($_SESSION['feedback_message'])) {
    $feedback_message = $_SESSION['feedback_message'];
    unset($_SESSION['feedback_message']);
}

$report_hours = ['02:00','04:00','06:00','08:00','10:00','12:00','14:00','16:00','18:00','20:00','22:00','00:00'];

// =================================================================================
// PROCESSAMENTO AJAX (EDITAR E BUSCAR) - RESTAURADO
// =================================================================================

// Processar edição de apontamento (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entry_id'])) {
    header('Content-Type: application/json');
    try {
        $entry_id = (int)$_POST['entry_id'];
        $hectares = (float)$_POST['hectares'];
        $hora_selecionada = $_POST['hora_selecionada'];
        $observacoes = $_POST['observacoes'] ?? '';
        
        // Verificar permissão e data
        $stmt_check = $pdo->prepare("SELECT usuario_id, DATE(data_hora) as data_apontamento FROM apontamentos WHERE id = ?");
        $stmt_check->execute([$entry_id]);
        $apontamento = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        $hoje = date('Y-m-d');
        
        if (!$apontamento || $apontamento['usuario_id'] != $_SESSION['usuario_id']) {
            echo json_encode(['success' => false, 'message' => 'Acesso negado']); exit;
        }
        
        if ($apontamento['data_apontamento'] != $hoje) {
            echo json_encode(['success' => false, 'message' => 'Edição permitida apenas para hoje']); exit;
        }
        
        $stmt_update = $pdo->prepare("UPDATE apontamentos SET hectares = ?, hora_selecionada = ?, observacoes = ? WHERE id = ?");
        $stmt_update->execute([$hectares, $hora_selecionada, $observacoes, $entry_id]);
        
        echo json_encode(['success' => true, 'message' => 'Apontamento atualizado com sucesso!']); exit;
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar: ' . $e->getMessage()]); exit;
    }
}

// Endpoint para buscar apontamentos (AJAX - Histórico)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['date']) && isset($_GET['usuario_id'])) {
    try {
        $date = $_GET['date'];
        $usuario_id = (int)$_GET['usuario_id'];
        $hoje = date('Y-m-d');
        
        $stmt_apontamentos = $pdo->prepare("
            SELECT 
                a.id, a.hectares, a.hora_selecionada, a.data_hora, a.observacoes,
                e.nome as equipamento_nome, o.nome as operacao_nome, u.nome as unidade_nome,
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
        
        // Flag de edição
        foreach ($apontamentos as &$apontamento) {
            $apontamento['pode_editar'] = ($apontamento['data_apontamento'] == $hoje);
        }
        
        header('Content-Type: application/json');
        echo json_encode($apontamentos); exit;
        
    } catch (PDOException $e) {
        header('Content-Type: application/json'); echo json_encode([]); exit;
    }
}

// =================================================================================
// PROCESSAMENTO POST (SALVAR NOVO)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario_id']) && !isset($_POST['entry_id'])) {
    try {
        $uid = (int)$_POST['usuario_id'];
        $time = $_POST['report_time'];
        $status = $_POST['status'];
        $fazenda = (int)$_POST['fazenda_id'];
        $equip = (int)$_POST['equipamento_id'];
        $oper = (int)$_POST['operacao_id'];
        $ha = ($status === 'ativo') ? (float)$_POST['hectares'] : 0;
        $obs = ($status === 'parado') ? ($_POST['observacoes'] ?? '') : '';

        $stmt_uni = $pdo->prepare("SELECT unidade_id FROM fazendas WHERE id = ?");
        $stmt_uni->execute([$fazenda]);
        $unidade_id = $stmt_uni->fetchColumn() ?: 0;

        $dt_local = date('Y-m-d') . ' ' . $time . ':00';
        $pdo->prepare("INSERT INTO apontamentos (usuario_id, unidade_id, equipamento_id, operacao_id, hectares, data_hora, hora_selecionada, observacoes, fazenda_id) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$uid, $unidade_id, $equip, $oper, $ha, $dt_local, $time, $obs, $fazenda]);

        $_SESSION['feedback_message'] = "Apontamento salvo com sucesso!";
        header("Location: /user_dashboard"); exit;
    } catch (Exception $e) { $feedback_message = "Erro ao salvar: " . $e->getMessage(); }
}

// =================================================================================
// 2. CARREGAMENTO DE DADOS DA TELA
// =================================================================================

try {
    $usuario_id = $_SESSION['usuario_id'];
    
    // Dados Usuário + Unidade
    $stmt = $pdo->prepare("SELECT u.unidade_id, u.tipo, u.nome, un.nome as nome_unidade, un.abreviacao as sigla FROM usuarios u LEFT JOIN unidades un ON u.unidade_id = un.id WHERE u.id = ?");
    $stmt->execute([$usuario_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

    $unidade_do_usuario = $user_data['unidade_id'] ?? null;
    $unidade_nome_real  = $user_data['nome_unidade'] ?? ''; 
    $unidade_sigla      = $user_data['sigla'] ?? '';
    $user_role = $user_data['tipo'] ?? 'operador';
    $username = $user_data['nome'] ?? 'Usuário';

    // Inicializar Arrays
    $tipos_operacao = []; 
    $metas_por_operacao = []; 
    $dados_por_operacao_diario = []; 
    $dados_por_operacao_mensal = []; 
    $fazendas = [];
    $allowed_ids = []; 
    $allowed_names = [];

    if ($unidade_do_usuario) {
        $stmt_op = $pdo->prepare("SELECT t.id, t.nome FROM tipos_operacao t INNER JOIN usuario_operacao uo ON t.id = uo.operacao_id WHERE uo.usuario_id = ? ORDER BY t.nome");
        $stmt_op->execute([$usuario_id]);
        $tipos_operacao = $stmt_op->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($tipos_operacao)) {
            $stmt_all = $pdo->query("SELECT id, nome FROM tipos_operacao ORDER BY nome");
            $tipos_operacao = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
        }
        
        foreach($tipos_operacao as $op) {
            $allowed_ids[] = (int)$op['id'];
            $allowed_names[] = $op['nome'];
        }
        
        // Metas e Progresso (RESTAURADO CÁLCULO DETALHADO)
        $hoje_brt = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $data_hoje = $hoje_brt->format('Y-m-d'); $mes = $hoje_brt->format('m'); $ano = $hoje_brt->format('Y');
        
        if(!empty($allowed_ids)) {
            $in_clause = implode(',', array_fill(0, count($allowed_ids), '?'));
            $params = array_merge([$unidade_do_usuario], $allowed_ids);
            
            // Metas
            $stmt = $pdo->prepare("SELECT operacao_id, meta_diaria, meta_mensal FROM metas_unidade_operacao WHERE unidade_id = ? AND operacao_id IN ($in_clause)");
            $stmt->execute($params);
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $metas_por_operacao[$r['operacao_id']] = $r;

            // Progresso Diário por Operação
            $params_diario = array_merge([$unidade_do_usuario, $data_hoje], $allowed_ids);
            $stmt_diario = $pdo->prepare("
                SELECT operacao_id, SUM(hectares) AS total_ha, COUNT(*) AS total_entries
                FROM apontamentos
                WHERE unidade_id = ? AND DATE(data_hora) = ? AND operacao_id IN ($in_clause)
                GROUP BY operacao_id
            ");
            $stmt_diario->execute($params_diario);
            while ($row = $stmt_diario->fetch(PDO::FETCH_ASSOC)) {
                $dados_por_operacao_diario[$row['operacao_id']] = [
                    'total_ha' => (float)($row['total_ha'] ?? 0),
                    'total_entries' => (int)($row['total_entries'] ?? 0),
                ];
            }

            // Progresso Mensal por Operação
            $params_mensal = array_merge([$unidade_do_usuario, $mes, $ano], $allowed_ids);
            $stmt_mensal = $pdo->prepare("
                SELECT operacao_id, SUM(hectares) AS total_ha, COUNT(*) AS total_entries
                FROM apontamentos
                WHERE unidade_id = ? AND MONTH(data_hora) = ? AND YEAR(data_hora) = ? AND operacao_id IN ($in_clause)
                GROUP BY operacao_id
            ");
            $stmt_mensal->execute($params_mensal);
            while ($row = $stmt_mensal->fetch(PDO::FETCH_ASSOC)) {
                $dados_por_operacao_mensal[$row['operacao_id']] = [
                    'total_ha' => (float)($row['total_ha'] ?? 0),
                    'total_entries' => (int)($row['total_entries'] ?? 0),
                ];
            }
        }
        
        $stmt_f = $pdo->prepare("SELECT id, nome FROM fazendas WHERE unidade_id = ? ORDER BY nome");
        $stmt_f->execute([$unidade_do_usuario]);
        $fazendas = $stmt_f->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // 🚀 LÓGICA DE COMPARAÇÃO SGPA
    // =========================================================================
    $comparison_data = [];
    $detalhes_equipamento = [];
    $debug_msg = "";

    $MAPA_OPERACOES_SGPA = [
        'PLANTIO'    => ['PLANTANDO', 'PLANTIO MECANIZADO'],
        'COLHEITA'   => ['COLHENDO', 'COLHEITA', 'CORTANDO'],
        'ACOP'       => ['ACOP', 'TRANSBORDO'],
        'VINHACA'    => ['VINHAÇA', 'FERTIRRIGAÇÃO'],
        'SUBSOLAGEM' => ['SUBSOLAGEM', 'SUBSOLANDO']
    ];

    if ($unidade_do_usuario && $unidade_nome_real) {
        $termo1 = $unidade_nome_real;
        $termo2 = !empty($unidade_sigla) ? $unidade_sigla : $unidade_nome_real;
        $filtro_ops_sgpa = "";
        $params_ops_sgpa = [];
        $filtro_exclusao = "";

        if (!empty($allowed_names)) {
            $clausulas_or = [];
            foreach ($allowed_names as $nome_sistema) {
                $chave = strtoupper($nome_sistema);
                $palavras_chave = $MAPA_OPERACOES_SGPA[$chave] ?? [$nome_sistema];
                foreach ($palavras_chave as $palavra) {
                    $clausulas_or[] = "nome_operacao LIKE ?";
                    $params_ops_sgpa[] = "%$palavra%"; 
                }
                if (stripos($nome_sistema, 'Plantio') !== false) {
                    $filtro_exclusao .= " AND nome_operacao NOT LIKE '%REPLANTIO%' ";
                }
            }
            if (!empty($clausulas_or)) $filtro_ops_sgpa = "AND (" . implode(" OR ", $clausulas_or) . ")";
        }

        // 1. COMPARATIVO GERAL (7 dias)
        for ($i = 6; $i >= 0; $i--) {
            $data_alvo = date('Y-m-d', strtotime("-$i days"));
            $ha_manual = 0;
            if (!empty($allowed_ids)) {
                $in_ids = implode(',', array_fill(0, count($allowed_ids), '?'));
                $params_m = array_merge([$unidade_do_usuario, $data_alvo], $allowed_ids);
                $stmt_m = $pdo->prepare("SELECT SUM(hectares) FROM apontamentos WHERE unidade_id=? AND DATE(data_hora)=? AND operacao_id IN ($in_ids)");
                $stmt_m->execute($params_m);
                $ha_manual = (float)$stmt_m->fetchColumn();
            }
            $ha_solinftec = 0;
            if ($filtro_ops_sgpa) {
                $sql_sgpa = "SELECT SUM(hectares_sgpa) FROM producao_sgpa WHERE data = ? AND (unidade LIKE ? OR unidade LIKE ?) $filtro_ops_sgpa $filtro_exclusao";
                $params_s = array_merge([$data_alvo, "%$termo1%", "%$termo2%"], $params_ops_sgpa);
                $stmt_s = $pdo->prepare($sql_sgpa);
                $stmt_s->execute($params_s);
                $ha_solinftec = (float)$stmt_s->fetchColumn();
            }
            $comparison_data[] = ['data' => $data_alvo, 'dia_mes' => date('d/m', strtotime($data_alvo)), 'ha_manual' => $ha_manual, 'ha_solinftec' => $ha_solinftec, 'diff' => $ha_manual - $ha_solinftec];
        }

        // 2. DETALHAMENTO POR EQUIPAMENTO (Hoje)
        $dados_consolidados = [];
        // Manual
        if (!empty($allowed_ids)) {
            $in_ids = implode(',', array_fill(0, count($allowed_ids), '?'));
            $sql_det_man = "SELECT e.nome as nome_equip, SUM(a.hectares) as total FROM apontamentos a JOIN equipamentos e ON a.equipamento_id = e.id WHERE a.unidade_id = ? AND DATE(a.data_hora) = ? AND a.operacao_id IN ($in_ids) GROUP BY e.nome";
            $params_det_man = array_merge([$unidade_do_usuario, $data_hoje], $allowed_ids);
            $stmt_det_m = $pdo->prepare($sql_det_man);
            $stmt_det_m->execute($params_det_man);
            while($r = $stmt_det_m->fetch(PDO::FETCH_ASSOC)) {
                $chave_num = preg_replace('/\D/', '', explode('-', $r['nome_equip'])[0]); 
                if (!isset($dados_consolidados[$chave_num])) $dados_consolidados[$chave_num] = ['nome' => $r['nome_equip'], 'manual' => 0, 'auto' => 0];
                $dados_consolidados[$chave_num]['manual'] += (float)$r['total'];
            }
        }
        // SGPA
        if ($filtro_ops_sgpa) {
            $sql_det_sgpa = "SELECT nome_equipamento, SUM(hectares_sgpa) as total FROM producao_sgpa WHERE data = ? AND (unidade LIKE ? OR unidade LIKE ?) $filtro_ops_sgpa $filtro_exclusao GROUP BY nome_equipamento";
            $params_det_sgpa = array_merge([$data_hoje, "%$termo1%", "%$termo2%"], $params_ops_sgpa);
            $stmt_det_s = $pdo->prepare($sql_det_sgpa);
            $stmt_det_s->execute($params_det_sgpa);
            while($r = $stmt_det_s->fetch(PDO::FETCH_ASSOC)) {
                $chave_num = preg_replace('/\D/', '', explode('-', $r['nome_equipamento'])[0]);
                if (!isset($dados_consolidados[$chave_num])) {
                    $dados_consolidados[$chave_num] = ['nome' => $r['nome_equipamento'], 'manual' => 0, 'auto' => 0];
                } else {
                    if (strlen($r['nome_equipamento']) > strlen($dados_consolidados[$chave_num]['nome'])) $dados_consolidados[$chave_num]['nome'] = $r['nome_equipamento'];
                }
                $dados_consolidados[$chave_num]['auto'] += (float)$r['total'];
            }
        }
        foreach ($dados_consolidados as $id_num => $dados) {
            $detalhes_equipamento[] = ['equip' => $dados['nome'], 'manual' => $dados['manual'], 'auto' => $dados['auto'], 'diff' => $dados['manual'] - $dados['auto']];
        }
        usort($detalhes_equipamento, function($a, $b) { return strcmp($a['equip'], $b['equip']); });
    }

} catch (Exception $e) { $feedback_message = "Erro no sistema: " . $e->getMessage(); }

// =================================================================================
// 3. TOTAIS GERAIS (Recalculados com segurança)
// =================================================================================

// Garante que os arrays existam mesmo se estiverem vazios
if (!isset($dados_por_operacao_diario)) $dados_por_operacao_diario = [];
if (!isset($metas_por_operacao)) $metas_por_operacao = [];
if (!isset($dados_por_operacao_mensal)) $dados_por_operacao_mensal = [];

// Soma os valores das colunas dos arrays multidimensionais
$total_daily_hectares = array_sum(array_column($dados_por_operacao_diario, 'total_ha'));
$total_daily_entries  = array_sum(array_column($dados_por_operacao_diario, 'total_entries')); // Aqui estava o possível erro
$total_daily_goal     = array_sum(array_column($metas_por_operacao, 'meta_diaria'));

$total_monthly_hectares = array_sum(array_column($dados_por_operacao_mensal, 'total_ha'));
$total_monthly_entries  = array_sum(array_column($dados_por_operacao_mensal, 'total_entries'));
$total_monthly_goal     = array_sum(array_column($metas_por_operacao, 'meta_mensal'));
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= htmlspecialchars($user_role) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/public/static/css/ctt.css">
    <style>
        body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
        .alert, .no-edit-tooltip::after { text-shadow: none !important; box-shadow: 0 2px 5px rgba(0,0,0,0.1); font-weight: 500; }
        @media (max-width: 768px) {
            .header-content { flex-direction: column; text-align: center; gap: 10px; }
            .mobile-menu-btn { display: block; }
            .header-info .admin-actions, .header-info .btn-outline-light:not(.mobile-visible) { display: none; }
        }
        .mobile-menu-btn { display: none; background: none; border: none; font-size: 1.5rem; color: #fff; }
        .table-comp th { font-size: 0.85rem; background-color: #f8f9fa; }
        .table-comp td { font-size: 0.9rem; vertical-align: middle; }
        /* Estilos para a lista de histórico restaurada */
        .status-list { list-style: none; padding: 0; margin: 0; }
        .entry-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #eee; }
        .entry-item:last-child { border-bottom: none; }
        .entry-details { display: flex; flex-direction: column; }
        .status-indicator { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 8px; }
        .edit-btn { background: none; border: none; color: #6c757d; cursor: pointer; transition: color 0.2s; }
        .edit-btn:hover { color: #0d6efd; }
        .edit-btn.disabled { color: #dee2e6; cursor: not-allowed; }
        /* Pequeno ajuste para garantir que os ícones fiquem bonitos */
        .icon-box {
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        /* Cores de fundo suaves para os ícones (caso o Bootstrap 5.3 não carregue nativamente) */
        .bg-primary-subtle { background-color: #cfe2ff; color: #084298; }
        .bg-success-subtle { background-color: #d1e7dd; color: #0f5132; }
        .bg-warning-subtle { background-color: #fff3cd; color: #664d03; }
        .bg-info-subtle    { background-color: #cff4fc; color: #055160; }
    </style>
</head>
<body>

<header class="ctt-header">
    <div class="container">
        <div class="header-content d-flex justify-content-between align-items-center">
            <div class="header-brand">
                <img src="/public/static/img/logousa.png" alt="CTT Logo" class="header-logo">
                <i class="fas fa-tractor"></i> <h1>Agro-Dash</h1> <span class="badge bg-warning text-dark">v2.5</span>
            </div>
            <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
            <div class="header-info" id="headerInfo">
                <div class="user-info text-white">
                    <i class="fas fa-user"></i> <?= htmlspecialchars($username) ?>
                    <span class="badge bg-light text-dark ms-2"><?= htmlspecialchars($unidade_nome_real) ?></span>
                </div>
                <a href="/logout" class="btn btn-sm btn-outline-light ms-3">Sair</a>
            </div>
        </div>
    </div>
</header>

<nav class="ctt-tabs mt-3">
    <div class="container">
        <div class="nav nav-tabs" id="nav-tab" role="tablist">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#nav-dashboard"><i class="fas fa-chart-line"></i> Dashboard</button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#nav-metas"><i class="fas fa-bullseye"></i> Metas</button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#nav-comp"><i class="fas fa-balance-scale"></i> Comparativo</button>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <?php if ($feedback_message): ?>
        <div class="alert alert-info alert-dismissible fade show alert-auto-hide"><?= $feedback_message ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <div id="js-alert-container"></div>

    <div class="tab-content">
        
        <div class="tab-pane fade show active" id="nav-dashboard">
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="ctt-card card-compact h-100 border-start border-4 border-primary">
                        <div class="card-body p-3 d-flex align-items-center justify-content-between">
                            <div><span class="text-muted small text-uppercase fw-bold">Ha Hoje</span><h4 class="mb-0 fw-bold text-dark"><?= number_format($total_daily_hectares, 2, ',', '.') ?></h4></div>
                            <div class="icon-box bg-primary-subtle text-primary rounded-circle"><i class="fas fa-tractor fa-lg"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="ctt-card card-compact h-100 border-start border-4 border-success">
                        <div class="card-body p-3 d-flex align-items-center justify-content-between">
                            <div><span class="text-muted small text-uppercase fw-bold">Meta Dia</span><h4 class="mb-0 fw-bold text-dark"><?= number_format($total_daily_goal, 0, ',', '.') ?></h4></div>
                            <div class="icon-box bg-success-subtle text-success rounded-circle"><i class="fas fa-bullseye fa-lg"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="ctt-card card-compact h-100 border-start border-4 border-warning">
                        <div class="card-body p-3 d-flex align-items-center justify-content-between">
                            <div><span class="text-muted small text-uppercase fw-bold">Mês Real</span><h4 class="mb-0 fw-bold text-dark"><?= number_format($total_monthly_hectares, 0, ',', '.') ?></h4></div>
                            <div class="icon-box bg-warning-subtle text-warning rounded-circle"><i class="fas fa-calendar fa-lg"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="ctt-card card-compact h-100 border-start border-4 border-info">
                        <div class="card-body p-3 d-flex align-items-center justify-content-between">
                            <div><span class="text-muted small text-uppercase fw-bold">Mês Meta</span><h4 class="mb-0 fw-bold text-dark"><?= number_format($total_monthly_goal, 0, ',', '.') ?></h4></div>
                            <div class="icon-box bg-info-subtle text-info rounded-circle"><i class="fas fa-flag-checkered fa-lg"></i></div>
                        </div>
                    </div>
                </div>
            </div>

                <div class="row g-4">
                <div class="col-12 col-md-6">
                    <div class="ctt-card">
                        <div class="card-header"><h5><i class="fas fa-chart-line"></i> Progresso Diário</h5></div>
                        <div class="card-body">
                            <?php $dp = ($total_daily_goal > 0) ? min(100, ($total_daily_hectares / $total_daily_goal) * 100) : 0; ?>
                            <div class="progress mb-3" style="height: 25px;"><div class="progress-bar bg-success progress-bar-striped progress-bar-animated" style="width: <?= $dp ?>%"><?= number_format($dp, 1, ',', '.') ?>%</div></div>
                            <div class="d-flex justify-content-between text-muted small">
                                <div class="text-center"><span class="d-block fw-bold text-dark">Real</span><?= number_format($total_daily_hectares, 2, ',', '.') ?></div>
                                <div class="text-center"><span class="d-block fw-bold text-dark">Meta</span><?= number_format($total_daily_goal, 0, ',', '.') ?></div>
                                <div class="text-center"><span class="d-block fw-bold text-dark">Apont.</span><?= (int)$total_daily_entries ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <div class="ctt-card">
                        <div class="card-header"><h5><i class="fas fa-chart-line"></i> Progresso Mensal</h5></div>
                        <div class="card-body">
                            <?php $mp = ($total_monthly_goal > 0) ? min(100, ($total_monthly_hectares / $total_monthly_goal) * 100) : 0; ?>
                            <div class="progress mb-3" style="height: 25px;"><div class="progress-bar bg-info progress-bar-striped progress-bar-animated" style="width: <?= $mp ?>%"><?= number_format($mp, 1, ',', '.') ?>%</div></div>
                            <div class="d-flex justify-content-between text-muted small">
                                <div class="text-center"><span class="d-block fw-bold text-dark">Real</span><?= number_format($total_monthly_hectares, 2, ',', '.') ?></div>
                                <div class="text-center"><span class="d-block fw-bold text-dark">Meta</span><?= number_format($total_monthly_goal, 0, ',', '.') ?></div>
                                <div class="text-center"><span class="d-block fw-bold text-dark">Apont.</span><?= (int)$total_monthly_entries ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-8">
                    <div class="ctt-card h-100">
                        <div class="card-header"><h5><i class="fas fa-plus-circle"></i> Novo Apontamento</h5></div>
                        <div class="card-body">
                            <form method="POST" id="entryForm">
                                <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                                <div class="row g-3">
                                    <div class="col-md-6"><label>Fazenda</label><select name="fazenda_id" class="form-select select-search" required><option value="">Selecione...</option><?php foreach ($fazendas as $f): ?><option value="<?= $f['id'] ?>"><?= $f['nome'] ?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-6"><label>Hora</label><select name="report_time" class="form-select"><?php foreach ($report_hours as $h): ?><option value="<?= $h ?>"><?= $h ?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-6"><label>Status</label><select id="status" name="status" class="form-select"><option value="ativo">Ativo</option><option value="parado">Parado</option></select></div>
                                    <div class="col-md-6"><label>Operação</label><select id="operation" name="operacao_id" class="form-select select-search" required><option value="">Selecione...</option><?php foreach ($tipos_operacao as $op): ?><option value="<?= (int)$op['id'] ?>"><?= htmlspecialchars($op['nome']) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-6"><label>Equipamento</label><select id="equipment" name="equipamento_id" class="form-select select-search" required disabled><option value="">Selecione a operação...</option></select></div>
                                    <div class="col-md-6" id="hectares-group"><label>Hectares</label><input type="number" step="0.01" id="hectares" name="hectares" class="form-control" required></div>
                                    <div class="col-12" id="reason-group" style="display:none;"><label>Motivo</label><textarea id="reason" name="observacoes" class="form-control"></textarea></div>
                                    <div class="col-12 text-end"><button class="btn btn-primary">Salvar</button></div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-4">
                    <div class="ctt-card h-100">
                        <div class="card-header"><h5><i class="fas fa-history"></i> Hoje</h5></div>
                        <div class="card-body">
                            <div class="date-filter mb-3"><input type="date" id="filter-date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                            <ul id="recent-entries-list" class="status-list"></ul>
                            <div id="no-entries-message" style="display:none;text-align:center;color:#777;margin-top:20px;">Nada hoje.</div>
                        </div>
                    </div>
                </div>

        </div> </div> <div class="tab-pane fade" id="nav-metas">
            <div class="row g-4">
                <?php foreach ($tipos_operacao as $operacao): 
                    $op_id = (int)$operacao['id']; 
                    $op_nome = $operacao['nome'];
                    
                    // Recuperando dados dos arrays calculados no PHP
                    $meta_d = (float)($metas_por_operacao[$op_id]['meta_diaria'] ?? 0);
                    $meta_m = (float)($metas_por_operacao[$op_id]['meta_mensal'] ?? 0);
                    $real_d = (float)($dados_por_operacao_diario[$op_id]['total_ha'] ?? 0);
                    $real_m = (float)($dados_por_operacao_mensal[$op_id]['total_ha'] ?? 0);
                    
                    // Cálculos visuais (Falta, %)
                    $falta_d = max(0, $meta_d - $real_d);
                    $falta_m = max(0, $meta_m - $real_m);
                    $prog_d = ($meta_d > 0) ? min(100, ($real_d / $meta_d) * 100) : 0;
                    $prog_m = ($meta_m > 0) ? min(100, ($real_m / $meta_m) * 100) : 0;
                ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="ctt-card h-100">
                        <div class="card-header">
                            <h6><i class="fas fa-tractor me-2"></i> <?= htmlspecialchars($op_nome) ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <h6 class="mb-0 text-muted"><i class="fas fa-sun text-warning me-1"></i> Diário</h6>
                                    <span class="badge bg-<?= $prog_d >= 100 ? 'success' : ($prog_d >= 80 ? 'warning' : 'danger') ?>">
                                        <?= number_format($prog_d, 1) ?>%
                                    </span>
                                </div>
                                <div class="progress mb-2" style="height: 10px;">
                                    <div class="progress-bar bg-success" style="width: <?= $prog_d ?>%"></div>
                                </div>
                                <div class="d-flex justify-content-between text-center small bg-light p-2 rounded">
                                    <div><span class="text-muted d-block" style="font-size:0.75rem">Meta</span><strong><?= number_format($meta_d, 2, ',', '.') ?></strong></div>
                                    <div><span class="text-muted d-block" style="font-size:0.75rem">Real</span><strong class="text-success"><?= number_format($real_d, 2, ',', '.') ?></strong></div>
                                    <div><span class="text-muted d-block" style="font-size:0.75rem">Falta</span><strong class="text-warning"><?= number_format($falta_d, 2, ',', '.') ?></strong></div>
                                </div>
                            </div>
                            
                            <div>
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <h6 class="mb-0 text-muted"><i class="fas fa-calendar text-info me-1"></i> Mensal</h6>
                                    <span class="badge bg-<?= $prog_m >= 100 ? 'success' : ($prog_m >= 80 ? 'warning' : 'danger') ?>">
                                        <?= number_format($prog_m, 1) ?>%
                                    </span>
                                </div>
                                <div class="progress mb-2" style="height: 10px;">
                                    <div class="progress-bar bg-info" style="width: <?= $prog_m ?>%"></div>
                                </div>
                                <div class="d-flex justify-content-between text-center small bg-light p-2 rounded">
                                    <div><span class="text-muted d-block" style="font-size:0.75rem">Meta</span><strong><?= number_format($meta_m, 2, ',', '.') ?></strong></div>
                                    <div><span class="text-muted d-block" style="font-size:0.75rem">Real</span><strong class="text-success"><?= number_format($real_m, 2, ',', '.') ?></strong></div>
                                    <div><span class="text-muted d-block" style="font-size:0.75rem">Falta</span><strong class="text-warning"><?= number_format($falta_m, 2, ',', '.') ?></strong></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="tab-pane fade" id="nav-comp">
            <div class="row g-4">
                <div class="col-12">
                    <div class="ctt-card">
                        <div class="card-header"><h5><i class="fas fa-tractor"></i> Por Equipamento (Hoje)</h5></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped text-center mb-0 table-comp">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Equipamento</th>
                                            <th>Manual</th>
                                            <th>SGPA</th>
                                            <th>Diferença</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($detalhes_equipamento)): ?>
                                            <tr>
                                                <td colspan="5" class="text-muted p-3">Sem dados.</td>
                                            </tr>
                                        <?php else: foreach($detalhes_equipamento as $d): ?>
                                            <tr>
                                                <td class="text-start ps-3 fw-bold"><?= $d['equip'] ?></td>
                                                <td><?= number_format($d['manual'],2,',','.') ?></td>
                                                <td><?= number_format($d['auto'],2,',','.') ?></td>
                                                <td><?= number_format(abs($d['diff']),2,',','.') ?></td>
                                                <td><?= (abs($d['diff'])<0.5)?'<span class="badge bg-success">Ok</span>':'<span class="badge bg-warning text-dark">Atenção!</span>' ?></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="ctt-card">
                        <div class="card-header"><h5>Resumo Semanal</h5></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped text-center mb-0 table-comp">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Data</th>
                                            <th>Manual</th>
                                            <th>SGPA</th>
                                            <th>Diferença</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach(array_reverse($comparison_data) as $row): ?>
                                        <tr>
                                            <td><?= $row['dia_mes'] ?></td>
                                            <td><?= number_format($row['ha_manual'],2,',','.') ?></td>
                                            <td><?= number_format($row['ha_solinftec'],2,',','.') ?></td>
                                            <td><?= number_format(abs($row['diff']),2,',','.') ?></td>
                                            <td><?php if(abs($row['diff']) < 0.5) echo '<span class="badge bg-success">Ok</span>'; else echo '<span class="badge bg-danger">Dif</span>'; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

<div class="modal fade" id="editEntryModal" tabindex="-1" aria-labelledby="editEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editEntryModalLabel"><i class="fas fa-edit"></i> Editar Apontamento</h5>
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
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="saveEditBtn">Salvar Alterações</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    $('.select-search').select2({ width: '100%' });
    setTimeout(() => $('.alert-auto-hide').alert('close'), 10000);
    $('#mobileMenuBtn').click(() => { $('#headerInfo').toggleClass('mobile-visible'); $('body').toggleClass('mobile-menu-open'); });

    // Lógica para carregar equipamentos dinamicamente
    $('#operation').change(function() {
        const opId = $(this).val();
        $('#equipment').prop('disabled', true).html('<option>Carregando...</option>');
        $.get('/equipamentos', { operacao_id: opId }, function(res) {
            let opts = '<option value="">Selecione...</option>';
            if(res.equipamentos) res.equipamentos.forEach(e => opts += `<option value="${e.id}">${e.nome}</option>`);
            $('#equipment').html(opts).prop('disabled', false);
        }, 'json');
    });

    $('#status').change(function() {
        const active = $(this).val() === 'ativo';
        $('#hectares-group').toggle(active); $('#reason-group').toggle(!active);
        $('#hectares').prop('required', active); $('#reason').prop('required', !active);
    }).trigger('change');

    // === FUNÇÕES RESTAURADAS PARA HISTÓRICO E EDIÇÃO === //

    function showAlert(msg, type) {
        const html = `<div class="alert alert-${type} alert-dismissible fade show">${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
        $('#js-alert-container').html(html);
        setTimeout(() => $('#js-alert-container').html(''), 5000);
    }

    // Carregar últimos lançamentos (AJAX)
    function loadRecentEntries(date) {
        const userId = <?= json_encode($_SESSION['usuario_id']) ?>;
        const list = $('#recent-entries-list');
        const noEntries = $('#no-entries-message');
        
        list.html('<div style="text-align:center;padding:1rem;">Carregando...</div>');
        noEntries.hide();

        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { date: date, usuario_id: userId },
            dataType: 'json'
        }).done(function(response) {
            list.empty();
            if (Array.isArray(response) && response.length) {
                response.forEach(function(entry) {
                    const horaTexto = entry.hora_selecionada ? String(entry.hora_selecionada).slice(0,5) : (entry.data_hora ? String(entry.data_hora).replace('T',' ').slice(11,16) : '--:--');
                    const item = $('<li class="entry-item">');
                    const details = $('<div class="entry-details">').append(
                        $('<div>').append(
                            $('<span class="status-indicator">').css('background-color', entry.hectares > 0 ? '#28a745' : '#dc3545'),
                            $('<strong>').text(entry.equipamento_nome || 'Equipamento')
                        ),
                        $('<small class="text-muted">').text((entry.unidade_nome || '') + ' - ' + (entry.operacao_nome || '')),
                        $('<br>'),
                        $('<small>').html(entry.hectares > 0 ? `<span class="text-success">${parseFloat(entry.hectares).toFixed(2)} ha | Hora: ${horaTexto}</span>` : `<span class="text-danger">Parado | Hora: ${horaTexto}</span>`)
                    );
                    const actions = $('<div class="entry-actions">');
                    const editBtn = $('<button type="button" class="edit-btn">').html('<i class="fas fa-edit"></i>');
                    
                    if (entry.pode_editar) {
                        editBtn.attr('title', 'Editar').click(function() { openEditModal(entry); });
                    } else {
                        editBtn.addClass('disabled').attr('title', 'Edição apenas no dia atual').prop('disabled', true);
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

    // Abrir modal
    function openEditModal(entry) {
        $('#edit_entry_id').val(entry.id);
        $('#edit_equipamento').val(entry.equipamento_nome || '');
        $('#edit_operacao').val(entry.operacao_nome || '');
        $('#edit_hectares').val(entry.hectares || '0');
        $('#edit_observacoes').val(entry.observacoes || '');
        
        let hora = '08:00';
        if(entry.hora_selecionada && entry.hora_selecionada.length >= 5) hora = entry.hora_selecionada.substring(0,5);
        $('#edit_hora').val(hora);
        
        new bootstrap.Modal(document.getElementById('editEntryModal')).show();
    }

    // Salvar Edição
    $('#saveEditBtn').click(function() {
        const formData = new FormData($('#editEntryForm')[0]);
        const btn = $(this);
        const originalText = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Salvando...');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function(response) {
            if (response.success) {
                bootstrap.Modal.getInstance(document.getElementById('editEntryModal')).hide();
                loadRecentEntries($('#filter-date').val());
                showAlert(response.message, 'success');
                // Recarregar página após 1s para atualizar totais se necessário
                setTimeout(() => location.reload(), 1000);
            } else {
                showAlert(response.message, 'danger');
            }
        }).fail(function() { showAlert('Erro de comunicação.', 'danger'); })
        .always(function() { btn.prop('disabled', false).html(originalText); });
    });

    // Iniciar carregamento da lista
    $('#filter-date').change(function() { loadRecentEntries($(this).val()); });
    loadRecentEntries($('#filter-date').val());
});
</script>
</body>
</html>