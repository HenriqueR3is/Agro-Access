<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit();
}

$fazenda_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$fazenda_id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT id, nome, codigo_fazenda FROM fazendas WHERE id = ?");
    $stmt->execute([$fazenda_id]);
    $fazenda = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fazenda) {
        http_response_code(404);
        echo json_encode(['error' => 'Fazenda não encontrada']);
        exit();
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($fazenda);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>