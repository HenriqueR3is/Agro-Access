<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

// Log para debug
error_log("=== SALVAR_PROGRESSO.PHP ACESSADO ===");
error_log("SESSION usuario_id: " . ($_SESSION['usuario_id'] ?? 'NÃO DEFINIDO'));
error_log("POST data: " . file_get_contents('php://input'));

if (!isset($_SESSION['usuario_id'])) {
    error_log("ERRO: Usuário não logado");
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$usuario_id = $_SESSION['usuario_id'];
$acao = $input['tipo'] ?? '';
$curso_id = $input['curso_id'] ?? null;

error_log("Dados recebidos - Tipo: $acao, Curso ID: $curso_id, Usuario ID: $usuario_id");

if (!$curso_id) {
    error_log("ERRO: Curso ID não especificado");
    echo json_encode(['success' => false, 'message' => 'Curso não especificado']);
    exit;
}

try {
    switch($acao) {
        case 'modulo':
            $modulo_id = $input['id'];
            $acertos = $input['acertos'] ?? null;
            $total = $input['total'] ?? null;
            
            // Verificar se já existe
            $stmt = $pdo->prepare("SELECT id FROM progresso_curso WHERE usuario_id = ? AND curso_id = ? AND item_id = ? AND tipo = 'modulo'");
            $stmt->execute([$usuario_id, $curso_id, $modulo_id]);
            
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("UPDATE progresso_curso SET data_conclusao = NOW(), acertos = ?, total = ? WHERE usuario_id = ? AND curso_id = ? AND item_id = ? AND tipo = 'modulo'");
                $stmt->execute([$acertos, $total, $usuario_id, $curso_id, $modulo_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO progresso_curso (usuario_id, curso_id, tipo, item_id, data_conclusao, acertos, total) VALUES (?, ?, 'modulo', ?, NOW(), ?, ?)");
                $stmt->execute([$usuario_id, $curso_id, $modulo_id, $acertos, $total]);
            }
            
            echo json_encode(['success' => true]);
            break;
            
case 'prova':
    $nota = floatval($input['nota']);
    $tentativas = intval($input['tentativas']);
    $aprovado = isset($input['aprovado']) && $input['aprovado'] ? 1 : 0;
    $acertos = intval($input['acertos'] ?? 0);
    $total = intval($input['total'] ?? 0);
    $item_id = $input['item_id'] ?? 'final-curso-' . $curso_id; // ✅ USAR ITEM_ID CORRETO
    
    error_log("Dados da prova - Nota: $nota, Tentativas: $tentativas, Aprovado: $aprovado, Acertos: $acertos/$total, Item ID: $item_id");
    
    // Gerar código de validação único
$codigo_validacao = 'AGD' . strtoupper(substr(uniqid(), -7)); // AGD (3) + 7 = 10 caracteres
    
    error_log("Código gerado: $codigo_validacao");
    
    // **SOLUÇÃO: Usar INSERT ... ON DUPLICATE KEY UPDATE**
    $stmt = $pdo->prepare("
        INSERT INTO progresso_curso 
        (usuario_id, curso_id, tipo, item_id, nota, tentativas, aprovado, data_conclusao, codigo_validacao, acertos, total) 
        VALUES (?, ?, 'prova', ?, ?, ?, ?, NOW(), ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            nota = VALUES(nota),
            tentativas = VALUES(tentativas),
            aprovado = VALUES(aprovado),
            data_conclusao = VALUES(data_conclusao),
            codigo_validacao = VALUES(codigo_validacao),
            acertos = VALUES(acertos),
            total = VALUES(total)
    ");
    
    $result = $stmt->execute([
        $usuario_id, 
        $curso_id, 
        $item_id, // ✅ AGORA USANDO O ITEM_ID CORRETO
        $nota, 
        $tentativas, 
        $aprovado, 
        $codigo_validacao,
        $acertos,
        $total
    ]);
            
            error_log("Query executada. Resultado: " . ($result ? 'SUCESSO' : 'FALHA'));
            error_log("Linhas afetadas: " . $stmt->rowCount());
            
            if ($result) {
                // Salvar o curso atual na sessão para o certificado
                $_SESSION['ultimo_curso_concluido'] = $curso_id;
                $_SESSION['prova_aprovada'] = $aprovado;
                
                error_log("SUCESSO: Progresso salvo. Curso $curso_id salvo na sessão.");
                echo json_encode(['success' => true, 'codigo' => $codigo_validacao]);
            } else {
                error_log("ERRO: Falha ao executar query");
                echo json_encode(['success' => false, 'message' => 'Falha ao executar query']);
            }
            break;
            
        default:
            error_log("ERRO: Ação não reconhecida: $acao");
            echo json_encode(['success' => false, 'message' => 'Ação não reconhecida']);
    }
} catch(PDOException $e) {
    error_log("ERRO PDO: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
}

error_log("=== SALVAR_PROGRESSO.PHP FINALIZADO ===");
?>