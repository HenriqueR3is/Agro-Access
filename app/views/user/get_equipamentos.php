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

try {
    // Recuperar a unidade do usuário logado
    $stmt = $pdo->prepare("SELECT unidade_id FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['usuario_id']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario || !$usuario['unidade_id']) {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['error' => 'Usuário não possui unidade vinculada']);
        exit();
    }

    $unidade_id = $usuario['unidade_id'];

    // Buscar equipamentos filtrados pela operação e pela unidade do usuário
    $stmt = $pdo->prepare("
        SELECT id, nome 
        FROM equipamentos 
        WHERE operacao = ? AND unidade_id = ?
        ORDER BY nome
    ");
    $stmt->execute([$operacao_nome, $unidade_id]);

    $equipamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'equipamentos' => $equipamentos,
        'operacao_nome' => $operacao_nome,
        'unidade_id' => $unidade_id // pode ser útil no front
    ]);

} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
}
