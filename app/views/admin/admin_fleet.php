<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
    header("Location: /");
    exit();
}

require_once __DIR__ . '/../../../app/includes/header.php';
require_once __DIR__.'/../../../app/lib/Audit.php';

// Processar ações de implementos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add_implemento':
                $nome = $_POST['nome'];
                $modelo = $_POST['modelo'] ?? '';
                $numero_identificacao = $_POST['numero_identificacao'];
                
                // Verificar se o número de identificação já existe
                $stmt_check = $pdo->prepare("SELECT id FROM implementos WHERE numero_identificacao = ?");
                $stmt_check->execute([$numero_identificacao]);
                
                if ($stmt_check->rowCount() > 0) {
                    $_SESSION['error'] = "Já existe um implemento com este número de identificação!";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO implementos (nome, modelo, numero_identificacao) VALUES (?, ?, ?)");
                    $stmt->execute([$nome, $modelo, $numero_identificacao]);
                    $_SESSION['message'] = "Implemento adicionado com sucesso!";
                }
                break;
                
            case 'edit_implemento':
                $id = $_POST['id'];
                $nome = $_POST['nome'];
                $modelo = $_POST['modelo'] ?? '';
                $numero_identificacao = $_POST['numero_identificacao'];
                
                // Verificar se o número de identificação já existe em outro implemento
                $stmt_check = $pdo->prepare("SELECT id FROM implementos WHERE numero_identificacao = ? AND id != ?");
                $stmt_check->execute([$numero_identificacao, $id]);
                
                if ($stmt_check->rowCount() > 0) {
                    $_SESSION['error'] = "Já existe outro implemento com este número de identificação!";
                } else {
                    $stmt = $pdo->prepare("UPDATE implementos SET nome = ?, modelo = ?, numero_identificacao = ? WHERE id = ?");
                    $stmt->execute([$nome, $modelo, $numero_identificacao, $id]);
                    $_SESSION['message'] = "Implemento atualizado com sucesso!";
                }
                break;
                
            case 'delete_implemento':
                $id = $_GET['id'] ?? 0;
                
                // Verificar se o implemento está sendo usado por algum equipamento
                $stmt_check = $pdo->prepare("SELECT id FROM equipamentos WHERE implemento_id = ?");
                $stmt_check->execute([$id]);
                
                if ($stmt_check->rowCount() > 0) {
                    $_SESSION['error'] = "Não é possível excluir este implemento pois está vinculado a um ou mais equipamentos!";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM implementos WHERE id = ?");
                    $stmt->execute([$id]);
                    $_SESSION['message'] = "Implemento excluído com sucesso!";
                }
                break;
                
            case 'add':
                $nome = $_POST['nome'];
                $unidade_id = $_POST['unidade_id'];
                $operacao_id = $_POST['operacao_id'];
                $implemento_id = $_POST['implemento_id'] ?? null;
                $ativo        = isset($_POST['ativo']) ? 1 : 0;
                
                $stmt = $pdo->prepare("
                    INSERT INTO equipamentos (nome, unidade_id, operacao_id, implemento_id, ativo)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nome, $unidade_id, $operacao_id, $implemento_id, $ativo]);

                $_SESSION['message'] = "Equipamento adicionado com sucesso!";
                break;
                
            case 'edit':
                $id = $_GET['id'] ?? 0;
                $nome = $_POST['nome'];
                $unidade_id = $_POST['unidade_id'];
                $operacao_id = $_POST['operacao_id'];
                $implemento_id = $_POST['implemento_id'] ?? null;
                $ativo         = isset($_POST['ativo']) ? 1 : 0;
                
                $stmt = $pdo->prepare("
                    UPDATE equipamentos
                    SET nome = ?,
                        unidade_id = ?,
                        operacao_id = ?,
                        implemento_id = ?,
                        ativo = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nome, $unidade_id, $operacao_id, $implemento_id, $ativo, $id]);

                $_SESSION['message'] = "Equipamento atualizado com sucesso!";
                break;

                case 'toggle_status':
                $id     = $_GET['id'] ?? 0;
                $status = isset($_POST['status']) && $_POST['status'] == '1' ? 1 : 0;

                $stmt = $pdo->prepare("UPDATE equipamentos SET ativo = ? WHERE id = ?");
                $stmt->execute([$status, $id]);

                $_SESSION['message'] = $status ? "Equipamento ativado." : "Equipamento inativado.";
                break;
                
            case 'delete':
                $id = $_GET['id'] ?? 0;
                
                $stmt = $pdo->prepare("DELETE FROM equipamentos WHERE id = ?");
                $stmt->execute([$id]);
                
                $_SESSION['message'] = "Equipamento excluído com sucesso!";
                break;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao executar ação: " . $e->getMessage();
    }
    
header("Location: /admin_fleet");
    exit();
}

// Buscar unidades, operações e implementos para filtros
$unidades = $pdo->query("SELECT id, nome FROM unidades ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$operacoes = $pdo->query("SELECT id, nome FROM tipos_operacao ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$implementos = $pdo->query("SELECT id, nome, modelo, numero_identificacao FROM implementos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Filtros (GET)
$filtro_unidade     = $_GET['unidade']      ?? '';
$filtro_operacao_id = $_GET['operacao_id']  ?? '';
$filtro_implemento  = $_GET['implemento']   ?? '';
$filtro_status = isset($_GET['status']) ? $_GET['status'] : '';
$visualizacao       = $_GET['visualizacao'] ?? 'cards'; // cards ou lista

// Mapa de ícones por operacao_id (1=ACOP, 2=PLANTIO, 3=SUBSOLAGEM, 4=VINHAÇA)
$iconePorOperacao = [
    1 => 'fa-tractor text-success',      // ACOP
    2 => 'fa-seedling text-primary',     // PLANTIO
    3 => 'fa-person-digging text-warning', // SUBSOLAGEM
    4 => 'fa-wine-bottle text-danger'    // VINHAÇA
];

// Query principal já trazendo o nome da operação
$sql = "SELECT 
            e.*,
            u.nome AS unidade_nome,
            i.nome AS implemento_nome,
            i.modelo AS implemento_modelo,
            i.numero_identificacao,
            t.nome AS operacao_nome
        FROM equipamentos e
        LEFT JOIN unidades u       ON e.unidade_id   = u.id
        LEFT JOIN implementos i    ON e.implemento_id = i.id
        LEFT JOIN tipos_operacao t  ON e.operacao_id  = t.id
        WHERE 1=1";

$params = [];
if ($filtro_unidade) {
    $sql .= " AND e.unidade_id = :unidade";
    $params[':unidade'] = $filtro_unidade;
}
if ($filtro_operacao_id) {
    $sql .= " AND e.operacao_id = :operacao_id";
    $params[':operacao_id'] = $filtro_operacao_id;
}
if ($filtro_implemento) {
    $sql .= " AND e.implemento_id = :implemento";
    $params[':implemento'] = $filtro_implemento;
}
if ($filtro_status !== '' && ($filtro_status === '0' || $filtro_status === '1')) {
    $sql .= " AND e.ativo = :ativo";
    $params[':ativo'] = (int)$filtro_status;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$equipamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Paginação
$itens_por_pagina = $visualizacao === 'lista' ? 10 : 12;
$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$total_itens = count($equipamentos);
$total_paginas = ceil($total_itens / $itens_por_pagina);
$inicio = ($pagina_atual - 1) * $itens_por_pagina;
$equipamentos_paginados = array_slice($equipamentos, $inicio, $itens_por_pagina);
?>

<!-- Bootstrap 5 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- FontAwesome para ícones -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">

<!-- Bootstrap 5 JS (necessário para modais, dropdowns etc) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- CSS personalizado -->
<link rel="stylesheet" href="/public/static/css/admin_fleet.css">


<div class="container mt-4">
    <h2 class="mb-4 text-center">🚜 Controle de Frota</h2>

    <!-- Mensagens de feedback -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $_SESSION['message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Filtros e controles -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" id="filtroForm" class="row g-3 align-items-end">
                <input type="hidden" name="visualizacao" value="<?= $visualizacao ?>">
                
                <div class="col-md-3">
                    <select name="unidade" class="form-select filtro-select" id="filtroUnidade">
                        <option value="">Todas as Unidades</option>
                        <?php foreach ($unidades as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $filtro_unidade == $u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <select name="operacao_id" class="form-select filtro-select" id="filtroOperacao">
                        <option value="">Todas as Operações</option>
                        <?php foreach ($operacoes as $op): ?>
                            <option value="<?= $op['id'] ?>" <?= (string)$filtro_operacao_id === (string)$op['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($op['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <select name="implemento" class="form-select filtro-select" id="filtroImplemento">
                        <option value="">Todos os Implementos</option>
                        <?php foreach ($implementos as $imp): ?>
                            <option value="<?= $imp['id'] ?>" <?= $filtro_implemento == $imp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($imp['nome']) ?> (<?= htmlspecialchars($imp['numero_identificacao']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <select name="status" class="form-select filtro-select" id="filtroStatus">
                        <option value="">Todos (Ativo/Inativo)</option>
                        <option value="1" <?= $filtro_status==='1'?'selected':''; ?>>Somente Ativos</option>
                        <option value="0" <?= $filtro_status==='0'?'selected':''; ?>>Somente Inativos</option>
                    </select>
                </div>

                <div class="col-auto ms-auto">
                    <div class="d-flex align-items-center">
                        <span class="me-2">Visualização:</span>
                        <div class="btn-group" role="group">
                        <a href="?<?= http_build_query(array_merge($_GET, ['visualizacao' => 'cards'])) ?>" 
                            class="btn btn-outline-primary <?= $visualizacao === 'cards' ? 'active' : '' ?>">
                            <i class="fas fa-th-large"></i> Cards
                        </a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['visualizacao' => 'lista'])) ?>" 
                            class="btn btn-outline-primary <?= $visualizacao === 'lista' ? 'active' : '' ?>">
                            <i class="fas fa-list"></i> Lista
                        </a>
                        </div>
                    </div>
                </div>
            </form>
            
            <div class="row mt-3">
                <div class="col-12 text-end">
                    <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#modalAdd">
                        <i class="fas fa-plus"></i> Adicionar Frota
                    </button>
                    <button class="btn btn-info me-2" data-bs-toggle="modal" data-bs-target="#modalAddImplemento">
                        <i class="fas fa-plus"></i> Adicionar Implemento
                    </button>
                    <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#modalGerenciarImplementos">
                        <i class="fas fa-cog"></i> Gerenciar Implementos
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Indicador de carregamento -->
    <div id="loading" class="mb-4">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Carregando...</span>
        </div>
        <p class="mt-2">Filtrando equipamentos...</p>
    </div>

    <!-- Container para os resultados -->
    <div id="resultados-container">
        <!-- Exibição dos equipamentos -->
        <?php if ($visualizacao === 'cards'): ?>
            <!-- Visualização em Cards -->
            <div class="row g-4">
                <?php foreach ($equipamentos_paginados as $eq): 
                    // Definir ícone conforme operação
                    $icone = $iconePorOperacao[(int)($eq['operacao_id'] ?? 0)] ?? 'fa-tractor text-secondary';
                ?>
                <div class="col-md-3">
                    <div class="card shadow-lg border-0 h-100">
                        <div class="card-body text-center">
                            <i class="fas <?= $icone ?> fa-3x mb-3"></i>
                            <h5 class="card-title"><?= htmlspecialchars($eq['nome']) ?></h5>
                            <p class="card-text"><strong>Unidade:</strong> <?= htmlspecialchars($eq['unidade_nome'] ?? 'Não vinculada') ?></p>
                            <p class="card-text"><strong>Operação:</strong> <?= htmlspecialchars($eq['operacao_nome'] ?? 'N/A') ?></p>
                            
                            <?php if (!empty($eq['implemento_nome'])): ?>
                                <p class="card-text">
                                    <strong>Implemento:</strong> <?= htmlspecialchars($eq['implemento_nome']) ?>
                                    <?php if (!empty($eq['implemento_modelo'])): ?>
                                        <br><small>Modelo: <?= htmlspecialchars($eq['implemento_modelo']) ?></small>
                                    <?php endif; ?>
                                    <br><span class="implemento-badge">#<?= htmlspecialchars($eq['numero_identificacao']) ?></span>
                                </p>
                            <?php else: ?>
                                <p class="card-text text-muted">Sem implemento atribuído</p>
                            <?php endif; ?>
                            
                            <td>
                            <?php if ((int)$eq['ativo'] === 1): ?>
                                <span class="badge bg-success">Ativo</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inativo</span>
                            <?php endif; ?>
                            </td>

                            <div class="d-flex justify-content-around mt-3">
                                <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $eq['id'] ?>">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalDelete<?= $eq['id'] ?>">
                                    <i class="fas fa-trash"></i> Excluir
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Editar -->
                <div class="modal fade" id="modalEdit<?= $eq['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form method="POST" action="?action=edit&id=<?= $eq['id'] ?>" class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Editar Frota <?= htmlspecialchars($eq['nome']) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Nome</label>
                                    <input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($eq['nome']) ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Unidade</label>
                                    <select name="unidade_id" class="form-select">
                                        <option value="">Selecione a Unidade</option>
                                        <?php foreach ($unidades as $u): ?>
                                            <option value="<?= $u['id'] ?>" <?= $eq['unidade_id'] == $u['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($u['nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Operação</label>
                                    <select name="operacao_id" class="form-select" required>
                                        <option value="">Selecione a Operação</option>
                                        <?php foreach ($operacoes as $op): ?>
                                            <option value="<?= $op['id'] ?>" <?= (string)$eq['operacao_id'] === (string)$op['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($op['nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Implemento</label>
                                    <select name="implemento_id" class="form-select">
                                        <option value="">Selecione um Implemento</option>
                                        <?php foreach ($implementos as $imp): ?>
                                            <option value="<?= $imp['id'] ?>" <?= $eq['implemento_id'] == $imp['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($imp['nome']) ?> (<?= htmlspecialchars($imp['numero_identificacao']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-check form-switch">
                                <input class="form-check-input"
                                        type="checkbox"
                                        id="editAtivo<?= $eq['id'] ?>"
                                        name="ativo"
                                        <?= (int)$eq['ativo'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="editAtivo<?= $eq['id'] ?>">Ativo</label>
                                </div>

                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-success">Salvar</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Modal Excluir -->
                <div class="modal fade" id="modalDelete<?= $eq['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form method="POST" action="?action=delete&id=<?= $eq['id'] ?>" class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Excluir Frota</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                Tem certeza que deseja excluir <strong><?= htmlspecialchars($eq['nome']) ?></strong>?
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-danger">Excluir</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Visualização em Lista -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Ícone</th>
                                    <th>Nome</th>
                                    <th>Unidade</th>
                                    <th>Operação</th>
                                    <th>Implemento</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($equipamentos_paginados as $eq): 
                                    $icone = $iconePorOperacao[(int)($eq['operacao_id'] ?? 0)] ?? 'fa-tractor text-secondary';
                                ?>
                                <tr>
                                    <td><i class="fas <?= $icone ?> fa-2x"></i></td>
                                    <td><?= htmlspecialchars($eq['nome']) ?></td>
                                    <td><?= htmlspecialchars($eq['unidade_nome'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($eq['operacao_nome'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php if (!empty($eq['implemento_nome'])): ?>
                                            <?= htmlspecialchars($eq['implemento_nome']) ?> 
                                            (<?= htmlspecialchars($eq['numero_identificacao']) ?>)
                                        <?php else: ?>
                                            <span class="text-muted">Nenhum</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $eq['id'] ?>">
                                            <i class="fas fa-edit"></i>
                                            </button>

                                            <form method="POST"
                                                action="?<?= http_build_query(array_merge($_GET, ['action'=>'toggle_status','id'=>$eq['id']])) ?>"
                                                style="display:inline;">
                                            <input type="hidden" name="status" value="<?= (int)$eq['ativo'] ? '0' : '1' ?>">
                                            <button type="submit"
                                                    class="btn btn-outline-<?= (int)$eq['ativo'] ? 'secondary' : 'success' ?>">
                                                <i class="fas <?= (int)$eq['ativo'] ? 'fa-ban' : 'fa-check' ?>"></i>
                                            </button>
                                            </form>

                                            <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalDelete<?= $eq['id'] ?>">
                                            <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Paginação -->
        <?php if ($total_paginas > 1): ?>
            <nav aria-label="Page navigation" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $pagina_atual <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_atual - 1])) ?>">Anterior</a>
                    </li>
                    
                    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                        <li class="page-item <?= $i == $pagina_atual ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <li class="page-item <?= $pagina_atual >= $total_paginas ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_atual + 1])) ?>">Próxima</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Adicionar Frota -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="?action=add" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adicionar Nova Frota</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nome</label>
                    <input type="text" name="nome" class="form-control" placeholder="Nome da frota" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Unidade</label>
                    <select name="unidade_id" class="form-select">
                        <option value="">Selecione a Unidade</option>
                        <?php foreach ($unidades as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Operação</label>
                    <select name="operacao_id" class="form-select" required>
                        <option value="">Selecione a Operação</option>
                        <?php foreach ($operacoes as $op): ?>
                            <option value="<?= $op['id'] ?>"><?= htmlspecialchars($op['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Implemento</label>
                    <select name="implemento_id" class="form-select">
                        <option value="">Selecione um Implemento</option>
                        <?php foreach ($implementos as $imp): ?>
                            <option value="<?= $imp['id'] ?>"><?= htmlspecialchars($imp['nome']) ?> (<?= htmlspecialchars($imp['numero_identificacao']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="addAtivo" name="ativo" checked>
                <label class="form-check-label" for="addAtivo">Ativo</label>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success">Adicionar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Adicionar Implemento -->
<div class="modal fade" id="modalAddImplemento" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="?action=add_implemento" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adicionar Novo Implemento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nome</label>
                    <input type="text" name="nome" class="form-control" placeholder="Nome do implemento" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Modelo</label>
                    <input type="text" name="modelo" class="form-control" placeholder="Modelo do implemento">
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Número de identificação</label>
                    <input type="text" name="numero_identificacao" class="form-control" placeholder="Número de identificação" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success">Adicionar Implemento</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Gerenciar Implementos -->
<div class="modal fade" id="modalGerenciarImplementos" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Gerenciar Implementos</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-striped">
            <thead>
              <tr>
                <th>Nome</th>
                <th>Modelo</th>
                <th>Número de Identificação</th>
                <th>Ações</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($implementos as $imp): ?>
                <tr>
                  <td><?= htmlspecialchars($imp['nome']) ?></td>
                  <td><?= htmlspecialchars($imp['modelo'] ?? 'N/A') ?></td>
                  <td><?= htmlspecialchars($imp['numero_identificacao']) ?></td>
                  <td>
                    <button type="button"
                            class="btn btn-sm btn-warning btn-open-edit-imp"
                            data-id="<?= $imp['id'] ?>"
                            data-nome="<?= htmlspecialchars($imp['nome'], ENT_QUOTES) ?>"
                            data-modelo="<?= htmlspecialchars($imp['modelo'] ?? '', ENT_QUOTES) ?>"
                            data-numero="<?= htmlspecialchars($imp['numero_identificacao'], ENT_QUOTES) ?>">
                      <i class="fas fa-edit"></i> Editar
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-danger"
                            data-bs-toggle="modal"
                            data-bs-target="#modalDeleteImplemento<?= $imp['id'] ?>">
                      <i class="fas fa-trash"></i> Excluir
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Editar Implemento (dinâmico) -->
<div class="modal fade" id="modalEditImplemento" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" action="?action=edit_implemento" class="modal-content" id="formEditImplemento">
      <input type="hidden" name="id" id="edit_imp_id" value="">
      <div class="modal-header">
        <h5 class="modal-title">Editar Implemento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Nome</label>
          <input type="text" name="nome" id="edit_imp_nome" class="form-control" required>
        </div>
        
        <div class="mb-3">
          <label class="form-label">Modelo</label>
          <input type="text" name="modelo" id="edit_imp_modelo" class="form-control">
        </div>
        
        <div class="mb-3">
          <label class="form-label">Número de identificação</label>
          <input type="text" name="numero_identificacao" id="edit_imp_numero" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-success">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Excluir Implemento (fora do loop) -->
<?php foreach ($implementos as $imp): ?>
<div class="modal fade" id="modalDeleteImplemento<?= $imp['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" action="?action=delete_implemento&id=<?= $imp['id'] ?>" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Excluir Implemento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Tem certeza que deseja excluir o implemento <strong><?= htmlspecialchars($imp['nome']) ?></strong>?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-danger">Excluir</button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<!-- Bootstrap 5 JS (necessário para modais, dropdowns etc) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- JavaScript para manipulação dos modais e filtro automático -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Abrir modal de edição de implemento com dados preenchidos
    document.querySelectorAll('.btn-open-edit-imp').forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const nome = this.getAttribute('data-nome');
            const modelo = this.getAttribute('data-modelo');
            const numero = this.getAttribute('data-numero');
            
            document.getElementById('edit_imp_id').value = id;
            document.getElementById('edit_imp_nome').value = nome;
            document.getElementById('edit_imp_modelo').value = modelo;
            document.getElementById('edit_imp_numero').value = numero;
            
            const modal = new bootstrap.Modal(document.getElementById('modalEditImplemento'));
            modal.show();
        });
    });
    
    // Limpar formulários ao fechar os modais
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('hidden.bs.modal', function() {
            const forms = this.querySelectorAll('form');
            forms.forEach(form => form.reset());
        });
    });
    
    // Filtro automático quando os selects são alterados
    const filtroForm = document.getElementById('filtroForm');
    const filtroUnidade = document.getElementById('filtroUnidade');
    const filtroOperacao = document.getElementById('filtroOperacao');
    const filtroImplemento = document.getElementById('filtroImplemento');
    const filtroStatus = document.getElementById('filtroStatus');
    const loadingElement = document.getElementById('loading');
    const resultadosContainer = document.getElementById('resultados-container');
    
    // Função para aplicar filtros via AJAX
    function aplicarFiltros() {
        // Mostrar indicador de carregamento
        loadingElement.style.display = 'block';
        resultadosContainer.style.opacity = '0.5';
        
        // Coletar valores dos filtros
        const filtros = {
            unidade: filtroUnidade.value,
            operacao_id: filtroOperacao.value,
            implemento: filtroImplemento.value,
            status: filtroStatus.value,
            visualizacao: '<?= $visualizacao ?>'
        };
        
        // Criar URL de busca
        const params = new URLSearchParams();
        for (const key in filtros) {
            if (filtros[key]) {
                params.append(key, filtros[key]);
            }
        }
        
        // Fazer requisição AJAX
        fetch('?' + params.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            // Extrair apenas a parte dos resultados da resposta
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const novosResultados = doc.getElementById('resultados-container');
            
            if (novosResultados) {
                resultadosContainer.innerHTML = novosResultados.innerHTML;
                
                // Atualizar URL sem recarregar a página
                window.history.replaceState({}, '', '?' + params.toString());
            }
        })
        .catch(error => {
            console.error('Erro ao filtrar:', error);
        })
        .finally(() => {
            // Ocultar indicador de carregamento
            loadingElement.style.display = 'none';
            resultadosContainer.style.opacity = '1';
        });
    }
    
    // Adicionar event listeners para os filtros
    [filtroUnidade, filtroOperacao, filtroImplemento, filtroStatus].forEach(select => {
    select.addEventListener('change', aplicarFiltros);
    });
    
    // Prevenir envio tradicional do formulário
    filtroForm.addEventListener('submit', function(e) {
        e.preventDefault();
        aplicarFiltros();
    });
});


</script>

<!-- Créditos -->
<div class="signature-credit">
  <p class="sig-text">
    Desenvolvido por 
    <span class="sig-name">Bruno Carmo</span> & 
    <span class="sig-name">Henrique Reis</span>
  </p>
</div>

<?php
require_once __DIR__ . '/../../../app/includes/footer.php';
?>
