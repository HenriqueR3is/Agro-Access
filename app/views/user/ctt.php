<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';
require_once __DIR__.'/../../../app/lib/Audit.php';

$stmt = $pdo->query("SELECT manutencao_ativa FROM configuracoes_sistema WHERE id = 1");
$manutencao = $stmt->fetchColumn();
if ($manutencao) {
    header("Location: /maintenance");
    exit;
}

$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
    header("Location: /");
    exit();
}

// Processar submissão do formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $frente_id = $_POST['frente_id'] ?? '';
    $apontamento = $_POST['apontamento'] ?? '';
    $realizado = $_POST['realizado'] ?? 'não';
    $equipamento_id = $_POST['equipamento_id'] ?? '';
    $fazenda_id = $_POST['fazenda_id'] ?? '';
    $rota = $_POST['rota'] ?? '';
    $tempo_carregamento = $_POST['tempo_carregamento'] ?? '';
    $tempo_medio = $_POST['tempo_medio'] ?? '';
    
    try {
        // Inserir registro no DB
        $stmt = $pdo->prepare("
            INSERT INTO ctt_registros (usuario_id, frente_id, equipamento_id, fazenda_id, rota, apontamento, tempo_carregamento, tempo_medio, realizado, data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $_SESSION['usuario_id'], 
            $frente_id, 
            $equipamento_id, 
            $fazenda_id,
            $rota,
            $apontamento, 
            $tempo_carregamento,
            $tempo_medio,
            $realizado
        ]);
        
        $_SESSION['message'] = "Registro enviado com sucesso!";
        Audit::log($pdo, [
            'action' => 'created',
            'entity' => 'ctt_registros',
            'entity_id' => $pdo->lastInsertId(),
            'meta' => [
                'frente_id' => $frente_id, 
                'equipamento_id' => $equipamento_id,
                'fazenda_id' => $fazenda_id,
                'rota' => $rota,
                'apontamento' => $apontamento, 
                'tempo_carregamento' => $tempo_carregamento,
                'tempo_medio' => $tempo_medio,
                'realizado' => $realizado
            ]
        ]);
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao enviar registro: " . $e->getMessage();
    }
    
    header("Location: /ctt");
    exit();
}

// Buscar dados do DB
$frentes = $pdo->query("SELECT id, nome, meta_cana FROM frentes ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Para cada frente, calcular entregue
foreach ($frentes as &$frente) {
    $stmt = $pdo->prepare("SELECT SUM(quantidade) AS total_entregue FROM entregas_cana WHERE frente_id = ?");
    $stmt->execute([$frente['id']]);
    $frente['total_entregue'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_entregue'] ?? 0;
}

// Apontamentos
$apontamentos = ['lavagem', 'limpeza', 'manutenção', 'abastecimento', 'troca de turno'];

// Buscar equipamentos de colheita
$equipamentos_colheita = $pdo->query("
    SELECT e.id, e.nome, u.nome AS unidade_nome, t.nome AS operacao_nome
    FROM equipamentos e
    LEFT JOIN unidades u ON e.unidade_id = u.id
    LEFT JOIN tipos_operacao t ON e.operacao_id = t.id
    WHERE e.ativo = 1 AND (t.nome LIKE '%colheita%' OR t.nome LIKE '%transbordo%' OR t.nome LIKE '%trator%')
    ORDER BY t.nome, e.nome
")->fetchAll(PDO::FETCH_ASSOC);

// Buscar fazendas
$fazendas = $pdo->query("SELECT id, nome, codigo_fazenda FROM fazendas ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Buscar registros recentes
try {
    $registros_recentes = $pdo->query("
        SELECT cr.*, f.nome as frente_nome, e.nome as equipamento_nome, 
               fz.nome as fazenda_nome, u.nome as usuario_nome
        FROM ctt_registros cr
        LEFT JOIN frentes f ON cr.frente_id = f.id
        LEFT JOIN equipamentos e ON cr.equipamento_id = e.id
        LEFT JOIN fazendas fz ON cr.fazenda_id = fz.id
        LEFT JOIN usuarios u ON cr.usuario_id = u.id
        ORDER BY cr.data DESC
        LIMIT 15
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $registros_recentes = [];
}

// Buscar estatísticas para os cards
$total_registros_hoje = $pdo->query("
    SELECT COUNT(*) as total FROM ctt_registros 
    WHERE DATE(data) = CURDATE()
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$total_frentes_ativas = $pdo->query("SELECT COUNT(*) as total FROM frentes WHERE meta_cana > 0")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$total_equipamentos = $pdo->query("SELECT COUNT(*) as total FROM equipamentos WHERE ativo = 1")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Buscar tempo médio de carregamento (exemplo)
$tempo_medio_carregamento = $pdo->query("
    SELECT AVG(tempo_carregamento) as media FROM ctt_registros 
    WHERE tempo_carregamento > 0 AND DATE(data) = CURDATE()
")->fetch(PDO::FETCH_ASSOC)['media'] ?? 0;
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTT - Dashboard Colheita</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- CSS personalizado -->
    <link rel="stylesheet" href="/public/static/css/ctt.css">
</head>
<body>

<!-- Header -->
<header class="ctt-header">
    <div class="container">
        <div class="header-content">
            <div class="header-brand">
                <img src="/public/static/img/logousa.png" alt="CTT Logo" class="header-logo">
                <i class="fas fa-tractor"></i>
                <h1>CTT Dashboard</h1>
                <span class="badge bg-warning">v2.0</span>
            </div>
            <div class="header-info">
                <div class="user-info">
                    <i class="fas fa-user"></i>
                    <span><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário') ?></span>
                </div>
                <div class="time-info" id="current-time">
                    <i class="fas fa-clock"></i>
                    <span>Carregando...</span>
                </div>
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
            <button class="nav-link" id="nav-registro-tab" data-bs-toggle="tab" data-bs-target="#nav-registro" type="button" role="tab">
                <i class="fas fa-plus-circle"></i> Novo Registro
            </button>
            <button class="nav-link" id="nav-operacao-tab" data-bs-toggle="tab" data-bs-target="#nav-operacao" type="button" role="tab">
                <i class="fas fa-cogs"></i> Operação
            </button>
            <button class="nav-link" id="nav-frentes-tab" data-bs-toggle="tab" data-bs-target="#nav-frentes" type="button" role="tab">
                <i class="fas fa-map-marked-alt"></i> Frentes
            </button>
            <button class="nav-link" id="nav-equipamentos-tab" data-bs-toggle="tab" data-bs-target="#nav-equipamentos" type="button" role="tab">
                <i class="fas fa-tractor"></i> Equipamentos
            </button>
            <button class="nav-link" id="nav-relatorios-tab" data-bs-toggle="tab" data-bs-target="#nav-relatorios" type="button" role="tab">
                <i class="fas fa-chart-bar"></i> Relatórios
            </button>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <!-- Mensagens de feedback -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i>
            <?= $_SESSION['message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle"></i>
            <?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
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
                            <h3><?= $total_registros_hoje ?></h3>
                            <p>Registros Hoje</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="ctt-card stat-card">
                        <div class="card-icon bg-success">
                            <i class="fas fa-map"></i>
                        </div>
                        <div class="card-content">
                            <h3><?= $total_frentes_ativas ?></h3>
                            <p>Frentes Ativas</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="ctt-card stat-card">
                        <div class="card-icon bg-warning">
                            <i class="fas fa-tractor"></i>
                        </div>
                        <div class="card-content">
                            <h3><?= $total_equipamentos ?></h3>
                            <p>Equipamentos</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="ctt-card stat-card">
                        <div class="card-icon bg-info">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="card-content">
                            <h3><?= number_format($tempo_medio_carregamento, 1) ?>min</h3>
                            <p>Tempo Médio</p>
                        </div>
                    </div>
                </div>

                <!-- Gráfico de Produtividade -->
                <div class="col-xl-8">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-line"></i> Produtividade por Frente</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="produtividadeChart" height="250"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Status dos Equipamentos -->
                <div class="col-xl-4">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-tachometer-alt"></i> Status dos Equipamentos</h5>
                        </div>
                        <div class="card-body">
                            <div class="status-list">
                                <?php foreach ($equipamentos_colheita as $equip): ?>
                                <div class="status-item">
                                    <div class="status-indicator bg-success"></div>
                                    <span class="equip-name"><?= htmlspecialchars($equip['nome']) ?></span>
                                    <span class="equip-type"><?= htmlspecialchars($equip['operacao_nome']) ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ABA NOVO REGISTRO -->
        <div class="tab-pane fade" id="nav-registro" role="tabpanel">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-plus-circle"></i> Novo Apontamento CTT</h5>
                        </div>
                        <form method="POST" class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Frente de Trabalho *</label>
                                    <select name="frente_id" class="form-select select-search" required>
                                        <option value="">Selecione a Frente</option>
                                        <?php foreach ($frentes as $frente): ?>
                                            <option value="<?= $frente['id'] ?>">
                                                <?= htmlspecialchars($frente['nome']) ?> - 
                                                Meta: <?= $frente['meta_cana'] ?> ton
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Fazenda *</label>
                                    <select name="fazenda_id" class="form-select select-search" required>
                                        <option value="">Selecione a Fazenda</option>
                                        <?php foreach ($fazendas as $fazenda): ?>
                                            <option value="<?= $fazenda['id'] ?>">
                                                <?= htmlspecialchars($fazenda['nome']) ?> 
                                                (<?= htmlspecialchars($fazenda['codigo_fazenda']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Equipamento *</label>
                                    <select name="equipamento_id" class="form-select select-search" required>
                                        <option value="">Selecione o Equipamento</option>
                                        <?php foreach ($equipamentos_colheita as $equipamento): ?>
                                            <option value="<?= $equipamento['id'] ?>">
                                                <?= htmlspecialchars($equipamento['nome']) ?> - 
                                                <?= htmlspecialchars($equipamento['operacao_nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Apontamento *</label>
                                    <select name="apontamento" class="form-select" required>
                                        <option value="">Selecione o Tipo</option>
                                        <?php foreach ($apontamentos as $ap): ?>
                                            <option value="<?= $ap ?>"><?= ucfirst($ap) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Status *</label>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="realizado" id="realizado_sim" value="sim" required>
                                        <label class="form-check-label" for="realizado_sim">
                                            <i class="fas fa-check-circle text-success"></i> Realizado
                                        </label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="realizado" id="realizado_nao" value="não">
                                        <label class="form-check-label" for="realizado_nao">
                                            <i class="fas fa-times-circle text-danger"></i> Não Realizado
                                        </label>
                                    </div>
                                </div>

                                <div class="col-12 text-end">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-paper-plane"></i> Enviar Registro
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- ABA OPERAÇÃO -->
        <div class="tab-pane fade" id="nav-operacao" role="tabpanel">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-cogs"></i> Dados da Operação</h5>
                        </div>
                        <form method="POST" class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Frente de Trabalho *</label>
                                    <select name="frente_id" class="form-select select-search" required>
                                        <option value="">Selecione a Frente</option>
                                        <?php foreach ($frentes as $frente): ?>
                                            <option value="<?= $frente['id'] ?>">
                                                <?= htmlspecialchars($frente['nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Equipamento *</label>
                                    <select name="equipamento_id" class="form-select select-search" required>
                                        <option value="">Selecione o Equipamento</option>
                                        <?php foreach ($equipamentos_colheita as $equipamento): ?>
                                            <option value="<?= $equipamento['id'] ?>">
                                                <?= htmlspecialchars($equipamento['nome']) ?> - 
                                                <?= htmlspecialchars($equipamento['operacao_nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Rota *</label>
                                    <input type="text" name="rota" class="form-control" 
                                           placeholder="Ex: Rota A - Usina Principal" required>
                                    <div class="form-text">Descreva a rota utilizada na operação</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Tempo de Carregamento (min) *</label>
                                    <input type="number" name="tempo_carregamento" class="form-control" 
                                           placeholder="Ex: 15" min="1" max="120" step="1" required>
                                    <div class="form-text">Tempo médio para carregar o equipamento</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Tempo Médio (min) *</label>
                                    <input type="number" name="tempo_medio" class="form-control" 
                                           placeholder="Ex: 45" min="1" max="240" step="1" required>
                                    <div class="form-text">Tempo médio total da operação</div>
                                </div>



                                <div class="col-12 text-end">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fas fa-save"></i> Salvar Dados da Operação
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- ABA FRENTES -->
        <div class="tab-pane fade" id="nav-frentes" role="tabpanel">
            <div class="row g-4">
                <?php foreach ($frentes as $frente): 
                    $progresso = $frente['meta_cana'] > 0 ? min(100, ($frente['total_entregue'] / $frente['meta_cana']) * 100) : 0;
                ?>
                <div class="col-xl-4 col-md-6">
                    <div class="ctt-card frente-card">
                        <div class="card-header">
                            <h6><?= htmlspecialchars($frente['nome']) ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="progress mb-3" style="height: 20px;">
                                <div class="progress-bar bg-success" role="progressbar" 
                                     style="width: <?= $progresso ?>%">
                                    <?= number_format($progresso, 1) ?>%
                                </div>
                            </div>
                            <div class="frente-stats">
                                <div class="stat">
                                    <span class="label">Meta:</span>
                                    <span class="value"><?= number_format($frente['meta_cana'], 0, ',', '.') ?> ton</span>
                                </div>
                                <div class="stat">
                                    <span class="label">Entregue:</span>
                                    <span class="value text-success"><?= number_format($frente['total_entregue'], 0, ',', '.') ?> ton</span>
                                </div>
                                <div class="stat">
                                    <span class="label">Falta:</span>
                                    <span class="value text-warning"><?= number_format(max(0, $frente['meta_cana'] - $frente['total_entregue']), 0, ',', '.') ?> ton</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ABA EQUIPAMENTOS -->
        <div class="tab-pane fade" id="nav-equipamentos" role="tabpanel">
            <div class="row g-4">
                <?php 
                $equipamentos_por_tipo = [];
                foreach ($equipamentos_colheita as $equip) {
                    $tipo = $equip['operacao_nome'] ?? 'Outros';
                    if (!isset($equipamentos_por_tipo[$tipo])) {
                        $equipamentos_por_tipo[$tipo] = [];
                    }
                    $equipamentos_por_tipo[$tipo][] = $equip;
                }
                ?>

                <?php foreach ($equipamentos_por_tipo as $tipo => $equipamentos): ?>
                <div class="col-12">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h6><i class="fas fa-tags"></i> <?= htmlspecialchars($tipo) ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <?php foreach ($equipamentos as $equip): ?>
                                <div class="col-xl-3 col-md-4 col-sm-6">
                                    <div class="equip-card">
                                        <div class="equip-icon">
                                            <i class="fas fa-tractor"></i>
                                        </div>
                                        <div class="equip-info">
                                            <h6><?= htmlspecialchars($equip['nome']) ?></h6>
                                            <span class="equip-unidade"><?= htmlspecialchars($equip['unidade_nome'] ?? 'N/A') ?></span>
                                            <span class="equip-status online">Online</span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ABA RELATÓRIOS -->
        <div class="tab-pane fade" id="nav-relatorios" role="tabpanel">
            <div class="row g-4">
                <div class="col-12">
                    <div class="ctt-card">
                        <div class="card-header">
                            <h5><i class="fas fa-history"></i> Registros Recentes</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Data/Hora</th>
                                            <th>Frente</th>
                                            <th>Equipamento</th>
                                            <th>Fazenda</th>
                                            <th>Rota</th>
                                            <th>Apontamento</th>
                                            <th>Tempo Carga</th>
                                            <th>Tempo Médio</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($registros_recentes as $registro): ?>
                                        <tr>
                                            <td><?= date('d/m/Y H:i', strtotime($registro['data'])) ?></td>
                                            <td><?= htmlspecialchars($registro['frente_nome']) ?></td>
                                            <td><?= htmlspecialchars($registro['equipamento_nome']) ?></td>
                                            <td><?= htmlspecialchars($registro['fazenda_nome'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($registro['rota'] ?? 'N/A') ?></td>
                                            <td><?= ucfirst(htmlspecialchars($registro['apontamento'])) ?></td>
                                            <td>
                                                <?php if ($registro['tempo_carregamento']): ?>
                                                    <?= $registro['tempo_carregamento'] ?>min
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($registro['tempo_medio']): ?>
                                                    <?= $registro['tempo_medio'] ?>min
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $registro['realizado'] == 'sim' ? 'success' : 'danger' ?>">
                                                    <?= ucfirst($registro['realizado']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
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
            <p>&copy; 2024 CTT Dashboard - Desenvolvido para Gestão de Colheita Mecanizada</p>
            <p class="version">v2.0 - <?= date('d/m/Y H:i') ?></p>
        </div>
    </div>
</footer>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
$(document).ready(function() {
    // Inicializar Select2
    $('.select-search').select2({
        placeholder: "Pesquisar...",
        allowClear: true,
        width: '100%'
    });

    // Atualizar relógio
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
        $('#current-time span').text(formatter.format(now));
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Gráfico de Produtividade
    const ctx = document.getElementById('produtividadeChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($frentes, 'nome')) ?>,
            datasets: [{
                label: 'Meta (ton)',
                data: <?= json_encode(array_column($frentes, 'meta_cana')) ?>,
                backgroundColor: 'rgba(54, 162, 235, 0.8)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }, {
                label: 'Entregue (ton)',
                data: <?= json_encode(array_column($frentes, 'total_entregue')) ?>,
                backgroundColor: 'rgba(75, 192, 192, 0.8)',
                borderColor: 'rgba(75, 192, 192, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Toneladas'
                    }
                }
            }
        }
    });

    // Animações suaves
    $('.ctt-card').hover(
        function() { $(this).addClass('card-hover'); },
        function() { $(this).removeClass('card-hover'); }
    );
});
</script>

</body>
</html>