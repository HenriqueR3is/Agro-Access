<?php

date_default_timezone_set('America/Sao_Paulo');

session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

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
        $feedback_message = "Usuário não está associado a uma unidade.";
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
        $allowed_operations_ids = array_map(fn($r) => (int)$r['id'], $tipos_operacao);

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario_id'])) {
    try {
        $usuario_id     = (int)$_POST['usuario_id'];
        $report_time    = $_POST['report_time'];          // "HH:MM"
        $status         = $_POST['status'];               // ativo | parado
        $fazenda_id     = (int)$_POST['fazenda_id'];
        $equipamento_id = (int)$_POST['equipamento_id'];
        $operacao_id    = (int)$_POST['operacao_id'];
        $hectares_input = isset($_POST['hectares']) ? (float)str_replace(',', '.', $_POST['hectares']) : 0;
        $viagens_input  = isset($_POST['viagens'])  ? (int)$_POST['viagens'] : 0; // vem do hidden (ver JS/HTML abaixo)
        $observacoes    = ($status === 'parado') ? ($_POST['observacoes'] ?? '') : '';

        // Descobre se a operação é "Caçamba" pelo NOME vindo do BD
        $stmtOp = $pdo->prepare("SELECT nome FROM tipos_operacao WHERE id = ?");
        $stmtOp->execute([$operacao_id]);
        $opNome = (string)$stmtOp->fetchColumn();

        // normaliza para comparar (sem acento/caixa)
        $norm = function($s){
            $s = mb_strtolower($s, 'UTF-8');
            $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
            return $s;
        };
        $isCacamba = str_contains($norm($opNome), 'cacamba'); // cobre "caçamba" e "cacamba"

        // Unidade da fazenda selecionada (garante consistência)
        $stmt_unidade = $pdo->prepare("SELECT unidade_id FROM fazendas WHERE id = ?");
        $stmt_unidade->execute([$fazenda_id]);
        $fazenda_data = $stmt_unidade->fetch(PDO::FETCH_ASSOC);
        $unidade_id   = $fazenda_data['unidade_id'] ?? $unidade_do_usuario;

        // Data de hoje em BRT + hora selecionada
        $hoje_brt = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $data_hora_local = (new DateTime($hoje_brt->format('Y-m-d') . ' ' . $report_time . ':00', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');

        // define hectares/viagens de acordo com operação + status
        $hectares = ($status === 'ativo' && !$isCacamba) ? $hectares_input : 0;
        $viagens  = ($status === 'ativo' &&  $isCacamba) ? max(0, $viagens_input) : 0;

        $stmt = $pdo->prepare("
            INSERT INTO apontamentos
            (usuario_id, unidade_id, equipamento_id, operacao_id, hectares, viagens, data_hora, hora_selecionada, observacoes, fazenda_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $usuario_id, $unidade_id, $equipamento_id, $operacao_id,
            $hectares, $viagens, $data_hora_local, $report_time, $observacoes, $fazenda_id
        ]);

        $_SESSION['feedback_message'] = 'Apontamento salvo com sucesso para às ' . substr($report_time, 0, 5) . '!';
        header('Location: /user_dashboard');
        exit;

    } catch (PDOException $e) {
        $feedback_message = "Erro ao salvar apontamento: " . $e->getMessage();
    }
}

// Totais para os cards (somatórios por operação)
$total_daily_hectares   = array_sum(array_map(fn($r)=>$r['total_ha']      ?? 0, $dados_por_operacao_diario));
$total_daily_goal       = array_sum(array_map(fn($r)=>$r['meta_diaria']   ?? 0, $metas_por_operacao));
$total_daily_entries    = array_sum(array_map(fn($r)=>$r['total_entries'] ?? 0, $dados_por_operacao_diario));

$total_monthly_hectares = array_sum(array_map(fn($r)=>$r['total_ha']      ?? 0, $dados_por_operacao_mensal));
$total_monthly_goal     = array_sum(array_map(fn($r)=>$r['meta_mensal']   ?? 0, $metas_por_operacao));
$total_monthly_entries  = array_sum(array_map(fn($r)=>$r['total_entries'] ?? 0, $dados_por_operacao_mensal));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars($user_role); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="icon" type="image/x-icon" href="/public/static/favicon.ico">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link rel="stylesheet" href="/public/static/css/styleDash.css">
    <link rel="stylesheet" href="/public/static/css/user_dashboard.css">
</head>

<body class="dashboard-body">
<header class="main-header">
    <div class="header-content">
        <div class="logo">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M17.59 13.41a6 6 0 0 0-8.49-8.49L3 11v8h8l6.59-6.59zM19 12l-7-7l-2 2l7 7l2-2z"/></svg>
            <span>Agro-Dash</span>
        </div>
        <div class="user-info">
            <span>Olá, <strong><?php echo htmlspecialchars($username); ?></strong>! (<?php echo htmlspecialchars($user_role); ?>)</span>
            <?php if ($user_role === 'admin' || $user_role === 'cia_dev'): ?>
                <a href="/metas" class="admin-btn">Metas</a>
                <a href="/dashboard" class="admin-btn">Back</a>
            <?php endif; ?>
            <a href="/" class="logout-btn">Sair</a>
        </div>
    </div>
</header>

<main class="container">
    <div class="timezone-info">
        <strong>Atenção</strong> Sistema em desenvolvimento — alguns pontos ainda podem mudar.
    </div>

    <?php if ($feedback_message): ?>
        <div class="alert-message <?php echo (stripos($feedback_message,'Erro') !== false ? 'error' : ''); ?>">
            <?php echo htmlspecialchars($feedback_message); ?>
        </div>
    <?php endif; ?>

    <div class="tab-navigation">
        <button class="tab-button active" data-tab="dashboard">Dashboard</button>
        <button class="tab-button" data-tab="goals">Metas Detalhadas</button>
        <button class="tab-button" data-tab="comparison">Comparativos</button>
    </div>

    <!-- =============== DASHBOARD =============== -->
    <div class="tab-content active" id="dashboard-tab">
        <section class="grid-container">
            <div class="card metric-card">
                <h3>Progresso Diário</h3>
                <div class="progress-chart">
                    <canvas id="dailyProgressChart"></canvas>
                    <div class="progress-text" id="dailyProgressText">0%</div>
                </div>
                <p><span id="dailyHectares"><?php echo number_format($total_daily_hectares, 2, ',', '.'); ?></span> ha de <span id="dailyGoal"><?php echo number_format($total_daily_goal, 0, ',', '.'); ?></span> ha</p>
                <p><strong>Apontamentos hoje:</strong> <span id="dailyEntries"><?php echo (int)$total_daily_entries; ?></span></p>
            </div>

            <div class="card metric-card">
                <h3>Progresso Mensal</h3>
                <?php
                $monthly_progress_percentage = ($total_monthly_goal > 0) ? min(100, ($total_monthly_hectares / $total_monthly_goal) * 100) : 0;
                ?>
                <div class="progress-bar-container">
                    <div class="progress-bar" id="monthlyProgressBar" style="width: <?php echo $monthly_progress_percentage; ?>%"></div>
                </div>
                <p class="progress-bar-label">
                    <span id="monthlyHectares"><?php echo number_format($total_monthly_hectares, 2, ',', '.'); ?></span> ha de
                    <span id="monthlyGoal"><?php echo number_format($total_monthly_goal, 0, ',', '.'); ?></span> ha
                    (<span id="monthlyProgress"><?php echo number_format($monthly_progress_percentage, 2, ',', '.'); ?></span>%)
                </p>
                <p><strong>Apontamentos no mês:</strong> <span id="monthlyEntries"><?php echo (int)$total_monthly_entries; ?></span></p>
            </div>
        </section>

        <section class="grid-container">
            <div class="card form-card">
                <h3>Novo Apontamento</h3>
                    <form action="/user_dashboard" method="POST" id="entryForm">
                        <input type="hidden" name="usuario_id" value="<?php echo (int)$usuario_id; ?>">

                        <!-- LINHA 1: OPERAÇÃO + EQUIPAMENTO -->
                        <div class="form-row">
                            <div class="input-group" style="width: 100%;">
                            <label for="operation">Operação</label>
                            <select id="operation" name="operacao_id" class="select-search" required>
                                <option value="">Selecione a operação...</option>
                                <?php foreach ($tipos_operacao as $op): ?>
                                <option value="<?php echo (int)$op['id']; ?>" data-nome="<?php echo htmlspecialchars($op['nome']); ?>">
                                    <?php echo htmlspecialchars($op['nome']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            </div>

                            <div class="input-group">
                            <label for="equipment">Equipamento</label>
                            <select id="equipment" name="equipamento_id" class="select-search" required disabled>
                                <option value="">Primeiro selecione uma operação</option>
                            </select>
                            </div>
                        </div>

                        <!-- LINHA 2: HORÁRIO + FAZENDA -->
                        <div class="form-row">
                            <div class="input-group">
                            <label for="report_time">Horário</label>
                            <select id="report_time" name="report_time" required disabled>
                                <option value="">Selecione a operação</option>
                            </select>
                            </div>

                            <div class="input-group">
                            <label for="fazenda_id">Fazenda</label>
                            <select id="fazenda_id" name="fazenda_id" class="select-search" required>
                                <option value="">Selecione uma fazenda...</option>
                                <?php foreach ($fazendas as $f): ?>
                                <option value="<?php echo (int)$f['id']; ?>">
                                    <?php echo htmlspecialchars($f['nome']); ?> (<?php echo htmlspecialchars($f['codigo_fazenda']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            </div>
                        </div>

                        <!-- LINHA 3: STATUS -->
                        <div class="form-row">
                            <div class="input-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" required>
                                <option value="ativo" selected>Ativo</option>
                                <option value="parado">Parado</option>
                            </select>
                            </div>
                        </div>

                        <!-- LINHA 4: QUANTIDADE (hectares/viagens) -->
                        <div class="form-row">
                            <div class="input-group" id="hectares-group">
                            <label for="hectares" id="qtyLabel">Hectares</label>
                            <input type="number" step="0.01" id="hectares" name="hectares" placeholder="Ex: 15.5" required>
                            <input type="hidden" id="viagens" name="viagens" value="0">
                            <small id="qtyHelp" class="form-text">Informe a área em hectares.</small>
                            </div>
                        </div>

                        <!-- MOTIVO PARADA -->
                        <div id="reason-group" class="input-group" style="display:none;">
                            <label for="reason">Motivo da Parada</label>
                            <textarea id="reason" name="observacoes" rows="3" placeholder="Descreva o motivo da parada..."></textarea>
                        </div>

                        <button type="submit" class="btn-submit">Salvar Apontamento</button>
                    </form>
            </div>

            <div class="card list-card">
                <div class="list-header">
                    <h3>Últimos Lançamentos</h3>
                    <div class="date-filter">
                        <input type="date" id="filter-date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div id="no-entries-message" style="display: none; text-align: center; color: #777;">
                    Nenhum apontamento encontrado para esta data.
                </div>
                <ul id="recent-entries-list"></ul>
            </div>
        </section>
    </div>

    <!-- =============== METAS DETALHADAS =============== -->
    <div class="tab-content" id="goals-tab">
        <div class="goals-container">
            <?php foreach ($tipos_operacao as $operacao):
                $op_id = (int)$operacao['id'];
                $op_nome = $operacao['nome'];

                $meta_diaria       = (float)($metas_por_operacao[$op_id]['meta_diaria'] ?? 0);
                $meta_mensal       = (float)($metas_por_operacao[$op_id]['meta_mensal'] ?? 0);
                $realizado_diario  = (float)($dados_por_operacao_diario[$op_id]['total_ha'] ?? 0);
                $realizado_mensal  = (float)($dados_por_operacao_mensal[$op_id]['total_ha'] ?? 0);
                $falta_diario      = max(0, $meta_diaria - $realizado_diario);
                $falta_mensal      = max(0, $meta_mensal - $realizado_mensal);
            ?>
                <div class="card goal-card">
                    <h4><?php echo htmlspecialchars($op_nome); ?></h4>
                    <div class="goal-details">
                        <p><strong>Meta Diária:</strong> <?php echo number_format($meta_diaria, 2, ',', '.'); ?> ha</p>
                        <p><strong>Realizado Hoje:</strong> <?php echo number_format($realizado_diario, 2, ',', '.'); ?> ha</p>
                        <p><strong>Falta:</strong> <?php echo number_format($falta_diario, 2, ',', '.'); ?> ha</p>
                        <hr style="border: 0; border-top: 1px solid #eee; margin: 10px 0;">
                        <p><strong>Meta Mensal:</strong> <?php echo number_format($meta_mensal, 2, ',', '.'); ?> ha</p>
                        <p><strong>Realizado no Mês:</strong> <?php echo number_format($realizado_mensal, 2, ',', '.'); ?> ha</p>
                        <p><strong>Falta:</strong> <?php echo number_format($falta_mensal, 2, ',', '.'); ?> ha</p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- =============== COMPARATIVOS =============== -->
    <div class="tab-content" id="comparison-tab">
        <div class="chart-container">
            <div class="chart-header">
                <h3 class="chart-title">Comparativo de Hectares - Solinftec vs Manual</h3>
                <div class="chart-filter">
                    <select id="comparison-period">
                        <option value="7">Últimos 7 dias</option>
                        <option value="15">Últimos 15 dias</option>
                        <option value="30">Últimos 30 dias</option>
                    </select>
                </div>
            </div>
            <div class="chart-canvas-container">
                <canvas id="comparisonChart"></canvas>
            </div>
        </div>

        <div class="card">
            <h3>Dados Detalhados</h3>
            <div class="table-responsive">
                <table class="comparison-table">
                    <thead>
                    <tr>
                        <th>Data</th>
                        <th>Hectares Solinftec</th>
                        <th>Hectares Manual</th>
                        <th>Diferença</th>
                        <th>Variação</th>
                    </tr>
                    </thead>
                    <tbody id="comparison-table-body">
                    <?php if (!empty($comparison_data)): ?>
                        <?php foreach ($comparison_data as $data): ?>
                            <?php
                            $ha_solinftec = (float)($data['ha_solinftec'] ?? 0);
                            $ha_manual    = (float)($data['ha_manual'] ?? 0);
                            $diferenca    = $ha_solinftec - $ha_manual;
                            $variacao     = $ha_solinftec > 0 ? (($diferenca / $ha_solinftec) * 100) : 0;
                            ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($data['data'])); ?></td>
                                <td><?php echo number_format($ha_solinftec, 2, ',', '.'); ?></td>
                                <td><?php echo number_format($ha_manual, 2, ',', '.'); ?></td>
                                <td class="<?php echo $diferenca >= 0 ? 'difference-positive' : 'difference-negative'; ?>">
                                    <?php echo number_format($diferenca, 2, ',', '.'); ?>
                                </td>
                                <td class="<?php echo $diferenca >= 0 ? 'difference-positive' : 'difference-negative'; ?>">
                                    <?php echo number_format($variacao, 2, ',', '.'); ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align: center;">Nenhum dado disponível para comparação</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close-btn">&times;</span>
        <h3>Detalhes do Apontamento</h3>
        <form id="editForm">
            <input type="hidden" id="edit-id" name="id">
            <div class="form-row">
                <div class="input-group">
                    <label>ID do Apontamento</label>
                    <input type="text" id="modal-id" readonly>
                </div>
            </div>
            <div class="form-row">
                <div class="input-group">
                    <label>Data/Hora (Brasília)</label>
                    <input type="text" id="modal-datetime" readonly>
                </div>
            </div>
            <div class="form-row">
                <div class="input-group">
                    <label>Fazenda</label>
                    <input type="text" id="modal-farm" readonly>
                </div>
                <div class="input-group">
                    <label>Equipamento</label>
                    <input type="text" id="modal-equipment" readonly>
                </div>
            </div>
            <div class="form-row">
                <div class="input-group">
                    <label>Operação</label>
                    <input type="text" id="modal-operation" readonly>
                </div>
                <div class="input-group">
                    <label>Hectares</label>
                    <input type="text" id="modal-hectares" readonly>
                </div>
            </div>
            <div class="form-row">
                <div class="input-group">
                    <label>Observações</label>
                    <textarea id="modal-reason" rows="3" readonly></textarea>
                </div>
            </div>
            <p>O recurso de edição está em desenvolvimento.</p>
        </form>
    </div>
</div>

<!-- Créditos -->
<div class="signature-credit">
  <p class="sig-text">
    Desenvolvido por 
    <span class="sig-name">Bruno Carmo</span> & 
    <span class="sig-name">Henrique Reis</span>
  </p>
</div>

<script>
$(function () {
  // ===== Relógio Brasília (informativo) =====
  function updateBrasiliaTime() {
    const now = new Date();
    const brasiliaOffset = -3 * 60; // UTC-3
    const localOffset = now.getTimezoneOffset();
    const brasiliaTime = new Date(now.getTime() + (localOffset - brasiliaOffset) * 60000);

    const fmt = new Intl.DateTimeFormat('pt-BR', {
      timeZone: 'America/Sao_Paulo',
      hour: '2-digit', minute: '2-digit', second: '2-digit',
      day: '2-digit', month: '2-digit', year: 'numeric'
    });
    $('#current-time').text(`Horário atual: ${fmt.format(brasiliaTime)} (Brasília)`);
  }
  setInterval(updateBrasiliaTime, 1000); updateBrasiliaTime();

  // ===== Select2 =====
  $('.select-search').select2({ placeholder: "Clique ou digite para pesquisar...", allowClear: true, width: '100%' });

  // ===== Fazendas da unidade do usuário =====
  function carregarFazendasDoUsuario() {
    const $fazenda = $('#fazenda_id');
    $fazenda.prop('disabled', true).html('<option value="">Carregando fazendas...</option>').trigger('change');

    fetch('/get_fazendas', { credentials: 'same-origin' })
      .then(r => { if (!r.ok) throw new Error('Falha ao carregar fazendas'); return r.json(); })
      .then(json => {
        if (!json.success) throw new Error(json.error || 'Erro ao carregar fazendas');
        const fazendas = json.fazendas || [];
        $fazenda.empty();
        if (!fazendas.length) {
          $fazenda.append('<option value="">Nenhuma fazenda disponível</option>').prop('disabled', true).trigger('change');
          return;
        }
        $fazenda.append('<option value="">Selecione uma fazenda...</option>');
        fazendas.forEach(f => $fazenda.append(new Option(`${f.nome} (${f.codigo_fazenda || 's/ código'})`, f.id)));
        $fazenda.prop('disabled', false).trigger('change');
      })
      .catch(() => {
        $fazenda.html('<option value="">Erro ao carregar fazendas</option>').prop('disabled', true).trigger('change');
      });
  }
  carregarFazendasDoUsuario();

  // ===== Equipamentos por operação =====
  function carregarEquipamentosPorOperacao(operacaoId) {
    const $equip = $('#equipment');
    if (!operacaoId) {
      $equip.html('<option value="">Primeiro selecione uma operação</option>').prop('disabled', true).trigger('change');
      return;
    }
    $equip.prop('disabled', false).html('<option value="">Carregando equipamentos...</option>').trigger('change');

    $.ajax({
      url: '/equipamentos',
      type: 'GET',
      data: { operacao_id: operacaoId },
      dataType: 'json'
    }).done(function (response) {
      if (response.success) {
        $equip.html('<option value="">Selecione um equipamento...</option>');
        (response.equipamentos || []).forEach(e => $equip.append(`<option value="${e.id}">${e.nome}</option>`));
        $equip.prop('disabled', false).trigger('change');
      } else {
        $equip.html('<option value="">Erro ao carregar equipamentos</option>').prop('disabled', true).trigger('change');
      }
    }).fail(function () {
      $equip.html('<option value="">Erro ao carregar equipamentos</option>').prop('disabled', true).trigger('change');
    });
  }
  $('#operation').on('change', function(){ carregarEquipamentosPorOperacao($(this).val()); });

  // ===== Status Ativo/Parado =====
$('#status').on('change', function () {
  const parado = ($(this).val() === 'parado');
  $('#hectares-group').toggle(!parado);
  $('#hectares').prop('required', !parado).val(parado ? 0 : '');
  $('#viagens').val(parado ? 0 : $('#viagens').val());
  $('#reason-group').toggle(parado).find('textarea').prop('required', parado).val('');
}).trigger('change');

// ===== Operação: alternar entre HECTARES (2/2h) e CAÇAMBA (06:00,14:00,22:00) =====
(function(){
  const CACAMBA_ID = 5;                  // <- id da operação "CACAMBA"
  const $op   = $('#operation');
  const $qtyLabel = $('#qtyLabel');
  const $qtyHelp  = $('#qtyHelp');
  const $hect     = $('#hectares');
  const $viag     = $('#viagens');
  const $time     = $('#report_time');

  const TURNOS_CACAMBA = ['06:00','14:00','22:00'];
  const SLOTS_2H       = ['02:00','04:00','06:00','08:00','10:00','12:00','14:00','16:00','18:00','20:00','22:00','00:00'];

  function rebuildTimeOptions(list, selected){
    $time.prop('disabled', false).empty();
    list.forEach(h => $time.append(`<option value="${h}" ${h===selected?'selected':''}>${h}</option>`));
  }

  function applyOpUI(){
    const opId = Number($op.val());
    if (!opId) {
      $time.prop('disabled', true).html('<option value="">Selecione a operação</option>');
      return;
    }
    const cacamba = (opId === CACAMBA_ID);

    if (cacamba) {
      $qtyLabel.text('Viagens');
      $qtyHelp.text('Quantas viagens no turno.');
      $hect.attr({ step: '1', min: '0', placeholder: 'Ex: 8' }).val('');
      rebuildTimeOptions(TURNOS_CACAMBA, '06:00');
    } else {
      $qtyLabel.text('Hectares');
      $qtyHelp.text('Informe a área em hectares.');
      $hect.attr({ step: '0.01', min: '0', placeholder: 'Ex: 15.5' }).val('');
      rebuildTimeOptions(SLOTS_2H, '08:00');
    }
    $('#status').trigger('change');
  }

  // copiar p/ hidden viagens no submit se for Caçamba
  $('#entryForm').on('submit', function(){
    const cacamba = (Number($op.val()) === CACAMBA_ID);
    if (cacamba) {
      const val = parseInt($hect.val() || '0', 10);
      $viag.val(isNaN(val) ? 0 : val);
      $hect.val('0'); // garante hectares=0 em caçamba
    } else {
      $viag.val(0);
    }
  });

  $op.on('change', applyOpUI);
  // estado inicial
  $time.prop('disabled', true).html('<option value="">Selecione a operação</option>');
})();

  // ===== Donut diário (metas somadas) =====
  const totalDailyGoal = <?php echo json_encode($total_daily_goal); ?>;
  const totalDailyHectares = <?php echo json_encode($total_daily_hectares); ?>;
  const dailyPct = (totalDailyGoal > 0) ? Math.min(100, (totalDailyHectares / totalDailyGoal) * 100) : 0;

  const dailyCtx = document.getElementById('dailyProgressChart').getContext('2d');
  new Chart(dailyCtx, {
    type: 'doughnut',
    data: { datasets: [{ data: [totalDailyHectares, Math.max(0, totalDailyGoal - totalDailyHectares)], backgroundColor: ['#00796b', '#e0e0e0'], borderWidth: 0 }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '80%', plugins: { legend: { display: false }, tooltip: { enabled: false } } }
  });
  $('#dailyProgressText').text(`${Math.round(dailyPct)}%`);

  // ===== Últimos lançamentos (com histórico por data) =====
  
function formatQtd(entry) {
  const isViagem = (entry.quant_label === 'Viagens') || (String(entry.is_cacamba) === '1');
  const raw = entry.quantidade ?? (isViagem ? entry.viagens : entry.hectares);
  const n = Number(raw) || 0;
  return isViagem
    ? `Viagens: ${parseInt(n, 10)}`
    : `Hectares: ${n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatQtd(entry) {
  const isViagem = (entry.quant_label === 'Viagens') || (String(entry.is_cacamba) === '1');
  const raw = entry.quantidade ?? (isViagem ? entry.viagens : entry.hectares);
  const n = Number(raw) || 0;
  return isViagem
    ? `Viagens: ${parseInt(n, 10)}`
    : `Hectares: ${n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function fetchApontamentos(date) {
  const userId = <?php echo json_encode($_SESSION['usuario_id']); ?>;
  $('#recent-entries-list').html('<div style="text-align:center;">Carregando...</div>');
  $('#no-entries-message').hide();

  $.ajax({
    url: '/apontamentos',
    type: 'GET',
    data: { date: date, usuario_id: userId },
    dataType: 'json'
  }).done(function (response) {
    const list = $('#recent-entries-list').empty();
    if (Array.isArray(response) && response.length) {
      response.forEach(entry => {
        const horaTexto = entry.hora_selecionada
          ? String(entry.hora_selecionada).slice(0,5)
          : (entry.data_hora ? String(entry.data_hora).replace('T',' ').slice(11,16) : '--:--');

        const $li = $(`
          <li class="entry-item" data-entry-id="${entry.id}">
            <div class="entry-details">
              <strong>${entry.equipamento_nome ?? ''}</strong>
              <p>${entry.unidade_nome ?? ''} - ${entry.operacao_nome ?? ''}</p>
              <small>${formatQtd(entry)} | Hora: ${horaTexto}</small>
            </div>
            <div class="entry-action">
              <button class="open-modal-btn">Detalhes</button>
            </div>
          </li>
        `);
        $li.data('entry', entry); // 👈 guarda o objeto (evita JSON como string)
        list.append($li);
      });
    } else {
      $('#no-entries-message').show();
    }
  }).fail(function () {
    $('#recent-entries-list').html('<div style="text-align:center;color:red;">Erro ao carregar os apontamentos.</div>');
  });
}
$('#filter-date').on('change', function(){ fetchApontamentos($(this).val()); });
fetchApontamentos($('#filter-date').val());

  // ===== Modal Detalhes (sem “+3h”) =====
const modal = $('#editModal');
const closeBtn = $('.close-btn');

window.openModal = function (entry) {
  if (typeof entry === 'string') { try { entry = JSON.parse(entry); } catch(_){} }

  const isViagem = (entry.quant_label === 'Viagens') || (String(entry.is_cacamba) === '1');
  const raw = entry.quantidade ?? (isViagem ? entry.viagens : entry.hectares);
  const n = Number(raw) || 0;

  $('#modal-operation').val(entry.operacao_nome || '');
  $('#modal-hectares').prev('label').text(isViagem ? 'Viagens' : 'Hectares');
  $('#modal-hectares').val(isViagem ? parseInt(n, 10) : n.toFixed(2));

  $('#modal-id').val(entry.id);

  let dataISO = '';
  if (entry.data_hora) {
    const s = String(entry.data_hora).replace('T',' ');
    dataISO = s.slice(0,10);
  }
  const horaSel = entry.hora_selecionada ? String(entry.hora_selecionada).slice(0,5)
                : (entry.data_hora ? String(entry.data_hora).slice(11,16) : '--:--');

  $('#modal-datetime').val(`${dataISO.split('-').reverse().join('/')} ${horaSel}`);
  $('#modal-farm').val(entry.unidade_nome || '');
  $('#modal-equipment').val(entry.equipamento_nome || '');
  $('#modal-reason').val(entry.observacoes || '');
  modal.show();
};

$('#recent-entries-list').on('click', '.open-modal-btn', function () {
  const entryObj = $(this).closest('.entry-item').data('entry'); // 👈 objeto salvo
  openModal(entryObj);
});
closeBtn.on('click', () => modal.hide());
$(window).on('click', (e) => { if ($(e.target).is(modal)) modal.hide(); });


  // ===== Abas =====
  $('.tab-button').on('click', function () {
    $('.tab-button').removeClass('active'); $('.tab-content').removeClass('active');
    $(this).addClass('active'); const tabId = $(this).data('tab'); $(`#${tabId}-tab`).addClass('active');
    if (tabId === 'comparison') updateComparisonChart();
  });

  // ===== Comparativo (fake) =====
  const comparisonData = <?php echo json_encode($comparison_data); ?>;
  function updateComparisonChart() {
    renderComparisonChart(comparisonData);
  }
  function renderComparisonChart(data) {
    const ctx = document.getElementById('comparisonChart').getContext('2d');
    if (window.comparisonChartInstance) window.comparisonChartInstance.destroy();

    const labels = data.map(item => {
      const d = new Date(item.data);
      return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
    });
    const manualData   = data.map(item => parseFloat(item.ha_manual)    || 0);
    const solinftecData= data.map(item => parseFloat(item.ha_solinftec) || 0);

    window.comparisonChartInstance = new Chart(ctx, {
      type: 'bar',
      data: { labels, datasets: [
        { label: 'Hectares Solinftec', data: solinftecData, backgroundColor: 'rgba(0,121,107,0.7)', borderColor: 'rgba(0,121,107,1)', borderWidth: 1 },
        { label: 'Hectares Manual',    data: manualData,    backgroundColor: 'rgba(255,152,0,0.7)', borderColor: 'rgba(255,152,0,1)', borderWidth: 1 }
      ]},
      options: {
        responsive: true, maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, title: { display: true, text: 'Hectares' } }, x: { title: { display: true, text: 'Data' } } },
        plugins: { legend: { position: 'top' }, title: { display: true, text: 'Comparativo de Hectares - Solinftec vs Manual' } }
      }
    });
  }
  $('#comparison-period').on('change', updateComparisonChart);
  updateComparisonChart();
});
</script>
</body>
</html>
