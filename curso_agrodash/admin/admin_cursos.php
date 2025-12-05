<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    header("Location: /curso_agrodash/dashboard");
    exit;
}

// Buscar todos os cursos
try {
    $stmt = $pdo->query("SELECT * FROM cursos ORDER BY id DESC");
    $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cursos = [];
    $error = "Erro ao carregar cursos: " . $e->getMessage();
}



// Buscar estatísticas para o sidebar
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos");
    $total_cursos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos WHERE status = 'ativo'");
    $cursos_ativos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $total_usuarios = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM modulos");
    $total_modulos = $stmt->fetchColumn();
} catch (PDOException $e) {
    $error_stats = "Erro ao carregar estatísticas: " . $e->getMessage();
}

// Processar ações AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'buscar_curso') {
        $curso_id = $_POST['id'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
            $stmt->execute([$curso_id]);
            $curso = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($curso) {
                echo json_encode(['success' => true, 'curso' => $curso]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Curso não encontrado']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao buscar curso: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'salvar_curso') {
        $id = $_POST['id'] ?? 0;
        $titulo = $_POST['titulo'] ?? '';
        $descricao = $_POST['descricao'] ?? '';
        $duracao_estimada = $_POST['duracao_estimada'] ?? '2 horas';
        $nivel = $_POST['nivel'] ?? 'Iniciante';
        $publico_alvo = $_POST['publico_alvo'] ?? 'todos';
        $status = $_POST['status'] ?? 'ativo';
        $imagem_url = $_POST['imagem_url'] ?? '';
        
        try {
            if ($id > 0) {
                // Atualizar curso existente
                $stmt = $pdo->prepare("UPDATE cursos SET titulo = ?, descricao = ?, duracao_estimada = ?, nivel = ?, publico_alvo = ?, status = ?, imagem = ? WHERE id = ?");
                $stmt->execute([$titulo, $descricao, $duracao_estimada, $nivel, $publico_alvo, $status, $imagem_url, $id]);
                echo json_encode(['success' => true, 'message' => 'Curso atualizado com sucesso!']);
            } else {
                // Criar novo curso
                $stmt = $pdo->prepare("INSERT INTO cursos (titulo, descricao, duracao_estimada, nivel, publico_alvo, status, imagem, data_criacao) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$titulo, $descricao, $duracao_estimada, $nivel, $publico_alvo, $status, $imagem_url]);
                echo json_encode(['success' => true, 'message' => 'Curso criado com sucesso!']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao salvar curso: ' . $e->getMessage()]);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Cursos - AgroDash Admin</title>
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

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--gray-900) 0%, var(--gray-800) 100%);
            color: white;
            height: 100vh;
            position: fixed;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            transition: transform var(--transition-normal);
            box-shadow: var(--shadow-xl);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo i {
            font-size: 2rem;
            color: var(--primary-500);
            background: rgba(76, 190, 76, 0.1);
            width: 48px;
            height: 48px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-text h1 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .logo-text p {
            font-size: 0.75rem;
            opacity: 0.7;
            font-weight: 500;
        }

        /* Navegação */
        .sidebar-nav {
            flex: 1;
            padding: 1rem 0;
            overflow-y: auto;
        }

        .nav-section {
            margin-bottom: 1.5rem;
        }

        .nav-title {
            padding: 0 1.5rem 0.5rem 1.5rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--gray-400);
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: var(--gray-300);
            text-decoration: none;
            transition: all var(--transition-fast);
            position: relative;
            margin: 0.125rem 0;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
            padding-left: 1.75rem;
        }

        .nav-item.active {
            background: rgba(76, 190, 76, 0.1);
            color: white;
            border-left: 3px solid var(--primary-500);
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: var(--primary-500);
        }

        .nav-item i {
            font-size: 1.125rem;
            width: 24px;
            text-align: center;
        }

        .nav-text {
            flex: 1;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .nav-badge {
            background: var(--primary-500);
            color: white;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            min-width: 1.75rem;
            text-align: center;
        }

        .nav-item.logout {
            color: var(--danger);
            margin-top: 0.5rem;
        }

        .nav-item.logout:hover {
            background: rgba(239, 68, 68, 0.1);
        }

        /* Footer da Sidebar */
        .sidebar-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(0, 0, 0, 0.2);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
        }

        .user-details h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.125rem;
        }

        .user-details p {
            font-size: 0.75rem;
            opacity: 0.7;
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

        /* Search Box */
        .search-box {
            position: relative;
            width: 240px;
        }

        .search-box i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
        }

        .search-box input {
            width: 100%;
            padding: 0.625rem 1rem 0.625rem 2.5rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            transition: all var(--transition-fast);
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 3px rgba(76, 190, 76, 0.1);
        }

        /* Conteúdo Principal */
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

        /* Grid de Cursos */
        .cursos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .curso-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: all var(--transition-normal);
            border: 1px solid var(--gray-200);
        }

        .curso-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-200);
        }

        .curso-imagem {
            height: 180px;
            background: linear-gradient(135deg, var(--primary-400), var(--primary-600));
            position: relative;
            overflow: hidden;
        }

        .curso-imagem img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .curso-status {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-ativo {
            background: var(--success);
            color: white;
        }

        .status-inativo {
            background: var(--gray-400);
            color: white;
        }

        .status-nao_iniciado, .status-rascunho {
            background: var(--warning);
            color: white;
        }

        .curso-info {
            padding: 1.25rem;
        }

        .curso-titulo {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.75rem;
            line-height: 1.4;
        }

        .curso-descricao {
            color: var(--gray-600);
            font-size: 0.875rem;
            line-height: 1.5;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .curso-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .curso-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .curso-publico {
            background: var(--primary-100);
            color: var(--primary-700);
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.7rem;
            font-weight: 500;
            margin-top: 0.5rem;
            display: inline-block;
        }

        .curso-acoes {
            display: flex;
            gap: 0.5rem;
        }

        .curso-acoes .btn {
            flex: 1;
            padding: 0.5rem;
            font-size: 0.75rem;
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

        /* Seletor de Imagem */
        .image-upload-container {
            position: relative;
        }

        .image-preview {
            width: 100%;
            height: 200px;
            border: 2px dashed var(--gray-300);
            border-radius: var(--radius-md);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: var(--gray-50);
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .image-preview:hover {
            border-color: var(--primary-500);
            background: var(--primary-50);
        }

        .image-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .image-placeholder {
            text-align: center;
            color: var(--gray-500);
        }

        .image-placeholder i {
            font-size: 3rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        .image-upload-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary-500);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
            width: 100%;
        }

        .image-upload-btn:hover {
            background: var(--primary-600);
        }

        .image-upload-input {
            display: none;
        }

        .image-url-container {
            margin-top: 1rem;
        }

        .image-url-toggle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-600);
            font-weight: 500;
            cursor: pointer;
            margin-bottom: 0.5rem;
        }

        .image-url-toggle:hover {
            color: var(--primary-700);
        }

        .image-url-input {
            display: none;
        }

        .image-url-input.active {
            display: block;
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
            
            .cursos-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
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
            
            .search-box {
                width: 100%;
            }
            
            .cursos-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .nav-tabs {
                flex-wrap: wrap;
            }
            
            .curso-acoes {
                flex-wrap: wrap;
            }
            
            .curso-acoes .btn {
                flex: auto;
            }
            
            .modal-content {
                width: 95%;
                margin: 1rem;
            }
        }

        /* Animações */
        .curso-card {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-leaf"></i>
                <div class="logo-text">
                    <h1>AgroDash</h1>
                    <p>Painel Admin</p>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-title">Principal</div>
                <a href="/curso_agrodash/dashboard" class="nav-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Conteúdo</div>
                <a href="/curso_agrodash/admincursos" class="nav-item active">
                    <i class="fas fa-book"></i>
                    <span class="nav-text">Gerenciar Cursos</span>
                    <span class="nav-badge"><?= $total_cursos ?? 0 ?></span>
                </a>
                <a href="/curso_agrodash/adminmodulos" class="nav-item">
                    <i class="fas fa-layer-group"></i>
                    <span class="nav-text">Gerenciar Módulos</span>
                    <span class="nav-badge"><?= $total_modulos ?? 0 ?></span>
                </a>
                <a href="/curso_agrodash/adminconteudos" class="nav-item">
                    <i class="fas fa-file-alt"></i>
                    <span class="nav-text">Gerenciar Conteúdos</span>
                </a>
                <a href="/curso_agrodash/loja" class="nav-item">
                    <i class="fas fa-home"></i>
                    <span class="nav-text">Loja de XP</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Usuários</div>
                <a href="/curso_agrodash/adminusuarios" class="nav-item">
                    <i class="fas fa-users"></i>
                    <span class="nav-text">Gerenciar Usuários</span>
                    <span class="nav-badge"><?= $total_usuarios ?? 0 ?></span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Sistema</div>
                <a href="/curso_agrodash/dashboard" class="nav-item">
                    <i class="fas fa-arrow-left"></i>
                    <span class="nav-text">Voltar ao Site</span>
                </a>
                <a href="../logout.php" class="nav-item logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="nav-text">Sair</span>
                </a>
            </div>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <?= strtoupper(substr($_SESSION['usuario_nome'], 0, 1)) ?>
                </div>
                <div class="user-details">
                    <h4><?= htmlspecialchars($_SESSION['usuario_nome']) ?></h4>
                    <p><?= htmlspecialchars($_SESSION['usuario_email']) ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Header -->
        <header class="main-header">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Gerenciar Cursos</h1>
                    <p>Crie e gerencie os cursos da plataforma</p>
                </div>
            </div>
            
            <div class="header-right">
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="mostrarSecao('novo-curso')">
                        <i class="fas fa-plus"></i>
                        <span>Novo Curso</span>
                    </button>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Pesquisar cursos..." id="searchInput">
                    </div>
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

            <!-- Abas de Navegação -->
            <div class="nav-tabs">
                <button class="nav-tab active" data-tab="cursos">
                    <i class="fas fa-book"></i> Todos os Cursos
                </button>
                <button class="nav-tab" data-tab="novo-curso">
                    <i class="fas fa-plus"></i> Novo Curso
                </button>
            </div>

            <!-- Seção: Lista de Cursos -->
            <div id="cursos" class="content-section active">
                <div class="cursos-grid" id="cursosGrid">
                    <?php foreach ($cursos as $curso): ?>
                    <div class="curso-card" data-curso-id="<?= $curso['id'] ?>">
                        <div class="curso-imagem">
                            <?php if (!empty($curso['imagem'])): ?>
                                <img src="<?= htmlspecialchars($curso['imagem']) ?>" alt="<?= htmlspecialchars($curso['titulo']) ?>">
                            <?php else: ?>
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: white; font-size: 3rem;">
                                    <i class="fas fa-graduation-cap"></i>
                                </div>
                            <?php endif; ?>
                            <span class="curso-status status-<?= str_replace(' ', '_', strtolower($curso['status'])) ?>">
                                <?= ucfirst($curso['status']) ?>
                            </span>
                        </div>
                        <div class="curso-info">
                            <h3 class="curso-titulo"><?= htmlspecialchars($curso['titulo']) ?></h3>
                            <p class="curso-descricao"><?= htmlspecialchars($curso['descricao']) ?></p>
                            <div class="curso-meta">
                                <span><i class="fas fa-clock"></i> <?= htmlspecialchars($curso['duracao_estimada'] ?? '2 horas') ?></span>
                                <span><i class="fas fa-signal"></i> <?= htmlspecialchars($curso['nivel'] ?? 'Iniciante') ?></span>
                            </div>
                            <?php if (!empty($curso['publico_alvo'])): ?>
                                <div class="curso-publico">Público: <?= htmlspecialchars($curso['publico_alvo']) ?></div>
                            <?php endif; ?>
                            <div class="curso-acoes">
                                <button class="btn btn-primary" onclick="abrirModalEditar(<?= $curso['id'] ?>)">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <a href="/curso_agrodash/adminmodulos?curso_id=<?= $curso['id'] ?>" class="btn btn-secondary">
                                    <i class="fas fa-layer-group"></i> Módulos
                                </a>
                                <button class="btn btn-danger" onclick="confirmarExclusao(<?= $curso['id'] ?>)">
                                    <i class="fas fa-trash"></i> Excluir
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($cursos)): ?>
                    <div class="empty-state">
                        <i class="fas fa-book-open"></i>
                        <h3>Nenhum curso encontrado</h3>
                        <p>Comece criando seu primeiro curso!</p>
                        <button class="btn btn-primary" onclick="mostrarSecao('novo-curso')" style="margin-top: 1rem;">
                            <i class="fas fa-plus"></i> Criar Primeiro Curso
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Seção: Novo Curso -->
            <div id="novo-curso" class="content-section">
                <div class="form-container">
                    <form id="form-novo-curso" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="salvar_curso">
                        <input type="hidden" name="id" value="0">
                        
                        <div class="form-group">
                            <label class="form-label" for="titulo">Título do Curso *</label>
                            <input type="text" class="form-control" id="titulo" name="titulo" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="descricao">Descrição *</label>
                            <textarea class="form-control form-textarea" id="descricao" name="descricao" required></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="duracao_estimada">Duração Estimada</label>
                                <input type="text" class="form-control" id="duracao_estimada" name="duracao_estimada" value="2 horas">
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="nivel">Nível</label>
                                <select class="form-control" id="nivel" name="nivel">
                                    <option value="Iniciante">Iniciante</option>
                                    <option value="Intermediário">Intermediário</option>
                                    <option value="Avançado">Avançado</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="publico_alvo">Público-Alvo</label>
                                <select class="form-control" id="publico_alvo" name="publico_alvo">
                                    <option value="todos">Todos</option>
                                    <option value="operador">Operadores</option>
                                    <option value="gestor">Gestores</option>
                                    <option value="admin">Administradores</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="status">Status</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="Nao_iniciado" selected>Não Iniciado</option>
                                    <option value="rascunho" selected>Rascunho</option>
                                    <option value="ativo">Ativo</option>
                                    <option value="inativo">Inativo</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Imagem do Curso</label>
                            <div class="image-upload-container">
                                <!-- Preview da imagem -->
                                <div class="image-preview" id="imagePreview">
                                    <div class="image-placeholder">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p>Clique para selecionar uma imagem</p>
                                    </div>
                                </div>
                                
                                <!-- Botão para upload -->
                                <button type="button" class="image-upload-btn" onclick="document.getElementById('imagem_upload').click()">
                                    <i class="fas fa-upload"></i> Selecionar Imagem
                                </button>
                                
                                <!-- Input de upload (oculto) -->
                                <input type="file" id="imagem_upload" name="imagem_upload" class="image-upload-input" accept="image/*" onchange="previewImage(event)">
                                
                                <!-- Opção para URL (alternativa) -->
                                <div class="image-url-container">
                                    <div class="image-url-toggle" onclick="toggleUrlInput()">
                                        <i class="fas fa-link"></i>
                                        <span>Ou insira uma URL da imagem</span>
                                    </div>
                                    <input type="text" class="form-control image-url-input" id="imagem_url" name="imagem_url" placeholder="https://exemplo.com/imagem.jpg">
                                </div>
                                
                                <!-- Campo oculto para enviar a imagem -->
                                <input type="hidden" id="imagem" name="imagem_url">
                            </div>
                        </div>

                        <div class="form-group" style="display: flex; gap: 0.75rem; margin-top: 2rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Salvar Curso
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="mostrarSecao('cursos')">
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
                <h2><i class="fas fa-edit"></i> Editar Curso</h2>
                <button class="modal-close" onclick="fecharModalEditar()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="form-editar-curso" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="salvar_curso">
                    <input type="hidden" id="edit_id" name="id" value="0">
                    
                    <div class="form-group">
                        <label class="form-label" for="edit_titulo">Título do Curso *</label>
                        <input type="text" class="form-control" id="edit_titulo" name="titulo" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit_descricao">Descrição *</label>
                        <textarea class="form-control form-textarea" id="edit_descricao" name="descricao" required></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="edit_duracao_estimada">Duração Estimada</label>
                            <input type="text" class="form-control" id="edit_duracao_estimada" name="duracao_estimada">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_nivel">Nível</label>
                            <select class="form-control" id="edit_nivel" name="nivel">
                                <option value="Iniciante">Iniciante</option>
                                <option value="Intermediário">Intermediário</option>
                                <option value="Avançado">Avançado</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="edit_publico_alvo">Público-Alvo</label>
                            <select class="form-control" id="edit_publico_alvo" name="publico_alvo">
                                <option value="todos">Todos</option>
                                <option value="operador">Operadores</option>
                                <option value="gestor">Gestores</option>
                                <option value="admin">Administradores</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_status">Status</label>
                            <select class="form-control" id="edit_status" name="status">
                                <option value="Nao_iniciado" selected>Não Iniciado</option>
                                <option value="ativo">Ativo</option>
                                <option value="inativo">Inativo</option>
                                <option value="rascunho">Rascunho</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Imagem do Curso</label>
                        <div class="image-upload-container">
                            <!-- Preview da imagem atual -->
                            <div class="image-preview" id="editImagePreview">
                                <div class="image-placeholder">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Clique para selecionar uma imagem</p>
                                </div>
                            </div>
                            
                            <!-- Botão para upload -->
                            <button type="button" class="image-upload-btn" onclick="document.getElementById('edit_imagem_upload').click()">
                                <i class="fas fa-upload"></i> Selecionar Nova Imagem
                            </button>
                            
                            <!-- Input de upload (oculto) -->
                            <input type="file" id="edit_imagem_upload" class="image-upload-input" accept="image/*" onchange="previewEditImage(event)">
                            
                            <!-- Opção para URL (alternativa) -->
                            <div class="image-url-container">
                                <div class="image-url-toggle" onclick="toggleEditUrlInput()">
                                    <i class="fas fa-link"></i>
                                    <span>Ou insira uma URL da imagem</span>
                                </div>
                                <input type="text" class="form-control image-url-input" id="edit_imagem_url" placeholder="https://exemplo.com/imagem.jpg">
                            </div>
                            
                            <!-- Campo oculto para enviar a imagem -->
                            <input type="hidden" id="edit_imagem" name="imagem_url">
                        </div>
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

    <script>
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

        // Modal de Edição
        function abrirModalEditar(cursoId) {
            // Buscar dados do curso
            const formData = new FormData();
            formData.append('action', 'buscar_curso');
            formData.append('id', cursoId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    preencherFormularioEditar(data.curso);
                    document.getElementById('modalEditar').classList.add('active');
                } else {
                    alert('Erro ao carregar curso: ' + data.message);
                }
            })
            .catch(error => {
                alert('Erro ao carregar curso: ' + error);
            });
        }

        function fecharModalEditar() {
            document.getElementById('modalEditar').classList.remove('active');
            limparFormularioEditar();
        }

        function preencherFormularioEditar(curso) {
            document.getElementById('edit_id').value = curso.id;
            document.getElementById('edit_titulo').value = curso.titulo;
            document.getElementById('edit_descricao').value = curso.descricao;
            document.getElementById('edit_duracao_estimada').value = curso.duracao_estimada || '2 horas';
            document.getElementById('edit_nivel').value = curso.nivel || 'Iniciante';
            document.getElementById('edit_publico_alvo').value = curso.publico_alvo || 'todos';
            document.getElementById('edit_status').value = curso.status || 'ativo';
            document.getElementById('edit_imagem').value = curso.imagem || '';
            
            // Atualizar preview da imagem
            const preview = document.getElementById('editImagePreview');
            if (curso.imagem) {
                preview.innerHTML = `<img src="${curso.imagem}" alt="Imagem do curso">`;
            } else {
                preview.innerHTML = `
                    <div class="image-placeholder">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p>Clique para selecionar uma imagem</p>
                    </div>
                `;
            }
        }

        function limparFormularioEditar() {
            document.getElementById('form-editar-curso').reset();
            document.getElementById('edit_id').value = '0';
            const preview = document.getElementById('editImagePreview');
            preview.innerHTML = `
                <div class="image-placeholder">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>Clique para selecionar uma imagem</p>
                </div>
            `;
            document.getElementById('edit_imagem_url').value = '';
            document.getElementById('edit_imagem_url').classList.remove('active');
        }

        // Formulário de Edição
        document.getElementById('form-editar-curso').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const imagemValor = document.getElementById('edit_imagem').value;
            
            if (!imagemValor && document.getElementById('edit_imagem_url').value) {
                formData.set('imagem_url', document.getElementById('edit_imagem_url').value);
            }
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    fecharModalEditar();
                    location.reload();
                } else {
                    alert('Erro ao salvar curso: ' + data.message);
                }
            })
            .catch(error => {
                alert('Erro ao salvar curso: ' + error);
            });
        });

        // Formulário de Novo Curso
        document.getElementById('form-novo-curso').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const imagemValor = document.getElementById('imagem').value;
            
            if (!imagemValor && document.getElementById('imagem_url').value) {
                formData.set('imagem_url', document.getElementById('imagem_url').value);
            }
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Erro ao salvar curso: ' + data.message);
                }
            })
            .catch(error => {
                alert('Erro ao salvar curso: ' + error);
            });
        });

        // Preview da imagem (novo curso)
        function previewImage(event) {
            const input = event.target;
            const preview = document.getElementById('imagePreview');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" alt="Preview da imagem">`;
                    document.getElementById('imagem').value = e.target.result;
                };
                
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Preview da imagem (edição)
        function previewEditImage(event) {
            const input = event.target;
            const preview = document.getElementById('editImagePreview');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" alt="Preview da imagem">`;
                    document.getElementById('edit_imagem').value = e.target.result;
                };
                
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Alternar entre upload e URL (novo curso)
        function toggleUrlInput() {
            const urlInput = document.getElementById('imagem_url');
            const uploadInput = document.getElementById('imagem_upload');
            const preview = document.getElementById('imagePreview');
            
            if (urlInput.classList.contains('active')) {
                urlInput.classList.remove('active');
                urlInput.value = '';
                document.getElementById('imagem').value = '';
                
                preview.innerHTML = `
                    <div class="image-placeholder">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p>Clique para selecionar uma imagem</p>
                    </div>
                `;
            } else {
                urlInput.classList.add('active');
                uploadInput.value = '';
                document.getElementById('imagem').value = '';
                
                preview.innerHTML = `
                    <div class="image-placeholder">
                        <i class="fas fa-link"></i>
                        <p>Insira a URL da imagem</p>
                    </div>
                `;
            }
        }

        // Alternar entre upload e URL (edição)
        function toggleEditUrlInput() {
            const urlInput = document.getElementById('edit_imagem_url');
            const uploadInput = document.getElementById('edit_imagem_upload');
            const preview = document.getElementById('editImagePreview');
            
            if (urlInput.classList.contains('active')) {
                urlInput.classList.remove('active');
                urlInput.value = '';
                document.getElementById('edit_imagem').value = '';
                
                const cursoId = document.getElementById('edit_id').value;
                if (cursoId > 0) {
                    // Restaurar imagem original
                    fetchImagemOriginal(cursoId, preview);
                } else {
                    preview.innerHTML = `
                        <div class="image-placeholder">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p>Clique para selecionar uma imagem</p>
                        </div>
                    `;
                }
            } else {
                urlInput.classList.add('active');
                uploadInput.value = '';
                document.getElementById('edit_imagem').value = '';
                
                preview.innerHTML = `
                    <div class="image-placeholder">
                        <i class="fas fa-link"></i>
                        <p>Insira a URL da imagem</p>
                    </div>
                `;
            }
        }

        // Atualizar imagem quando URL for inserida (novo curso)
        document.getElementById('imagem_url').addEventListener('input', function(e) {
            const url = e.target.value;
            const preview = document.getElementById('imagePreview');
            
            if (url) {
                if (isValidUrl(url)) {
                    preview.innerHTML = `<img src="${url}" alt="Preview da imagem" onerror="this.onerror=null;this.parentElement.innerHTML='<div class=\\'image-placeholder\\'><i class=\\'fas fa-exclamation-triangle\\'></i><p>Erro ao carregar imagem</p></div>';">`;
                    document.getElementById('imagem').value = url;
                } else {
                    preview.innerHTML = `
                        <div class="image-placeholder">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>URL inválida</p>
                        </div>
                    `;
                    document.getElementById('imagem').value = '';
                }
            } else {
                preview.innerHTML = `
                    <div class="image-placeholder">
                        <i class="fas fa-link"></i>
                        <p>Insira a URL da imagem</p>
                    </div>
                `;
                document.getElementById('imagem').value = '';
            }
        });

        // Atualizar imagem quando URL for inserida (edição)
        document.getElementById('edit_imagem_url').addEventListener('input', function(e) {
            const url = e.target.value;
            const preview = document.getElementById('editImagePreview');
            
            if (url) {
                if (isValidUrl(url)) {
                    preview.innerHTML = `<img src="${url}" alt="Preview da imagem" onerror="this.onerror=null;this.parentElement.innerHTML='<div class=\\'image-placeholder\\'><i class=\\'fas fa-exclamation-triangle\\'></i><p>Erro ao carregar imagem</p></div>';">`;
                    document.getElementById('edit_imagem').value = url;
                } else {
                    preview.innerHTML = `
                        <div class="image-placeholder">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>URL inválida</p>
                        </div>
                    `;
                    document.getElementById('edit_imagem').value = '';
                }
            } else {
                preview.innerHTML = `
                    <div class="image-placeholder">
                        <i class="fas fa-link"></i>
                        <p>Insira a URL da imagem</p>
                    </div>
                `;
                document.getElementById('edit_imagem').value = '';
            }
        });

        // Função para buscar imagem original do curso
        function fetchImagemOriginal(cursoId, previewElement) {
            const formData = new FormData();
            formData.append('action', 'buscar_curso');
            formData.append('id', cursoId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.curso.imagem) {
                    previewElement.innerHTML = `<img src="${data.curso.imagem}" alt="Imagem do curso">`;
                    document.getElementById('edit_imagem').value = data.curso.imagem;
                }
            });
        }

        // Validar URL
        function isValidUrl(string) {
            try {
                new URL(string);
                return true;
            } catch (_) {
                return false;
            }
        }

        // Função de exclusão
        function confirmarExclusao(cursoId) {
            if (confirm('Tem certeza que deseja excluir este curso?')) {
                // Você pode adicionar aqui uma chamada AJAX para excluir
                // Ou redirecionar para um script de exclusão
                window.location.href = `/curso_agrodash/ajax/excluir_curso.php?id=${cursoId}`;
            }
        }

        // Filtro de busca
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const cursos = document.querySelectorAll('.curso-card');
            
            cursos.forEach(curso => {
                const titulo = curso.querySelector('.curso-titulo').textContent.toLowerCase();
                const descricao = curso.querySelector('.curso-descricao').textContent.toLowerCase();
                
                if (titulo.includes(searchTerm) || descricao.includes(searchTerm)) {
                    curso.style.display = 'block';
                } else {
                    curso.style.display = 'none';
                }
            });
            
            // Verificar se há resultados
            const visibleCursos = Array.from(cursos).filter(curso => curso.style.display !== 'none');
            const emptyState = document.querySelector('.empty-state');
            
            if (visibleCursos.length === 0 && searchTerm !== '') {
                if (!emptyState || emptyState.parentElement !== document.getElementById('cursosGrid')) {
                    const grid = document.getElementById('cursosGrid');
                    const emptyDiv = document.createElement('div');
                    emptyDiv.className = 'empty-state';
                    emptyDiv.innerHTML = `
                        <i class="fas fa-search"></i>
                        <h3>Nenhum curso encontrado</h3>
                        <p>Tente usar outros termos de busca</p>
                        <button class="btn btn-secondary" onclick="document.getElementById('searchInput').value='';document.getElementById('searchInput').dispatchEvent(new Event('input'))" style="margin-top: 1rem;">
                            <i class="fas fa-times"></i> Limpar busca
                        </button>
                    `;
                    grid.appendChild(emptyDiv);
                }
            } else if (emptyState && emptyState.parentElement === document.getElementById('cursosGrid')) {
                emptyState.remove();
            }
        });

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

        // Click na preview para abrir seletor de arquivo
        document.getElementById('imagePreview').addEventListener('click', function() {
            if (!document.getElementById('imagem_url').classList.contains('active')) {
                document.getElementById('imagem_upload').click();
            }
        });

        document.getElementById('editImagePreview').addEventListener('click', function() {
            if (!document.getElementById('edit_imagem_url').classList.contains('active')) {
                document.getElementById('edit_imagem_upload').click();
            }
        });

        // Fechar modal com ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                fecharModalEditar();
            }
        });
        
            // Definir status padrão se não estiver selecionado
            const statusSelect = document.getElementById('status');
            if (!statusSelect.value) {
                statusSelect.value = 'Nao_iniciado';
            }
    </script>
</body>
</html>