<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit();
}

$operacao_id = filter_input(INPUT_GET, 'operacao_id', FILTER_VALIDATE_INT);
if (!$operacao_id) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'operacao_id inválido']);
    exit();
}

$usuario_id  = (int) $_SESSION['usuario_id'];
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'operador';

try {
    // Descobre a unidade do usuário (para operador) ou aceita unidade_id do admin
    $unidade_id = null;

    if ($usuario_tipo === 'admin') {
        $unidade_id = filter_input(INPUT_GET, 'unidade_id', FILTER_VALIDATE_INT) ?: null;
    } else {
        $stmtU = $pdo->prepare("SELECT unidade_id FROM usuarios WHERE id = ? LIMIT 1");
        $stmtU->execute([$usuario_id]);
        $unidade_id = $stmtU->fetchColumn();
    }

    // Monta SQL: SEMPRE filtra por operacao_id; filtra por unidade_id quando disponível
    $sql = "SELECT id, nome 
            FROM equipamentos
            WHERE operacao_id = :operacao_id";
    $params = [':operacao_id' => $operacao_id];

    if (!empty($unidade_id)) {
        $sql .= " AND unidade_id = :unidade_id";
        $params[':unidade_id'] = $unidade_id;
    }

    $sql .= " ORDER BY nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $equipamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'equipamentos' => $equipamentos
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'Erro DB: ' . $e->getMessage()]);
}
