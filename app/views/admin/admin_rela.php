<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';
require_once __DIR__.'/../../../app/lib/Audit.php';

$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev','coordenador_campo'];

if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
    header("Location: /");
    exit();
}

require_once __DIR__ . '/../../../app/includes/header.php';
require_once __DIR__.'/../../../app/lib/Audit.php';

// Verificar autenticação
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login");
    exit();
}

// Dados do usuário
$usuario_id = $_SESSION['usuario_id'];
$user_role = $_SESSION['usuario_tipo'] ?? 'operador';

// Buscar dados do usuário
$stmt_user = $pdo->prepare("SELECT unidade_id, nome FROM usuarios WHERE id = ?");
$stmt_user->execute([$usuario_id]);
$user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
$unidade_usuario = $user_data['unidade_id'] ?? null;
$username = $user_data['nome'] ?? 'Usuário';

// Filtros
$periodo = $_GET['periodo'] ?? 'month';
$unidade_id = $_GET['unidade'] ?? ($unidade_usuario ?: 'all');
$operacao_id = $_GET['operacao'] ?? 'all';
$data_inicio = $_GET['data_inicio'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';
$equipamento_id = $_GET['equipamento'] ?? 'all';
$status = $_GET['status'] ?? 'all';

// Definir datas baseadas no período
$hoje = date('Y-m-d');
switch ($periodo) {
    case 'day':
        $data_inicio = $hoje;
        $data_fim = $hoje;
        break;
    case 'week':
        $data_inicio = date('Y-m-d', strtotime('monday this week'));
        $data_fim = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $data_inicio = date('Y-m-01');
        $data_fim = date('Y-m-t');
        break;
    case 'year':
        $data_inicio = date('Y-01-01');
        $data_fim = date('Y-12-31');
        break;
    case 'custom':
        // Usar datas fornecidas
        break;
}

// Construir condições WHERE
$where_conditions = [];
$params = [];

// Condição de data
if ($data_inicio && $data_fim) {
    $where_conditions[] = "DATE(a.data_hora) BETWEEN ? AND ?";
    $params[] = $data_inicio;
    $params[] = $data_fim;
}

// Condição de unidade
if ($unidade_id !== 'all' && is_numeric($unidade_id)) {
    $where_conditions[] = "a.unidade_id = ?";
    $params[] = $unidade_id;
}

// Condição de operação
if ($operacao_id !== 'all' && is_numeric($operacao_id)) {
    $where_conditions[] = "a.operacao_id = ?";
    $params[] = $operacao_id;
}

// Condição de equipamento
if ($equipamento_id !== 'all' && is_numeric($equipamento_id)) {
    $where_conditions[] = "a.equipamento_id = ?";
    $params[] = $equipamento_id;
}

$where_clause = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Consulta para totais gerais
$sql_totais = "
    SELECT 
        COUNT(DISTINCT a.equipamento_id) as total_equipamentos,
        COUNT(DISTINCT a.operacao_id) as total_operacoes,
        SUM(a.hectares) as total_hectares,
        COUNT(*) as total_apontamentos,
        AVG(a.hectares) as media_hectares,
        MAX(a.hectares) as max_hectares,
        MIN(a.hectares) as min_hectares
    FROM apontamentos a
    $where_clause
";

$stmt_totais = $pdo->prepare($sql_totais);
$stmt_totais->execute($params);
$totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);

// Consulta para dados por operação
$sql_operacoes = "
    SELECT 
        o.id,
        o.nome as operacao_nome,
        COUNT(DISTINCT a.equipamento_id) as equipamentos,
        SUM(a.hectares) as total_hectares,
        COUNT(*) as apontamentos,
        AVG(a.hectares) as media_hectares
    FROM apontamentos a
    INNER JOIN tipos_operacao o ON a.operacao_id = o.id
    $where_clause
    GROUP BY o.id, o.nome
    ORDER BY total_hectares DESC
";

$stmt_operacoes = $pdo->prepare($sql_operacoes);
$stmt_operacoes->execute($params);
$operacoes = $stmt_operacoes->fetchAll(PDO::FETCH_ASSOC);

// Consulta para TODOS os equipamentos (sem limite)
$sql_equipamentos = "
    SELECT 
        e.id,
        e.nome as equipamento_nome,
        o.nome as operacao_nome,
        u.nome as unidade_nome,
        SUM(a.hectares) as total_hectares,
        COUNT(*) as apontamentos,
        AVG(a.hectares) as media_hectares,
        MAX(a.hectares) as max_hectares,
        MIN(a.hectares) as min_hectares
    FROM apontamentos a
    INNER JOIN equipamentos e ON a.equipamento_id = e.id
    INNER JOIN tipos_operacao o ON a.operacao_id = o.id
    INNER JOIN unidades u ON a.unidade_id = u.id
    $where_clause
    GROUP BY e.id, e.nome, o.nome, u.nome
    ORDER BY total_hectares DESC
";

$stmt_equipamentos = $pdo->prepare($sql_equipamentos);
$stmt_equipamentos->execute($params);
$equipamentos = $stmt_equipamentos->fetchAll(PDO::FETCH_ASSOC);

// Consulta para dados mensais (comparativo)
$sql_mensal = "
    SELECT 
        YEAR(a.data_hora) as ano,
        MONTH(a.data_hora) as mes,
        CONCAT(YEAR(a.data_hora), '-', LPAD(MONTH(a.data_hora), 2, '0')) as periodo,
        SUM(a.hectares) as total_hectares,
        COUNT(*) as apontamentos,
        AVG(a.hectares) as media_hectares
    FROM apontamentos a
    WHERE YEAR(a.data_hora) = YEAR(CURDATE())
    GROUP BY YEAR(a.data_hora), MONTH(a.data_hora)
    ORDER BY ano DESC, mes DESC
    LIMIT 12
";

$stmt_mensal = $pdo->prepare($sql_mensal);
$stmt_mensal->execute();
$dados_mensais = $stmt_mensal->fetchAll(PDO::FETCH_ASSOC);

// Encontrar melhor e pior mês
$melhor_mes = null;
$pior_mes = null;

if (!empty($dados_mensais)) {
    $melhor_mes = $dados_mensais[0];
    $pior_mes = $dados_mensais[0];
    
    foreach ($dados_mensais as $mes) {
        if ($mes['total_hectares'] > $melhor_mes['total_hectares']) {
            $melhor_mes = $mes;
        }
        if ($mes['total_hectares'] < $pior_mes['total_hectares']) {
            $pior_mes = $mes;
        }
    }
}

// Buscar unidades para o filtro
$sql_unidades = "SELECT id, nome FROM unidades ORDER BY nome";
$unidades = $pdo->query($sql_unidades)->fetchAll(PDO::FETCH_ASSOC);

// Buscar operações para o filtro
$sql_operacoes_filtro = "SELECT id, nome FROM tipos_operacao ORDER BY nome";
$operacoes_filtro = $pdo->query($sql_operacoes_filtro)->fetchAll(PDO::FETCH_ASSOC);

// Buscar equipamentos para o filtro
$sql_equipamentos_filtro = "SELECT id, nome FROM equipamentos ORDER BY nome";
$equipamentos_filtro = $pdo->query($sql_equipamentos_filtro)->fetchAll(PDO::FETCH_ASSOC);

// ====== NOVAS CONSULTAS PARA METAS ======
$data_hoje = date('Y-m-d');
$mes_atual = date('m');
$ano_atual = date('Y');

// Buscar metas diárias e mensais
$metas_data = [];
$realizado_mensal_total = 0;
$meta_diaria_total = 0;
$meta_mensal_total = 0;

if ($unidade_id !== 'all' && is_numeric($unidade_id)) {
    // Metas da unidade selecionada
    $stmt_metas = $pdo->prepare("
        SELECT operacao_id, meta_diaria, meta_mensal 
        FROM metas_unidade_operacao
        WHERE unidade_id = ?
    ");
    $stmt_metas->execute([$unidade_id]);
    $metas_data = $stmt_metas->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular totais de metas
    foreach ($metas_data as $meta) {
        $meta_diaria_total += (float)$meta['meta_diaria'];
        $meta_mensal_total += (float)$meta['meta_mensal'];
    }
    
    // Realizado mensal total
    $stmt_realizado_mensal = $pdo->prepare("
        SELECT SUM(hectares) as total_hectares
        FROM apontamentos 
        WHERE unidade_id = ?
          AND MONTH(data_hora) = ?
          AND YEAR(data_hora) = ?
    ");
    $stmt_realizado_mensal->execute([$unidade_id, $mes_atual, $ano_atual]);
    $realizado_mensal_result = $stmt_realizado_mensal->fetch(PDO::FETCH_ASSOC);
    $realizado_mensal_total = (float)($realizado_mensal_result['total_hectares'] ?? 0);
} else {
    // Para "Todas as Unidades", somar todas as metas
    $stmt_metas_all = $pdo->query("
        SELECT SUM(meta_diaria) as total_meta_diaria, SUM(meta_mensal) as total_meta_mensal
        FROM metas_unidade_operacao
    ");
    $metas_totais = $stmt_metas_all->fetch(PDO::FETCH_ASSOC);
    $meta_diaria_total = (float)($metas_totais['total_meta_diaria'] ?? 0);
    $meta_mensal_total = (float)($metas_totais['total_meta_mensal'] ?? 0);
    
    // Realizado mensal total para todas as unidades
    $stmt_realizado_mensal_all = $pdo->prepare("
        SELECT SUM(hectares) as total_hectares
        FROM apontamentos 
        WHERE MONTH(data_hora) = ?
          AND YEAR(data_hora) = ?
    ");
    $stmt_realizado_mensal_all->execute([$mes_atual, $ano_atual]);
    $realizado_mensal_result = $stmt_realizado_mensal_all->fetch(PDO::FETCH_ASSOC);
    $realizado_mensal_total = (float)($realizado_mensal_result['total_hectares'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Produção - CTT</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Chart.js DataLabels Plugin -->
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <!-- CSS personalizado -->
    <style>
        :root {


        }
        


               :root {
                            --secondary-color: #27ae60;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-green: #d5f4e6;
            --medium-green: #a3e4c1;
            --dark-green: #145a32;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --primary: #2e7d32;
            --primary-dark: #1b5e20;
            --primary-light: #81c784;
            --secondary: #0288d1;
            --accent: #ffab00;
            --text: #263238;
            --text-light: #eceff1;
            --text-muted: #78909c;
            --bg: #f5f7fa;
            --card: #ffffff;
            --border: #cfd8dc;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.12);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --radius: 8px;
            
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
font-family: 'Poppins', sans-serif;
            background-color: #f8fcf9;
    color: var(--text);
    line-height: 1.6;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
                color: #2c3e50;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 1.5rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(26, 83, 54, 0.3);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(26, 83, 54, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: none;
            border-top: 4px solid var(--success-color);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(26, 83, 54, 0.15);
        }
        
        .stat-card .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--dark-green) 100%);
            color: white;
            font-weight: 600;
            padding: 0.75rem 1rem;
            border-bottom: none;
        }
        
        .stat-card .card-body {
            padding: 1.5rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #5d6d7e;
            font-size: 0.9rem;
        }
        
        .progress-container {
            margin-top: 1rem;
        }
        
        .chart-container {
            position: relative;
            height: 320px;
            margin-bottom: 1rem;
        }
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 8px rgba(26, 83, 54, 0.1);
            border-left: 4px solid var(--success-color);
        }
        
        .operation-section {
            margin-bottom: 2rem;
        }
        
        .operation-title {
            border-left: 5px solid var(--success-color);
            padding-left: 1rem;
            margin-bottom: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .equipment-card {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 6px rgba(26, 83, 54, 0.1);
            border-left: 4px solid var(--success-color);
            transition: all 0.3s;
        }
        
        .equipment-card:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(26, 83, 54, 0.15);
        }
        
        .equipment-name {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
        }
        
        .equipment-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
        }
        
        .comparison-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 8px rgba(26, 83, 54, 0.1);
            border-top: 4px solid var(--success-color);
        }
        
        .best-month {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
        }
        
        .worst-month {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
        }
        
        .month-card {
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1rem;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .month-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .month-label {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .badge-custom {
            font-size: 0.7rem;
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
        }
        
        .nav-tabs {
            border-bottom: 2px solid var(--light-green);
        }
        
        .nav-tabs .nav-link {
            color: var(--primary-color);
            font-weight: 500;
            border: none;
            padding: 0.75rem 1.5rem;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--success-color);
            font-weight: 600;
            border-bottom: 3px solid var(--success-color);
            background: transparent;
        }
        
        .nav-tabs .nav-link:hover {
            border: none;
            border-bottom: 3px solid var(--medium-green);
        }
        
        .tab-content {
            padding-top: 1.5rem;
        }
        
        .hectares-display {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--success-color);
            background: var(--light-green);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            display: inline-block;
            margin-bottom: 0.5rem;
        }
        
        .filter-advanced {
            background: var(--light-green);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            border-left: 3px solid var(--success-color);
        }
        
        .summary-card {
            background: linear-gradient(135deg, #1a5336 0%, #27ae60 100%);
            color: white;
            border-radius: 12px;
            padding: 2.8rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 6px 12px rgba(26, 83, 54, 0.2);
        }
        
        .summary-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .summary-label {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(39, 174, 96, 0.1);
        }
        
        .chart-tooltip {
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .chart-data-label {
            font-weight: 600;
            font-size: 11px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border: none;
            border-radius: 6px;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--dark-green) 0%, var(--primary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(26, 83, 54, 0.3);
        }
        
        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 6px;
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #ced4da;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--success-color);
            box-shadow: 0 0 0 0.2rem rgba(39, 174, 96, 0.25);
        }
        
        .progress {
            border-radius: 10px;
            background-color: #e9ecef;
        }
        
        .progress-bar {
            border-radius: 10px;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--success-color) 100%);
        }
        
        .search-container {
            background: var(--light-green);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--success-color);
        }
        
        .equipment-counter {
            background: var(--primary-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .no-results {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }
        
        .no-results i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #bdc3c7;
        }
        
        @media (max-width: 768px) {
            .stat-value {
                font-size: 1.5rem;
            }
            
            .chart-container {
                height: 250px;
            }
            
            .summary-value {
                font-size: 2rem;
            }
            
            .nav-tabs .nav-link {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
            
            .search-container .input-group {
                flex-direction: column;
            }
            
            .search-container .input-group .form-control,
            .search-container .input-group .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="dashboard-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1><i class="fas fa-seedling me-2"></i>Dashboard de Produção Agrícola</h1>
                    <p class="mb-0">Análise de desempenho da frota e produção por operação</p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="btn-group">
                        <button class="btn btn-light" id="exportBtn">
                            <i class="fas fa-download me-1"></i> Exportar
                        </button>
                        <button class="btn btn-light" id="printBtn">
                            <i class="fas fa-print me-1"></i> Imprimir
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Filtros -->
        <div class="filter-section">
            <form method="GET" action="">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Período</label>
                        <select class="form-select" name="periodo" id="periodFilter">
                            <option value="day" <?= $periodo === 'day' ? 'selected' : '' ?>>Hoje</option>
                            <option value="week" <?= $periodo === 'week' ? 'selected' : '' ?>>Esta Semana</option>
                            <option value="month" <?= $periodo === 'month' ? 'selected' : '' ?>>Este Mês</option>
                            <option value="year" <?= $periodo === 'year' ? 'selected' : '' ?>>Este Ano</option>
                            <option value="custom" <?= $periodo === 'custom' ? 'selected' : '' ?>>Personalizado</option>
                        </select>
                    </div>
                    <div class="col-md-2" id="customDateRange" style="<?= $periodo === 'custom' ? '' : 'display: none;' ?>">
                        <label class="form-label">Data Inicial</label>
                        <input type="date" class="form-control" name="data_inicio" value="<?= $data_inicio ?>">
                    </div>
                    <div class="col-md-2" id="customDateRangeEnd" style="<?= $periodo === 'custom' ? '' : 'display: none;' ?>">
                        <label class="form-label">Data Final</label>
                        <input type="date" class="form-control" name="data_fim" value="<?= $data_fim ?>">
                    </div>
<div class="col-md-2">
    <label class="form-label">Unidade</label>
    <select class="form-select" name="unidade" id="unidadeFilter" <?= (!in_array($tipoSess, $ADMIN_LIKE)) ? 'disabled' : '' ?>>
        <?php 
        // Encontrar a unidade Iguatemi para selecionar por padrão
        $id_iguatemi = null;
        foreach ($unidades as $unidade) {
            if (stripos($unidade['nome'], 'iguatemi') !== false) {
                $id_iguatemi = $unidade['id'];
                break;
            }
        }
        
        // Se não encontrou Iguatemi, usar a unidade do usuário como padrão
        if (!$id_iguatemi && $unidade_usuario) {
            $id_iguatemi = $unidade_usuario;
        }
        
        // Mostrar TODAS as unidades, com Iguatemi selecionada por padrão
        foreach ($unidades as $unidade): ?>
            <option value="<?= $unidade['id'] ?>" 
                <?= ($unidade_id == $unidade['id'] || (!$unidade_id && $unidade['id'] == $id_iguatemi)) ? 'selected' : '' ?>
                <?= (!in_array($tipoSess, $ADMIN_LIKE) && $unidade_id != $unidade['id']) ? 'disabled' : '' ?>>
                <?= htmlspecialchars($unidade['nome']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if (!in_array($tipoSess, $ADMIN_LIKE)): ?>
        <input type="hidden" name="unidade" value="<?= $unidade_id ?>">
    <?php endif; ?>
</div>
                    <div class="col-md-2">
                        <label class="form-label">Operação</label>
                        <select class="form-select" name="operacao">
                            <option value="all">Todas as Operações</option>
                            <?php foreach ($operacoes_filtro as $operacao): ?>
                                <option value="<?= $operacao['id'] ?>" <?= $operacao_id == $operacao['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($operacao['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Equipamento</label>
                        <select class="form-select" name="equipamento">
                            <option value="all">Todos os Equipamentos</option>
                            <?php foreach ($equipamentos_filtro as $equipamento): ?>
                                <option value="<?= $equipamento['id'] ?>" <?= $equipamento_id == $equipamento['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($equipamento['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Filtros Avançados -->
                <div class="filter-advanced" id="advancedFilters" style="display: none;">
                    <h6 class="mb-3"><i class="fas fa-sliders-h me-2"></i>Filtros Avançados</h6>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="all">Todos</option>
                                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ativos</option>
                                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inativos</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Ordenar por</label>
                            <select class="form-select" name="sort">
                                <option value="hectares">Hectares</option>
                                <option value="equipamentos">Equipamentos</option>
                                <option value="apontamentos">Apontamentos</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Direção</label>
                            <select class="form-select" name="order">
                                <option value="desc">Decrescente</option>
                                <option value="asc">Crescente</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="toggleAdvancedFilters">
                            <i class="fas fa-sliders-h me-1"></i> Filtros Avançados
                        </button>
                    </div>
                    <div class="col-md-6 text-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-1"></i> Aplicar Filtros
                        </button>
                        <a href="?" class="btn btn-outline-secondary">
                            <i class="fas fa-redo me-1"></i> Limpar
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Cards de Resumo -->
        <div class="row">
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-value"><?= number_format($totais['total_hectares'] ?? 0, 2, ',', '.') ?> ha</div>
                    <div class="summary-label">Hectares Totais</div>
                    <div class="mt-2">
                        <small><i class="fas fa-arrow-up me-1"></i> Máx: <?= number_format($totais['max_hectares'] ?? 0, 2, ',', '.') ?> ha</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="card-header">
                        <i class="fas fa-bullseye me-2"></i>Meta Diária
                    </div>
                    <div class="card-body">
                        <div class="stat-value text-success"><?= number_format($meta_diaria_total, 2, ',', '.') ?> ha</div>
                        <div class="stat-label">Total de metas diárias</div>
                        <?php
                        $progresso_diario = $meta_diaria_total > 0 ? min(100, ($totais['total_hectares'] / $meta_diaria_total) * 100) : 0;
                        ?>
                        <div class="progress mt-2" style="height: 10px;">
                            <div class="progress-bar" role="progressbar" 
                                 style="width: <?= $progresso_diario ?>%">
                            </div>
                        </div>
                        <small class="text-muted"><?= number_format($progresso_diario, 1) ?>% do realizado</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="card-header">
                        <i class="fas fa-chart-line me-2"></i>Realizado Mensal
                    </div>
                    <div class="card-body">
                        <div class="stat-value text-success"><?= number_format($realizado_mensal_total, 2, ',', '.') ?> ha</div>
                        <div class="stat-label">Total realizado no mês</div>
                        <?php
                        $progresso_mensal = $meta_mensal_total > 0 ? min(100, ($realizado_mensal_total / $meta_mensal_total) * 100) : 0;
                        ?>
                        <div class="progress mt-2" style="height: 10px;">
                            <div class="progress-bar" role="progressbar" 
                                 style="width: <?= $progresso_mensal ?>%">
                            </div>
                        </div>
                        <small class="text-muted"><?= number_format($progresso_mensal, 1) ?>% da meta mensal</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="card-header">
                        <i class="fas fa-bullseye me-2"></i>Meta Mensal
                    </div>
                    <div class="card-body">
                        <div class="stat-value text-success"><?= number_format($meta_mensal_total, 2, ',', '.') ?> ha</div>
                        <div class="stat-label">Total de metas mensais</div>
                        <div class="mt-2">
                            <small class="text-muted"><?= $totais['total_apontamentos'] ?? 0 ?> apontamentos</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navegação por Abas -->
        <ul class="nav nav-tabs" id="dashboardTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">
                    <i class="fas fa-chart-pie me-1"></i> Visão Geral
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="operations-tab" data-bs-toggle="tab" data-bs-target="#operations" type="button" role="tab">
                    <i class="fas fa-tractor me-1"></i> Por Operação
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="equipment-tab" data-bs-toggle="tab" data-bs-target="#equipment" type="button" role="tab">
                    <i class="fas fa-cogs me-1"></i> Por Equipamento
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="comparison-tab" data-bs-toggle="tab" data-bs-target="#comparison" type="button" role="tab">
                    <i class="fas fa-chart-line me-1"></i> Comparativos
                </button>
            </li>
        </ul>

        <div class="tab-content" id="dashboardTabsContent">
            <!-- Aba: Visão Geral -->
            <div class="tab-pane fade show active" id="overview" role="tabpanel">
                <div class="row mt-4">
                    <div class="col-md-8">
                        <div class="stat-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-chart-bar me-2"></i>Produção por Operação (Hectares)</span>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="toggleDataLabels" checked>
                                    <label class="form-check-label" for="toggleDataLabels">Valores nos Gráficos</label>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="productionByOperationChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="card-header">
                                <i class="fas fa-chart-pie me-2"></i>Distribuição por Operação
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="operationDistributionChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="stat-card">
                            <div class="card-header">
                                <i class="fas fa-calendar me-2"></i>Produção Mensal (Hectares)
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="monthlyProductionChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="stat-card">
                            <div class="card-header">
                                <i class="fas fa-trophy me-2"></i>Top 10 Equipamentos (Hectares)
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="topEquipmentChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Aba: Por Operação -->
            <div class="tab-pane fade" id="operations" role="tabpanel">
                <div class="row mt-4">
                    <div class="col-12 mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="operation-title">Desempenho por Operação</h5>
                            <div class="btn-group">
                                <button class="btn btn-outline-primary btn-sm" id="exportOperations">
                                    <i class="fas fa-download me-1"></i> Exportar
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <?php foreach ($operacoes as $operacao): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="stat-card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <span><?= htmlspecialchars($operacao['operacao_nome']) ?></span>
                                    <span class="badge bg-success"><?= $operacao['equipamentos'] ?> equip.</span>
                                </div>
                                <div class="card-body">
                                    <div class="hectares-display"><?= number_format($operacao['total_hectares'], 2, ',', '.') ?> ha</div>
                                    <div class="stat-label">Hectares totais</div>
                                    
                                    <div class="row mt-3">
                                        <div class="col-6">
                                            <small class="text-muted">Apontamentos:</small>
                                            <div class="fw-bold"><?= $operacao['apontamentos'] ?></div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Média:</small>
                                            <div class="fw-bold"><?= number_format($operacao['media_hectares'], 2, ',', '.') ?> ha</div>
                                        </div>
                                    </div>
                                    
                                    <div class="progress mt-2" style="height: 10px;">
                                        <?php
                                        $percentage = $totais['total_hectares'] > 0 ? 
                                            ($operacao['total_hectares'] / $totais['total_hectares']) * 100 : 0;
                                        ?>
                                        <div class="progress-bar" role="progressbar" 
                                             style="width: <?= $percentage ?>%">
                                        </div>
                                    </div>
                                    <small class="text-muted"><?= number_format($percentage, 1) ?>% do total</small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Aba: Por Equipamento -->
            <div class="tab-pane fade" id="equipment" role="tabpanel">
                <div class="row mt-4">
                    <div class="col-12">
                        <!-- Área de Busca -->
                        <div class="search-container">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <div class="input-group">
                                        <input type="text" class="form-control" placeholder="Digite para buscar equipamento..." id="equipmentSearch">
                                        <button class="btn btn-outline-secondary" type="button" id="clearSearch">
                                            <i class="fas fa-times"></i> Limpar
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-4 text-end">
                                    <div class="d-flex justify-content-end align-items-center">
                                        <span class="equipment-counter me-3">
                                            <i class="fas fa-tractor me-1"></i>
                                            <span id="equipmentCount"><?= count($equipamentos) ?></span> equipamentos
                                        </span>
                                        <button class="btn btn-primary btn-sm" id="exportEquipment">
                                            <i class="fas fa-download me-1"></i> Exportar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tabela de Equipamentos -->
                        <div class="stat-card">
                            <div class="card-header">
                                <i class="fas fa-table me-2"></i>Desempenho de Todos os Equipamentos
                            </div>
                            <div class="card-body">
                                <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                                    <table class="table table-hover" id="equipmentTable">
                                        <thead style="position: sticky; top: 0; background: white; z-index: 1;">
                                            <tr>
                                                <th>Equipamento</th>
                                                <th>Operação</th>
                                                <th>Unidade</th>
                                                <th>Total Ha</th>
                                                <th>Apontamentos</th>
                                                <th>Média Ha</th>
                                                <th>Máximo</th>
                                                <th>Mínimo</th>
                                            </tr>
                                        </thead>
                                        <tbody id="equipmentTableBody">
                                            <?php if (count($equipamentos) > 0): ?>
                                                <?php foreach ($equipamentos as $equipamento): ?>
                                                    <tr class="equipment-row">
                                                        <td class="fw-bold"><?= htmlspecialchars($equipamento['equipamento_nome']) ?></td>
                                                        <td><?= htmlspecialchars($equipamento['operacao_nome']) ?></td>
                                                        <td><?= htmlspecialchars($equipamento['unidade_nome']) ?></td>
                                                        <td class="fw-bold text-success"><?= number_format($equipamento['total_hectares'], 2, ',', '.') ?></td>
                                                        <td><?= $equipamento['apontamentos'] ?></td>
                                                        <td><?= number_format($equipamento['media_hectares'], 2, ',', '.') ?></td>
                                                        <td class="text-success"><?= number_format($equipamento['max_hectares'], 2, ',', '.') ?></td>
                                                        <td class="text-danger"><?= number_format($equipamento['min_hectares'], 2, ',', '.') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center py-4">
                                                        <div class="no-results">
                                                            <i class="fas fa-search"></i>
                                                            <h5>Nenhum equipamento encontrado</h5>
                                                            <p>Não há dados de equipamentos para os filtros selecionados.</p>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Contador de resultados -->
                                <div class="mt-3 d-flex justify-content-between align-items-center">
                                    <small class="text-muted" id="resultsCount">
                                        Mostrando <?= count($equipamentos) ?> de <?= count($equipamentos) ?> equipamentos
                                    </small>
                                    <div class="btn-group">
                                        <button class="btn btn-outline-secondary btn-sm" id="sortHectares">
                                            <i class="fas fa-sort-amount-down me-1"></i> Ordenar por Hectares
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Aba: Comparativos -->
            <div class="tab-pane fade" id="comparison" role="tabpanel">
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="comparison-card">
                            <h5 class="mb-3"><i class="fas fa-trophy me-2"></i>Melhor Mês</h5>
                            <?php if ($melhor_mes): ?>
                                <div class="month-card best-month">
                                    <div class="month-value">
                                        <?= number_format($melhor_mes['total_hectares'], 2, ',', '.') ?> ha
                                    </div>
                                    <div class="month-label">
                                        <?= DateTime::createFromFormat('!m', $melhor_mes['mes'])->format('F') ?> de <?= $melhor_mes['ano'] ?>
                                    </div>
                                    <div class="mt-2">
                                        <span class="badge bg-light text-dark">
                                            <?= $melhor_mes['apontamentos'] ?> apontamentos
                                        </span>
                                        <span class="badge bg-light text-dark ms-1">
                                            Média: <?= number_format($melhor_mes['media_hectares'], 2, ',', '.') ?> ha
                                        </span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-chart-line fa-3x mb-2"></i>
                                    <p>Nenhum dado disponível para comparação</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="comparison-card">
                            <h5 class="mb-3"><i class="fas fa-chart-line me-2"></i>Pior Mês</h5>
                            <?php if ($pior_mes): ?>
                                <div class="month-card worst-month">
                                    <div class="month-value">
                                        <?= number_format($pior_mes['total_hectares'], 2, ',', '.') ?> ha
                                    </div>
                                    <div class="month-label">
                                        <?= DateTime::createFromFormat('!m', $pior_mes['mes'])->format('F') ?> de <?= $pior_mes['ano'] ?>
                                    </div>
                                    <div class="mt-2">
                                        <span class="badge bg-light text-dark">
                                            <?= $pior_mes['apontamentos'] ?> apontamentos
                                        </span>
                                        <span class="badge bg-light text-dark ms-1">
                                            Média: <?= number_format($pior_mes['media_hectares'], 2, ',', '.') ?> ha
                                        </span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-chart-line fa-3x mb-2"></i>
                                    <p>Nenhum dado disponível para comparação</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="stat-card">
                            <div class="card-header">
                                <i class="fas fa-chart-line me-2"></i>Evolução Mensal - <?= date('Y') ?>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="monthlyEvolutionChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Registrar o plugin DataLabels globalmente
        Chart.register(ChartDataLabels);
        
        // Mostrar/ocultar campos de data personalizada
        document.getElementById('periodFilter').addEventListener('change', function() {
            const isCustom = this.value === 'custom';
            document.getElementById('customDateRange').style.display = isCustom ? 'block' : 'none';
            document.getElementById('customDateRangeEnd').style.display = isCustom ? 'block' : 'none';
        });

        // Alternar filtros avançados
        document.getElementById('toggleAdvancedFilters').addEventListener('click', function() {
            const advancedFilters = document.getElementById('advancedFilters');
            if (advancedFilters.style.display === 'none') {
                advancedFilters.style.display = 'block';
                this.innerHTML = '<i class="fas fa-sliders-h me-1"></i> Ocultar Filtros Avançados';
            } else {
                advancedFilters.style.display = 'none';
                this.innerHTML = '<i class="fas fa-sliders-h me-1"></i> Filtros Avançados';
            }
        });

        // Busca em tempo real na tabela de equipamentos
        document.getElementById('equipmentSearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#equipmentTableBody .equipment-row');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const isVisible = text.includes(searchTerm);
                row.style.display = isVisible ? '' : 'none';
                
                if (isVisible) {
                    visibleCount++;
                }
            });
            
            // Atualizar contadores
            document.getElementById('equipmentCount').textContent = visibleCount;
            document.getElementById('resultsCount').textContent = `Mostrando ${visibleCount} de ${rows.length} equipamentos`;
            
            // Mostrar mensagem se não houver resultados
            const noResults = document.querySelector('.no-results');
            if (visibleCount === 0 && searchTerm !== '') {
                if (!noResults) {
                    const tbody = document.getElementById('equipmentTableBody');
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <div class="no-results">
                                    <i class="fas fa-search"></i>
                                    <h5>Nenhum equipamento encontrado</h5>
                                    <p>Não foram encontrados equipamentos para "${searchTerm}"</p>
                                </div>
                            </td>
                        </tr>
                    `;
                }
            } else if (visibleCount === 0) {
                if (!noResults) {
                    const tbody = document.getElementById('equipmentTableBody');
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <div class="no-results">
                                    <i class="fas fa-search"></i>
                                    <h5>Nenhum equipamento encontrado</h5>
                                    <p>Não há dados de equipamentos para os filtros selecionados.</p>
                                </div>
                            </td>
                        </tr>
                    `;
                }
            }
        });

        // Limpar busca
        document.getElementById('clearSearch').addEventListener('click', function() {
            const searchInput = document.getElementById('equipmentSearch');
            searchInput.value = '';
            
            // Disparar evento de input para atualizar a tabela
            const event = new Event('input');
            searchInput.dispatchEvent(event);
        });

        // Ordenar por hectares
        document.getElementById('sortHectares').addEventListener('click', function() {
            const tbody = document.getElementById('equipmentTableBody');
            const rows = Array.from(tbody.querySelectorAll('.equipment-row'));
            
            // Verificar a ordem atual
            const isAscending = this.classList.contains('asc');
            
            // Ordenar linhas
            rows.sort((a, b) => {
                const hectaresA = parseFloat(a.cells[3].textContent.replace('.', '').replace(',', '.'));
                const hectaresB = parseFloat(b.cells[3].textContent.replace('.', '').replace(',', '.'));
                
                return isAscending ? hectaresB - hectaresA : hectaresA - hectaresB;
            });
            
            // Alternar classe e ícone
            this.classList.toggle('asc');
            const icon = this.querySelector('i');
            if (this.classList.contains('asc')) {
                icon.className = 'fas fa-sort-amount-up me-1';
                this.innerHTML = '<i class="fas fa-sort-amount-up me-1"></i> Ordenar por Hectares';
            } else {
                icon.className = 'fas fa-sort-amount-down me-1';
                this.innerHTML = '<i class="fas fa-sort-amount-down me-1"></i> Ordenar por Hectares';
            }
            
            // Reinserir linhas ordenadas
            rows.forEach(row => tbody.appendChild(row));
        });

        // Botão de impressão
        document.getElementById('printBtn').addEventListener('click', function() {
            window.print();
        });

        // Dados para os gráficos
        const operationData = {
            labels: [<?= implode(',', array_map(function($op) { return "'" . addslashes($op['operacao_nome']) . "'"; }, $operacoes)) ?>],
            hectares: [<?= implode(',', array_column($operacoes, 'total_hectares')) ?>],
            apontamentos: [<?= implode(',', array_column($operacoes, 'apontamentos')) ?>]
        };

        const monthlyData = {
            labels: [<?= implode(',', array_map(function($mes) { 
                return "'" . DateTime::createFromFormat('!m', $mes['mes'])->format('M') . "'"; 
            }, $dados_mensais)) ?>],
            hectares: [<?= implode(',', array_column($dados_mensais, 'total_hectares')) ?>],
            apontamentos: [<?= implode(',', array_column($dados_mensais, 'apontamentos')) ?>]
        };

        const topEquipmentData = {
            labels: [<?= implode(',', array_map(function($eq, $index) { 
                return $index < 10 ? "'" . addslashes($eq['equipamento_nome']) . "'" : ''; 
            }, $equipamentos, array_keys($equipamentos))) ?>].filter(Boolean),
            hectares: [<?= implode(',', array_map(function($eq, $index) { 
                return $index < 10 ? $eq['total_hectares'] : ''; 
            }, $equipamentos, array_keys($equipamentos))) ?>].filter(Boolean)
        };

        // Configuração comum para os gráficos com valores visíveis
        const dataLabelsConfig = {
            display: true,
            color: '#fff',
            font: {
                weight: 'bold',
                size: 11
            },
            formatter: function(value, context) {
                return value.toLocaleString('pt-BR', {minimumFractionDigits: 1, maximumFractionDigits: 1});
            }
        };

        // Gráfico: Produção por Operação
        const productionChart = new Chart(document.getElementById('productionByOperationChart'), {
            type: 'bar',
            data: {
                labels: operationData.labels,
                datasets: [{
                    label: 'Hectares',
                    data: operationData.hectares,
                    backgroundColor: [
                        '#1a5336', '#27ae60', '#2ecc71', '#58d68d', '#82e5aa',
                        '#abf0c6', '#145a32', '#0e3d22', '#52b788', '#40916c'
                    ],
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: 'Produção por Tipo de Operação (Hectares)',
                        font: {
                            size: 16
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Hectares: ${context.raw.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                            }
                        }
                    },
                    datalabels: dataLabelsConfig
                },
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
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Gráfico: Distribuição por Operação
        const distributionChart = new Chart(document.getElementById('operationDistributionChart'), {
            type: 'doughnut',
            data: {
                labels: operationData.labels,
                datasets: [{
                    data: operationData.hectares,
                    backgroundColor: [
                        '#1a5336', '#27ae60', '#2ecc71', '#58d68d', '#82e5aa',
                        '#abf0c6', '#145a32', '#0e3d22', '#52b788', '#40916c'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    title: {
                        display: true,
                        text: 'Distribuição por Operação',
                        font: {
                            size: 16
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.raw / total) * 100).toFixed(1);
                                return `${context.label}: ${context.raw.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2})} ha (${percentage}%)`;
                            }
                        }
                    },
                    datalabels: {
                        display: true,
                        color: '#fff',
                        font: {
                            weight: 'bold',
                            size: 11
                        },
                        formatter: function(value, context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${percentage}%`;
                        }
                    }
                }
            }
        });

        // Gráfico: Produção Mensal
        const monthlyChart = new Chart(document.getElementById('monthlyProductionChart'), {
            type: 'line',
            data: {
                labels: monthlyData.labels,
                datasets: [{
                    label: 'Hectares',
                    data: monthlyData.hectares,
                    borderColor: '#27ae60',
                    backgroundColor: 'rgba(39, 174, 96, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#1a5336',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 6
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Evolução da Produção Mensal (Hectares)',
                        font: {
                            size: 16
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Hectares: ${context.raw.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                            }
                        }
                    },
                    datalabels: {
                        display: true,
                        align: 'top',
                        anchor: 'end',
                        color: '#1a5336',
                        font: {
                            weight: 'bold',
                            size: 10
                        },
                        formatter: function(value) {
                            return value.toLocaleString('pt-BR', {minimumFractionDigits: 1, maximumFractionDigits: 1});
                        }
                    }
                },
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
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Gráfico: Melhores Equipamentos
        const equipmentChart = new Chart(document.getElementById('topEquipmentChart'), {
            type: 'bar',
            data: {
                labels: topEquipmentData.labels,
                datasets: [{
                    label: 'Hectares',
                    data: topEquipmentData.hectares,
                    backgroundColor: '#27ae60',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: 'Top 10 Equipamentos por Produção (Hectares)',
                        font: {
                            size: 16
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Hectares: ${context.raw.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                            }
                        }
                    },
                    datalabels: dataLabelsConfig
                },
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
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Gráfico: Evolução Mensal (Comparativo)
        new Chart(document.getElementById('monthlyEvolutionChart'), {
            type: 'line',
            data: {
                labels: monthlyData.labels,
                datasets: [
                    {
                        label: 'Hectares',
                        data: monthlyData.hectares,
                        borderColor: '#27ae60',
                        backgroundColor: 'rgba(39, 174, 96, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#1a5336',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 6
                    },
                    {
                        label: 'Apontamentos',
                        data: monthlyData.apontamentos,
                        borderColor: '#e74c3c',
                        backgroundColor: 'rgba(231, 76, 60, 0.1)',
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y1',
                        pointBackgroundColor: '#c0392b',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Comparativo: Hectares vs Apontamentos',
                        font: {
                            size: 16
                        }
                    },
                    datalabels: {
                        display: false // Desativado para este gráfico por ser muito poluído
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Hectares'
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.1)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Apontamentos'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });

        // Botão de exportação
        document.getElementById('exportBtn').addEventListener('click', function() {
            // Simular exportação
            const tab = document.querySelector('.nav-link.active').textContent.trim();
            alert(`Exportando dados da aba: ${tab}`);
        });

        // Alternar valores nos gráficos
        document.getElementById('toggleDataLabels').addEventListener('change', function() {
            const isChecked = this.checked;
            
            // Atualizar todos os gráficos
            productionChart.options.plugins.datalabels.display = isChecked;
            distributionChart.options.plugins.datalabels.display = isChecked;
            monthlyChart.options.plugins.datalabels.display = isChecked;
            equipmentChart.options.plugins.datalabels.display = isChecked;
            
            productionChart.update();
            distributionChart.update();
            monthlyChart.update();
            equipmentChart.update();
        });

        // Exportar dados de equipamentos
        document.getElementById('exportEquipment').addEventListener('click', function() {
            alert('Exportando dados de equipamentos para Excel...');
        });

        // Exportar dados de operações
        document.getElementById('exportOperations').addEventListener('click', function() {
            alert('Exportando dados de operações para Excel...');
        });
    </script>
</body>
</html>