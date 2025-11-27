<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// Configurar headers para JSON
header('Content-Type: application/json; charset=utf-8');

// Verificar se o usuário está logado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Usuário não logado']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$curso_id = $_GET['curso_id'] ?? null;
$forcar = $_GET['forcar'] ?? 0;

if (!$curso_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID do curso não fornecido']);
    exit;
}

try {
    // Buscar progresso da prova final
    $item_id_final = 'final-curso-' . $curso_id;
    
    $stmt = $pdo->prepare("
        SELECT tentativas, aprovado, nota, data_conclusao, codigo_validacao, bloqueado_ate 
        FROM progresso_curso 
        WHERE usuario_id = ? AND curso_id = ? AND tipo = 'prova' AND item_id = ?
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([$usuario_id, $curso_id, $item_id_final]);
    $prova_final_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Se não encontrou, criar registro padrão
    if (!$prova_final_info) {
        $prova_final_info = [
            'tentativas' => 0,
            'aprovado' => false,
            'nota' => 0,
            'data_conclusao' => null,
            'codigo_validacao' => null,
            'bloqueado_ate' => null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'prova_final_info' => $prova_final_info
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Erro ao verificar progresso',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Erro inesperado',
        'error' => $e->getMessage()
    ]);
}
?>