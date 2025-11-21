<?php
// /apontamentos
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../../../config/db/conexao.php';

try {
    $usuario_id = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;
    if ($usuario_id <= 0) {
        echo json_encode([]); exit;
    }

    // data padrão = hoje no fuso de Brasília
    $date = $_GET['date'] ?? (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');

    // ATENÇÃO: se data_hora está gravado em HORÁRIO LOCAL (BRT), use DATE(a.data_hora)
    // Se estivesse em UTC, troque a linha do WHERE por DATE(CONVERT_TZ(a.data_hora,'+00:00','-03:00'))
    $sql = "
        SELECT
            a.id,
            a.data_hora,
            a.hora_selecionada,
            a.hectares,
            a.observacoes,
            u.nome  AS unidade_nome,
            e.nome  AS equipamento_nome,
            t.nome  AS operacao_nome
            -- se quiser exibir fazenda mais tarde:
            -- , f.nome AS fazenda_nome, f.codigo_fazenda
        FROM apontamentos a
        JOIN unidades      u ON u.id = a.unidade_id
        JOIN equipamentos  e ON e.id = a.equipamento_id
        JOIN tipos_operacao t ON t.id = a.operacao_id
        LEFT JOIN fazendas f ON f.id = a.fazenda_id
        WHERE a.usuario_id = :uid
          AND DATE(a.data_hora) = :dt
        ORDER BY a.data_hora DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $usuario_id, ':dt' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
