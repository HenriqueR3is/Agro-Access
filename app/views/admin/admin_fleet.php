
<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: /app/views/login.php");
    exit();
}
require_once __DIR__ . '/../../../app/includes/header.php';

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
                $operacao = $_POST['operacao'];
                $implemento_id = $_POST['implemento_id'] ?? null;
                
                $stmt = $pdo->prepare("INSERT INTO equipamentos (nome, unidade_id, operacao, implemento_id) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nome, $unidade_id, $operacao, $implemento_id]);
                
                $_SESSION['message'] = "Equipamento adicionado com sucesso!";
                break;
                
            case 'edit':
                $id = $_GET['id'] ?? 0;
                $nome = $_POST['nome'];
                $unidade_id = $_POST['unidade_id'];
                $operacao = $_POST['operacao'];
                $implemento_id = $_POST['implemento_id'] ?? null;
                
                $stmt = $pdo->prepare("UPDATE equipamentos SET nome = ?, unidade_id = ?, operacao = ?, implemento_id = ? WHERE id = ?");
                $stmt->execute([$nome, $unidade_id, $operacao, $implemento_id, $id]);
                
                $_SESSION['message'] = "Equipamento atualizado com sucesso!";
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
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Buscar unidades, operações e implementos para filtros
$unidades = $pdo->query("SELECT DISTINCT id, nome FROM unidades ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$operacoes = $pdo->query("SELECT DISTINCT operacao FROM equipamentos WHERE operacao IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$implementos = $pdo->query("SELECT id, nome, modelo, numero_identificacao FROM implementos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Filtro aplicado
$filtro_unidade = $_GET['unidade'] ?? '';
$filtro_operacao = $_GET['operacao'] ?? '';
$filtro_implemento = $_GET['implemento'] ?? '';

$sql = "SELECT e.*, u.nome AS unidade_nome, i.nome AS implemento_nome, i.modelo AS implemento_modelo, i.numero_identificacao 
        FROM equipamentos e
        LEFT JOIN unidades u ON e.unidade_id = u.id
        LEFT JOIN implementos i ON e.implemento_id = i.id
        WHERE 1=1";

$params = [];
if ($filtro_unidade) {
    $sql .= " AND e.unidade_id = :unidade";
    $params[':unidade'] = $filtro_unidade;
}
if ($filtro_operacao) {
    $sql .= " AND e.operacao = :operacao";
    $params[':operacao'] = $filtro_operacao;
}
if ($filtro_implemento) {
    $sql .= " AND e.implemento_id = :implemento";
    $params[':implemento'] = $filtro_implemento;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$equipamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!-- Bootstrap 5 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- FontAwesome para ícones -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<!-- Bootstrap 5 JS (necessário para modais, dropdowns etc) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<style>
/* ==============================
   ESTILOS GERAIS
   ============================== */
body {
    background: linear-gradient(135deg, #eef2f3, #d9e4ec);
    font-family: 'Poppins', sans-serif;
    color: #2c3e50;
    margin: 0;
    padding: 0;
    min-height: 100vh;
}

/* Títulos principais */
h2 {
    font-weight: 700;
    color: #2c3e50;
    text-shadow: 1px 2px 4px rgba(0,0,0,0.1);
    letter-spacing: 1px;
    margin-bottom: 30px;
    animation: fadeInDown 1s ease-in-out;
}

/* ==============================
   FILTROS
   ============================== */
form select, form button {
    border-radius: 14px;
    font-size: 15px;
    transition: all 0.3s ease;
    box-shadow: 0 3px 6px rgba(0,0,0,0.05);
}

form select {
    padding: 10px;
}

form select:focus {
    border-color: #2ecc71;
    box-shadow: 0 0 12px rgba(46, 204, 113, 0.4);
    transform: scale(1.02);
}

form button {
    background: linear-gradient(135deg, #27ae60, #2ecc71);
    border: none;
    font-weight: 600;
    padding: 12px;
    color: white;
    box-shadow: 0 4px 8px rgba(39, 174, 96, 0.3);
}

form button:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 16px rgba(39, 174, 96, 0.4);
}

/* ==============================
   BOTÃO ADICIONAR
   ============================== */
.btn-primary {
    background: linear-gradient(135deg, #2980b9, #3498db);
    border: none;
    border-radius: 14px;
    font-weight: 600;
    padding: 12px 24px;
    box-shadow: 0 5px 10px rgba(52, 152, 219, 0.3);
    transition: all 0.3s ease-in-out;
}

.btn-primary:hover {
    transform: translateY(-3px) scale(1.05);
    background: linear-gradient(135deg, #3498db, #2980b9);
    box-shadow: 0 8px 18px rgba(52, 152, 219, 0.4);
}

.btn-info {
    background: linear-gradient(135deg, #17a2b8, #2ecc71);
    border: none;
    border-radius: 14px;
    font-weight: 600;
    padding: 12px 24px;
    box-shadow: 0 5px 10px rgba(23, 162, 184, 0.3);
    transition: all 0.3s ease-in-out;
}

.btn-info:hover {
    transform: translateY(-3px) scale(1.05);
    background: linear-gradient(135deg, #2ecc71, #17a2b8);
    box-shadow: 0 8px 18px rgba(23, 162, 184, 0.4);
}

/* ==============================
   CARDS DE FROTA
   ============================== */
.card {
    border-radius: 18px;
    transition: all 0.4s ease;
    background: #fff;
    overflow: hidden;
    position: relative;
    animation: fadeInUp 0.8s ease forwards;
}

.card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 14px 28px rgba(0,0,0,0.15);
}

.card-body {
    padding: 25px 20px;
}

/* Efeito de linha colorida no topo */
.card::before {
    content: "";
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 6px;
    background: linear-gradient(90deg, #2ecc71, #27ae60);
    opacity: 0.8;
    transition: height 0.3s ease;
}

.card:hover::before {
    height: 10px;
}

/* Ícones */
.card-body i {
    transition: transform 0.3s ease, color 0.3s ease;
    animation: popIn 0.6s ease;
}

.card-body i:hover {
    transform: rotate(-8deg) scale(1.25);
    filter: drop-shadow(0 3px 5px rgba(0,0,0,0.2));
}

/* Título */
.card-title {
    font-size: 1.3rem;
    font-weight: 600;
    margin-bottom: 10px;
    color: #34495e;
}

/* Texto */
.card-text {
    font-size: 0.95rem;
    margin-bottom: 6px;
}

/* Implemento badge */
.implemento-badge {
    display: inline-block;
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.8rem;
    margin-top: 5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* ==============================
   ÍCONES PERSONALIZADOS
   ============================== */
.fa-tractor.text-success { color: #27ae60 !important; }
.fa-wine-bottle.text-danger { color: #e74c3c !important; }
.fa-seedling.text-primary { color: #2980b9 !important; }
.fa-tractor.text-secondary { color: #7f8c8d !important; }

/* ==============================
   BOTÕES NOS CARDS
   ============================== */
.card .btn {
    border-radius: 10px;
    font-size: 14px;
    padding: 8px 14px;
    transition: all 0.25s ease-in-out;
}

.card .btn-warning {
    background: #f39c12;
    border: none;
    color: #fff;
    box-shadow: 0 3px 6px rgba(243, 156, 18, 0.3);
}

.card .btn-warning:hover {
    background: #e67e22;
    transform: scale(1.08);
    box-shadow: 0 6px 12px rgba(243, 156, 18, 0.4);
}

.card .btn-danger {
    background: #e74c3c;
    border: none;
    color: #fff;
    box-shadow: 0 3px 6px rgba(231, 76, 60, 0.3);
}

.card .btn-danger:hover {
    background: #c0392b;
    transform: scale(1.08);
    box-shadow: 0 6px 12px rgba(231, 76, 60, 0.4);
}

/* ==============================
   MODAIS
   ============================== */
.modal-content {
    border-radius: 16px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    border: none;
    animation: zoomIn 0.4s ease;
}

.modal-header {
    background: linear-gradient(135deg, #2c3e50, #34495e);
    color: #fff;
    border-radius: 16px 16px 0 0;
}

.modal-header h5 {
    font-weight: 600;
}

.modal-footer button {
    border-radius: 10px;
    padding: 8px 16px;
    transition: transform 0.2s ease;
}

.modal-footer button:hover {
    transform: translateY(-2px);
}

/* ==============================
   ANIMAÇÕES
   ============================== */
@keyframes fadeInDown {
    from { opacity: 0; transform: translateY(-20px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes zoomIn {
    from { transform: scale(0.8); opacity: 0; }
    to   { transform: scale(1); opacity: 1; }
}

@keyframes popIn {
    0% { transform: scale(0.5); opacity: 0; }
    80% { transform: scale(1.2); opacity: 1; }
    100% { transform: scale(1); }
}

/* ==============================
   RESPONSIVIDADE
   ============================== */
@media (max-width: 992px) {
    .card-title { font-size: 1.1rem; }
    .card-text { font-size: 0.9rem; }
}

@media (max-width: 768px) {
    .card {
        margin-bottom: 20px;
    }
    .btn-primary, .btn-info {
        width: 100%;
        margin-bottom: 10px;
    }
}

@media (max-width: 576px) {
    h2 {
        font-size: 1.5rem;
    }
    .card-body {
        padding: 18px;
    }
    .card-body i {
        font-size: 2.2rem;
    }
}

/* Alertas */
.alert {
    border-radius: 12px;
    border: none;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.alert-success {
    background: linear-gradient(135deg, #2ecc71, #27ae60);
    color: white;
}

.alert-danger {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
}
</style>
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

    <!-- Filtros -->
    <form method="GET" class="row mb-4">
        <div class="col-md-3">
            <select name="unidade" class="form-select">
                <option value="">Todas as Unidades</option>
                <?php foreach ($unidades as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filtro_unidade == $u['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- Filtro de Operação -->
        <div class="col-md-3">
            <select name="operacao" class="form-select">
                <option value="">Todas as Operações</option>
                <?php 
                $operacoes_fixas = ["ACOP", "SUBSOLAGEM", "PLANTIO", "VINHAÇA"];
                foreach ($operacoes_fixas as $op): ?>
                    <option value="<?= $op ?>" <?= $filtro_operacao == $op ? 'selected' : '' ?>>
                        <?= $op ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- Filtro de Implemento -->
        <div class="col-md-3">
            <select name="implemento" class="form-select">
                <option value="">Todos os Implementos</option>
                <?php foreach ($implementos as $imp): ?>
                    <option value="<?= $imp['id'] ?>" <?= $filtro_implemento == $imp['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($imp['nome']) ?> (<?= htmlspecialchars($imp['numero_identificacao']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-success w-100">Filtrar</button>
        </div>
    </form>

    <!-- Botões adicionar -->
    <div class="mb-4 text-end">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdd">+ Adicionar Frota</button>
        <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalAddImplemento">+ Adicionar Implemento</button>
        <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#modalGerenciarImplementos">Gerenciar Implementos</button>
    </div>

    <!-- Cards de frota -->
    <div class="row g-4">
        <?php foreach ($equipamentos as $eq): 
            // Definir ícone conforme operação
            $icone = "fa-tractor text-secondary";
            if ($eq['operacao'] === 'ACOP') $icone = "fa-tractor text-success";
            elseif ($eq['operacao'] === 'VINHAÇA') $icone = "fa-wine-bottle text-danger";
            elseif ($eq['operacao'] === 'PLANTIO') $icone = "fa-seedling text-primary";
        ?>
        <div class="col-md-3">
            <div class="card shadow-lg border-0 h-100">
                <div class="card-body text-center">
                    <i class="fas <?= $icone ?> fa-3x mb-3"></i>
                    <h5 class="card-title"><?= htmlspecialchars($eq['nome']) ?></h5>
                    <p class="card-text"><strong>Unidade:</strong> <?= htmlspecialchars($eq['unidade_nome'] ?? 'Não vinculada') ?></p>
                    <p class="card-text"><strong>Operação:</strong> <?= htmlspecialchars($eq['operacao']) ?></p>
                    
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
                    
                    <div class="d-flex justify-content-around mt-3">
                        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $eq['id'] ?>">Editar</button>
                        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalDelete<?= $eq['id'] ?>">Excluir</button>
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
                        <input type="text" name="nome" class="form-control mb-3" value="<?= htmlspecialchars($eq['nome']) ?>" required>
                        <select name="unidade_id" class="form-select mb-3">
                            <option value="">Selecione a Unidade</option>
                            <?php foreach ($unidades as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $eq['unidade_id'] == $u['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="operacao" class="form-select mb-3">
                            <option value="">Selecione a Operação</option>
                            <?php foreach (["ACOP", "SUBSOLAGEM", "PLANTIO", "VINHAÇA"] as $op): ?>
                                <option value="<?= $op ?>" <?= $eq['operacao'] == $op ? 'selected' : '' ?>>
                                    <?= $op ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="implemento_id" class="form-select">
                            <option value="">Selecione um Implemento</option>
                            <?php foreach ($implementos as $imp): ?>
                                <option value="<?= $imp['id'] ?>" <?= $eq['implemento_id'] == $imp['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($imp['nome']) ?> (<?= htmlspecialchars($imp['numero_identificacao']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                <input type="text" name="nome" class="form-control mb-3" placeholder="Nome da frota" required>
                <select name="unidade_id" class="form-select mb-3">
                    <option value="">Selecione a Unidade</option>
                    <?php foreach ($unidades as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="operacao" class="form-select mb-3">
                    <option value="">Selecione a Operação</option>
                    <?php foreach (["ACOP", "SUBSOLAGEM", "PLANTIO", "VINHAÇA"] as $op): ?>
                        <option value="<?= $op ?>"><?= $op ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="implemento_id" class="form-select">
                    <option value="">Selecione um Implemento</option>
                    <?php foreach ($implementos as $imp): ?>
                        <option value="<?= $imp['id'] ?>"><?= htmlspecialchars($imp['nome']) ?> (<?= htmlspecialchars($imp['numero_identificacao']) ?>)</option>
                    <?php endforeach; ?>
                </select>
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
                <input type="text" name="nome" class="form-control mb-3" placeholder="Nome do implemento" required>
                <input type="text" name="modelo" class="form-control mb-3" placeholder="Modelo do implemento">
                <input type="text" name="numero_identificacao" class="form-control" placeholder="Número de identificação" required>
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
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
                                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#modalEditImplemento<?= $imp['id'] ?>">Editar</button>
                                        <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#modalDeleteImplemento<?= $imp['id'] ?>">Excluir</button>
                                    </td>
                                </tr>
                                
                                <!-- Modal Editar Implemento -->
                                <div class="modal fade" id="modalEditImplemento<?= $imp['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <form method="POST" action="?action=edit_implemento" class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Editar Implemento</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="id" value="<?= $imp['id'] ?>">
                                                <input type="text" name="nome" class="form-control mb-3" value="<?= htmlspecialchars($imp['nome']) ?>" required>
                                                <input type="text" name="modelo" class="form-control mb-3" value="<?= htmlspecialchars($imp['modelo'] ?? '') ?>" placeholder="Modelo do implemento">
                                                <input type="text" name="numero_identificacao" class="form-control" value="<?= htmlspecialchars($imp['numero_identificacao']) ?>" required>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-success">Salvar</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                
                                <!-- Modal Excluir Implemento -->
                                <div class="modal fade" id="modalDeleteImplemento<?= $imp['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <form method="POST" action="?action=delete_implemento&id=<?= $imp['id'] ?>" class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Excluir Implemento</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                Tem certeza que deseja excluir o implemento <strong><?= htmlspecialchars($imp['nome']) ?></strong>?
                                                <?php
                                                // Verificar se o implemento está sendo usado
                                                $stmt_check = $pdo->prepare("SELECT id FROM equipamentos WHERE implemento_id = ?");
                                                $stmt_check->execute([$imp['id']]);
                                                if ($stmt_check->rowCount() > 0): ?>
                                                    <div class="alert alert-warning mt-2">
                                                        <i class="fas fa-exclamation-triangle"></i> Este implemento está vinculado a um ou mais equipamentos e não pode ser excluído.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                <?php if ($stmt_check->rowCount() == 0): ?>
                                                    <button type="submit" class="btn btn-danger">Excluir</button>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    </div>
                                </div>
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

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>
