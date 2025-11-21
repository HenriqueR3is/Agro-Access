<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    header("Location: dashboard.php");
    exit;
}

$curso_id = $_GET['curso_id'] ?? null;

if (!$curso_id) {
    header("Location: admin_cursos.php");
    exit;
}

// Buscar informações do curso
try {
    $stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
    $stmt->execute([$curso_id]);
    $curso = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Erro ao carregar curso: " . $e->getMessage();
}

if (!$curso) {
    header("Location: admin_cursos.php");
    exit;
}

// Buscar módulos do curso
try {
    $stmt = $pdo->prepare("SELECT * FROM modulos WHERE curso_id = ? ORDER BY ordem");
    $stmt->execute([$curso_id]);
    $modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $modulos = [];
    // A tabela modulos pode não existir ainda
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Módulos - AgroDash</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Roboto', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .admin-container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 15px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); overflow: hidden; }
        .admin-header { background: linear-gradient(135deg, #32CD32, #228B22); color: white; padding: 30px; }
        .admin-content { padding: 30px; }
        .curso-info { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
        .modulos-list { display: flex; flex-direction: column; gap: 15px; }
        .modulo-item { background: white; border: 2px solid #e9ecef; border-radius: 8px; padding: 20px; transition: all 0.3s ease; }
        .modulo-item:hover { border-color: #32CD32; box-shadow: 0 5px 15px rgba(50, 205, 50, 0.1); }
        .modulo-header { display: flex; justify-content: between; align-items: center; margin-bottom: 15px; }
        .modulo-titulo { font-size: 1.2rem; font-weight: 600; color: #2c3e50; flex: 1; }
        .modulo-meta { display: flex; gap: 15px; color: #6c757d; font-size: 0.9rem; }
        .modulo-acoes { display: flex; gap: 8px; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-size: 0.9rem; }
        .btn-primary { background: #32CD32; color: white; }
        .btn-primary:hover { background: #228B22; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #545b62; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .empty-state { text-align: center; padding: 50px; color: #6c757d; }
        .form-group { margin-bottom: 15px; }
        .form-label { display: block; margin-bottom: 5px; font-weight: 500; color: #2c3e50; }
        .form-control { width: 100%; padding: 10px 12px; border: 2px solid #e9ecef; border-radius: 6px; font-size: 0.95rem; }
        .form-control:focus { outline: none; border-color: #32CD32; }
        .form-textarea { min-height: 80px; resize: vertical; }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1><i class="fas fa-layer-group"></i> Gerenciar Módulos</h1>
            <p>Curso: <?= htmlspecialchars($curso['titulo']) ?></p>
        </div>

        <div class="admin-content">
            <div class="curso-info">
                <h3><?= htmlspecialchars($curso['titulo']) ?></h3>
                <p><?= htmlspecialchars($curso['descricao']) ?></p>
                <div style="display: flex; gap: 20px; margin-top: 10px;">
                    <a href="admin_cursos.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Voltar aos Cursos
                    </a>
                    <a href="admin_curso_editar.php?id=<?= $curso['id'] ?>" class="btn">
                        <i class="fas fa-edit"></i> Editar Curso
                    </a>
                    <button class="btn btn-success" onclick="mostrarFormModulo()">
                        <i class="fas fa-plus"></i> Novo Módulo
                    </button>
                </div>
            </div>

            <!-- Formulário de Novo Módulo (inicialmente oculto) -->
            <div id="form-modulo-container" style="display: none; background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <h3><i class="fas fa-plus"></i> Adicionar Novo Módulo</h3>
                <form id="form-modulo" action="ajax/salvar_modulo.php" method="POST">
                    <input type="hidden" name="curso_id" value="<?= $curso_id ?>">
                    
                    <div class="form-group">
                        <label class="form-label" for="titulo">Título do Módulo *</label>
                        <input type="text" class="form-control" id="titulo" name="titulo" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="descricao">Descrição</label>
                        <textarea class="form-control form-textarea" id="descricao" name="descricao"></textarea>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label class="form-label" for="ordem">Ordem</label>
                            <input type="number" class="form-control" id="ordem" name="ordem" value="<?= count($modulos) + 1 ?>" min="1">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="duracao">Duração</label>
                            <input type="text" class="form-control" id="duracao" name="duracao" value="30 min">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="icone">Ícone</label>
                            <select class="form-control" id="icone" name="icone">
                                <option value="fas fa-play-circle">Play</option>
                                <option value="fas fa-chart-bar">Gráfico</option>
                                <option value="fas fa-chart-line">Linha</option>
                                <option value="fas fa-book">Livro</option>
                                <option value="fas fa-video">Vídeo</option>
                                <option value="fas fa-file-alt">Documento</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Salvar Módulo
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="ocultarFormModulo()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>

            <!-- Lista de Módulos -->
            <div class="modulos-list">
                <?php if (!empty($modulos)): ?>
                    <?php foreach ($modulos as $modulo): ?>
                    <div class="modulo-item">
                        <div class="modulo-header">
                            <div class="modulo-titulo">
                                <i class="<?= $modulo['icone'] ?? 'fas fa-book' ?>"></i>
                                <?= htmlspecialchars($modulo['titulo']) ?>
                            </div>
                            <div class="modulo-meta">
                                <span><i class="fas fa-clock"></i> <?= $modulo['duracao'] ?? '30 min' ?></span>
                                <span><i class="fas fa-sort-numeric-down"></i> Ordem: <?= $modulo['ordem'] ?></span>
                            </div>
                            <div class="modulo-acoes">
                                <a href="admin_conteudos.php?modulo_id=<?= $modulo['id'] ?>" class="btn btn-primary">
                                    <i class="fas fa-file-alt"></i> Conteúdos
                                </a>
                                <button class="btn" onclick="editarModulo(<?= $modulo['id'] ?>, '<?= htmlspecialchars($modulo['titulo']) ?>', '<?= htmlspecialchars($modulo['descricao']) ?>', <?= $modulo['ordem'] ?>, '<?= $modulo['duracao'] ?>', '<?= $modulo['icone'] ?>')">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <button class="btn btn-secondary" onclick="excluirModulo(<?= $modulo['id'] ?>)">
                                    <i class="fas fa-trash"></i> Excluir
                                </button>
                            </div>
                        </div>
                        <?php if (!empty($modulo['descricao'])): ?>
                            <p style="color: #6c757d; font-size: 0.95rem;"><?= htmlspecialchars($modulo['descricao']) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-layer-group" style="font-size: 4rem; margin-bottom: 20px;"></i>
                        <h3>Nenhum módulo encontrado</h3>
                        <p>Comece criando o primeiro módulo deste curso!</p>
                        <button class="btn btn-success" onclick="mostrarFormModulo()">
                            <i class="fas fa-plus"></i> Criar Primeiro Módulo
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function mostrarFormModulo() {
            document.getElementById('form-modulo-container').style.display = 'block';
        }

        function ocultarFormModulo() {
            document.getElementById('form-modulo-container').style.display = 'none';
            document.getElementById('form-modulo').reset();
        }

        function editarModulo(id, titulo, descricao, ordem, duracao, icone) {
            // Preencher formulário com dados do módulo
            document.querySelector('input[name="titulo"]').value = titulo;
            document.querySelector('textarea[name="descricao"]').value = descricao;
            document.querySelector('input[name="ordem"]').value = ordem;
            document.querySelector('input[name="duracao"]').value = duracao;
            document.querySelector('select[name="icone"]').value = icone;
            
            // Alterar formulário para edição
            const form = document.getElementById('form-modulo');
            form.action = 'ajax/salvar_modulo.php';
            form.innerHTML += `<input type="hidden" name="id" value="${id}">`;
            
            mostrarFormModulo();
        }

        function excluirModulo(moduloId) {
            if (confirm('Tem certeza que deseja excluir este módulo? Todos os conteúdos serão perdidos.')) {
                window.location.href = `ajax/excluir_modulo.php?id=${moduloId}`;
            }
        }

        // Submissão do formulário
        document.getElementById('form-modulo').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Módulo salvo com sucesso!');
                    window.location.reload();
                } else {
                    alert('Erro ao salvar módulo: ' + data.message);
                }
            })
            .catch(error => {
                alert('Erro ao salvar módulo: ' + error);
            });
        });
    </script>
</body>
</html>