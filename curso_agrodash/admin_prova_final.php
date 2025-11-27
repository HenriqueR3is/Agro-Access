<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    header("Location: dashboard.php");
    exit;
}

$curso_id = $_GET['curso_id'] ?? null;
$acao = $_GET['acao'] ?? 'criar';

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
    die("Erro ao carregar curso: " . $e->getMessage());
}

if (!$curso) {
    header("Location: admin_cursos.php");
    exit;
}

// Buscar prova final existente
try {
    $stmt = $pdo->prepare("SELECT * FROM provas_finais WHERE curso_id = ?");
    $stmt->execute([$curso_id]);
    $prova_final = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($prova_final) {
        // Buscar perguntas da prova final
        $stmt_perguntas = $pdo->prepare("SELECT * FROM prova_final_perguntas WHERE prova_id = ? ORDER BY ordem");
        $stmt_perguntas->execute([$prova_final['id']]);
        $perguntas = $stmt_perguntas->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $perguntas = [];
    }
} catch (PDOException $e) {
    $prova_final = null;
    $perguntas = [];
}

// Processar formulário de salvamento
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        $dados = $_POST;
        
        if ($prova_final) {
            // Atualizar prova existente
            $stmt = $pdo->prepare("UPDATE provas_finais SET titulo = ?, descricao = ?, total_questoes = ?, tempo_limite = ?, nota_minima = ?, tentativas_permitidas = ?, atualizado_em = NOW() WHERE id = ?");
            $stmt->execute([
                $dados['titulo'],
                $dados['descricao'],
                $dados['total_questoes'],
                $dados['tempo_limite'],
                $dados['nota_minima'],
                $dados['tentativas_permitidas'],
                $prova_final['id']
            ]);
            $prova_id = $prova_final['id'];
            
            // Remover perguntas antigas
            $stmt = $pdo->prepare("DELETE FROM prova_final_perguntas WHERE prova_id = ?");
            $stmt->execute([$prova_id]);
        } else {
            // Criar nova prova
            $stmt = $pdo->prepare("INSERT INTO provas_finais (curso_id, titulo, descricao, total_questoes, tempo_limite, nota_minima, tentativas_permitidas, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $curso_id,
                $dados['titulo'],
                $dados['descricao'],
                $dados['total_questoes'],
                $dados['tempo_limite'],
                $dados['nota_minima'],
                $dados['tentativas_permitidas']
            ]);
            $prova_id = $pdo->lastInsertId();
        }
        
        // Salvar perguntas
        if (isset($dados['perguntas']) && is_array($dados['perguntas'])) {
            foreach ($dados['perguntas'] as $index => $pergunta_data) {
                if (!empty($pergunta_data['pergunta']) && isset($pergunta_data['opcoes'])) {
                    $opcoes_array = [];
                    $resposta_correta = 0;
                    
                    foreach ($pergunta_data['opcoes'] as $opcao_index => $opcao_texto) {
                        if (!empty($opcao_texto)) {
                            $opcoes_array[] = $opcao_texto;
                            
                            // Verificar se é a resposta correta
                            if (isset($pergunta_data['correta']) && $pergunta_data['correta'] == $opcao_index) {
                                $resposta_correta = count($opcoes_array) - 1;
                            }
                        }
                    }
                    
                    if (count($opcoes_array) >= 2) {
                        $stmt = $pdo->prepare("INSERT INTO prova_final_perguntas (prova_id, pergunta, opcoes, resposta_correta, ordem, criado_em) VALUES (?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([
                            $prova_id,
                            $pergunta_data['pergunta'],
                            json_encode($opcoes_array),
                            $resposta_correta,
                            $index
                        ]);
                    }
                }
            }
        }
        
        $pdo->commit();
        $_SESSION['success_message'] = "Prova final salva com sucesso!";
        header("Location: admin_modulos.php?curso_id=" . $curso_id);
        exit;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error_message = "Erro ao salvar prova final: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Prova Final - AgroDash</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Roboto', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .admin-container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 15px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); overflow: hidden; }
        .admin-header { background: linear-gradient(135deg, #32CD32, #228B22); color: white; padding: 30px; }
        .admin-content { padding: 30px; }
        .curso-info { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
        .btn { padding: 10px 20px; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: #32CD32; color: white; }
        .btn-primary:hover { background: #228B22; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #545b62; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; color: #2c3e50; }
        .form-control { width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 6px; font-size: 1rem; }
        .form-control:focus { outline: none; border-color: #32CD32; }
        .form-textarea { min-height: 100px; resize: vertical; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        .pergunta-item { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px; border-left: 4px solid #32CD32; }
        .pergunta-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .opcoes-list { display: flex; flex-direction: column; gap: 10px; }
        .opcao-item { display: flex; align-items: center; gap: 10px; padding: 10px; background: white; border-radius: 4px; }
        .btn-remove { background: #dc3545; color: white; border: none; border-radius: 4px; padding: 5px 10px; cursor: pointer; font-size: 0.8rem; }
        .btn-remove:hover { background: #c82333; }
        .empty-state { text-align: center; padding: 40px; color: #6c757d; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        @media (max-width: 768px) {
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1><i class="fas fa-graduation-cap"></i> Gerenciar Prova Final</h1>
            <p>Curso: <?= htmlspecialchars($curso['titulo']) ?></p>
        </div>

        <div class="admin-content">
            <div class="curso-info">
                <h3><?= htmlspecialchars($curso['titulo']) ?></h3>
                <p><?= htmlspecialchars($curso['descricao']) ?></p>
                <div style="display: flex; gap: 15px; margin-top: 15px;">
                    <a href="admin_modulos.php?curso_id=<?= $curso_id ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Voltar aos Módulos
                    </a>
                </div>
            </div>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?= $error_message ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <form id="form-prova-final" method="POST">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="titulo">Título da Prova Final *</label>
                        <input type="text" class="form-control" id="titulo" name="titulo" 
                               value="<?= htmlspecialchars($prova_final['titulo'] ?? 'Prova Final do Curso') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="total_questoes">Total de Questões *</label>
                        <input type="number" class="form-control" id="total_questoes" name="total_questoes" 
                               value="<?= $prova_final['total_questoes'] ?? 10 ?>" min="1" max="50" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="descricao">Descrição da Prova</label>
                    <textarea class="form-control form-textarea" id="descricao" name="descricao"><?= htmlspecialchars($prova_final['descricao'] ?? 'Prova final para avaliar o conhecimento adquirido durante o curso.') ?></textarea>
                </div>

                <div class="grid-3">
                    <div class="form-group">
                        <label class="form-label" for="tempo_limite">Tempo Limite (minutos) *</label>
                        <input type="number" class="form-control" id="tempo_limite" name="tempo_limite" 
                               value="<?= $prova_final['tempo_limite'] ?? 60 ?>" min="10" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="nota_minima">Nota Mínima para Aprovação (%) *</label>
                        <input type="number" class="form-control" id="nota_minima" name="nota_minima" 
                               value="<?= $prova_final['nota_minima'] ?? 70 ?>" min="1" max="100" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="tentativas_permitidas">Tentativas Permitidas *</label>
                        <input type="number" class="form-control" id="tentativas_permitidas" name="tentativas_permitidas" 
                               value="<?= $prova_final['tentativas_permitidas'] ?? 2 ?>" min="1" max="5" required>
                    </div>
                </div>

                <h3 style="margin: 30px 0 20px 0; color: #2c3e50; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-question-circle"></i> Perguntas da Prova Final
                </h3>

                <div id="perguntas-container">
                    <?php if (!empty($perguntas)): ?>
                        <?php foreach ($perguntas as $index => $pergunta): 
                            $opcoes = json_decode($pergunta['opcoes'], true);
                        ?>
                            <div class="pergunta-item" data-pergunta-index="<?= $index ?>">
                                <div class="pergunta-header">
                                    <strong>Pergunta <?= $index + 1 ?></strong>
                                    <button type="button" class="btn btn-danger btn-remove" onclick="removerPergunta(this)">
                                        <i class="fas fa-trash"></i> Remover
                                    </button>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Pergunta *</label>
                                    <input type="text" class="form-control pergunta-texto" name="perguntas[<?= $index ?>][pergunta]" 
                                           value="<?= htmlspecialchars($pergunta['pergunta']) ?>" placeholder="Digite a pergunta..." required>
                                </div>

                                <label class="form-label">Opções de Resposta *</label>
                                <div class="opcoes-list">
                                    <?php foreach ($opcoes as $opcao_index => $opcao): ?>
                                        <div class="opcao-item">
                                            <input type="radio" name="perguntas[<?= $index ?>][correta]" value="<?= $opcao_index ?>" 
                                                   <?= $opcao_index == $pergunta['resposta_correta'] ? 'checked' : '' ?> required>
                                            <input type="text" class="form-control opcao-texto" 
                                                   name="perguntas[<?= $index ?>][opcoes][<?= $opcao_index ?>]" 
                                                   value="<?= htmlspecialchars($opcao) ?>" placeholder="Texto da opção..." required>
                                            <button type="button" class="btn-remove" onclick="removerOpcao(this)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <button type="button" class="btn btn-secondary" onclick="adicionarOpcao(this)" style="margin-top: 10px;">
                                    <i class="fas fa-plus"></i> Adicionar Opção
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state" id="empty-perguntas">
                            <i class="fas fa-question-circle" style="font-size: 3rem; margin-bottom: 15px;"></i>
                            <h4>Nenhuma pergunta adicionada</h4>
                            <p>Comece adicionando a primeira pergunta da prova.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="display: flex; gap: 15px; margin: 25px 0;">
                    <button type="button" class="btn btn-success" onclick="adicionarPergunta()">
                        <i class="fas fa-plus"></i> Adicionar Pergunta
                    </button>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Salvar Prova Final
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let perguntaCount = <?= count($perguntas) ?>;

        function adicionarPergunta() {
            const container = document.getElementById('perguntas-container');
            const emptyState = document.getElementById('empty-perguntas');
            
            if (emptyState) {
                emptyState.remove();
            }
            
            const perguntaIndex = perguntaCount++;
            
            const perguntaHTML = `
                <div class="pergunta-item" data-pergunta-index="${perguntaIndex}">
                    <div class="pergunta-header">
                        <strong>Pergunta ${perguntaIndex + 1}</strong>
                        <button type="button" class="btn btn-danger btn-remove" onclick="removerPergunta(this)">
                            <i class="fas fa-trash"></i> Remover
                        </button>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Pergunta *</label>
                        <input type="text" class="form-control pergunta-texto" name="perguntas[${perguntaIndex}][pergunta]" placeholder="Digite a pergunta..." required>
                    </div>

                    <label class="form-label">Opções de Resposta *</label>
                    <div class="opcoes-list">
                        <div class="opcao-item">
                            <input type="radio" name="perguntas[${perguntaIndex}][correta]" value="0" checked required>
                            <input type="text" class="form-control opcao-texto" name="perguntas[${perguntaIndex}][opcoes][0]" placeholder="Texto da opção..." required>
                            <button type="button" class="btn-remove" onclick="removerOpcao(this)">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="opcao-item">
                            <input type="radio" name="perguntas[${perguntaIndex}][correta]" value="1" required>
                            <input type="text" class="form-control opcao-texto" name="perguntas[${perguntaIndex}][opcoes][1]" placeholder="Texto da opção..." required>
                            <button type="button" class="btn-remove" onclick="removerOpcao(this)">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button type="button" class="btn btn-secondary" onclick="adicionarOpcao(this)" style="margin-top: 10px;">
                        <i class="fas fa-plus"></i> Adicionar Opção
                    </button>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', perguntaHTML);
        }

        function adicionarOpcao(button) {
            const opcoesList = button.previousElementSibling;
            const perguntaItem = button.closest('.pergunta-item');
            const perguntaIndex = perguntaItem.dataset.perguntaIndex;
            const opcaoCount = opcoesList.children.length;
            
            const opcaoHTML = `
                <div class="opcao-item">
                    <input type="radio" name="perguntas[${perguntaIndex}][correta]" value="${opcaoCount}" required>
                    <input type="text" class="form-control opcao-texto" name="perguntas[${perguntaIndex}][opcoes][${opcaoCount}]" placeholder="Texto da opção..." required>
                    <button type="button" class="btn-remove" onclick="removerOpcao(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            opcoesList.insertAdjacentHTML('beforeend', opcaoHTML);
        }

        function removerPergunta(button) {
            if (confirm('Tem certeza que deseja remover esta pergunta?')) {
                const perguntaItem = button.closest('.pergunta-item');
                perguntaItem.remove();
                
                // Reindexar perguntas restantes
                const perguntas = document.querySelectorAll('.pergunta-item');
                if (perguntas.length === 0) {
                    const container = document.getElementById('perguntas-container');
                    container.innerHTML = `
                        <div class="empty-state" id="empty-perguntas">
                            <i class="fas fa-question-circle" style="font-size: 3rem; margin-bottom: 15px;"></i>
                            <h4>Nenhuma pergunta adicionada</h4>
                            <p>Comece adicionando a primeira pergunta da prova.</p>
                        </div>
                    `;
                } else {
                    perguntas.forEach((item, index) => {
                        item.querySelector('strong').textContent = `Pergunta ${index + 1}`;
                        item.dataset.perguntaIndex = index;
                        
                        // Atualizar names dos inputs
                        const perguntaInput = item.querySelector('.pergunta-texto');
                        const opcoesInputs = item.querySelectorAll('.opcao-texto');
                        const radioInputs = item.querySelectorAll('input[type="radio"]');
                        
                        perguntaInput.name = `perguntas[${index}][pergunta]`;
                        
                        opcoesInputs.forEach((opcaoInput, opcaoIndex) => {
                            opcaoInput.name = `perguntas[${index}][opcoes][${opcaoIndex}]`;
                        });
                        
                        radioInputs.forEach((radioInput, radioIndex) => {
                            radioInput.name = `perguntas[${index}][correta]`;
                            radioInput.value = radioIndex;
                        });
                    });
                    
                    perguntaCount = perguntas.length;
                }
            }
        }

        function removerOpcao(button) {
            const opcaoItem = button.closest('.opcao-item');
            const opcoesList = opcaoItem.parentElement;
            
            if (opcoesList.children.length > 1) {
                // Verificar se a opção a ser removida é a correta
                const radio = opcaoItem.querySelector('input[type="radio"]');
                if (radio.checked) {
                    // Marcar a primeira opção como correta
                    opcoesList.querySelector('input[type="radio"]').checked = true;
                }
                
                opcaoItem.remove();
                
                // Reindexar opções
                const opcoes = opcoesList.querySelectorAll('.opcao-item');
                opcoes.forEach((item, index) => {
                    const input = item.querySelector('.opcao-texto');
                    const radio = item.querySelector('input[type="radio"]');
                    
                    input.name = input.name.replace(/\[\d+\]$/, `[${index}]`);
                    radio.value = index;
                });
            } else {
                alert('Cada pergunta deve ter pelo menos uma opção de resposta.');
            }
        }

        // Validação do formulário
        document.getElementById('form-prova-final').addEventListener('submit', function(e) {
            const totalQuestoes = parseInt(document.getElementById('total_questoes').value);
            const perguntas = document.querySelectorAll('.pergunta-item');
            
            if (perguntas.length === 0) {
                e.preventDefault();
                alert('Adicione pelo menos uma pergunta à prova.');
                return;
            }
            
            if (perguntas.length !== totalQuestoes) {
                if (!confirm(`O número de perguntas (${perguntas.length}) não corresponde ao total de questões configurado (${totalQuestoes}). Deseja continuar mesmo assim?`)) {
                    e.preventDefault();
                    return;
                }
            }
            
            // Validar cada pergunta
            let todasValidas = true;
            perguntas.forEach((pergunta, index) => {
                const texto = pergunta.querySelector('.pergunta-texto').value.trim();
                const opcoes = pergunta.querySelectorAll('.opcao-texto');
                let opcoesPreenchidas = 0;
                
                opcoes.forEach(opcao => {
                    if (opcao.value.trim() !== '') {
                        opcoesPreenchidas++;
                    }
                });
                
                if (!texto || opcoesPreenchidas < 2) {
                    todasValidas = false;
                    alert(`A pergunta ${index + 1} não está completa. Certifique-se de que tem pelo menos 2 opções preenchidas.`);
                }
            });
            
            if (!todasValidas) {
                e.preventDefault();
            }
        });

        // Adicionar primeira pergunta automaticamente se não houver nenhuma
        document.addEventListener('DOMContentLoaded', function() {
            const perguntas = document.querySelectorAll('.pergunta-item');
            if (perguntas.length === 0) {
                adicionarPergunta();
            }
        });
    </script>
</body>
</html>