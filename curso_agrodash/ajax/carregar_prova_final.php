<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

// 🔥 CORREÇÃO RADICAL: Limpeza total de output
while (ob_get_level()) ob_end_clean();

// Headers STRICT para JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Desativar TODOS os erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

$curso_id = $_GET['curso_id'] ?? null;

if (!$curso_id) {
    echo json_encode([
        'success' => false, 
        'message' => 'ID do curso não fornecido',
        'debug' => ['curso_id' => $curso_id]
    ]);
    exit;
}

// DEBUG: Log para verificar
error_log("DEBUG: Carregando prova final para curso_id: " . $curso_id);

try {
    // 1. Buscar prova final
    $stmt = $pdo->prepare("SELECT * FROM provas_finais WHERE curso_id = ?");
    $stmt->execute([$curso_id]);
    $prova_final = $stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("DEBUG: Prova final encontrada: " . ($prova_final ? 'SIM' : 'NÃO'));
    
    if (!$prova_final) {
        echo json_encode([
            'success' => false, 
            'message' => 'Prova final não configurada para este curso',
            'debug' => ['curso_id' => $curso_id, 'prova_existe' => false]
        ]);
        exit;
    }
    
    // 2. Buscar perguntas
    $stmt_perguntas = $pdo->prepare("
        SELECT * FROM prova_final_perguntas 
        WHERE prova_id = ? 
        ORDER BY ordem ASC
    ");
    $stmt_perguntas->execute([$prova_final['id']]);
    $perguntas_db = $stmt_perguntas->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("DEBUG: Perguntas encontradas: " . count($perguntas_db));
    
    if (empty($perguntas_db)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Nenhuma pergunta encontrada para esta prova',
            'debug' => [
                'curso_id' => $curso_id,
                'prova_id' => $prova_final['id'],
                'perguntas_count' => 0
            ]
        ]);
        exit;
    }
    
    // 3. DEBUG: Ver estrutura das perguntas
    error_log("DEBUG: Estrutura primeira pergunta: " . print_r($perguntas_db[0], true));
    
    // 4. Formatar perguntas
    $perguntas = [];
    foreach ($perguntas_db as $index => $pergunta) {
        error_log("DEBUG: Processando pergunta $index: " . $pergunta['pergunta']);
        
        $opcoes = json_decode($pergunta['opcoes'], true);
        
        error_log("DEBUG: Opções decodificadas: " . print_r($opcoes, true));
        error_log("DEBUG: Resposta correta: " . $pergunta['resposta_correta']);
        
        if (is_array($opcoes) && !empty($opcoes)) {
            $perguntas[] = [
                'pergunta' => $pergunta['pergunta'],
                'opcoes' => $opcoes,
                'resposta_correta' => (int)$pergunta['resposta_correta'],
                'explicacao' => $pergunta['explicacao'] ?? ''
            ];
            error_log("DEBUG: Pergunta $index adicionada com sucesso");
        } else {
            error_log("DEBUG: ERRO - Pergunta $index tem opções inválidas");
        }
    }
    
    error_log("DEBUG: Total de perguntas formatadas: " . count($perguntas));
    
    if (empty($perguntas)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Perguntas formatadas estão vazias',
            'debug' => [
                'curso_id' => $curso_id,
                'perguntas_db_count' => count($perguntas_db),
                'perguntas_formatadas_count' => 0,
                'primeira_pergunta_db' => $perguntas_db[0] ?? null
            ]
        ]);
        exit;
    }
    
    // 5. Retornar sucesso
    $response = [
        'success' => true,
        'prova_final' => $prova_final,
        'perguntas' => $perguntas,
        'debug' => [
            'perguntas_count' => count($perguntas),
            'curso_id' => $curso_id
        ]
    ];
    
    error_log("DEBUG: Enviando resposta com " . count($perguntas) . " perguntas");
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("ERROR PDO: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Erro de banco de dados',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("ERROR Geral: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Erro inesperado',
        'error' => $e->getMessage()
    ]);
}
?>