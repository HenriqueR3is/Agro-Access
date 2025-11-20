<?php
class PermissionHelper {
    private static $pdo = null;
    private static $initialized = false;
    
    public static function init() {
        if (self::$initialized) {
            return;
        }
        
        try {
            require_once __DIR__ . '/../../config/db/conexao.php';
            global $pdo;
            
            if ($pdo instanceof PDO) {
                self::$pdo = $pdo;
                self::$initialized = true;
                
                // Testar a conexão
                $pdo->query("SELECT 1");
            } else {
                throw new Exception("Conexão PDO não disponível");
            }
            
        } catch (Exception $e) {
            error_log("Erro ao inicializar PermissionHelper: " . $e->getMessage());
            throw $e;
        }
    }
    
    public static function canAccess($userRole, $permissionKey) {
        if (!self::$initialized) {
            self::init();
        }
        
        // Se for cia_dev, tem acesso total
        if ($userRole === 'cia_dev') {
            return true;
        }
        
        try {
            $stmt = self::$pdo->prepare("
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
    
    public static function getAllUserPermissions($userRole) {
        if (!self::$initialized) {
            self::init();
        }
        
        try {
            $stmt = self::$pdo->prepare("
                SELECT permission_key, can_access 
                FROM role_permissions 
                WHERE role_name = ?
            ");
            $stmt->execute([$userRole]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $permissions = [];
            foreach ($results as $result) {
                $permissions[$result['permission_key']] = (bool)$result['can_access'];
            }
            
            return $permissions;
        } catch (Exception $e) {
            error_log("Erro ao buscar permissões do usuário: " . $e->getMessage());
            return [];
        }
    }
}
?>