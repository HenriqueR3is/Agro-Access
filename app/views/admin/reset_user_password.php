<?php
session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../config/db/conexao.php';

// 1. Permissão
$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$cargos_permitidos = ['admin', 'cia_admin', 'cia_dev']; 

if (!in_array($tipoSess, $cargos_permitidos)) {
    echo json_encode(['success' => false, 'message' => 'Sem permissão.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $id_usuario = $data['id'] ?? null;

    // DEBUG: Verifica se o ID chegou
    if (empty($id_usuario)) {
        echo json_encode(['success' => false, 'message' => 'ERRO CRÍTICO: O ID do usuário chegou vazio ou nulo.']);
        exit;
    }

    try {
        $senha_padrao = "Mudar@123";
        $hash = password_hash($senha_padrao, PASSWORD_DEFAULT);

        // Tenta atualizar
        $stmt = $pdo->prepare("UPDATE usuarios SET senha = ?, primeiro_acesso = 1 WHERE id = ?");
        $executou = $stmt->execute([$hash, $id_usuario]);
        
        // A HORA DA VERDADE: Quantas linhas mudaram?
        $linhas_afetadas = $stmt->rowCount();

        if ($executou && $linhas_afetadas > 0) {
            echo json_encode(['success' => true, 'message' => "Sucesso! ID $id_usuario alterado."]);
        } elseif ($executou && $linhas_afetadas === 0) {
            // O comando rodou, mas não achou o usuário ou a senha já era essa
            echo json_encode([
                'success' => false, 
                'message' => "Alerta: O banco não encontrou o usuário com ID $id_usuario para atualizar."
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erro na execução do SQL.']);
        }

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro SQL: ' . $e->getMessage()]);
    }
}
?>