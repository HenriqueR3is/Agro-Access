<?php
session_start();
require_once(__DIR__ . '/../../../config/db/conexao.php');

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: /app/views/login.php");
    exit();
}
require_once __DIR__ . '/../../../app/includes/header.php';

$date_filter = $_GET['date'] ?? date('Y-m-d');
$unidade_filter = $_GET['unidade_id'] ?? null;
$operacao_filter = $_GET['operacao_id'] ?? null;

// Consultas para popular os filtros
$unidades_list = $pdo->query("SELECT id, nome FROM unidades ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$operacoes_list = $pdo->query("SELECT id, nome FROM tipos_operacao ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Consulta para o gráfico
$sql_chart = "SELECT u.nome, SUM(a.hectares) AS total_hectares
              FROM apontamentos a
              JOIN unidades u ON a.unidade_id = u.id
              WHERE DATE(a.data_hora) = :date_filter";
if ($unidade_filter) {
    $sql_chart .= " AND a.unidade_id = :unidade_id";
}
if ($operacao_filter) {
    $sql_chart .= " AND a.operacao_id = :operacao_id";
}
$sql_chart .= " GROUP BY u.nome";

$stmt_chart = $pdo->prepare($sql_chart);
$stmt_chart->bindParam(':date_filter', $date_filter);
if ($unidade_filter) {
    $stmt_chart->bindParam(':unidade_id', $unidade_filter);
}
if ($operacao_filter) {
    $stmt_chart->bindParam(':operacao_id', $operacao_filter);
}
$stmt_chart->execute();
$result_chart = $stmt_chart->fetchAll(PDO::FETCH_ASSOC);

$chart_labels = [];
$chart_data = [];
foreach ($result_chart as $row) {
    $chart_labels[] = $row['nome'];
    $chart_data[] = (float) $row['total_hectares'];
}

// Consulta para a tabela de apontamentos
$sql_apontamentos = "SELECT a.data_hora, a.hectares, u.nome AS unidade, us.nome AS usuario, t.nome AS operacao, eq.nome AS equipamento, f.nome AS nome_fazenda, f.codigo_fazenda
                     FROM apontamentos a
                     JOIN unidades u ON a.unidade_id = u.id
                     JOIN usuarios us ON a.usuario_id = us.id
                     JOIN tipos_operacao t ON a.operacao_id = t.id
                     JOIN equipamentos eq ON a.equipamento_id = eq.id
                     JOIN fazendas f ON a.fazenda_id = f.id
                     WHERE DATE(a.data_hora) = :date_filter";
if ($unidade_filter) {
    $sql_apontamentos .= " AND a.unidade_id = :unidade_id";
}
if ($operacao_filter) {
    $sql_apontamentos .= " AND a.operacao_id = :operacao_id";
}
$sql_apontamentos .= " ORDER BY a.data_hora DESC";

$stmt_apontamentos = $pdo->prepare($sql_apontamentos);
$stmt_apontamentos->bindParam(':date_filter', $date_filter);
if ($unidade_filter) {
    $stmt_apontamentos->bindParam(':unidade_id', $unidade_filter);
}
if ($operacao_filter) {
    $stmt_apontamentos->bindParam(':operacao_id', $operacao_filter);
}
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
            padding: 30px;
            color: var(--text-color);
        }

        h2 {
            font-size: 2em;
            color: var(--primary-green);
            border-bottom: 3px solid var(--primary-green);
            padding-bottom: 10px;
            margin-bottom: 25px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
        }

        .card {
            background-color: var(--card-bg);
            padding: 30px;
            border-radius: 16px;
            box-shadow: var(--shadow);
            margin-bottom: 25px;
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
            animation: fadeIn var(--transition-speed) ease forwards;
            opacity: 0;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card:nth-of-type(1) { animation-delay: 0.1s; }
        .card:nth-of-type(2) { animation-delay: 0.2s; }

        .form-group {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .filters-group {
            display: flex;
            gap: 20px;
            align-items: flex-end;
            margin-bottom: 20px;
        }

        .filter-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group label {
            font-weight: 600;
            color: var(--light-text);
        }

        .form-group input[type="date"],
        .filter-item select {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.3s, box-shadow 0.3s;
            background-color: #f9f9f9;
        }
        
        .form-group input[type="date"]:focus,
        .filter-item select:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 8px rgba(46, 125, 50, 0.2);
        }

        /* --- Estilo para o Gráfico --- */
        #productionChart {
            padding: 20px;
            background-color: #f9f9f9;
            border-radius: 12px;
            box-shadow: inset 0 0 5px rgba(0,0,0,0.05);
        }

        /* --- Estilo para as Abas --- */
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
            animation: fadeIn var(--transition-speed) ease forwards;
        }
        .tab-content.active {
            display: block;
        }

        /* --- Estilo para a Tabela --- */
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
        
        /* Botão Exportar Excel */
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
    <div class="filters-group">
        <div class="filter-item">
            <label for="date-filter">Data:</label>
            <input type="date" id="date-filter" value="<?php echo htmlspecialchars($date_filter); ?>">
        </div>
        <div class="filter-item">
            <label for="unidade-filter">Unidade:</label>
            <select id="unidade-filter">
                <option value="">Todas</option>
                <?php foreach ($unidades_list as $unidade): ?>
                    <option value="<?php echo $unidade['id']; ?>" <?php echo $unidade_filter == $unidade['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($unidade['nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-item">
            <label for="operacao-filter">Operação:</label>
            <select id="operacao-filter">
                <option value="">Todas</option>
                <?php foreach ($operacoes_list as $operacao): ?>
                    <option value="<?php echo $operacao['id']; ?>" <?php echo $operacao_filter == $operacao['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($operacao['nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div style="height: 400px;">
        <canvas id="productionChart"></canvas>
    </div>
</div>

<div class="card">
    <div class="tab-navigation">
        <button class="tab-button active" onclick="openTab(event, 'apontamentos')">Apontamentos do Dia</button>
        <button class="tab-button" onclick="openTab(event, 'relatorios')">Relatórios</button>
    </div>

    <div id="apontamentos" class="tab-content active">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h3>Apontamentos (<?php echo htmlspecialchars($date_filter); ?>)</h3>
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
                        <th>Hectares</th>
                        <th>Código Fazenda</th>
                        <th>Nome Fazenda</th>
                        <th>Operação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($apontamentos as $row): ?>
                    <tr>
                        <td><?php echo date('H:i', strtotime($row['data_hora'])); ?></td>
                        <td><?php echo htmlspecialchars($row['unidade']); ?></td>
                        <td><?php echo htmlspecialchars($row['usuario']); ?></td>
                        <td><?php echo htmlspecialchars($row['equipamento']); ?></td>
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

    <div id="relatorios" class="tab-content">
        <p>Funcionalidade de Relatórios em Desenvolvimento...</p>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
<script>
    function openTab(evt, tabName) {
        let i, tabcontent, tabbuttons;
        tabcontent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabcontent.length; i++) {
            tabcontent[i].classList.remove("active");
        }
        tabbuttons = document.getElementsByClassName("tab-button");
        for (i = 0; i < tabbuttons.length; i++) {
            tabbuttons[i].classList.remove("active");
        }
        document.getElementById(tabName).classList.add("active");
        evt.currentTarget.classList.add("active");
    }

    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('productionChart').getContext('2d');
        const chartData = {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Hectares Produzidos',
                data: <?php echo json_encode($chart_data); ?>,
                backgroundColor: 'rgba(76, 175, 80, 0.8)',
                borderColor: 'rgba(46, 125, 50, 1)',
                borderWidth: 1,
                borderRadius: 5,
            }]
        };

        new Chart(ctx, {
            type: 'bar',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e0e0e0'
                        }
                    },
                    x: {
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
                        backgroundColor: 'rgba(0, 0, 0, 0.7)',
                        titleFont: { size: 14, family: 'Poppins' },
                        bodyFont: { size: 12, family: 'Poppins' },
                        padding: 10,
                        boxPadding: 5
                    }
                }
            }
        });

        // Lógica de filtragem
        function updateUrl() {
            const date = document.getElementById('date-filter').value;
            const unidade = document.getElementById('unidade-filter').value;
            const operacao = document.getElementById('operacao-filter').value;
            let url = `?date=${date}`;
            if (unidade) {
                url += `&unidade_id=${unidade}`;
            }
            if (operacao) {
                url += `&operacao_id=${operacao}`;
            }
            window.location.href = url;
        }

        document.getElementById('date-filter').addEventListener('change', updateUrl);
        document.getElementById('unidade-filter').addEventListener('change', updateUrl);
        document.getElementById('operacao-filter').addEventListener('change', updateUrl);

        // Funcionalidade de exportar para Excel com ajuste de formato
        document.getElementById('export-excel-btn').addEventListener('click', function() {
            const table = document.getElementById('apontamentos-table');
            const ws = XLSX.utils.table_to_sheet(table);

            const columnHectares = XLSX.utils.encode_col(4);
            for (let R = 1; ws['!ref'] && R <= XLSX.utils.decode_range(ws['!ref']).e.r; ++R) {
                const cellRef = XLSX.utils.encode_cell({c: 4, r: R});
                if (ws[cellRef] && typeof ws[cellRef].v === 'string') {
                    const formattedValue = parseFloat(ws[cellRef].v.replace(',', '.'));
                    if (!isNaN(formattedValue)) {
                        ws[cellRef].v = formattedValue;
                        ws[cellRef].t = 'n';
                    }
                }
            }

            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Apontamentos");
            XLSX.writeFile(wb, `apontamentos_${document.getElementById('date-filter').value}.xlsx`);
        });
    });
</script>

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>