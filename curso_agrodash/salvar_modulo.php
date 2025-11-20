<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';
header('Content-Type: application/json');

if(!isset($_SESSION['usuario_id'])){
    echo json_encode(['success'=>false,'msg'=>'Não logado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$modulo = intval($data['modulo']);
$nota = floatval($data['nota']);

if($modulo>=1 && $nota>=0){
    // Salvar módulo
    $stmt = $pdo->prepare("INSERT INTO progresso_modulos (usuario_id, modulo, concluido, nota, data_conclusao)
        VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE concluido=1, nota=?, data_conclusao=NOW()");
    $stmt->execute([$_SESSION['usuario_id'],$modulo,1,$nota,$nota]);

    // Se último módulo (prova final), salvar curso concluído
    if($modulo==4){
        $stmt2 = $pdo->prepare("INSERT INTO cursos_concluidos (usuario_id, curso, concluido, nota_final, data_conclusao)
            VALUES (?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE concluido=1, nota_final=?, data_conclusao=NOW()");
        $stmt2->execute([$_SESSION['usuario_id'],'AgroDash',$nota,$nota]);
    }

    echo json_encode(['success'=>true]);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Dados inválidos']);
