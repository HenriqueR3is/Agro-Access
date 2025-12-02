<?php
// producao.php
date_default_timezone_set('America/Sao_Paulo');
$host = 'sql107.infinityfree.com'; $db = 'if0_39840919_agrodash'; $user = 'if0_39840919'; $pass = 'QQs4kbmVS7Z';

try { 
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4;port=3306", $user, $pass); 
    // Garante que o PDO não converta números em strings, mantendo precisão matemática
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
} 
catch (PDOException $e) { die("Erro: " . $e->getMessage()); }

// --- LÓGICA DO FILTRO DE DATA ---

// 1. Se o usuário escolheu uma data no formulário, usa ela.
if (isset($_GET['data_selecionada']) && !empty($_GET['data_selecionada'])) {
    $data_filtro = $_GET['data_selecionada'];
} else {
    // 2. Se não, busca a última data disponível no banco (comportamento padrão)
    $data_filtro = $pdo->query("SELECT MAX(data) FROM producao_sgpa")->fetchColumn();
    if(!$data_filtro) $data_filtro = date('Y-m-d');
}

// Consulta segura usando Prepared Statements (evita bugs e invasão)
$sql = "SELECT * FROM producao_sgpa WHERE data = :data_filtro ORDER BY hectares_sgpa DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([':data_filtro' => $data_filtro]);
$dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- CÁLCULO DE TOTAIS ---
$total_hec = 0.0; 
$total_horas = 0.0;

foreach($dados as $d) { 
    // Soma os valores brutos (float) para evitar erro de arredondamento prematuro
    $total_hec += (float)$d['hectares_sgpa']; 
    $total_horas += (float)$d['horas_efetivas'];
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgroDash - Monitoramento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f6f9; font-size: 0.9rem; } 
        .table-container { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .fw-bold { font-weight: 600; }
        .text-small { font-size: 0.85rem; }
        .filter-card { background: #fff; padding: 15px; border-radius: 10px; border-left: 5px solid #0d6efd; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="container-fluid py-4">
    
    <div class="row mb-3 align-items-center">
        <div class="col-md-6">
            <h2 class="fw-bold text-dark mb-0">🚜 Monitoramento SGPA</h2>
            <small class="text-muted">Dados referentes a: <strong><?php echo date('d/m/Y', strtotime($data_filtro)); ?></strong></small>
        </div>
        <div class="col-md-6">
            <form method="GET" class="d-flex justify-content-md-end align-items-center gap-2 mt-2 mt-md-0">
                <label for="data_selecionada" class="fw-bold text-secondary">Ver dia:</label>
                <input type="date" id="data_selecionada" name="data_selecionada" 
                       value="<?php echo $data_filtro; ?>" 
                       max="<?php echo date('Y-m-d'); ?>" 
                       class="form-control w-auto shadow-sm">
                <button type="submit" class="btn btn-primary shadow-sm"><i class="bi bi-search"></i> Buscar</button>
                <?php if(isset($_GET['data_selecionada'])): ?>
                    <a href="producao.php" class="btn btn-outline-secondary" title="Voltar ao último dia"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    
    <div class="row mb-4 text-white">
        <div class="col-md-3">
            <div class="card p-3 bg-success shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="opacity-75">Total Produzido</h6>
                        <h3 class="fw-bold mb-0"><?php echo number_format($total_hec, 2, ',', '.'); ?> ha</h3>
                    </div>
                    <i class="bi bi-bar-chart-fill fs-1 opacity-25"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 bg-info text-white shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="opacity-75">Tempo Efetivo</h6>
                        <h3 class="fw-bold mb-0"><?php echo number_format($total_horas, 2, ',', '.'); ?> h</h3>
                    </div>
                    <i class="bi bi-clock-history fs-1 opacity-25"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3 bg-warning text-dark shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="opacity-75">Rendimento Médio</h6>
                        <h3 class="fw-bold mb-0">
                            <?php echo ($total_horas > 0) ? number_format($total_hec / $total_horas, 2, ',', '.') : '0,00'; ?> ha/h
                        </h3>
                    </div>
                    <i class="bi bi-speedometer2 fs-1 opacity-25"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row"><div class="col-12"><div class="table-container">
        <?php if(count($dados) > 0): ?>
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
                            <?php echo number_format($linha['rpm_medio'], 0, ',', '.'); ?>
                        </td>

                        <td class="text-center">
                            <?php echo number_format($linha['consumo_litros'], 2, ',', '.'); ?>
                        </td>                    
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="alert alert-warning text-center">
                <i class="bi bi-exclamation-triangle"></i> Nenhum registro encontrado para a data <strong><?php echo date('d/m/Y', strtotime($data_filtro)); ?></strong>.
            </div>
        <?php endif; ?>
    </div></div></div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function(){ 
        $('#tabelaSGPA').DataTable({ 
            language: {url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-BR.json'}, 
            order: [[5, 'desc']], // Ordena por Produção (coluna 5) do maior para o menor
            pageLength: 10
        }); 
    });
</script>
</body>
</html>