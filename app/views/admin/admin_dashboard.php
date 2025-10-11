<?php
session_start();
require_once(__DIR__ . '/../../../config/db/conexao.php');
// "Hoje" no fuso de Brasília (para consultas iniciais)
$today_br = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');

$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
    header("Location: /");
    exit();
}

require_once __DIR__.'/../../../app/lib/Audit.php';

// Configurações do sistema de exportação
$clusters = [
    'NORTE' => ['IGUATEMI', 'PARANACITY', 'TERRA RICA'],
    'CENTRO' => ['RONDON', 'CIDADE GAUCHA', 'IVATE'],
    'SUL' => ['TAPEJARA', 'MOREIRA SALES']
];

$unidade_abreviacoes = [
    'IGUATEMI' => 'IGT',
    'PARANACITY' => 'PCT',
    'TERRA RICA' => 'TRC',
    'RONDON' => 'RDN',
    'CIDADE GAUCHA' => 'CGA',
    'IVATE' => 'IVA',
    'TAPEJARA' => 'TAP',
    'MOREIRA SALES' => 'MOS'
];

// Funções do sistema de exportação
function get_cluster_for_unidade($unidade_nome, $clusters) {
    $unidade_upper = strtoupper(trim($unidade_nome));
    foreach ($clusters as $cluster => $unidades) {
        foreach ($unidades as $unidade) {
            if (strpos($unidade_upper, $unidade) !== false) {
                return $cluster;
            }
        }
    }
    return 'OUTROS';
}

function formatar_nome_operacao($nome_operacao) {
    $nome_formatado = strtoupper(str_replace(' ', '', $nome_operacao));
    $substituicoes = ['Ç' => 'C', 'Ã' => 'A', 'Õ' => 'O', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U'];
    return str_replace(array_keys($substituicoes), array_values($substituicoes), $nome_formatado);
}

function formatar_nome_cluster($nome_cluster) {
    $abreviacoes_cluster = ['NORTE' => 'NORTE', 'CENTRO' => 'CENTRO', 'SUL' => 'SUL'];
    return $abreviacoes_cluster[$nome_cluster] ?? $nome_cluster;
}

// Processar exportação manual
if (isset($_POST['export_manual'])) {
    $export_type = $_POST['export_type'] ?? 'apontamentos';
    $date_type = $_POST['date_type'] ?? 'hoje';
    
    // Determinar datas
    if ($date_type === 'hoje') {
        $data_inicio = $data_fim = date('Y-m-d');
    } elseif ($date_type === 'ontem') {
        $data_inicio = $data_fim = date('Y-m-d', strtotime('-1 day'));
    } elseif ($date_type === 'intervalo') {
        $data_inicio = $_POST['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        $data_fim = $_POST['end_date'] ?? date('Y-m-d');
    } elseif ($date_type === 'data_especifica') {
        $data_inicio = $data_fim = $_POST['specific_date'] ?? date('Y-m-d');
    }
    
    // Primeiro exportar metas
    exportarMetasPPT($pdo);
    
    // Exportar dados principais
    $dados = obterDadosExportacao($pdo, $data_inicio, $data_fim, $clusters, $unidade_abreviacoes);
    
    if (!empty($dados)) {
        $filename = "export_manual_" . date('Ymd_His') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, array_keys($dados[0]), ';');
        
        foreach ($dados as $row) {
            fputcsv($output, $row, ';');
        }
        fclose($output);
        exit;
    }
}

// Processar exportação mensal
if (isset($_POST['export_mensal'])) {
    $mes = intval($_POST['mes']);
    $ano = intval($_POST['ano']);

    $primeiro_dia = new DateTime(sprintf('%04d-%02d-01', $ano, $mes));
    $ultimo_dia = clone $primeiro_dia;
    $ultimo_dia->modify('last day of this month');

    $data_inicio = $primeiro_dia->format('Y-m-d');
    $data_fim = $ultimo_dia->format('Y-m-d');

    // Primeiro exportar metas
    exportarMetasPPT($pdo);
    
    // Obter dados do mês
    $dados = obterDadosExportacao($pdo, $data_inicio, $data_fim, $clusters, $unidade_abreviacoes);

    if (!empty($dados)) {
        // Agrupar por cluster e operação
        $grouped = [];
        foreach ($dados as $row) {
            $key = $row['cluster'] . '|' . $row['Operação'];
            $grouped[$key][] = $row;
        }

        $zip = new ZipArchive();
        $zip_filename = "export_mensal_{$ano}_{$mes}.zip";

        if ($zip->open($zip_filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            // Adicionar arquivo de metas
            $metas_csv = gerarCSVMetas($pdo);
            $zip->addFromString('metasppt.csv', $metas_csv);

            // Adicionar arquivos agrupados
            $colunas_finais = ['Unidade', 'Frente', 'Hora', 'Data', 'Trator', 'Implemento', 'Produção', 'Cód. Fazenda', 'Fazenda', 'Operação'];
            
            foreach ($grouped as $key => $group_data) {
                list($cluster, $operacao) = explode('|', $key);
                $nome_operacao = formatar_nome_operacao($operacao);
                $nome_cluster = formatar_nome_cluster($cluster);
                $nome_arquivo = "{$nome_cluster}{$nome_operacao}.csv";

                $csv_content = gerarCSV($group_data, $colunas_finais);
                $zip->addFromString($nome_arquivo, $csv_content);
            }

            $zip->close();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
            header('Content-Length: ' . filesize($zip_filename));
            readfile($zip_filename);
            unlink($zip_filename);
            exit;
        }
    }
}

// Funções auxiliares
function exportarMetasPPT($pdo) {
    $sql_metas = "SELECT 
                    u.nome AS unidade_nome,
                    t.nome AS operacao_nome,
                    m.meta_diaria,
                    m.meta_mensal
                FROM metas_unidade_operacao m
                JOIN unidades u ON m.unidade_id = u.id
                JOIN tipos_operacao t ON m.operacao_id = t.id
                ORDER BY u.nome, t.nome";
    
    $stmt_metas = $pdo->query($sql_metas);
    $metas = $stmt_metas->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($metas)) {
        $output_dir = 'exports';
        if (!file_exists($output_dir)) {
            mkdir($output_dir, 0777, true);
        }
        
        $filepath = $output_dir . '/metasppt.csv';
        $fp = fopen($filepath, 'w');
        fputcsv($fp, ['Unidade', 'Operacao', 'Meta_Diaria', 'Meta_Mensal'], ';');
        
        foreach ($metas as $meta) {
            $linha = [
                $meta['unidade_nome'],
                $meta['operacao_nome'],
                number_format((float)$meta['meta_diaria'], 2, ',', ''),
                number_format((float)$meta['meta_mensal'], 2, ',', '')
            ];
            fputcsv($fp, $linha, ';');
        }
        
        fclose($fp);
    }
}

function obterDadosExportacao($pdo, $data_inicio, $data_fim, $clusters, $abreviacoes) {
    $sql = "SELECT
                DATE(a.data_hora) as data_apontamento,
                a.hora_selecionada,
                u.nome AS unidade,
                us.nome AS usuario,
                t.nome AS operacao,
                eq.nome AS equipamento,
                i.nome AS implemento_nome,
                i.numero_identificacao,
                f.nome AS nome_fazenda,
                f.codigo_fazenda,
                a.hectares,
                a.data_hora as timestamp_original
            FROM apontamentos a
            JOIN unidades u ON a.unidade_id = u.id
            JOIN usuarios us ON a.usuario_id = us.id
            JOIN tipos_operacao t ON a.operacao_id = t.id
            JOIN equipamentos eq ON a.equipamento_id = eq.id
            LEFT JOIN implementos i ON eq.implemento_id = i.id
            JOIN fazendas f ON a.fazenda_id = f.id
            WHERE DATE(a.data_hora) BETWEEN ? AND ?
            ORDER BY a.data_hora DESC, u.nome, t.nome";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data_inicio, $data_fim]);
    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return processarDataframe($dados, $clusters, $abreviacoes);
}

function processarDataframe($dados, $clusters, $abreviacoes) {
    $resultado = [];
    
    foreach ($dados as $linha) {
        // Determinar cluster
        $cluster = get_cluster_for_unidade($linha['unidade'], $clusters);
        
        // Aplicar abreviação
        $unidade_upper = strtoupper(trim($linha['unidade']));
        $unidade_abreviada = $abreviacoes[$unidade_upper] ?? $linha['unidade'];
        
        // Formatar dados
        $data_formatada = date('d/m/Y', strtotime($linha['data_apontamento']));
        $hora_formatada = $linha['hora_selecionada'] ?: date('H:i:s', strtotime($linha['timestamp_original']));
        $producao_formatada = number_format($linha['hectares'], 2, ',', '');
        
        $resultado[] = [
            'Unidade' => $unidade_abreviada,
            'Frente' => 'F1',
            'Hora' => $hora_formatada,
            'Data' => $data_formatada,
            'Trator' => $linha['equipamento'],
            'Implemento' => $linha['numero_identificacao'] ?? '',
            'Produção' => $producao_formatada,
            'Cód. Fazenda' => $linha['codigo_fazenda'],
            'Fazenda' => '', // Campo vazio conforme especificado
            'Operação' => $linha['operacao'],
            'cluster' => $cluster
        ];
    }
    
    return $resultado;
}

function gerarCSVMetas($pdo) {
    $sql_metas = "SELECT 
                    u.nome AS unidade_nome,
                    t.nome AS operacao_nome,
                    m.meta_diaria,
                    m.meta_mensal
                FROM metas_unidade_operacao m
                JOIN unidades u ON m.unidade_id = u.id
                JOIN tipos_operacao t ON m.operacao_id = t.id
                ORDER BY u.nome, t.nome";
    
    $stmt_metas = $pdo->query($sql_metas);
    $metas = $stmt_metas->fetchAll(PDO::FETCH_ASSOC);
    
    $output = fopen('php://temp', 'w+');
    fputcsv($output, ['Unidade', 'Operacao', 'Meta_Diaria', 'Meta_Mensal'], ';');
    
    foreach ($metas as $meta) {
        $linha = [
            $meta['unidade_nome'],
            $meta['operacao_nome'],
            number_format((float)$meta['meta_diaria'], 2, ',', ''),
            number_format((float)$meta['meta_mensal'], 2, ',', '')
        ];
        fputcsv($output, $linha, ';');
    }
    
    rewind($output);
    $content = stream_get_contents($output);
    fclose($output);
    
    return $content;
}

function gerarCSV($dados, $colunas) {
    $output = fopen('php://temp', 'w+');
    fputcsv($output, $colunas, ';');
    
    foreach ($dados as $row) {
        $line = [];
        foreach ($colunas as $coluna) {
            $line[] = $row[$coluna] ?? '';
        }
        fputcsv($output, $line, ';');
    }
    
    rewind($output);
    $content = stream_get_contents($output);
    fclose($output);
    
    return $content;
}

// AJAX para dados da dashboard
if (isset($_GET['ajax_data'])) {
    header('Content-Type: application/json; charset=UTF-8');

    $today_br = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');

    $date_filter        = $_GET['date'] ?? $today_br;
    $unidade_filter     = $_GET['unidade_id'] ?? null;
    $operacao_filter    = $_GET['operacao_id'] ?? null;
    $equipamento_filter = $_GET['equipamento_id'] ?? null;
    $implemento_filter  = $_GET['implemento_id'] ?? null;
    $type               = $_GET['type'] ?? 'table';

    if ($type === 'table') {
        $sql = "SELECT 
                    a.data_hora,
                    a.hora_selecionada,
                    DATE(a.data_hora) AS data_brasilia,
                    DATE_FORMAT(a.data_hora, '%H:%i') AS hora_brasilia,
                    a.hectares,
                    u.nome  AS unidade,
                    us.nome AS usuario,
                    t.nome  AS operacao,
                    eq.nome AS equipamento,
                    i.nome  AS implemento_nome,
                    i.numero_identificacao,
                    f.nome  AS nome_fazenda,
                    f.codigo_fazenda
                FROM apontamentos a
                JOIN unidades u         ON a.unidade_id     = u.id
                JOIN usuarios us        ON a.usuario_id     = us.id
                JOIN tipos_operacao t   ON a.operacao_id    = t.id
                JOIN equipamentos eq    ON a.equipamento_id = eq.id
                LEFT JOIN implementos i ON eq.implemento_id = i.id
                JOIN fazendas f         ON a.fazenda_id     = f.id
                WHERE DATE(a.data_hora) = :date_filter";

        if ($unidade_filter)     { $sql .= " AND a.unidade_id = :unidade_id"; }
        if ($operacao_filter)    { $sql .= " AND a.operacao_id = :operacao_id"; }
        if ($equipamento_filter) { $sql .= " AND a.equipamento_id = :equipamento_id"; }
        if ($implemento_filter)  { $sql .= " AND eq.implemento_id = :implemento_id"; }

        $sql .= " ORDER BY a.data_hora DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':date_filter', $date_filter);
        if ($unidade_filter)     { $stmt->bindParam(':unidade_id', $unidade_filter); }
        if ($operacao_filter)    { $stmt->bindParam(':operacao_id', $operacao_filter); }
        if ($equipamento_filter) { $stmt->bindParam(':equipamento_id', $equipamento_filter); }
        if ($implemento_filter)  { $stmt->bindParam(':implemento_id', $implemento_filter); }
        $stmt->execute();

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    } elseif ($type === 'chart') {
        $sql = "SELECT 
                    eq.nome AS equipamento_nome, 
                    SUM(a.hectares) AS total_hectares
                FROM apontamentos a
                JOIN equipamentos eq ON a.equipamento_id = eq.id
                LEFT JOIN implementos i ON eq.implemento_id = i.id
                WHERE DATE(a.data_hora) = :date_filter";

        if ($unidade_filter)     { $sql .= " AND a.unidade_id = :unidade_id"; }
        if ($operacao_filter)    { $sql .= " AND a.operacao_id = :operacao_id"; }
        if ($equipamento_filter) { $sql .= " AND a.equipamento_id = :equipamento_id"; }
        if ($implemento_filter)  { $sql .= " AND eq.implemento_id = :implemento_id"; }

        $sql .= " GROUP BY eq.nome";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':date_filter', $date_filter);
        if ($unidade_filter)     { $stmt->bindParam(':unidade_id', $unidade_filter); }
        if ($operacao_filter)    { $stmt->bindParam(':operacao_id', $operacao_filter); }
        if ($equipamento_filter) { $stmt->bindParam(':equipamento_id', $equipamento_filter); }
        if ($implemento_filter)  { $stmt->bindParam(':implemento_id', $implemento_filter); }
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $chart_data = array_map(function($row) {
            $row['total_hectares'] = (float)$row['total_hectares'];
            return $row;
        }, $results);

        echo json_encode($chart_data);
        exit;
    } else {
        echo json_encode([]);
        exit;
    }
}

require_once __DIR__ . '/../../../app/includes/header.php';

// Consultas para popular os filtros
$unidades_list = $pdo->query("SELECT id, nome FROM unidades ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$operacoes_list = $pdo->query("SELECT id, nome FROM tipos_operacao ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$equipamentos_list = $pdo->query("SELECT id, nome FROM equipamentos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$implementos_list = $pdo->query("SELECT id, nome, numero_identificacao FROM implementos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Define a primeira unidade e operação para os gráficos
$default_unidade_id = !empty($unidades_list) ? $unidades_list[0]['id'] : null;
$default_operacao_id = !empty($operacoes_list) ? $operacoes_list[0]['id'] : null;

// Consulta inicial para a tabela (com "Todas" por padrão)
$sql_apontamentos = "SELECT 
                        a.data_hora,
                        a.hora_selecionada,
                        DATE(a.data_hora) AS data_brasilia,
                        DATE_FORMAT(a.data_hora, '%H:%i') AS hora_brasilia,
                        a.hectares,
                        u.nome  AS unidade,
                        us.nome AS usuario,
                        t.nome  AS operacao, 
                        eq.nome AS equipamento,
                        i.nome  AS implemento_nome,
                        i.numero_identificacao,
                        f.nome  AS nome_fazenda,
                        f.codigo_fazenda
                    FROM apontamentos a
                    JOIN unidades u         ON a.unidade_id     = u.id
                    JOIN usuarios us        ON a.usuario_id     = us.id
                    JOIN tipos_operacao t   ON a.operacao_id    = t.id
                    JOIN equipamentos eq    ON a.equipamento_id = eq.id
                    LEFT JOIN implementos i ON eq.implemento_id = i.id
                    JOIN fazendas f         ON a.fazenda_id     = f.id
                    WHERE DATE(a.data_hora) = :today_br
                    ORDER BY a.data_hora DESC";
$stmt_apontamentos = $pdo->prepare($sql_apontamentos);
$stmt_apontamentos->bindValue(':today_br', $today_br);
$stmt_apontamentos->execute();
$apontamentos = $stmt_apontamentos->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrativo - AgroDash Export</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="/public/static/css/admin.css">
    <link rel="stylesheet" href="/public/static/css/admin_dashboard.css">
    <link rel="icon" type="image/x-icon" href="./public/static/favicon.ico">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">
    <style>
        .export-section {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .export-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 2px solid #e9ecef;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .export-box h3 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            font-size: 16px;
        }
        
        .export-controls {
            margin: 15px 0;
        }
        
        .export-controls .form-group {
            margin-bottom: 12px;
        }
        
        .export-controls label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
        }
        
        .export-controls select,
        .export-controls input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .date-controls {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 4px;
            margin-top: 10px;
            border: 1px solid #e9ecef;
        }
        
        .btn-export {
            background: #27ae60;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            width: 100%;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .btn-export:hover {
            background: #219a52;
        }
        
        .btn-export-accent {
            background: #e67e22;
        }
        
        .btn-export-accent:hover {
            background: #d35400;
        }
        
        .btn-export-error {
            background: #e74c3c;
        }
        
        .btn-export-error:hover {
            background: #c0392b;
        }
        
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-connected { background: #27ae60; }
        .status-disconnected { background: #e74c3c; }
        .status-running { background: #f39c12; }
        .status-stopped { background: #7f8c8d; }
        
        .system-status {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 4px solid #3498db;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .status-item {
            display: flex;
            align-items: center;
            margin: 8px 0;
            font-size: 14px;
        }
        
        .status-label {
            font-weight: 600;
            margin-right: 10px;
            min-width: 180px;
        }
    </style>
</head>
<body>

<h2>Dashboard Geral - Sistema de Exportação AgroDash</h2>

<!-- Status do Sistema -->
<div class="system-status">
    <h3 style="margin-top: 0; color: #2c3e50;">
        <i class="fas fa-server"></i> Status do Sistema
    </h3>
    <div class="status-item">
        <span class="status-label">Conexão com Banco de Dados:</span>
        <span class="status-indicator status-connected"></span>
        <span>Conectado</span>
    </div>
    <div class="status-item">
        <span class="status-label">Exportação Automática:</span>
        <span class="status-indicator status-stopped"></span>
        <span>Parada</span>
    </div>
    <div class="status-item">
        <span class="status-label">Próxima Exportação:</span>
        <span>-</span>
    </div>
</div>

<div class="card">
    <div class="tab-navigation">
        <button class="tab-button active" onclick="openTab(event, 'apontamentos')">Apontamentos do Dia</button>
        <button class="tab-button" onclick="openTab(event, 'graficos')">Gráficos de Produção</button>
        <button class="tab-button" onclick="openTab(event, 'exportacao')">Sistema de Exportação</button>
        <button class="tab-button" onclick="openTab(event, 'relatorios')">Relatórios</button>
    </div>

    <div id="apontamentos" class="tab-content active">
        <div class="filters-group">
            <div class="filter-item">
                <label for="table-date-filter">Data:</label>
                <input type="date" id="table-date-filter" value="<?php echo htmlspecialchars($today_br); ?>">
            </div>
            <div class="filter-item">
                <label for="table-unidade-filter">Unidade:</label>
                <select id="table-unidade-filter">
                    <option value="">Todas</option>
                    <?php foreach ($unidades_list as $unidade): ?>
                        <option value="<?php echo $unidade['id']; ?>"><?php echo htmlspecialchars($unidade['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <label for="table-operacao-filter">Operação:</label>
                <select id="table-operacao-filter">
                    <option value="">Todas</option>
                    <?php foreach ($operacoes_list as $operacao): ?>
                        <option value="<?php echo $operacao['id']; ?>"><?php echo htmlspecialchars($operacao['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <label for="table-equipamento-filter">Equipamento:</label>
                <select id="table-equipamento-filter">
                    <option value="">Todos</option>
                    <?php foreach ($equipamentos_list as $equipamento): ?>
                        <option value="<?php echo $equipamento['id']; ?>"><?php echo htmlspecialchars($equipamento['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <label for="table-implemento-filter">Implemento:</label>
                <select id="table-implemento-filter">
                    <option value="">Todos</option>
                    <?php foreach ($implementos_list as $implemento): ?>
                        <option value="<?php echo $implemento['id']; ?>">
                            <?php echo htmlspecialchars($implemento['nome']); ?> 
                            (<?php echo htmlspecialchars($implemento['numero_identificacao']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="button-excel" id="export-excel-btn">Exportar para Excel</button>
        </div>
        <div class="table-container">
            <table id="apontamentos-table">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Unidade</th>
                        <th>Usuário</th>
                        <th>Equipamento</th>
                        <th>Implemento</th>
                        <th>Hectares</th>
                        <th>Código Fazenda</th>
                        <th>Nome Fazenda</th>
                        <th>Operação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apontamentos as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['hora_selecionada'] ?: $row['hora_brasilia']) ?></td>
                            <td><?php echo htmlspecialchars($row['unidade']); ?></td>
                            <td><?php echo htmlspecialchars($row['usuario']); ?></td>
                            <td><?php echo htmlspecialchars($row['equipamento']); ?></td>
                            <td>
                                <?php if (!empty($row['implemento_nome'])): ?>
                                    <?php echo htmlspecialchars($row['implemento_nome']); ?>
                                    (<?php echo htmlspecialchars($row['numero_identificacao']); ?>)
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format($row['hectares'], 2, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars($row['codigo_fazenda']); ?></td>
                            <td><?php echo htmlspecialchars($row['nome_fazenda']); ?></td>
                            <td><?php echo htmlspecialchars($row['operacao']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="graficos" class="tab-content">
        <div class="filters-group">
            <div class="filter-item">
                <label for="chart-date-filter">Data:</label>
                <input type="date" id="chart-date-filter" value="<?php echo htmlspecialchars($today_br); ?>">
            </div>
            <div class="filter-item">
                <label for="chart-unidade-filter">Unidade:</label>
                <select id="chart-unidade-filter">
                    <?php foreach ($unidades_list as $unidade): ?>
                        <option value="<?php echo $unidade['id']; ?>" <?php echo ($unidade['id'] == $default_unidade_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($unidade['nome']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <label for="chart-operacao-filter">Operação:</label>
                <select id="chart-operacao-filter">
                    <?php foreach ($operacoes_list as $operacao): ?>
                        <option value="<?php echo $operacao['id']; ?>" <?php echo ($operacao['id'] == $default_operacao_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($operacao['nome']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <label for="chart-equipamento-filter">Equipamento:</label>
                <select id="chart-equipamento-filter">
                    <option value="">Todos</option>
                    <?php foreach ($equipamentos_list as $equipamento): ?>
                        <option value="<?php echo $equipamento['id']; ?>"><?php echo htmlspecialchars($equipamento['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <label for="chart-implemento-filter">Implemento:</label>
                <select id="chart-implemento-filter">
                    <option value="">Todos</option>
                    <?php foreach ($implementos_list as $implemento): ?>
                        <option value="<?php echo $implemento['id']; ?>">
                            <?php echo htmlspecialchars($implemento['nome']); ?> 
                            (<?php echo htmlspecialchars($implemento['numero_identificacao']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="chart-container" style="height: 400px;">
            <canvas id="productionChart"></canvas>
        </div>
    </div>

    <div id="exportacao" class="tab-content">
        <div class="export-section">
            <!-- Exportação Manual -->
            <div class="export-box">
                <h3><i class="fas fa-download"></i> Exportação Manual</h3>
                <form method="POST" id="form-export-manual">
                    <div class="export-controls">
                        <div class="form-group">
                            <label for="export-type">Tipo de Dados:</label>
                            <select id="export-type" name="export_type" class="form-control">
                                <option value="apontamentos">Apontamentos</option>
                                <option value="equipamentos">Equipamentos</option>
                                <option value="resumo">Resumo</option>
                                <option value="implementos">Implementos</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="date-type">Período:</label>
                            <select id="date-type" name="date_type" class="form-control" onchange="toggleDateControls()">
                                <option value="hoje">Hoje</option>
                                <option value="ontem">Ontem</option>
                                <option value="intervalo">Intervalo</option>
                                <option value="data_especifica">Data Específica</option>
                            </select>
                        </div>
                        <div id="date-controls" class="date-controls">
                            <!-- Controles de data serão inseridos aqui via JavaScript -->
                        </div>
                    </div>
                    <button type="submit" name="export_manual" class="btn-export">
                        <i class="fas fa-file-export"></i> Exportar Agora
                    </button>
                </form>
            </div>

            <!-- Exportação Mensal -->
            <div class="export-box">
                <h3><i class="fas fa-calendar-alt"></i> Exportação Mensal</h3>
                <form method="POST" id="form-export-mensal">
                    <div class="export-controls">
                        <div class="form-group">
                            <label for="mes">Mês:</label>
                            <select id="mes" name="mes" class="form-control">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo ($m == date('n')) ? 'selected' : ''; ?>>
                                        <?php echo DateTime::createFromFormat('!m', $m)->format('F'); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="ano">Ano:</label>
                            <input type="number" id="ano" name="ano" value="<?php echo date('Y'); ?>" min="2020" max="2030" class="form-control">
                        </div>
                    </div>
                    <button type="submit" name="export_mensal" class="btn-export">
                        <i class="fas fa-file-archive"></i> Exportar Mês Completo (ZIP)
                    </button>
                </form>
            </div>

            <!-- Exportação Automática -->
            <div class="export-box">
                <h3><i class="fas fa-robot"></i> Exportação Automática</h3>
                <div class="export-controls">
                    <div class="form-group">
                        <label for="interval-minutos">Intervalo (minutos):</label>
                        <input type="number" id="interval-minutos" value="40" min="1" max="1440" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="auto-export-status">Status:</label>
                        <div style="display: flex; align-items: center;">
                            <span class="status-indicator status-stopped"></span>
                            <span>Parada</span>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-export btn-export-accent" onclick="toggleAutoExport()" id="auto-export-btn">
                    <i class="fas fa-play"></i> Iniciar Exportação Automática
                </button>
            </div>
        </div>
        
        <div style="background: white; padding: 20px; border-radius: 8px; margin-top: 20px;">
            <h4><i class="fas fa-info-circle"></i> Informações do Sistema</h4>
            <p><strong>Organização dos Arquivos:</strong> Os dados são organizados por cluster (NORTE, CENTRO, SUL) e operação, seguindo o padrão do sistema AgroDash.</p>
            <p><strong>Formato:</strong> CSV com separador ponto-e-vírgula, codificação UTF-8</p>
            <p><strong>Metas:</strong> O arquivo 'metasppt.csv' é gerado automaticamente em todas as exportações</p>
        </div>
    </div>

    <div id="relatorios" class="tab-content">
        <h3>Relatórios e Estatísticas</h3>
        <p>Funcionalidade de relatórios detalhados em desenvolvimento...</p>
        
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 20px;">
            <h4>Estatísticas Rápidas</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div style="background: white; padding: 15px; border-radius: 5px; text-align: center;">
                    <h5 style="margin: 0; color: #7f8c8d;">Total de Exportações</h5>
                    <p style="font-size: 24px; font-weight: bold; color: #2c3e50; margin: 10px 0;">0</p>
                </div>
                <div style="background: white; padding: 15px; border-radius: 5px; text-align: center;">
                    <h5 style="margin: 0; color: #7f8c8d;">Última Exportação</h5>
                    <p style="font-size: 14px; color: #2c3e50; margin: 10px 0;">Nenhuma</p>
                </div>
            </div>
        </div>
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
    let isAutoExportRunning = false;
    let autoExportInterval = null;

    function openTab(evt, tabName) {
        const tabContents = document.querySelectorAll('.tab-content');
        const tabButtons = document.querySelectorAll('.tab-button');
        
        tabContents.forEach(content => content.classList.remove('active'));
        tabButtons.forEach(button => button.classList.remove('active'));

        document.getElementById(tabName).classList.add('active');
        evt.currentTarget.classList.add('active');

        if (tabName === 'graficos') {
            fetchChartData();
        } else if (tabName === 'apontamentos') {
            fetchTableData();
        }
    }

    function toggleDateControls() {
        const dateType = document.getElementById('date-type').value;
        const dateControls = document.getElementById('date-controls');
        
        let html = '';
        
        if (dateType === 'intervalo') {
            html = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <label style="font-size: 12px;">Data Início:</label>
                        <input type="date" name="start_date" class="form-control" value="${getDate(-7)}">
                    </div>
                    <div>
                        <label style="font-size: 12px;">Data Fim:</label>
                        <input type="date" name="end_date" class="form-control" value="${getDate(0)}">
                    </div>
                </div>
            `;
        } else if (dateType === 'data_especifica') {
            html = `
                <div>
                    <label style="font-size: 12px;">Data:</label>
                    <input type="date" name="specific_date" class="form-control" value="${getDate(0)}">
                </div>
            `;
        }
        
        dateControls.innerHTML = html;
        dateControls.style.display = html ? 'block' : 'none';
    }

    function getDate(daysOffset) {
        const date = new Date();
        date.setDate(date.getDate() + daysOffset);
        return date.toISOString().split('T')[0];
    }

    function toggleAutoExport() {
        if (isAutoExportRunning) {
            stopAutoExport();
        } else {
            startAutoExport();
        }
    }

    function startAutoExport() {
        const interval = parseInt(document.getElementById('interval-minutos').value) * 60 * 1000;
        
        isAutoExportRunning = true;
        
        // Atualizar UI
        document.querySelector('#exportacao .status-indicator').className = 'status-indicator status-running';
        document.querySelector('#exportacao .status-indicator').nextElementSibling.textContent = 'Executando';
        document.getElementById('auto-export-btn').innerHTML = '<i class="fas fa-stop"></i> Parar Exportação Automática';
        document.getElementById('auto-export-btn').className = 'btn-export btn-export-error';
        
        // Simular execução automática
        autoExportInterval = setInterval(() => {
            showNotification('Exportação automática executada', 'success');
        }, interval);
        
        showNotification('Exportação automática iniciada', 'success');
    }

    function stopAutoExport() {
        isAutoExportRunning = false;
        
        // Atualizar UI
        document.querySelector('#exportacao .status-indicator').className = 'status-indicator status-stopped';
        document.querySelector('#exportacao .status-indicator').nextElementSibling.textContent = 'Parada';
        document.getElementById('auto-export-btn').innerHTML = '<i class="fas fa-play"></i> Iniciar Exportação Automática';
        document.getElementById('auto-export-btn').className = 'btn-export btn-export-accent';
        
        if (autoExportInterval) {
            clearInterval(autoExportInterval);
            autoExportInterval = null;
        }
        
        showNotification('Exportação automática parada', 'warning');
    }

    function showNotification(message, type = 'info') {
        // Implementar notificação simples
        alert(message);
    }

    // Gráficos e tabelas (código existente)
    let productionChart;
    const chartColors = [
        'rgba(76, 175, 80, 0.8)',
        'rgba(255, 159, 64, 0.8)',
        'rgba(54, 162, 235, 0.8)',
        'rgba(153, 102, 255, 0.8)',
        'rgba(255, 99, 132, 0.8)',
        'rgba(255, 205, 86, 0.8)'
    ];

    function createChart(chartData) {
        if (productionChart) {
            productionChart.destroy();
        }

        const ctx = document.getElementById('productionChart').getContext('2d');
        const labels = chartData.map(item => item.equipamento_nome);
        const data = chartData.map(item => parseFloat(item.total_hectares));

        productionChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Hectares Produzidos',
                    data: data,
                    backgroundColor: chartColors,
                    borderColor: chartColors.map(color => color.replace('0.8', '1')),
                    borderWidth: 1,
                    borderRadius: 5,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Total de Hectares'
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Equipamentos'
                        },
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(27, 94, 32, 0.9)',
                        titleFont: { size: 14, family: 'Poppins', weight: '600' },
                        bodyFont: { size: 12, family: 'Poppins' },
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: (context) => {
                                return `🚜 ${context[0].label}`;
                            },
                            label: (context) => {
                                if (typeof context.raw === 'number') {
                                    return `Hectares: ${context.raw.toFixed(2).replace('.', ',')}`;
                                }
                                return `Hectares: ${context.raw}`;
                            }
                        }
                    }
                }
            }
        });
    }

    function fetchChartData() {
        const date = document.getElementById('chart-date-filter').value;
        const unidade = document.getElementById('chart-unidade-filter').value;
        const operacao = document.getElementById('chart-operacao-filter').value;
        const equipamento = document.getElementById('chart-equipamento-filter').value;
        const implemento = document.getElementById('chart-implemento-filter').value;

        let url = `?ajax_data=1&type=chart&date=${date}`;
        if (unidade) url += `&unidade_id=${unidade}`;
        if (operacao) url += `&operacao_id=${operacao}`;
        if (equipamento) url += `&equipamento_id=${equipamento}`;
        if (implemento) url += `&implemento_id=${implemento}`;

        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Falha na resposta da rede: ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                createChart(data);
            })
            .catch(error => {
                console.error('Erro ao buscar dados do gráfico:', error);
                const ctx = document.getElementById('productionChart').getContext('2d');
                ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
                ctx.fillText("Nenhum dado encontrado para esta seleção.", 10, 50);
            });
    }

    function fetchTableData() {
        const date = document.getElementById('table-date-filter').value;
        const unidade = document.getElementById('table-unidade-filter').value;
        const operacao = document.getElementById('table-operacao-filter').value;
        const equipamento = document.getElementById('table-equipamento-filter').value;
        const implemento = document.getElementById('table-implemento-filter').value;

        let url = `?ajax_data=1&type=table&date=${date}`;
        if (unidade) url += `&unidade_id=${unidade}`;
        if (operacao) url += `&operacao_id=${operacao}`;
        if (equipamento) url += `&equipamento_id=${equipamento}`;
        if (implemento) url += `&implemento_id=${implemento}`;

        fetch(url)
            .then(response => response.json())
            .then(data => {
                updateTable(data);
            })
            .catch(error => console.error('Erro ao buscar dados da tabela:', error));
    }

    function updateTable(apontamentos) {
        const tbody = document.getElementById('apontamentos-table').querySelector('tbody');
        tbody.innerHTML = '';
        if (apontamentos.length === 0) {
            const row = document.createElement('tr');
            row.innerHTML = `<td colspan="9" style="text-align: center;">Nenhum apontamento encontrado para os filtros selecionados.</td>`;
            tbody.appendChild(row);
            return;
        }

        apontamentos.forEach(row => {
            let hora = row.hora_selecionada || row.hora_brasilia;
            if (!hora && row.data_hora) {
                const d = new Date((row.data_hora.includes('T') ? row.data_hora : row.data_hora.replace(' ', 'T')) + 'Z');
                hora = new Intl.DateTimeFormat('pt-BR', { timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit' }).format(d);
            }

            const newRow = document.createElement('tr');
            newRow.innerHTML = `
                <td>${hora || '--:--'}</td>
                <td>${row.unidade}</td>
                <td>${row.usuario}</td>
                <td>${row.equipamento}</td>
                <td>${row.implemento_nome ? row.implemento_nome + ' (' + row.numero_identificacao + ')' : 'N/A'}</td>
                <td>${parseFloat(row.hectares).toFixed(2).replace('.', ',')}</td>
                <td>${row.codigo_fazenda}</td>
                <td>${row.nome_fazenda}</td>
                <td>${row.operacao}</td>
            `;
            tbody.appendChild(newRow);
        });
    }

    // Inicialização
    document.addEventListener('DOMContentLoaded', function() {
        // Inicializar controles de data
        toggleDateControls();
        
        // Event listeners para filtros
        document.getElementById('chart-date-filter').addEventListener('change', fetchChartData);
        document.getElementById('chart-unidade-filter').addEventListener('change', fetchChartData);
        document.getElementById('chart-operacao-filter').addEventListener('change', fetchChartData);
        document.getElementById('chart-equipamento-filter').addEventListener('change', fetchChartData);
        document.getElementById('chart-implemento-filter').addEventListener('change', fetchChartData);
        
        document.getElementById('table-date-filter').addEventListener('change', fetchTableData);
        document.getElementById('table-unidade-filter').addEventListener('change', fetchTableData);
        document.getElementById('table-operacao-filter').addEventListener('change', fetchTableData);
        document.getElementById('table-equipamento-filter').addEventListener('change', fetchTableData);
        document.getElementById('table-implemento-filter').addEventListener('change', fetchTableData);
        
        // Carregar dados iniciais
        fetchChartData();
    });

    // Código para exportação Excel (mantido do original)
    document.getElementById('export-excel-btn').addEventListener('click', function() {
        const date = document.getElementById('table-date-filter').value;
        const unidade = document.getElementById('table-unidade-filter').value;
        const operacao = document.getElementById('table-operacao-filter').value;
        const equipamento = document.getElementById('table-equipamento-filter').value;
        const implemento = document.getElementById('table-implemento-filter').value;

        let url = `?ajax_data=1&type=table&date=${date}`;
        if (unidade) url += `&unidade_id=${unidade}`;
        if (operacao) url += `&operacao_id=${operacao}`;
        if (equipamento) url += `&equipamento_id=${equipamento}`;
        if (implemento) url += `&implemento_id=${implemento}`;

        fetch(url)
            .then(response => response.json())
            .then(data => {
                exportToExcel(data, date);
            })
            .catch(error => console.error('Erro ao buscar dados para exportação:', error));
    });

    function exportToExcel(data, date) {
        const parseUTC = (s) => s
            ? new Date((s.includes('T') ? s : s.replace(' ', 'T')) + (s.endsWith('Z') ? '' : 'Z'))
            : null;

        const fmtBRDate = (d) => new Intl.DateTimeFormat('pt-BR', {
            timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', year: 'numeric'
        }).format(d);

        const fmtBRTime = (d) => new Intl.DateTimeFormat('pt-BR', {
            timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit'
        }).format(d);

        const excelData = data.map(row => {
            const dataStr = row.data_brasilia
                ? fmtBRDate(new Date(row.data_brasilia + 'T00:00:00'))
                : (row.data_hora ? fmtBRDate(parseUTC(row.data_hora)) : '');

            const horaStr = row.hora_selecionada
                ? row.hora_selecionada
                : (row.hora_brasilia
                    ? row.hora_brasilia
                    : (row.data_hora ? fmtBRTime(parseUTC(row.data_hora)) : '--:--'));

            return {
                'Data': dataStr,
                'Hora': horaStr,
                'Unidade': row.unidade,
                'Usuário': row.usuario,
                'Equipamento': row.equipamento,
                'Implemento': row.numero_identificacao || 'N/A',
                'Hectares': parseFloat(row.hectares).toFixed(2).replace('.', ','),
                'Código Fazenda': row.codigo_fazenda,
                'Nome Fazenda': row.nome_fazenda,
                'Operação': row.operacao
            };
        });

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.json_to_sheet(excelData);

        ws['!cols'] = [
            { wch: 10 }, { wch: 8 }, { wch: 20 }, { wch: 20 }, { wch: 20 },
            { wch: 25 }, { wch: 12 }, { wch: 18 }, { wch: 28 }, { wch: 16 }
        ];

        XLSX.utils.book_append_sheet(wb, ws, "Apontamentos");
        XLSX.writeFile(wb, `apontamentos_${date}.xlsx`);
    }
</script>

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>