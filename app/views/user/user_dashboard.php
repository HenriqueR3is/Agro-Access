<?php
// user_dashboard.php - CORREÇÃO DEFINITIVA: SEPARAÇÃO DE COLUNAS (HECTARES vs VIAGENS)
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

// --- DEFINIÇÃO DOS HORÁRIOS ---
$report_hours_default = ['02:00','04:00','06:00','08:00','10:00','12:00','14:00','16:00','18:00','20:00','22:00','00:00'];
$report_hours_cacamba = ['06:00','14:00','22:00'];

// =================================================================================
// 🆕 PROCESSAMENTO DO FEEDBACK
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_feedback'])) {
    try {
        $tipo = $_POST['tipo_feedback'];
        $msg = trim($_POST['mensagem_feedback']);
        $anonimo = isset($_POST['anonimo']);
        $pagina = 'user_dashboard (Operador)';
        $uid = $anonimo ? null : $_SESSION['usuario_id'];

        if (!empty($msg)) {
            $stmt = $pdo->prepare("INSERT INTO feedbacks (usuario_id, tipo, mensagem, pagina_origem) VALUES (?, ?, ?, ?)");
            $stmt->execute([$uid, $tipo, $msg, $pagina]);
            $_SESSION['feedback_message'] = "Obrigado! Seu feedback foi enviado com sucesso.";
        }
        header("Location: /user_dashboard"); exit;
    } catch (Exception $e) { $_SESSION['feedback_message'] = "Erro: " . $e->getMessage(); }
}

// =================================================================================
// PROCESSAMENTO AJAX (EDITAR) - CORRIGIDO PARA USAR COLUNA VIAGENS
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entry_id'])) {
    header('Content-Type: application/json');
    try {
        $entry_id = (int)$_POST['entry_id'];
        $valor_input = (float)$_POST['hectares']; // O name do input é 'hectares', mas pode ser viagem
        $hora_selecionada = $_POST['hora_selecionada'];
        $observacoes = $_POST['observacoes'] ?? '';
        
        // Busca info do apontamento E O NOME DA OPERAÇÃO para decidir a coluna
        $stmt_check = $pdo->prepare("
            SELECT a.usuario_id, DATE(a.data_hora) as data_apontamento, t.nome as nome_operacao
            FROM apontamentos a
            LEFT JOIN tipos_operacao t ON a.operacao_id = t.id
            WHERE a.id = ?
        ");
        $stmt_check->execute([$entry_id]);
        $apontamento = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        $hoje = date('Y-m-d');
        
        if (!$apontamento || $apontamento['usuario_id'] != $_SESSION['usuario_id']) {
            echo json_encode(['success' => false, 'message' => 'Acesso negado']); exit;
        }
        
        if ($apontamento['data_apontamento'] != $hoje) {
            echo json_encode(['success' => false, 'message' => 'Edição permitida apenas para hoje']); exit;
        }

        // --- LÓGICA DE DECISÃO DA COLUNA ---
        $nome_op = strtoupper($apontamento['nome_operacao']);
        $is_cacamba = (strpos($nome_op, 'CAÇAMBA') !== false || strpos($nome_op, 'CACAMBA') !== false);

        $hectares_final = 0;
        $viagens_final = 0;

        if ($is_cacamba) {
            // Validação Backend de Inteiro
            if (floor($valor_input) != $valor_input) {
                echo json_encode(['success' => false, 'message' => 'Erro: Para Caçamba, informe apenas números inteiros!']); exit;
            }
            $viagens_final = (int)$valor_input;
            $hectares_final = 0; // Garante que zera hectares se virou viagem
        } else {
            $hectares_final = $valor_input;
            $viagens_final = 0; // Garante que zera viagens se virou hectare
        }
        
        // Atualiza ambas as colunas para garantir integridade
        $stmt_update = $pdo->prepare("UPDATE apontamentos SET hectares = ?, viagens = ?, hora_selecionada = ?, observacoes = ? WHERE id = ?");
        $stmt_update->execute([$hectares_final, $viagens_final, $hora_selecionada, $observacoes, $entry_id]);
        
        echo json_encode(['success' => true, 'message' => 'Atualizado com sucesso!']); exit;
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar: ' . $e->getMessage()]); exit;
    }
}

// Endpoint Buscar (AJAX) - Traz Hectares E Viagens
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['date']) && isset($_GET['usuario_id'])) {
    try {
        $date = $_GET['date'];
        $usuario_id = (int)$_GET['usuario_id'];
        $hoje = date('Y-m-d');
        
        $stmt = $pdo->prepare("
            SELECT 
                a.id, a.hectares, a.viagens, a.hora_selecionada, a.data_hora, a.observacoes,
                e.nome as equipamento_nome, o.nome as operacao_nome, u.nome as unidade_nome,
                DATE(a.data_hora) as data_apontamento
            FROM apontamentos a
            LEFT JOIN equipamentos e ON a.equipamento_id = e.id
            LEFT JOIN tipos_operacao o ON a.operacao_id = o.id
            LEFT JOIN unidades u ON a.unidade_id = u.id
            WHERE a.usuario_id = ? AND DATE(a.data_hora) = ?
            ORDER BY a.data_hora DESC
        ");
        $stmt->execute([$usuario_id, $date]);
        $apontamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($apontamentos as &$apontamento) {
            $apontamento['pode_editar'] = ($apontamento['data_apontamento'] == $hoje);
            
            // Lógica para o JS saber qual valor exibir no histórico
            // Se tiver viagens gravadas (>0), prioriza viagens. Se não, usa hectares.
            if ($apontamento['viagens'] > 0) {
                $apontamento['valor_real'] = $apontamento['viagens'];
                $apontamento['tipo_dado'] = 'viagens';
            } else {
                $apontamento['valor_real'] = $apontamento['hectares'];
                $apontamento['tipo_dado'] = 'hectares';
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode($apontamentos); exit;
    } catch (PDOException $e) { header('Content-Type: application/json'); echo json_encode([]); exit; }
}

// =================================================================================
// PROCESSAMENTO POST (SALVAR NOVO) - CORRIGIDO PARA USAR COLUNA VIAGENS
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario_id']) && !isset($_POST['entry_id']) && !isset($_POST['acao_feedback'])) {
    try {
        $uid = (int)$_POST['usuario_id'];
        $time = $_POST['report_time'];
        $status = $_POST['status'];
        $fazenda = (int)$_POST['fazenda_id'];
        $equip = (int)$_POST['equipamento_id'];
        $oper = (int)$_POST['operacao_id'];
        
        $valor_input = ($status === 'ativo') ? (float)$_POST['hectares'] : 0;
        $obs = ($status === 'parado') ? ($_POST['observacoes'] ?? '') : '';

        // 1. Descobre se é Caçamba
        $stmt_op = $pdo->prepare("SELECT nome FROM tipos_operacao WHERE id = ?");
        $stmt_op->execute([$oper]);
        $nome_op = strtoupper($stmt_op->fetchColumn());
        $is_cacamba = (strpos($nome_op, 'CAÇAMBA') !== false || strpos($nome_op, 'CACAMBA') !== false);

        $hectares_final = 0;
        $viagens_final = 0;

        if ($status === 'ativo') {
            if ($is_cacamba) {
                // Validação Backend
                if (floor($valor_input) != $valor_input) {
                    $_SESSION['feedback_message'] = "Erro: Viagens devem ser números inteiros.";
                    header("Location: /user_dashboard"); exit;
                }
                $viagens_final = (int)$valor_input;
            } else {
                $hectares_final = $valor_input;
            }
        }

        $stmt_uni = $pdo->prepare("SELECT unidade_id FROM fazendas WHERE id = ?");
        $stmt_uni->execute([$fazenda]);
        $unidade_id = $stmt_uni->fetchColumn() ?: 0;

        $dt_local = date('Y-m-d') . ' ' . $time . ':00';
        
        // INSERT ATUALIZADO: Grava na coluna certa
        $pdo->prepare("INSERT INTO apontamentos (usuario_id, unidade_id, equipamento_id, operacao_id, hectares, viagens, data_hora, hora_selecionada, observacoes, fazenda_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$uid, $unidade_id, $equip, $oper, $hectares_final, $viagens_final, $dt_local, $time, $obs, $fazenda]);

        $_SESSION['feedback_message'] = "Apontamento salvo com sucesso!";
        header("Location: /user_dashboard"); exit;
    } catch (Exception $e) { $feedback_message = "Erro ao salvar: " . $e->getMessage(); }
}

// ... (CÓDIGO DE CARREGAMENTO DE DADOS - MANTIDO RESUMIDO POIS NÃO MUDOU A LÓGICA) ...
try {
    $usuario_id = $_SESSION['usuario_id'];
    $stmt = $pdo->prepare("SELECT u.unidade_id, u.tipo, u.nome, un.nome as nome_unidade, un.abreviacao as sigla FROM usuarios u LEFT JOIN unidades un ON u.unidade_id = un.id WHERE u.id = ?");
    $stmt->execute([$usuario_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $unidade_do_usuario = $user_data['unidade_id'] ?? null;
    $unidade_nome_real  = $user_data['nome_unidade'] ?? ''; 
    $unidade_sigla      = $user_data['sigla'] ?? '';
    $user_role = $user_data['tipo'] ?? 'operador';
    $username = $user_data['nome'] ?? 'Usuário';

    $tipos_operacao = []; $metas_por_operacao = []; $dados_por_operacao_diario = []; $dados_por_operacao_mensal = []; $fazendas = []; $allowed_ids = []; $allowed_names = [];

    if ($unidade_do_usuario) {
        // ... (Carrega tipos de operação e equipamentos igual ao anterior) ...
        $stmt_op = $pdo->prepare("SELECT t.id, t.nome FROM tipos_operacao t INNER JOIN usuario_operacao uo ON t.id = uo.operacao_id WHERE uo.usuario_id = ? ORDER BY t.nome");
        $stmt_op->execute([$usuario_id]);
        $tipos_operacao = $stmt_op->fetchAll(PDO::FETCH_ASSOC);
        if (empty($tipos_operacao)) {
            $stmt_all = $pdo->query("SELECT id, nome FROM tipos_operacao ORDER BY nome");
            $tipos_operacao = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach($tipos_operacao as $op) { $allowed_ids[] = (int)$op['id']; $allowed_names[] = $op['nome']; }
        
        $hoje_brt = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $data_hoje = $hoje_brt->format('Y-m-d'); $mes = $hoje_brt->format('m'); $ano = $hoje_brt->format('Y');
        
        if(!empty($allowed_ids)) {
            $in_clause = implode(',', array_fill(0, count($allowed_ids), '?'));
            $params = array_merge([$unidade_do_usuario], $allowed_ids);
            $stmt = $pdo->prepare("SELECT operacao_id, meta_diaria, meta_mensal FROM metas_unidade_operacao WHERE unidade_id = ? AND operacao_id IN ($in_clause)");
            $stmt->execute($params);
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $metas_por_operacao[$r['operacao_id']] = $r;

            // (Lógica SGPA mantida igual...)
            $MAPA_OPERACOES_SGPA = ['PLANTIO'=>['PLANTANDO','PLANTIO MECANIZADO'],'COLHEITA'=>['COLHENDO','COLHEITA','CORTANDO'],'ACOP'=>['ACOP','TRANSBORDO'],'VINHACA'=>['VINHAÇA','FERTIRRIGAÇÃO'],'SUBSOLAGEM'=>['SUBSOLAGEM','SUBSOLANDO']];
            $termo1 = $unidade_nome_real; $termo2 = !empty($unidade_sigla) ? $unidade_sigla : $unidade_nome_real;

            foreach ($tipos_operacao as $op) {
                $op_id = $op['id']; $nome_sistema = strtoupper($op['nome']);
                $palavras_chave = $MAPA_OPERACOES_SGPA[$nome_sistema] ?? [$op['nome']];
                $like_parts = []; $params_sgpa_base = [];
                foreach ($palavras_chave as $p) { $like_parts[] = "nome_operacao LIKE ?"; $params_sgpa_base[] = "%$p%"; }
                $sql_like = "(" . implode(" OR ", $like_parts) . ")";
                $extra_filter = (stripos($nome_sistema, 'PLANTIO') !== false) ? " AND nome_operacao NOT LIKE '%REPLANTIO%' " : "";

                $sql_dia = "SELECT SUM(hectares_sgpa) as total_ha, COUNT(*) as qtd FROM producao_sgpa WHERE data = ? AND (unidade LIKE ? OR unidade LIKE ?) AND $sql_like $extra_filter";
                $params_dia = array_merge([$data_hoje, "%$termo1%", "%$termo2%"], $params_sgpa_base);
                $stmt_dia = $pdo->prepare($sql_dia); $stmt_dia->execute($params_dia); $res_dia = $stmt_dia->fetch(PDO::FETCH_ASSOC);
                $dados_por_operacao_diario[$op_id] = ['total_ha' => (float)($res_dia['total_ha'] ?? 0), 'total_entries' => (int)($res_dia['qtd'] ?? 0)];

                $sql_mes = "SELECT SUM(hectares_sgpa) as total_ha, COUNT(*) as qtd FROM producao_sgpa WHERE MONTH(data) = ? AND YEAR(data) = ? AND (unidade LIKE ? OR unidade LIKE ?) AND $sql_like $extra_filter";
                $params_mes = array_merge([$mes, $ano, "%$termo1%", "%$termo2%"], $params_sgpa_base);
                $stmt_mes = $pdo->prepare($sql_mes); $stmt_mes->execute($params_mes); $res_mes = $stmt_mes->fetch(PDO::FETCH_ASSOC);
                $dados_por_operacao_mensal[$op_id] = ['total_ha' => (float)($res_mes['total_ha'] ?? 0), 'total_entries' => (int)($res_mes['qtd'] ?? 0)];
            }
        }
        $stmt_f = $pdo->prepare("SELECT id, nome FROM fazendas WHERE unidade_id = ? ORDER BY nome");
        $stmt_f->execute([$unidade_do_usuario]);
        $fazendas = $stmt_f->fetchAll(PDO::FETCH_ASSOC);
    }

    $comparison_data = []; $detalhes_equipamento = [];
    $MAPA_OPERACOES_SGPA = ['PLANTIO'=>['PLANTANDO', 'PLANTIO MECANIZADO'],'COLHEITA'=>['COLHENDO', 'COLHEITA', 'CORTANDO'],'ACOP'=>['ACOP', 'TRANSBORDO'],'VINHACA'=>['VINHAÇA', 'FERTIRRIGAÇÃO'],'SUBSOLAGEM'=>['SUBSOLAGEM', 'SUBSOLANDO']];

    if ($unidade_do_usuario && $unidade_nome_real) {
        $termo1 = $unidade_nome_real; $termo2 = !empty($unidade_sigla) ? $unidade_sigla : $unidade_nome_real;
        $filtro_ops_sgpa = ""; $params_ops_sgpa = []; $filtro_exclusao = "";

        if (!empty($allowed_names)) {
            $clausulas_or = [];
            foreach ($allowed_names as $nome_sistema) {
                $chave = strtoupper($nome_sistema); $palavras_chave = $MAPA_OPERACOES_SGPA[$chave] ?? [$nome_sistema];
                foreach ($palavras_chave as $palavra) { $clausulas_or[] = "nome_operacao LIKE ?"; $params_ops_sgpa[] = "%$palavra%"; }
                if (stripos($nome_sistema, 'Plantio') !== false) { $filtro_exclusao .= " AND nome_operacao NOT LIKE '%REPLANTIO%' "; }
            }
            if (!empty($clausulas_or)) $filtro_ops_sgpa = "AND (" . implode(" OR ", $clausulas_or) . ")";
        }

        // Comparativo Geral
        for ($i = 6; $i >= 0; $i--) {
            $data_alvo = date('Y-m-d', strtotime("-$i days"));
            $ha_manual = 0;
            if (!empty($allowed_ids)) {
                $in_ids = implode(',', array_fill(0, count($allowed_ids), '?'));
                $params_m = array_merge([$unidade_do_usuario, $data_alvo], $allowed_ids);
                // AJUSTE: SOMA HECTARES + VIAGENS PARA O TOTALIZADOR
                $stmt_m = $pdo->prepare("SELECT SUM(hectares + viagens) FROM apontamentos WHERE unidade_id=? AND DATE(data_hora)=? AND operacao_id IN ($in_ids)");
                $stmt_m->execute($params_m);
                $ha_manual = (float)$stmt_m->fetchColumn();
            }
            $ha_solinftec = 0;
            if ($filtro_ops_sgpa) {
                $sql_sgpa = "SELECT SUM(hectares_sgpa) FROM producao_sgpa WHERE data = ? AND (unidade LIKE ? OR unidade LIKE ?) $filtro_ops_sgpa $filtro_exclusao";
                $params_s = array_merge([$data_alvo, "%$termo1%", "%$termo2%"], $params_ops_sgpa);
                $stmt_s = $pdo->prepare($sql_sgpa); $stmt_s->execute($params_s); $ha_solinftec = (float)$stmt_s->fetchColumn();
            }
            $comparison_data[] = ['data' => $data_alvo, 'dia_mes' => date('d/m', strtotime($data_alvo)), 'ha_manual' => $ha_manual, 'ha_solinftec' => $ha_solinftec, 'diff' => $ha_manual - $ha_solinftec];
        }

        // Detalhamento
        $dados_consolidados = [];
        if (!empty($allowed_ids)) {
            $in_ids = implode(',', array_fill(0, count($allowed_ids), '?'));
            // AJUSTE: SOMA HECTARES + VIAGENS PARA O DETALHAMENTO MANUAL
            $sql_det_man = "SELECT e.nome as nome_equip, SUM(a.hectares + a.viagens) as total FROM apontamentos a JOIN equipamentos e ON a.equipamento_id = e.id WHERE a.unidade_id = ? AND DATE(a.data_hora) = ? AND a.operacao_id IN ($in_ids) GROUP BY e.nome";
            $params_det_man = array_merge([$unidade_do_usuario, $data_hoje], $allowed_ids);
            $stmt_det_m = $pdo->prepare($sql_det_man); $stmt_det_m->execute($params_det_man);
            while($r = $stmt_det_m->fetch(PDO::FETCH_ASSOC)) {
                $chave_num = preg_replace('/\D/', '', explode('-', $r['nome_equip'])[0]); 
                if (!isset($dados_consolidados[$chave_num])) $dados_consolidados[$chave_num] = ['nome' => $r['nome_equip'], 'manual' => 0, 'auto' => 0];
                $dados_consolidados[$chave_num]['manual'] += (float)$r['total'];
            }
        }
        if ($filtro_ops_sgpa) {
            $sql_det_sgpa = "SELECT nome_equipamento, SUM(hectares_sgpa) as total FROM producao_sgpa WHERE data = ? AND (unidade LIKE ? OR unidade LIKE ?) $filtro_ops_sgpa $filtro_exclusao GROUP BY nome_equipamento";
            $params_det_sgpa = array_merge([$data_hoje, "%$termo1%", "%$termo2%"], $params_ops_sgpa);
            $stmt_det_s = $pdo->prepare($sql_det_sgpa); $stmt_det_s->execute($params_det_sgpa);
            while($r = $stmt_det_s->fetch(PDO::FETCH_ASSOC)) {
                $chave_num = preg_replace('/\D/', '', explode('-', $r['nome_equipamento'])[0]);
                if (!isset($dados_consolidados[$chave_num])) { $dados_consolidados[$chave_num] = ['nome' => $r['nome_equipamento'], 'manual' => 0, 'auto' => 0]; }
                else { if (strlen($r['nome_equipamento']) > strlen($dados_consolidados[$chave_num]['nome'])) $dados_consolidados[$chave_num]['nome'] = $r['nome_equipamento']; }
                $dados_consolidados[$chave_num]['auto'] += (float)$r['total'];
            }
        }
        foreach ($dados_consolidados as $id_num => $dados) {
            $detalhes_equipamento[] = ['equip' => $dados['nome'], 'manual' => $dados['manual'], 'auto' => $dados['auto'], 'diff' => $dados['manual'] - $dados['auto']];
        }
        usort($detalhes_equipamento, function($a, $b) { return strcmp($a['equip'], $b['equip']); });
    }
} catch (Exception $e) { $feedback_message = "Erro no sistema: " . $e->getMessage(); }

if (!isset($dados_por_operacao_diario)) $dados_por_operacao_diario = [];
if (!isset($metas_por_operacao)) $metas_por_operacao = [];
if (!isset($dados_por_operacao_mensal)) $dados_por_operacao_mensal = [];
$total_daily_hectares = array_sum(array_column($dados_por_operacao_diario, 'total_ha'));
$total_daily_goal = array_sum(array_column($metas_por_operacao, 'meta_diaria'));
$total_monthly_hectares = array_sum(array_column($dados_por_operacao_mensal, 'total_ha'));
$total_monthly_goal = array_sum(array_column($metas_por_operacao, 'meta_mensal'));
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
            .header-info { display: none; width: 100%; justify-content: center; flex-wrap: wrap; gap: 10px; }
            .header-info.mobile-visible { display: flex; }
        }
        .mobile-menu-btn { display: none; background: none; border: none; font-size: 1.5rem; color: #fff; }
        .table-comp th { font-size: 0.85rem; background-color: #f8f9fa; }
        .table-comp td { font-size: 0.9rem; vertical-align: middle; }
        .status-list { list-style: none; padding: 0; margin: 0; max-height: 248px; overflow-y: auto; padding-right: 5px; }
        .status-list::-webkit-scrollbar { width: 6px; }
        .status-list::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .status-list::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
        .entry-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #eee; }
        .entry-item:last-child { border-bottom: none; }
        .entry-details { display: flex; flex-direction: column; }
        .status-indicator { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 8px; }
        .edit-btn { background: none; border: none; color: #6c757d; cursor: pointer; transition: color 0.2s; }
        .edit-btn:hover { color: #0d6efd; }
        .edit-btn.disabled { color: #dee2e6; cursor: not-allowed; }
        .icon-box { width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; }
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
                <i class="fas fa-tractor"></i> <h1>Agro-Dash</h1> <span class="badge bg-warning text-dark">v3.5</span>
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

<nav class="ctt-tabs">
    <div class="container">
        <div class="nav nav-tabs" id="nav-tab" role="tablist">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#nav-dashboard"><i class="fas fa-chart-line"></i> <span class="d-none d-sm-inline">Dash</span></button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#nav-metas"><i class="fas fa-bullseye"></i> <span class="d-none d-sm-inline">Metas</span></button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#nav-comp"><i class="fas fa-balance-scale"></i> <span class="d-none d-sm-inline">Comparativo</span></button>
            <button class="nav-link text-warning ms-auto" data-bs-toggle="modal" data-bs-target="#modalFeedback"><i class="fas fa-bullhorn"></i> <span class="d-none d-sm-inline">Feedback</span></button>
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
                <div class="col-6 col-md-3"><div class="ctt-card card-compact h-100 border-start border-4 border-primary"><div class="card-body p-3 d-flex align-items-center justify-content-between"><div><span class="text-muted small text-uppercase fw-bold">Ha Hoje</span><h4 class="mb-0 fw-bold text-dark"><?= number_format($total_daily_hectares, 2, ',', '.') ?></h4></div><div class="icon-box bg-primary-subtle text-primary rounded-circle"><i class="fas fa-tractor fa-lg"></i></div></div></div></div>
                <div class="col-6 col-md-3"><div class="ctt-card card-compact h-100 border-start border-4 border-success"><div class="card-body p-3 d-flex align-items-center justify-content-between"><div><span class="text-muted small text-uppercase fw-bold">Meta Dia</span><h4 class="mb-0 fw-bold text-dark"><?= number_format($total_daily_goal, 0, ',', '.') ?></h4></div><div class="icon-box bg-success-subtle text-success rounded-circle"><i class="fas fa-bullseye fa-lg"></i></div></div></div></div>
                <div class="col-6 col-md-3"><div class="ctt-card card-compact h-100 border-start border-4 border-warning"><div class="card-body p-3 d-flex align-items-center justify-content-between"><div><span class="text-muted small text-uppercase fw-bold">Mês Real</span><h4 class="mb-0 fw-bold text-dark"><?= number_format($total_monthly_hectares, 0, ',', '.') ?></h4></div><div class="icon-box bg-warning-subtle text-warning rounded-circle"><i class="fas fa-calendar fa-lg"></i></div></div></div></div>
                <div class="col-6 col-md-3"><div class="ctt-card card-compact h-100 border-start border-4 border-info"><div class="card-body p-3 d-flex align-items-center justify-content-between"><div><span class="text-muted small text-uppercase fw-bold">Mês Meta</span><h4 class="mb-0 fw-bold text-dark"><?= number_format($total_monthly_goal, 0, ',', '.') ?></h4></div><div class="icon-box bg-info-subtle text-info rounded-circle"><i class="fas fa-flag-checkered fa-lg"></i></div></div></div></div>
            </div>

            <div class="row g-4">
                <div class="col-12 col-md-6"><div class="ctt-card"><div class="card-header"><h5><i class="fas fa-chart-line"></i> Progresso Diário</h5></div><div class="card-body"><?php $dp = ($total_daily_goal > 0) ? min(100, ($total_daily_hectares / $total_daily_goal) * 100) : 0; ?><div class="progress mb-3" style="height: 25px;"><div class="progress-bar bg-success progress-bar-striped progress-bar-animated" style="width: <?= $dp ?>%"><?= number_format($dp, 1, ',', '.') ?>%</div></div><div class="d-flex justify-content-between text-muted small"><div class="text-center"><span class="d-block fw-bold text-dark">Real</span><?= number_format($total_daily_hectares, 2, ',', '.') ?></div><div class="text-center"><span class="d-block fw-bold text-dark">Meta</span><?= number_format($total_daily_goal, 0, ',', '.') ?></div></div></div></div></div>
                <div class="col-12 col-md-6"><div class="ctt-card"><div class="card-header"><h5><i class="fas fa-chart-line"></i> Progresso Mensal</h5></div><div class="card-body"><?php $mp = ($total_monthly_goal > 0) ? min(100, ($total_monthly_hectares / $total_monthly_goal) * 100) : 0; ?><div class="progress mb-3" style="height: 25px;"><div class="progress-bar bg-info progress-bar-striped progress-bar-animated" style="width: <?= $mp ?>%"><?= number_format($mp, 1, ',', '.') ?>%</div></div><div class="d-flex justify-content-between text-muted small"><div class="text-center"><span class="d-block fw-bold text-dark">Real</span><?= number_format($total_monthly_hectares, 2, ',', '.') ?></div><div class="text-center"><span class="d-block fw-bold text-dark">Meta</span><?= number_format($total_monthly_goal, 0, ',', '.') ?></div></div></div></div></div>

                <div class="col-12 col-lg-8">
                    <div class="ctt-card h-100">
                        <div class="card-header"><h5><i class="fas fa-plus-circle"></i> Novo Apontamento</h5></div>
                        <div class="card-body">
                            <form method="POST" id="entryForm">
                                <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                                <div class="row g-3">
                                    <div class="col-md-6"><label>Fazenda</label><select name="fazenda_id" class="form-select select-search" required><option value="">Selecione...</option><?php foreach ($fazendas as $f): ?><option value="<?= $f['id'] ?>"><?= $f['nome'] ?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-6"><label>Hora / Turno</label><select name="report_time" id="report_time" class="form-select"><?php foreach ($report_hours_default as $h): ?><option value="<?= $h ?>"><?= $h ?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-6"><label>Status</label><select id="status" name="status" class="form-select"><option value="ativo">Ativo</option><option value="parado">Parado</option></select></div>
                                    <div class="col-md-6"><label>Operação</label><select id="operation" name="operacao_id" class="form-select select-search" required><option value="">Selecione...</option><?php foreach ($tipos_operacao as $op): ?><option value="<?= (int)$op['id'] ?>"><?= htmlspecialchars($op['nome']) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-6"><label>Equipamento</label><select id="equipment" name="equipamento_id" class="form-select select-search" required disabled><option value="">Selecione a operação...</option></select></div>
                                    <div class="col-md-6" id="hectares-group"><label id="label-hectares">Hectares</label><input type="number" step="0.01" id="hectares" name="hectares" class="form-control" required></div>
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
            </div>
        </div>
        
        <div class="tab-pane fade" id="nav-metas">
            <div class="row g-4">
                <?php foreach ($tipos_operacao as $operacao): 
                    $op_id = (int)$operacao['id']; $op_nome = $operacao['nome'];
                    $meta_d = (float)($metas_por_operacao[$op_id]['meta_diaria'] ?? 0); $meta_m = (float)($metas_por_operacao[$op_id]['meta_mensal'] ?? 0);
                    $real_d = (float)($dados_por_operacao_diario[$op_id]['total_ha'] ?? 0); $real_m = (float)($dados_por_operacao_mensal[$op_id]['total_ha'] ?? 0);
                    $falta_d = max(0, $meta_d - $real_d); $falta_m = max(0, $meta_m - $real_m);
                    $prog_d = ($meta_d > 0) ? min(100, ($real_d / $meta_d) * 100) : 0; $prog_m = ($meta_m > 0) ? min(100, ($real_m / $meta_m) * 100) : 0;
                ?>
                <div class="col-12 col-md-6 col-xl-4"><div class="ctt-card h-100"><div class="card-header"><h6><i class="fas fa-tractor me-2"></i> <?= htmlspecialchars($op_nome) ?></h6></div><div class="card-body"><div class="mb-4"><div class="d-flex justify-content-between align-items-center mb-1"><h6 class="mb-0 text-muted"><i class="fas fa-sun text-warning me-1"></i> Diário</h6><span class="badge bg-<?= $prog_d >= 100 ? 'success' : ($prog_d >= 80 ? 'warning' : 'danger') ?>"><?= number_format($prog_d, 1) ?>%</span></div><div class="progress mb-2" style="height: 10px;"><div class="progress-bar bg-success" style="width: <?= $prog_d ?>%"></div></div><div class="d-flex justify-content-between text-center small bg-light p-2 rounded"><div><span class="text-muted d-block" style="font-size:0.75rem">Meta</span><strong><?= number_format($meta_d, 2, ',', '.') ?></strong></div><div><span class="text-muted d-block" style="font-size:0.75rem">Real</span><strong class="text-success"><?= number_format($real_d, 2, ',', '.') ?></strong></div><div><span class="text-muted d-block" style="font-size:0.75rem">Falta</span><strong class="text-warning"><?= number_format($falta_d, 2, ',', '.') ?></strong></div></div></div><div><div class="d-flex justify-content-between align-items-center mb-1"><h6 class="mb-0 text-muted"><i class="fas fa-calendar text-info me-1"></i> Mensal</h6><span class="badge bg-<?= $prog_m >= 100 ? 'success' : ($prog_m >= 80 ? 'warning' : 'danger') ?>"><?= number_format($prog_m, 1) ?>%</span></div><div class="progress mb-2" style="height: 10px;"><div class="progress-bar bg-info" style="width: <?= $prog_m ?>%"></div></div><div class="d-flex justify-content-between text-center small bg-light p-2 rounded"><div><span class="text-muted d-block" style="font-size:0.75rem">Meta</span><strong><?= number_format($meta_m, 2, ',', '.') ?></strong></div><div><span class="text-muted d-block" style="font-size:0.75rem">Real</span><strong class="text-success"><?= number_format($real_m, 2, ',', '.') ?></strong></div><div><span class="text-muted d-block" style="font-size:0.75rem">Falta</span><strong class="text-warning"><?= number_format($falta_m, 2, ',', '.') ?></strong></div></div></div></div></div></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="tab-pane fade" id="nav-comp">
            <div class="row g-4">
                <div class="col-12"><div class="ctt-card"><div class="card-header"><h5><i class="fas fa-tractor"></i> Por Equipamento (Hoje)</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-bordered table-striped text-center mb-0 table-comp"><thead class="table-light"><tr><th>Equipamento</th><th>Manual</th><th>SGPA</th><th>Diferença</th><th>Status</th></tr></thead><tbody><?php if(empty($detalhes_equipamento)): ?><tr><td colspan="5" class="text-muted p-3">Sem dados.</td></tr><?php else: foreach($detalhes_equipamento as $d): ?><tr><td class="text-start ps-3 fw-bold"><?= $d['equip'] ?></td><td><?= number_format($d['manual'],2,',','.') ?></td><td><?= number_format($d['auto'],2,',','.') ?></td><td><?= number_format(abs($d['diff']),2,',','.') ?></td><td><?= (abs($d['diff'])<0.5)?'<span class="badge bg-success">Ok</span>':'<span class="badge bg-warning text-dark">Atenção!</span>' ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div></div></div>
                <div class="col-12"><div class="ctt-card"><div class="card-header"><h5>Resumo Semanal</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-bordered table-striped text-center mb-0 table-comp"><thead class="table-dark"><tr><th>Data</th><th>Manual</th><th>SGPA</th><th>Diferença</th><th>Status</th></tr></thead><tbody><?php foreach(array_reverse($comparison_data) as $row): ?><tr><td><?= $row['dia_mes'] ?></td><td><?= number_format($row['ha_manual'],2,',','.') ?></td><td><?= number_format($row['ha_solinftec'],2,',','.') ?></td><td><?= number_format(abs($row['diff']),2,',','.') ?></td><td><?php if(abs($row['diff']) < 0.5) echo '<span class="badge bg-success">Ok</span>'; else echo '<span class="badge bg-danger">Dif</span>'; ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editEntryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form id="editEntryForm">
                    <input type="hidden" id="edit_entry_id" name="entry_id">
                    <div class="mb-3"><label>Equipamento</label><input type="text" class="form-control" id="edit_equipamento" readonly></div>
                    <div class="mb-3"><label>Operação</label><input type="text" class="form-control" id="edit_operacao" readonly></div>
                    <div class="mb-3"><label id="edit_label_hectares">Hectares</label><input type="number" step="0.01" class="form-control" id="edit_hectares" name="hectares" required></div>
                    <div class="mb-3"><label>Horário</label><select class="form-select" id="edit_hora" name="hora_selecionada" required></select></div>
                    <div class="mb-3"><label>Observações</label><textarea class="form-control" id="edit_observacoes" name="observacoes" rows="3"></textarea></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="saveEditBtn">Salvar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalFeedback" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content"><form method="POST"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Feedback</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="acao_feedback" value="1"><select name="tipo_feedback" class="form-select mb-3"><option value="sugestao">Sugestão</option><option value="bug">Erro</option></select><textarea name="mensagem_feedback" class="form-control mb-3" rows="4" required></textarea><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="anonimo" id="anon"><label class="form-check-label" for="anon">Anônimo</label></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Enviar</button></div></form></div></div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const HOURS_DEFAULT = <?= json_encode($report_hours_default) ?>;
const HOURS_CACAMBA = <?= json_encode($report_hours_cacamba) ?>;

$(document).ready(function() {
    $('.select-search').select2({ width: '100%' });
    setTimeout(() => $('.alert-auto-hide').alert('close'), 10000);
    $('#mobileMenuBtn').click(() => { $('#headerInfo').toggleClass('mobile-visible'); $('body').toggleClass('mobile-menu-open'); });

    $('#operation').change(function() {
        const opId = $(this).val();
        if (!opId) {
            const $timeSelect = $('#report_time'); $timeSelect.empty();
            HOURS_DEFAULT.forEach(h => $timeSelect.append(new Option(h, h)));
            return;
        }
        const opNome = $(this).find(':selected').text().toUpperCase(); 
        const isCacamba = opNome.includes('CAÇAMBA') || opNome.includes('CACAMBA');
        const targetHours = isCacamba ? HOURS_CACAMBA : HOURS_DEFAULT;
        
        const $timeSelect = $('#report_time'); $timeSelect.empty();
        targetHours.forEach(h => $timeSelect.append(new Option(h, h)));

        const $inputHectares = $('#hectares');
        if (isCacamba) {
            $('#label-hectares').text('Viagens');
            $inputHectares.attr('step', '1').attr('placeholder', 'Ex: 5');
        } else {
            $('#label-hectares').text('Hectares');
            $inputHectares.attr('step', '0.01').attr('placeholder', 'Ex: 10.50');
        }

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

    function showAlert(msg, type) { 
        const html = `<div class="alert alert-${type} alert-dismissible fade show">${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
        $('#js-alert-container').html(html);
        setTimeout(() => $('#js-alert-container').html(''), 5000);
    }

    function loadRecentEntries(date) { 
        const userId = <?= json_encode($_SESSION['usuario_id']) ?>;
        const list = $('#recent-entries-list'); const noEntries = $('#no-entries-message');
        list.html('<div class="text-center p-3">Carregando...</div>'); noEntries.hide();
        $.ajax({ url: window.location.href, type: 'GET', data: { date: date, usuario_id: userId }, dataType: 'json' })
        .done(function(resp) {
            list.empty();
            if(Array.isArray(resp) && resp.length) {
                resp.forEach(ent => {
                    const labelQtd = (ent.tipo_dado === 'viagens') ? 'Viagens' : 'ha';
                    // Se for viagens, mostra inteiro (parseInt), se for hectare, mostra decimal (toFixed)
                    const valFormatado = (ent.tipo_dado === 'viagens') ? parseInt(ent.valor_real) : parseFloat(ent.valor_real).toFixed(2);
                    
                    const item = $('<li class="entry-item">');
                    const dets = $('<div class="entry-details">').append(
                        $('<div>').append($('<span class="status-indicator">').css('background-color', ent.valor_real>0?'#28a745':'#dc3545'), $('<strong>').text(ent.equipamento_nome)),
                        $('<small class="text-muted">').text((ent.unidade_nome||'')+' - '+(ent.operacao_nome||'')),
                        $('<br>'),
                        $('<small>').html(ent.valor_real>0 ? `<span class="text-success">${valFormatado} ${labelQtd} | Hora: ${ent.hora_selecionada.slice(0,5)}</span>` : `<span class="text-danger">Parado | Hora: ${ent.hora_selecionada.slice(0,5)}</span>`)
                    );
                    const acts = $('<div class="entry-actions">');
                    const btn = $('<button class="edit-btn"><i class="fas fa-edit"></i></button>');
                    if(ent.pode_editar) btn.click(()=>openEditModal(ent)); else btn.addClass('disabled');
                    acts.append(btn); item.append(dets, acts); list.append(item);
                });
            } else noEntries.show();
        });
    }

    function openEditModal(entry) {
        $('#edit_entry_id').val(entry.id);
        $('#edit_equipamento').val(entry.equipamento_nome || '');
        $('#edit_operacao').val(entry.operacao_nome || '');
        const opNome = (entry.operacao_nome || '').toUpperCase();
        const isCacamba = opNome.includes('CAÇAMBA') || opNome.includes('CACAMBA');
        const targetHours = isCacamba ? HOURS_CACAMBA : HOURS_DEFAULT;
        
        const $editSelect = $('#edit_hora'); $editSelect.empty();
        targetHours.forEach(h => $editSelect.append(new Option(h, h)));
        let hora = entry.hora_selecionada ? entry.hora_selecionada.substring(0,5) : '08:00';
        if (!$editSelect.find(`option[value="${hora}"]`).length) $editSelect.append(new Option(hora, hora));
        $editSelect.val(hora);

        const $inputEdit = $('#edit_hectares');
        if (isCacamba) {
            $('#edit_label_hectares').text('Viagens');
            $inputEdit.attr('step', '1'); 
            // Carrega VIAGENS se tiver, senão hectares
            $inputEdit.val(parseInt(entry.viagens > 0 ? entry.viagens : entry.hectares));
        } else {
            $('#edit_label_hectares').text('Hectares');
            $inputEdit.attr('step', '0.01');
            $inputEdit.val(entry.hectares);
        }
        $('#edit_observacoes').val(entry.observacoes || '');
        new bootstrap.Modal(document.getElementById('editEntryModal')).show();
    }

    $('#saveEditBtn').click(function() {
        // Validação JS Extra para garantir
        const val = $('#edit_hectares').val();
        const isViagem = $('#edit_label_hectares').text().includes('Viagens');
        if (isViagem && val % 1 !== 0) {
            alert("Erro: Viagens devem ser números inteiros!");
            return;
        }

        const formData = new FormData($('#editEntryForm')[0]);
        const btn = $(this); const txt = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Salvando...');
        $.ajax({ url: window.location.href, type: 'POST', data: formData, processData: false, contentType: false, dataType: 'json' })
        .done(res => { if(res.success) { bootstrap.Modal.getInstance(document.getElementById('editEntryModal')).hide(); loadRecentEntries($('#filter-date').val()); showAlert(res.message, 'success'); setTimeout(() => location.reload(), 1000); } else showAlert(res.message, 'danger'); })
        .fail(() => showAlert('Erro de comunicação.', 'danger'))
        .always(() => btn.prop('disabled', false).html(txt));
    });

    $('#filter-date').change(function() { loadRecentEntries($(this).val()); });
    loadRecentEntries($('#filter-date').val());
});
</script>
</body>
</html>