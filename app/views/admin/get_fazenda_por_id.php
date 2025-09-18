<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_tipo'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso não autorizado']);
    exit();
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT 
          id, nome, codigo_fazenda, unidade_id, localizacao,
          distancia_terra, distancia_asfalto, distancia_total
        FROM fazendas
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Registro não encontrado']);
        exit();
    }

    echo json_encode($row, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
}
