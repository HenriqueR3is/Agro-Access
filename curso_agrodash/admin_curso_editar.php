<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    header("Location: dashboard.php");
    exit;
}

$curso_id = $_GET['id'] ?? null;
$curso = null;

if ($curso_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
        $stmt->execute([$curso_id]);
        $curso = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao carregar curso: " . $e->getMessage();
    }
}

if (!$curso) {
    header("Location: admin_cursos.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Curso - AgroDash</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        /* Reutilize os estilos do admin_cursos.php */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Roboto', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .admin-container { max-width: 1000px; margin: 0 auto; background: white; border-radius: 15px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); overflow: hidden; }
        .admin-header { background: linear-gradient(135deg, #32CD32, #228B22); color: white; padding: 30px; text-align: center; }
        .admin-content { padding: 30px; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; color: #2c3e50; }
        .form-control { width: 100%; padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 1rem; transition: border-color 0.3s ease; }
        .form-control:focus { outline: none; border-color: #32CD32; }
        .form-textarea { min-height: 120px; resize: vertical; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .btn { padding: 12px 24px; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: #32CD32; color: white; }
        .btn-primary:hover { background: #228B22; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #545b62; }
        .preview-imagem { max-width: 300px; max-height: 200px; margin-top: 10px; border-radius: 8px; border: 2px solid #e9ecef; }
        @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1><i class="fas fa-edit"></i> Editar Curso</h1>
            <p>Modifique as informações do curso</p>
        </div>

        <div class="admin-content">
            <form id="form-curso" action="ajax/salvar_curso.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?= $curso['id'] ?>">

                <div class="form-group">
                    <label class="form-label" for="titulo">Título do Curso *</label>
                    <input type="text" class="form-control" id="titulo" name="titulo" value="<?= htmlspecialchars($curso['titulo']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="descricao">Descrição *</label>
                    <textarea class="form-control form-textarea" id="descricao" name="descricao" required><?= htmlspecialchars($curso['descricao']) ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="duracao_estimada">Duração Estimada</label>
                        <input type="text" class="form-control" id="duracao_estimada" name="duracao_estimada" value="<?= htmlspecialchars($curso['duracao_estimada'] ?? '2 horas') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="nivel">Nível</label>
                        <select class="form-control" id="nivel" name="nivel">
                            <option value="Iniciante" <?= ($curso['nivel'] ?? 'Iniciante') === 'Iniciante' ? 'selected' : '' ?>>Iniciante</option>
                            <option value="Intermediário" <?= ($curso['nivel'] ?? '') === 'Intermediário' ? 'selected' : '' ?>>Intermediário</option>
                            <option value="Avançado" <?= ($curso['nivel'] ?? '') === 'Avançado' ? 'selected' : '' ?>>Avançado</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="publico_alvo">Público-Alvo</label>
                        <select class="form-control" id="publico_alvo" name="publico_alvo">
                            <option value="todos" <?= ($curso['publico_alvo'] ?? 'todos') === 'todos' ? 'selected' : '' ?>>Todos</option>
                            <option value="operador" <?= ($curso['publico_alvo'] ?? '') === 'operador' ? 'selected' : '' ?>>Operadores</option>
                            <option value="gestor" <?= ($curso['publico_alvo'] ?? '') === 'gestor' ? 'selected' : '' ?>>Gestores</option>
                            <option value="admin" <?= ($curso['publico_alvo'] ?? '') === 'admin' ? 'selected' : '' ?>>Administradores</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-control" id="status" name="status">
                            <option value="ativo" <?= $curso['status'] === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                            <option value="inativo" <?= $curso['status'] === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                            <option value="rascunho" <?= $curso['status'] === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Imagem do Curso</label>
                    <?php if (!empty($curso['imagem'])): ?>
                        <div style="margin-bottom: 10px;">
                            <img src="<?= htmlspecialchars($curso['imagem']) ?>" alt="Imagem atual" class="preview-imagem" id="preview-atual">
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control" id="imagem" name="imagem" accept="image/*">
                    <small>Deixe em branco para manter a imagem atual</small>
                </div>

                <div class="form-group" style="display: flex; gap: 10px; margin-top: 30px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                    <a href="admin_cursos.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                    <a href="admin_modulos.php?curso_id=<?= $curso['id'] ?>" class="btn" style="background: #17a2b8; color: white;">
                        <i class="fas fa-layer-group"></i> Gerenciar Módulos
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('form-curso').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Curso atualizado com sucesso!');
                    window.location.href = 'admin_cursos.php';
                } else {
                    alert('Erro ao atualizar curso: ' + data.message);
                }
            })
            .catch(error => {
                alert('Erro ao atualizar curso: ' + error);
            });
        });

        // Preview de nova imagem
        document.getElementById('imagem').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const previewAtual = document.getElementById('preview-atual');
                    if (previewAtual) {
                        previewAtual.src = e.target.result;
                    } else {
                        const newPreview = document.createElement('img');
                        newPreview.src = e.target.result;
                        newPreview.className = 'preview-imagem';
                        newPreview.id = 'preview-atual';
                        this.parentNode.insertBefore(newPreview, this);
                    }
                }
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>