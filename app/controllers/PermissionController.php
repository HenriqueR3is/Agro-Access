<?php
class PermissionController {
    private $pdo;
    private $roles = ['admin', 'cia_admin', 'cia_user', 'operador'];
    private $categories = [
        'Gestão' => [
            'users:crud' => 'Gestão de Usuários (CRUD)',
            'equip:crud' => 'Gestão de Frota (CRUD)',
            'fazendas:crud' => 'Gestão de Fazendas/Unidades',
            'metas:view' => 'Visualização de Metas',
            'metas:edit' => 'Edição de Metas'
        ],
        'Relatórios' => [
            'consumo:view' => 'Consumo, Velocidade & RPM',
            'apontamentos:view' => 'Visualização de Apontamentos',
            'visaogeral:view' => 'Visão Geral'
        ],
        'Dashboards' => [
            'user_dashboard:view' => 'Dashboard do Usuário'
        ],
        'Desenvolvimento' => [
            'audit:view' => 'Visualização de Auditoria'
        ]
    ];

    public function __construct() {
        try {
            require_once __DIR__ . '/../../config/db/conexao.php';
            global $pdo;
            $this->pdo = $pdo;
            
            // Verificar conexão
            $this->pdo->query("SELECT 1");
            
        } catch (Exception $e) {
            error_log("Erro no construtor PermissionController: " . $e->getMessage());
            throw new Exception("Não foi possível conectar ao banco de dados: " . $e->getMessage());
        }
    }

    public function index() {
        session_start();
        
        // Verificação de permissão
        $tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
        $ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

        if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
            $_SESSION['feedback_message'] = '❌ Sem permissão para acessar esta página';
            header("Location: /dashboard");
            exit();
        }

        $permissions = $this->getAllPermissions();
        
        // Incluir a view
        include __DIR__ . '/../views/admin/permissions.php';
        exit();
    }

    public function getAllPermissions() {
        $result = [];
        
        // Inicializar estrutura vazia
        foreach ($this->roles as $role) {
            $result[$role] = [];
            foreach ($this->categories as $category => $perms) {
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
            $checkTable = $this->pdo->query("SHOW TABLES LIKE 'role_permissions'");
            if ($checkTable->rowCount() == 0) {
                error_log("Tabela role_permissions não existe - criando estrutura padrão");
                $this->createTableAndDefaults();
                return $result;
            }

            // Buscar permissões do banco
            $stmt = $this->pdo->prepare("
                SELECT role_name, permission_key, can_access 
                FROM role_permissions 
                WHERE role_name IN (?, ?, ?, ?)
            ");
            
            $stmt->execute($this->roles);
            $dbPermissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Atualizar com dados do banco
            foreach ($dbPermissions as $perm) {
                $role = $perm['role_name'];
                $key = $perm['permission_key'];
                $allowed = (bool)$perm['can_access'];

                // Encontrar a categoria da permissão
                foreach ($this->categories as $category => $perms) {
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
            throw new Exception("Erro ao carregar permissões: " . $e->getMessage());
        }

        return $result;
    }

    public function update() {
        session_start();
        
        // Verificação de permissão
        $tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
        $ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

        if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Sem permissão']);
            exit();
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Método não permitido']);
            exit();
        }

        // Verificar se é JSON
        $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
        if (strpos($contentType, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);
        } else {
            $input = $_POST;
        }

        if (!$input) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Dados inválidos']);
            exit();
        }

        $role = $input['role'] ?? '';
        $permission = $input['permission'] ?? '';
        $allowed = filter_var($input['allowed'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!in_array($role, $this->roles) || empty($permission)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Dados inválidos: role ou permission']);
            exit();
        }

        try {
            // Verificar se a permissão existe
            $permissionExists = false;
            $permissionName = '';
            $category = '';
            
            foreach ($this->categories as $cat => $perms) {
                if (isset($perms[$permission])) {
                    $permissionExists = true;
                    $permissionName = $perms[$permission];
                    $category = $cat;
                    break;
                }
            }

            if (!$permissionExists) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Permissão não encontrada: ' . $permission]);
                exit();
            }

            // Inserir ou atualizar
            $stmt = $this->pdo->prepare("
                INSERT INTO role_permissions (role_name, permission_key, permission_name, category, can_access) 
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    can_access = VALUES(can_access),
                    updated_at = CURRENT_TIMESTAMP
            ");

            $success = $stmt->execute([
                $role, 
                $permission, 
                $permissionName, 
                $category, 
                $allowed ? 1 : 0
            ]);

            if ($success) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true, 
                    'message' => 'Permissão atualizada com sucesso',
                    'data' => [
                        'role' => $role,
                        'permission' => $permission,
                        'allowed' => $allowed
                    ]
                ]);
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Erro ao executar query no banco']);
            }

        } catch (Exception $e) {
            error_log("Erro ao atualizar permissão: " . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()]);
        }
        exit();
    }

    public function reset() {
        session_start();
        
        // Verificação de permissão
        $tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
        $ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

        if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Sem permissão']);
            exit();
        }

        try {
            // Limpar tabela
            $stmt = $this->pdo->prepare("TRUNCATE TABLE role_permissions");
            $stmt->execute();

            // Inserir padrões
            $defaults = $this->getDefaultPermissions();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO role_permissions (role_name, permission_key, permission_name, category, can_access) 
                VALUES (?, ?, ?, ?, ?)
            ");

            $successCount = 0;
            foreach ($defaults as $default) {
                if ($stmt->execute($default)) {
                    $successCount++;
                }
            }

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true, 
                'message' => 'Permissões resetadas para o padrão (' . $successCount . ' registros)'
            ]);

        } catch (Exception $e) {
            error_log("Erro ao resetar permissões: " . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
        }
        exit();
    }

    private function getDefaultPermissions() {
        return [
            // Admin - Acesso total
            ['admin', 'users:crud', 'Gestão de Usuários (CRUD)', 'Gestão', 1],
            ['admin', 'equip:crud', 'Gestão de Frota (CRUD)', 'Gestão', 1],
            ['admin', 'fazendas:crud', 'Gestão de Fazendas/Unidades', 'Gestão', 1],
            ['admin', 'metas:view', 'Visualização de Metas', 'Gestão', 1],
            ['admin', 'metas:edit', 'Edição de Metas', 'Gestão', 1],
            ['admin', 'consumo:view', 'Consumo, Velocidade & RPM', 'Relatórios', 1],
            ['admin', 'apontamentos:view', 'Visualização de Apontamentos', 'Relatórios', 1],
            ['admin', 'visaogeral:view', 'Visão Geral', 'Relatórios', 1],
            ['admin', 'user_dashboard:view', 'Dashboard do Usuário', 'Dashboards', 1],
            ['admin', 'audit:view', 'Visualização de Auditoria', 'Desenvolvimento', 1],

            // CIA Admin - Acesso quase total
            ['cia_admin', 'users:crud', 'Gestão de Usuários (CRUD)', 'Gestão', 1],
            ['cia_admin', 'equip:crud', 'Gestão de Frota (CRUD)', 'Gestão', 1],
            ['cia_admin', 'fazendas:crud', 'Gestão de Fazendas/Unidades', 'Gestão', 1],
            ['cia_admin', 'metas:view', 'Visualização de Metas', 'Gestão', 1],
            ['cia_admin', 'metas:edit', 'Edição de Metas', 'Gestão', 1],
            ['cia_admin', 'consumo:view', 'Consumo, Velocidade & RPM', 'Relatórios', 1],
            ['cia_admin', 'apontamentos:view', 'Visualização de Apontamentos', 'Relatórios', 1],
            ['cia_admin', 'visaogeral:view', 'Visão Geral', 'Relatórios', 1],
            ['cia_admin', 'user_dashboard:view', 'Dashboard do Usuário', 'Dashboards', 1],

            // CIA User - Acesso limitado
            ['cia_user', 'metas:view', 'Visualização de Metas', 'Gestão', 1],
            ['cia_user', 'consumo:view', 'Consumo, Velocidade & RPM', 'Relatórios', 1],
            ['cia_user', 'apontamentos:view', 'Visualização de Apontamentos', 'Relatórios', 1],
            ['cia_user', 'visaogeral:view', 'Visão Geral', 'Relatórios', 1],
            ['cia_user', 'user_dashboard:view', 'Dashboard do Usuário', 'Dashboards', 1],

            // Operador - Acesso mínimo
            ['operador', 'user_dashboard:view', 'Dashboard do Usuário', 'Dashboards', 1]
        ];
    }

    private function createTableAndDefaults() {
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
            
            $this->pdo->exec($createTableSQL);
            
            // Inserir padrões
            $defaults = $this->getDefaultPermissions();
            $stmt = $this->pdo->prepare("
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
}
?>