<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    header("Location: /curso_agrodash/dashboard");
    exit;
}

$modulo_id = $_GET['modulo_id'] ?? null;
$conteudo_id = $_GET['conteudo_id'] ?? null;

if (!$modulo_id) {
    header("Location: /curso_agrodash/admincursos");
    exit;
}

// Buscar informações do módulo e curso
try {
    $stmt = $pdo->prepare("SELECT m.*, c.titulo as curso_titulo FROM modulos m JOIN cursos c ON m.curso_id = c.id WHERE m.id = ?");
    $stmt->execute([$modulo_id]);
    $modulo = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Erro ao carregar módulo: " . $e->getMessage();
}

if (!$modulo) {
    header("Location: /curso_agrodash/admincursos");
    exit;
}

// Buscar conteúdos do módulo
try {
    $stmt = $pdo->prepare("SELECT * FROM conteudos WHERE modulo_id = ? ORDER BY ordem");
    $stmt->execute([$modulo_id]);
    $conteudos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $conteudos = [];
}

// Buscar dados do conteúdo para edição
$conteudo_edit = null;
if ($conteudo_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM conteudos WHERE id = ?");
        $stmt->execute([$conteudo_id]);
        $conteudo_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao carregar conteúdo: " . $e->getMessage();
    }
}

// Função auxiliar para obter valor seguro do conteúdo em edição
function getConteudoEditValue($conteudo_edit, $key, $default = '') {
    if (!$conteudo_edit || !isset($conteudo_edit[$key])) {
        return $default;
    }
    return $conteudo_edit[$key];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Conteúdos - AgroDash</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Roboto', sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            min-height: 100vh; 
            padding: 20px; 
        }
        .admin-container { 
            max-width: 1200px; 
            margin: 0 auto; 
            background: white; 
            border-radius: 15px; 
            box-shadow: 0 20px 40px rgba(0,0,0,0.1); 
            overflow: hidden; 
        }
        .admin-header { 
            background: linear-gradient(135deg, #32CD32, #228B22); 
            color: white; 
            padding: 30px; 
            position: relative;
        }
        .admin-header h1 { 
            font-size: 2rem; 
            margin-bottom: 10px; 
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .admin-content { 
            padding: 30px; 
        }
        .modulo-info { 
            background: #f8f9fa; 
            padding: 25px; 
            border-radius: 12px; 
            margin-bottom: 30px; 
            border-left: 5px solid #32CD32;
        }
        .modulo-info h3 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 1.5rem;
        }
        .conteudos-list { 
            display: flex; 
            flex-direction: column; 
            gap: 20px; 
        }
        .conteudo-item { 
            background: white; 
            border: 2px solid #e9ecef; 
            border-radius: 12px; 
            padding: 25px; 
            transition: all 0.3s ease; 
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        .conteudo-item:hover { 
            border-color: #32CD32; 
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .conteudo-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 15px; 
        }
        .conteudo-titulo { 
            font-size: 1.2rem; 
            font-weight: 600; 
            color: #2c3e50; 
            flex: 1; 
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .conteudo-meta { 
            display: flex; 
            gap: 15px; 
            color: #6c757d; 
            font-size: 0.9rem; 
        }
        .conteudo-acoes { 
            display: flex; 
            gap: 10px; 
        }
        .btn { 
            padding: 10px 18px; 
            border: none; 
            border-radius: 8px; 
            font-weight: 500; 
            cursor: pointer; 
            transition: all 0.3s ease; 
            text-decoration: none; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            font-size: 0.9rem; 
        }
        .btn-primary { 
            background: #32CD32; 
            color: white; 
        }
        .btn-primary:hover { 
            background: #228B22; 
            transform: translateY(-2px);
        }
        .btn-secondary { 
            background: #6c757d; 
            color: white; 
        }
        .btn-secondary:hover { 
            background: #545b62; 
            transform: translateY(-2px);
        }
        .btn-success { 
            background: #28a745; 
            color: white; 
        }
        .btn-success:hover { 
            background: #218838; 
            transform: translateY(-2px);
        }
        .btn-warning { 
            background: #ffc107; 
            color: #212529; 
        }
        .btn-warning:hover { 
            background: #e0a800; 
            transform: translateY(-2px);
        }
        .btn-danger { 
            background: #dc3545; 
            color: white; 
        }
        .btn-danger:hover { 
            background: #c82333; 
            transform: translateY(-2px);
        }
        .empty-state { 
            text-align: center; 
            padding: 60px 20px; 
            color: #6c757d; 
        }
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #adb5bd;
        }
        .form-group { 
            margin-bottom: 20px; 
        }
        .form-label { 
            display: block; 
            margin-bottom: 8px; 
            font-weight: 500; 
            color: #2c3e50; 
        }
        .form-control { 
            width: 100%; 
            padding: 12px 15px; 
            border: 2px solid #e9ecef; 
            border-radius: 8px; 
            font-size: 1rem; 
            transition: all 0.3s ease;
        }
        .form-control:focus { 
            outline: none; 
            border-color: #32CD32; 
            box-shadow: 0 0 0 3px rgba(50, 205, 50, 0.2);
        }
        .form-textarea { 
            min-height: 120px; 
            resize: vertical; 
        }
        .tipo-conteudo { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); 
            gap: 15px; 
            margin-bottom: 20px; 
        }
        .tipo-option { 
            border: 2px solid #e9ecef; 
            border-radius: 10px; 
            padding: 20px; 
            text-align: center; 
            cursor: pointer; 
            transition: all 0.3s ease; 
        }
        .tipo-option:hover { 
            border-color: #32CD32; 
            transform: translateY(-3px);
        }
        .tipo-option.selected { 
            border-color: #32CD32; 
            background: #f0fff4; 
            box-shadow: 0 5px 15px rgba(50, 205, 50, 0.2);
        }
        .tipo-icone { 
            font-size: 2.5rem; 
            margin-bottom: 10px; 
            color: #32CD32; 
        }
        .badge-tipo {
            background: #e9ecef;
            color: #495057;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .form-container {
            background: #f8f9fa; 
            padding: 25px; 
            border-radius: 12px; 
            margin-bottom: 30px;
            border-left: 5px solid #32CD32;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .form-title {
            font-size: 1.5rem;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .file-info {
            margin-top: 10px;
            padding: 10px;
            background: #e9ecef;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .json-example {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 10px;
            margin-top: 5px;
            font-size: 0.85rem;
            color: #6c757d;
        }



        /* Estilos para o construtor de quiz */
.quiz-builder {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
    background: #f8f9fa;
}

.quiz-actions {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.lista-perguntas {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.pergunta-item {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 15px;
    position: relative;
}

.pergunta-header {
    display: flex;
    justify-content: between;
    align-items: center;
    margin-bottom: 10px;
}

.pergunta-titulo {
    font-weight: 600;
    color: #2c3e50;
    flex: 1;
}

.pergunta-acoes {
    display: flex;
    gap: 5px;
}

.btn-pergunta {
    padding: 5px 10px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.8rem;
}

.btn-remover {
    background: #dc3545;
    color: white;
}

.btn-mover {
    background: #6c757d;
    color: white;
}

.form-group-pergunta {
    margin-bottom: 10px;
}

.form-group-pergunta label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #495057;
}

.opcoes-lista {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 10px;
}

.opcao-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px;
    background: #f8f9fa;
    border-radius: 4px;
}

.opcao-item input[type="radio"] {
    margin: 0;
}

.opcao-item input[type="text"] {
    flex: 1;
    padding: 6px 8px;
    border: 1px solid #ced4da;
    border-radius: 4px;
}

.btn-adicionar-opcao {
    background: #28a745;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.8rem;
    margin-top: 5px;
}

/* Modal styles */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 800px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #dee2e6;
}

.modal-header h3 {
    margin: 0;
    color: #2c3e50;
}

.btn-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #6c757d;
}

.modal-body {
    padding: 20px;
}

/* Preview do quiz */
.preview-pergunta {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
}

.preview-pergunta h4 {
    color: #2c3e50;
    margin-bottom: 10px;
}

.preview-opcoes {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.preview-opcao {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

.preview-opcao.correta {
    background: #d4edda;
    border-color: #c3e6cb;
}

.preview-opcao label {
    cursor: pointer;
    flex: 1;
    margin: 0;
}


.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    max-width: 400px;
}
.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}
.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1><i class="fas fa-file-alt"></i> Gerenciar Conteúdos</h1>
            <p>Módulo: <?= htmlspecialchars($modulo['titulo']) ?> - Curso: <?= htmlspecialchars($modulo['curso_titulo']) ?></p>
        </div>

        <div class="admin-content">
            <div class="modulo-info">
                <h3><?= htmlspecialchars($modulo['titulo']) ?></h3>
                <p><?= htmlspecialchars($modulo['descricao']) ?></p>
                <div style="display: flex; gap: 15px; margin-top: 15px;">
                    <a href="/curso_agrodash/adminmodulos?curso_id=<?= $modulo['curso_id'] ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Voltar aos Módulos
                    </a>
                    <button class="btn btn-success" onclick="mostrarFormConteudo()">
                        <i class="fas fa-plus"></i> Novo Conteúdo
                    </button>
                </div>
            </div>

            <!-- Formulário de Novo/Editar Conteúdo -->
            <div id="form-conteudo-container" class="form-container" style="<?= $conteudo_edit ? 'display: block;' : 'display: none;' ?>">
                <div class="form-header">
                    <h3 class="form-title">
                        <i class="fas <?= $conteudo_edit ? 'fa-edit' : 'fa-plus' ?>"></i> 
                        <?= $conteudo_edit ? 'Editar Conteúdo' : 'Adicionar Novo Conteúdo' ?>
                    </h3>
                    <button class="btn btn-secondary" onclick="ocultarFormConteudo()">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                </div>
                
                <form id="form-conteudo" action="ajax/salvar_conteudo.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="modulo_id" value="<?= $modulo_id ?>">
                    <input type="hidden" name="conteudo_id" value="<?= $conteudo_edit ? $conteudo_edit['id'] : '' ?>">
                    
                    <div class="form-group">
                        <label class="form-label" for="titulo">Título do Conteúdo *</label>
                        <input type="text" class="form-control" id="titulo" name="titulo" 
                               value="<?= htmlspecialchars(getConteudoEditValue($conteudo_edit, 'titulo')) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="descricao">Descrição</label>
                        <textarea class="form-control form-textarea" id="descricao" name="descricao"><?= 
                            htmlspecialchars(getConteudoEditValue($conteudo_edit, 'descricao')) 
                        ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Tipo de Conteúdo *</label>
                        <div class="tipo-conteudo">
                            <div class="tipo-option <?= (!$conteudo_edit || getConteudoEditValue($conteudo_edit, 'tipo') === 'texto') ? 'selected' : '' ?>" 
                                 data-tipo="texto" onclick="selecionarTipo('texto')">
                                <div class="tipo-icone"><i class="fas fa-file-alt"></i></div>
                                <div>Texto</div>
                            </div>
                            <div class="tipo-option <?= (getConteudoEditValue($conteudo_edit, 'tipo') === 'video') ? 'selected' : '' ?>" 
                                 data-tipo="video" onclick="selecionarTipo('video')">
                                <div class="tipo-icone"><i class="fas fa-video"></i></div>
                                <div>Vídeo</div>
                            </div>
                            <div class="tipo-option <?= (getConteudoEditValue($conteudo_edit, 'tipo') === 'imagem') ? 'selected' : '' ?>" 
                                 data-tipo="imagem" onclick="selecionarTipo('imagem')">
                                <div class="tipo-icone"><i class="fas fa-image"></i></div>
                                <div>Imagem</div>
                            </div>
                            <div class="tipo-option <?= (getConteudoEditValue($conteudo_edit, 'tipo') === 'quiz') ? 'selected' : '' ?>" 
                                 data-tipo="quiz" onclick="selecionarTipo('quiz')">
                                <div class="tipo-icone"><i class="fas fa-question-circle"></i></div>
                                <div>Quiz</div>
                            </div>
                        </div>
                        <input type="hidden" name="tipo" id="tipo" 
                               value="<?= getConteudoEditValue($conteudo_edit, 'tipo', 'texto') ?>" required>
                    </div>

                    <div id="conteudo-texto" class="tipo-conteudo-form" 
                         style="<?= (!$conteudo_edit || getConteudoEditValue($conteudo_edit, 'tipo') === 'texto') ? 'display: block;' : 'display: none;' ?>">
                        <div class="form-group">
                            <label class="form-label" for="conteudo">Conteúdo em Texto</label>
                            <textarea class="form-control form-textarea" id="conteudo" name="conteudo" rows="8"><?= 
                                htmlspecialchars(getConteudoEditValue($conteudo_edit, 'conteudo')) 
                            ?></textarea>
                        </div>
                    </div>

<div id="conteudo-video" class="tipo-conteudo-form" 
     style="<?= (getConteudoEditValue($conteudo_edit, 'tipo') === 'video') ? 'display: block;' : 'display: none;' ?>">
    <div class="form-group">
        <label class="form-label" for="url_video">URL do Vídeo (YouTube, Vimeo, etc.)</label>
        <input type="url" class="form-control" id="url_video" name="url_video" 
               placeholder="https://www.youtube.com/watch?v=... ou https://vimeo.com/..."
               value="<?= htmlspecialchars(getConteudoEditValue($conteudo_edit, 'url_video')) ?>">
        <small class="text-muted">Cole a URL completa do vídeo do YouTube, Vimeo ou outro serviço suportado.</small>
    </div>
    
    <div class="form-group">
        <label class="form-label">OU faça upload de um arquivo de vídeo</label>
        <input type="file" class="form-control" name="arquivo_video" accept="video/mp4,video/webm,video/ogg,video/quicktime">
        <small class="text-muted">Formatos suportados: MP4, WebM, OGG. Tamanho máximo: 50MB</small>
        
        <?php if (getConteudoEditValue($conteudo_edit, 'tipo') === 'video' && getConteudoEditValue($conteudo_edit, 'arquivo')): ?>
            <div class="file-info mt-2">
                <i class="fas fa-file-video"></i> 
                Arquivo atual: <?= htmlspecialchars(getConteudoEditValue($conteudo_edit, 'arquivo')) ?>
                <br>
                <small>
                    <a href="<?= htmlspecialchars(getConteudoEditValue($conteudo_edit, 'arquivo')) ?>" target="_blank" class="text-primary">Visualizar arquivo</a>
                    |
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removerArquivoVideo()">Remover arquivo</button>
                </small>
            </div>
            <input type="hidden" name="arquivo_video_atual" value="<?= htmlspecialchars(getConteudoEditValue($conteudo_edit, 'arquivo')) ?>">
        <?php endif; ?>
    </div>
    
    <div class="form-group">
        <label class="form-label">Pré-visualização do Vídeo</label>
        <div id="video-preview" class="mt-2">
            <?php if (getConteudoEditValue($conteudo_edit, 'tipo') === 'video'): ?>
                <?php if (getConteudoEditValue($conteudo_edit, 'url_video')): ?>
                    <div class="video-preview-container">
                        <p class="text-success">✓ Vídeo por URL configurado</p>
                        <small class="text-muted">URL: <?= htmlspecialchars(getConteudoEditValue($conteudo_edit, 'url_video')) ?></small>
                    </div>
                <?php elseif (getConteudoEditValue($conteudo_edit, 'arquivo')): ?>
                    <div class="video-preview-container">
                        <p class="text-success">✓ Arquivo de vídeo carregado</p>
                        <small class="text-muted">Arquivo: <?= htmlspecialchars(getConteudoEditValue($conteudo_edit, 'arquivo')) ?></small>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-muted">Nenhum vídeo configurado ainda.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

                    <div id="conteudo-imagem" class="tipo-conteudo-form" 
                         style="<?= (getConteudoEditValue($conteudo_edit, 'tipo') === 'imagem') ? 'display: block;' : 'display: none;' ?>">
                        <div class="form-group">
                            <label class="form-label">Upload de Imagem</label>
                            <input type="file" class="form-control" name="arquivo_imagem" accept="image/*">
                            <?php if (getConteudoEditValue($conteudo_edit, 'tipo') === 'imagem' && getConteudoEditValue($conteudo_edit, 'arquivo')): ?>
                                <div class="file-info">
                                    <i class="fas fa-file-image"></i> Arquivo atual: <?= htmlspecialchars(getConteudoEditValue($conteudo_edit, 'arquivo')) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
// Substitua a seção do quiz no admin_conteudos.php por este código:

<div id="conteudo-quiz" class="tipo-conteudo-form" 
     style="<?= (getConteudoEditValue($conteudo_edit, 'tipo') === 'quiz') ? 'display: block;' : 'display: none;' ?>">
    <div class="form-group">
        <label class="form-label">Construtor de Quiz</label>
        <div class="quiz-builder">
            <div class="quiz-actions">
                <button type="button" class="btn btn-success" onclick="adicionarPergunta()">
                    <i class="fas fa-plus"></i> Adicionar Pergunta
                </button>
                <button type="button" class="btn btn-secondary" onclick="visualizarQuiz()">
                    <i class="fas fa-eye"></i> Visualizar Quiz
                </button>
            </div>
            
            <div id="lista-perguntas" class="lista-perguntas">
                <!-- Perguntas serão adicionadas aqui -->
            </div>
            
            <input type="hidden" name="perguntas_quiz" id="perguntas_quiz" 
                   value="<?= htmlspecialchars(getConteudoEditValue($conteudo_edit, 'perguntas_quiz')) ?>">
        </div>
    </div>
</div>

<!-- Modal para visualizar quiz -->
<div id="modal-visualizar-quiz" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Visualizar Quiz</h3>
            <button type="button" class="btn-close" onclick="fecharModalQuiz()">&times;</button>
        </div>
        <div class="modal-body" id="preview-quiz">
            <!-- Preview do quiz será gerado aqui -->
        </div>
    </div>
</div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label class="form-label" for="ordem">Ordem</label>
                            <input type="number" class="form-control" id="ordem" name="ordem" 
                                   value="<?= getConteudoEditValue($conteudo_edit, 'ordem', count($conteudos) + 1) ?>" min="1">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="duracao">Duração Estimada</label>
                            <input type="text" class="form-control" id="duracao" name="duracao" 
                                   value="<?= htmlspecialchars(getConteudoEditValue($conteudo_edit, 'duracao', '10 min')) ?>">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?= $conteudo_edit ? 'Atualizar' : 'Salvar' ?> Conteúdo
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="ocultarFormConteudo()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>

            <!-- Lista de Conteúdos -->
            <div class="conteudos-list">
                <?php if (!empty($conteudos)): ?>
                    <?php foreach ($conteudos as $conteudo): ?>
                    <div class="conteudo-item">
                        <div class="conteudo-header">
                            <div class="conteudo-titulo">
                                <i class="<?= obterIconeTipo($conteudo['tipo']) ?>"></i>
                                <?= htmlspecialchars($conteudo['titulo']) ?>
                            </div>
                            <div class="conteudo-meta">
                                <span><i class="fas fa-clock"></i> <?= $conteudo['duracao'] ?? '10 min' ?></span>
                                <span><i class="fas fa-sort-numeric-down"></i> Ordem: <?= $conteudo['ordem'] ?></span>
                                <span class="badge-tipo"><?= ucfirst($conteudo['tipo']) ?></span>
                            </div>
                            <div class="conteudo-acoes">
                                <a href="?modulo_id=<?= $modulo_id ?>&conteudo_id=<?= $conteudo['id'] ?>" class="btn btn-warning">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                <button class="btn btn-danger" onclick="excluirConteudo(<?= $conteudo['id'] ?>)">
                                    <i class="fas fa-trash"></i> Excluir
                                </button>
                                <button class="btn btn-primary" onclick="visualizarConteudo(<?= $conteudo['id'] ?>)">
                                    <i class="fas fa-eye"></i> Visualizar
                                </button>
                            </div>
                        </div>
                        <?php if (!empty($conteudo['descricao'])): ?>
                            <p style="color: #6c757d; font-size: 0.95rem; line-height: 1.5;"><?= htmlspecialchars($conteudo['descricao']) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-file-alt"></i>
                        <h3>Nenhum conteúdo encontrado</h3>
                        <p>Comece criando o primeiro conteúdo deste módulo!</p>
                        <button class="btn btn-success" onclick="mostrarFormConteudo()">
                            <i class="fas fa-plus"></i> Criar Primeiro Conteúdo
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>



// Adicione este JavaScript ao admin_conteudos.php

let contadorPerguntas = 0;

function adicionarPergunta() {
    contadorPerguntas++;
    const perguntaId = `pergunta_${contadorPerguntas}`;
    
    const perguntaHTML = `
        <div class="pergunta-item" id="${perguntaId}">
            <div class="pergunta-header">
                <div class="pergunta-titulo">Pergunta ${contadorPerguntas}</div>
                <div class="pergunta-acoes">
                    <button type="button" class="btn-pergunta btn-mover" onclick="moverPergunta('${perguntaId}', -1)">↑</button>
                    <button type="button" class="btn-pergunta btn-mover" onclick="moverPergunta('${perguntaId}', 1)">↓</button>
                    <button type="button" class="btn-pergunta btn-remover" onclick="removerPergunta('${perguntaId}')">×</button>
                </div>
            </div>
            <div class="form-group-pergunta">
                <label>Pergunta:</label>
                <input type="text" class="form-control pergunta-texto" placeholder="Digite a pergunta..." 
                       onchange="atualizarQuizJSON()">
            </div>
            <div class="opcoes-lista" id="opcoes_${perguntaId}">
                <div class="opcao-item">
                    <input type="radio" name="correta_${perguntaId}" value="0" checked onchange="atualizarQuizJSON()">
                    <input type="text" class="opcao-texto" placeholder="Opção 1" onchange="atualizarQuizJSON()">
                </div>
                <div class="opcao-item">
                    <input type="radio" name="correta_${perguntaId}" value="1" onchange="atualizarQuizJSON()">
                    <input type="text" class="opcao-texto" placeholder="Opção 2" onchange="atualizarQuizJSON()">
                </div>
            </div>
            <button type="button" class="btn-adicionar-opcao" onclick="adicionarOpcao('${perguntaId}')">
                <i class="fas fa-plus"></i> Adicionar Opção
            </button>
        </div>
    `;
    
    document.getElementById('lista-perguntas').insertAdjacentHTML('beforeend', perguntaHTML);
    atualizarQuizJSON();
}

function adicionarOpcao(perguntaId) {
    const opcoesLista = document.getElementById(`opcoes_${perguntaId}`);
    const totalOpcoes = opcoesLista.children.length;
    
    const opcaoHTML = `
        <div class="opcao-item">
            <input type="radio" name="correta_${perguntaId}" value="${totalOpcoes}" onchange="atualizarQuizJSON()">
            <input type="text" class="opcao-texto" placeholder="Opção ${totalOpcoes + 1}" onchange="atualizarQuizJSON()">
        </div>
    `;
    
    opcoesLista.insertAdjacentHTML('beforeend', opcaoHTML);
    atualizarQuizJSON();
}

function removerPergunta(perguntaId) {
    const pergunta = document.getElementById(perguntaId);
    if (pergunta && confirm('Tem certeza que deseja remover esta pergunta?')) {
        pergunta.remove();
        reordenarPerguntas();
        atualizarQuizJSON();
    }
}

function moverPergunta(perguntaId, direcao) {
    const pergunta = document.getElementById(perguntaId);
    const lista = document.getElementById('lista-perguntas');
    const perguntas = Array.from(lista.children);
    const index = perguntas.indexOf(pergunta);
    
    const novoIndex = index + direcao;
    if (novoIndex >= 0 && novoIndex < perguntas.length) {
        if (direcao === -1) {
            lista.insertBefore(pergunta, perguntas[novoIndex]);
        } else {
            lista.insertBefore(pergunta, perguntas[novoIndex + 1] || null);
        }
        reordenarPerguntas();
        atualizarQuizJSON();
    }
}

function reordenarPerguntas() {
    const perguntas = document.querySelectorAll('.pergunta-item');
    perguntas.forEach((pergunta, index) => {
        const titulo = pergunta.querySelector('.pergunta-titulo');
        titulo.textContent = `Pergunta ${index + 1}`;
    });
}

function atualizarQuizJSON() {
    const perguntas = [];
    const perguntaItems = document.querySelectorAll('.pergunta-item');
    
    perguntaItems.forEach((perguntaItem, index) => {
        const perguntaTexto = perguntaItem.querySelector('.pergunta-texto').value;
        const opcoesItems = perguntaItem.querySelectorAll('.opcao-item');
        
        const opcoes = [];
        let respostaCorreta = 0;
        
        opcoesItems.forEach((opcaoItem, opcaoIndex) => {
            const opcaoTexto = opcaoItem.querySelector('.opcao-texto').value;
            const radio = opcaoItem.querySelector('input[type="radio"]');
            
            opcoes.push(opcaoTexto);
            
            if (radio.checked) {
                respostaCorreta = opcaoIndex;
            }
        });
        
        if (perguntaTexto.trim() !== '' && opcoes.length >= 2) {
            perguntas.push({
                pergunta: perguntaTexto,
                opcoes: opcoes,
                resposta: respostaCorreta
            });
        }
    });
    
    document.getElementById('perguntas_quiz').value = JSON.stringify(perguntas, null, 2);
}

function visualizarQuiz() {
    const quizJSON = document.getElementById('perguntas_quiz').value;
    let perguntas = [];
    
    try {
        perguntas = JSON.parse(quizJSON);
    } catch (e) {
        alert('Erro ao carregar quiz: ' + e.message);
        return;
    }
    
    const preview = document.getElementById('preview-quiz');
    preview.innerHTML = '';
    
    if (perguntas.length === 0) {
        preview.innerHTML = '<p>Nenhuma pergunta adicionada ao quiz.</p>';
    } else {
        perguntas.forEach((pergunta, index) => {
            const perguntaHTML = `
                <div class="preview-pergunta">
                    <h4>${index + 1}. ${pergunta.pergunta}</h4>
                    <div class="preview-opcoes">
                        ${pergunta.opcoes.map((opcao, opcaoIndex) => `
                            <div class="preview-opcao ${opcaoIndex === pergunta.resposta ? 'correta' : ''}">
                                <input type="radio" name="preview_${index}" ${opcaoIndex === pergunta.resposta ? 'checked' : ''} disabled>
                                <label>${opcao}</label>
                                ${opcaoIndex === pergunta.resposta ? '<i class="fas fa-check" style="color: #28a745;"></i>' : ''}
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
            preview.insertAdjacentHTML('beforeend', perguntaHTML);
        });
    }
    
    document.getElementById('modal-visualizar-quiz').style.display = 'flex';
}

function fecharModalQuiz() {
    document.getElementById('modal-visualizar-quiz').style.display = 'none';
}

// Carregar perguntas existentes ao editar
function carregarPerguntasExistentes() {
    const quizJSON = document.getElementById('perguntas_quiz').value;
    if (!quizJSON) return;
    
    try {
        const perguntas = JSON.parse(quizJSON);
        contadorPerguntas = 0;
        document.getElementById('lista-perguntas').innerHTML = '';
        
        perguntas.forEach(pergunta => {
            contadorPerguntas++;
            const perguntaId = `pergunta_${contadorPerguntas}`;
            
            const perguntaHTML = `
                <div class="pergunta-item" id="${perguntaId}">
                    <div class="pergunta-header">
                        <div class="pergunta-titulo">Pergunta ${contadorPerguntas}</div>
                        <div class="pergunta-acoes">
                            <button type="button" class="btn-pergunta btn-mover" onclick="moverPergunta('${perguntaId}', -1)">↑</button>
                            <button type="button" class="btn-pergunta btn-mover" onclick="moverPergunta('${perguntaId}', 1)">↓</button>
                            <button type="button" class="btn-pergunta btn-remover" onclick="removerPergunta('${perguntaId}')">×</button>
                        </div>
                    </div>
                    <div class="form-group-pergunta">
                        <label>Pergunta:</label>
                        <input type="text" class="form-control pergunta-texto" value="${pergunta.pergunta.replace(/"/g, '&quot;')}" 
                               onchange="atualizarQuizJSON()">
                    </div>
                    <div class="opcoes-lista" id="opcoes_${perguntaId}">
                        ${pergunta.opcoes.map((opcao, index) => `
                            <div class="opcao-item">
                                <input type="radio" name="correta_${perguntaId}" value="${index}" 
                                       ${index === pergunta.resposta ? 'checked' : ''} onchange="atualizarQuizJSON()">
                                <input type="text" class="opcao-texto" value="${opcao.replace(/"/g, '&quot;')}" 
                                       onchange="atualizarQuizJSON()">
                            </div>
                        `).join('')}
                    </div>
                    <button type="button" class="btn-adicionar-opcao" onclick="adicionarOpcao('${perguntaId}')">
                        <i class="fas fa-plus"></i> Adicionar Opção
                    </button>
                </div>
            `;
            
            document.getElementById('lista-perguntas').insertAdjacentHTML('beforeend', perguntaHTML);
        });
    } catch (e) {
        console.error('Erro ao carregar perguntas existentes:', e);
    }
}


// Inicializar se já estiver no modo quiz
<?php if (getConteudoEditValue($conteudo_edit, 'tipo') === 'quiz'): ?>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        carregarPerguntasExistentes();
    }, 500);
});
<?php endif; ?>




        function obterIconeTipo(tipo) {
            const icones = {
                'texto': 'fas fa-file-alt',
                'video': 'fas fa-video',
                'imagem': 'fas fa-image',
                'quiz': 'fas fa-question-circle'
            };
            return icones[tipo] || 'fas fa-file';
        }

        function mostrarFormConteudo() {
            document.getElementById('form-conteudo-container').style.display = 'block';
            // Scroll para o formulário
            document.getElementById('form-conteudo-container').scrollIntoView({ behavior: 'smooth' });
        }

        function ocultarFormConteudo() {
            // Se estiver editando, redireciona para a página sem parâmetro de edição
            if (window.location.search.includes('conteudo_id')) {
                window.location.href = '?modulo_id=<?= $modulo_id ?>';
            } else {
                document.getElementById('form-conteudo-container').style.display = 'none';
                document.getElementById('form-conteudo').reset();
                document.querySelectorAll('.tipo-conteudo-form').forEach(el => el.style.display = 'none');
                document.querySelectorAll('.tipo-option').forEach(el => el.classList.remove('selected'));
                // Seleciona o tipo padrão
                selecionarTipo('texto');
            }
        }

        function selecionarTipo(tipo) {
            // Atualizar seleção visual
            document.querySelectorAll('.tipo-option').forEach(el => {
                el.classList.remove('selected');
            });
            document.querySelector(`[data-tipo="${tipo}"]`).classList.add('selected');
            
            // Atualizar campo hidden
            document.getElementById('tipo').value = tipo;
            
            // Mostrar formulário específico
            document.querySelectorAll('.tipo-conteudo-form').forEach(el => {
                el.style.display = 'none';
            });
            document.getElementById(`conteudo-${tipo}`).style.display = 'block';

    if (tipo === 'quiz') {
        setTimeout(() => {
            carregarPerguntasExistentes();
        }, 100);
    }


        }

function excluirConteudo(conteudoId) {
    if (confirm('Tem certeza que deseja excluir este conteúdo? Esta ação não pode ser desfeita.')) {
        // Mostrar loading
        const btn = event.target;
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';
        btn.disabled = true;

        fetch(`ajax/excluir_conteudo.php?id=${conteudoId}&modulo_id=<?= $modulo_id ?>`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mostrar mensagem de sucesso temporária
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-success';
                    alertDiv.innerHTML = `<i class="fas fa-check-circle"></i> ${data.message}`;
                    alertDiv.style.position = 'fixed';
                    alertDiv.style.top = '20px';
                    alertDiv.style.right = '20px';
                    alertDiv.style.zIndex = '1000';
                    document.body.appendChild(alertDiv);

                    // Recarregar a página após um breve delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    alert('Erro ao excluir conteúdo: ' + data.message);
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }
            })
            .catch(error => {
                alert('Erro ao excluir conteúdo: ' + error);
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            });
    }
}
        function visualizarConteudo(conteudoId) {
            window.open(`visualizar_conteudo.php?id=${conteudoId}`, '_blank');
        }

 // Submissão do formulário com feedback visual
document.getElementById('form-conteudo').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    // Mostrar loading
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    submitBtn.disabled = true;
    
    // Limpar alertas anteriores
    const alertasAnteriores = this.querySelectorAll('.alert');
    alertasAnteriores.forEach(alerta => alerta.remove());
    
    fetch(this.action, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Verificar se a resposta é JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Resposta do servidor não é JSON');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Mostrar mensagem de sucesso
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success';
            alertDiv.innerHTML = `<i class="fas fa-check-circle"></i> Conteúdo ${<?= $conteudo_edit ? "'atualizado'" : "'salvo'" ?>} com sucesso!`;
            
            document.getElementById('form-conteudo').prepend(alertDiv);
            
            // Recarregar a página após um breve delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            throw new Error(data.message || 'Erro desconhecido');
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        
        // Mostrar mensagem de erro
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger';
        alertDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Erro ao salvar conteúdo: ${error.message}`;
        
        document.getElementById('form-conteudo').prepend(alertDiv);
        
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        
        // Scroll para o topo para ver o erro
        alertDiv.scrollIntoView({ behavior: 'smooth' });
    });
});



// Função para remover arquivo de vídeo
function removerArquivoVideo() {
    if (confirm('Tem certeza que deseja remover o arquivo de vídeo atual?')) {
        const fileInfo = document.querySelector('.file-info');
        if (fileInfo) {
            fileInfo.remove();
        }
        // Adicionar um campo hidden para indicar remoção
        const form = document.getElementById('form-conteudo');
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'remover_arquivo_video';
        hiddenInput.value = '1';
        form.appendChild(hiddenInput);
    }
}

// Preview da URL do vídeo em tempo real
document.getElementById('url_video')?.addEventListener('input', function() {
    const preview = document.getElementById('video-preview');
    const url = this.value.trim();
    
    if (url) {
        preview.innerHTML = `
            <div class="video-preview-container">
                <p class="text-info">✓ URL do vídeo detectada</p>
                <small class="text-muted">URL: ${url}</small>
            </div>
        `;
    } else {
        preview.innerHTML = '<p class="text-muted">Nenhum vídeo configurado ainda.</p>';
    }
});

// Preview do arquivo de vídeo selecionado
document.querySelector('input[name="arquivo_video"]')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('video-preview');
    
    if (file) {
        const fileSize = (file.size / (1024 * 1024)).toFixed(2);
        preview.innerHTML = `
            <div class="video-preview-container">
                <p class="text-success">✓ Arquivo selecionado</p>
                <small class="text-muted">
                    Nome: ${file.name}<br>
                    Tamanho: ${fileSize} MB<br>
                    Tipo: ${file.type}
                </small>
            </div>
        `;
    }
});


        // Se estiver editando, garantir que o tipo correto está selecionado
        <?php if ($conteudo_edit): ?>
        document.addEventListener('DOMContentLoaded', function() {
            selecionarTipo('<?= getConteudoEditValue($conteudo_edit, 'tipo', 'texto') ?>');
        });
        <?php endif; ?>
    </script>
</body>
</html>

<?php
function obterIconeTipo($tipo) {
    $icones = [
        'texto' => 'fas fa-file-alt',
        'video' => 'fas fa-video',
        'imagem' => 'fas fa-image',
        'quiz' => 'fas fa-question-circle'
    ];
    return $icones[$tipo] ?? 'fas fa-file';
}
?>