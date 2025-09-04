<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

header('Content-Type: application/json');

$response = [];

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado.']);
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$date = $_GET['date'] ?? date('Y-m-d');

try {
    $sql = "
        SELECT 
            ap.id, 
            ap.hectares, 
            ap.data_hora, 
            ap.observacoes,
            u.nome AS unidade_nome,
            e.nome AS equipamento_nome,
            o.nome AS operacao_nome
        FROM apontamentos ap
        JOIN unidades u ON ap.unidade_id = u.id
        JOIN equipamentos e ON ap.equipamento_id = e.id
        JOIN operacoes o ON ap.operacao_id = o.id
        WHERE ap.usuario_id = ? AND DATE(ap.data_hora) = ?
        ORDER BY ap.data_hora DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$usuario_id, $date]);
    $response = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    http_response_code(500);
    $response = ['error' => 'Erro no banco de dados: ' . $e->getMessage()];
}

echo json_encode($response);
