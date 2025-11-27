<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

$curso_id = $_GET['curso_id'] ?? null;

if (!$curso_id) {
    echo json_encode(['success' => false, 'message' => 'ID do curso não fornecido']);
    exit;
}

try {
    // Buscar módulos do curso
    $stmt_modulos = $pdo->prepare("SELECT id FROM modulos WHERE curso_id = ?");
    $stmt_modulos->execute([$curso_id]);
    $modulos = $stmt_modulos->fetchAll(PDO::FETCH_COLUMN);
    
    $quizzes = [];
    
    foreach ($modulos as $modulo_id) {
        // Buscar quiz do módulo
        $stmt_quiz = $pdo->prepare("SELECT * FROM quizzes WHERE modulo_id = ?");
        $stmt_quiz->execute([$modulo_id]);
        $quiz = $stmt_quiz->fetch(PDO::FETCH_ASSOC);
        
        if ($quiz) {
            // Buscar perguntas do quiz
            $stmt_perguntas = $pdo->prepare("
                SELECT * 
                FROM quiz_perguntas 
                WHERE quiz_id = ? 
                ORDER BY ordem ASC
            ");
            $stmt_perguntas->execute([$quiz['id']]);
            $perguntas_db = $stmt_perguntas->fetchAll(PDO::FETCH_ASSOC);
            
            // Formatar perguntas
            $perguntas = [];
            foreach ($perguntas_db as $pergunta) {
                $opcoes = json_decode($pergunta['opcoes'], true);
                
                $perguntas[] = [
                    'pergunta' => $pergunta['pergunta'],
                    'opcoes' => $opcoes,
                    'resposta_correta' => (int)$pergunta['resposta_correta'],
                    'explicacao' => $pergunta['explicacao'] ?? ''
                ];
            }
            
            $quizzes[] = [
                'modulo_id' => $modulo_id,
                'quiz' => $quiz,
                'perguntas' => $perguntas
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'quizzes' => $quizzes
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Erro ao carregar quizzes: ' . $e->getMessage()
    ]);
}
?>