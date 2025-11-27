<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    die('Acesso negado');
}

$modulo_id = $_GET['modulo_id'] ?? null;

if (!$modulo_id) {
    die('ID do módulo não fornecido');
}

// Buscar quiz existente do módulo
try {
    $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE modulo_id = ?");
    $stmt->execute([$modulo_id]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $quiz = null;
}

// Buscar perguntas do quiz
$perguntas = [];
if ($quiz) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM quiz_perguntas WHERE quiz_id = ? ORDER BY ordem");
        $stmt->execute([$quiz['id']]);
        $perguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $perguntas = [];
    }
}
?>

<div id="quiz-manager">
    <div class="form-group">
        <label class="form-label">Título do Quiz</label>
        <input type="text" class="form-control" id="quiz_titulo" value="<?= $quiz ? htmlspecialchars($quiz['titulo']) : 'Quiz de Fixação' ?>">
    </div>

    <div class="form-group">
        <label class="form-label">Descrição</label>
        <textarea class="form-control form-textarea" id="quiz_descricao"><?= $quiz ? htmlspecialchars($quiz['descricao']) : 'Teste seus conhecimentos sobre este módulo' ?></textarea>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
            <label class="form-label">Duração (minutos)</label>
            <input type="number" class="form-control" id="quiz_duracao" value="<?= $quiz ? $quiz['duracao'] : 10 ?>" min="1">
        </div>

        <div class="form-group">
            <label class="form-label">Nota Mínima (%)</label>
            <input type="number" class="form-control" id="quiz_nota_minima" value="<?= $quiz ? $quiz['nota_minima'] : 70 ?>" min="1" max="100">
        </div>
    </div>

    <h4 style="margin: 20px 0 10px 0;">Perguntas do Quiz</h4>
    <div id="perguntas-container">
        <?php if (empty($perguntas)): ?>
            <div class="empty-state" style="padding: 20px;">
                <p>Nenhuma pergunta adicionada ainda.</p>
            </div>
        <?php else: ?>
            <?php foreach ($perguntas as $index => $pergunta): 
                $opcoes = json_decode($pergunta['opcoes'], true);
            ?>
                <div class="pergunta-item" data-pergunta-id="<?= $pergunta['id'] ?>">
                    <div class="pergunta-header">
                        <strong>Pergunta <?= $index + 1 ?></strong>
                        <button type="button" class="btn-remove" onclick="removerPerguntaQuiz(this)">Remover</button>
                    </div>
                    <input type="text" class="form-control pergunta-texto" value="<?= htmlspecialchars($pergunta['pergunta']) ?>" placeholder="Digite a pergunta..." style="margin-bottom: 10px;">
                    
                    <label style="display: block; margin: 10px 0 5px 0; font-weight: 500;">Opções de Resposta:</label>
                    <div class="opcoes-list">
                        <?php foreach ($opcoes as $i => $opcao): ?>
                            <div class="opcao-item">
                                <input type="radio" name="correta_<?= $index ?>" value="<?= $i ?>" <?= $i == $pergunta['resposta_correta'] ? 'checked' : '' ?>>
                                <input type="text" class="form-control opcao-texto" value="<?= htmlspecialchars($opcao) ?>" placeholder="Texto da opção..." style="flex: 1;">
                                <button type="button" class="btn-remove" onclick="removerOpcaoQuiz(this)">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="adicionarOpcaoQuiz(this)" style="margin-top: 10px; font-size: 0.8rem;">
                        <i class="fas fa-plus"></i> Adicionar Opção
                    </button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <button type="button" class="btn btn-success" onclick="adicionarPerguntaQuiz()" style="margin: 15px 0;">
        <i class="fas fa-plus"></i> Adicionar Pergunta
    </button>

    <div style="display: flex; gap: 10px; margin-top: 20px;">
        <button type="button" class="btn btn-primary" onclick="salvarQuiz(<?= $modulo_id ?>)">
            <i class="fas fa-save"></i> Salvar Quiz
        </button>
        <?php if ($quiz): ?>
            <button type="button" class="btn btn-secondary" onclick="excluirQuiz(<?= $quiz['id'] ?>)">
                <i class="fas fa-trash"></i> Excluir Quiz
            </button>
        <?php endif; ?>
    </div>
</div>

<script>
// Funções específicas para o quiz (para evitar conflito com outras páginas)
function adicionarPerguntaQuiz() {
    const container = document.getElementById('perguntas-container');
    const index = container.children.length;
    
    const perguntaHTML = `
        <div class="pergunta-item">
            <div class="pergunta-header">
                <strong>Pergunta ${index + 1}</strong>
                <button type="button" class="btn-remove" onclick="removerPerguntaQuiz(this)">Remover</button>
            </div>
            <input type="text" class="form-control pergunta-texto" placeholder="Digite a pergunta..." style="margin-bottom: 10px;">
            
            <label style="display: block; margin: 10px 0 5px 0; font-weight: 500;">Opções de Resposta:</label>
            <div class="opcoes-list">
                <div class="opcao-item">
                    <input type="radio" name="correta_${index}" value="0" checked>
                    <input type="text" class="form-control opcao-texto" placeholder="Texto da opção..." style="flex: 1;">
                    <button type="button" class="btn-remove" onclick="removerOpcaoQuiz(this)">×</button>
                </div>
                <div class="opcao-item">
                    <input type="radio" name="correta_${index}" value="1">
                    <input type="text" class="form-control opcao-texto" placeholder="Texto da opção..." style="flex: 1;">
                    <button type="button" class="btn-remove" onclick="removerOpcaoQuiz(this)">×</button>
                </div>
            </div>
            <button type="button" class="btn btn-secondary" onclick="adicionarOpcaoQuiz(this)" style="margin-top: 10px; font-size: 0.8rem;">
                <i class="fas fa-plus"></i> Adicionar Opção
            </button>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', perguntaHTML);
}

function adicionarOpcaoQuiz(button) {
    const opcoesList = button.previousElementSibling;
    const index = Array.from(opcoesList.parentElement.parentElement.parentElement.children).indexOf(opcoesList.parentElement.parentElement);
    
    const opcaoHTML = `
        <div class="opcao-item">
            <input type="radio" name="correta_${index}" value="${opcoesList.children.length}">
            <input type="text" class="form-control opcao-texto" placeholder="Texto da opção..." style="flex: 1;">
            <button type="button" class="btn-remove" onclick="removerOpcaoQuiz(this)">×</button>
        </div>
    `;
    
    opcoesList.insertAdjacentHTML('beforeend', opcaoHTML);
}

function removerPerguntaQuiz(button) {
    if (confirm('Tem certeza que deseja remover esta pergunta?')) {
        button.closest('.pergunta-item').remove();
        // Reindexar perguntas
        document.querySelectorAll('.pergunta-item').forEach((item, index) => {
            item.querySelector('strong').textContent = `Pergunta ${index + 1}`;
        });
    }
}

function removerOpcaoQuiz(button) {
    const opcaoItem = button.closest('.opcao-item');
    const opcoesList = opcaoItem.parentElement;
    
    if (opcoesList.children.length > 1) {
        opcaoItem.remove();
        // Reindexar opções
        Array.from(opcoesList.children).forEach((item, index) => {
            item.querySelector('input[type="radio"]').value = index;
        });
    } else {
        alert('Cada pergunta deve ter pelo menos uma opção de resposta.');
    }
}

function salvarQuiz(moduloId) {
    const perguntas = [];
    
    document.querySelectorAll('.pergunta-item').forEach((perguntaElem, index) => {
        const texto = perguntaElem.querySelector('.pergunta-texto').value.trim();
        const opcoes = [];
        let respostaCorreta = 0;
        
        perguntaElem.querySelectorAll('.opcao-item').forEach((opcaoElem, opcaoIndex) => {
            const textoOpcao = opcaoElem.querySelector('.opcao-texto').value.trim();
            const radio = opcaoElem.querySelector('input[type="radio"]');
            
            opcoes.push(textoOpcao);
            
            if (radio.checked) {
                respostaCorreta = opcaoIndex;
            }
        });
        
        if (texto && opcoes.length >= 2) {
            perguntas.push({
                pergunta: texto,
                opcoes: opcoes,
                resposta_correta: respostaCorreta,
                ordem: index
            });
        }
    });
    
    if (perguntas.length === 0) {
        alert('Adicione pelo menos uma pergunta válida.');
        return;
    }
    
    const quizData = {
        modulo_id: moduloId,
        titulo: document.getElementById('quiz_titulo').value.trim(),
        descricao: document.getElementById('quiz_descricao').value.trim(),
        duracao: parseInt(document.getElementById('quiz_duracao').value),
        nota_minima: parseInt(document.getElementById('quiz_nota_minima').value),
        perguntas: perguntas
    };
    
    fetch('ajax/salvar_quiz_modulo.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(quizData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Quiz salvo com sucesso!');
            if (window.fecharModalQuiz) {
                fecharModalQuiz();
            }
        } else {
            alert('Erro ao salvar quiz: ' + data.message);
        }
    })
    .catch(error => {
        alert('Erro ao salvar quiz: ' + error);
    });
}

function excluirQuiz(quizId) {
    if (confirm('Tem certeza que deseja excluir este quiz?')) {
        fetch('ajax/excluir_quiz_modulo.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ quiz_id: quizId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Quiz excluído com sucesso!');
                if (window.fecharModalQuiz) {
                    fecharModalQuiz();
                }
            } else {
                alert('Erro ao excluir quiz: ' + data.message);
            }
        })
        .catch(error => {
            alert('Erro ao excluir quiz: ' + error);
        });
    }
}
</script>