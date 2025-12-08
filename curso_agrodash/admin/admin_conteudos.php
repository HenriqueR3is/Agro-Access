<?php
// admin_conteudos.php - VERSÃO COM HEADER E CSS MODERNO

// 1. Inicie a sessão no TOPO do arquivo
session_start();

// 2. Verifique se o usuário está logado e é admin
if (!isset($_SESSION['usuario_tipo']) || ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev')) {
    header("Location: /curso_agrodash/dashboard");
    exit;
}

// 3. Conecte ao banco de dados
require_once __DIR__ . '/../../config/db/conexao.php';

// 4. Verificar modulo_id
$modulo_id = $_GET['modulo_id'] ?? 0;
$conteudo_id = $_GET['conteudo_id'] ?? 0;

if ($modulo_id <= 0) {
    header("Location: /curso_agrodash/admincursos");
    exit;
}

// 5. Buscar informações do módulo e curso
try {
    $stmt = $pdo->prepare("SELECT m.*, c.titulo as curso_titulo, c.id as curso_id FROM modulos m JOIN cursos c ON m.curso_id = c.id WHERE m.id = ?");
    $stmt->execute([$modulo_id]);
    $modulo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$modulo) {
        header("Location: /curso_agrodash/admincursos");
        exit;
    }
} catch (PDOException $e) {
    $modulo = null;
    $error = "Erro ao carregar módulo: " . $e->getMessage();
}

// 6. Buscar conteúdos do módulo
try {
    $stmt = $pdo->prepare("SELECT * FROM conteudos WHERE modulo_id = ? ORDER BY ordem ASC");
    $stmt->execute([$modulo_id]);
    $conteudos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $conteudos = [];
}

// 7. Buscar dados do conteúdo para edição
$conteudo_edit = null;
if ($conteudo_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM conteudos WHERE id = ?");
        $stmt->execute([$conteudo_id]);
        $conteudo_edit = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verificar se o conteúdo pertence ao módulo
        if ($conteudo_edit && $conteudo_edit['modulo_id'] != $modulo_id) {
            $conteudo_edit = null;
        }
    } catch (PDOException $e) {
        $error = "Erro ao carregar conteúdo: " . $e->getMessage();
    }
}

// 8. Buscar estatísticas para o sidebar
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos");
    $total_cursos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM modulos");
    $total_modulos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $total_usuarios = $stmt->fetchColumn();
    
    // Contar conteúdos deste módulo
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM conteudos WHERE modulo_id = ?");
    $stmt->execute([$modulo_id]);
    $total_conteudos_modulo = $stmt->fetchColumn();
} catch (PDOException $e) {
    $error_stats = "Erro ao carregar estatísticas: " . $e->getMessage();
}

// 9. Processar ações AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'buscar_conteudo') {
        $conteudo_id = $_POST['id'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM conteudos WHERE id = ?");
            $stmt->execute([$conteudo_id]);
            $conteudo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($conteudo) {
                echo json_encode(['success' => true, 'conteudo' => $conteudo]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Conteúdo não encontrado']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao buscar conteúdo: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'salvar_conteudo') {
        $id = $_POST['id'] ?? 0;
        $modulo_id = $_POST['modulo_id'] ?? 0;
        $titulo = $_POST['titulo'] ?? '';
        $descricao = $_POST['descricao'] ?? '';
        $tipo = $_POST['tipo'] ?? 'texto';
        $ordem = $_POST['ordem'] ?? 1;
        $duracao = $_POST['duracao'] ?? '10 min';
        
        // Dados específicos por tipo
        $conteudo_texto = $_POST['conteudo'] ?? '';
        $url_video = $_POST['url_video'] ?? '';
        $perguntas_quiz = $_POST['perguntas_quiz'] ?? '[]';
        
        try {
            // Verificar colunas da tabela
            $stmt = $pdo->prepare("DESCRIBE conteudos");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if ($id > 0) {
                // Atualizar conteúdo existente
                $query = "UPDATE conteudos SET 
                         titulo = ?, descricao = ?, tipo = ?, ordem = ?, duracao = ?,
                         conteudo = ?, url_video = ?, perguntas_quiz = ? 
                         WHERE id = ?";
                
                $stmt = $pdo->prepare($query);
                $params = [
                    $titulo, $descricao, $tipo, $ordem, $duracao,
                    $conteudo_texto, $url_video, $perguntas_quiz, $id
                ];
                
                $stmt->execute($params);
                echo json_encode(['success' => true, 'message' => 'Conteúdo atualizado com sucesso!']);
            } else {
                // Criar novo conteúdo
                $query = "INSERT INTO conteudos (modulo_id, titulo, descricao, tipo, ordem, duracao, conteudo, url_video, perguntas_quiz";
                
                // Adicionar timestamp se a coluna existir
                if (in_array('created_at', $columns)) {
                    $query .= ", created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                } else if (in_array('data_criacao', $columns)) {
                    $query .= ", data_criacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                } else {
                    $query .= ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                }
                
                $stmt = $pdo->prepare($query);
                $params = [
                    $modulo_id, $titulo, $descricao, $tipo, $ordem, $duracao,
                    $conteudo_texto, $url_video, $perguntas_quiz
                ];
                
                $stmt->execute($params);
                $novo_id = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'message' => 'Conteúdo criado com sucesso!', 'id' => $novo_id]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao salvar conteúdo: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'excluir_conteudo') {
        $conteudo_id = $_POST['id'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("DELETE FROM conteudos WHERE id = ?");
            $stmt->execute([$conteudo_id]);
            
            echo json_encode(['success' => true, 'message' => 'Conteúdo excluído com sucesso!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao excluir conteúdo: ' . $e->getMessage()]);
        }
        exit;
    }
}

// 10. Passar variáveis para o header
$GLOBALS['total_cursos'] = $total_cursos ?? 0;
$GLOBALS['total_modulos'] = $total_modulos ?? 0;
$GLOBALS['total_usuarios'] = $total_usuarios ?? 0;

// 11. Função auxiliar para obter valor seguro do conteúdo em edição
function getConteudoEditValue($conteudo_edit, $key, $default = '') {
    if (!$conteudo_edit || !isset($conteudo_edit[$key])) {
        return $default;
    }
    return $conteudo_edit[$key];
}

// 12. Função para obter ícone baseado no tipo
function obterIconeTipo($tipo) {
    $icones = [
        'texto' => 'fas fa-file-alt',
        'video' => 'fas fa-video',
        'imagem' => 'fas fa-image',
        'quiz' => 'fas fa-question-circle',
        'pdf' => 'fas fa-file-pdf',
        'audio' => 'fas fa-music'
    ];
    return $icones[$tipo] ?? 'fas fa-file';
}

// 13. AGORA, depois de toda a lógica PHP, começa o HTML
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Conteúdos - AgroDash Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Variáveis de Cores - Tema Cinza e Verde */
        :root {
            /* Cores principais */
            --primary-50: #f0f9f0;
            --primary-100: #dcf2dc;
            --primary-200: #b8e5b8;
            --primary-300: #94d894;
            --primary-400: #70cb70;
            --primary-500: #4cbe4c; /* Verde principal */
            --primary-600: #3d983d;
            --primary-700: #2e722e;
            --primary-800: #1f4c1f;
            --primary-900: #102610;
            
            /* Cores neutras */
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            
            /* Cores de estado */
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
            
            /* Sombras */
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            
            /* Bordas */
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            
            /* Transições */
            --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-normal: 300ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: 500ms cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Reset e Estilos Base */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-800);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-left var(--transition-normal);
        }

        /* Header Principal */
        .main-header {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-md);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--gray-200);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .menu-toggle {
            background: none;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--gray-600);
            transition: all var(--transition-fast);
        }

        .menu-toggle:hover {
            background: var(--gray-100);
            color: var(--gray-900);
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        /* Header Direito */
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Botões */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all var(--transition-fast);
            border: none;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-500);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-600);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-800);
        }

        .btn-secondary:hover {
            background: var(--gray-300);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #0da271;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-info:hover {
            background: #2563eb;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--gray-900);
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: none;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--gray-600);
            position: relative;
            transition: all var(--transition-fast);
        }

        .btn-icon:hover {
            background: var(--gray-100);
            color: var(--gray-900);
        }

        /* Content Area */
        .content-area {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
        }

        /* Alertas */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-left: 4px solid transparent;
        }

        .alert-error {
            background: #fee;
            color: #dc3545;
            border-left-color: #dc3545;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #155724;
        }

        .alert i {
            font-size: 1.25rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        .breadcrumb a {
            color: var(--primary-600);
            text-decoration: none;
            transition: color var(--transition-fast);
        }

        .breadcrumb a:hover {
            color: var(--primary-700);
            text-decoration: underline;
        }

        .breadcrumb-separator {
            color: var(--gray-400);
        }

        /* Módulo Info Card */
        .modulo-info-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
            border-left: 4px solid var(--primary-500);
        }

        .modulo-info-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .modulo-info-title h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .modulo-info-title p {
            font-size: 0.875rem;
            color: var(--gray-600);
            margin-bottom: 0.5rem;
        }

        .modulo-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.875rem;
            color: var(--gray-500);
            margin-top: 0.5rem;
        }

        .modulo-info-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Abas de Navegação */
        .nav-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--gray-200);
            padding-bottom: 1rem;
        }

        .nav-tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-radius: var(--radius-md);
            color: var(--gray-600);
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition-fast);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-tab:hover {
            background: var(--gray-100);
            color: var(--gray-900);
        }

        .nav-tab.active {
            background: var(--primary-500);
            color: white;
        }

        /* Grid de Conteúdos */
        .conteudos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .conteudo-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: all var(--transition-normal);
            border: 1px solid var(--gray-200);
            position: relative;
        }

        .conteudo-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-200);
        }

        .conteudo-header {
            padding: 1.25rem;
            background: linear-gradient(135deg, var(--gray-50), var(--gray-100));
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .conteudo-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .conteudo-icon.texto {
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            color: white;
        }

        .conteudo-icon.video {
            background: linear-gradient(135deg, var(--danger), #dc3545);
            color: white;
        }

        .conteudo-icon.imagem {
            background: linear-gradient(135deg, var(--info), #3b82f6);
            color: white;
        }

        .conteudo-icon.quiz {
            background: linear-gradient(135deg, var(--warning), #f59e0b);
            color: var(--gray-900);
        }

        .conteudo-info {
            flex: 1;
        }

        .conteudo-titulo {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .conteudo-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        .conteudo-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: var(--primary-500);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .conteudo-body {
            padding: 1.25rem;
        }

        .conteudo-descricao {
            color: var(--gray-600);
            font-size: 0.875rem;
            line-height: 1.5;
            margin-bottom: 1.25rem;
            min-height: 60px;
        }

        .conteudo-acoes {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .conteudo-acoes .btn {
            flex: 1;
            min-width: 100px;
            padding: 0.5rem;
            font-size: 0.75rem;
            justify-content: center;
        }

        /* Estado Vazio */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-500);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: var(--gray-300);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--gray-700);
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 1.5rem;
        }

        /* Formulários */
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--gray-700);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            transition: all var(--transition-fast);
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 3px rgba(76, 190, 76, 0.1);
        }

        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Seletor de Tipo */
        .tipo-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .tipo-option {
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: 1.5rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: all var(--transition-fast);
            background: var(--gray-50);
        }

        .tipo-option:hover {
            border-color: var(--primary-300);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .tipo-option.selected {
            border-color: var(--primary-500);
            background: var(--primary-50);
            box-shadow: 0 0 0 3px rgba(76, 190, 76, 0.1);
        }

        .tipo-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--primary-500);
        }

        .tipo-text {
            font-weight: 500;
            color: var(--gray-700);
        }

        /* Formulários por Tipo */
        .tipo-form {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .tipo-form.active {
            display: block;
        }

        /* Construtor de Quiz */
        .quiz-builder {
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            background: var(--gray-50);
        }

        .quiz-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .perguntas-lista {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .pergunta-item {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            position: relative;
        }

        .pergunta-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .pergunta-numero {
            font-weight: 600;
            color: var(--gray-700);
        }

        .pergunta-acoes {
            display: flex;
            gap: 0.5rem;
        }

        .opcoes-lista {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin: 1rem 0;
        }

        .opcao-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            background: var(--gray-50);
            border-radius: var(--radius-sm);
        }

        .opcao-item input[type="radio"] {
            margin: 0;
            width: 18px;
            height: 18px;
        }

        .opcao-item input[type="text"] {
            flex: 1;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
        }

        /* Preview do Quiz */
        .quiz-preview {
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-top: 1.5rem;
        }

        .preview-pergunta {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .preview-pergunta:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity var(--transition-normal);
        }

        .modal.active {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            color: white;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background var(--transition-fast);
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .modal-body {
            padding: 1.5rem;
        }

        /* Seções de Conteúdo */
        .content-section {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .content-section.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsividade */
        @media (max-width: 1024px) {
            .sidebar {
                width: 240px;
            }
            
            .main-content {
                margin-left: 240px;
            }
            
            .conteudos-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
            
            .tipo-selector {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .main-header {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }
            
            .header-left,
            .header-right {
                width: 100%;
            }
            
            .conteudos-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .nav-tabs {
                flex-wrap: wrap;
            }
            
            .conteudo-acoes {
                flex-wrap: wrap;
            }
            
            .conteudo-acoes .btn {
                flex: auto;
                min-width: auto;
            }
            
            .modal-content {
                width: 95%;
                margin: 1rem;
            }
            
            .modulo-info-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .modulo-info-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .tipo-selector {
                grid-template-columns: 1fr;
            }
        }

        /* Animações */
        .conteudo-card {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>
    <!-- Inclui o sidebar via header.php -->
    <?php 
    // Defina qual aba está ativa
    $active_tab = 'adminconteudos';
    require_once __DIR__ . '/../public/header.php'; 
    ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Header -->
        <header class="main-header">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Gerenciar Conteúdos</h1>
                    <p>Módulo: <?= htmlspecialchars($modulo['titulo'] ?? 'Módulo não encontrado') ?></p>
                </div>
            </div>
            
            <div class="header-right">
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="mostrarSecao('novo-conteudo')">
                        <i class="fas fa-plus"></i>
                        <span>Novo Conteúdo</span>
                    </button>
                    <button class="btn btn-info" onclick="window.location.href='/curso_agrodash/adminmodulos?curso_id=<?= $modulo['curso_id'] ?? 0 ?>'">
                        <i class="fas fa-layer-group"></i>
                        <span>Ver Módulos</span>
                    </button>
                </div>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="/curso_agrodash/admincursos">
                    <i class="fas fa-arrow-left"></i> Cursos
                </a>
                <span class="breadcrumb-separator">/</span>
                <a href="/curso_agrodash/adminmodulos?curso_id=<?= $modulo['curso_id'] ?? 0 ?>">
                    Módulos
                </a>
                <span class="breadcrumb-separator">/</span>
                <span>Conteúdos</span>
            </div>

            <!-- Card de Informações do Módulo -->
            <div class="modulo-info-card">
                <div class="modulo-info-header">
                    <div class="modulo-info-title">
                        <h2><?= htmlspecialchars($modulo['titulo'] ?? 'Módulo não encontrado') ?></h2>
                        <p><?= htmlspecialchars($modulo['descricao'] ?? '') ?></p>
                        <div class="modulo-meta">
                            <span><i class="fas fa-book"></i> Curso: <?= htmlspecialchars($modulo['curso_titulo'] ?? 'Curso') ?></span>
                            <span><i class="fas fa-clock"></i> <?= htmlspecialchars($modulo['duracao'] ?? '30 min') ?></span>
                            <span><i class="fas fa-sort-numeric-down"></i> Ordem: <?= $modulo['ordem'] ?? 1 ?></span>
                        </div>
                    </div>
                    <div class="modulo-info-actions">
                        <button class="btn btn-warning" onclick="window.location.href='/curso_agrodash/adminmodulos?curso_id=<?= $modulo['curso_id'] ?? 0 ?>'">
                            <i class="fas fa-edit"></i> Editar Módulo
                        </button>
                        <button class="btn btn-secondary" onclick="window.location.href='/curso_agrodash/admincursos'">
                            <i class="fas fa-book"></i> Ver Cursos
                        </button>
                    </div>
                </div>
            </div>

            <!-- Abas de Navegação -->
            <div class="nav-tabs">
                <button class="nav-tab active" data-tab="conteudos">
                    <i class="fas fa-file-alt"></i> Conteúdos
                    <span style="background: var(--primary-500); color: white; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem;">
                        <?= count($conteudos) ?>
                    </span>
                </button>
                <button class="nav-tab" data-tab="novo-conteudo">
                    <i class="fas fa-plus"></i> Novo Conteúdo
                </button>
            </div>

            <!-- Seção: Lista de Conteúdos -->
            <div id="conteudos" class="content-section active">
                <div class="conteudos-grid" id="conteudosGrid">
                    <?php foreach ($conteudos as $conteudo): ?>
                    <?php $tipo = $conteudo['tipo'] ?? 'texto'; ?>
                    <div class="conteudo-card" data-conteudo-id="<?= $conteudo['id'] ?>">
                        <div class="conteudo-header">
                            <div class="conteudo-icon <?= $tipo ?>">
                                <i class="<?= obterIconeTipo($tipo) ?>"></i>
                            </div>
                            <div class="conteudo-info">
                                <div class="conteudo-titulo"><?= htmlspecialchars($conteudo['titulo']) ?></div>
                                <div class="conteudo-meta">
                                    <span><i class="fas fa-clock"></i> <?= htmlspecialchars($conteudo['duracao'] ?? '10 min') ?></span>
                                    <span><i class="fas fa-sort-numeric-down"></i> Ordem: <?= $conteudo['ordem'] ?></span>
                                </div>
                            </div>
                            <div class="conteudo-badge">
                                <?= ucfirst($tipo) ?>
                            </div>
                        </div>
                        <div class="conteudo-body">
                            <p class="conteudo-descricao">
                                <?= !empty($conteudo['descricao']) ? htmlspecialchars($conteudo['descricao']) : 'Sem descrição fornecida.' ?>
                            </p>
                            <div class="conteudo-acoes">
                                <button class="btn btn-primary" onclick="abrirModalEditar(<?= $conteudo['id'] ?>)">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <button class="btn btn-danger" onclick="confirmarExclusao(<?= $conteudo['id'] ?>)">
                                    <i class="fas fa-trash"></i> Excluir
                                </button>
                                <button class="btn btn-info" onclick="visualizarConteudo(<?= $conteudo['id'] ?>)">
                                    <i class="fas fa-eye"></i> Visualizar
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($conteudos)): ?>
                    <div class="empty-state">
                        <i class="fas fa-file-alt"></i>
                        <h3>Nenhum conteúdo encontrado</h3>
                        <p>Comece criando o primeiro conteúdo para este módulo!</p>
                        <button class="btn btn-primary" onclick="mostrarSecao('novo-conteudo')" style="margin-top: 1rem;">
                            <i class="fas fa-plus"></i> Criar Primeiro Conteúdo
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Seção: Novo Conteúdo -->
            <div id="novo-conteudo" class="content-section">
                <div class="form-container">
                    <form id="form-novo-conteudo" method="POST">
                        <input type="hidden" name="action" value="salvar_conteudo">
                        <input type="hidden" name="modulo_id" value="<?= $modulo_id ?>">
                        <input type="hidden" name="id" value="0">
                        
                        <div class="form-group">
                            <label class="form-label" for="titulo">Título do Conteúdo *</label>
                            <input type="text" class="form-control" id="titulo" name="titulo" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="descricao">Descrição</label>
                            <textarea class="form-control form-textarea" id="descricao" name="descricao" placeholder="Descreva o conteúdo..."></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Tipo de Conteúdo *</label>
                            <div class="tipo-selector">
                                <div class="tipo-option selected" data-tipo="texto" onclick="selecionarTipo('texto')">
                                    <div class="tipo-icon"><i class="fas fa-file-alt"></i></div>
                                    <div class="tipo-text">Texto</div>
                                </div>
                                <div class="tipo-option" data-tipo="video" onclick="selecionarTipo('video')">
                                    <div class="tipo-icon"><i class="fas fa-video"></i></div>
                                    <div class="tipo-text">Vídeo</div>
                                </div>
                                <div class="tipo-option" data-tipo="imagem" onclick="selecionarTipo('imagem')">
                                    <div class="tipo-icon"><i class="fas fa-image"></i></div>
                                    <div class="tipo-text">Imagem</div>
                                </div>
                                <div class="tipo-option" data-tipo="quiz" onclick="selecionarTipo('quiz')">
                                    <div class="tipo-icon"><i class="fas fa-question-circle"></i></div>
                                    <div class="tipo-text">Quiz</div>
                                </div>
                            </div>
                            <input type="hidden" name="tipo" id="tipo" value="texto" required>
                        </div>

                        <!-- Formulário para Texto -->
                        <div id="form-texto" class="tipo-form active">
                            <div class="form-group">
                                <label class="form-label" for="conteudo">Conteúdo em Texto</label>
                                <textarea class="form-control form-textarea" id="conteudo" name="conteudo" rows="8" placeholder="Digite o conteúdo em texto aqui..."></textarea>
                            </div>
                        </div>

                        <!-- Formulário para Vídeo -->
                        <div id="form-video" class="tipo-form">
                            <div class="form-group">
                                <label class="form-label" for="url_video">URL do Vídeo</label>
                                <input type="url" class="form-control" id="url_video" name="url_video" 
                                       placeholder="https://www.youtube.com/watch?v=...">
                                <small style="color: var(--gray-500); font-size: 0.75rem; display: block; margin-top: 0.25rem;">
                                    Suporta YouTube, Vimeo e outros serviços de vídeo
                                </small>
                            </div>
                        </div>

                        <!-- Formulário para Imagem -->
                        <div id="form-imagem" class="tipo-form">
                            <div class="form-group">
                                <label class="form-label" for="arquivo_imagem">Upload de Imagem</label>
                                <input type="file" class="form-control" id="arquivo_imagem" name="arquivo_imagem" accept="image/*">
                                <small style="color: var(--gray-500); font-size: 0.75rem; display: block; margin-top: 0.25rem;">
                                    Formatos suportados: JPG, PNG, GIF (máx. 5MB)
                                </small>
                            </div>
                        </div>

                        <!-- Formulário para Quiz -->
                        <div id="form-quiz" class="tipo-form">
                            <div class="quiz-builder">
                                <div class="quiz-actions">
                                    <button type="button" class="btn btn-success" onclick="adicionarPergunta()">
                                        <i class="fas fa-plus"></i> Adicionar Pergunta
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="visualizarQuiz()">
                                        <i class="fas fa-eye"></i> Visualizar Quiz
                                    </button>
                                </div>
                                
                                <div id="lista-perguntas" class="perguntas-lista">
                                    <!-- Perguntas serão adicionadas aqui -->
                                </div>
                                
                                <input type="hidden" name="perguntas_quiz" id="perguntas_quiz" value="[]">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="ordem">Ordem</label>
                                <input type="number" class="form-control" id="ordem" name="ordem" 
                                       value="<?= count($conteudos) + 1 ?>" min="1">
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="duracao">Duração Estimada</label>
                                <input type="text" class="form-control" id="duracao" name="duracao" value="10 min">
                            </div>
                        </div>

                        <div style="display: flex; gap: 0.75rem; margin-top: 2rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Salvar Conteúdo
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="mostrarSecao('conteudos')">
                                <i class="fas fa-times"></i> Cancelar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal de Edição -->
    <div id="modalEditar" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Editar Conteúdo</h2>
                <button class="modal-close" onclick="fecharModalEditar()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="form-editar-conteudo" method="POST">
                    <input type="hidden" name="action" value="salvar_conteudo">
                    <input type="hidden" name="modulo_id" value="<?= $modulo_id ?>">
                    <input type="hidden" id="edit_id" name="id" value="0">
                    
                    <div class="form-group">
                        <label class="form-label" for="edit_titulo">Título do Conteúdo *</label>
                        <input type="text" class="form-control" id="edit_titulo" name="titulo" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit_descricao">Descrição</label>
                        <textarea class="form-control form-textarea" id="edit_descricao" name="descricao"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Tipo de Conteúdo *</label>
                        <div class="tipo-selector" id="edit-tipo-selector">
                            <!-- Tipos serão carregados dinamicamente -->
                        </div>
                        <input type="hidden" name="tipo" id="edit_tipo" value="texto" required>
                    </div>

                    <!-- Formulários por tipo serão carregados dinamicamente -->

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="edit_ordem">Ordem</label>
                            <input type="number" class="form-control" id="edit_ordem" name="ordem" min="1" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_duracao">Duração Estimada</label>
                            <input type="text" class="form-control" id="edit_duracao" name="duracao">
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.75rem; margin-top: 2rem;">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Salvar Alterações
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="fecharModalEditar()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para Visualizar Quiz -->
    <div id="modalQuiz" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h2><i class="fas fa-question-circle"></i> Visualizar Quiz</h2>
                <button class="modal-close" onclick="fecharModalQuiz()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modalQuizContent">
                <!-- Conteúdo do quiz será carregado aqui -->
            </div>
        </div>
    </div>

    <script>
        // Sistema de notificação
        function showNotification(message, type = 'info') {
            const existing = document.querySelector('.custom-notification');
            if (existing) existing.remove();
            
            const notification = document.createElement('div');
            notification.className = `custom-notification ${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            `;
            
            document.body.appendChild(notification);
            
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
                color: white;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                display: flex;
                align-items: center;
                gap: 0.75rem;
                box-shadow: 0 10px 25px rgba(0,0,0,0.2);
                z-index: 9999;
                animation: slideIn 0.3s ease;
                max-width: 400px;
            `;
            
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
            `;
            document.head.appendChild(style);
            
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Menu Mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });

        // Navegação entre abas
        document.querySelectorAll('.nav-tab').forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                const targetTab = this.dataset.tab;
                mostrarSecao(targetTab);
            });
        });

        function mostrarSecao(secaoId) {
            document.querySelectorAll('.content-section').forEach(sec => {
                sec.classList.remove('active');
            });
            
            document.getElementById(secaoId).classList.add('active');
            
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelector(`[data-tab="${secaoId}"]`).classList.add('active');
            
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Seleção de tipo de conteúdo
        function selecionarTipo(tipo) {
            document.querySelectorAll('.tipo-option').forEach(el => {
                el.classList.remove('selected');
            });
            document.querySelector(`[data-tipo="${tipo}"]`).classList.add('selected');
            
            document.getElementById('tipo').value = tipo;
            
            document.querySelectorAll('.tipo-form').forEach(el => {
                el.classList.remove('active');
            });
            document.getElementById(`form-${tipo}`).classList.add('active');
            
            if (tipo === 'quiz') {
                setTimeout(() => {
                    if (document.getElementById('lista-perguntas').children.length === 0) {
                        adicionarPergunta();
                    }
                }, 100);
            }
        }

        // Sistema de quiz
        let contadorPerguntas = 0;

        function adicionarPergunta() {
            contadorPerguntas++;
            const perguntaId = `pergunta_${contadorPerguntas}`;
            
            const perguntaHTML = `
                <div class="pergunta-item" id="${perguntaId}">
                    <div class="pergunta-header">
                        <div class="pergunta-numero">Pergunta ${contadorPerguntas}</div>
                        <div class="pergunta-acoes">
                            <button type="button" class="btn btn-icon btn-secondary" onclick="moverPergunta('${perguntaId}', -1)">
                                <i class="fas fa-arrow-up"></i>
                            </button>
                            <button type="button" class="btn btn-icon btn-secondary" onclick="moverPergunta('${perguntaId}', 1)">
                                <i class="fas fa-arrow-down"></i>
                            </button>
                            <button type="button" class="btn btn-icon btn-danger" onclick="removerPergunta('${perguntaId}')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Pergunta:</label>
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
                    <button type="button" class="btn btn-sm btn-success" onclick="adicionarOpcao('${perguntaId}')" style="margin-top: 0.5rem;">
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
                const numero = pergunta.querySelector('.pergunta-numero');
                numero.textContent = `Pergunta ${index + 1}`;
                contadorPerguntas = index + 1;
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
            
            const preview = document.getElementById('modalQuizContent');
            preview.innerHTML = '';
            
            if (perguntas.length === 0) {
                preview.innerHTML = '<p class="text-center text-gray-500">Nenhuma pergunta adicionada ao quiz.</p>';
            } else {
                perguntas.forEach((pergunta, index) => {
                    const perguntaHTML = `
                        <div class="preview-pergunta">
                            <h4 style="color: var(--gray-800); margin-bottom: 1rem;">${index + 1}. ${pergunta.pergunta}</h4>
                            <div class="preview-opcoes">
                                ${pergunta.opcoes.map((opcao, opcaoIndex) => `
                                    <div class="opcao-item" style="${opcaoIndex === pergunta.resposta ? 'background: var(--primary-50); border-color: var(--primary-200);' : ''}">
                                        <input type="radio" ${opcaoIndex === pergunta.resposta ? 'checked' : ''} disabled>
                                        <span style="flex: 1;">${opcao}</span>
                                        ${opcaoIndex === pergunta.resposta ? '<i class="fas fa-check" style="color: var(--success);"></i>' : ''}
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    `;
                    preview.insertAdjacentHTML('beforeend', perguntaHTML);
                });
            }
            
            document.getElementById('modalQuiz').classList.add('active');
        }

        function fecharModalQuiz() {
            document.getElementById('modalQuiz').classList.remove('active');
        }

        // Edição de conteúdo
        function abrirModalEditar(conteudoId) {
            const formData = new FormData();
            formData.append('action', 'buscar_conteudo');
            formData.append('id', conteudoId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erro na rede: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.conteudo) {
                    preencherFormularioEditar(data.conteudo);
                    document.getElementById('modalEditar').classList.add('active');
                } else {
                    showNotification('Erro ao carregar conteúdo: ' + (data.message || 'Conteúdo não encontrado'), 'error');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showNotification('Erro ao carregar conteúdo', 'error');
            });
        }

        function fecharModalEditar() {
            document.getElementById('modalEditar').classList.remove('active');
        }

        function preencherFormularioEditar(conteudo) {
            document.getElementById('edit_id').value = conteudo.id;
            document.getElementById('edit_titulo').value = conteudo.titulo;
            document.getElementById('edit_descricao').value = conteudo.descricao || '';
            document.getElementById('edit_ordem').value = conteudo.ordem;
            document.getElementById('edit_duracao').value = conteudo.duracao || '10 min';
            document.getElementById('edit_tipo').value = conteudo.tipo || 'texto';
            
            // Atualizar seletor de tipo
            const tipoSelector = document.getElementById('edit-tipo-selector');
            const tipos = ['texto', 'video', 'imagem', 'quiz'];
            
            tipoSelector.innerHTML = tipos.map(tipo => `
                <div class="tipo-option ${tipo === (conteudo.tipo || 'texto') ? 'selected' : ''}" 
                     data-tipo="${tipo}" onclick="selecionarTipoEditar('${tipo}', '${conteudo.tipo || 'texto'}')">
                    <div class="tipo-icon"><i class="fas fa-${tipo === 'texto' ? 'file-alt' : tipo === 'video' ? 'video' : tipo === 'imagem' ? 'image' : 'question-circle'}"></i></div>
                    <div class="tipo-text">${tipo.charAt(0).toUpperCase() + tipo.slice(1)}</div>
                </div>
            `).join('');
            
            // Carregar conteúdo específico do tipo
            carregarConteudoTipo(conteudo);
        }

        function selecionarTipoEditar(tipo, tipoAtual) {
            document.querySelectorAll('#edit-tipo-selector .tipo-option').forEach(el => {
                el.classList.remove('selected');
            });
            document.querySelector(`#edit-tipo-selector [data-tipo="${tipo}"]`).classList.add('selected');
            
            document.getElementById('edit_tipo').value = tipo;
            
            // Aqui você precisaria carregar o formulário específico do tipo
            // Isso é um pouco mais complexo e requer mais código
        }

        function carregarConteudoTipo(conteudo) {
            // Esta função precisaria ser implementada para carregar
            // o conteúdo específico do tipo no formulário de edição
            // Dependendo da complexidade, pode ser necessário fazer via AJAX
        }

// Formulário de Novo Conteúdo
document.getElementById('form-novo-conteudo').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    
    // DEBUG: Verificar dados do FormData
    for (let pair of formData.entries()) {
        console.log(pair[0] + ': ' + pair[1]);
    }
    
    fetch('/curso_agrodash/ajax/salvar_conteudo.php', {  // URL completa
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Erro na rede: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        console.log('Resposta do servidor:', data);
        if (data.success) {
            showNotification('Conteúdo criado com sucesso!', 'success');
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showNotification('Erro ao criar conteúdo: ' + (data.message || 'Erro desconhecido'), 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        showNotification('Erro ao conectar com o servidor: ' + error.message, 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

        // Formulário de Edição
        document.getElementById('form-editar-conteudo').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erro na rede: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showNotification('Conteúdo atualizado com sucesso!', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('Erro ao atualizar conteúdo: ' + (data.message || 'Erro desconhecido'), 'error');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showNotification('Erro ao conectar com o servidor', 'error');
            });
        });

        // Função de exclusão
        function confirmarExclusao(conteudoId) {
            if (confirm('Tem certeza que deseja excluir este conteúdo?\n\n⚠️ Esta ação não pode ser desfeita.')) {
                const formData = new FormData();
                formData.append('action', 'excluir_conteudo');
                formData.append('id', conteudoId);
                
                const btn = event.target;
                const originalText = btn.innerHTML;
                
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Conteúdo excluído com sucesso!', 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification('Erro ao excluir conteúdo: ' + (data.message || 'Erro desconhecido'), 'error');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    showNotification('Erro ao excluir conteúdo', 'error');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
            }
        }

        function visualizarConteudo(conteudoId) {
            showNotification('Funcionalidade de visualização em desenvolvimento!', 'info');
        }

        // Fechar sidebar ao clicar fora (mobile)
        document.addEventListener('click', function(e) {
            const sidebar = document.querySelector('.sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 768 && 
                sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && 
                !menuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        });

        // Fechar sidebar ao redimensionar para desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                document.querySelector('.sidebar').classList.remove('active');
            }
        });

        // Fechar modais com ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                fecharModalEditar();
                fecharModalQuiz();
            }
        });

        // Fechar modais ao clicar fora
        document.addEventListener('click', function(e) {
            const modalEditar = document.getElementById('modalEditar');
            if (e.target === modalEditar) {
                fecharModalEditar();
            }
            
            const modalQuiz = document.getElementById('modalQuiz');
            if (e.target === modalQuiz) {
                fecharModalQuiz();
            }
        });

        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            // Se houver parâmetro de edição na URL, abrir modal de edição
            const urlParams = new URLSearchParams(window.location.search);
            const conteudoId = urlParams.get('conteudo_id');
            
            if (conteudoId && conteudoId > 0) {
                abrirModalEditar(conteudoId);
            }
            
            // Se estiver na seção de novo conteúdo e o tipo for quiz, adicionar primeira pergunta
            if (document.getElementById('novo-conteudo').classList.contains('active') && 
                document.getElementById('tipo').value === 'quiz') {
                setTimeout(() => {
                    if (document.getElementById('lista-perguntas').children.length === 0) {
                        adicionarPergunta();
                    }
                }, 500);
            }
        });
    </script>
</body>
</html>