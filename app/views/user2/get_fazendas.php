<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit();
}

$usuario_id  = (int) $_SESSION['usuario_id'];
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'operador';

try {
    // Resolve unidade alvo: operador = unidade do próprio usuário; admin = pode informar via GET
    $unidade_id = null;
    if ($usuario_tipo === 'admin') {
        $unidade_id = filter_input(INPUT_GET, 'unidade_id', FILTER_VALIDATE_INT) ?: null;
    }
    if (!$unidade_id) {
        $stmtU = $pdo->prepare("SELECT unidade_id FROM usuarios WHERE id = ? LIMIT 1");
        $stmtU->execute([$usuario_id]);
        $unidade_id = $stmtU->fetchColumn();
    }

    // Monta consulta
    $sql = "SELECT id, nome, codigo_fazenda
            FROM fazendas ";
    $params = [];

    if (!empty($unidade_id)) {
        $sql .= "WHERE unidade_id = :unidade_id ";
        $params[':unidade_id'] = $unidade_id;
    }

    $sql .= "ORDER BY nome";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $fazendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => true, 'fazendas' => $fazendas]);

} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'Erro DB: ' . $e->getMessage()]);
}
