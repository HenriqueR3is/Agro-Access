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

        if ($action === 'add_user' || $action === 'edit_user') {
            $nome  = trim($_POST['nome'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $tipo  = trim($_POST['tipo'] ?? 'operador');
            $ativo = isset($_POST['ativo']) ? 1 : 0;

            $unidades  = array_values(array_filter(array_map('intval', $_POST['unidades']  ?? [])));
            $operacoes = array_values(array_filter(array_map('intval', $_POST['operacoes'] ?? [])));
            $unidade_principal = $unidades[0] ?? null;

            if ($nome === '' || $email === '' || $tipo === '') {
                if ($isAjax) $jsonOut(['success' => false, 'error' => 'Preencha nome, e-mail e tipo.']);
                $_SESSION['error_message'] = "Preencha nome, e-mail e tipo.";
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

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM usuario_unidade  WHERE usuario_id = :id")->execute([':id' => $user_id]);
            $pdo->prepare("DELETE FROM usuario_operacao WHERE usuario_id = :id")->execute([':id' => $user_id]);
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
            $stmt->execute([':id' => $user_id]);
            $pdo->commit();

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
    <link rel="stylesheet" href="/public/static/css/admin_users.css">
    <link rel="icon" type="image/x-icon" href="./public/static/favicon.ico">
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
    // Elementos do modal
    const userModal    = document.getElementById('userModal');
    const confirmModal = document.getElementById('confirmModal');
    const addUserBtn   = document.getElementById('addUserBtn');
    const modalCloses  = document.querySelectorAll('.modal-close');

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
        // type: 'success' | 'danger'
        const container = document.querySelector('.card-body') || document.body;
        const el = document.createElement('div');
        el.className = `alert alert-${type}`;
        el.innerHTML = `${message} <button class="alert-close">&times;</button>`;
        container.prepend(el);
        // auto-close
        setTimeout(() => el.remove(), 4000);
    }
    // Fechar alertas (inclusive os criados dinamicamente)
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

    // Abrir modal "Editar"
    document.querySelectorAll('.edit-user').forEach(btn => {
        btn.addEventListener('click', function() {
            const userId = this.getAttribute('data-id');

            fetch(`/app/views/admin/get_user.php?id=${userId}`, { credentials: 'same-origin' })
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
                    document.getElementById('userId').value  = data.user.id;
                    document.getElementById('nome').value    = data.user.nome;
                    document.getElementById('email').value   = data.user.email;
                    document.getElementById('tipo').value    = data.user.tipo;
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
                    showAlert('danger', "Erro ao buscar dados do usuário: " + err.message);
                    console.error(err);
                });
        });
    });

    // Submit do formulário (Salvar) com AJAX + feedback
    document.getElementById('userForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const form = this;
        const btnSubmit = form.querySelector('.btn.btn-primary');
        const originalText = btnSubmit.textContent;
        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Salvando...';

        try {
            const res = await fetch(location.href, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form)
            });

            const data = await res.json().catch(() => null);

            if (data && data.success) {
                closeModal(userModal);
                showAlert('success', data.message || 'Usuário salvo com sucesso!');
                setTimeout(() => location.reload(), 900);
            } else {
                const msg = data && data.error ? data.error : 'Erro ao salvar usuário.';
                showAlert('danger', msg);
            }
        } catch (err) {
            showAlert('danger', 'Erro na requisição: ' + err.message);
            console.error(err);
        } finally {
            btnSubmit.disabled = false;
            btnSubmit.textContent = originalText;
        }
    });

    // Abrir modal de confirmação (Excluir)
    document.querySelectorAll('.delete-user').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('deleteUserId').value = this.getAttribute('data-id');
            openModal(confirmModal);
        });
    });

    // Submit do excluir com AJAX + feedback (opcional mas recomendado)
    const deleteForm = document.getElementById('deleteForm');
    if (deleteForm) {
        deleteForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const btn = deleteForm.querySelector('button[type="submit"]');
            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Excluindo...';

            try {
                const res = await fetch(location.href, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(deleteForm)
                });
                const data = await res.json().catch(() => null);

                if (data && data.success) {
                    closeModal(confirmModal);
                    showAlert('success', data.message || 'Usuário excluído com sucesso!');
                    setTimeout(() => location.reload(), 600);
                } else {
                    const msg = data && data.error ? data.error : 'Erro ao excluir usuário.';
                    showAlert('danger', msg);
                }
            } catch (err) {
                showAlert('danger', 'Erro na requisição: ' + err.message);
                console.error(err);
            } finally {
                btn.disabled = false;
                btn.textContent = original;
            }
        });
    }

    // Fechar modais (X)
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
});
</script>
</body>
</html>
