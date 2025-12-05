<?php
require_once __DIR__ . '/../../config/db/conexao.php';

$item_id = $_GET['item_id'] ?? 0;
$stmt = $pdo->prepare("
    SELECT e.*, u.nome as usuario_nome 
    FROM estoque_itens e
    LEFT JOIN usuarios u ON e.usuario_id = u.id
    WHERE e.item_id = ?
    ORDER BY e.data_resgate DESC, e.id DESC
");
$stmt->execute([$item_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>