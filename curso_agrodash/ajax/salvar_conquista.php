<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$usuario_id = $_SESSION['usuario_id'];
$curso_id = $input['curso_id'] ?? null;
$conquista_id = $input['conquista_id'] ?? null;

if (!$curso_id || !$conquista_id) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
    exit;
}

try {
    // Verificar se a conquista já foi concedida
    $stmt = $pdo->prepare("SELECT id FROM conquistas_usuario WHERE usuario_id = ? AND curso_id = ? AND conquista_id = ?");
    $stmt->execute([$usuario_id, $curso_id, $conquista_id]);
    
    if (!$stmt->fetch()) {
        // Conceder conquista
        $stmt = $pdo->prepare("INSERT INTO conquistas_usuario (usuario_id, curso_id, conquista_id, data_conquista) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$usuario_id, $curso_id, $conquista_id]);
    }
    
    echo json_encode(['success' => true]);
} catch(PDOException $e) {
    error_log("Erro ao salvar conquista: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro no banco de dados']);
}
?>