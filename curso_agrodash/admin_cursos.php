<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    header("Location: dashboard.php");
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
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Cursos - AgroDash Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        /* Reutilizar os estilos do admin_dashboard.php */
        :root {
            --primary: #32CD32;
            --primary-dark: #228B22;
            --secondary: #667eea;
            --dark: #2c3e50;
            --light: #f8f9fa;
            --gray: #6c757d;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Roboto', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; }
        
        /* Sidebar (mesmo estilo do dashboard) */
        .sidebar { width: 280px; background: var(--dark); color: white; height: 100vh; position: fixed; overflow-y: auto; }
        .sidebar-header { padding: 30px 25px; background: var(--primary); text-align: center; }
        .sidebar-nav { padding: 20px 0; }
        .nav-section { margin-bottom: 25px; }
        .nav-title { padding: 0 25px 10px 25px; font-size: 0.8rem; text-transform: uppercase; color: var(--gray); border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 10px; }
        .nav-item { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; border-left-color: var(--primary); }
        .nav-item i { width: 20px; margin-right: 12px; font-size: 1.1rem; }
        
        /* Main Content */
        .main-content { flex: 1; margin-left: 280px; padding: 30px; }
        .content-header { display: flex; justify-content: between; align-items: center; margin-bottom: 30px; }
        .header-title h1 { font-size: 2rem; color: white; margin-bottom: 5px; }
        .header-title p { color: rgba(255,255,255,0.8); }
        
        /* Estilos específicos da página de cursos */
        .admin-container { background: white; border-radius: 15px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); overflow: hidden; }
        .admin-nav { background: var(--light); padding: 20px; border-bottom: 1px solid #e9ecef; }
        .nav-tabs { display: flex; gap: 10px; flex-wrap: wrap; }
        .nav-tab { padding: 12px 24px; background: white; border: 2px solid var(--primary); border-radius: 25px; color: var(--primary); text-decoration: none; font-weight: 500; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px; }
        .nav-tab:hover, .nav-tab.active { background: var(--primary); color: white; }
        .admin-content { padding: 30px; }
        .content-section { display: none; }
        .content-section.active { display: block; animation: fadeIn 0.5s ease; }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Grid de Cursos */
        .cursos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 25px; margin-top: 20px; }
        .curso-card { background: white; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); overflow: hidden; transition: transform 0.3s ease, box-shadow 0.3s ease; border: 1px solid #e9ecef; }
        .curso-card:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.15); }
        .curso-imagem { height: 200px; background: linear-gradient(135deg, #667eea, #764ba2); position: relative; overflow: hidden; }
        .curso-status { position: absolute; top: 15px; right: 15px; padding: 5px 12px; border-radius: 15px; font-size: 0.8rem; font-weight: 500; }
        .status-ativo { background: var(--primary); color: white; }
        .status-inativo { background: var(--gray); color: white; }
        .curso-info { padding: 20px; }
        .curso-titulo { font-size: 1.3rem; font-weight: 600; color: var(--dark); margin-bottom: 10px; }
        .curso-descricao { color: var(--gray); font-size: 0.95rem; line-height: 1.5; margin-bottom: 15px; }
        .curso-meta { display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--gray); margin-bottom: 15px; }
        .curso-acoes { display: flex; gap: 10px; }
        
        .btn { padding: 8px 16px; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-size: 0.9rem; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-secondary { background: var(--gray); color: white; }
        .btn-secondary:hover { background: #545b62; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }

        /* Formulários */
        .form-container { max-width: 800px; margin: 0 auto; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--dark); }
        .form-control { width: 100%; padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 1rem; transition: border-color 0.3s ease; }
        .form-control:focus { outline: none; border-color: var(--primary); }
        .form-textarea { min-height: 120px; resize: vertical; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .form-row, .cursos-grid { grid-template-columns: 1fr; }
            .nav-tabs { flex-direction: column; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h1><i class="fas fa-graduation-cap"></i> AgroDash Admin</h1>
            <p>Painel de Controle</p>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-title">Principal</div>
                <a href="admin_dashboard.php" class="nav-item">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Conteúdo</div>
                <a href="admin_cursos.php" class="nav-item active">
                    <i class="fas fa-book"></i> Gerenciar Cursos
                </a>
                <a href="admin_modulos.php" class="nav-item">
                    <i class="fas fa-layer-group"></i> Gerenciar Módulos
                </a>
                <a href="admin_conteudos.php" class="nav-item">
                    <i class="fas fa-file-alt"></i> Gerenciar Conteúdos
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Sistema</div>
                <a href="../dashboard.php" class="nav-item">
                    <i class="fas fa-arrow-left"></i> Voltar ao Site
                </a>
                <a href="../logout.php" class="nav-item">
                    <i class="fas fa-sign-out-alt"></i> Sair
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="content-header">
            <div class="header-title">
                <h1>Gerenciar Cursos</h1>
                <p>Crie e gerencie os cursos da plataforma</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="mostrarSecao('novo-curso')">
                    <i class="fas fa-plus"></i> Novo Curso
                </button>
                <button class="btn btn-secondary" id="mobile-menu-btn">
                    <i class="fas fa-bars"></i> Menu
                </button>
            </div>
        </div>

        <div class="admin-container">
            <div class="admin-nav">
                <div class="nav-tabs">
                    <a href="#" class="nav-tab active" data-tab="cursos">
                        <i class="fas fa-book"></i> Todos os Cursos
                    </a>
                    <a href="#" class="nav-tab" data-tab="novo-curso">
                        <i class="fas fa-plus"></i> Novo Curso
                    </a>
                </div>
            </div>

            <div class="admin-content">
                <!-- Seção: Lista de Cursos -->
                <div id="cursos" class="content-section active">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle"></i> <?= $error ?>
                        </div>
                    <?php endif; ?>

                    <div class="cursos-grid">
                        <?php foreach ($cursos as $curso): ?>
                        <div class="curso-card">
                            <div class="curso-imagem">
                                <?php if (!empty($curso['imagem'])): ?>
                                    <img src="<?= htmlspecialchars($curso['imagem']) ?>" alt="<?= htmlspecialchars($curso['titulo']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                <?php else: ?>
                                    <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: white; font-size: 3rem;">
                                        <i class="fas fa-graduation-cap"></i>
                                    </div>
                                <?php endif; ?>
                                <span class="curso-status status-<?= $curso['status'] ?>">
                                    <?= ucfirst($curso['status']) ?>
                                </span>
                            </div>
                            <div class="curso-info">
                                <h3 class="curso-titulo"><?= htmlspecialchars($curso['titulo']) ?></h3>
                                <p class="curso-descricao"><?= htmlspecialchars($curso['descricao']) ?></p>
                                <div class="curso-meta">
                                    <span><i class="fas fa-clock"></i> <?= $curso['duracao_estimada'] ?? '2 horas' ?></span>
                                    <span><i class="fas fa-signal"></i> <?= $curso['nivel'] ?? 'Iniciante' ?></span>
                                </div>
                                <div class="curso-acoes">
                                    <a href="admin_curso_editar.php?id=<?= $curso['id'] ?>" class="btn btn-primary">
                                        <i class="fas fa-edit"></i> Editar
                                    </a>
                                    <a href="admin_modulos.php?curso_id=<?= $curso['id'] ?>" class="btn btn-secondary">
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
                        <div class="empty-state" style="grid-column: 1 / -1; text-align: center; padding: 50px; color: var(--gray);">
                            <i class="fas fa-book-open" style="font-size: 4rem; margin-bottom: 20px;"></i>
                            <h3>Nenhum curso encontrado</h3>
                            <p>Comece criando seu primeiro curso!</p>
                            <button class="btn btn-primary" onclick="mostrarSecao('novo-curso')" style="margin-top: 15px;">
                                <i class="fas fa-plus"></i> Criar Primeiro Curso
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Seção: Novo Curso -->
                <div id="novo-curso" class="content-section">
                    <div class="form-container">
                        <form id="form-curso" action="ajax/salvar_curso.php" method="POST" enctype="multipart/form-data">
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

                            <div class="form-group">
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
        </div>
    </main>

    <script>
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
        }

        // Mobile menu
        document.getElementById('mobile-menu-btn').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });

        // Form submission
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
                    alert('Curso salvo com sucesso!');
                    window.location.reload();
                } else {
                    alert('Erro ao salvar curso: ' + data.message);
                }
            })
            .catch(error => {
                alert('Erro ao salvar curso: ' + error);
            });
        });

        function confirmarExclusao(cursoId) {
            if (confirm('Tem certeza que deseja excluir este curso?')) {
                window.location.href = `ajax/excluir_curso.php?id=${cursoId}`;
            }
        }
    </script>
</body>
</html>