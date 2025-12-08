<?php
session_start();
require_once __DIR__ . '/../../config/db/conexao.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev')) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

$configs_padrao = [
    'loja_nome' => 'AgroDash Loja XP',
    'loja_descricao' => 'Troque seus XP por recompensas incríveis',
    'xp_minimo_troca' => '1000',
    'limite_trocas_diarias' => '3',
    'email_notificacoes' => 'admin@agrodash.com',
    'manutencao' => '0',
    'taxa_servico' => '0',
    'backup_frequency' => 'weekly',
    'backup_retention' => '30'
];

try {
    foreach ($configs_padrao as $chave => $valor) {
        $stmt = $pdo->prepare("INSERT INTO config_sistema (chave, valor) VALUES (?, ?) 
                              ON DUPLICATE KEY UPDATE valor = ?");
        $stmt->execute([$chave, $valor, $valor]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Configurações restauradas com sucesso']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}