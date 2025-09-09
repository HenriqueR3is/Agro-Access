<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

// Verificar se o usuário está autenticado como admin
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: /");
    exit();
}

// Processar as ações
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? 0;

try {
    switch ($action) {
        case 'add':
            // Adicionar novo equipamento
            $nome = $_POST['nome'] ?? '';
            $unidade_id = $_POST['unidade_id'] ?? null;
            $operacao = $_POST['operacao'] ?? '';
            
            if (!empty($nome)) {
                $stmt = $pdo->prepare("INSERT INTO equipamentos (nome, unidade_id, operacao) VALUES (?, ?, ?)");
                $stmt->execute([$nome, $unidade_id, $operacao]);
                
                $_SESSION['msg'] = "Equipamento adicionado com sucesso!";
                $_SESSION['msg_type'] = "success";
            }
            break;
            
        case 'edit':
            // Editar equipamento existente
            $nome = $_POST['nome'] ?? '';
            $unidade_id = $_POST['unidade_id'] ?? null;
            $operacao = $_POST['operacao'] ?? '';
            
            if (!empty($nome) && $id > 0) {
                $stmt = $pdo->prepare("UPDATE equipamentos SET nome = ?, unidade_id = ?, operacao = ? WHERE id = ?");
                $stmt->execute([$nome, $unidade_id, $operacao, $id]);
                
                $_SESSION['msg'] = "Equipamento atualizado com sucesso!";
                $_SESSION['msg_type'] = "success";
            }
            break;
            
        case 'delete':
            // Excluir equipamento
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM equipamentos WHERE id = ?");
                $stmt->execute([$id]);
                
                $_SESSION['msg'] = "Equipamento excluído com sucesso!";
                $_SESSION['msg_type'] = "success";
            }
            break;
            
        default:
            $_SESSION['msg'] = "Ação inválida!";
            $_SESSION['msg_type'] = "danger";
            break;
    }
} catch (PDOException $e) {
    $_SESSION['msg'] = "Erro ao processar a ação: " . $e->getMessage();
    $_SESSION['msg_type'] = "danger";
}

// Redirecionar de volta para a página principal
header("Location: " . (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'fleet.php'));
exit();
?>