<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$item_id = $_POST['item_id'] ?? 0;

if (!$item_id) {
    echo json_encode(['success' => false, 'message' => 'Item não especificado']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Buscar informações do item
    $stmt = $pdo->prepare("SELECT * FROM loja_xp WHERE id = ? AND status = 'ativo' FOR UPDATE");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        throw new Exception("Item não disponível");
    }
    
    // Verificar estoque
    if ($item['estoque'] == 0) {
        throw new Exception("Item esgotado");
    }
    
    // Calcular XP do usuário
    $total_xp = calcularTotalXP($pdo, $usuario_id);
    
    if ($total_xp < $item['custo_xp']) {
        throw new Exception("XP insuficiente. Você tem " . number_format($total_xp) . " XP e precisa de " . number_format($item['custo_xp']) . " XP.");
    }
    
    // Registrar a troca
    $stmt_troca = $pdo->prepare("INSERT INTO trocas_xp (usuario_id, item_id, custo_xp, status) VALUES (?, ?, ?, 'concluido')");
    $stmt_troca->execute([$usuario_id, $item_id, $item['custo_xp']]);
    
    // Atualizar estoque se não for ilimitado
    if ($item['estoque'] > 0) {
        $stmt_estoque = $pdo->prepare("UPDATE loja_xp SET estoque = estoque - 1 WHERE id = ?");
        $stmt_estoque->execute([$item_id]);
        
        // Verificar se esgotou
        $novo_estoque = $item['estoque'] - 1;
        if ($novo_estoque == 0) {
            $stmt_status = $pdo->prepare("UPDATE loja_xp SET status = 'esgotado' WHERE id = ?");
            $stmt_status->execute([$item_id]);
        }
    }
    
    // Gerar código de resgate se for item físico/digital
    $codigo_resgate = null;
    if ($item['categoria'] == 'fisico' || $item['categoria'] == 'digital') {
        $codigo_resgate = 'AGR' . strtoupper(uniqid());
        $stmt_codigo = $pdo->prepare("INSERT INTO estoque_itens (item_id, usuario_id, codigo_resgate, status) VALUES (?, ?, ?, 'disponivel')");
        $stmt_codigo->execute([$item_id, $usuario_id, $codigo_resgate]);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Troca realizada com sucesso! Você adquiriu: " . $item['nome'],
        'codigo' => $codigo_resgate,
        'novo_saldo' => $total_xp - $item['custo_xp']
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}