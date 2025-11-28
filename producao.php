<?php
// dashboard.php
$host = 'sql107.infinityfree.com'; $db = 'if0_39840919_agrodash'; $user = 'if0_39840919'; $pass = 'QQs4kbmVS7Z';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4;port=3306", $user, $pass);
} catch (PDOException $e) { die("Erro: " . $e->getMessage()); }

$sql = "SELECT * FROM producao_sgpa ORDER BY data DESC LIMIT 2000";
$dados = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$total_hec = 0; $total_litros = 0;
foreach($dados as $d) { $total_hec += $d['hectares_sgpa']; $total_litros += $d['consumo_litros']; }
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgroDash - Monitoramento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>body { background-color: #f4f6f9; } .table-container { background: white; padding: 20px; border-radius: 10px; }</style>
</head>
<body>
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12"><h2 class="fw-bold">🚜 Produção SGPA</h2></div>
    </div>
    
    <div class="row mb-4 text-white">
        <div class="col-md-3"><div class="card p-3 bg-success"><h5>Total Hectares</h5><h3><?php echo number_format($total_hec, 2, ',', '.'); ?> ha</h3></div></div>
        <div class="col-md-3"><div class="card p-3 bg-primary"><h5>Consumo Litros</h5><h3><?php echo number_format($total_litros, 2, ',', '.'); ?> L</h3></div></div>
    </div>

    <div class="row"><div class="col-12"><div class="table-container">
        <table id="tabelaSGPA" class="table table-striped table-hover" style="width:100%">
            <thead class="table-dark">
                <tr>
                    <th>Data</th>
                    <th>Equipamento</th> <th>Unidade</th>
                    <th>Frente</th>
                    <th>Operador</th>
                    <th>Operação</th>
                    <th>Prod. (ha)</th>
                    <th>Consumo (L/ha)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($dados as $linha): ?>
                <tr>
                    <td><?php echo date('d/m/Y', strtotime($linha['data'])); ?></td>
                    
                    <td class="fw-bold"><?php echo !empty($linha['nome_equipamento']) ? $linha['nome_equipamento'] : $linha['equipamento_id']; ?></td>
                    
                    <td><?php echo $linha['unidade']; ?></td>
                    <td><?php echo $linha['frente']; ?></td>
                    <td><?php echo $linha['operador']; ?></td>
                    <td><span class="badge bg-secondary"><?php echo $linha['nome_operacao']; ?></span></td>
                    <td class="text-success fw-bold"><?php echo number_format($linha['hectares_sgpa'], 2, ',', '.'); ?></td>
                    <td><?php echo number_format($linha['consumo_litros'], 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div></div></div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>$(document).ready(function(){ $('#tabelaSGPA').DataTable({ language: {url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-BR.json'}, order: [[0, 'desc']] }); });</script>
</body>
</html>