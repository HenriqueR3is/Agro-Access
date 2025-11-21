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
$modulo_id = $input['modulo_id'] ?? null;
$conteudo_id = $input['conteudo_id'] ?? null;

if (!$curso_id || !$modulo_id || !$conteudo_id) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
    exit;
}

try {
    // Criar tabela de progresso de conteúdo se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS progresso_conteudo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        curso_id INT NOT NULL,
        modulo_id INT NOT NULL,
        conteudo_id INT NOT NULL,
        data_visualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_progresso (usuario_id, curso_id, modulo_id, conteudo_id)
    )");

    // Inserir ou atualizar progresso
    $stmt = $pdo->prepare("
        INSERT INTO progresso_conteudo (usuario_id, curso_id, modulo_id, conteudo_id) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE data_visualizacao = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$usuario_id, $curso_id, $modulo_id, $conteudo_id]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Erro ao salvar progresso do conteúdo: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro no banco de dados']);
}
?>