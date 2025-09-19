<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
    header("Location: /");
    exit();
}

$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$user_id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit();
}

$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$user_id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit();
}

try {
    // Dados do usuário
    $stmt = $pdo->prepare("SELECT id, nome, email, tipo, ativo FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado']);
        exit();
    }

    // Unidades
    $stmt = $pdo->prepare("SELECT unidade_id FROM usuario_unidade WHERE usuario_id = :id");
    $stmt->execute([':id' => $user_id]);
    $unidades = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Operações
    $stmt = $pdo->prepare("SELECT operacao_id FROM usuario_operacao WHERE usuario_id = :id");
    $stmt->execute([':id' => $user_id]);
    $operacoes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'user' => $user,
        'unidades' => $unidades,
        'operacoes' => $operacoes
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
