<?php
require_once __DIR__ . '/../../config/db/conexao.php';

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("
    SELECT t.*, u.nome as usuario_nome, u.email as usuario_email, 
           l.nome as item_nome, l.categoria, l.imagem
    FROM trocas_xp t
    JOIN usuarios u ON t.usuario_id = u.id
    JOIN loja_xp l ON t.item_id = l.id
    WHERE t.id = ?
");
$stmt->execute([$id]);
echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
?>