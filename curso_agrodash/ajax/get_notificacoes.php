<?php
require_once __DIR__ . '/../../config/db/conexao.php';

$stmt = $pdo->prepare("
    SELECT COUNT(*) as count FROM trocas_xp 
    WHERE status = 'pendente'
");
$stmt->execute();
echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
?>