<?php
// Arquivo de rotas para permissões

require_once __DIR__ . '/../controllers/PermissionController.php';

$permissionController = new PermissionController();

// Definir rotas
$request_uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Rota para a página principal de permissões
if ($request_uri === '/permissions' && $method === 'GET') {
    $permissionController->index();
    exit();
}

// Rota para atualizar permissões
if ($request_uri === '/permissions/update' && $method === 'POST') {
    $permissionController->update();
    exit();
}

// Rota para resetar permissões
if ($request_uri === '/permissions/reset' && $method === 'POST') {
    $permissionController->reset();
    exit();
}

// Se nenhuma rota corresponder, redirecionar para dashboard
header("Location: /dashboard");
exit();
?>