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

    // IMPORTANTE: seus apontamentos estão gravados em horário local (BRT),
    // então usamos DATE(a.data_hora). Se fosse UTC, usaríamos CONVERT_TZ.
    $sql = "
        SELECT
            a.id,
            a.usuario_id,
            a.unidade_id,
            a.equipamento_id,
            a.operacao_id,
            a.hectares,
            a.viagens,                     -- <<<<<<<<<< AQUI! agora vem do banco
            a.data_hora,
            a.hora_selecionada,
            a.observacoes,

            u.nome  AS unidade_nome,
            e.nome  AS equipamento_nome,
            t.nome  AS operacao_nome,

            -- detecta 'caçamba' mesmo que venha sem acento
            CASE
              WHEN UPPER(REPLACE(t.nome,'Ç','C')) LIKE '%CACAMBA%' THEN 1
              ELSE 0
            END AS is_cacamba,

            -- campo dinamico para o front exibir direto
            CASE
              WHEN UPPER(REPLACE(t.nome,'Ç','C')) LIKE '%CACAMBA%' THEN a.viagens
              ELSE a.hectares
            END AS quantidade,

            CASE
              WHEN UPPER(REPLACE(t.nome,'Ç','C')) LIKE '%CACAMBA%' THEN 'Viagens'
              ELSE 'Hectares'
            END AS quant_label

            -- se quiser, dá pra liberar fazenda depois:
            -- , f.nome AS fazenda_nome, f.codigo_fazenda
        FROM apontamentos a
        JOIN unidades       u ON u.id = a.unidade_id
        JOIN equipamentos   e ON e.id = a.equipamento_id
        JOIN tipos_operacao t ON t.id = a.operacao_id
        LEFT JOIN fazendas  f ON f.id = a.fazenda_id
        WHERE a.usuario_id = :uid
          AND DATE(a.data_hora) = :dt
        ORDER BY a.data_hora DESC, a.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $usuario_id, ':dt' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
