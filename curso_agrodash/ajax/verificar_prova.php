<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

// Configurar headers para JSON
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

$curso_id = $_GET['curso_id'] ?? null;
$usuario_id = $_SESSION['usuario_id'] ?? null;

if (!$curso_id || !$usuario_id) {
    echo json_encode([
        'success' => false, 
        'message' => 'Dados insuficientes'
    ]);
    exit;
}

try {
    // Buscar progresso da prova final do usuário
    $stmt = $pdo->prepare("
        SELECT * FROM progresso_provas 
        WHERE usuario_id = ? AND curso_id = ?
        ORDER BY data_tentativa DESC 
        LIMIT 1
    ");
    $stmt->execute([$usuario_id, $curso_id]);
    $progresso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $prova_final_info = [
        'tentativas' => 0,
        'aprovado' => false,
        'nota' => 0,
        'bloqueado_ate' => null,
        'data_conclusao' => null
    ];
    
    if ($progresso) {
        $prova_final_info = [
            'tentativas' => (int)$progresso['tentativa'],
            'aprovado' => (bool)$progresso['aprovado'],
            'nota' => (float)$progresso['nota'],
            'bloqueado_ate' => $progresso['bloqueado_ate'],
            'data_conclusao' => $progresso['data_conclusao']
        ];
        
        // Se reprovou e tem bloqueio
        if (!$progresso['aprovado'] && $progresso['bloqueado_ate']) {
            $bloqueadoAte = new DateTime($progresso['bloqueado_ate']);
            $agora = new DateTime();
            
            if ($agora < $bloqueadoAte) {
                $prova_final_info['bloqueado_ate'] = $progresso['bloqueado_ate'];
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'prova_final_info' => $prova_final_info
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Erro ao verificar prova',
        'error' => $e->getMessage()
    ]);
}
?>