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
    $stmt = $pdo->prepare("
        SELECT t.*, u.nome as usuario_nome, u.email, l.nome as item_nome, l.categoria
        FROM trocas_xp t
        JOIN usuarios u ON t.usuario_id = u.id
        JOIN loja_xp l ON t.item_id = l.id
        WHERE t.id = ?
    ");
    $stmt->execute([$id]);
    $troca = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($troca) {
        echo json_encode($troca);
    } else {
        echo json_encode(['error' => 'Troca não encontrada']);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Erro: ' . $e->getMessage()]);
}