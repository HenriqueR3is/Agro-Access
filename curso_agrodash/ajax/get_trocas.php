<?php
require_once __DIR__ . '/../../config/db/conexao.php';

$stmt = $pdo->prepare("
    SELECT t.*, u.nome as usuario_nome, l.nome as item_nome 
    FROM trocas_xp t
    JOIN usuarios u ON t.usuario_id = u.id
    JOIN loja_xp l ON t.item_id = l.id
    ORDER BY t.data_troca DESC
");
$stmt->execute();
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>