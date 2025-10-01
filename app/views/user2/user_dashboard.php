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

    // Carrega equipamentos por operação
    $equipamentos_por_operacao = [];
    foreach ($tipos_operacao as $operacao) {
        $op_id = (int)$operacao['id'];
        try {
            $stmt_equip = $pdo->prepare("
                SELECT id, nome 
                FROM equipamentos 
                WHERE operacao_id = ? AND ativo = 1 
                ORDER BY nome
            ");
            $stmt_equip->execute([$op_id]);
            $equipamentos_por_operacao[$op_id] = $stmt_equip->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $equipamentos_por_operacao[$op_id] = [];
        }
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
    $equipamentos_por_operacao = [];
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
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- CSS personalizado -->
    <link rel="stylesheet" href="/public/static/css/ctt.css">
    <style>
        /* Estilos adicionais para melhorar a visualização */
        .progress-stats {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .progress-stats .stat {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border);
        }

        .progress-stats .stat:last-child {
            border-bottom: none;
        }

        .progress-stats .label {
            font-weight: 500;
            color: var(--gray);
            font-size: 0.9rem;
        }

        .progress-stats .value {
            font-weight: 600;
            font-size: 0.95rem;
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

        @media (max-width: 768px) {
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
                <i class="fas fa-chart-line"></i>
                <h1>User Dashboard</h1>
                <span class="badge bg-warning">v2.0</span>
            </div>
            <div class="header-info">
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
                <a href="/" class="btn btn-sm btn-outline-light">
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
                <i class="fas fa-chart-line"></i> Dashboard
            </button>
            <button class="nav-link" id="nav-metas-tab" data-bs-toggle="tab" data-bs-target="#nav-metas" type="button" role="tab">
                <i class="fas fa-bullseye"></i> Metas Detalhadas
            </button>
            <button class="nav-link" id="nav-comparison-tab" data-bs-toggle="tab" data-bs-target="#nav-comparison" type="button" role="tab">
                <i class="fas fa-chart-bar"></i> Comparativos
            </button>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <!-- Mensagens de feedback -->
    <?php if ($feedback_message): ?>
        <div class="alert alert-<?php echo (stripos($feedback_message,'Erro') !== false ? 'danger' : 'success'); ?> alert-dismissible fade show" role="alert">
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
                            <p>Ha Mensal</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="ctt-card stat-card">
                        <div class="card-icon bg-info">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="card-content">
                            <h3><?= $total_daily_entries ?></h3>
                            <p>Apontamentos Hoje</p>
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

                <!-- Gráficos de Produtividade -->
                <div class="col-xl-8">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-bar"></i> Produtividade por Operação</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="operacaoChart" height="250"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Status das Operações -->
                <div class="col-xl-4">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-tachometer-alt"></i> Status das Operações</h5>
                        </div>
                        <div class="card-body">
                            <div class="status-list">
                                <?php foreach ($tipos_operacao as $operacao): 
                                    $op_id = (int)$operacao['id'];
                                    $realizado_diario = (float)($dados_por_operacao_diario[$op_id]['total_ha'] ?? 0);
                                    $meta_diaria = (float)($metas_por_operacao[$op_id]['meta_diaria'] ?? 0);
                                    $progresso = ($meta_diaria > 0) ? min(100, ($realizado_diario / $meta_diaria) * 100) : 0;
                                    $status_class = $progresso >= 80 ? 'bg-success' : ($progresso >= 50 ? 'bg-warning' : 'bg-danger');
                                ?>
                                <div class="status-item">
                                    <div class="status-indicator <?= $status_class ?>"></div>
                                    <span class="operacao-name"><?= htmlspecialchars($operacao['nome']) ?></span>
                                    <span class="operacao-progress"><?= number_format($progresso, 1) ?>%</span>
                                </div>
                                <?php endforeach; ?>
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
                                <!-- LINHA 1: OPERAÇÃO + EQUIPAMENTO -->
                                <div class="col-md-6">
                                    <label class="form-label">Operação *</label>
                                    <select id="operation" name="operacao_id" class="form-select select-search" required>
                                        <option value="">Selecione a operação...</option>
                                        <?php foreach ($tipos_operacao as $op): ?>
                                        <option value="<?= (int)$op['id'] ?>" 
                                                data-nome="<?= htmlspecialchars($op['nome']) ?>"
                                                data-equipamentos='<?= json_encode($equipamentos_por_operacao[(int)$op['id']] ?? []) ?>'>
                                            <?= htmlspecialchars($op['nome']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Equipamento *</label>
                                    <select id="equipment" name="equipamento_id" class="form-select select-search" required disabled>
                                        <option value="">Selecione primeiro a operação</option>
                                    </select>
                                </div>

                                <!-- LINHA 2: HORÁRIO + FAZENDA -->
                                <div class="col-md-6">
                                    <label class="form-label">Horário *</label>
                                    <select id="report_time" name="report_time" class="form-select" required disabled>
                                        <option value="">Selecione a operação</option>
                                    </select>
                                </div>

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

                                <!-- LINHA 3: STATUS -->
                                <div class="col-md-6">
                                    <label class="form-label">Status *</label>
                                    <select id="status" name="status" class="form-select" required>
                                        <option value="ativo" selected>Ativo</option>
                                        <option value="parado">Parado</option>
                                    </select>
                                </div>

                                <!-- LINHA 4: QUANTIDADE (hectares/viagens) -->
                                <div class="col-md-6" id="hectares-group">
                                    <label class="form-label" id="qtyLabel">Hectares *</label>
                                    <input type="number" step="0.01" id="hectares" name="hectares" class="form-control" placeholder="Ex: 15.5" required>
                                    <input type="hidden" id="viagens" name="viagens" value="0">
                                    <div class="form-text" id="qtyHelp">Informe a área em hectares.</div>
                                </div>

                                <!-- MOTIVO PARADA -->
                                <div class="col-12" id="reason-group" style="display:none;">
                                    <label class="form-label">Motivo da Parada</label>
                                    <textarea id="reason" name="observacoes" class="form-control" rows="3" placeholder="Descreva o motivo da parada..."></textarea>
                                </div>

                                <div class="col-12 text-end">
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
                            <h6><?= htmlspecialchars($op_nome) ?></h6>
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
                            <h5><i class="fas fa-chart-bar"></i> Comparativo de Hectares - Solinftec vs Manual</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-filter mb-3">
                                <select id="comparison-period" class="form-select" style="max-width: 200px;">
                                    <option value="7">Últimos 7 dias</option>
                                    <option value="15">Últimos 15 dias</option>
                                    <option value="30">Últimos 30 dias</option>
                                </select>
                            </div>
                            <div class="chart-canvas-container">
                                <canvas id="comparisonChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-table"></i> Dados Detalhados</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
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
                                            $diff         = $ha_solinftec - $ha_manual;
                                            $variation    = ($ha_manual > 0) ? ($diff / $ha_manual) * 100 : 0;
                                            ?>
                                            <tr>
                                                <td><?= date('d/m/Y', strtotime($data['data'])) ?></td>
                                                <td><?= number_format($ha_solinftec, 2, ',', '.') ?></td>
                                                <td><?= number_format($ha_manual, 2, ',', '.') ?></td>
                                                <td class="<?= $diff >= 0 ? 'text-success' : 'text-danger' ?>">
                                                    <?= number_format($diff, 2, ',', '.') ?>
                                                </td>
                                                <td class="<?= $variation >= 0 ? 'text-success' : 'text-danger' ?>">
                                                    <?= number_format($variation, 1, ',', '.') ?>%
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center">Nenhum dado disponível</td>
                                        </tr>
                                    <?php endif; ?>
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

<!-- Footer -->
<footer class="ctt-footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-info">
                <span>CTT Agrícola - Sistema de Apontamentos</span>
                <span class="separator">|</span>
                <span>Unidade: <?= htmlspecialchars($unidade_do_usuario_nome) ?></span>
            </div>
            <div class="footer-version">
                <span>v2.0 - <?= date('Y') ?></span>
            </div>
        </div>
    </div>
</footer>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Inicialização do Select2
$(document).ready(function() {
    $('.select-search').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });
});

// Atualização do relógio em tempo real
function updateClock() {
    const now = new Date();
    const options = { 
        timeZone: 'America/Sao_Paulo',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    };
    const formatter = new Intl.DateTimeFormat('pt-BR', options);
    document.getElementById('current-time').querySelector('span').textContent = formatter.format(now);
}
setInterval(updateClock, 1000);
updateClock();

// Lógica do formulário de apontamento
document.addEventListener('DOMContentLoaded', function() {
    const operationSelect = document.getElementById('operation');
    const equipmentSelect = document.getElementById('equipment');
    const reportTimeSelect = document.getElementById('report_time');
    const statusSelect = document.getElementById('status');
    const hectaresGroup = document.getElementById('hectares-group');
    const reasonGroup = document.getElementById('reason-group');
    const qtyLabel = document.getElementById('qtyLabel');
    const qtyHelp = document.getElementById('qtyHelp');
    const hectaresInput = document.getElementById('hectares');
    const viagensInput = document.getElementById('viagens');

    // Horários disponíveis
    const reportHours = <?= json_encode($report_hours) ?>;

    // Carrega equipamentos quando operação é selecionada
    operationSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const operationName = selectedOption?.getAttribute('data-nome') || '';
        
        // Limpa e desabilita selects dependentes
        equipmentSelect.innerHTML = '<option value="">Selecione o equipamento...</option>';
        equipmentSelect.disabled = true;
        reportTimeSelect.innerHTML = '<option value="">Selecione horário...</option>';
        reportTimeSelect.disabled = true;

        if (!this.value) {
            return;
        }

        // Carrega equipamentos do data attribute
        const equipamentosData = selectedOption.getAttribute('data-equipamentos');
        const equipamentos = equipamentosData ? JSON.parse(equipamentosData) : [];
        
        if (equipamentos.length > 0) {
            equipamentos.forEach(equip => {
                const option = document.createElement('option');
                option.value = equip.id;
                option.textContent = equip.nome;
                equipmentSelect.appendChild(option);
            });
            equipmentSelect.disabled = false;
        } else {
            equipmentSelect.innerHTML = '<option value="">Nenhum equipamento disponível</option>';
        }

        // Preenche horários
        reportTimeSelect.innerHTML = '';
        reportHours.forEach(hour => {
            reportTimeSelect.innerHTML += `<option value="${hour}">${hour}</option>`;
        });
        reportTimeSelect.disabled = false;

        // Verifica se é operação de caçamba
        const isCacamba = operationName.toLowerCase().includes('caçamba') || 
                          operationName.toLowerCase().includes('cacamba');
        
        // Atualiza labels e placeholders
        if (isCacamba) {
            qtyLabel.textContent = 'Número de Viagens *';
            hectaresInput.placeholder = 'Ex: 8';
            qtyHelp.textContent = 'Informe o número de viagens realizadas.';
            hectaresInput.type = 'number';
            hectaresInput.step = '1';
            hectaresInput.min = '0';
            viagensInput.value = '0';
        } else {
            qtyLabel.textContent = 'Hectares *';
            hectaresInput.placeholder = 'Ex: 15.5';
            qtyHelp.textContent = 'Informe a área em hectares.';
            hectaresInput.type = 'number';
            hectaresInput.step = '0.01';
            hectaresInput.min = '0';
            viagensInput.value = '0';
        }
    });

    // Mostra/oculta motivo da parada
    statusSelect.addEventListener('change', function() {
        if (this.value === 'parado') {
            reasonGroup.style.display = 'block';
            hectaresGroup.style.display = 'none';
            hectaresInput.required = false;
        } else {
            reasonGroup.style.display = 'none';
            hectaresGroup.style.display = 'block';
            hectaresInput.required = true;
        }
    });

    // Atualiza viagens quando hectares muda (para caçambas)
    hectaresInput.addEventListener('input', function() {
        const operationName = operationSelect.options[operationSelect.selectedIndex]?.getAttribute('data-nome') || '';
        const isCacamba = operationName.toLowerCase().includes('caçamba') || 
                          operationName.toLowerCase().includes('cacamba');
        
        if (isCacamba) {
            viagensInput.value = this.value;
        }
    });

    // Processa o formulário para caçambas
    document.getElementById('entryForm').addEventListener('submit', function(e) {
        const operationName = operationSelect.options[operationSelect.selectedIndex]?.getAttribute('data-nome') || '';
        const isCacamba = operationName.toLowerCase().includes('caçamba') || 
                          operationName.toLowerCase().includes('cacamba');
        
        if (isCacamba && statusSelect.value === 'ativo') {
            // Para caçambas ativas, move o valor de hectares para viagens
            viagensInput.value = hectaresInput.value;
            hectaresInput.value = '0';
        }
    });

    // Gráfico de Produtividade por Operação
    const operacaoCtx = document.getElementById('operacaoChart').getContext('2d');
    const operacaoChart = new Chart(operacaoCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($tipos_operacao, 'nome')) ?>,
            datasets: [
                {
                    label: 'Meta Diária',
                    data: <?= json_encode(array_map(function($op) use ($metas_por_operacao) {
                        $op_id = (int)$op['id'];
                        return $metas_por_operacao[$op_id]['meta_diaria'] ?? 0;
                    }, $tipos_operacao)) ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.8)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Realizado Hoje',
                    data: <?= json_encode(array_map(function($op) use ($dados_por_operacao_diario) {
                        $op_id = (int)$op['id'];
                        return $dados_por_operacao_diario[$op_id]['total_ha'] ?? 0;
                    }, $tipos_operacao)) ?>,
                    backgroundColor: 'rgba(75, 192, 192, 0.8)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Hectares'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Operações'
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                },
                title: {
                    display: true,
                    text: 'Produtividade por Operação - Diário'
                }
            }
        }
    });

    // Gráfico de Comparativo
    const comparisonCtx = document.getElementById('comparisonChart').getContext('2d');
    const comparisonChart = new Chart(comparisonCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode(array_map(fn($d) => date('d/m', strtotime($d['data'])), $comparison_data)) ?>,
            datasets: [
                {
                    label: 'Solinftec',
                    data: <?= json_encode(array_column($comparison_data, 'ha_solinftec')) ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.1)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: 'rgba(54, 162, 235, 1)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 6
                },
                {
                    label: 'Manual',
                    data: <?= json_encode(array_column($comparison_data, 'ha_manual')) ?>,
                    backgroundColor: 'rgba(255, 99, 132, 0.1)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: 'rgba(255, 99, 132, 1)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Hectares'
                    },
                    grid: {
                        color: 'rgba(0,0,0,0.1)'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Data'
                    },
                    grid: {
                        color: 'rgba(0,0,0,0.1)'
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 20
                    }
                },
                title: {
                    display: true,
                    text: 'Comparativo de Hectares - Solinftec vs Manual',
                    font: {
                        size: 16
                    }
                }
            }
        }
    });

    // Filtro de período para comparativo
    document.getElementById('comparison-period').addEventListener('change', function() {
        alert('Funcionalidade de filtro por período será implementada em breve!');
    });
});
</script>

</body>
</html>