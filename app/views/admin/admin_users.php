<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

// Verificação de administrador
if (!isset($_SESSION['usuario_id']) || strtolower($_SESSION['usuario_tipo']) !== 'admin') {
    header("Location: /");
    exit();
}

// Lógica de CRUD
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action == 'add_user' || $action == 'edit_user') {
            // Validação e sanitização
            $nome = filter_input(INPUT_POST, 'nome', FILTER_SANITIZE_STRING);
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $tipo = filter_input(INPUT_POST, 'tipo', FILTER_SANITIZE_STRING);
            $ativo = isset($_POST['ativo']) ? 1 : 0;
            $unidades = $_POST['unidades'] ?? [];
            $operacoes = $_POST['operacoes'] ?? [];

            if ($action == 'add_user') {
                $senha = password_hash($_POST['senha'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, tipo, ativo) VALUES (:nome, :email, :senha, :tipo, :ativo)");
                $stmt->execute([
                    ':nome' => $nome,
                    ':email' => $email,
                    ':senha' => $senha,
                    ':tipo' => $tipo,
                    ':ativo' => $ativo
                ]);
                $user_id = $pdo->lastInsertId();
            } else { // edit_user
                $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
                $stmt = $pdo->prepare("UPDATE usuarios SET nome = :nome, email = :email, tipo = :tipo, ativo = :ativo WHERE id = :id");
                $stmt->execute([
                    ':nome' => $nome,
                    ':email' => $email,
                    ':tipo' => $tipo,
                    ':ativo' => $ativo,
                    ':id' => $user_id
                ]);

                // Alterar senha se fornecida
                if (!empty($_POST['senha'])) {
                    $senha = password_hash($_POST['senha'], PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
                    $stmt->execute([':senha' => $senha, ':id' => $user_id]);
                }

                // Excluir permissões antigas
                $pdo->prepare("DELETE FROM usuario_unidade WHERE usuario_id = :id")->execute([':id' => $user_id]);
                $pdo->prepare("DELETE FROM usuario_operacao WHERE usuario_id = :id")->execute([':id' => $user_id]);
            }

// Inserir novas permissões
$stmt_unidade = $pdo->prepare("INSERT INTO usuario_unidade (usuario_id, unidade_id) VALUES (:usuario_id, :unidade_id)");
$stmt_operacao = $pdo->prepare("INSERT INTO usuario_operacao (usuario_id, operacao_id) VALUES (:usuario_id, :operacao_id)");

foreach ($unidades as $unidade_id) {
    $stmt_unidade->execute([
        ':usuario_id' => $user_id,
        ':unidade_id' => $unidade_id
    ]);
}

foreach ($operacoes as $operacao_id) {
    $stmt_operacao->execute([
        ':usuario_id' => $user_id,
        ':operacao_id' => $operacao_id
    ]);
}
            
            $_SESSION['success_message'] = "Usuário " . ($action == 'add_user' ? 'adicionado' : 'atualizado') . " com sucesso!";
            
// substituir apenas o bloco delete_user dentro do try principal
} elseif ($action == 'delete_user') {
    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    if (!$user_id) {
        $_SESSION['error_message'] = "ID de usuário inválido.";
        header("Location: /app/views/admin/admin_users.php");
        exit();
    }

    // detecta se é requisição AJAX (útil se for usar fetch para deletar)
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    try {
        // garante que o PDO lance exceções (deveria estar em conexao.php)
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->beginTransaction();

        // 1) Apagar permissões/filhos primeiro
        $stmt = $pdo->prepare("DELETE FROM usuario_unidade WHERE usuario_id = :id");
        $stmt->execute([':id' => $user_id]);

        $stmt = $pdo->prepare("DELETE FROM usuario_operacao WHERE usuario_id = :id");
        $stmt->execute([':id' => $user_id]);

        // 2) Apagar o usuário
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);

        // checar se alguma linha foi removida (opcional)
        if ($stmt->rowCount() === 0) {
            throw new Exception("Usuário não encontrado ou já removido.");
        }

        $pdo->commit();

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Usuário excluído com sucesso.']);
            exit();
        }

        $_SESSION['success_message'] = "Usuário excluído com sucesso!";
    } catch (Exception $e) {
        // desfaz se algo deu errado
        if ($pdo->inTransaction()) $pdo->rollBack();

        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit();
        }

        // em produção você pode querer logar $e->getMessage() em log ao invés de mostrar
        $_SESSION['error_message'] = "Erro ao excluir usuário: " . $e->getMessage();
    }

    header("Location: /app/views/admin/admin_users.php");
    exit();
}

        
        header("Location: /app/views/admin/admin_users.php");
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Erro no banco de dados: " . $e->getMessage();
        header("Location: /app/views/admin/admin_users.php");
        exit();
    }
}

// Consulta de dados
try {
    $users = $pdo->query("SELECT id, nome, email, tipo, ativo FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
    $unidades = $pdo->query("SELECT id, nome FROM unidades")->fetchAll(PDO::FETCH_ASSOC);
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
    <style>
        :root {
            --primary: #2e7d32;
            --primary-dark: #1b5e20;
            --primary-light: #81c784;
            --secondary: #0288d1;
            --accent: #ffab00;
            --text: #263238;
            --text-light: #eceff1;
            --text-muted: #78909c;
            --bg: #f5f7fa;
            --card: #ffffff;
            --border: #cfd8dc;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.12);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --radius: 8px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
font-family: 'Poppins', sans-serif;
    background-color: var(--bg-light);
    color: var(--text-color);
    line-height: 1.6;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
        }

        .admin-container {

            grid-template-areas:
                "header"
                "main";
            min-height: 100vh;
        }

        .admin-header {
            grid-area: header;
            background: var(--card);
            box-shadow: var(--shadow-sm);
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 700;
            color: var(--primary);
        }

        .logo i {
            font-size: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logout-btn {
            color: var(--text);
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: rgba(0,0,0,0.05);
            color: var(--secondary);
        }

        .admin-main {
            grid-area: main;

        }

        .card {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            font-size: 1.5rem;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.25rem;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            box-shadow: var(--shadow-sm);
        }

        .btn i {
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--secondary);
            color: white;
        }

        .btn-danger {
            background: #d32f2f;
            color: white;
        }

        .btn-warning {
            background: var(--accent);
            color: var(--text);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .alert-success {
            background: #e8f5e9;
            color: var(--primary-dark);
            border-left: 4px solid var(--primary);
        }

        .alert-danger {
            background: #ffebee;
            color: #d32f2f;
            border-left: 4px solid #d32f2f;
        }

        .alert-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: inherit;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th {
            background: var(--primary-dark);
            color: white;
            padding: 1rem;
            text-align: left;
        }

        table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border);
        }

        table tr:hover {
            background: rgba(46, 125, 50, 0.05);
        }

        .badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .bg-success {
            background: var(--primary);
            color: white;
        }

        .bg-danger {
            background: #d32f2f;
            color: white;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: all 0.3s ease;
        }

        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            margin: 0;
            font-size: 1.5rem;
            color: var(--primary);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .form-row {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            flex: 1;
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            padding-inline: inherit;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.2);
        }

        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-checkbox input {
            width: auto;
        }

        .permissions-container {
            max-height: 200px;
            overflow-y: auto;
            padding: 0.5rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-top: 0.5rem;
        }

        .permissions-container .form-check {
            display: flex;
            padding: 0.5rem;
            margin-bottom: 0.25rem;
        }

        .permissions-container .form-check:hover {
            background: rgba(46, 125, 50, 0.05);
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 1rem;
            }
            
            .admin-main {
                padding: 1rem;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            table td {
                padding: 0.5rem;
            }
        }

.permissions-container {
    max-height: 200px;
    overflow-y: auto;
    padding: 0.5rem;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-top: 0.5rem;
}

.permissions-container .form-check {
    display: flex;
    align-items: center;
    padding: 0.5rem;
    margin-bottom: 0.25rem;
    border-radius: 4px;
    transition: background-color 0.2s ease;
}

.permissions-container .form-check:hover {
    background: rgba(46, 125, 50, 0.05);
}

.permissions-container .form-check input[type="checkbox"]:checked + label {
    font-weight: 600;
    color: var(--primary-dark);
}

.permissions-container .form-check input[type="checkbox"]:checked {
    accent-color: var(--primary);
}

.permissions-container label {
    cursor: pointer;
    margin-left: 5px;
    flex-grow: 1;
}

        
        
    </style>
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
                                <?php foreach($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['nome']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo ucfirst(htmlspecialchars($user['tipo'])); ?></td>
                                    <td>
                                        <span class="badge <?php echo $user['ativo'] ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $user['ativo'] ? 'Ativo' : 'Inativo'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-warning btn-sm edit-user" 
                                                data-id="<?php echo $user['id']; ?>">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn btn-danger btn-sm delete-user" 
                                                data-id="<?php echo $user['id']; ?>">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal de Usuário -->
    <div id="userModal" class="modal-overlay">
        <div class="modal-content">
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
                                <option value="operador">Operador</option>
                                <option value="coordenador">Coordenador</option>
                                <option value="admin">Administrador</option>
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

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Elementos do modal
        const userModal = document.getElementById('userModal');
        const confirmModal = document.getElementById('confirmModal');
        const addUserBtn = document.getElementById('addUserBtn');
        const modalCloses = document.querySelectorAll('.modal-close');
        
        // Funções para abrir/fechar modais
        function openModal(modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        // Event listeners
        addUserBtn.addEventListener('click', () => {
            document.getElementById('modalTitle').textContent = 'Adicionar Usuário';
            document.getElementById('formAction').value = 'add_user';
            document.getElementById('userForm').reset();
            document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
            document.getElementById('ativo').checked = true;
            openModal(userModal);
        });
        
document.querySelectorAll('.edit-user').forEach(btn => {
    btn.addEventListener('click', function() {
        const userId = this.getAttribute('data-id');

        fetch(`/app/views/admin/get_user.php?id=${userId}`)
            .then(res => {
                if (!res.ok) {
                    throw new Error('Erro ao buscar dados do usuário');
                }
                return res.json();
            })
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    return;
                }

                // Preencher campos básicos
                document.getElementById('modalTitle').textContent = 'Editar Usuário';
                document.getElementById('formAction').value = 'edit_user';
                document.getElementById('userId').value = data.user.id;
                document.getElementById('nome').value = data.user.nome;
                document.getElementById('email').value = data.user.email;
                document.getElementById('tipo').value = data.user.tipo;
                document.getElementById('ativo').checked = data.user.ativo == 1;

                // Limpar campo de senha
                document.getElementById('senha').value = '';

                // Selecionar unidades - converter IDs para número para comparação
                const unidadesIds = data.unidades.map(id => parseInt(id));
                document.querySelectorAll('input[name="unidades[]"]').forEach(cb => {
                    cb.checked = unidadesIds.includes(parseInt(cb.value));
                });

                // Selecionar operações - converter IDs para número para comparação
                const operacoesIds = data.operacoes.map(id => parseInt(id));
                document.querySelectorAll('input[name="operacoes[]"]').forEach(cb => {
                    cb.checked = operacoesIds.includes(parseInt(cb.value));
                });

                openModal(userModal);
            })
            .catch(err => {
                alert("Erro ao buscar dados do usuário: " + err.message);
                console.error(err);
            });
    });
});


document.getElementById('userForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);

    fetch(location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.text())
    .then(() => {
        closeModal(userModal);

        // Exibir notificação
        const msg = document.createElement('div');
        msg.className = 'alert alert-success';
        msg.innerHTML = 'Usuário salvo com sucesso! <button class="alert-close">&times;</button>';
        document.querySelector('.card-body').prepend(msg);

        // Fechar automaticamente
        setTimeout(() => msg.remove(), 4000);

        // Atualizar tabela sem reload
        location.reload();
    })
    .catch(err => {
        closeModal(userModal);
        const msg = document.createElement('div');
        msg.className = 'alert alert-danger';
        msg.innerHTML = 'Erro ao salvar usuário! <button class="alert-close">&times;</button>';
        document.querySelector('.card-body').prepend(msg);
        console.error(err);
    });
});

        
        document.querySelectorAll('.delete-user').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('deleteUserId').value = this.getAttribute('data-id');
                openModal(confirmModal);
            });
        });
        
        modalCloses.forEach(btn => {
            btn.addEventListener('click', function() {
                const modal = this.closest('.modal-overlay');
                closeModal(modal);
            });
        });
        
        // Fechar modal ao clicar fora
        window.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-overlay')) {
                closeModal(e.target);
            }
        });
        
        // Fechar alerts
        document.querySelectorAll('.alert-close').forEach(btn => {
            btn.addEventListener('click', function() {
                this.parentElement.style.display = 'none';
            });
        });
    });
    </script>
</body>
</html>