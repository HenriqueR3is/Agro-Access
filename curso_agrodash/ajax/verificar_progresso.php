<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

$curso_id = $_GET['curso_id'] ?? null;
$usuario_id = $_SESSION['usuario_id'];

if (!$curso_id) {
    echo json_encode(['success' => false, 'message' => 'Curso não especificado']);
    exit;
}

try {
    // Buscar progresso da prova usando o mesmo padrão de item_id
    $item_id_final = 'final-curso-' . $curso_id;
    
    $stmt = $pdo->prepare("
        SELECT tentativas, aprovado, nota, data_conclusao, codigo_validacao 
        FROM progresso_curso 
        WHERE usuario_id = ? 
        AND curso_id = ? 
        AND tipo = 'prova' 
        AND item_id = ?
    ");
    $stmt->execute([$usuario_id, $curso_id, $item_id_final]);
    $prova_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'prova_final_info' => $prova_info ?: [
            'tentativas' => 0,
            'aprovado' => false,
            'nota' => 0,
            'data_conclusao' => null,
            'codigo_validacao' => null
        ]
    ]);
    
} catch(PDOException $e) {
    error_log("Erro ao verificar progresso: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro no banco de dados']);
}
?>