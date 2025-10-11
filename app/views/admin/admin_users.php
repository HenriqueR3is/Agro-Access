<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';
require_once __DIR__ . '/../../../app/lib/Audit.php';

$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
    header("Location: /");
    exit();
}

// Lógica de CRUD
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Detecta se é uma chamada AJAX pra responder JSON
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // Helper rápido pra responder JSON e sair
    $jsonOut = function(array $payload) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload);
        exit();
    };

    try {
        $action = $_POST['action'] ?? '';
        $TIPOS_VALIDOS = ['operador','coordenador','admin','cia_user','cia_admin','cia_dev'];
        
        if ($action === 'add_user' || $action === 'edit_user') {
            $nome  = trim($_POST['nome'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $tipo  = strtolower(trim($_POST['tipo'] ?? 'operador'));
            $ativo = isset($_POST['ativo']) ? 1 : 0;

            $unidades  = array_values(array_filter(array_map('intval', $_POST['unidades']  ?? [])));
            $operacoes = array_values(array_filter(array_map('intval', $_POST['operacoes'] ?? [])));
            $unidade_principal = $unidades[0] ?? null;

            if ($nome === '' || $email === '') {
                if ($isAjax) $jsonOut(['success' => false, 'error' => 'Preencha nome e e-mail.']);
                $_SESSION['error_message'] = "Preencha nome e e-mail.";
                header("Location: /admin_users");
                exit();
            }
            if (!in_array($tipo, $TIPOS_VALIDOS, true)) {
                if ($isAjax) $jsonOut(['success' => false, 'error' => 'Tipo de usuário inválido.']);
                $_SESSION['error_message'] = "Tipo de usuário inválido.";
                header("Location: /admin_users");
                exit();
            }

            $pdo->beginTransaction();

            if ($action === 'add_user') {
                $senha_plana = $_POST['senha'] ?? '';
                if ($senha_plana === '') {
                    $pdo->rollBack();
                    if ($isAjax) $jsonOut(['success' => false, 'error' => 'Informe uma senha para o novo usuário.']);
                    $_SESSION['error_message'] = "Informe uma senha para o novo usuário.";
                    header("Location: /admin_users");
                    exit();
                }
                $senha_hash = password_hash($senha_plana, PASSWORD_DEFAULT);

                $sql = "INSERT INTO usuarios (nome, email, senha, tipo, ativo, unidade_id)
                        VALUES (:nome, :email, :senha, :tipo, :ativo, :unidade_id)";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':nome', $nome);
                $stmt->bindValue(':email', $email);
                $stmt->bindValue(':senha', $senha_hash);
                $stmt->bindValue(':tipo', $tipo);
                $stmt->bindValue(':ativo', $ativo, PDO::PARAM_INT);
                if ($unidade_principal !== null) {
                    $stmt->bindValue(':unidade_id', $unidade_principal, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue(':unidade_id', null, PDO::PARAM_NULL);
                }
                $stmt->execute();

                $user_id = (int)$pdo->lastInsertId();

            } else { // edit_user
                $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
                if (!$user_id) {
                    $pdo->rollBack();
                    if ($isAjax) $jsonOut(['success' => false, 'error' => 'ID de usuário inválido.']);
                    $_SESSION['error_message'] = "ID de usuário inválido.";
                    header("Location: /admin_users");
                    exit();
                }

                $sql = "UPDATE usuarios
                           SET nome = :nome,
                               email = :email,
                               tipo = :tipo,
                               ativo = :ativo,
                               unidade_id = :unidade_id
                         WHERE id = :id LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':nome', $nome);
                $stmt->bindValue(':email', $email);
                $stmt->bindValue(':tipo', $tipo);
                $stmt->bindValue(':ativo', $ativo, PDO::PARAM_INT);
                if ($unidade_principal !== null) {
                    $stmt->bindValue(':unidade_id', $unidade_principal, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue(':unidade_id', null, PDO::PARAM_NULL);
                }
                $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
                $stmt->execute();

                if (!empty($_POST['senha'])) {
                    $senha_hash = password_hash($_POST['senha'], PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id")
                        ->execute([':senha' => $senha_hash, ':id' => $user_id]);
                }

                $pdo->prepare("DELETE FROM usuario_unidade  WHERE usuario_id = :id")->execute([':id' => $user_id]);
                $pdo->prepare("DELETE FROM usuario_operacao WHERE usuario_id = :id")->execute([':id' => $user_id]);
            }

            if (!empty($unidades)) {
                $stmtU = $pdo->prepare("INSERT INTO usuario_unidade (usuario_id, unidade_id) VALUES (:usuario_id, :unidade_id)");
                foreach ($unidades as $uid) {
                    $stmtU->execute([':usuario_id' => $user_id, ':unidade_id' => $uid]);
                }
            }

            if (!empty($operacoes)) {
                $stmtO = $pdo->prepare("INSERT INTO usuario_operacao (usuario_id, operacao_id) VALUES (:usuario_id, :operacao_id)");
                foreach ($operacoes as $opid) {
                    $stmtO->execute([':usuario_id' => $user_id, ':operacao_id' => $opid]);
                }
            }

            $pdo->commit();

            // === AUDIT por ação ===
            if ($action === 'add_user') {
                Audit::log($pdo, [
                'action'    => 'created',
                'entity'    => 'usuarios',
                'entity_id' => $user_id,
                'meta'      => ['nome'=>$nome,'email'=>$email,'tipo'=>$tipo,'ativo'=>$ativo]
                ]);
            } else { // edit_user
                Audit::log($pdo, [
                'action'    => 'updated',
                'entity'    => 'usuarios',
                'entity_id' => $user_id,
                'meta'      => [
                    'nome'=>$nome,'email'=>$email,'tipo'=>$tipo,'ativo'=>$ativo,
                    'senha_alterada'=> !empty($_POST['senha'])
                ]
                ]);
            }

            if ($isAjax) $jsonOut(['success' => true, 'message' => 'Usuário salvo com sucesso!']);
            $_SESSION['success_message'] = "Usuário " . ($action === 'add_user' ? 'adicionado' : 'atualizado') . " com sucesso!";
            header("Location: /admin_users");
            exit();

        } elseif ($action === 'delete_user') {
            $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            if (!$user_id) {
                if ($isAjax) $jsonOut(['success' => false, 'error' => 'ID de usuário inválido.']);
                $_SESSION['error_message'] = "ID de usuário inválido.";
                header("Location: /admin_users");
                exit();
            }

            $stU = $pdo->prepare("SELECT nome, email FROM usuarios WHERE id = :id");
            $stU->execute([':id'=>$user_id]);
            $uInfo = $stU->fetch(PDO::FETCH_ASSOC) ?: null;

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM usuario_unidade  WHERE usuario_id = :id")->execute([':id' => $user_id]);
            $pdo->prepare("DELETE FROM usuario_operacao WHERE usuario_id = :id")->execute([':id' => $user_id]);
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
            $stmt->execute([':id' => $user_id]);
            $pdo->commit();

            Audit::log($pdo, [
                'action'    => 'deleted',
                'entity'    => 'usuarios',
                'entity_id' => $user_id,
                'meta'      => $uInfo ? ['nome'=>$uInfo['nome'],'email'=>$uInfo['email']] : null
            ]);

            if ($isAjax) $jsonOut(['success' => true, 'message' => 'Usuário excluído com sucesso!']);
            $_SESSION['success_message'] = "Usuário excluído com sucesso!";
            header("Location: /admin_users");
            exit();
        }

        // ação desconhecida
        if ($isAjax) $jsonOut(['success' => false, 'error' => 'Ação inválida.']);
        header("Location: /admin_users");
        exit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($isAjax) {
            $jsonOut(['success' => false, 'error' => 'Erro: ' . $e->getMessage()]);
        }
        $_SESSION['error_message'] = "Erro: " . $e->getMessage();
        header("Location: /admin_users");
        exit();
    }
}

// Consulta de dados
try {
    // filtros via GET
    $tipoFilter   = strtolower(trim($_GET['tipo'] ?? ''));   
    $statusFilter = trim($_GET['status'] ?? '');              
    $searchFilter = trim($_GET['search'] ?? '');              

    // paginação
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = (int)($_GET['per_page'] ?? 10);
    $perPage  = max(5, min($perPage, 100));
    $offset   = ($page - 1) * $perPage;

    // tipos válidos
    $TIPOS_VALIDOS_FILTER = ['operador','coordenador','admin','cia_user','cia_admin','cia_dev'];
    if ($tipoFilter && !in_array($tipoFilter, $TIPOS_VALIDOS_FILTER, true)) {
        $tipoFilter = '';
    }
    if ($statusFilter !== '' && !in_array($statusFilter, ['0','1'], true)) {
        $statusFilter = '';
    }

    // WHERE dinâmico
    $where  = [];
    $params = [];

    if ($tipoFilter !== '') {
        $where[] = 'tipo = ?';
        $params[] = $tipoFilter;
    }
    if ($statusFilter !== '') {
        $where[] = 'ativo = ?';
        $params[] = (int)$statusFilter;
    }
    if ($searchFilter !== '') {
        $where[] = '(nome LIKE ? OR email LIKE ?)';
        $params[] = '%' . $searchFilter . '%';
        $params[] = '%' . $searchFilter . '%';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // helper pra manter querystring
    if (!function_exists('qs')) {
        function qs(array $params = []): string {
            $base = $_GET;
            foreach ($params as $k => $v) $base[$k] = $v;
            return '?' . http_build_query($base);
        }
    }

    // total
    $sqlTotal = "SELECT COUNT(*) FROM usuarios $whereSql";
    $stmtT = $pdo->prepare($sqlTotal);
    if ($params) {
        foreach ($params as $index => $value) {
            $stmtT->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
    $stmtT->execute();
    $total = (int)$stmtT->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) { 
        $page = $totalPages; 
        $offset = ($page - 1) * $perPage; 
    }

    // ordenação
    $orderBy = 'id DESC';
    try {
        $testStmt = $pdo->query("SELECT 1 FROM usuarios LIMIT 1");
        $orderBy = 'criado_em DESC, id DESC';
    } catch (Throwable $e) {
        $orderBy = 'id DESC';
    }

    // dados
    $sql = "
        SELECT id, nome, email, tipo, ativo
        FROM usuarios
        $whereSql
        ORDER BY $orderBy
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($sql);
    
    // Bind dos parâmetros de filtro
    $paramIndex = 1;
    foreach ($params as $value) {
        $stmt->bindValue($paramIndex, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $paramIndex++;
    }
    
    // Bind dos parâmetros de paginação
    $stmt->bindValue($paramIndex, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex + 1, $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // combos auxiliares
    $unidades  = $pdo->query("SELECT id, nome FROM unidades")->fetchAll(PDO::FETCH_ASSOC);
    $operacoes = $pdo->query("SELECT id, nome FROM tipos_operacao")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erro ao consultar dados: " . $e->getMessage());
}

require_once __DIR__ . '/../../../app/includes/header.php';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Usuários - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/public/static/css/admin_users.css">
    <link rel="icon" type="image/x-icon" href="./public/static/favicon.ico">
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">
</head>
<body>
    <div class="admin-container">
        <main class="admin-main">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-users-cog"></i> Gestão de Usuários</h2>
                    <button id="addUserBtn" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Adicionar Usuário
                    </button>
                </div>
                
                <!-- Mensagens de feedback -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success">
                        <?php echo $_SESSION['success_message']; ?>
                        <button type="button" class="alert-close">&times;</button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger">
                        <?php echo $_SESSION['error_message']; ?>
                        <button type="button" class="alert-close">&times;</button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>

                <div class="card-body">
                    <?php if ($searchFilter !== ''): ?>
                    <div class="search-results-info">
                        <div>
                            <i class="fas fa-search"></i> 
                            Resultados para: "<strong><?= htmlspecialchars($searchFilter) ?></strong>" 
                            <?php if ($total > 0): ?>
                                - <strong><?= $total ?></strong> usuário(s) encontrado(s)
                            <?php endif; ?>
                        </div>
                        <?php if ($searchFilter !== ''): ?>
                            <a href="<?= qs(['search' => '', 'page' => 1]) ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-times"></i> Limpar pesquisa
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="table-toolbar">
                        <div class="left-section">
                            <!-- Barra de Pesquisa -->
                            <div class="search-container">
                                <form id="searchForm" method="GET" class="search-form">
                                    <input type="text" 
                                           name="search" 
                                           class="search-box" 
                                           placeholder="Pesquisar usuários por nome ou email..."
                                           value="<?= htmlspecialchars($searchFilter) ?>"
                                           autocomplete="off"
                                           id="searchInput">
                                    <button type="submit" class="search-button">
                                        <i class="fas fa-search"></i>
                                    </button>
                                    <div class="search-loading" id="searchLoading" style="display: none;">
                                        <i class="fas fa-spinner fa-spin"></i>
                                    </div>
                                </form>
                            </div>

                            <!-- Filtros -->
                            <div class="filters-container">
                                <label class="filter">
                                    Tipo:
                                    <select id="filterTipo" class="form-control compact">
                                        <option value="" <?= $tipoFilter==='' ? 'selected':'' ?>>Todos</option>
                                        <option value="operador" <?= $tipoFilter==='operador' ? 'selected':'' ?>>Operador</option>
                                        <option value="coordenador" <?= $tipoFilter==='coordenador' ? 'selected':'' ?>>Coordenador</option>
                                        <option value="admin" <?= $tipoFilter==='admin' ? 'selected':'' ?>>Administrador</option>
                                        <option value="cia_user" <?= $tipoFilter==='cia_user' ? 'selected':'' ?>>CIA — Usuário</option>
                                        <option value="cia_admin" <?= $tipoFilter==='cia_admin' ? 'selected':'' ?>>CIA — Admin</option>
                                        <option value="cia_dev" <?= $tipoFilter==='cia_dev' ? 'selected':'' ?>>CIA — Dev</option>
                                    </select>
                                </label>

                                <label class="filter">
                                    Status:
                                    <select id="filterStatus" class="form-control compact">
                                        <option value="" <?= $statusFilter==='' ? 'selected':'' ?>>Todos</option>
                                        <option value="1" <?= $statusFilter==='1' ? 'selected':'' ?>>Ativo</option>
                                        <option value="0" <?= $statusFilter==='0' ? 'selected':'' ?>>Inativo</option>
                                    </select>
                                </label>

                                <button id="resetFilters" class="btn btn-sm btn-secondary" type="button" title="Limpar filtros">
                                    <i class="fas fa-times"></i> Limpar
                                </button>
                            </div>
                        </div>

                        <div class="right-section">
                            <label class="perpage">
                                Itens por página:
                                <select id="perPage" class="form-control compact">
                                    <option value="10" <?= isset($perPage)&&$perPage==10 ? 'selected':'' ?>>10</option>
                                    <option value="20" <?= isset($perPage)&&$perPage==20 ? 'selected':'' ?>>20</option>
                                    <option value="50" <?= isset($perPage)&&$perPage==50 ? 'selected':'' ?>>50</option>
                                    <option value="100" <?= isset($perPage)&&$perPage==100 ? 'selected':'' ?>>100</option>
                                </select>
                            </label>
                            <span class="muted range">
                                <?php $from = $total ? ($offset + 1) : 0; $to = min($offset + $perPage, $total); ?>
                                Mostrando <strong><?= $from ?></strong>–<strong><?= $to ?></strong> de <strong><?= $total ?></strong>
                            </span>
                        </div>
                    </div>

                    <!-- TABELA -->
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Email</th>
                                    <th>Tipo</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($users) > 0): ?>
                                <?php foreach($users as $user): ?>
                                <tr>
                                    <td><?= htmlspecialchars($user['nome']); ?></td>
                                    <td><?= htmlspecialchars($user['email']); ?></td>
                                    <?php
                                        $labelsTipo = [
                                        'operador'=>'Operador',
                                        'coordenador'=>'Coordenador',
                                        'admin'=>'Administrador',
                                        'cia_user'=>'CIA — Usuário',
                                        'cia_admin'=>'CIA — Admin',
                                        'cia_dev'=>'CIA — Dev'
                                        ];
                                    ?>
                                    <td><?= htmlspecialchars($labelsTipo[strtolower($user['tipo'])] ?? $user['tipo']); ?></td>
                                    <td>
                                        <span class="badge <?= $user['ativo'] ? 'bg-success' : 'bg-danger'; ?>">
                                            <?= $user['ativo'] ? 'Ativo' : 'Inativo'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-warning btn-sm edit-user" data-id="<?= $user['id']; ?>">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn btn-danger btn-sm delete-user" data-id="<?= $user['id']; ?>">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="5" class="no-results">
                                        <i class="fas fa-user-slash"></i>
                                        <h4>Nenhum usuário encontrado</h4>
                                        <p><?= $searchFilter !== '' ? 'Tente ajustar os termos da pesquisa ou limpar os filtros.' : 'Não há usuários cadastrados no momento.' ?></p>
                                        <?php if ($searchFilter !== '' || $tipoFilter !== '' || $statusFilter !== ''): ?>
                                        <a href="<?= qs(['search' => '', 'tipo' => '', 'status' => '', 'page' => 1]) ?>" class="btn btn-primary">
                                            <i class="fas fa-refresh"></i> Limpar todos os filtros
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- PAGINAÇÃO -->
                    <?php if ($totalPages > 1): ?>
                    <nav class="pagination-bar">
                        <a class="page-link <?= ($page<=1?'disabled':'') ?>" href="<?= $page>1 ? qs(['page'=>$page-1]) : '#' ?>">« Anterior</a>

                        <?php
                            $start = max(1, $page - 2);
                            $end   = min($totalPages, $page + 2);
                            if ($start > 1) {
                                echo '<a class="page-link" href="'.qs(['page'=>1]).'">1</a>';
                                if ($start > 2) echo '<span class="dots">…</span>';
                            }
                            for ($p=$start; $p<=$end; $p++) {
                                $active = $p == $page ? 'active' : '';
                                echo '<a class="page-link '.$active.'" href="'.qs(['page'=>$p]).'">'.$p.'</a>';
                            }
                            if ($end < $totalPages) {
                                if ($end < $totalPages-1) echo '<span class="dots">…</span>';
                                echo '<a class="page-link" href="'.qs(['page'=>$totalPages]).'">'.$totalPages.'</a>';
                            }
                        ?>

                        <a class="page-link <?= ($page>=$totalPages?'disabled':'') ?>" href="<?= $page<$totalPages ? qs(['page'=>$page+1]) : '#' ?>">Próxima »</a>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Modal de Usuário -->
            <div id="userModal" class="modal-overlay">
                <div class="modal-content modal-sm">
                    <div class="modal-header">
                        <h3 class="modal-title" id="modalTitle">Adicionar Usuário</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <form id="userForm" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="action" id="formAction" value="add_user">
                            <input type="hidden" name="user_id" id="userId">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="nome">Nome</label>
                                    <input type="text" id="nome" name="nome" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label for="email">Email</label>
                                    <input type="email" id="email" name="email" class="form-control" required>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="senha">Senha</label>
                                    <input type="password" id="senha" name="senha" class="form-control">
                                    <small class="form-text">Deixe em branco para não alterar (ao editar)</small>
                                </div>
                                <div class="form-group">
                                    <label for="tipo">Tipo de Usuário</label>
                                    <select id="tipo" name="tipo" class="form-control" required>
                                        <option value="cia_user">CIA - Usuário</option>
                                        <option value="operador">Operador</option>
                                        <option value="coordenador">Coordenador</option>
                                        <option value="admin">Administrador</option>
                                        <option value="cia_admin">CIA - Admin</option>
                                        <option value="cia_dev">CIA - Dev</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group form-checkbox">
                                <input type="checkbox" id="ativo" name="ativo" checked>
                                <label for="ativo">Usuário Ativo</label>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <h4>Permissões de Unidades</h4>
                                    <div class="permissions-container">
                                        <?php foreach ($unidades as $unidade): ?>
                                            <div class="form-check">
                                                <input type="checkbox" name="unidades[]" 
                                                       value="<?php echo $unidade['id']; ?>" 
                                                       id="unidade_<?php echo $unidade['id']; ?>">
                                                <label for="unidade_<?php echo $unidade['id']; ?>">
                                                    <?php echo htmlspecialchars($unidade['nome']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <h4>Permissões de Operações</h4>
                                    <div class="permissions-container">
                                        <?php foreach ($operacoes as $operacao): ?>
                                            <div class="form-check">
                                                <input type="checkbox" name="operacoes[]" 
                                                       value="<?php echo $operacao['id']; ?>" 
                                                       id="operacao_<?php echo $operacao['id']; ?>">
                                                <label for="operacao_<?php echo $operacao['id']; ?>">
                                                    <?php echo htmlspecialchars($operacao['nome']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary modal-close">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Salvar</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal de Confirmação -->
            <div id="confirmModal" class="modal-overlay">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">Confirmar Exclusão</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p>Tem certeza que deseja excluir este usuário?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary modal-close">Cancelar</button>
                        <form id="deleteForm" method="POST">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" id="deleteUserId">
                            <button type="submit" class="btn btn-danger">Excluir</button>
                        </form>
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
document.addEventListener('DOMContentLoaded', function() {
    // Busca em tempo real
    const searchInput = document.getElementById('searchInput');
    const searchLoading = document.getElementById('searchLoading');
    let searchTimeout;

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchLoading.style.display = 'block';
            
            searchTimeout = setTimeout(() => {
                const searchValue = this.value.trim();
                const url = new URL(window.location.href);
                
                if (searchValue === '') {
                    url.searchParams.delete('search');
                } else {
                    url.searchParams.set('search', searchValue);
                }
                
                url.searchParams.set('page', '1');
                window.location.href = url.toString();
            }, 600);
        });
    }

    // Submit manual do formulário de pesquisa
    const searchForm = document.getElementById('searchForm');
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const url = new URL(window.location.href);
            const searchValue = searchInput.value.trim();
            
            if (searchValue === '') {
                url.searchParams.delete('search');
            } else {
                url.searchParams.set('search', searchValue);
            }
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        });
    }

    // Elementos do modal
    const userModal = document.getElementById('userModal');
    const confirmModal = document.getElementById('confirmModal');
    const addUserBtn = document.getElementById('addUserBtn');
    const modalCloses = document.querySelectorAll('.modal-close');

    // Helpers de modal
    function openModal(modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Helper de alerta visual
    function showAlert(type, message) {
        const container = document.querySelector('.card-body') || document.body;
        const el = document.createElement('div');
        el.className = `alert alert-${type}`;
        el.innerHTML = `${message} <button class="alert-close">&times;</button>`;
        container.prepend(el);
        
        setTimeout(() => el.remove(), 4000);
    }

    // Fechar alertas
    document.addEventListener('click', (e) => {
        if (e.target.classList.contains('alert-close')) {
            e.target.closest('.alert')?.remove();
        }
    });

    // Abrir modal "Adicionar"
    addUserBtn.addEventListener('click', () => {
        document.getElementById('modalTitle').textContent = 'Adicionar Usuário';
        document.getElementById('formAction').value = 'add_user';
        document.getElementById('userForm').reset();
        document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
        document.getElementById('ativo').checked = true;
        openModal(userModal);
    });

    // Abrir modal "Editar" - CORRIGIDO
    document.addEventListener('click', function(e) {
        if (e.target.closest('.edit-user')) {
            const btn = e.target.closest('.edit-user');
            const userId = btn.getAttribute('data-id');

            // Buscar dados reais do usuário
            fetch(`/app/views/admin/get_user.php?id=${userId}`, { 
                credentials: 'same-origin' 
            })
            .then(res => {
                if (!res.ok) throw new Error('Erro ao buscar dados do usuário');
                return res.json();
            })
            .then(data => {
                if (data.error) {
                    showAlert('danger', data.error);
                    return;
                }

                // Preenche campos
                document.getElementById('modalTitle').textContent = 'Editar Usuário';
                document.getElementById('formAction').value = 'edit_user';
                document.getElementById('userId').value = data.user.id;
                document.getElementById('nome').value = data.user.nome;
                document.getElementById('email').value = data.user.email;
                document.getElementById('tipo').value = data.user.tipo;
                document.getElementById('ativo').checked = data.user.ativo == 1;

                // Limpa senha
                document.getElementById('senha').value = '';

                // Checkbox Unidades
                const unidadesIds = (data.unidades || []).map(id => parseInt(id));
                document.querySelectorAll('input[name="unidades[]"]').forEach(cb => {
                    cb.checked = unidadesIds.includes(parseInt(cb.value));
                });

                // Checkbox Operações
                const operacoesIds = (data.operacoes || []).map(id => parseInt(id));
                document.querySelectorAll('input[name="operacoes[]"]').forEach(cb => {
                    cb.checked = operacoesIds.includes(parseInt(cb.value));
                });

                openModal(userModal);
            })
            .catch(err => {
                console.error('Erro:', err);
                showAlert('danger', 'Erro ao carregar dados do usuário');
            });
        }
    });

    // Abrir modal "Excluir"
    document.addEventListener('click', function(e) {
        if (e.target.closest('.delete-user')) {
            const btn = e.target.closest('.delete-user');
            const userId = btn.getAttribute('data-id');
            document.getElementById('deleteUserId').value = userId;
            openModal(confirmModal);
        }
    });

    // Fechar modais
    modalCloses.forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('.modal-overlay');
            closeModal(modal);
        });
    });

    // Fechar modal clicando fora
    [userModal, confirmModal].forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeModal(this);
        });
    });

    // Filtros
    const filterTipo = document.getElementById('filterTipo');
    const filterStatus = document.getElementById('filterStatus');
    const perPage = document.getElementById('perPage');
    const resetFilters = document.getElementById('resetFilters');

    function applyFilters() {
        const url = new URL(window.location.href);
        
        if (filterTipo.value) url.searchParams.set('tipo', filterTipo.value);
        else url.searchParams.delete('tipo');
        
        if (filterStatus.value !== '') url.searchParams.set('status', filterStatus.value);
        else url.searchParams.delete('status');
        
        if (perPage.value !== '10') url.searchParams.set('per_page', perPage.value);
        else url.searchParams.delete('per_page');
        
        url.searchParams.set('page', '1');
        window.location.href = url.toString();
    }

    if (filterTipo) filterTipo.addEventListener('change', applyFilters);
    if (filterStatus) filterStatus.addEventListener('change', applyFilters);
    if (perPage) perPage.addEventListener('change', applyFilters);

    if (resetFilters) {
        resetFilters.addEventListener('click', () => {
            window.location.href = '<?= qs(['tipo' => '', 'status' => '', 'search' => '', 'page' => 1, 'per_page' => 10]) ?>';
        });
    }

const userForm = document.getElementById('userForm');
if (userForm) {
    userForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
        submitBtn.disabled = true;

        fetch('', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'  // Adicione isso aqui
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert('success', data.message);
                closeModal(userModal);
                setTimeout(() => window.location.reload(), 800);
            } else {
                showAlert('danger', data.error || 'Erro desconhecido');
            }
        })
        .catch(err => {
            console.error('Erro:', err);
            showAlert('danger', 'Erro de rede ao salvar usuário');
        })
        .finally(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });
}
    // Submit do formulário de exclusão com AJAX
    const deleteForm = document.getElementById('deleteForm');
    if (deleteForm) {
        deleteForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';
            submitBtn.disabled = true;

            fetch('', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                'X-Requested-With': 'XMLHttpRequest'  // Adicione isso aqui
            }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    closeModal(confirmModal);
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    showAlert('danger', data.error || 'Erro desconhecido');
                closeModal(confirmModal);
                submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(err => {
                console.error('Erro:', err);
                showAlert('danger', 'Erro de rede ao excluir usuário');
                closeModal(confirmModal);
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
    }
});
</script>
</body>
</html>