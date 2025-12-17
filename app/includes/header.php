<?php
ob_start();

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    // Verifica se o arquivo existe antes de incluir para evitar erro fatal se a estrutura de pastas for diferente
    if (file_exists(__DIR__ . '/../app/controllers/AuthController.php')) {
        require_once __DIR__ . '/../app/controllers/AuthController.php';
    }
}

if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit();
}

// Buscar permissões do usuário atual
$userRole = strtolower($_SESSION['usuario_tipo'] ?? 'operador');
$userPermissions = [];

if ($userRole !== 'cia_dev') {
    if (file_exists(__DIR__ . '/../helpers/SimpleAuth.php')) {
        require_once __DIR__ . '/../helpers/SimpleAuth.php';
        if (function_exists('getUserPermissionsFromDB')) {
            $userPermissions = getUserPermissionsFromDB($userRole);
        }
    }
}


// ===============================================
// CONFIGURAÇÃO E CONEXÃO COM BANCO
// ===============================================

// Configurações do banco
$host = 'localhost';
$port = 3306;
$db   = 'agrodash';
$user = 'root';
$pass = '';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log("Erro de conexão no header: " . $e->getMessage());
}

// ===============================================
// 📥 PROCESSAMENTO DO FEEDBACK (ADICIONADO)
// ===============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_feedback'])) {
    try {
        $tipo = $_POST['tipo_feedback'];
        $msg = trim($_POST['mensagem_feedback']);
        $anonimo = isset($_POST['anonimo']);
        // Tenta pegar a página atual, fallback para URI
        $pagina = $_POST['pagina_atual'] ?? $_SERVER['REQUEST_URI'];
        
        // Se for anônimo, salva NULL no ID
        $uid = $anonimo ? null : $_SESSION['usuario_id'];

        if (!empty($msg) && isset($pdo)) {
            $stmt = $pdo->prepare("INSERT INTO feedbacks (usuario_id, tipo, mensagem, pagina_origem) VALUES (?, ?, ?, ?)");
            $stmt->execute([$uid, $tipo, $msg, $pagina]);
            $_SESSION['feedback_sucesso'] = true;
        }
        // Recarrega para limpar o POST
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } catch (Exception $e) {
        error_log("Erro ao salvar feedback: " . $e->getMessage());
    }
}

// ===============================================
// SISTEMA DE PERMISSÕES
// ===============================================

// Função para verificar permissões no banco
function userCan($permissionKey, $userRole, $pdo) {
    // Se for cia_dev, tem acesso total
    if ($userRole === 'cia_dev') {
        return true;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT can_access FROM role_permissions 
            WHERE role_name = ? AND permission_key = ?
        ");
        $stmt->execute([$userRole, $permissionKey]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result && (bool)$result['can_access'];
    } catch (Exception $e) {
        error_log("Erro ao verificar permissão [$userRole, $permissionKey]: " . $e->getMessage());
        return false;
    }
}

// ===============================================
// Definição das variáveis de usuário e badges
// ===============================================
$usuario_nome = htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário');
$usuario_tipo_raw = $userRole;

$labelsTipo = [
    'operador' => 'Operador',
    'coordenador' => 'Coordenador',
    'admin' => 'Administrador',
    'cia_user' => 'CIA — Usuário',
    'cia_admin' => 'CIA — Admin',
    'cia_dev' => 'CIA — Dev'
];
$usuario_cargo = $labelsTipo[$usuario_tipo_raw] ?? 'Desconhecido';

$badges = [];
if (in_array($usuario_tipo_raw, ['admin', 'cia_admin'])) {
    $badges[] = [
        'title' => 'Administrador',
        'icon' => 'fas fa-shield-alt',
        'color' => '#f39c12'
    ];
}
if ($usuario_tipo_raw === 'cia_user') {
    $badges[] = [
        'title' => 'Usuário',
        'icon' => 'fas fa-star',
        'color' => '#3498db'
    ];
}

if ($usuario_tipo_raw === 'cia_dev') {
    $badges[] = [
        'title' => 'Desenvolvedor/CEO',
        'icon' => 'fa-solid fa-web-awesome',
        'color' => '#ffef11ff'
    ];

    $badges[] = [
        'title' => 'Desenvolvedor',
        'icon' => 'fa-solid fa-code',
        'color' => '#00bfff'
    ];
    
}

$pagina_atual = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agro-Dash Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">
    <link rel="stylesheet" href="/public/static/css/header.css">
    <link rel="stylesheet" href="/public/static/css/style.css">
    <link rel="icon" type="image/x-icon" href="/public/static/favicon.ico">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</head>
<body>
    <div class="header">
        <button class="menu-toggle d-lg-none">
            <div class="icon-container">
                <i class="fas fa-bars"></i>
                <i class="fas fa-times"></i>
            </div>
        </button>
        <div class="logo">
            <i class="fas fa-leaf"></i>Agro-Access Admin
        </div>
        <div class="header-shortcuts">
            <a href="/dashboard" class="shortcut-link">
                <i class="fas fa-tachometer-alt"></i>
                <small>Dashboard</small>
            </a>
            <?php if (userCan('comparativo:view', $userRole, $pdo)): ?>
            <a href="/admin_fleet" class="shortcut-link">
                <i class="fa-regular fa-truck"></i>
                <small>CT Frotas</small>
            </a>
            <?php endif; ?>
            <?php if (userCan('users:crud', $userRole, $pdo)): ?>
            <a href="/admin_users" class="shortcut-link">
                <i class="fas fa-users-cog"></i>
                <small>Usuários</small>
            </a>
            <?php endif; ?>
            <?php if (userCan('atalhos:edit', $userRole, $pdo)): ?>
            <a href="/admin_atalhos" class="shortcut-link">
                <i class="fa-solid fa-share-from-square"></i>
                <small>Atalhos</small>
            </a>
            <?php endif; ?>
        </div>
    <div class="logout-container">
        <a href="/logout" class="logout-btn" onclick="return confirm('Tem certeza que deseja sair?')">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
    </div>
    
    <div class="mobile-overlay"></div>
    
    <div class="main-content">
        <div class="sidebar">
            <div class="sidebar-nav">
                <?php if (userCan('users:crud', $userRole, $pdo) || userCan('equip:crud', $userRole, $pdo) || userCan('fazendas:crud', $userRole, $pdo) || userCan('metas:view', $userRole, $pdo)): ?>
                <div class="has-submenu">👨‍💼 Gestão <span class="arrow"></span></div>
                <div class="submenu">
                    <?php if (userCan('users:crud', $userRole, $pdo)): ?>
                    <a href="admin_users" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_users.php' ? 'active' : ''; ?>">👥 Gestão de Usuários</a>
                    <?php endif; ?>
                    
                    <?php if (userCan('equip:crud', $userRole, $pdo)): ?>
                    <a href="admin_fleet" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_fleet.php' ? 'active' : ''; ?>">🚜 Gestão de Frota</a>
                    <?php endif; ?>
                    
                    <?php if (userCan('fazendas:crud', $userRole, $pdo)): ?>
                    <a href="fazendas" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_farms.php' ? 'active' : ''; ?>">🌾 Gestão Fda/Uni</a>
                    <?php endif; ?>
                    
                    <?php if (userCan('metas:view', $userRole, $pdo)): ?>
                    <a href="metas" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_metas.php' ? 'active' : ''; ?>">🎯 Gestão de Metas</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (userCan('comparativo:view', $userRole, $pdo) || userCan('consumo:view', $userRole, $pdo) || userCan('apontamentos:view', $userRole, $pdo) || userCan('visaogeral:view', $userRole, $pdo)): ?>
                <div class="has-submenu">📊 Relatórios <span class="arrow"></span></div>
                <div class="submenu">
                    <?php if (userCan('comparativo:view', $userRole, $pdo)): ?>
                    <a href="comparativo" class="<?php echo basename($_SERVER['PHP_SELF']) == 'comparativo.php' ? 'active' : ''; ?>">📈 Comp. de Produção</a>
                    <?php endif; ?>
                    
                    <?php if (userCan('consumo:view', $userRole, $pdo)): ?>
                    <a href="consumo" class="<?php echo basename($_SERVER['PHP_SELF']) == 'consumo.php' ? 'active' : ''; ?>">⛽ Con., Vel. & RPM</a>
                    <a href="consumo_equip" class="<?php echo basename($_SERVER['PHP_SELF']) == 'consumo_equip.php' ? 'active' : ''; ?>">⛽ Comp. Consumo</a>
                    <a href="horasoperacionais" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_consumo.php' ? 'active' : ''; ?>">⏱ Horas Operacionais</a>
                    <?php endif; ?>
                    
                    <?php if (userCan('apontamentos:view', $userRole, $pdo)): ?>
                    <a href="admin_dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : ''; ?>">💻 Apontamentos</a>
                    <?php endif; ?>
                    
                    <?php if (userCan('visaogeral:view', $userRole, $pdo)): ?>
                    <a href="visaogeral" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_rela.php' ? 'active' : ''; ?>">👁‍🗨 Visão Geral</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (userCan('user_dashboard:view', $userRole, $pdo)): ?>
                <div class="has-submenu">📈 CIA Dashboards <span class="arrow"></span></div>
                <div class="submenu">
                    <a href="dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">💻 Dash CIA</a>
                    <?php if (userCan('user_dashboard:view', $userRole, $pdo)): ?>
                    <a href="user_dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'user_dashboard.php' ? 'active' : ''; ?>">🧑‍🌾 Dash User</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (userCan('audit:view', $userRole, $pdo)): // Usando permissão genérica ou específica se houver ?>
                <div class="has-submenu">🚜 CTT - Colheita <span class="arrow"></span></div>
                <div class="submenu">
                    <a href="/ctt" class="<?php echo basename($_SERVER['PHP_SELF'])=='ctt.php'?'active':''; ?>">📈 Aponta CTT</a>
                </div>
                <?php endif; ?>

                <?php if (userCan('audit:view', $userRole, $pdo)): ?>
                <div class="has-submenu">👑 DEV Section <span class="arrow"></span></div>
                <div class="submenu">
                    <a href="/permissions" class="<?php echo basename($_SERVER['PHP_SELF'])=='permissions.php'?'active':''; ?>">🚫 Permissões</a>
                    <a href="/configuracoes" class="<?php echo basename($_SERVER['PHP_SELF'])=='config.php'?'active':''; ?>">🚫 Configurações</a>
                    <a href="/audit_logs" class="<?php echo basename($_SERVER['PHP_SELF'])=='audit_logs.php'?'active':''; ?>">📃 Logs de Auditoria</a>
                    <a href="/aponta" class="<?php echo basename($_SERVER['PHP_SELF'])=='apontamentos.php'?'active':''; ?>">🚜 APONTA PPT</a>
                    <a href="/ctt" class="<?php echo basename($_SERVER['PHP_SELF'])=='ctt.php'?'active':''; ?>">👩🏻‍🌾 APONTA CTT</a>
                    
                    <a href="/admin_feedbacks" class="<?php echo basename($_SERVER['PHP_SELF'])=='admin_feedbacks.php'?'active':''; ?>">📥 Ler Feedbacks</a>
                </div>
                <?php endif; ?>
                
                <a href="#" data-bs-toggle="modal" data-bs-target="#modalFeedback" class="text-warning mt-3 d-block ps-3">
                    <i class="fas fa-bullhorn me-2"></i> Enviar Feedback
                </a>
            </div>
            
            <div class="sidebar-profile-footer">
                <div class="profile-picture-footer">
                    <img src="/public/static/img/default-avatar.png" alt="Foto de Perfil">
                </div>
                <div class="profile-info-footer">
                    <div class="profile-name-footer"><?php echo $usuario_nome; ?></div>
                    <div class="profile-role-footer"><?php echo $usuario_cargo; ?></div>
                    <div class="badges-container-footer">
                        <?php if (!empty($badges)): ?>
                            <?php foreach($badges as $badge): ?>
                                <i class="badge-icon <?php echo $badge['icon']; ?>" 
                                   style="color: <?php echo $badge['color']; ?>;" 
                                   title="<?php echo $badge['title']; ?>">
                                </i>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <div class="modal fade" id="modalFeedback" tabindex="-1" aria-hidden="true" style="z-index: 9999;">
        <div class="modal-dialog">
            <div class="modal-content text-dark">
                <form method="POST">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title"><i class="fas fa-bullhorn me-2"></i>Enviar Feedback</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body">
                        <input type="hidden" name="acao_feedback" value="1">
                        <input type="hidden" name="pagina_atual" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Tipo:</label>
                            <select name="tipo_feedback" class="form-select" required>
                                <option value="sugestao">💡 Sugestão</option>
                                <option value="bug">🐛 Reportar Erro</option>
                                <option value="critica">⚠️ Crítica</option>
                                <option value="elogio">👏 Elogio</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Mensagem:</label>
                            <textarea name="mensagem_feedback" class="form-control" rows="4" placeholder="Descreva aqui sua opinião, sugestão ou o erro que encontrou..." required></textarea>
                        </div>

                        <div class="form-check form-switch bg-light p-2 rounded">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="anonimo" id="checkAnonimo">
                            <label class="form-check-label fw-bold" for="checkAnonimo">Enviar Anônimo</label>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Enviar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['feedback_sucesso'])): ?>
    <div class="position-fixed top-0 end-0 p-3" style="z-index: 11000">
        <div class="toast show align-items-center text-white bg-success border-0 shadow-lg">
            <div class="d-flex">
                <div class="toast-body fs-6">
                    <i class="fas fa-check-circle me-2"></i> Feedback enviado com sucesso!
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>
</div>
<script>
    setTimeout(() => { document.querySelector('.toast')?.remove(); }, 4000);
</script>
<?php unset($_SESSION['feedback_sucesso']); endif; ?>

<script>
// SISTEMA DE MENU MOBILE
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const mobileOverlay = document.querySelector('.mobile-overlay');
    const submenuHeaders = document.querySelectorAll('.has-submenu');
    
    // Toggle do menu hamburger
    if (menuToggle) {
        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            this.classList.toggle('active');
            sidebar.classList.toggle('active');
            
            if (mobileOverlay) {
                mobileOverlay.classList.toggle('active');
            }
            
            // Bloqueia scroll do body quando menu aberto
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        });
    }
    
    // Fechar menu ao clicar no overlay
    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', function() {
            closeMobileMenu();
        });
    }
    
    // Fechar menu ao pressionar ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMobileMenu();
        }
    });
    
    function closeMobileMenu() {
        if (menuToggle) menuToggle.classList.remove('active');
        if (sidebar) sidebar.classList.remove('active');
        if (mobileOverlay) mobileOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }
    
    // SISTEMA DE SUBMENUS - CORRIGIDO
    function saveSubmenuState() {
        const state = {};
        submenuHeaders.forEach((item, index) => {
            state[index] = item.classList.contains('open');
        });
        localStorage.setItem('submenuState', JSON.stringify(state));
    }
    
    function restoreSubmenuState() {
        const state = JSON.parse(localStorage.getItem('submenuState') || '{}');
        submenuHeaders.forEach((item, index) => {
            if (state[index]) {
                item.classList.add('open');
                const submenu = item.nextElementSibling;
                if (submenu && submenu.classList.contains('submenu')) {
                    // Adiciona transição para a animação
                    submenu.style.transition = 'max-height 0.4s ease';
                    // Usa requestAnimationFrame para garantir que o DOM esteja pronto
                    requestAnimationFrame(() => {
                        submenu.style.maxHeight = submenu.scrollHeight + 'px';
                    });
                }
            }
        });
    }
    
    // Eventos dos submenus - CORRIGIDO
    submenuHeaders.forEach((item, index) => {
        const submenu = item.nextElementSibling;
        
        if (!submenu || !submenu.classList.contains('submenu')) return;
        
        item.addEventListener('click', function(e) {
            // Impedir que o clique feche o menu mobile
            e.stopPropagation();
            e.preventDefault();
            
            // Garantir que o submenu tenha transição
            submenu.style.transition = 'max-height 0.4s ease';
            
            const isOpening = !this.classList.contains('open');
            
            // Fechar outros submenus se estiver abrindo este
            if (isOpening) {
                submenuHeaders.forEach(otherItem => {
                    if (otherItem !== this && otherItem.classList.contains('open')) {
                        const otherSubmenu = otherItem.nextElementSibling;
                        otherItem.classList.remove('open');
                        if (otherSubmenu && otherSubmenu.classList.contains('submenu')) {
                            otherSubmenu.style.maxHeight = '0';
                        }
                    }
                });
            }
            
            // Alternar estado atual
            this.classList.toggle('open');
            
            // Animar altura
            if (this.classList.contains('open')) {
                submenu.style.maxHeight = submenu.scrollHeight + 'px';
            } else {
                submenu.style.maxHeight = '0';
            }
            
            saveSubmenuState();
        });
    });
    
    // Restaurar estado ao carregar
    restoreSubmenuState();
    
    // Fechar menu ao clicar fora em mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 991 && 
            sidebar.classList.contains('active') && 
            !sidebar.contains(e.target) && 
            e.target !== menuToggle) {
            closeMobileMenu();
        }
    });
    
    // Ajustar altura dos submenus quando a janela é redimensionada
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            submenuHeaders.forEach(item => {
                if (item.classList.contains('open')) {
                    const submenu = item.nextElementSibling;
                    if (submenu && submenu.classList.contains('submenu')) {
                        submenu.style.maxHeight = submenu.scrollHeight + 'px';
                    }
                }
            });
        }, 250);
    });
});

// Função para confirmar logout

// Sistema de proteção de páginas - verifica se usuário tem acesso à página atual
(function(){
    // Mapeamento de páginas para permissões necessárias
    const pagePermissions = {
        'admin_users.php': 'users:crud',
        'admin_fleet.php': 'equip:crud',
        'admin_farms.php': 'fazendas:crud',
        'admin_metas.php': 'metas:view',
        'comparativo.php': 'comparativo:view',
        'consumo.php': 'consumo:view',
        'consumo_equip.php': 'consumo:view',
        'admin_consumo.php': 'consumo:view',
        'admin_dashboard.php': 'apontamentos:view',
        'admin_rela.php': 'visaogeral:view',
        'user_dashboard.php': 'user_dashboard:view',
        'audit_logs.php': 'audit:view',
        'aponta.php': 'audit:view',
        'ctt.php': 'audit:view',
        'admin_atalhos.php' : 'atalhos:edit',
        'admin_feedbacks.php' : 'audit:view' // Apenas devs acessam feedbacks
    };

    function checkPageAccess() {
        const currentPage = window.location.pathname.split('/').pop();
        const requiredPermission = pagePermissions[currentPage];
        
        if (requiredPermission) {
            // Verificar se o link correspondente está visível
            const link = document.querySelector(`a[href*="${currentPage}"]`);
            if (!link || link.offsetParent === null) {
                show403Toast('❌ Acesso negado: Você não tem permissão para acessar esta página.');
                setTimeout(() => {
                    window.location.href = '/dashboard';
                }, 3000);
            }
        }
    }

    // Verificar acesso quando a página carregar
    setTimeout(checkPageAccess, 100);
})();

// Toast no TOPO central
window.show403Toast = (msg) => {
    const t = document.createElement('div');
    t.className = 'toast-403';
    t.textContent = msg;
    document.body.appendChild(t);
    // animação
    requestAnimationFrame(()=> t.classList.add('show'));
    setTimeout(()=> { t.classList.remove('show'); setTimeout(()=>t.remove(), 200); }, 2400);
};



// Função para ajustar dinamicamente a altura dos submenus
function adjustSubmenuHeight(submenu) {
    const maxSubmenuHeight = 250; // Altura máxima em pixels
    const actualHeight = submenu.scrollHeight;
    
    // Se o conteúdo for maior que a altura máxima, usar altura máxima com scroll
    if (actualHeight > maxSubmenuHeight) {
        submenu.style.maxHeight = maxSubmenuHeight + 'px';
        submenu.style.overflowY = 'auto';
    } else {
        submenu.style.maxHeight = actualHeight + 'px';
        submenu.style.overflowY = 'hidden';
    }
}

// E modifique a função openSubmenu para usar essa função:
function openSubmenu(header, submenu) {
    header.classList.add('open');
    adjustSubmenuHeight(submenu);
    submenu.style.opacity = '1';
    submenu.style.visibility = 'visible';
    console.log('Abrindo submenu, altura:', submenu.scrollHeight);
}


</script>

<style>
.toast-403 {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%) translateY(-100px);
    background: #e74c3c;
    color: white;
    padding: 12px 24px;
    border-radius: 6px;
    z-index: 10000;
    transition: transform 0.3s ease;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    font-weight: 500;
}

.toast-403.show {
    transform: translateX(-50%) translateY(0);
}

/* Estilo para links desabilitados por permissão */
a[style*="display: none"] {
    display: none !important;
}

/* Melhorias na seta dos submenus */
.arrow {
    font-size: 10px;
    opacity: 0.7;
    transition: transform 0.3s ease;
}

.has-submenu.open .arrow {
    transform: rotate(180deg);
}
</style>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>