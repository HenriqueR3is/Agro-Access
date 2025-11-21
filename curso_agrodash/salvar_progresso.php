<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

error_log("=== SALVAR_PROGRESSO.PHP ACESSADO ===");

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$usuario_id = $_SESSION['usuario_id'];
$acao = $input['tipo'] ?? '';
$curso_id = $input['curso_id'] ?? null;

if (!$curso_id) {
    echo json_encode(['success' => false, 'message' => 'Curso não especificado']);
    exit;
}

try {
    if ($acao === 'prova') {
        $nota = floatval($input['nota']);
        $tentativas = intval($input['tentativas']);
        $aprovado = isset($input['aprovado']) && $input['aprovado'] ? 1 : 0;
        $acertos = intval($input['acertos'] ?? 0);
        $total = intval($input['total'] ?? 0);
        
        error_log("Processando prova - Usuario: $usuario_id, Curso: $curso_id, Nota: $nota, Aprovado: $aprovado");

        // **SOLUÇÃO: Incluir curso_id no item_id para evitar conflito na UNIQUE KEY**
        $item_id_final = 'final-curso-' . $curso_id;
        
        error_log("Item ID único gerado: $item_id_final");

        // 1. Primeiro, remover qualquer registro existente para este curso
        $stmt_delete = $pdo->prepare("
            DELETE FROM progresso_curso 
            WHERE usuario_id = ? 
            AND curso_id = ? 
            AND tipo = 'prova' 
            AND item_id LIKE 'final-curso-%'
        ");
        $stmt_delete->execute([$usuario_id, $curso_id]);
        error_log("Registros antigos removidos: " . $stmt_delete->rowCount());
        
        // 2. Inserir novo registro com item_id único por curso
        $codigo_validacao = 'AGD' . strtoupper(uniqid());
        
        $stmt_insert = $pdo->prepare("
            INSERT INTO progresso_curso 
            (usuario_id, curso_id, tipo, item_id, nota, tentativas, aprovado, data_conclusao, codigo_validacao, acertos, total) 
            VALUES (?, ?, 'prova', ?, ?, ?, ?, NOW(), ?, ?, ?)
        ");
        
        $result = $stmt_insert->execute([
            $usuario_id, 
            $curso_id, 
            $item_id_final, 
            $nota, 
            $tentativas, 
            $aprovado, 
            $codigo_validacao,
            $acertos,
            $total
        ]);
        
        if ($result) {
            $_SESSION['ultimo_curso_concluido'] = $curso_id;
            $_SESSION['prova_aprovada'] = $aprovado;
            
            error_log("SUCESSO: Prova salva com código $codigo_validacao e item_id $item_id_final");
            echo json_encode(['success' => true, 'codigo' => $codigo_validacao]);
        } else {
            error_log("ERRO: Falha ao inserir prova");
            echo json_encode(['success' => false, 'message' => 'Falha ao salvar prova']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Ação não reconhecida']);
    }
    
} catch(PDOException $e) {
    error_log("ERRO PDO: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
}
?>