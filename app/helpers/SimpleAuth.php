<?php
function getUserPermissionsFromDB($userRole) {
    // Configurações do banco - use as mesmas do seu conexao.php
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
        
        // Buscar permissões do banco
        $stmt = $pdo->prepare("
            SELECT permission_key 
            FROM role_permissions 
            WHERE role_name = ? AND can_access = 1
        ");
        $stmt->execute([$userRole]);
        $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return $permissions;
        
    } catch (Exception $e) {
        error_log("Erro ao buscar permissões: " . $e->getMessage());
        return [];
    }
}

function canAccessPage($requiredPermission) {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    // Verificar se usuário está logado
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: /login');
        exit();
    }
    
    $userRole = strtolower($_SESSION['usuario_tipo'] ?? 'operador');
    
    // Se for cia_dev, tem acesso total
    if ($userRole === 'cia_dev') {
        return true;
    }
    
    // Buscar permissões do usuário
    $userPermissions = getUserPermissionsFromDB($userRole);
    
    // Verificar se tem a permissão necessária
    if (in_array($requiredPermission, $userPermissions)) {
        return true;
    }
    
    // Se não tem permissão, redirecionar
    header('Location: /login');
    exit();
}
?>