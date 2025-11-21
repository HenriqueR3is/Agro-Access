<?php
// ... auth do operador + validação CSRF/JWT se houver
$input = json_decode(file_get_contents('php://input'), true);
$client_id = $input['client_id'] ?? null;

try {
  if ($client_id) {
    $stmt = $pdo->prepare("SELECT id FROM apontamentos WHERE client_id = :cid LIMIT 1");
    $stmt->execute([':cid'=>$client_id]);
    if ($stmt->fetch()) {
      http_response_code(200); echo json_encode(['ok'=>true,'dup'=>true]); exit;
    }
  }

  $stmt = $pdo->prepare("
    INSERT INTO apontamentos (talhao_id, quantidade, turno, obs, client_id, created_at)
    VALUES (:talhao_id, :quantidade, :turno, :obs, :client_id, NOW())
  ");
  $stmt->execute([
    ':talhao_id'=>(int)$input['talhao_id'],
    ':quantidade'=>(float)$input['quantidade'],
    ':turno'=>$input['turno'],
    ':obs'=>$input['obs'] ?? '',
    ':client_id'=>$client_id
  ]);

  echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
} catch (PDOException $e) {
  // se violar UNIQUE por corrida, trata como ok
  if ($e->errorInfo[1] == 1062) { echo json_encode(['ok'=>true,'dup'=>true]); exit; }
  http_response_code(500); echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
}
