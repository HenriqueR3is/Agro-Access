<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso não autorizado']);
    exit();
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($data, JSON_UNESCAPED_UNICODE);

try {
    $usuario_id = (int) $_SESSION['usuario_id'];
    $tipo       = $_SESSION['usuario_tipo'] ?? 'operador';

    // Descobre a unidade do usuário
    $stmt = $pdo->prepare("SELECT unidade_id FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $unidade_id_usuario = $user['unidade_id'] ?? null;

    // Admin pode opcionalmente filtrar por unidade via ?unidade_id=#
    $unidade_param = filter_input(INPUT_GET, 'unidade_id', FILTER_VALIDATE_INT);

    if ($tipo === 'admin') {
        if ($unidade_param) {
            $stmtF = $pdo->prepare("SELECT id, nome, codigo_fazenda FROM fazendas WHERE unidade_id = ? ORDER BY nome");
            $stmtF->execute([$unidade_param]);
        } else {
            $stmtF = $pdo->query("SELECT id, nome, codigo_fazenda FROM fazendas ORDER BY nome");
        }
    } else {
        // Operador (ou coordenador) recebe apenas as fazendas da própria unidade
        if (!$unidade_id_usuario) {
            echo json_encode(['success' => true, 'unidade_id' => null, 'fazendas' => []]);
            exit();
        }
        $stmtF = $pdo->prepare("SELECT id, nome, codigo_fazenda FROM fazendas WHERE unidade_id = ? ORDER BY nome");
        $stmtF->execute([$unidade_id_usuario]);
    }

    $fazendas = $stmtF->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'success'    => true,
        'unidade_id' => $tipo === 'admin' ? ($unidade_param ?: null) : $unidade_id_usuario,
        'fazendas'   => $fazendas
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro no banco de dados: ' . $e->getMessage()]);
}
