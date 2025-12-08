<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev')) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

$id = $_GET['id'] ?? 0;

try {
    $stmt = $pdo->prepare("SELECT nome, estoque, custo_xp, status FROM loja_xp WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($item) {
        echo json_encode(['success' => true, ...$item]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Item não encontrado']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}