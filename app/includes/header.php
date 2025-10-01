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

// ===============================================
// Definição das variáveis de usuário e badges
// ===============================================
$usuario_nome = htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário');
$usuario_tipo_raw = strtolower($_SESSION['usuario_tipo'] ?? 'desconhecido');

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
        <button class="menu-toggle d-lg-none"><i class="fas fa-bars"></i></button>
        <div class="logo">
            <i class="fas fa-leaf"></i>Agro-Dash Admin
        </div>
        <div class="header-shortcuts">
            <a href="/dashboard" class="shortcut-link">
                <i class="fas fa-tachometer-alt"></i>
                <small>Dashboard</small>
            </a>
            <a href="/relatorios_bi" class="shortcut-link">
                <i class="fas fa-chart-bar"></i>
                <small>Relatórios</small>
            </a>
            <a href="/admin_users" data-cap="users:crud" class="shortcut-link">
                <i class="fas fa-users-cog"></i>
                <small>Usuários</small>
            </a>
        </div>
        <a href="/login" class="logout-btn"><i class="fas fa-sign-out-alt"></i></a>
    </div>
    
    <div class="main-content">
        <div class="sidebar">
            <div class="sidebar-nav">
                <div class="has-submenu">👨‍💼 Gestão <span class="arrow"></span></div>
                <div class="submenu">
                    <a href="admin_users" data-cap="users:crud" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_users.php' ? 'active' : ''; ?>">👥 Gestão de Usuários</a>
                    <a href="admin_fleet" data-cap="equip:crud" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_fleet.php' ? 'active' : ''; ?>">🚜 Gestão de Frota</a>
                    <a href="fazendas" data-cap="fazendas:crud" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_farms.php' ? 'active' : ''; ?>">🌾 Gestão Fda/Uni</a>
                    <a href="metas" data-cap="metas:view" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_metas.php' ? 'active' : ''; ?>">🎯 Gestão de Metas</a>
                </div>
                <div class="has-submenu">📊 Relatórios <span class="arrow"></span></div>
                <div class="submenu">
                    <a href="comparativo" data-cap="comparativo:view" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_comparativo.php' ? 'active' : ''; ?>">📈 Comparativo de Produção</a>
                    <a href="consumo" data-cap="consumo:view" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_consumo.php' ? 'active' : ''; ?>">⛽ Consumo, Vel. & RPM</a>
                    <a href="admin_dashboard" data-cap="apontamentos:view" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : ''; ?>">💻 Apontamentos</a>
                </div>
                <div class="has-submenu">📈 CIA Dashboards <span class="arrow"></span></div>
                <div class="submenu">
                    <a href="dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">💻 Dash CIA</a>
                    <a href="user_dashboard" data-cap="user_dashboard:view" class="<?php echo basename($_SERVER['PHP_SELF']) == 'user_dashboard.php' ? 'active' : ''; ?>">🧑‍🌾 Dash User</a>
                </div>

                <div class="has-submenu">🚜 CTT - Colheita <span class="arrow"></span></div>
                <div class="submenu">
                    <a href="/ctt" data-cap="audit:view" class="<?php echo basename($_SERVER['PHP_SELF'])=='audit_logs.php'?'active':''; ?>">📈 Aponta CTT</a>
            </div>


                <div class="has-submenu">👑 DEV Section <span class="arrow"></span></div>
                <div class="submenu">
                    <a href="/audit_logs" data-cap="audit:view" class="<?php echo basename($_SERVER['PHP_SELF'])=='audit_logs.php'?'active':''; ?>">📃 Logs de Auditoria</a>
                    <a href="/aponta" data-cap="audit:view" class="<?php echo basename($_SERVER['PHP_SELF'])=='audit_logs.php'?'active':''; ?>">📃 APONTA PPT</a>
                    <a href="/ctt" data-cap="audit:view" class="<?php echo basename($_SERVER['PHP_SELF'])=='audit_logs.php'?'active':''; ?>">📃 APONTA CTT</a>
                </div>
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
        <div class="container"> </div>
    </body>

<?php
  $role = strtolower($_SESSION['usuario_tipo'] ?? '');

  // Lista única de capabilities usadas nas telas/menus
  $ALL_CAPS = [
    'dashboard:view','admin:menu','admin:pages','users:crud', 'equip:crud',
    'fazendas:crud', 'metas:view','metas:edit','admin_dashboard:view', 'apontamentos:view','user_dashboard:view', 'audit:view', 'consumo:view'
  ];

  $caps = [];
  if (function_exists('can')) {
    foreach ($ALL_CAPS as $c) if (can($c)) $caps[] = $c;
  } else {
    if ($role === 'cia_dev') {
      $caps = ['*']; // acesso total
    } elseif ($role === 'admin') {
      $caps = ['dashboard:view','admin:menu','admin:pages','users:crud','fazendas:crud','equip:crud','comparativo:view','metas:view','metas:edit', 'apontamentos:view', 'audit:view', 'consumo:view'];
    } elseif ($role === 'cia_admin') {
      $caps = ['dashboard:view','admin:menu','users:view','fazendas:crud','equip:crud','comparativo:view','metas:view', 'apontamentos:view', 'consumo:view'];
    } elseif ($role === 'cia_user') {
      $caps = ['dashboard:view','comparativo:view', 'consumo:view'];
    } elseif ($role === 'operador') {
      $caps = ['user_dashboard:view'];
    } else {
      $caps = [];
    }
  }
?>

<script>
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

  window.ROLE = <?= json_encode($role) ?>;
  window.CAPS = <?= json_encode($caps, JSON_UNESCAPED_SLASHES) ?>;

(function(){
  const ROLE = (window.ROLE || '').toLowerCase();
  const CAPS = Array.isArray(window.CAPS) ? window.CAPS.map(s => (s||'').toLowerCase().trim()) : [];
  const HAS_ALL = ROLE === 'cia_dev' || CAPS.includes('*');

  const can = (cap) => {
    if (!cap) return true;
    if (HAS_ALL) return true;
    return CAPS.includes((cap||'').toLowerCase().trim());
  };

  document.querySelectorAll('a[data-cap]').forEach(a => {
    const reqRaw = (a.dataset.cap || '').trim();
    // suporta "cap1|cap2" ou "cap1,cap2" (qualquer uma libera)
    const reqCaps = reqRaw.split(/[|,]/).map(s => s.trim()).filter(Boolean);
    const allowed = reqCaps.length ? reqCaps.some(can) : true;

    if (!allowed) {
      a.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        show403Toast('Você não tem permissão para acessar esta área.');
      });
      // opcional: visual desabilitado mas clicável para o aviso
      a.classList.add('is-disabled-click');
    }
  });

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
})();

</script>