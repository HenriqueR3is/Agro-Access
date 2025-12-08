<?php
// admin_modulos.php - VERSÃO COMPLETA COM HEADER CORRIGIDA

// 1. Inicie a sessão no TOPO do arquivo
session_start();

// 2. Verifique se o usuário está logado e é admin
if (!isset($_SESSION['usuario_tipo']) || ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev')) {
    header("Location: /curso_agrodash/dashboard");
    exit;
}

// 3. Conecte ao banco de dados
require_once __DIR__ . '/../../config/db/conexao.php';

// 4. Verificar curso_id
$curso_id = $_GET['curso_id'] ?? 0;

if ($curso_id <= 0) {
    header("Location: /curso_agrodash/admincursos");
    exit;
}

// 5. Buscar informações do curso
try {
    $stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
    $stmt->execute([$curso_id]);
    $curso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$curso) {
        header("Location: /curso_agrodash/admincursos");
        exit;
    }
} catch (PDOException $e) {
    $curso = null;
    $error = "Erro ao carregar curso: " . $e->getMessage();
}

// 6. Buscar todos os módulos do curso
try {
    $stmt = $pdo->prepare("SELECT * FROM modulos WHERE curso_id = ? ORDER BY ordem ASC");
    $stmt->execute([$curso_id]);
    $modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $modulos = [];
    $error = "Erro ao carregar módulos: " . $e->getMessage();
}

// 7. Buscar prova final do curso (se a tabela existir)
try {
    // Verificar se a tabela existe
    $tableExists = $pdo->query("SHOW TABLES LIKE 'provas_finais'")->rowCount() > 0;
    
    if ($tableExists) {
        $stmt = $pdo->prepare("SELECT * FROM provas_finais WHERE curso_id = ?");
        $stmt->execute([$curso_id]);
        $prova_final = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $prova_final = null;
    }
} catch (PDOException $e) {
    $prova_final = null;
}

// 8. Buscar estatísticas para o sidebar
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos");
    $total_cursos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM modulos");
    $total_modulos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $total_usuarios = $stmt->fetchColumn();
} catch (PDOException $e) {
    $error_stats = "Erro ao carregar estatísticas: " . $e->getMessage();
}

// 9. Processar ações AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Para AJAX, não envie HTML, apenas JSON
    ob_clean(); // Limpa qualquer saída anterior
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'buscar_modulo') {
        $modulo_id = $_POST['id'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM modulos WHERE id = ?");
            $stmt->execute([$modulo_id]);
            $modulo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($modulo) {
                echo json_encode(['success' => true, 'modulo' => $modulo]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Módulo não encontrado']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao buscar módulo: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'salvar_modulo') {
        $id = $_POST['id'] ?? 0;
        $curso_id = $_POST['curso_id'] ?? 0;
        $titulo = $_POST['titulo'] ?? '';
        $descricao = $_POST['descricao'] ?? '';
        $ordem = $_POST['ordem'] ?? 1;
        $duracao = $_POST['duracao'] ?? '30 min';
        $icone = $_POST['icone'] ?? 'fas fa-book';
        
        try {
            if ($id > 0) {
                // Atualizar módulo existente
                $stmt = $pdo->prepare("UPDATE modulos SET titulo = ?, descricao = ?, ordem = ?, duracao = ?, icone = ? WHERE id = ?");
                $stmt->execute([$titulo, $descricao, $ordem, $duracao, $icone, $id]);
                echo json_encode(['success' => true, 'message' => 'Módulo atualizado com sucesso!']);
            } else {
                // Criar novo módulo - USANDO COLUNAS CORRETAS
                // Primeiro, vamos verificar a estrutura da tabela
                $stmt = $pdo->prepare("DESCRIBE modulos");
                $stmt->execute();
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Verificar se a coluna created_at existe
                if (in_array('created_at', $columns)) {
                    // Usar created_at se existir
                    $stmt = $pdo->prepare("INSERT INTO modulos (curso_id, titulo, descricao, ordem, duracao, icone, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                } else if (in_array('data_criacao', $columns)) {
                    // Usar data_criacao se existir
                    $stmt = $pdo->prepare("INSERT INTO modulos (curso_id, titulo, descricao, ordem, duracao, icone, data_criacao) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                } else {
                    // Se nenhuma das colunas existir, não incluir timestamp
                    $stmt = $pdo->prepare("INSERT INTO modulos (curso_id, titulo, descricao, ordem, duracao, icone) VALUES (?, ?, ?, ?, ?, ?)");
                }
                
                if (isset($stmt)) {
                    $params = [$curso_id, $titulo, $descricao, $ordem, $duracao, $icone];
                    if ((in_array('created_at', $columns) || in_array('data_criacao', $columns)) && !in_array('data_criacao', $columns)) {
                        // Já incluímos NOW() na query, não precisa de parâmetro extra
                    }
                    $stmt->execute($params);
                    $novo_id = $pdo->lastInsertId();
                    echo json_encode(['success' => true, 'message' => 'Módulo criado com sucesso!', 'id' => $novo_id]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erro ao preparar query de inserção']);
                }
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao salvar módulo: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'excluir_modulo') {
        $modulo_id = $_POST['id'] ?? 0;
        
        try {
            // Verificar se há conteúdos associados (se a tabela existir)
            $conteudos_count = 0;
            $tableExists = $pdo->query("SHOW TABLES LIKE 'conteudos'")->rowCount() > 0;
            
            if ($tableExists) {
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM conteudos WHERE modulo_id = ?");
                $stmt->execute([$modulo_id]);
                $conteudos_count = $stmt->fetchColumn();
            }
            
            if ($conteudos_count > 0) {
                echo json_encode(['success' => false, 'message' => 'Não é possível excluir. Existem conteúdos associados a este módulo.']);
                exit;
            }
            
            // Excluir módulo
            $stmt = $pdo->prepare("DELETE FROM modulos WHERE id = ?");
            $stmt->execute([$modulo_id]);
            
            echo json_encode(['success' => true, 'message' => 'Módulo excluído com sucesso!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao excluir módulo: ' . $e->getMessage()]);
        }
        exit;
    }
}

// 10. Passar variáveis para o header
$GLOBALS['total_cursos'] = $total_cursos ?? 0;
$GLOBALS['total_modulos'] = $total_modulos ?? 0;
$GLOBALS['total_usuarios'] = $total_usuarios ?? 0;

// 11. AGORA, depois de toda a lógica PHP, começa o HTML
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Módulos - AgroDash Admin</title>
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

        .btn-purple {
            background: var(--purple);
            color: white;
        }

        .btn-purple:hover {
            background: #7c3aed;
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

        /* Curso Info Card */
        .curso-info-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
            border-left: 4px solid var(--primary-500);
        }

        .curso-info-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .curso-info-title h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .curso-info-title p {
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        .curso-info-actions {
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

        /* Grid de Módulos */
        .modulos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .modulo-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: all var(--transition-normal);
            border: 1px solid var(--gray-200);
            position: relative;
        }

        .modulo-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-200);
        }

        .modulo-header {
            padding: 1.25rem;
            background: linear-gradient(135deg, var(--gray-50), var(--gray-100));
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .modulo-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .modulo-info {
            flex: 1;
            padding-top: 21px;
        }

        .modulo-titulo {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .modulo-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        .modulo-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: var(--primary-500);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .modulo-body {
            padding: 1.25rem;
        }

        .modulo-descricao {
            color: var(--gray-600);
            font-size: 0.875rem;
            line-height: 1.5;
            margin-bottom: 1.25rem;
            min-height: 60px;
        }

        .modulo-acoes {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .modulo-acoes .btn {
            flex: 1;
            min-width: 120px;
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

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }

        /* Modal de Edição */
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
            max-width: 600px;
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

        /* Seção: Prova Final */
        .prova-final-card {
            background: linear-gradient(135deg, var(--info), var(--purple));
            color: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .prova-final-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .prova-final-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .prova-stat {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: var(--radius-md);
            text-align: center;
        }

        .prova-stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .prova-stat-label {
            font-size: 0.875rem;
            opacity: 0.9;
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
            
            .modulos-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
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
            
            .modulos-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .nav-tabs {
                flex-wrap: wrap;
            }
            
            .modulo-acoes {
                flex-wrap: wrap;
            }
            
            .modulo-acoes .btn {
                flex: auto;
                min-width: auto;
            }
            
            .modal-content {
                width: 95%;
                margin: 1rem;
            }
            
            .curso-info-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .curso-info-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }

        /* Animações */
        .modulo-card {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>
    <!-- Inclui o sidebar via header.php -->
    <?php 
    // Defina qual aba está ativa
    $active_tab = 'admincursos'; // Mantém ativo o "Gerenciar Cursos" no menu
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
                    <h1>Gerenciar Módulos</h1>
                    <p>Curso: <?= htmlspecialchars($curso['titulo'] ?? 'Curso não encontrado') ?></p>
                </div>
            </div>
            
            <div class="header-right">
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="mostrarSecao('novo-modulo')">
                        <i class="fas fa-plus"></i>
                        <span>Novo Módulo</span>
                    </button>
                    <button class="btn btn-info" onclick="window.location.href='/curso_agrodash/adminprovafinal?curso_id=<?= $curso_id ?>'">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Prova Final</span>
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
                    <i class="fas fa-arrow-left"></i> Voltar para Cursos
                </a>
                <span class="breadcrumb-separator">/</span>
                <span>Módulos de: <?= htmlspecialchars($curso['titulo'] ?? 'Curso') ?></span>
            </div>

            <!-- Card de Informações do Curso -->
            <div class="curso-info-card">
                <div class="curso-info-header">
                    <div class="curso-info-title">
                        <h2><?= htmlspecialchars($curso['titulo'] ?? 'Curso não encontrado') ?></h2>
                        <p><?= htmlspecialchars($curso['descricao'] ?? '') ?></p>
                        <div style="display: flex; gap: 1rem; margin-top: 0.5rem; font-size: 0.875rem; color: var(--gray-600);">
                            <span><i class="fas fa-clock"></i> <?= htmlspecialchars($curso['duracao_estimada'] ?? '2 horas') ?></span>
                            <span><i class="fas fa-signal"></i> <?= htmlspecialchars($curso['nivel'] ?? 'Iniciante') ?></span>
                            <span><i class="fas fa-users"></i> Público: <?= htmlspecialchars($curso['publico_alvo'] ?? 'todos') ?></span>
                        </div>
                    </div>
                    <div class="curso-info-actions">
                        <button class="btn btn-warning" onclick="window.location.href='/curso_agrodash/admincursosedit?id=<?= $curso_id ?>'">
                            <i class="fas fa-edit"></i> Editar Curso
                        </button>
                        <button class="btn btn-secondary" onclick="window.location.href='/curso_agrodash/admincursos'">
                            <i class="fas fa-book"></i> Ver Cursos
                        </button>
                    </div>
                </div>
            </div>

            <!-- Abas de Navegação -->
            <div class="nav-tabs">
                <button class="nav-tab active" data-tab="modulos">
                    <i class="fas fa-layer-group"></i> Módulos
                    <span class="nav-badge" style="background: var(--primary-500); color: white; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem;">
                        <?= count($modulos) ?>
                    </span>
                </button>
                <button class="nav-tab" data-tab="novo-modulo">
                    <i class="fas fa-plus"></i> Novo Módulo
                </button>
            </div>

            <!-- Seção: Lista de Módulos -->
            <div id="modulos" class="content-section active">
                <div class="modulos-grid" id="modulosGrid">
                    <?php foreach ($modulos as $modulo): ?>
                    <div class="modulo-card" data-modulo-id="<?= $modulo['id'] ?>">
                        <div class="modulo-header">
                            <div class="modulo-icon">
                                <i class="<?= htmlspecialchars($modulo['icone'] ?? 'fas fa-book') ?>"></i>
                            </div>
                            <div class="modulo-info">
                                <div class="modulo-titulo"><?= htmlspecialchars($modulo['titulo']) ?></div>
                                <div class="modulo-meta">
                                    <span><i class="fas fa-clock"></i> <?= htmlspecialchars($modulo['duracao'] ?? '30 min') ?></span>
                                    <span><i class="fas fa-sort-numeric-down"></i> Ordem: <?= $modulo['ordem'] ?></span>
                                </div>
                            </div>
                            <div class="modulo-badge">
                                Módulo <?= $modulo['ordem'] ?>
                            </div>
                        </div>
                        <div class="modulo-body">
                            <p class="modulo-descricao">
                                <?= !empty($modulo['descricao']) ? htmlspecialchars($modulo['descricao']) : 'Sem descrição fornecida.' ?>
                            </p>
                            <div class="modulo-acoes">
                                <a href="/curso_agrodash/adminconteudos?modulo_id=<?= $modulo['id'] ?>&curso_id=<?= $curso_id ?>" class="btn btn-primary">
                                    <i class="fas fa-file-alt"></i> Conteúdos
                                </a>
                                <button class="btn btn-info" onclick="gerenciarQuiz(<?= $modulo['id'] ?>, '<?= htmlspecialchars(addslashes($modulo['titulo'])) ?>')">
                                    <i class="fas fa-question-circle"></i> Quiz
                                </button>
                                <button class="btn btn-warning" onclick="abrirModalEditar(<?= $modulo['id'] ?>)">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <button class="btn btn-danger" onclick="confirmarExclusao(<?= $modulo['id'] ?>)">
                                    <i class="fas fa-trash"></i> Excluir
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($modulos)): ?>
                    <div class="empty-state">
                        <i class="fas fa-layer-group"></i>
                        <h3>Nenhum módulo encontrado</h3>
                        <p>Comece criando o primeiro módulo para este curso!</p>
                        <button class="btn btn-primary" onclick="mostrarSecao('novo-modulo')" style="margin-top: 1rem;">
                            <i class="fas fa-plus"></i> Criar Primeiro Módulo
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Seção: Prova Final -->
                <?php if ($prova_final): ?>
                <div class="prova-final-card">
                    <div class="prova-final-header">
                        <h3><i class="fas fa-graduation-cap"></i> Prova Final Configurada</h3>
                        <a href="/curso_agrodash/adminprovafinal?curso_id=<?= $curso_id ?>&acao=editar" class="btn btn-success">
                            <i class="fas fa-edit"></i> Gerenciar Prova
                        </a>
                    </div>
                    <div class="prova-final-stats">
                        <div class="prova-stat">
                            <div class="prova-stat-value"><?= $prova_final['total_questoes'] ?></div>
                            <div class="prova-stat-label">Questões</div>
                        </div>
                        <div class="prova-stat">
                            <div class="prova-stat-value"><?= $prova_final['nota_minima'] ?>%</div>
                            <div class="prova-stat-label">Nota Mínima</div>
                        </div>
                        <div class="prova-stat">
                            <div class="prova-stat-value"><?= $prova_final['tempo_limite'] ?> min</div>
                            <div class="prova-stat-label">Tempo Limite</div>
                        </div>
                        <div class="prova-stat">
                            <div class="prova-stat-value"><?= $prova_final['tentativas_permitidas'] ?></div>
                            <div class="prova-stat-label">Tentativas</div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="prova-final-card" style="background: linear-gradient(135deg, var(--warning), var(--danger));">
                    <div style="text-align: center; padding: 1rem;">
                        <i class="fas fa-exclamation-circle" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                        <h3 style="margin-bottom: 0.5rem;">Prova Final Não Configurada</h3>
                        <p style="opacity: 0.9; margin-bottom: 1rem;">Configure a prova final para este curso.</p>
                        <a href="/curso_agrodash/adminprovafinal?curso_id=<?= $curso_id ?>&acao=criar" class="btn btn-success">
                            <i class="fas fa-plus"></i> Criar Prova Final
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Seção: Novo Módulo -->
            <div id="novo-modulo" class="content-section">
                <div class="form-container">
                    <form id="form-novo-modulo" method="POST">
                        <input type="hidden" name="action" value="salvar_modulo">
                        <input type="hidden" name="curso_id" value="<?= $curso_id ?>">
                        <input type="hidden" name="id" value="0">
                        
                        <div class="form-group">
                            <label class="form-label" for="titulo">Título do Módulo *</label>
                            <input type="text" class="form-control" id="titulo" name="titulo" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="descricao">Descrição</label>
                            <textarea class="form-control form-textarea" id="descricao" name="descricao" placeholder="Descreva o conteúdo deste módulo..."></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="ordem">Ordem *</label>
                                <input type="number" class="form-control" id="ordem" name="ordem" value="<?= count($modulos) + 1 ?>" min="1" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="duracao">Duração</label>
                                <input type="text" class="form-control" id="duracao" name="duracao" value="30 min" placeholder="ex: 30 min, 1 hora">
                            </div>
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
                                <option value="fas fa-question-circle">Pergunta</option>
                                <option value="fas fa-check-circle">Check</option>
                                <option value="fas fa-star">Estrela</option>
                                <option value="fas fa-flag">Bandeira</option>
                                <option value="fas fa-trophy">Troféu</option>
                                <option value="fas fa-graduation-cap">Chapéu</option>
                                <option value="fas fa-laptop">Laptop</option>
                                <option value="fas fa-mobile-alt">Celular</option>
                            </select>
                        </div>

                        <div class="form-group" style="display: flex; gap: 0.75rem; margin-top: 2rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Salvar Módulo
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="mostrarSecao('modulos')">
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
                <h2><i class="fas fa-edit"></i> Editar Módulo</h2>
                <button class="modal-close" onclick="fecharModalEditar()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="form-editar-modulo" method="POST">
                    <input type="hidden" name="action" value="salvar_modulo">
                    <input type="hidden" name="curso_id" value="<?= $curso_id ?>">
                    <input type="hidden" id="edit_id" name="id" value="0">
                    
                    <div class="form-group">
                        <label class="form-label" for="edit_titulo">Título do Módulo *</label>
                        <input type="text" class="form-control" id="edit_titulo" name="titulo" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit_descricao">Descrição</label>
                        <textarea class="form-control form-textarea" id="edit_descricao" name="descricao"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="edit_ordem">Ordem *</label>
                            <input type="number" class="form-control" id="edit_ordem" name="ordem" min="1" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_duracao">Duração</label>
                            <input type="text" class="form-control" id="edit_duracao" name="duracao">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit_icone">Ícone</label>
                        <select class="form-control" id="edit_icone" name="icone">
                            <option value="fas fa-book">Livro</option>
                            <option value="fas fa-play-circle">Play</option>
                            <option value="fas fa-chart-bar">Gráfico</option>
                            <option value="fas fa-chart-line">Linha</option>
                            <option value="fas fa-video">Vídeo</option>
                            <option value="fas fa-file-alt">Documento</option>
                            <option value="fas fa-cogs">Engrenagens</option>
                            <option value="fas fa-lightbulb">Lâmpada</option>
                            <option value="fas fa-question-circle">Pergunta</option>
                            <option value="fas fa-check-circle">Check</option>
                            <option value="fas fa-star">Estrela</option>
                            <option value="fas fa-flag">Bandeira</option>
                            <option value="fas fa-trophy">Troféu</option>
                            <option value="fas fa-graduation-cap">Chapéu</option>
                            <option value="fas fa-laptop">Laptop</option>
                            <option value="fas fa-mobile-alt">Celular</option>
                        </select>
                    </div>

                    <div class="form-group" style="display: flex; gap: 0.75rem; margin-top: 2rem;">
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

    <!-- Modal para Gerenciar Quiz -->
    <div id="modalQuiz" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h2><i class="fas fa-question-circle"></i> Quiz do Módulo</h2>
                <button class="modal-close" onclick="fecharModalQuiz()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modalQuizContent">
                <!-- Conteúdo do quiz será carregado aqui via AJAX -->
            </div>
        </div>
    </div>

    <script>
        // Sistema de notificação (mesmo do admin_cursos)
        function showNotification(message, type = 'info') {
            // Remove notificações existentes
            const existing = document.querySelector('.custom-notification');
            if (existing) existing.remove();
            
            // Cria a notificação
            const notification = document.createElement('div');
            notification.className = `custom-notification ${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            `;
            
            document.body.appendChild(notification);
            
            // Adiciona estilos
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
            
            // Adiciona animação
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
            
            // Remove automaticamente após 5 segundos
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
            // Esconder todas as seções
            document.querySelectorAll('.content-section').forEach(sec => {
                sec.classList.remove('active');
            });
            
            // Mostrar a seção selecionada
            document.getElementById(secaoId).classList.add('active');
            
            // Atualizar aba ativa
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelector(`[data-tab="${secaoId}"]`).classList.add('active');
            
            // Rolar para o topo
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Funções para Módulos
        function abrirModalEditar(moduloId) {
            // Buscar dados do módulo via AJAX
            const formData = new FormData();
            formData.append('action', 'buscar_modulo');
            formData.append('id', moduloId);
            
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
                if (data.success && data.modulo) {
                    preencherFormularioEditar(data.modulo);
                    document.getElementById('modalEditar').classList.add('active');
                } else {
                    showNotification('Erro ao carregar módulo: ' + (data.message || 'Módulo não encontrado'), 'error');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showNotification('Erro ao carregar módulo', 'error');
            });
        }

        function fecharModalEditar() {
            document.getElementById('modalEditar').classList.remove('active');
            limparFormularioEditar();
        }

        function preencherFormularioEditar(modulo) {
            document.getElementById('edit_id').value = modulo.id;
            document.getElementById('edit_titulo').value = modulo.titulo;
            document.getElementById('edit_descricao').value = modulo.descricao || '';
            document.getElementById('edit_ordem').value = modulo.ordem;
            document.getElementById('edit_duracao').value = modulo.duracao || '30 min';
            document.getElementById('edit_icone').value = modulo.icone || 'fas fa-book';
        }

        function limparFormularioEditar() {
            document.getElementById('form-editar-modulo').reset();
            document.getElementById('edit_id').value = '0';
        }

        // Formulário de Edição
        document.getElementById('form-editar-modulo').addEventListener('submit', function(e) {
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
                    showNotification(data.message || 'Módulo atualizado com sucesso!', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('Erro ao salvar módulo: ' + (data.message || 'Erro desconhecido'), 'error');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showNotification('Erro ao conectar com o servidor', 'error');
            });
        });

        // Formulário de Novo Módulo
        document.getElementById('form-novo-modulo').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            
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
                    showNotification('Módulo criado com sucesso!', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('Erro ao criar módulo: ' + (data.message || 'Erro desconhecido'), 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showNotification('Erro ao conectar com o servidor', 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        // Função para gerenciar quiz do módulo
        function gerenciarQuiz(moduloId, moduloTitulo) {
            // Mostrar loading
            document.getElementById('modalQuizContent').innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--primary-500);"></i>
                    <p style="margin-top: 1rem; color: var(--gray-600);">Carregando quiz...</p>
                </div>
            `;
            
            // Abrir modal
            document.getElementById('modalQuiz').classList.add('active');
            
            // Carregar conteúdo do quiz via AJAX
            fetch(`/curso_agrodash/ajax/carregar_quiz_modulo.php?modulo_id=${moduloId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Erro ao carregar quiz: ' + response.status);
                    }
                    return response.text();
                })
                .then(html => {
                    document.getElementById('modalQuizContent').innerHTML = html;
                })
                .catch(error => {
                    console.error('Erro ao carregar quiz:', error);
                    document.getElementById('modalQuizContent').innerHTML = `
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
                });
        }

        function fecharModalQuiz() {
            document.getElementById('modalQuiz').classList.remove('active');
            document.getElementById('modalQuizContent').innerHTML = '';
        }

        // Função de exclusão
        function confirmarExclusao(moduloId) {
            if (confirm('Tem certeza que deseja excluir este módulo?\n\n⚠️ Todos os conteúdos e quizzes associados serão perdidos.')) {
                // Enviar requisição de exclusão via AJAX
                const formData = new FormData();
                formData.append('action', 'excluir_modulo');
                formData.append('id', moduloId);
                
                const submitBtn = event.target;
                const originalText = submitBtn.innerHTML;
                
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'Módulo excluído com sucesso!', 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification('Erro ao excluir módulo: ' + (data.message || 'Erro desconhecido'), 'error');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    showNotification('Erro ao excluir módulo', 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                });
            }
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
            // Modal de edição
            const modalEditar = document.getElementById('modalEditar');
            if (e.target === modalEditar) {
                fecharModalEditar();
            }
            
            // Modal de quiz
            const modalQuiz = document.getElementById('modalQuiz');
            if (e.target === modalQuiz) {
                fecharModalQuiz();
            }
        });
    </script>
</body>
</html>