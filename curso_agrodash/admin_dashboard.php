<?php
require_once 'public/header.php';

// Buscar estatísticas
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos");
    $total_cursos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos WHERE status = 'ativo'");
    $cursos_ativos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $total_usuarios = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM modulos");
    $total_modulos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT * FROM cursos ORDER BY created_at DESC LIMIT 5");
    $cursos_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT nome, email, created_at FROM usuarios ORDER BY created_at DESC LIMIT 5");
    $usuarios_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Erro ao carregar estatísticas: " . $e->getMessage();
}






?>

<div class="content-area">
    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-book"></i>
            </div>
            <div class="stat-info">
                <h3><?= $total_cursos ?? 0 ?></h3>
                <p>Total de Cursos</p>
                <div class="stat-trend trend-up">
                    <i class="fas fa-arrow-up"></i>
                    <span>12% este mês</span>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-play-circle"></i>
            </div>
            <div class="stat-info">
                <h3><?= $cursos_ativos ?? 0 ?></h3>
                <p>Cursos Ativos</p>
                <div class="stat-trend trend-up">
                    <i class="fas fa-arrow-up"></i>
                    <span>8% este mês</span>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-info">
                <h3><?= $total_usuarios ?? 0 ?></h3>
                <p>Usuários Cadastrados</p>
                <div class="stat-trend trend-up">
                    <i class="fas fa-arrow-up"></i>
                    <span>24% este mês</span>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-layer-group"></i>
            </div>
            <div class="stat-info">
                <h3><?= $total_modulos ?? 0 ?></h3>
                <p>Módulos Criados</p>
                <div class="stat-trend trend-up">
                    <i class="fas fa-arrow-up"></i>
                    <span>15% este mês</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="content-grid">
        <!-- Left Column -->
        <div class="left-column">
            <!-- Cursos Recentes -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-clock"></i> Cursos Recentes</h3>
                    <a href="admin_cursos.php">Ver Todos</a>
                </div>
                <div class="card-body">
                    <?php if (!empty($cursos_recentes)): ?>
                        <?php foreach ($cursos_recentes as $curso): ?>
                        <div class="curso-item">
                            <div class="curso-imagem">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <div class="curso-info">
                                <h4><?= htmlspecialchars($curso['titulo']) ?></h4>
                                <p><?= htmlspecialchars(substr($curso['descricao'], 0, 60)) ?>...</p>
                            </div>
                            <span class="curso-status status-<?= $curso['status'] ?>">
                                <?= ucfirst($curso['status']) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 2rem;">
                            <i class="fas fa-book" style="font-size: 3rem; color: var(--gray-300); margin-bottom: 1rem;"></i>
                            <p style="color: var(--gray-500);">Nenhum curso encontrado</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ações Rápidas -->
            <div class="card" style="margin-top: 2rem;">
                <div class="card-header">
                    <h3><i class="fas fa-bolt"></i> Ações Rápidas</h3>
                </div>
                <div class="card-body">
                    <div class="quick-actions">
                        <a href="admin_cursos.php" class="action-btn">
                            <div class="action-icon">
                                <i class="fas fa-book"></i>
                            </div>
                            <span>Novo Curso</span>
                        </a>
                        <a href="admin_modulos.php" class="action-btn">
                            <div class="action-icon">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <span>Novo Módulo</span>
                        </a>
                        <a href="admin_usuarios.php" class="action-btn">
                            <div class="action-icon">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <span>Novo Usuário</span>
                        </a>
                        <a href="admin_relatorios.php" class="action-btn">
                            <div class="action-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <span>Relatórios</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div class="right-column">
            <!-- Usuários Recentes -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Usuários Recentes</h3>
                    <a href="admin_usuarios.php">Ver Todos</a>
                </div>
                <div class="card-body">
                    <?php if (!empty($usuarios_recentes)): ?>
                        <?php foreach ($usuarios_recentes as $usuario): ?>
                        <div class="user-item">
                            <div class="user-avatar">
                                <?= strtoupper(substr($usuario['nome'], 0, 1)) ?>
                            </div>
                            <div class="user-info">
                                <h4><?= htmlspecialchars($usuario['nome']) ?></h4>
                                <p><?= htmlspecialchars($usuario['email']) ?></p>
                            </div>
                            <div class="user-date">
                                <?= date('d/m', strtotime($usuario['created_at'])) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 2rem;">
                            <i class="fas fa-user" style="font-size: 3rem; color: var(--gray-300); margin-bottom: 1rem;"></i>
                            <p style="color: var(--gray-500);">Nenhum usuário encontrado</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status do Sistema -->
            <div class="card" style="margin-top: 2rem;">
                <div class="card-header">
                    <h3><i class="fas fa-server"></i> Status do Sistema</h3>
                </div>
                <div class="card-body">
                    <div class="system-status-item">
                        <span>Servidor Web</span>
                        <div class="status-indicator">
                            <div class="status-dot"></div>
                            <span style="color: var(--success); font-weight: 600;">Online</span>
                        </div>
                    </div>
                    <div class="system-status-item">
                        <span>Banco de Dados</span>
                        <div class="status-indicator">
                            <div class="status-dot"></div>
                            <span style="color: var(--success); font-weight: 600;">Conectado</span>
                        </div>
                    </div>
                    <div class="system-status-item">
                        <span>Armazenamento</span>
                        <div class="status-indicator">
                            <div class="status-dot"></div>
                            <span style="color: var(--success); font-weight: 600;">85% Livre</span>
                        </div>
                    </div>
                    <div class="system-status-item">
                        <span>Última Atualização</span>
                        <span style="color: var(--gray-600); font-weight: 500; font-size: 0.875rem;">
                            <?= date('d/m/Y H:i') ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Toggle do menu mobile
    document.getElementById('menuToggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('active');
    });

    // Fechar menu ao clicar fora (mobile)
    document.addEventListener('click', function(event) {
        const sidebar = document.querySelector('.sidebar');
        const menuToggle = document.getElementById('menuToggle');
        
        if (window.innerWidth <= 768 && 
            !sidebar.contains(event.target) && 
            !menuToggle.contains(event.target)) {
            sidebar.classList.remove('active');
        }
    });

    // Atualizar título dinamicamente
    document.addEventListener('DOMContentLoaded', function() {
        const hour = new Date().getHours();
        const greeting = hour < 12 ? 'Bom dia' : hour < 18 ? 'Boa tarde' : 'Boa noite';
        
        // Atualizar subtítulo se houver elemento
        const subtitle = document.querySelector('.page-title p');
        if (subtitle) {
            subtitle.textContent = `${greeting}, ${'<?= $_SESSION['usuario_nome'] ?>'}! Bem-vindo ao painel.`;
        }
    });

    // Notificações
    document.getElementById('notificationsBtn').addEventListener('click', function() {
        alert('Sistema de notificações em desenvolvimento!');
    });

    // Auto-refresh stats
    setInterval(() => {
        console.log('Atualizando estatísticas...');
        // Implementar AJAX para atualizar stats se necessário
    }, 300000); // 5 minutos
</script>

</body>
</html>