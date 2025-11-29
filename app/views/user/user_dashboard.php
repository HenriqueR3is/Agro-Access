<?php
// user_dashboard.php - VERSÃO FINAL: COM DETALHAMENTO POR EQUIPAMENTO
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

// =================================================================================
// PROCESSAMENTO POST (SALVAR/EDITAR) - MANTIDO ORIGINAL
// =================================================================================

// Editar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entry_id'])) {
    try {
        $entry_id = (int)$_POST['entry_id'];
        $hectares = (float)$_POST['hectares'];
        $hora = $_POST['hora_selecionada'];
        $obs = $_POST['observacoes'] ?? '';
        
        $stmt_check = $pdo->prepare("SELECT usuario_id, DATE(data_hora) as dt FROM apontamentos WHERE id = ?");
        $stmt_check->execute([$entry_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $hoje = date('Y-m-d');
        if (!$row || $row['usuario_id'] != $_SESSION['usuario_id']) { echo json_encode(['success'=>false, 'message'=>'Acesso negado']); exit; }
        if ($row['dt'] != $hoje) { echo json_encode(['success'=>false, 'message'=>'Edição permitida apenas para hoje']); exit; }
        
        $pdo->prepare("UPDATE apontamentos SET hectares=?, hora_selecionada=?, observacoes=? WHERE id=?")->execute([$hectares, $hora, $obs, $entry_id]);
        echo json_encode(['success'=>true, 'message'=>'Atualizado!']); exit;
    } catch (Exception $e) { echo json_encode(['success'=>false, 'message'=>$e->getMessage()]); exit; }
}

// Salvar Novo
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

        $_SESSION['feedback_message'] = "Salvo com sucesso!";
        header("Location: /user_dashboard"); exit;
    } catch (Exception $e) { $feedback_message = "Erro: " . $e->getMessage(); }
}

// AJAX Lista
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['date']) && isset($_GET['usuario_id'])) {
    $stmt = $pdo->prepare("SELECT a.*, e.nome as equipamento_nome, o.nome as operacao_nome, u.nome as unidade_nome, DATE(a.data_hora) as data_apontamento FROM apontamentos a LEFT JOIN equipamentos e ON a.equipamento_id = e.id LEFT JOIN tipos_operacao o ON a.operacao_id = o.id LEFT JOIN unidades u ON a.unidade_id = u.id WHERE a.usuario_id = ? AND DATE(a.data_hora) = ? ORDER BY a.data_hora DESC");
    $stmt->execute([$_GET['usuario_id'], $_GET['date']]);
    $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($lista as &$l) $l['pode_editar'] = ($l['data_apontamento'] == date('Y-m-d'));
    header('Content-Type: application/json'); echo json_encode($lista); exit;
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

    // Listas
    $tipos_operacao = []; $metas_por_operacao = []; $dados_por_operacao_diario = []; $dados_por_operacao_mensal = []; $fazendas = [];
    $allowed_ids = []; $allowed_names = [];

    if ($unidade_do_usuario) {
        $stmt_op = $pdo->prepare("SELECT t.id, t.nome FROM tipos_operacao t INNER JOIN usuario_operacao uo ON t.id = uo.operacao_id WHERE uo.usuario_id = ? ORDER BY t.nome");
        $stmt_op->execute([$usuario_id]);
        $tipos_operacao = $stmt_op->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($tipos_operacao)) {
            $tipos_operacao = $pdo->query("SELECT id, nome FROM tipos_operacao ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
        }
        
        foreach($tipos_operacao as $op) {
            $allowed_ids[] = (int)$op['id'];
            $allowed_names[] = $op['nome'];
        }
        
        // Metas e Progresso
        $hoje_brt = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $data_hoje = $hoje_brt->format('Y-m-d'); $mes = $hoje_brt->format('m'); $ano = $hoje_brt->format('Y');
        
        if(!empty($allowed_ids)) {
            $in = implode(',', array_fill(0, count($allowed_ids), '?'));
            $params = array_merge([$unidade_do_usuario], $allowed_ids);
            
            $stmt = $pdo->prepare("SELECT operacao_id, meta_diaria, meta_mensal FROM metas_unidade_operacao WHERE unidade_id = ? AND operacao_id IN ($in)");
            $stmt->execute($params);
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $metas_por_operacao[$r['operacao_id']] = $r;

            $params_d = array_merge([$unidade_do_usuario, $data_hoje], $allowed_ids);
            $stmt = $pdo->prepare("SELECT operacao_id, SUM(hectares) as ha, COUNT(*) as qtd FROM apontamentos WHERE unidade_id = ? AND DATE(data_hora) = ? AND operacao_id IN ($in) GROUP BY operacao_id");
            $stmt->execute($params_d);
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $dados_por_operacao_diario[$r['operacao_id']] = ['total_ha'=>$r['ha'], 'total_entries'=>$r['qtd']];

            $params_m = array_merge([$unidade_do_usuario, $mes, $ano], $allowed_ids);
            $stmt = $pdo->prepare("SELECT operacao_id, SUM(hectares) as ha, COUNT(*) as qtd FROM apontamentos WHERE unidade_id = ? AND MONTH(data_hora)=? AND YEAR(data_hora)=? AND operacao_id IN ($in) GROUP BY operacao_id");
            $stmt->execute($params_m);
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $dados_por_operacao_mensal[$r['operacao_id']] = ['total_ha'=>$r['ha'], 'total_entries'=>$r['qtd']];
        }
        
        $stmt_f = $pdo->prepare("SELECT id, nome, codigo_fazenda FROM fazendas WHERE unidade_id = ? ORDER BY nome");
        $stmt_f->execute([$unidade_do_usuario]);
        $fazendas = $stmt_f->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // 🚀 LÓGICA DE COMPARAÇÃO E DETALHAMENTO
    // =========================================================================
    $comparison_data = [];
    $detalhes_equipamento = []; // Array para a nova tabela
    $debug_msg = "";

    $MAPA_OPERACOES_SGPA = [
        'PLANTIO'    => ['PLANTANDO', 'PLANTIO MECANIZADO'],
        'COLHEITA'   => ['COLHENDO', 'COLHEITA', 'CORTANDO'],
        'ACOP'       => ['ACOP', 'TRANSBORDO'],
        'VINHACA'    => ['VINHAÇA', 'FERTIRRIGAÇÃO'],
        'SUBSOLAGEM' => ['SUBSOLAGEM', 'SUBSOLANDO']
    ];

    if ($unidade_do_usuario && $unidade_nome_real) {
        
        // Configuração dos Filtros
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
            if (!empty($clausulas_or)) {
                $filtro_ops_sgpa = "AND (" . implode(" OR ", $clausulas_or) . ")";
            }
        }

        // 1. COMPARATIVO GERAL (Últimos 7 dias)
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

        // 2. DETALHAMENTO POR EQUIPAMENTO (Apenas Hoje)
        
        // Array auxiliar para normalizar nomes
        // Formato: [ '13100105' => ['nome_exibicao' => '...', 'manual' => 0, 'auto' => 0] ]
        $dados_consolidados = [];

        // A) Busca Manual Hoje
        if (!empty($allowed_ids)) {
            $in_ids = implode(',', array_fill(0, count($allowed_ids), '?'));
            // IMPORTANTE: Aqui pegamos o NOME do equipamento da tabela equipamentos
            $sql_det_man = "SELECT e.nome as nome_equip, SUM(a.hectares) as total 
                            FROM apontamentos a 
                            JOIN equipamentos e ON a.equipamento_id = e.id 
                            WHERE a.unidade_id = ? AND DATE(a.data_hora) = ? AND a.operacao_id IN ($in_ids) 
                            GROUP BY e.nome";
            $params_det_man = array_merge([$unidade_do_usuario, $data_hoje], $allowed_ids);
            $stmt_det_m = $pdo->prepare($sql_det_man);
            $stmt_det_m->execute($params_det_man);
            while($r = $stmt_det_m->fetch(PDO::FETCH_ASSOC)) {
                // Extrai só os números do nome para usar como CHAVE
                // Ex: "13100105" ou "13100105 - Trator" vira "13100105"
                $chave_num = preg_replace('/\D/', '', explode('-', $r['nome_equip'])[0]); 
                
                if (!isset($dados_consolidados[$chave_num])) {
                    $dados_consolidados[$chave_num] = ['nome' => $r['nome_equip'], 'manual' => 0, 'auto' => 0];
                }
                $dados_consolidados[$chave_num]['manual'] += (float)$r['total'];
            }
        }

        // B) Busca SGPA Hoje
        if ($filtro_ops_sgpa) {
            $sql_det_sgpa = "SELECT nome_equipamento, SUM(hectares_sgpa) as total 
                             FROM producao_sgpa 
                             WHERE data = ? AND (unidade LIKE ? OR unidade LIKE ?) $filtro_ops_sgpa $filtro_exclusao
                             GROUP BY nome_equipamento";
            $params_det_sgpa = array_merge([$data_hoje, "%$termo1%", "%$termo2%"], $params_ops_sgpa);
            $stmt_det_s = $pdo->prepare($sql_det_sgpa);
            $stmt_det_s->execute($params_det_sgpa);
            while($r = $stmt_det_s->fetch(PDO::FETCH_ASSOC)) {
                // Extrai só os números do nome do SGPA para usar como CHAVE
                // Ex: "13100105 - TRATOR VINHAÇA" vira "13100105"
                $nome_original = trim($r['nome_equipamento']);
                $chave_num = preg_replace('/\D/', '', explode('-', $nome_original)[0]);

                if (!isset($dados_consolidados[$chave_num])) {
                    // Se não existia (não teve manual), cria com o nome do SGPA
                    $dados_consolidados[$chave_num] = ['nome' => $nome_original, 'manual' => 0, 'auto' => 0];
                } else {
                    // Se já existia (veio do manual), atualiza o nome para o mais completo (SGPA geralmente é mais detalhado)
                    // Ou mantém o manual se preferir. Vou preferir o mais longo.
                    if (strlen($nome_original) > strlen($dados_consolidados[$chave_num]['nome'])) {
                        $dados_consolidados[$chave_num]['nome'] = $nome_original;
                    }
                }
                $dados_consolidados[$chave_num]['auto'] += (float)$r['total'];
            }
        }

        // C) Transforma o array associativo em lista para a tabela
        foreach ($dados_consolidados as $id_num => $dados) {
            $detalhes_equipamento[] = [
                'equip' => $dados['nome'], // Nome bonito para exibição
                'manual' => $dados['manual'],
                'auto' => $dados['auto'],
                'diff' => $dados['manual'] - $dados['auto']
            ];
        }
        
        // Ordena pelo número do equipamento
        usort($detalhes_equipamento, function($a, $b) {
            return strcmp($a['equip'], $b['equip']);
        });

    } else {
        $debug_msg = "Usuário sem unidade vinculada.";
    }

} catch (Exception $e) {
    $feedback_message = "Erro: " . $e->getMessage();
}

$report_hours = ['02:00','04:00','06:00','08:00','10:00','12:00','14:00','16:00','18:00','20:00','22:00','00:00'];
// Totais (Mantidos)
$total_daily_hectares = array_sum(array_column($dados_por_operacao_diario, 'total_ha'));
$total_daily_goal = array_sum(array_column($metas_por_operacao, 'meta_diaria'));
$total_daily_entries = array_sum(array_column($dados_por_operacao_diario, 'total_entries'));
$total_monthly_hectares = array_sum(array_column($dados_por_operacao_mensal, 'total_ha'));
$total_monthly_goal = array_sum(array_column($metas_por_operacao, 'meta_mensal'));
$total_monthly_entries = array_sum(array_column($dados_por_operacao_mensal, 'total_entries'));
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
        /* CSS Original + Responsivo */
        @media (max-width: 768px) {
            .header-content { flex-direction: column; text-align: center; gap: 10px; }
            .header-info { flex-direction: column; gap: 10px; }
            .ctt-tabs .nav-tabs { flex-wrap: nowrap; overflow-x: auto; }
            .stat-card .card-content h3 { font-size: 1.5rem; }
            .mobile-stack { flex-direction: column; }
            .mobile-btn-group { display: flex; flex-direction: column; gap: 10px; }
            .mobile-menu-btn { display: block; }
            .header-info .admin-actions, .header-info .btn-outline-light:not(.mobile-visible) { display: none; }
            .mobile-menu-open .header-info .admin-actions, .mobile-menu-open .header-info .btn-outline-light { display: block; width: 100%; margin-bottom: 5px; }
        }
        .mobile-menu-btn { display: none; background: none; border: none; font-size: 1.5rem; color: #fff; }
        .table-comp th { font-size: 0.85rem; background-color: #f8f9fa; }
        .table-comp td { font-size: 0.9rem; vertical-align: middle; }
    </style>
</head>
<body>

<header class="ctt-header">
    <div class="container">
        <div class="header-content d-flex justify-content-between align-items-center">
            <div class="header-brand">
                <img src="/public/static/img/logousa.png" alt="CTT Logo" class="header-logo">
                <i class="fas fa-tractor"></i> <h1>Agro-Dash</h1> <span class="badge bg-warning text-dark">v2.4</span>
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
        <div class="alert alert-info alert-dismissible fade show"><?= $feedback_message ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="tab-content">
        
        <div class="tab-pane fade show active" id="nav-dashboard">
            <div class="row g-4">
                <div class="col-xl-3 col-md-6"><div class="ctt-card stat-card"><div class="card-icon bg-primary"><i class="fas fa-chart-bar"></i></div><div class="card-content"><h3><?= number_format($total_daily_hectares, 2, ',', '.') ?></h3><p>Ha Hoje</p></div></div></div>
                <div class="col-xl-3 col-md-6"><div class="ctt-card stat-card"><div class="card-icon bg-success"><i class="fas fa-bullseye"></i></div><div class="card-content"><h3><?= number_format($total_daily_goal, 0, ',', '.') ?></h3><p>Meta Diária</p></div></div></div>
                <div class="col-xl-3 col-md-6"><div class="ctt-card stat-card"><div class="card-icon bg-warning"><i class="fas fa-calendar"></i></div><div class="card-content"><h3><?= number_format($total_monthly_hectares, 2, ',', '.') ?></h3><p>Realizado Mensal</p></div></div></div>
                <div class="col-xl-3 col-md-6"><div class="ctt-card stat-card"><div class="card-icon bg-info"><i class="fas fa-bullseye"></i></div><div class="card-content"><h3><?= number_format($total_monthly_goal, 0, ',', '.') ?></h3><p>Meta Mensal</p></div></div></div>

                <div class="col-xl-6"><div class="ctt-card"><div class="card-header"><h5><i class="fas fa-chart-line"></i> Progresso Diário</h5></div><div class="card-body"><?php $dp = ($total_daily_goal > 0) ? min(100, ($total_daily_hectares / $total_daily_goal) * 100) : 0; ?><div class="progress mb-3" style="height: 25px;"><div class="progress-bar bg-success progress-bar-striped progress-bar-animated" style="width: <?= $dp ?>%"><?= number_format($dp, 1, ',', '.') ?>%</div></div></div></div></div>
                <div class="col-xl-6"><div class="ctt-card"><div class="card-header"><h5><i class="fas fa-chart-line"></i> Progresso Mensal</h5></div><div class="card-body"><?php $mp = ($total_monthly_goal > 0) ? min(100, ($total_monthly_hectares / $total_monthly_goal) * 100) : 0; ?><div class="progress mb-3" style="height: 25px;"><div class="progress-bar bg-info progress-bar-striped progress-bar-animated" style="width: <?= $mp ?>%"><?= number_format($mp, 1, ',', '.') ?>%</div></div></div></div></div>

                <div class="col-xl-8">
                    <div class="ctt-card">
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

                <div class="col-xl-4">
                    <div class="ctt-card"><div class="card-header"><h5><i class="fas fa-history"></i> Hoje</h5></div><div class="card-body"><div class="date-filter mb-3"><input type="date" id="filter-date" class="form-control" value="<?= date('Y-m-d') ?>"></div><div class="alert alert-info py-2"><small>Edição permitida apenas hoje.</small></div><div id="no-entries-message" style="display:none;text-align:center;">Nenhum apontamento.</div><ul id="recent-entries-list" class="list-group list-group-flush"></ul></div></div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="nav-metas">
            <div class="row g-4">
                <?php foreach ($tipos_operacao as $operacao): 
                    $op_id = (int)$operacao['id']; 
                    $meta_d = (float)($metas_por_operacao[$op_id]['meta_diaria'] ?? 0);
                    $real_d = (float)($dados_por_operacao_diario[$op_id]['total_ha'] ?? 0);
                    $prog_d = ($meta_d > 0) ? min(100, ($real_d / $meta_d) * 100) : 0;
                ?>
                <div class="col-xl-6"><div class="ctt-card"><div class="card-header"><h6><?= htmlspecialchars($operacao['nome']) ?></h6></div><div class="card-body">Progresso: <?= number_format($prog_d, 1) ?>% <div class="progress"><div class="progress-bar bg-success" style="width: <?= $prog_d ?>%"></div></div></div></div></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="tab-pane fade" id="nav-comp">
            <div class="alert alert-warning mb-3"><h6>🔍 Debug</h6><small><?= $debug_msg ?></small></div>
            <div class="row g-4">
                <div class="col-12">
                    <div class="ctt-card">
                        <div class="card-header"><h5><i class="fas fa-tractor"></i> Por Equipamento (Hoje: <?= date('d/m') ?>)</h5></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped text-center mb-0 table-comp">
                                    <thead class="table-light"><tr><th>Equipamento</th><th>Manual</th><th>SGPA</th><th>Diff</th><th>Status</th></tr></thead>
                                    <tbody>
                                        <?php if(empty($detalhes_equipamento)): ?>
                                            <tr><td colspan="5" class="text-muted">Nenhum dado encontrado hoje para esta operação/unidade.</td></tr>
                                        <?php else: ?>
                                            <?php foreach($detalhes_equipamento as $d): ?>
                                            <tr>
                                                <td class="fw-bold"><?= $d['equip'] ?></td>
                                                <td class="text-primary"><?= number_format($d['manual'], 2, ',', '.') ?></td>
                                                <td class="text-success"><?= number_format($d['auto'], 2, ',', '.') ?></td>
                                                <td><?= number_format(abs($d['diff']), 2, ',', '.') ?></td>
                                                <td><?= (abs($d['diff'])<0.5)?'<span class="badge bg-success">Ok</span>':'<span class="badge bg-warning text-dark">Atenção</span>' ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="ctt-card">
                        <div class="card-header"><h5>Resumo Semanal (Unidade)</h5></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped text-center mb-0 table-comp">
                                    <thead class="table-dark"><tr><th>Data</th><th>Manual</th><th>SGPA</th><th>Diferença</th><th>Status</th></tr></thead>
                                    <tbody>
                                        <?php foreach(array_reverse($comparison_data) as $row): ?>
                                        <tr>
                                            <td><?= $row['dia_mes'] ?></td>
                                            <td class="text-primary fw-bold"><?= number_format($row['ha_manual'], 2, ',', '.') ?> ha</td>
                                            <td class="text-success fw-bold"><?= number_format($row['ha_solinftec'], 2, ',', '.') ?> ha</td>
                                            <td><?= number_format(abs($row['diff']), 2, ',', '.') ?></td>
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
    </div>
</div>

<div class="modal fade" id="editEntryModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Editar</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="editEntryForm"><input type="hidden" id="edit_entry_id" name="entry_id"><div class="mb-3"><label>Hectares</label><input type="number" step="0.01" id="edit_hectares" name="hectares" class="form-control" required></div><button type="button" class="btn btn-primary" id="saveEditBtn">Salvar</button></form></div></div></div></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    $('.select-search').select2({ width: '100%' });
    setTimeout(() => $('.alert-auto-hide').alert('close'), 10000);
    $('#mobileMenuBtn').click(() => { $('#headerInfo').toggleClass('mobile-visible'); $('body').toggleClass('mobile-menu-open'); });

    function updateClock() { $('#current-time span').text(new Date().toLocaleTimeString('pt-BR')); }
    setInterval(updateClock, 1000);

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

    function loadRecent(date) {
        const uid = <?= json_encode($_SESSION['usuario_id']) ?>;
        $('#recent-list').html('Carregando...');
        $.get(window.location.href, { date: date, usuario_id: uid }, function(res) {
            let html = '<ul class="list-group list-group-flush">';
            if(Array.isArray(res) && res.length) {
                res.forEach(e => {
                    html += `<li class="list-group-item d-flex justify-content-between">
                        <div><strong>${e.equipamento_nome||'Eq'}</strong><br><small>${e.operacao_nome}</small></div>
                        <span class="badge bg-primary">${e.hectares} ha</span>
                    </li>`;
                });
            } else { html += '<li class="list-group-item text-center text-muted">Nada hoje.</li>'; }
            $('#recent-list').html(html);
        }, 'json');
    }
    $('#filter-date').change(function() { loadRecent($(this).val()); });
    loadRecent($('#filter-date').val());

    window.openEditModal = function(e) {
        $('#edit_entry_id').val(e.id);
        $('#edit_hectares').val(e.hectares);
        new bootstrap.Modal('#editEntryModal').show();
    };
    $('#saveEditBtn').click(function() {
        $.post(window.location.href, new FormData($('#editEntryForm')[0]), function(res) {
            alert(res.message); if(res.success) location.reload();
        }, 'json');
    });
});
</script>
</body>
</html>