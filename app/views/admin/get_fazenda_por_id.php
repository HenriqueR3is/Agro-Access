<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit();
}

$ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit();
}

try {
    $usuario_id = (int) $_SESSION['usuario_id'];
    $tipo       = strtolower($_SESSION['usuario_tipo'] ?? 'operador');

    // Unidade do usuário (para restringir quem não é admin-like)
    $stmt = $pdo->prepare("SELECT unidade_id FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([$usuario_id]);
    $unidade_user = $stmt->fetchColumn();

    if (in_array($tipo, $ADMIN_LIKE, true)) {
        // Admin-like pode ver/editar qualquer fazenda
        $sql = "SELECT id, nome, codigo_fazenda, unidade_id, localizacao,
                       distancia_terra, distancia_asfalto, distancia_total
                FROM fazendas
                WHERE id = ? LIMIT 1";
        $params = [$id];
    } else {
        // Demais: só se a fazenda for da mesma unidade do usuário
        if (!$unidade_user) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado']);
            exit();
        }
        $sql = "SELECT id, nome, codigo_fazenda, unidade_id, localizacao,
                       distancia_terra, distancia_asfalto, distancia_total
                FROM fazendas
                WHERE id = ? AND unidade_id = ? LIMIT 1";
        $params = [$id, $unidade_user];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $fazenda = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fazenda) {
        http_response_code(404);
        echo json_encode(['error' => 'Fazenda não encontrada']);
        exit();
    }

    echo json_encode($fazenda, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
}
