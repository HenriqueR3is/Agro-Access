<?php
require_once __DIR__ . '/../../config/db/conexao.php';

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM loja_xp WHERE id = ?");
$stmt->execute([$id]);
echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
?>