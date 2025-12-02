<?php
session_start();




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
    die("Erro de conexão: " . $e->getMessage());
}

// ===============================================
// VERIFICAÇÃO DE PERMISSÃO DO USUÁRIO
// ===============================================

$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
    header("Location: /dashboard");
    exit();
}

// ===============================================
// DEFINIÇÕES DAS PERMISSÕES
// ===============================================

$roles = ['admin', 'cia_admin', 'cia_user', 'operador'];
$categories = [
    'Gestão' => [
        'users:crud' => 'Gestão de Usuários (CRUD)',
        'equip:crud' => 'Gestão de Frota (CRUD)',
        'fazendas:crud' => 'Gestão de Fazendas/Unidades',
        'metas:view' => 'Visualização de Metas',
        'metas:edit' => 'Edição de Metas',
        'atalhos:edit' => 'Edição de Atalhos'
    ],
    'Relatórios' => [
        'consumo:view' => 'Consumo, Velocidade & RPM',
        'apontamentos:view' => 'Visualização de Apontamentos',
        'visaogeral:view' => 'Visão Geral'
    ],
    'Dashboards' => [
        'user_dashboard:view' => 'Dashboard do Usuário',
        'dashboard:view' => 'Dashboard CIA'
    ],
    'Desenvolvimento' => [
        'audit:view' => 'Visualização de Auditoria'
    ]
];

// ===============================================
// FUNÇÕES DO SISTEMA DE PERMISSÕES
// ===============================================

function getAllPermissions($pdo, $roles, $categories) {
    $result = [];
    
    // Inicializar estrutura vazia
    foreach ($roles as $role) {
        $result[$role] = [];
        foreach ($categories as $category => $perms) {
            $result[$role][$category] = [];
            foreach ($perms as $key => $name) {
                $result[$role][$category][$key] = [
                    'name' => $name,
                    'allowed' => false
                ];
            }
        }
    }

    try {
        // Verificar se a tabela existe
        $checkTable = $pdo->query("SHOW TABLES LIKE 'role_permissions'");
        if ($checkTable->rowCount() == 0) {
            createTableAndDefaults($pdo, $roles, $categories);
            return $result;
        }

        // Buscar permissões do banco
        $stmt = $pdo->prepare("
            SELECT role_name, permission_key, can_access 
            FROM role_permissions 
            WHERE role_name IN (?, ?, ?, ?)
        ");
        
        $stmt->execute($roles);
        $dbPermissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Atualizar com dados do banco
        foreach ($dbPermissions as $perm) {
            $role = $perm['role_name'];
            $key = $perm['permission_key'];
            $allowed = (bool)$perm['can_access'];

            // Encontrar a categoria da permissão
            foreach ($categories as $category => $perms) {
                if (isset($perms[$key])) {
                    if (isset($result[$role][$category][$key])) {
                        $result[$role][$category][$key]['allowed'] = $allowed;
                    }
                    break;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao buscar permissões: " . $e->getMessage());
    }

    return $result;
}

function createTableAndDefaults($pdo, $roles, $categories) {
    try {
        // Criar tabela se não existir
        $createTableSQL = "
            CREATE TABLE IF NOT EXISTS role_permissions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                role_name VARCHAR(50) NOT NULL,
                permission_key VARCHAR(100) NOT NULL,
                permission_name VARCHAR(200) NOT NULL,
                category VARCHAR(100) NOT NULL,
                can_access BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_role_permission (role_name, permission_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $pdo->exec($createTableSQL);
        
        // Inserir padrões
        $defaults = getDefaultPermissions();
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO role_permissions (role_name, permission_key, permission_name, category, can_access) 
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($defaults as $default) {
            $stmt->execute($default);
        }
        
    } catch (Exception $e) {
        error_log("Erro ao criar tabela e defaults: " . $e->getMessage());
        throw $e;
    }
}

function getDefaultPermissions() {
    return [
        // Admin - Acesso total
        ['admin', 'dashboard:view', 'DashBoard Principal', 'Gestão', 1],
        ['admin', 'users:crud', 'Gestão de Usuários (CRUD)', 'Gestão', 1],
        ['admin', 'equip:crud', 'Gestão de Frota (CRUD)', 'Gestão', 1],
        ['admin', 'fazendas:crud', 'Gestão de Fazendas/Unidades', 'Gestão', 1],
        ['admin', 'metas:view', 'Visualização de Metas', 'Gestão', 1],
        ['admin', 'metas:edit', 'Edição de Metas', 'Gestão', 1],
        ['admin', 'atalhos:edit', 'Edição de Atalhos', 'Gestão', 1],
        ['admin', 'consumo:view', 'Consumo, Velocidade & RPM', 'Relatórios', 1],
        ['admin', 'apontamentos:view', 'Visualização de Apontamentos', 'Relatórios', 1],
        ['admin', 'visaogeral:view', 'Visão Geral', 'Relatórios', 1],
        ['admin', 'user_dashboard:view', 'Dashboard do Usuário', 'Dashboards', 1],
        ['admin', 'audit:view', 'Visualização de Auditoria', 'Desenvolvimento', 1],
        ['admin', 'dashboard:view', 'Dashboard CIA', 'Dashboards', 1],

        // CIA Admin - Acesso quase total
        
        ['cia_admin', 'users:crud', 'Gestão de Usuários (CRUD)', 'Gestão', 1],
        ['cia_admin', 'equip:crud', 'Gestão de Frota (CRUD)', 'Gestão', 1],
        ['cia_admin', 'fazendas:crud', 'Gestão de Fazendas/Unidades', 'Gestão', 1],
        ['cia_admin', 'metas:view', 'Visualização de Metas', 'Gestão', 1],
        ['cia_admin', 'metas:edit', 'Edição de Metas', 'Gestão', 1],
        ['cia_admin', 'atalhos:edit', 'Edição de Atalhos', 'Gestão', 1],
        ['cia_admin', 'consumo:view', 'Consumo, Velocidade & RPM', 'Relatórios', 1],
        ['cia_admin', 'apontamentos:view', 'Visualização de Apontamentos', 'Relatórios', 1],
        ['cia_admin', 'visaogeral:view', 'Visão Geral', 'Relatórios', 1],
        ['cia_admin', 'user_dashboard:view', 'Dashboard do Usuário', 'Dashboards', 1],
        ['cia_admin', 'dashboard:view', 'Dashboard CIA', 'Dashboards', 1],

        // CIA User - Acesso limitado
        ['cia_user', 'dashboard:view', 'Dashboard CIA', 'Dashboards', 1],
        ['cia_user', 'consumo:view', 'Consumo, Velocidade & RPM', 'Relatórios', 1],
        // Operador - Acesso mínimo
        ['operador', 'user_dashboard:view', 'Dashboard do Usuário', 'Dashboards', 1],
        ['operador', 'dashboard:view', 'Dashboard CIA', 'Dashboards', 1],
    ];
}

function updatePermission($pdo, $role, $permission, $allowed, $categories) {
    try {
        // Verificar se a permissão existe
        $permissionExists = false;
        $permissionName = '';
        $category = '';
        
        foreach ($categories as $cat => $perms) {
            if (isset($perms[$permission])) {
                $permissionExists = true;
                $permissionName = $perms[$permission];
                $category = $cat;
                break;
            }
        }

        if (!$permissionExists) {
            return ['success' => false, 'message' => 'Permissão não encontrada: ' . $permission];
        }

        // Inserir ou atualizar
        $stmt = $pdo->prepare("
            INSERT INTO role_permissions (role_name, permission_key, permission_name, category, can_access) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                can_access = VALUES(can_access),
                updated_at = CURRENT_TIMESTAMP
        ");

        $success = $stmt->execute([$role, $permission, $permissionName, $category, $allowed ? 1 : 0]);

        if ($success) {
            return ['success' => true, 'message' => 'Permissão atualizada com sucesso'];
        } else {
            return ['success' => false, 'message' => 'Erro ao executar query no banco'];
        }

    } catch (Exception $e) {
        error_log("Erro ao atualizar permissão: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()];
    }
}

function resetPermissions($pdo) {
    try {
        // Limpar tabela
        $stmt = $pdo->prepare("TRUNCATE TABLE role_permissions");
        $stmt->execute();

        // Inserir padrões
        $defaults = getDefaultPermissions();
        
        $stmt = $pdo->prepare("
            INSERT INTO role_permissions (role_name, permission_key, permission_name, category, can_access) 
            VALUES (?, ?, ?, ?, ?)
        ");

        $successCount = 0;
        foreach ($defaults as $default) {
            if ($stmt->execute($default)) {
                $successCount++;
            }
        }

        return [
            'success' => true, 
            'message' => 'Permissões resetadas para o padrão (' . $successCount . ' registros)'
        ];

    } catch (Exception $e) {
        error_log("Erro ao resetar permissões: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro: ' . $e->getMessage()];
    }
}

// ===============================================
// PROCESSAMENTO DAS REQUISIÇÕES AJAX
// ===============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'update') {
        $role = $_POST['role'] ?? '';
        $permission = $_POST['permission'] ?? '';
        $allowed = filter_var($_POST['allowed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        
        if (!in_array($role, $roles) || empty($permission)) {
            echo json_encode(['success' => false, 'message' => 'Dados inválidos']);
            exit();
        }
        
        $result = updatePermission($pdo, $role, $permission, $allowed, $categories);
        echo json_encode($result);
        exit();
    }
    
    if ($_POST['action'] === 'reset') {
        $result = resetPermissions($pdo);
        echo json_encode($result);
        exit();
    }
}

// ===============================================
// CARREGAR PERMISSÕES PARA A PÁGINA
// ===============================================

$permissions = getAllPermissions($pdo, $roles, $categories);

$feedback_message = '';
if (isset($_SESSION['feedback_message'])) {
    $feedback_message = $_SESSION['feedback_message'];
    unset($_SESSION['feedback_message']);
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Permissões - Agro-Dash</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="/public/static/favicon.ico">
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">
    <link rel="stylesheet" href="/public/static/css/admin.css">
    <style>
        /* MANTENHA TODO O SEU CSS AQUI - É IDÊNTICO AO QUE VOCÊ JÁ TEM */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;

            color: #333;
        }

        .permissions-container {
            zoom: 80%;

            max-height: 1980px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.15);
            overflow: hidden;
            animation: fadeInUp 0.6s ease-out;
        }

        /* ... (TODO O RESTANTE DO SEU CSS PERMANECE IGUAL) ... */
        
        .permissions-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 50px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .permissions-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 30px 30px;
            animation: float 20s linear infinite;
        }

        @keyframes float {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(-30px, -30px) rotate(360deg); }
        }

        .permissions-header h1 {
            font-size: 2.8rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            position: relative;
            z-index: 2;
            font-weight: 700;
        }

        .permissions-header p {
            font-size: 1.3rem;
            opacity: 0.9;
            position: relative;
            z-index: 2;
            font-weight: 400;
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }

        .controls {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 30px 40px;
            border-bottom: 2px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 25px;
            position: relative;
        }

        .role-selector {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .role-btn {
            padding: 14px 28px;
            border: 2px solid #3498db;
            background: white;
            color: #3498db;
            border-radius: 35px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            position: relative;
            overflow: hidden;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 160px;
            justify-content: center;
        }

        .role-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transition: left 0.6s;
        }

        .role-btn:hover::before {
            left: 100%;
        }

        .role-btn.active {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(52, 152, 219, 0.4);
            border-color: #3498db;
        }

        .role-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(52, 152, 219, 0.3);
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 35px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1rem;
            position: relative;
            overflow: hidden;
            min-width: 160px;
            justify-content: center;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.6s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            box-shadow: 0 8px 25px rgba(39, 174, 96, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            box-shadow: 0 8px 25px rgba(231, 76, 60, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            box-shadow: 0 8px 25px rgba(52, 152, 219, 0.3);
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }

        .btn:active {
            transform: translateY(-1px);
        }

        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 30px;
            padding: 40px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            min-height: 500px;
        }

        .category-card {
            background: white;
            border-radius: 18px;
            border: 1px solid #e9ecef;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            position: relative;
            animation: slideIn 0.6s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .category-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(135deg, #3498db 0%, #9b59b6 100%);
        }

        .category-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .category-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 25px 30px;
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }

        .category-header i {
            font-size: 1.6rem;
            opacity: 0.9;
        }

        .permissions-list {
            padding: 30px;
        }

        .permission-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid #f1f3f4;
            transition: all 0.3s ease;
            position: relative;
        }

        .permission-item:hover {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 12px;
            padding-left: 20px;
            padding-right: 20px;
            margin: 0 -15px;
            transform: translateX(5px);
        }

        .permission-item:last-child {
            border-bottom: none;
        }

        .permission-info {
            flex: 1;
            min-width: 0;
        }

        .permission-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 1.1rem;
            line-height: 1.4;
        }

        .permission-key {
            font-size: 0.85rem;
            color: #7f8c8d;
            font-family: 'Courier New', monospace;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 6px 12px;
            border-radius: 8px;
            display: inline-block;
            border: 1px solid #e9ecef;
            font-weight: 500;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 68px;
            height: 34px;
            flex-shrink: 0;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
            transition: .4s;
            border-radius: 34px;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
        }

        .toggle-slider::before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        input:checked + .toggle-slider {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
        }

        input:checked + .toggle-slider::before {
            transform: translateX(34px);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            padding: 35px 40px;
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
        }

        .stat-card {
            background: rgba(255,255,255,0.1);
            padding: 30px 25px;
            border-radius: 16px;
            text-align: center;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: left 0.6s;
        }

        .stat-card:hover::before {
            left: 100%;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.3);
            background: rgba(255,255,255,0.15);
        }

        .stat-number {
            font-size: 2.8rem;
            font-weight: 800;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #3498db 0%, #9b59b6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
        }

        .stat-label {
            color: #bdc3c7;
            font-size: 1rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .toast {
            position: fixed;
            top: 30px;
            right: 30px;
            padding: 20px 30px;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            z-index: 10000;
            transform: translateX(400px);
            transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            font-size: 1rem;
            max-width: 400px;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast.success {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
        }

        .toast.error {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        }

        .toast.info {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        }

        .loading {
            display: none;
            text-align: center;
            padding: 50px 40px;
            color: #7f8c8d;
            font-size: 1.2rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }

        .loading.show {
            display: block;
        }

        .loading i {
            font-size: 2.5rem;
            margin-bottom: 20px;
            color: #3498db;
        }

        .alert-message {
            margin: 20px 40px;
            padding: 20px 25px;
            border-radius: 12px;
            font-weight: 500;
            border-left: 5px solid;
            animation: slideInRight 0.5s ease-out;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert-message.error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left-color: #e74c3c;
        }

        .alert-message.success {
            background: linear-gradient(135deg, #d1edff 0%, #b6e0fe 100%);
            color: #004085;
            border-left-color: #3498db;
        }

        /* Scroll personalizado */
        ::-webkit-scrollbar {
            width: 10px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #3498db 0%, #9b59b6 100%);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #2980b9 0%, #8e44ad 100%);
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .permissions-grid {
                grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                gap: 25px;
                padding: 30px;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .permissions-container {
                border-radius: 15px;
            }

            .permissions-header {
                padding: 30px 20px;
            }

            .permissions-header h1 {
                font-size: 2.2rem;
                flex-direction: column;
                gap: 15px;
            }

            .controls {
                padding: 25px 20px;
                flex-direction: column;
                align-items: stretch;
                gap: 20px;
            }

            .role-selector {
                justify-content: center;
            }

            .action-buttons {
                justify-content: center;
            }

            .role-btn, .btn {
                min-width: 140px;
                padding: 12px 20px;
                font-size: 0.9rem;
            }

            .permissions-grid {
                grid-template-columns: 1fr;
                padding: 20px;
                gap: 20px;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
                padding: 25px 20px;
                gap: 15px;
            }

            .stat-card {
                padding: 20px 15px;
            }

            .stat-number {
                font-size: 2.2rem;
            }

            .toast {
                right: 20px;
                left: 20px;
                max-width: none;
            }
        }

        @media (max-width: 480px) {
            .permissions-header h1 {
                font-size: 1.8rem;
            }

            .permissions-header p {
                font-size: 1.1rem;
            }

            .role-selector {
                flex-direction: column;
                width: 100%;
            }

            .role-btn, .btn {
                width: 100%;
                min-width: auto;
            }

            .action-buttons {
                flex-direction: column;
                width: 100%;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .category-header {
                padding: 20px 25px;
                font-size: 1.2rem;
            }

            .permissions-list {
                padding: 20px;
            }

            .permission-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
                padding: 15px 0;
            }

            .permission-item:hover {
                flex-direction: column;
                align-items: flex-start;
            }

            .toggle-switch {
                align-self: flex-end;
            }
        }
    </style>
</head>
<body>

<div class="permissions-container">
    <div class="permissions-header">
        <h1>
            <i class="fas fa-shield-alt"></i>
            Gerenciar Permissões do Sistema
        </h1>
        <p>Controle completo de acesso e privilégios por tipo de usuário</p>
    </div>

    <?php if ($feedback_message): ?>
        <div class="alert-message <?php echo strpos($feedback_message, 'Erro') !== false ? 'error' : 'success'; ?>">
            <i class="fas <?php echo strpos($feedback_message, 'Erro') !== false ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?>"></i>
            <?php echo htmlspecialchars($feedback_message); ?>
        </div>
    <?php endif; ?>

    <div class="controls">
        <div class="role-selector">
            <div class="role-btn active" data-role="admin">
                <i class="fas fa-crown"></i> Administrador
            </div>
            <div class="role-btn" data-role="cia_admin">
                <i class="fas fa-user-shield"></i> CIA Admin
            </div>
            <div class="role-btn" data-role="cia_user">
                <i class="fas fa-user"></i> CIA User
            </div>
            <div class="role-btn" data-role="operador">
                <i class="fas fa-user-cog"></i> Operador
            </div>
        </div>
        
        <div class="action-buttons">
            <button class="btn btn-info" onclick="selectAllPermissions()">
                <i class="fas fa-check-double"></i> Selecionar Tudo
            </button>
            <button class="btn btn-primary" onclick="saveAllPermissions()">
                <i class="fas fa-save"></i> Salvar Tudo
            </button>
            <button class="btn btn-secondary" onclick="resetToDefault()">
                <i class="fas fa-undo"></i> Resetar Padrão
            </button>
        </div>
    </div>

    <div class="permissions-grid" id="permissionsGrid">
        <div class="loading-state" style="text-align: center; padding: 60px; color: #7f8c8d;">
            <i class="fas fa-spinner fa-spin fa-2x"></i>
            <p style="margin-top: 15px; font-size: 1.1rem;">Carregando permissões...</p>
        </div>
    </div>

    <div class="stats" id="statsSection">
        <div class="stat-card">
            <div class="stat-number">0</div>
            <div class="stat-label">Total de Permissões</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">0</div>
            <div class="stat-label">Permissões Ativas</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">0%</div>
            <div class="stat-label">Taxa de Ativação</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">0</div>
            <div class="stat-label">Alterações Pendentes</div>
        </div>
    </div>

    <div class="loading" id="loading">
        <i class="fas fa-spinner fa-spin"></i>
        <div>Processando suas alterações...</div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
    let currentRole = 'admin';
    let permissionsData = <?php 
        echo json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    ?>;
    let changedPermissions = new Set();

    console.log('Permissions Data loaded:', permissionsData);

    // Inicializar a página
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded, initializing permissions...');
        
        // Verificar se temos dados antes de inicializar
        if (!permissionsData || Object.keys(permissionsData).length === 0) {
            console.error('No permissions data found');
            showToast('❌ Erro: Nenhum dado de permissão encontrado. Verifique se a tabela role_permissions existe.', 'error');
            document.getElementById('permissionsGrid').innerHTML = `
                <div style="text-align: center; padding: 60px; color: #e74c3c;">
                    <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 20px;"></i>
                    <h3 style="margin-bottom: 15px;">Erro ao Carregar Permissões</h3>
                    <p>Não foi possível carregar as permissões do banco de dados.</p>
                    <p style="font-size: 0.9rem; margin-top: 10px; color: #7f8c8d;">
                        Verifique se a tabela 'role_permissions' existe e possui dados.
                    </p>
                </div>
            `;
            return;
        }
        
        loadRolePermissions('admin');
        setupRoleButtons();
        updateStats();
    });

    // Configurar botões de role
    function setupRoleButtons() {
        document.querySelectorAll('.role-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.role-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentRole = this.dataset.role;
                console.log('Switching to role:', currentRole);
                loadRolePermissions(currentRole);
                showToast(`📊 Carregando permissões para: ${this.textContent.trim()}`, 'info');
            });
        });
    }

    // Carregar permissões para uma role específica
    function loadRolePermissions(role) {
        const grid = document.getElementById('permissionsGrid');
        
        // Verificar se a role existe nos dados
        if (!permissionsData[role]) {
            console.error('Role not found in permissions data:', role);
            grid.innerHTML = `
                <div style="text-align: center; padding: 60px; color: #e74c3c;">
                    <i class="fas fa-exclamation-circle fa-3x" style="margin-bottom: 20px;"></i>
                    <h3>Role Não Encontrada</h3>
                    <p>Nenhuma permissão configurada para: ${role}</p>
                </div>
            `;
            return;
        }
        
        const roleData = permissionsData[role];
        console.log('Loading permissions for role:', role, roleData);
        
        if (!roleData || Object.keys(roleData).length === 0) {
            grid.innerHTML = `
                <div style="text-align: center; padding: 60px; color: #7f8c8d;">
                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 20px;"></i>
                    <h3>Nenhuma Permissão Configurada</h3>
                    <p>Não há permissões definidas para: ${role}</p>
                </div>
            `;
            return;
        }

        let html = '';

        for (const [category, permissions] of Object.entries(roleData)) {
            // Verificar se permissions é um objeto válido
            if (!permissions || typeof permissions !== 'object') {
                console.warn('Invalid permissions for category:', category);
                continue;
            }
            
            const categoryIcon = getCategoryIcon(category);
            const permissionCount = Object.keys(permissions).length;
            const enabledCount = Object.values(permissions).filter(p => p.allowed).length;
            
            html += `
                <div class="category-card">
                    <div class="category-header">
                        ${categoryIcon}
                        ${category}
                        <span style="margin-left: auto; font-size: 0.9rem; opacity: 0.8; font-weight: 500;">
                            ${enabledCount}/${permissionCount} ativas
                        </span>
                    </div>
                    <div class="permissions-list">
            `;

            for (const [key, permission] of Object.entries(permissions)) {
                // Verificar se permission é válido
                if (!permission || typeof permission !== 'object') {
                    console.warn('Invalid permission object:', key);
                    continue;
                }
                
                html += `
                    <div class="permission-item">
                        <div class="permission-info">
                            <div class="permission-name">${permission.name || key}</div>
                            <div class="permission-key">${key}</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" 
                                   data-role="${role}" 
                                   data-permission="${key}"
                                   ${permission.allowed ? 'checked' : ''}
                                   onchange="togglePermission('${role}', '${key}', this.checked)">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                `;
            }

            html += `
                    </div>
                </div>
            `;
        }

        grid.innerHTML = html || `
            <div style="text-align: center; padding: 60px; color: #7f8c8d;">
                <i class="fas fa-search fa-3x" style="margin-bottom: 20px;"></i>
                <h3>Nenhuma Permissão Encontrada</h3>
                <p>Não foi possível carregar as permissões para esta role.</p>
            </div>
        `;
        updateStats();
    }

    // Obter ícone para cada categoria
    function getCategoryIcon(category) {
        const icons = {
            'Gestão': '<i class="fas fa-briefcase"></i>',
            'Relatórios': '<i class="fas fa-chart-bar"></i>',
            'Dashboards': '<i class="fas fa-tachometer-alt"></i>',
            'Desenvolvimento': '<i class="fas fa-code"></i>'
        };
        return icons[category] || '<i class="fas fa-folder"></i>';
    }

    // Alternar permissão individual
    function togglePermission(role, permission, allowed) {
        const key = `${role}-${permission}`;
        
        if (allowed) {
            changedPermissions.add(key);
        } else {
            changedPermissions.delete(key);
        }

        // Atualizar dados locais com verificação de segurança
        const category = getCategoryByPermission(role, permission);
        if (category && permissionsData[role] && permissionsData[role][category] && permissionsData[role][category][permission]) {
            permissionsData[role][category][permission].allowed = allowed;
        }
        
        updateStats();
        savePermission(role, permission, allowed);
    }

    // Selecionar todas as permissões
    function selectAllPermissions() {
        const roleData = permissionsData[currentRole];
        if (!roleData) {
            showToast('❌ Nenhum dado encontrado para esta role', 'error');
            return;
        }
        
        let count = 0;
        
        for (const [category, permissions] of Object.entries(roleData)) {
            for (const [key, permission] of Object.entries(permissions)) {
                if (!permission.allowed) {
                    permission.allowed = true;
                    const toggleKey = `${currentRole}-${key}`;
                    changedPermissions.add(toggleKey);
                    count++;
                }
            }
        }
        
        if (count === 0) {
            showToast('ℹ️ Todas as permissões já estão ativadas', 'info');
            return;
        }
        
        loadRolePermissions(currentRole);
        updateStats();
        showToast(`✅ ${count} permissões ativadas para ${currentRole}`, 'success');
    }

    // Encontrar a categoria de uma permissão
    function getCategoryByPermission(role, permission) {
        if (!permissionsData[role]) return null;
        
        for (const [category, perms] of Object.entries(permissionsData[role])) {
            if (perms && perms[permission]) {
                return category;
            }
        }
        return null;
    }

    // Salvar permissão individual - AGORA USA FORM DATA
    async function savePermission(role, permission, allowed) {
        try {
            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('role', role);
            formData.append('permission', permission);
            formData.append('allowed', allowed);

            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            
            if (result.success) {
                showToast('✅ Permissão salva com sucesso!', 'success');
                // Remover da lista de alterações pendentes
                const key = `${role}-${permission}`;
                changedPermissions.delete(key);
                updateStats();
            } else {
                showToast('❌ Erro: ' + result.message, 'error');
                // Reverter mudança em caso de erro
                const category = getCategoryByPermission(role, permission);
                if (category && permissionsData[role] && permissionsData[role][category] && permissionsData[role][category][permission]) {
                    permissionsData[role][category][permission].allowed = !allowed;
                }
                loadRolePermissions(currentRole);
            }
        } catch (error) {
            showToast('❌ Erro de conexão com o servidor: ' + error.message, 'error');
            // Reverter mudança em caso de erro
            const category = getCategoryByPermission(role, permission);
            if (category && permissionsData[role] && permissionsData[role][category] && permissionsData[role][category][permission]) {
                permissionsData[role][category][permission].allowed = !allowed;
            }
            loadRolePermissions(currentRole);
        }
    }

    // Salvar todas as permissões
    async function saveAllPermissions() {
        if (changedPermissions.size === 0) {
            showToast('ℹ️ Nenhuma alteração pendente para salvar', 'info');
            return;
        }

        showLoading(true);
        
        try {
            let saved = 0;
            let errors = 0;

            for (const key of changedPermissions) {
                const [role, permission] = key.split('-');
                const category = getCategoryByPermission(role, permission);
                const allowed = category && permissionsData[role] && permissionsData[role][category] && permissionsData[role][category][permission] 
                    ? permissionsData[role][category][permission].allowed 
                    : false;
                
                const formData = new FormData();
                formData.append('action', 'update');
                formData.append('role', role);
                formData.append('permission', permission);
                formData.append('allowed', allowed);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success) {
                    saved++;
                } else {
                    errors++;
                    console.error('Erro ao salvar permissão:', result.message);
                }
            }

            showLoading(false);
            
            if (errors === 0) {
                showToast(`✅ Todas as ${saved} permissões foram salvas com sucesso!`, 'success');
                changedPermissions.clear();
                updateStats();
            } else {
                showToast(`⚠️ ${saved} permissões salvas, ${errors} erros encontrados`, 'error');
            }
        } catch (error) {
            showLoading(false);
            showToast('❌ Erro ao salvar permissões: ' + error.message, 'error');
        }
    }

    // Resetar para padrão
    async function resetToDefault() {
        if (!confirm('🚨 ATENÇÃO: Tem certeza que deseja resetar TODAS as permissões para os valores padrão?\n\nEsta ação não pode ser desfeita e afetará todos os usuários do sistema.')) {
            return;
        }

        showLoading(true);

        try {
            const formData = new FormData();
            formData.append('action', 'reset');

            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            
            showLoading(false);
            
            if (result.success) {
                showToast('✅ Permissões resetadas com sucesso! Recarregando...', 'success');
                setTimeout(() => location.reload(), 2000);
            } else {
                showToast('❌ Erro ao resetar: ' + result.message, 'error');
            }
        } catch (error) {
            showLoading(false);
            showToast('❌ Erro de conexão: ' + error.message, 'error');
        }
    }

    // Atualizar estatísticas
    function updateStats() {
        const roleData = permissionsData[currentRole];
        
        // Verificar se roleData existe e é um objeto
        if (!roleData || typeof roleData !== 'object') {
            console.error('Invalid role data for:', currentRole);
            document.getElementById('statsSection').innerHTML = `
                <div class="stat-card">
                    <div class="stat-number" style="color: #e74c3c;">0</div>
                    <div class="stat-label">Erro ao Carregar</div>
                </div>
            `;
            return;
        }
        
        let totalPermissions = 0;
        let enabledPermissions = 0;

        for (const category of Object.values(roleData)) {
            // Verificar se category é um objeto válido
            if (!category || typeof category !== 'object') {
                continue;
            }
            
            for (const permission of Object.values(category)) {
                // Verificar se permission é um objeto válido
                if (permission && typeof permission === 'object') {
                    totalPermissions++;
                    if (permission.allowed) {
                        enabledPermissions++;
                    }
                }
            }
        }

        const percentage = totalPermissions > 0 ? Math.round((enabledPermissions / totalPermissions) * 100) : 0;
        const pendingColor = changedPermissions.size > 0 ? 'color: #e74c3c;' : '';

        document.getElementById('statsSection').innerHTML = `
            <div class="stat-card">
                <div class="stat-number">${totalPermissions}</div>
                <div class="stat-label">Total de Permissões</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">${enabledPermissions}</div>
                <div class="stat-label">Permissões Ativas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">${percentage}%</div>
                <div class="stat-label">Taxa de Ativação</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="${pendingColor}">${changedPermissions.size}</div>
                <div class="stat-label">Alterações Pendentes</div>
            </div>
        `;
    }

    // Mostrar toast
    function showToast(message, type) {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.className = `toast ${type} show`;
        
        setTimeout(() => {
            toast.classList.remove('show');
        }, 4000);
    }

    // Mostrar/ocultar loading
    function showLoading(show) {
        document.getElementById('loading').classList.toggle('show', show);
    }

    // Teclas de atalho
    document.addEventListener('keydown', function(e) {
        // Ctrl + S para salvar
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            saveAllPermissions();
        }
        // Ctrl + A para selecionar tudo
        if (e.ctrlKey && e.key === 'a') {
            e.preventDefault();
            selectAllPermissions();
        }
        // Esc para limpar seleção
        if (e.key === 'Escape') {
            changedPermissions.clear();
            updateStats();
            showToast('Seleção de alterações limpa', 'info');
        }
    });
</script>

</body>
</html>