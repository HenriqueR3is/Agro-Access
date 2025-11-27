<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Verificar se já existe um quiz para este módulo
    $stmt = $pdo->prepare("SELECT id FROM quizzes WHERE modulo_id = ?");
    $stmt->execute([$data['modulo_id']]);
    $quiz_existente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($quiz_existente) {
        // Atualizar quiz existente
        $stmt = $pdo->prepare("UPDATE quizzes SET titulo = ?, descricao = ?, duracao = ?, nota_minima = ?, atualizado_em = NOW() WHERE id = ?");
        $stmt->execute([
            $data['titulo'],
            $data['descricao'],
            $data['duracao'],
            $data['nota_minima'],
            $quiz_existente['id']
        ]);
        $quiz_id = $quiz_existente['id'];
        
        // Remover perguntas antigas
        $stmt = $pdo->prepare("DELETE FROM quiz_perguntas WHERE quiz_id = ?");
        $stmt->execute([$quiz_id]);
    } else {
        // Criar novo quiz
        $stmt = $pdo->prepare("INSERT INTO quizzes (modulo_id, titulo, descricao, duracao, nota_minima, criado_em) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $data['modulo_id'],
            $data['titulo'],
            $data['descricao'],
            $data['duracao'],
            $data['nota_minima']
        ]);
        $quiz_id = $pdo->lastInsertId();
    }
    
    // Inserir novas perguntas
    foreach ($data['perguntas'] as $pergunta) {
        $stmt = $pdo->prepare("INSERT INTO quiz_perguntas (quiz_id, pergunta, opcoes, resposta_correta, ordem, criado_em) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $quiz_id,
            $pergunta['pergunta'],
            json_encode($pergunta['opcoes']),
            $pergunta['resposta_correta'],
            $pergunta['ordem']
        ]);
    }
    
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Quiz salvo com sucesso']);
    
} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Erro ao salvar quiz: ' . $e->getMessage()]);
}