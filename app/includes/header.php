<?php
ob_start();

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    require_once __DIR__ . '/../app/controllers/AuthController.php';
}

if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit();
}

// Buscar permissões do usuário atual
$userRole = strtolower($_SESSION['usuario_tipo'] ?? 'operador');
$userPermissions = [];

if ($userRole !== 'cia_dev') {
    require_once __DIR__ . '/../helpers/SimpleAuth.php';
    $userPermissions = getUserPermissionsFromDB($userRole);
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
// SISTEMA DE PERMISSÕES
// ===============================================

$userRole = strtolower($_SESSION['usuario_tipo'] ?? 'operador');

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
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">
    <link rel="stylesheet" href="/public/static/css/header.css">
    <link rel="stylesheet" href="/public/static/css/style.css">
    <link rel="icon" type="image/x-icon" href="/public/static/favicon.ico">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="/public/static/js/scriptheader.js"></script>
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
            <i class="fas fa-leaf"></i>Agro-Dash Admin
        </div>
        <div class="header-shortcuts">
            <a href="/dashboard" class="shortcut-link">
                <i class="fas fa-tachometer-alt"></i>
                <small>Dashboard</small>
            </a>
            <?php if (userCan('comparativo:view', $userRole, $pdo)): ?>
            <a href="/relatorios_bi" class="shortcut-link">
                <i class="fas fa-chart-bar"></i>
                <small>Relatórios</small>
            </a>
            <?php endif; ?>
            <?php if (userCan('users:crud', $userRole, $pdo)): ?>
            <a href="/admin_users" class="shortcut-link">
                <i class="fas fa-users-cog"></i>
                <small>Usuários</small>
            </a>
            <?php endif; ?>
        </div>
        <!-- Alterado para usar logout.php -->
        <a href="/logout" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
    
    <div class="main-content">
        <div class="sidebar">
            <div class="sidebar-nav">
                <!-- Gestão -->
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

                <!-- Relatórios -->
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

                <!-- CIA Dashboards -->
                <?php if (userCan('user_dashboard:view', $userRole, $pdo)): ?>
                <div class="has-submenu">📈 CIA Dashboards <span class="arrow"></span></div>
                <div class="submenu">
                    <a href="dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">💻 Dash CIA</a>
                    <?php if (userCan('user_dashboard:view', $userRole, $pdo)): ?>
                    <a href="user_dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'user_dashboard.php' ? 'active' : ''; ?>">🧑‍🌾 Dash User</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- CTT - Colheita -->
                <?php if (userCan('audit:view', $userRole, $pdo)): ?>
                <div class="has-submenu">🚜 CTT - Colheita <span class="arrow"></span></div>
                <div class="submenu">
                    <a href="/ctt" class="<?php echo basename($_SERVER['PHP_SELF'])=='ctt.php'?'active':''; ?>">📈 Aponta CTT</a>
                </div>
                <?php endif; ?>

                <!-- DEV Section -->
                <?php if (userCan('audit:view', $userRole, $pdo)): ?>
                <div class="has-submenu">👑 DEV Section <span class="arrow"></span></div>
                <div class="submenu">
                    <a href="/permissions" class="<?php echo basename($_SERVER['PHP_SELF'])=='permissions.php'?'active':''; ?>">🚫 Permissões</a>
                    <a href="/configuracoes" class="<?php echo basename($_SERVER['PHP_SELF'])=='config.php'?'active':''; ?>">🚫 Configurações</a>
                    <a href="/audit_logs" class="<?php echo basename($_SERVER['PHP_SELF'])=='audit_logs.php'?'active':''; ?>">📃 Logs de Auditoria</a>
                    <a href="/aponta" class="<?php echo basename($_SERVER['PHP_SELF'])=='apontamentos.php'?'active':''; ?>">🚜 APONTA PPT</a>
                    <a href="/ctt" class="<?php echo basename($_SERVER['PHP_SELF'])=='ctt.php'?'active':''; ?>">👩🏻‍🌾 APONTA CTT</a>
                </div>
                <?php endif; ?>
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

<script>
// Função de confirmação de logout
function confirmLogout() {
    return confirm('Tem certeza que deseja sair?');
}

document.addEventListener('DOMContentLoaded', function() {
    const submenus = document.querySelectorAll('.has-submenu');

    function saveState() {
        const state = {};
        submenus.forEach((item, index) => {
            state[index] = item.classList.contains('open');
        });
        localStorage.setItem('submenuState', JSON.stringify(state));
    }

    function restoreState() {
        const state = JSON.parse(localStorage.getItem('submenuState') || '{}');
        submenus.forEach((item, index) => {
            if(state[index]) {
                item.classList.add('open');
                const submenu = item.nextElementSibling;
                if (submenu && submenu.classList.contains('submenu')) {
                    // Sem transição no carregamento
                    submenu.style.maxHeight = submenu.scrollHeight + 'px';
                }
            }
        });
    }

    submenus.forEach(item => {
        const submenu = item.nextElementSibling;
        
        // Adiciona o evento de clique para abrir/fechar e salvar
        item.addEventListener('click', () => {
            // Adiciona a classe de transição somente no clique
            if (submenu) {
                submenu.classList.add('submenu-animated');
            }
            
            item.classList.toggle('open');
            
            if (submenu && submenu.classList.contains('submenu')) {
                if (item.classList.contains('open')) {
                    submenu.style.maxHeight = submenu.scrollHeight + 'px';
                } else {
                    submenu.style.maxHeight = '0';
                }
            }
            
            saveState();
        });
    });

    restoreState();
});

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
        'ctt.php': 'audit:view'
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
</style>
</body>
</html>