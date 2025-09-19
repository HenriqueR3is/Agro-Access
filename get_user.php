<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
    header("Location: /");
    exit();
}

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'ID do usuário não fornecido']);
    exit();
}

$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$userId) {
    echo json_encode(['error' => 'ID inválido']);
    exit();
}

try {
    // Buscar dados do usuário
    $stmt = $pdo->prepare("SELECT id, nome, email, tipo, ativo FROM usuarios WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['error' => 'Usuário não encontrado']);
        exit();
    }

    // Buscar unidades permitidas
    $stmt = $pdo->prepare("SELECT unidade_id FROM usuario_unidade WHERE usuario_id = ?");
    $stmt->execute([$userId]);
    $unidades = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // Buscar operações permitidas
    $stmt = $pdo->prepare("SELECT operacao_id FROM usuario_operacao WHERE usuario_id = ?");
    $stmt->execute([$userId]);
    $operacoes = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // Retornar os dados em JSON
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'user' => $user,
        'unidades' => $unidades,
        'operacoes' => $operacoes
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
}

