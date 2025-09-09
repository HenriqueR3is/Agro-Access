
<?php
session_start();
require_once(__DIR__ . '/../../../config/db/conexao.php');

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: /");
    exit();
}

// Verifica se a requisição é para a API (via AJAX) para a tabela ou gráfico
if (isset($_GET['ajax_data'])) {
    header('Content-Type: application/json');

    $date_filter = $_GET['date'] ?? date('Y-m-d');
    $unidade_filter = $_GET['unidade_id'] ?? null;
    $operacao_filter = $_GET['operacao_id'] ?? null;
    $equipamento_filter = $_GET['equipamento_id'] ?? null;
    $implemento_filter = $_GET['implemento_id'] ?? null;
    $type = $_GET['type'] ?? 'table';

    if ($type === 'table') {
        $sql = "SELECT a.data_hora, a.hectares, u.nome AS unidade, us.nome AS usuario, t.nome AS operacao, 
                       eq.nome AS equipamento, i.nome AS implemento_nome, i.numero_identificacao,
                       f.nome AS nome_fazenda, f.codigo_fazenda
                FROM apontamentos a
                JOIN unidades u ON a.unidade_id = u.id
                JOIN usuarios us ON a.usuario_id = us.id
                JOIN tipos_operacao t ON a.operacao_id = t.id
                JOIN equipamentos eq ON a.equipamento_id = eq.id
                LEFT JOIN implementos i ON eq.implemento_id = i.id
                JOIN fazendas f ON a.fazenda_id = f.id
                WHERE DATE(a.data_hora) = :date_filter";
        if ($unidade_filter) {
            $sql .= " AND a.unidade_id = :unidade_id";
        }
        if ($operacao_filter) {
            $sql .= " AND a.operacao_id = :operacao_id";
        }
        if ($equipamento_filter) {
            $sql .= " AND a.equipamento_id = :equipamento_id";
        }
        if ($implemento_filter) {
            $sql .= " AND eq.implemento_id = :implemento_id";
        }
        $sql .= " ORDER BY a.data_hora DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':date_filter', $date_filter);
        if ($unidade_filter) {
            $stmt->bindParam(':unidade_id', $unidade_filter);
        }
        if ($operacao_filter) {
            $stmt->bindParam(':operacao_id', $operacao_filter);
        }
        if ($equipamento_filter) {
            $stmt->bindParam(':equipamento_id', $equipamento_filter);
        }
        if ($implemento_filter) {
            $stmt->bindParam(':implemento_id', $implemento_filter);
        }
        $stmt->execute();
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($results);

    } elseif ($type === 'chart') {
        $sql = "SELECT eq.nome AS equipamento_nome, SUM(a.hectares) AS total_hectares
                FROM apontamentos a
                JOIN equipamentos eq ON a.equipamento_id = eq.id
                LEFT JOIN implementos i ON eq.implemento_id = i.id
                WHERE DATE(a.data_hora) = :date_filter";
        if ($unidade_filter) {
            $sql .= " AND a.unidade_id = :unidade_id";
        }
        if ($operacao_filter) {
            $sql .= " AND a.operacao_id = :operacao_id";
        }
        if ($equipamento_filter) {
            $sql .= " AND a.equipamento_id = :equipamento_id";
        }
        if ($implemento_filter) {
            $sql .= " AND eq.implemento_id = :implemento_id";
        }
        $sql .= " GROUP BY eq.nome";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':date_filter', $date_filter);
        if ($unidade_filter) {
            $stmt->bindParam(':unidade_id', $unidade_filter);
        }
        if ($operacao_filter) {
            $stmt->bindParam(':operacao_id', $operacao_filter);
        }
        if ($equipamento_filter) {
            $stmt->bindParam(':equipamento_id', $equipamento_filter);
        }
        if ($implemento_filter) {
            $stmt->bindParam(':implemento_id', $implemento_filter);
        }
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $chart_data = array_map(function($row) {
            $row['total_hectares'] = (float) $row['total_hectares'];
            return $row;
        }, $results);

        echo json_encode($chart_data);
    }
    
    exit();
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
$sql_apontamentos = "SELECT a.data_hora, a.hectares, u.nome AS unidade, us.nome AS usuario, t.nome AS operacao, 
                            eq.nome AS equipamento, i.nome AS implemento_nome, i.numero_identificacao,
                            f.nome AS nome_fazenda, f.codigo_fazenda
                     FROM apontamentos a
                     JOIN unidades u ON a.unidade_id = u.id
                     JOIN usuarios us ON a.usuario_id = us.id
                     JOIN tipos_operacao t ON a.operacao_id = t.id
                     JOIN equipamentos eq ON a.equipamento_id = eq.id
                     LEFT JOIN implementos i ON eq.implemento_id = i.id
                     JOIN fazendas f ON a.fazenda_id = f.id
                     WHERE DATE(a.data_hora) = CURDATE()
                     ORDER BY a.data_hora DESC";
$stmt_apontamentos = $pdo->prepare($sql_apontamentos);
$stmt_apontamentos->execute();
$apontamentos = $stmt_apontamentos->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrativo</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <style>
        :root {
            --primary-green: #2e7d32;
            --light-green: #4caf50;
            --dark-green: #1b5e20;
            --background-color: #f0f4f8;
            --card-bg: #ffffff;
            --text-color: #333;
            --light-text: #666;
            --border-color: #e0e0e0;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --transition-speed: 0.4s;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--background-color);
            margin: 0;
            color: var(--text-color);
        }

        h2 {
            font-size: 2em;
            color: var(--primary-green);
            border-bottom: 3px solid var(--primary-green);
            padding-bottom: 10px;
            margin-bottom: 25px;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.1);
        }

        .card {
            background-color: var(--card-bg);
            padding: 30px;
            border-radius: 16px;
            box-shadow: var(--shadow);
            margin-bottom: 25px;
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }

        .filters-group {
            display: flex;
            gap: 20px;
            align-items: flex-end;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filters-group label {
            font-weight: 600;
            color: var(--light-text);
        }

        .filters-group input[type="date"],
        .filters-group select {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.3s, box-shadow 0.3s;
            background-color: #f9f9f9;
        }
        
        .filters-group input[type="date"]:focus,
        .filters-group select:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 8px rgba(46, 125, 50, 0.2);
        }

        .chart-container {
            padding: 20px;
            background-color: #f9f9f9;
            border-radius: 12px;
            box-shadow: inset 0 0 5px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .tab-navigation {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 20px;
        }

        .tab-button {
            padding: 12px 25px;
            cursor: pointer;
            background-color: #f0f0f0;
            border: none;
            border-radius: 8px 8px 0 0;
            margin-right: 5px;
            font-weight: 600;
            color: var(--light-text);
            transition: all 0.3s ease;
        }

        .tab-button.active {
            background-color: var(--card-bg);
            border-bottom: 2px solid var(--primary-green);
            color: var(--primary-green);
            transform: translateY(2px);
        }

        .tab-button:hover:not(.active) {
            background-color: #e5e5e5;
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease forwards;
        }
        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .table-container {
            overflow-x: auto;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: inset 0 0 5px rgba(0,0,0,0.02);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th, td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s ease;
        }

        th {
            background-color: var(--dark-green);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
        }

        tr:nth-child(even) {
            background-color: #f8f8f8;
        }

        tr:hover {
            background-color: #eef5f9;
        }
        
        .button-excel {
            background-color: var(--primary-green);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
            transition: background-color 0.3s ease, transform 0.2s ease, box-shadow 0.3s ease;
        }

        .button-excel:hover {
            background-color: var(--dark-green);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>

<h2>Dashboard Geral</h2>

<div class="card">
    <div class="tab-navigation">
        <button class="tab-button active" onclick="openTab(event, 'apontamentos')">Apontamentos do Dia</button>
        <button class="tab-button" onclick="openTab(event, 'graficos')">Gráficos de Produção</button>
        <button class="tab-button" onclick="openTab(event, 'relatorios')">Relatórios</button>
    </div>

    <div id="apontamentos" class="tab-content active">
        <div class="filters-group">
            <div class="filter-item">
                <label for="table-date-filter">Data:</label>
                <input type="date" id="table-date-filter" value="<?php echo date('Y-m-d'); ?>">
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
                            <td><?php echo date('H:i', strtotime($row['data_hora'])); ?></td>
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
                <input type="date" id="chart-date-filter" value="<?php echo date('Y-m-d'); ?>">
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

    <div id="relatorios" class="tab-content">
        <p>Funcionalidade de Relatórios em Desenvolvimento...</p>
    </div>
</div>

<script>
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
                animation: {
                    onProgress: (animation) => {
                        if (animation.numSteps) {
                           const elapsed = animation.currentStep / animation.numSteps;
                           if (elapsed > 0.5) {
                               ctx.canvas.style.opacity = 1;
                           }
                        }
                    },
                    onComplete: (animation) => {
                    }
                },
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
            const newRow = document.createElement('tr');
            newRow.innerHTML = `
                <td>${new Date(row.data_hora).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}</td>
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

    document.addEventListener('DOMContentLoaded', function() {
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

        document.getElementById('export-excel-btn').addEventListener('click', function() {
            const table = document.getElementById('apontamentos-table');
            const ws = XLSX.utils.table_to_sheet(table);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Apontamentos");
            const date = document.getElementById('table-date-filter').value;
            XLSX.writeFile(wb, `apontamentos_${date}.xlsx`);
        });

        // Carregar dados iniciais
        fetchChartData();
    });
</script>

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>
