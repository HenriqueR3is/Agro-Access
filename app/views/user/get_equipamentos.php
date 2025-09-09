<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Acesso não autorizado']);
    exit();
}

if (!isset($_GET['operacao_nome']) || empty($_GET['operacao_nome'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Nome da operação não fornecido']);
    exit();
}

$operacao_nome = trim($_GET['operacao_nome']);
$unidade_id = isset($_GET['unidade_id']) ? filter_var($_GET['unidade_id'], FILTER_VALIDATE_INT) : null;

try {
    // Buscar equipamentos filtrados por operação e unidade
    if ($unidade_id) {
        $stmt = $pdo->prepare("
            SELECT id, nome 
            FROM equipamentos 
            WHERE operacao = ? AND unidade_id = ?
            ORDER BY nome
        ");
        $stmt->execute([$operacao_nome, $unidade_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, nome 
            FROM equipamentos 
            WHERE operacao = ?
            ORDER BY nome
        ");
        $stmt->execute([$operacao_nome]);
    }
    
    $equipamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'equipamentos' => $equipamentos,
        'operacao_nome' => $operacao_nome
    ]);
    
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
}