<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev')) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

$item_id = $_GET['item_id'] ?? 0;

try {
    $stmt = $pdo->prepare("
        SELECT e.*, u.nome as usuario_nome
        FROM estoque_itens e
        LEFT JOIN usuarios u ON e.usuario_id = u.id
        WHERE e.item_id = ?
        ORDER BY e.criado_em DESC
        LIMIT 50
    ");
    $stmt->execute([$item_id]);
    $codigos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($codigos);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Erro: ' . $e->getMessage()]);
}