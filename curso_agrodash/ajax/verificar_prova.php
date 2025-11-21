<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: 'application/json');

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
    // BUSCAR DE TODAS AS FORMAS POSSÍVEIS
    $queries = [
        // Tentativa 1: Item_id novo
        ['item_id' => 'final-curso-' . $curso_id, 'desc' => 'novo'],
        // Tentativa 2: Item_id antigo  
        ['item_id' => 'final', 'desc' => 'antigo'],
        // Tentativa 3: Qualquer item_id que contenha 'final'
        ['item_id' => '%final%', 'desc' => 'like_final', 'like' => true],
    ];

    $prova_info = null;
    
    foreach ($queries as $query) {
        if ($query['like'] ?? false) {
            $stmt = $pdo->prepare("
                SELECT * FROM progresso_curso 
                WHERE usuario_id = ? 
                AND curso_id = ? 
                AND tipo = 'prova' 
                AND item_id LIKE ?
                ORDER BY data_conclusao DESC 
                LIMIT 1
            ");
            $stmt->execute([$usuario_id, $curso_id, $query['item_id']]);
        } else {
            $stmt = $pdo->prepare("
                SELECT * FROM progresso_curso 
                WHERE usuario_id = ? 
                AND curso_id = ? 
                AND tipo = 'prova' 
                AND item_id = ?
            ");
            $stmt->execute([$usuario_id, $curso_id, $query['item_id']]);
        }
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $prova_info = $result;
            error_log("✅ REGISTRO ENCONTRADO com item_id '{$query['item_id']}' ({$query['desc']})");
            break;
        } else {
            error_log("❌ Nenhum registro com item_id '{$query['item_id']}' ({$query['desc']})");
        }
    }

    if ($prova_info) {
        // CONVERSÃO CORRETA para boolean
        $aprovado = false;
        
        // Verificar todos os formatos possíveis que o MySQL pode retornar
        if ($prova_info['aprovado'] === 1 || $prova_info['aprovado'] === '1' || $prova_info['aprovado'] === true) {
            $aprovado = true;
        }
        
        // Log detalhado para debug
        error_log("🎯 DADOS ORIGINAIS DO BANCO:");
        error_log(" - aprovado (valor): " . $prova_info['aprovado']);
        error_log(" - aprovado (tipo): " . gettype($prova_info['aprovado']));
        error_log(" - aprovado (convertido): " . ($aprovado ? 'TRUE' : 'FALSE'));
        error_log(" - tentativas: " . $prova_info['tentativas']);
        error_log(" - nota: " . $prova_info['nota']);
        
        $response = [
            'tentativas' => (int)$prova_info['tentativas'],
            'aprovado' => $aprovado, // JÁ CONVERTIDO PARA BOOLEAN
            'nota' => (float)$prova_info['nota'],
            'data_conclusao' => $prova_info['data_conclusao'],
            'codigo_validacao' => $prova_info['codigo_validacao'],
            'bloqueado_ate' => $prova_info['bloqueado_ate']
        ];
        
        echo json_encode([
            'success' => true,
            'prova_final_info' => $response,
            'debug' => [
                'aprovado_original' => $prova_info['aprovado'],
                'aprovado_tipo' => gettype($prova_info['aprovado']),
                'aprovado_convertido' => $aprovado,
                'item_id_encontrado' => $prova_info['item_id']
            ]
        ]);
        
    } else {
        error_log("❌ NENHUM REGISTRO ENCONTRADO PARA PROVA");
        echo json_encode([
            'success' => true,
            'prova_final_info' => [
                'tentativas' => 0,
                'aprovado' => false, // BOOLEAN EXPLÍCITO
                'nota' => 0,
                'data_conclusao' => null,
                'codigo_validacao' => null,
                'bloqueado_ate' => null
            ],
            'debug' => [
                'mensagem' => 'Nenhum registro encontrado'
            ]
        ]);
    }
    
} catch(PDOException $e) {
    error_log("❌ ERRO NO PHP: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Erro: ' . $e->getMessage(),
        'prova_final_info' => [
            'tentativas' => 0,
            'aprovado' => false,
            'nota' => 0,
            'data_conclusao' => null,
            'codigo_validacao' => null,
            'bloqueado_ate' => null
        ]
    ]);
}
?>