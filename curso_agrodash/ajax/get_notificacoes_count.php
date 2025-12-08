<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev')) {
    echo json_encode(['count' => 0]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM trocas_xp WHERE status = 'pendente'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['count' => $result['count'] ?? 0]);
} catch (PDOException $e) {
    echo json_encode(['count' => 0]);
}