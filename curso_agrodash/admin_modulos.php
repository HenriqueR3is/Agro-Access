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
}

// Buscar prova final do curso
try {
    $stmt = $pdo->prepare("SELECT * FROM provas_finais WHERE curso_id = ?");
    $stmt->execute([$curso_id]);
    $prova_final = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $prova_final = null;
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
        .admin-container { max-width: 1400px; margin: 0 auto; background: white; border-radius: 15px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); overflow: hidden; }
        .admin-header { background: linear-gradient(135deg, #32CD32, #228B22); color: white; padding: 30px; }
        .admin-content { padding: 30px; }
        .curso-info { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
        .modulos-list { display: flex; flex-direction: column; gap: 15px; margin-bottom: 40px; }
        .modulo-item { background: white; border: 2px solid #e9ecef; border-radius: 8px; padding: 20px; transition: all 0.3s ease; }
        .modulo-item:hover { border-color: #32CD32; box-shadow: 0 5px 15px rgba(50, 205, 50, 0.1); }
        .modulo-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .modulo-titulo { font-size: 1.2rem; font-weight: 600; color: #2c3e50; flex: 1; }
        .modulo-meta { display: flex; gap: 15px; color: #6c757d; font-size: 0.9rem; }
        .modulo-acoes { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-size: 0.9rem; }
        .btn-primary { background: #32CD32; color: white; }
        .btn-primary:hover { background: #228B22; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #545b62; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .btn-info { background: #17a2b8; color: white; }
        .btn-info:hover { background: #138496; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-warning:hover { background: #e0a800; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .empty-state { text-align: center; padding: 50px; color: #6c757d; }
        .form-group { margin-bottom: 15px; }
        .form-label { display: block; margin-bottom: 5px; font-weight: 500; color: #2c3e50; }
        .form-control { width: 100%; padding: 10px 12px; border: 2px solid #e9ecef; border-radius: 6px; font-size: 0.95rem; }
        .form-control:focus { outline: none; border-color: #32CD32; }
        .form-textarea { min-height: 80px; resize: vertical; }
        .prova-final-section { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 12px; margin-top: 30px; }
        .pergunta-item { background: #f8f9fa; border-radius: 8px; padding: 15px; margin-bottom: 15px; border-left: 4px solid #32CD32; }
        .pergunta-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .opcoes-list { display: flex; flex-direction: column; gap: 8px; }
        .opcao-item { display: flex; align-items: center; gap: 10px; padding: 8px; background: white; border-radius: 4px; }
        .btn-remove { background: #dc3545; color: white; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer; font-size: 0.8rem; }
        .btn-remove:hover { background: #c82333; }
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; }
        .tab { padding: 12px 24px; background: #f8f9fa; border-radius: 6px 6px 0 0; cursor: pointer; border: 1px solid #e9ecef; border-bottom: none; transition: all 0.3s ease; }
        .tab.active { background: #32CD32; color: white; border-color: #32CD32; }
        .tab-content { display: none; padding: 20px 0; }
        .tab-content.active { display: block; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        /* Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal-content { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 12px; width: 90%; max-width: 900px; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e9ecef; }
        .modal-title { font-size: 1.5rem; font-weight: 600; color: #2c3e50; flex: 1; }
        
        @media (max-width: 768px) {
            .modulo-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .modulo-acoes { width: 100%; justify-content: flex-start; }
            .modal-content { width: 95%; padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1><i class="fas fa-layer-group"></i> Gerenciar Módulos</h1>
            <p>Curso: <?= htmlspecialchars($curso['titulo']) ?></p>
        </div>

        <div class="admin-content">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?= $error ?>
                </div>
            <?php endif; ?>

            <div class="curso-info">
                <h3><?= htmlspecialchars($curso['titulo']) ?></h3>
                <p><?= htmlspecialchars($curso['descricao']) ?></p>
                <div style="display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap;">
                    <a href="admin_cursos.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Voltar aos Cursos
                    </a>
                    <a href="admin_curso_editar.php?id=<?= $curso['id'] ?>" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Editar Curso
                    </a>
                    <button class="btn btn-success" onclick="mostrarFormModulo()">
                        <i class="fas fa-plus"></i> Novo Módulo
                    </button>
                    <a href="admin_prova_final.php?curso_id=<?= $curso_id ?>" class="btn btn-info">
                        <i class="fas fa-graduation-cap"></i> Gerenciar Prova Final
                    </a>
                </div>
            </div>

            <!-- Tabs para navegação -->
            <div class="tabs">
                <div class="tab active" data-tab="modulos">
                    <i class="fas fa-book"></i> Módulos (<?= count($modulos) ?>)
                </div>
                <div class="tab" data-tab="prova-final">
                    <i class="fas fa-graduation-cap"></i> Prova Final
                </div>
            </div>

            <!-- Tab Módulos -->
            <div class="tab-content active" id="tab-modulos">
                <!-- Formulário de Novo Módulo -->
                <div id="form-modulo-container" style="display: none; background: #f8f9fa; padding: 25px; border-radius: 8px; margin-bottom: 25px; border: 2px solid #32CD32;">
                    <h3 style="margin-bottom: 20px; color: #2c3e50; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-plus"></i> 
                        <span id="form-modulo-titulo">Adicionar Novo Módulo</span>
                    </h3>
                    <form id="form-modulo" action="ajax/salvar_modulo.php" method="POST">
                        <input type="hidden" name="curso_id" value="<?= $curso_id ?>">
                        <input type="hidden" name="id" id="modulo_id">
                        
                        <div class="form-group">
                            <label class="form-label" for="titulo">Título do Módulo *</label>
                            <input type="text" class="form-control" id="titulo" name="titulo" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="descricao">Descrição</label>
                            <textarea class="form-control form-textarea" id="descricao" name="descricao" placeholder="Descreva o conteúdo deste módulo..."></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label class="form-label" for="ordem">Ordem *</label>
                                <input type="number" class="form-control" id="ordem" name="ordem" value="<?= count($modulos) + 1 ?>" min="1" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="duracao">Duração</label>
                                <input type="text" class="form-control" id="duracao" name="duracao" value="30 min" placeholder="ex: 30 min, 1 hora">
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="icone">Ícone</label>
                                <select class="form-control" id="icone" name="icone">
                                    <option value="fas fa-book">Livro</option>
                                    <option value="fas fa-play-circle">Play</option>
                                    <option value="fas fa-chart-bar">Gráfico</option>
                                    <option value="fas fa-chart-line">Linha</option>
                                    <option value="fas fa-video">Vídeo</option>
                                    <option value="fas fa-file-alt">Documento</option>
                                    <option value="fas fa-cogs">Engrenagens</option>
                                    <option value="fas fa-lightbulb">Lâmpada</option>
                                </select>
                            </div>
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: 25px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> <span id="btn-salvar-texto">Salvar Módulo</span>
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
                                    <button class="btn btn-info" onclick="gerenciarQuizModulo(<?= $modulo['id'] ?>, '<?= htmlspecialchars($modulo['titulo']) ?>')">
                                        <i class="fas fa-question-circle"></i> Quiz
                                    </button>
                                    <button class="btn btn-warning" onclick="editarModulo(<?= $modulo['id'] ?>, '<?= htmlspecialchars($modulo['titulo']) ?>', '<?= htmlspecialchars($modulo['descricao']) ?>', <?= $modulo['ordem'] ?>, '<?= $modulo['duracao'] ?>', '<?= $modulo['icone'] ?>')">
                                        <i class="fas fa-edit"></i> Editar
                                    </button>
                                    <button class="btn btn-danger" onclick="excluirModulo(<?= $modulo['id'] ?>)">
                                        <i class="fas fa-trash"></i> Excluir
                                    </button>
                                </div>
                            </div>
                            <?php if (!empty($modulo['descricao'])): ?>
                                <p style="color: #6c757d; font-size: 0.95rem; margin-top: 10px; line-height: 1.5;">
                                    <?= htmlspecialchars($modulo['descricao']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-layer-group" style="font-size: 4rem; margin-bottom: 20px; color: #6c757d;"></i>
                            <h3 style="color: #6c757d; margin-bottom: 10px;">Nenhum módulo encontrado</h3>
                            <p style="color: #6c757d; margin-bottom: 20px;">Comece criando o primeiro módulo deste curso!</p>
                            <button class="btn btn-success" onclick="mostrarFormModulo()">
                                <i class="fas fa-plus"></i> Criar Primeiro Módulo
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab Prova Final -->
            <div class="tab-content" id="tab-prova-final">
                <div class="prova-final-section">
                    <h3 style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-graduation-cap"></i> Prova Final do Curso
                    </h3>
                    <p style="margin-bottom: 20px; opacity: 0.9;">
                        Configure a prova final que será disponibilizada após a conclusão de todos os módulos.
                    </p>
                    
                    <?php if ($prova_final): ?>
                        <div style="background: rgba(255,255,255,0.1); padding: 20px; border-radius: 8px; margin: 20px 0;">
                            <h4 style="margin-bottom: 15px;">Prova Final Configurada</h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                <div>
                                    <strong>Quantidade de questões:</strong>
                                    <div style="font-size: 1.2rem; font-weight: bold;"><?= $prova_final['total_questoes'] ?></div>
                                </div>
                                <div>
                                    <strong>Nota mínima:</strong>
                                    <div style="font-size: 1.2rem; font-weight: bold;"><?= $prova_final['nota_minima'] ?>%</div>
                                </div>
                                <div>
                                    <strong>Tempo limite:</strong>
                                    <div style="font-size: 1.2rem; font-weight: bold;"><?= $prova_final['tempo_limite'] ?> min</div>
                                </div>
                                <div>
                                    <strong>Tentativas:</strong>
                                    <div style="font-size: 1.2rem; font-weight: bold;"><?= $prova_final['tentativas_permitidas'] ?></div>
                                </div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <a href="admin_prova_final.php?curso_id=<?= $curso_id ?>&acao=editar" class="btn btn-success">
                                <i class="fas fa-edit"></i> Editar Prova Final
                            </a>
                            <a href="admin_prova_final.php?curso_id=<?= $curso_id ?>&acao=visualizar" class="btn btn-info">
                                <i class="fas fa-eye"></i> Ver Perguntas
                            </a>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 20px;">
                            <i class="fas fa-graduation-cap" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.7;"></i>
                            <h4 style="margin-bottom: 10px;">Nenhuma prova final configurada</h4>
                            <p style="margin-bottom: 20px; opacity: 0.8;">Crie a prova final para este curso.</p>
                            <a href="admin_prova_final.php?curso_id=<?= $curso_id ?>&acao=criar" class="btn btn-success">
                                <i class="fas fa-plus"></i> Criar Prova Final
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Gerenciar Quiz do Módulo -->
    <div id="modal-quiz" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modal-quiz-titulo"></h3>
                <button type="button" class="btn btn-secondary" onclick="fecharModalQuiz()">
                    <i class="fas fa-times"></i> Fechar
                </button>
            </div>
            <div id="conteudo-quiz"></div>
        </div>
    </div>

    <script>
        // Funções de tabs
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                tab.classList.add('active');
                document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
            });
        });

        let editandoModulo = false;

        function mostrarFormModulo() {
            const container = document.getElementById('form-modulo-container');
            container.style.display = 'block';
            container.scrollIntoView({ behavior: 'smooth' });
        }

        function ocultarFormModulo() {
            const container = document.getElementById('form-modulo-container');
            container.style.display = 'none';
            document.getElementById('form-modulo').reset();
            document.getElementById('modulo_id').value = '';
            document.getElementById('form-modulo-titulo').textContent = 'Adicionar Novo Módulo';
            document.getElementById('btn-salvar-texto').textContent = 'Salvar Módulo';
            editandoModulo = false;
        }

        function editarModulo(id, titulo, descricao, ordem, duracao, icone) {
            document.getElementById('modulo_id').value = id;
            document.getElementById('titulo').value = titulo;
            document.getElementById('descricao').value = descricao;
            document.getElementById('ordem').value = ordem;
            document.getElementById('duracao').value = duracao;
            document.getElementById('icone').value = icone;
            
            document.getElementById('form-modulo-titulo').textContent = 'Editar Módulo';
            document.getElementById('btn-salvar-texto').textContent = 'Atualizar Módulo';
            
            mostrarFormModulo();
            editandoModulo = true;
        }

        function excluirModulo(moduloId) {
            if (confirm('Tem certeza que deseja excluir este módulo?\n\n⚠️ Todos os conteúdos e quizzes associados serão perdidos.')) {
                window.location.href = `ajax/excluir_modulo.php?id=${moduloId}`;
            }
        }

        function gerenciarQuizModulo(moduloId, moduloTitulo) {
            console.log('Abrindo quiz para módulo:', moduloId);
            document.getElementById('modal-quiz-titulo').textContent = `Quiz do Módulo: ${moduloTitulo}`;
            
            // Mostrar loading
            document.getElementById('conteudo-quiz').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #32CD32;"></i>
                    <p style="margin-top: 15px; color: #6c757d;">Carregando quiz...</p>
                </div>
            `;
            
            // Carregar conteúdo do quiz via AJAX
            fetch(`ajax/carregar_quiz_modulo.php?modulo_id=${moduloId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Erro ao carregar quiz: ' + response.status);
                    }
                    return response.text();
                })
                .then(html => {
                    document.getElementById('conteudo-quiz').innerHTML = html;
                    document.getElementById('modal-quiz').style.display = 'block';
                })
                .catch(error => {
                    console.error('Erro ao carregar quiz:', error);
                    document.getElementById('conteudo-quiz').innerHTML = `
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Erro ao carregar quiz:</strong><br>
                            ${error.message}
                        </div>
                        <div style="text-align: center; margin-top: 20px;">
                            <button class="btn btn-secondary" onclick="fecharModalQuiz()">
                                <i class="fas fa-times"></i> Fechar
                            </button>
                        </div>
                    `;
                    document.getElementById('modal-quiz').style.display = 'block';
                });
        }

        function fecharModalQuiz() {
            document.getElementById('modal-quiz').style.display = 'none';
            document.getElementById('conteudo-quiz').innerHTML = '';
        }

        // Fechar modal ao clicar fora
        document.getElementById('modal-quiz').addEventListener('click', function(e) {
            if (e.target === this) {
                fecharModalQuiz();
            }
        });

        // Submissão do formulário de módulo
        document.getElementById('form-modulo').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Módulo salvo com sucesso!', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showAlert('Erro ao salvar módulo: ' + data.message, 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                showAlert('Erro ao salvar módulo: ' + error, 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        function showAlert(message, type) {
            // Remove alertas existentes
            const existingAlerts = document.querySelectorAll('.alert');
            existingAlerts.forEach(alert => alert.remove());
            
            const alert = document.createElement('div');
            alert.className = `alert alert-${type}`;
            alert.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                ${message}
            `;
            
            document.querySelector('.admin-content').insertBefore(alert, document.querySelector('.curso-info'));
            
            // Rolagem suave para o alerta
            alert.scrollIntoView({ behavior: 'smooth' });
            
            // Auto-remover após 5 segundos
            if (type === 'success') {
                setTimeout(() => {
                    alert.remove();
                }, 5000);
            }
        }

        // Adicionar primeira pergunta automaticamente se não houver nenhuma ao abrir o modal de quiz
        document.addEventListener('DOMContentLoaded', function() {
            // Fechar modal com ESC
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    fecharModalQuiz();
                }
            });
        });
    </script>
</body>
</html>