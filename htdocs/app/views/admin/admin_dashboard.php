<?php
session_start();
require_once(__DIR__ . '/../../../config/db/conexao.php');
 // Certifique-se de que este arquivo retorna uma instância de PDO
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: /app/views/login.php");
    exit();
}
require_once __DIR__ . '/../../../app/includes/header.php';

// Coletar dados para o dashboard
$date_filter = $_GET['date'] ?? date('Y-m-d');

// Consulta para o gráfico
$sql_chart = "SELECT u.nome, SUM(a.hectares) AS total_hectares 
              FROM apontamentos a 
              JOIN unidades u ON a.unidade_id = u.id 
              WHERE DATE(a.data_hora) = :date_filter 
              GROUP BY u.nome";
$stmt_chart = $pdo->prepare($sql_chart);
$stmt_chart->bindParam(':date_filter', $date_filter);
$stmt_chart->execute();
$result_chart = $stmt_chart->fetchAll(PDO::FETCH_ASSOC);

$chart_labels = [];
$chart_data = [];
foreach ($result_chart as $row) {
    $chart_labels[] = $row['nome'];
    $chart_data[] = (float) $row['total_hectares'];
}

// Consulta para a tabela de apontamentos
$sql_apontamentos = "SELECT a.data_hora, u.nome AS unidade, us.nome AS usuario, t.nome AS operacao, a.hectares 
                     FROM apontamentos a 
                     JOIN unidades u ON a.unidade_id = u.id 
                     JOIN usuarios us ON a.usuario_id = us.id 
                     JOIN tipos_operacao t ON a.operacao_id = t.id 
                     WHERE DATE(a.data_hora) = :date_filter 
                     ORDER BY a.data_hora DESC";
$stmt_apontamentos = $pdo->prepare($sql_apontamentos);
$stmt_apontamentos->bindParam(':date_filter', $date_filter);
$stmt_apontamentos->execute();
$apontamentos = $stmt_apontamentos->fetchAll(PDO::FETCH_ASSOC);

?>
<h2>Dashboard Geral</h2>

<div class="card">
    <div class="form-group" style="display: flex; align-items: center; gap: 10px;">
        <label for="date-filter">Data:</label>
        <input type="date" id="date-filter" value="<?php echo htmlspecialchars($date_filter); ?>">
    </div>
    <div style="height: 400px;">
        <canvas id="productionChart"></canvas>
    </div>
</div>

<div class="card" style="margin-top: 20px;">
    <h3>Apontamentos do Dia (<?php echo date('d/m/Y', strtotime($date_filter)); ?>)</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Hora</th>
                    <th>Unidade</th>
                    <th>Usuário</th>
                    <th>Operação</th>
                    <th>Hectares</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($apontamentos as $row): ?>
                <tr>
                    <td><?php echo date('H:i', strtotime($row['data_hora'])); ?></td>
                    <td><?php echo htmlspecialchars($row['unidade']); ?></td>
                    <td><?php echo htmlspecialchars($row['usuario']); ?></td>
                    <td><?php echo htmlspecialchars($row['operacao']); ?></td>
                    <td><?php echo number_format($row['hectares'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('productionChart').getContext('2d');
        const chartData = {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Hectares Produzidos',
                data: <?php echo json_encode($chart_data); ?>,
                backgroundColor: 'rgba(0, 123, 255, 0.7)',
                borderColor: 'rgba(0, 123, 255, 1)',
                borderWidth: 1
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
                        beginAtZero: true
                    }
                }
            }
        });

        $('#date-filter').on('change', function() {
            window.location.href = `admin_dashboard.php?date=${this.value}`;
        });
    });
</script>

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>