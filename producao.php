<?php
// producao.php
date_default_timezone_set('America/Sao_Paulo');
$host = 'sql107.infinityfree.com'; $db = 'if0_39840919_agrodash'; $user = 'if0_39840919'; $pass = 'QQs4kbmVS7Z';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4;port=3306", $user, $pass); } 
catch (PDOException $e) { die("Erro: " . $e->getMessage()); }

// PEGA A ÚLTIMA DATA DISPONÍVEL NO BANCO
$data_filtro = $pdo->query("SELECT MAX(data) FROM producao_sgpa")->fetchColumn();
if(!$data_filtro) $data_filtro = date('Y-m-d');

$sql = "SELECT * FROM producao_sgpa WHERE data = '$data_filtro' ORDER BY hectares_sgpa DESC";
$dados = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$total_hec = 0; $total_horas = 0;
foreach($dados as $d) { $total_hec += $d['hectares_sgpa']; $total_horas += $d['horas_efetivas']; }
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgroDash - Monitoramento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-size: 0.9rem; } 
        .table-container { background: white; padding: 20px; border-radius: 10px; }
        .fw-bold { font-weight: 600; }
        .text-small { font-size: 0.85rem; }
    </style>
</head>
<body>
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="fw-bold">
                🚜 Monitoramento SGPA (<?php echo date('d/m/Y', strtotime($data_filtro)); ?>)
            </h2>
        </div>
    </div>
    
    <div class="row mb-4 text-white">
        <div class="col-md-3">
            <div class="card p-3 bg-success">
                <h6>Total Produzido</h6>
                <h3 class="fw-bold"><?php echo number_format($total_hec, 2, ',', '.'); ?> ha</h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 bg-info text-white">
                <h6>Tempo Efetivo</h6>
                <h3 class="fw-bold"><?php echo number_format($total_horas, 2, ',', '.'); ?> h</h3>
            </div>
        </div>
    </div>

    <div class="row"><div class="col-12"><div class="table-container">
        <table id="tabelaSGPA" class="table table-hover align-middle" style="width:100%">
            <thead class="table-dark">
                <tr>
                    <th>Data</th>
                    <th>Unidade / Frente</th>
                    <th>Equipamento</th>                    
                    <th>Operador</th>
                    <th>Operação</th>
                    <th class="text-center">Prod. (ha)</th>
                    <th class="text-center">Hrs Trab.</th>
                    <th class="text-center">Veloc.</th>
                    <th class="text-center">RPM</th>
                    <th class="text-center">Cons. (L/h)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($dados as $linha): ?>
                <tr>
                    <td><?php echo date('d/m/Y', strtotime($linha['data'])); ?></td>

                    <td class="text-small">
                        <strong><?php echo $linha['unidade']; ?></strong><br>
                        <span class="text-muted"><?php echo $linha['frente']; ?></span>
                    </td>
                    
                    <td class="fw-bold">
                        <?php echo !empty($linha['nome_equipamento']) ? $linha['nome_equipamento'] : $linha['equipamento_id']; ?>
                    </td>                                    
                    
                    <td class="text-small">
                        <?php echo $linha['operador']; ?>
                    </td>
                    
                    <td>
                        <span class="badge bg-secondary">
                            <?php echo $linha['nome_operacao']; ?>
                        </span>
                    </td>
                    
                    <td class="text-center fw-bold text-success">
                        <?php echo number_format($linha['hectares_sgpa'], 2, ',', '.'); ?>
                    </td>

                    <td class="text-center fw-bold text-primary">
                        <?php echo number_format($linha['horas_efetivas'], 2, ',', '.'); ?> h
                    </td>                    
                    
                    <td class="text-center">
                        <?php echo number_format($linha['velocidade_media'], 1, ',', '.'); ?>
                    </td>
                    
                    <td class="text-center">
                        <?php echo number_format($linha['rpm_medio'], 1, ',', '.'); ?>
                    </td>

                    <td class="text-center">
                        <?php echo number_format($linha['consumo_litros'], 2, ',', '.'); ?>
                    </td>                    
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div></div></div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function(){ 
        $('#tabelaSGPA').DataTable({ 
            language: {url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-BR.json'}, 
            order: [[0, 'desc']],
            pageLength: 10
        }); 
    });
</script>
</body>
</html>