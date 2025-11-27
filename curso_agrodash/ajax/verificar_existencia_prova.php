<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

$curso_id = $_GET['curso_id'] ?? null;

if (!$curso_id) {
    echo json_encode(['error' => 'ID do curso não fornecido']);
    exit;
}

try {
    // Verificar se existe prova final
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM provas_finais WHERE curso_id = ?");
    $stmt->execute([$curso_id]);
    $prova_count = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar se existem perguntas
    $stmt_perguntas = $pdo->prepare("
        SELECT COUNT(pfp.id) as total_perguntas 
        FROM prova_final_perguntas pfp 
        JOIN provas_finais pf ON pfp.prova_id = pf.id 
        WHERE pf.curso_id = ?
    ");
    $stmt_perguntas->execute([$curso_id]);
    $perguntas_count = $stmt_perguntas->fetch(PDO::FETCH_ASSOC);
    
    // Buscar detalhes da prova
    $stmt_detalhes = $pdo->prepare("SELECT * FROM provas_finais WHERE curso_id = ?");
    $stmt_detalhes->execute([$curso_id]);
    $prova_detalhes = $stmt_detalhes->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'existe_prova' => $prova_count['total'] > 0,
        'total_provas' => $prova_count['total'],
        'total_perguntas' => $perguntas_count['total_perguntas'],
        'prova_detalhes' => $prova_detalhes,
        'curso_id' => $curso_id
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'error' => 'Erro ao verificar prova: ' . $e->getMessage()
    ]);
}
?>