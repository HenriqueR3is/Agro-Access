<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

// Verificar se o usuário está logado
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$curso_id = $data['curso_id'] ?? null;
$nota = $data['nota'] ?? 0;
$acertos = $data['acertos'] ?? 0;
$total = $data['total'] ?? 0;
$aprovado = $data['aprovado'] ?? false;
$tentativas = $data['tentativas'] ?? 1;

if (!$curso_id) {
    echo json_encode(['success' => false, 'message' => 'ID do curso não informado']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Item ID único para a prova final
    $item_id = 'final-curso-' . $curso_id;

    // Verificar se já existe um registro de prova final
    $stmt_check = $pdo->prepare("
        SELECT id, tentativas 
        FROM progresso_curso 
        WHERE usuario_id = ? AND curso_id = ? AND tipo = 'prova' AND item_id = ?
    ");
    $stmt_check->execute([$usuario_id, $curso_id, $item_id]);
    $prova_existente = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($prova_existente) {
        // Atualizar prova existente
        $stmt_update = $pdo->prepare("
            UPDATE progresso_curso 
            SET nota = ?, aprovado = ?, data_conclusao = NOW(), tentativas = ?
            WHERE id = ?
        ");
        $stmt_update->execute([$nota, $aprovado ? 1 : 0, $tentativas, $prova_existente['id']]);
    } else {
        // Inserir nova prova
        $stmt_insert = $pdo->prepare("
            INSERT INTO progresso_curso 
            (usuario_id, curso_id, tipo, item_id, nota, aprovado, data_conclusao, tentativas) 
            VALUES (?, ?, 'prova', ?, ?, ?, NOW(), ?)
        ");
        $stmt_insert->execute([$usuario_id, $curso_id, $item_id, $nota, $aprovado ? 1 : 0, $tentativas]);
    }

    // Se aprovado, marcar o curso como concluído
    if ($aprovado) {
        $stmt_curso = $pdo->prepare("
            UPDATE progresso_curso 
            SET data_conclusao = NOW(), aprovado = 1
            WHERE usuario_id = ? AND curso_id = ? AND tipo = 'curso'
        ");
        $stmt_curso->execute([$usuario_id, $curso_id]);

        // Gerar código de validação para o certificado
        $codigo_validacao = generateValidationCode($usuario_id, $curso_id);
        
        $stmt_certificado = $pdo->prepare("
            INSERT INTO certificados 
            (usuario_id, curso_id, codigo_validacao, data_emissao, data_validade) 
            VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 2 YEAR))
            ON DUPLICATE KEY UPDATE 
            codigo_validacao = ?, data_emissao = NOW(), data_validade = DATE_ADD(NOW(), INTERVAL 2 YEAR)
        ");
        $stmt_certificado->execute([$usuario_id, $curso_id, $codigo_validacao, $codigo_validacao]);
    }

    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Resultado da prova salvo com sucesso',
        'aprovado' => $aprovado,
        'nota' => $nota
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Erro ao salvar prova final: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro ao salvar resultado: ' . $e->getMessage()]);
}

function generateValidationCode($usuario_id, $curso_id) {
    return 'CERT-' . strtoupper(substr(md5($usuario_id . $curso_id . time()), 0, 12));
}
?>