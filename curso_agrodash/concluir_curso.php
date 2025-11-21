<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])){
    echo json_encode(['success'=>false,'msg'=>'Usuário não logado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$curso = $data['curso'] ?? '';

if($curso){
    $sql = $pdo->prepare("INSERT INTO cursos_concluidos (usuario_id, curso, concluido, data_conclusao)
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE concluido=1, data_conclusao=NOW()");
    $sql->execute([$_SESSION['usuario_id'], $curso]);
    echo json_encode(['success'=>true]);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Curso não informado']);
