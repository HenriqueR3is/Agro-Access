<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: /login");
    exit();
}

/**
 * Detecta delimitador com heurística simples usando a linha do cabeçalho
 */
function detectar_delimitador(array $linhas, int $headerIndex = 0): string {
    $delims = ["\t", ";", ",", "|"];
    $scores = [];
    $sampleIdx = max(0, min($headerIndex, count($linhas)-1));
    $sampleLine = $linhas[$sampleIdx] ?? ($linhas[0] ?? '');
    foreach ($delims as $d) {
        $parts = str_getcsv($sampleLine, $d);
        $scores[$d] = count($parts);
    }
    // escolhe o delimitador que produz mais colunas (e que seja pelo menos 3)
    arsort($scores);
    $best = key($scores);
    return $best;
}

/**
 * Encontra a linha de cabeçalho baseada em palavras-chaves
 */
function encontrar_indice_cabecalho(array $linhas): int {
    $keywords = ['unidade','frente','processo','equipamento','operador','data','área operacional','área operacional (ha)','fzt'];
    $maxCheck = min(6, count($linhas)-1);
    for ($i = 0; $i <= $maxCheck; $i++) {
        $l = mb_strtolower($linhas[$i]);
        foreach ($keywords as $k) {
            if (mb_strpos($l, $k) !== false) {
                return $i;
            }
        }
    }
    // fallback: muitas exports do SGPA têm 2 linhas lixo + 1 header -> headerIndex = 2
    return min(2, max(0, count($linhas)-1));
}

/**
 * Tenta parsear data em dd/mm/yyyy -> Y-m-d
 */
function parse_data($s) {
    $s = trim($s);
    if (empty($s)) return null;
    // formatos possíveis
    $formats = ['d/m/Y', 'd-m-Y', 'Y-m-d'];
    foreach ($formats as $f) {
        $dt = DateTime::createFromFormat($f, $s);
        if ($dt && $dt->format($f) === $s) {
            return $dt->format('Y-m-d');
        }
    }
    // tentativa relaxada (pode ter espaços)
    $s2 = preg_replace('/\s+/', '', $s);
    foreach ($formats as $f) {
        $dt = DateTime::createFromFormat($f, $s2);
        if ($dt) return $dt->format('Y-m-d');
    }
    return null;
}

/**
 * Remove BOM UTF-8/UTF-16 do começo da string
 */
function trim_bom($s) {
    return preg_replace('/^\x{FEFF}|^\xEF\xBB\xBF/u', '', $s);
}


// ------------------ IMPORTAÇÃO ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_csv'])) {
    if ($_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error_message'] = "Erro no upload do arquivo (código: ".$_FILES['arquivo_csv']['error'].").";
        header("Location: /comparativo");
        exit();
    }

    $arquivoTmp = $_FILES['arquivo_csv']['tmp_name'];
    $raw_lines = file($arquivoTmp, FILE_IGNORE_NEW_LINES);
    if ($raw_lines === false || count($raw_lines) === 0) {
        $_SESSION['error_message'] = "Arquivo vazio ou não pode ser lido.";
        header("Location: /comparativo");
        exit();
    }

    $headerIndex = encontrar_indice_cabecalho($raw_lines);
    $delim = detectar_delimitador($raw_lines, $headerIndex);
    $firstDataLine = min(count($raw_lines), $headerIndex + 1);

    // Mapeamento abreviação -> unidade_id
    $mapUnidade = [
        'IGT' => 1,
        'PCT' => 2,
        'TRC' => 3,
        'RDN' => 4,
        'CGA' => 5,
        'IVA' => 6,
        'URP' => 7,
        'TAP' => 8,
        'MOS' => 9,
        'MGA' => 10
    ];

    // prepara statements
    $stmtEquip = $pdo->prepare("SELECT id FROM equipamentos WHERE nome LIKE ? LIMIT 1");
    $stmtEquipExact = $pdo->prepare("SELECT id FROM equipamentos WHERE nome = ? LIMIT 1");
    $stmtFazendaCode = $pdo->prepare("SELECT id FROM fazendas WHERE codigo_fazenda = ? LIMIT 1");
    $stmtFazendaLike = $pdo->prepare("SELECT id FROM fazendas WHERE nome LIKE ? LIMIT 1");

    $stmtCol = $pdo->prepare("
        SELECT COUNT(*) 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'producao_sgpa' AND COLUMN_NAME = 'fazenda_id'
    ");
    $stmtCol->execute();
    $producao_tem_fazenda = (bool) $stmtCol->fetchColumn();

    if ($producao_tem_fazenda) {
        $stmtUpsert = $pdo->prepare("
            INSERT INTO producao_sgpa (data, equipamento_id, unidade_id, frente_id, fazenda_id, hectares_sgpa)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE hectares_sgpa = VALUES(hectares_sgpa)
        ");
    } else {
        $stmtUpsert = $pdo->prepare("
            INSERT INTO producao_sgpa (data, equipamento_id, unidade_id, frente_id, hectares_sgpa)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE hectares_sgpa = VALUES(hectares_sgpa)
        ");
    }

    $pdo->beginTransaction();
    try {
        $processados = 0; $ignorados = 0; $erros = 0;
        $nao_encontrados = ['equipamentos' => [], 'fazendas' => []];

        for ($i = $firstDataLine; $i < count($raw_lines); $i++) {
            $line = $raw_lines[$i];
            if (trim($line) === '') continue;

            $data = str_getcsv($line, $delim);
            if (isset($data[0])) $data[0] = trim_bom($data[0]);

            $unidade_abrev = strtoupper(trim($data[0] ?? ''));
            $unidade_id = $mapUnidade[$unidade_abrev] ?? null;

            $nome_equip = trim($data[4] ?? '');
            $nome_fazenda = trim($data[6] ?? '');
            $data_str = trim($data[7] ?? '');
            $hectares_str = trim($data[8] ?? '');
            $frente_id = null; // ainda não populado

            if ($nome_equip === '' || $data_str === '' || $hectares_str === '' || !$unidade_id) {
                $ignorados++; continue;
            }

            $data_producao = parse_data($data_str);
            if (!$data_producao) { $erros++; continue; }

            $hectares = (float) str_replace(',', '.', str_replace(' ', '', $hectares_str));

            // Extrai código equipamento para busca
            $codigo_equip = '';
            if ($nome_equip !== '') {
                if (preg_match('/^\s*([^-\s]+)/u', $nome_equip, $m)) {
                    $codigo_equip = trim($m[1]);
                } else {
                    $tokens = preg_split('/\s+/', $nome_equip);
                    $codigo_equip = trim($tokens[0] ?? '');
                }
            }

            // busca equipamento existente
            $equip_id = null;
            if ($codigo_equip) {
                $stmtEquip->execute(['%' . $codigo_equip . '%']);
                $row = $stmtEquip->fetch(PDO::FETCH_ASSOC);
                if ($row) $equip_id = $row['id'];
            }
            if (!$equip_id) {
                $stmtEquip->execute(['%' . $nome_equip . '%']);
                $row2 = $stmtEquip->fetch(PDO::FETCH_ASSOC);
                if ($row2) $equip_id = $row2['id'];
            }
            if (!$equip_id) {
                $stmtEquipExact->execute([$nome_equip]);
                $rowExact = $stmtEquipExact->fetch(PDO::FETCH_ASSOC);
                if ($rowExact) $equip_id = $rowExact['id'];
            }

            if (!$equip_id && !in_array($nome_equip, $nao_encontrados['equipamentos'])) {
                $nao_encontrados['equipamentos'][] = $nome_equip;
            }

            // busca fazenda
            $fazenda_id = null;
            if ($producao_tem_fazenda) {
                $codigo_fazenda = '';
                if ($nome_fazenda !== '') {
                    if (preg_match('/^\s*([^-\s]+)/u', $nome_fazenda, $m2)) {
                        $codigo_fazenda = trim($m2[1]);
                    } else {
                        $tokens = preg_split('/\s+/', $nome_fazenda);
                        $codigo_fazenda = trim($tokens[0] ?? '');
                    }
                }

                if ($codigo_fazenda) {
                    $stmtFazendaCode->execute([$codigo_fazenda]);
                    $frow = $stmtFazendaCode->fetch(PDO::FETCH_ASSOC);
                    if ($frow) $fazenda_id = $frow['id'];
                }

                if (!$fazenda_id && $nome_fazenda !== '') {
                    $stmtFazendaLike->execute(['%' . $nome_fazenda . '%']);
                    $frow2 = $stmtFazendaLike->fetch(PDO::FETCH_ASSOC);
                    if ($frow2) $fazenda_id = $frow2['id'];
                }

                if (!$fazenda_id && !in_array($nome_fazenda, $nao_encontrados['fazendas'])) {
                    $nao_encontrados['fazendas'][] = $nome_fazenda;
                }
            }

            // insere registro
            if ($equip_id && ($producao_tem_fazenda ? $fazenda_id : true)) {
                if ($producao_tem_fazenda) {
                    $stmtUpsert->execute([$data_producao, $equip_id, $unidade_id, $frente_id, $fazenda_id, $hectares]);
                } else {
                    $stmtUpsert->execute([$data_producao, $equip_id, $unidade_id, $frente_id, $hectares]);
                }
                $processados++;
            } else {
                $ignorados++;
            }
        }

        $pdo->commit();

        $msg = "Importação finalizada. Delimitador detectado: " . ($delim === "\t" ? "TAB" : $delim) . ". Cabeçalho na linha: $headerIndex. ";
        $msg .= "Linhas analisadas: ".max(0, count($raw_lines)-$firstDataLine).". Processados: $processados. Ignorados: $ignorados. Erros: $erros.";

        if (!empty($nao_encontrados['equipamentos'])) {
            $sample_e = array_slice(array_unique($nao_encontrados['equipamentos']), 0, 15);
            $msg .= "<br>Equipamentos não encontrados (amostra): " . implode(', ', $sample_e);
        }
        if (!empty($nao_encontrados['fazendas'])) {
            $sample_f = array_slice(array_unique($nao_encontrados['fazendas']), 0, 15);
            $msg .= "<br>Fazendas não encontradas (amostra): " . implode(', ', $sample_f);
        }

        $_SESSION['success_message'] = "Importação concluída!";
        $_SESSION['info_message'] = $msg;

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Erro durante a importação: " . $e->getMessage();
    }

    header("Location: /comparativo");
    exit();
}

// após toda a lógica de POST/redirect -> inclui header e renderiza a página
require_once __DIR__ . '/../../../app/includes/header.php';
?>

<link rel="stylesheet" href="/public/static/css/admin.css">

<?php
// ================= FILTRO DE DATAS =================
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-d');
$data_fim = $_GET['data_fim'] ?? $data_inicio;

try {
    // ver se producao_sgpa tem fazenda_id
    $stmtCol = $pdo->prepare("
        SELECT COUNT(*) 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'producao_sgpa' AND COLUMN_NAME = 'fazenda_id'
    ");
    $stmtCol->execute();
    $producao_tem_fazenda = (bool) $stmtCol->fetchColumn();

    if ($producao_tem_fazenda) {
        $sql = "
        SELECT
            e.nome AS equipamento_nome,
            f.nome AS fazenda_nome,
            COALESCE(agro.total_ha, 0) AS ha_agro_access,
            COALESCE(sgpa.total_ha, 0) AS ha_sgpa,
            p.data
        FROM (
            SELECT DISTINCT equipamento_id, fazenda_id, DATE(data) AS data
            FROM producao_sgpa
            WHERE DATE(data) BETWEEN ? AND ?
        ) p
        JOIN equipamentos e ON p.equipamento_id = e.id
        LEFT JOIN (
            SELECT equipamento_id, fazenda_id, SUM(hectares) AS total_ha, DATE(data_hora) as data
            FROM apontamentos
            WHERE DATE(data_hora) BETWEEN ? AND ?
            GROUP BY equipamento_id, fazenda_id, DATE(data_hora)
        ) AS agro 
            ON agro.equipamento_id = p.equipamento_id 
           AND agro.fazenda_id = p.fazenda_id 
           AND agro.data = p.data
        LEFT JOIN (
            SELECT equipamento_id, fazenda_id, SUM(hectares_sgpa) AS total_ha, DATE(data) as data
            FROM producao_sgpa
            WHERE DATE(data) BETWEEN ? AND ?
            GROUP BY equipamento_id, fazenda_id, DATE(data)
        ) AS sgpa
            ON sgpa.equipamento_id = p.equipamento_id 
           AND sgpa.fazenda_id = p.fazenda_id 
           AND sgpa.data = p.data
        LEFT JOIN fazendas f ON f.id = p.fazenda_id
        ORDER BY p.data, e.nome, f.nome
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data_inicio, $data_fim, $data_inicio, $data_fim, $data_inicio, $data_fim]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        $sql = "
        SELECT
            e.nome AS equipamento_nome,
            COALESCE(agro.total_ha, 0) AS ha_agro_access,
            COALESCE(sgpa.total_ha, 0) AS ha_sgpa,
            DATE(p.data) as data
        FROM producao_sgpa p
        JOIN equipamentos e ON p.equipamento_id = e.id
        LEFT JOIN (
            SELECT equipamento_id, SUM(hectares) AS total_ha, DATE(data_hora) as data
            FROM apontamentos
            WHERE DATE(data_hora) BETWEEN ? AND ?
            GROUP BY equipamento_id, DATE(data_hora)
        ) AS agro 
        ON agro.equipamento_id = p.equipamento_id AND agro.data = DATE(p.data)
        LEFT JOIN (
            SELECT equipamento_id, SUM(hectares_sgpa) AS total_ha, DATE(data) as data
            FROM producao_sgpa
            WHERE DATE(data) BETWEEN ? AND ?
            GROUP BY equipamento_id, DATE(data)
        ) AS sgpa
        ON sgpa.equipamento_id = p.equipamento_id AND sgpa.data = DATE(p.data)
        WHERE DATE(p.data) BETWEEN ? AND ?
        ORDER BY e.nome, DATE(p.data)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data_inicio, $data_fim, $data_inicio, $data_fim, $data_inicio, $data_fim]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $_SESSION['error_message'] = "Erro ao buscar dados: " . $e->getMessage();
}
?>

<link rel="stylesheet" href="/public/static/css/admin_comparativo.css">

<div class="container">
    <div class="page-header"><h2>Conciliação de Produção</h2></div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['info_message'])): ?>
        <div class="alert alert-info"><?= $_SESSION['info_message']; unset($_SESSION['info_message']); ?></div>
    <?php endif; ?>

    <!-- Filtro de período -->
    <div class="card">
        <div class="card-header flex-between">
            <h3>Comparativo de Produção</h3>
            <form method="GET" action="/comparativo" class="flex">
                <label for="data_inicio">De:</label>
                <input type="date" id="data_inicio" name="data_inicio" value="<?= htmlspecialchars($data_inicio) ?>" class="form-input">
                <label for="data_fim">Até:</label>
                <input type="date" id="data_fim" name="data_fim" value="<?= htmlspecialchars($data_fim) ?>" class="form-input">
                <button type="submit" class="btn btn-secondary">Filtrar</button>
            </form>
        </div>
    </div>

    <!-- Gráfico comparativo -->
    <div class="card">
        <div class="card-body">
            <canvas id="comparativoChart" height="150"></canvas>
        </div>
    </div>

    <!-- Tabela comparativa -->
    <div class="card">
        <div class="card-body table-container">
            <?php if (empty($resultados)): ?>
                <p class="text-center">Nenhum dado encontrado para o período selecionado.</p>
            <?php else: ?>
                <table class="table-comparativo">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Equipamento</th>
                            <?php if ($producao_tem_fazenda): ?><th>Fazenda</th><?php endif; ?>
                            <th>Produção Apontada (Agro-Access)</th>
                            <th>Produção Oficial (SGPA)</th>
                            <th>Diferença</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultados as $row):
                            $ha_sgpa = (float) ($row['ha_sgpa'] ?? 0);
                            $ha_agro = (float) ($row['ha_agro_access'] ?? 0);
                            $dif = $ha_agro - $ha_sgpa;
                            $cor = $dif > 0 ? 'var(--accent)' : ($dif < 0 ? 'var(--danger)' : '');
                            $data_display = isset($row['data']) ? (new DateTime($row['data']))->format('d/m/Y') : 'N/A';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($data_display) ?></td>
                            <td><?= htmlspecialchars($row['equipamento_nome'] ?? 'N/A') ?></td>
                            <?php if ($producao_tem_fazenda): ?>
                                <td><?= htmlspecialchars($row['fazenda_nome'] ?? 'N/A') ?></td>
                            <?php endif; ?>
                            <td><?= number_format($ha_agro, 2, ',', '.') ?> ha</td>
                            <td><?= number_format($ha_sgpa, 2, ',', '.') ?> ha</td>
                            <td style="color: <?= $cor ?>; font-weight: bold;">
                                <?= ($dif > 0 ? '+' : '') . number_format($dif, 2, ',', '.') ?> ha
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('comparativoChart').getContext('2d');
const labels = <?= json_encode(array_map(fn($r)=>$r['equipamento_nome'], $resultados)) ?>;
const dataAgro = <?= json_encode(array_map(fn($r)=>(float)$r['ha_agro_access'], $resultados)) ?>;
const dataSGPA = <?= json_encode(array_map(fn($r)=>(float)$r['ha_sgpa'], $resultados)) ?>;

new Chart(ctx, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            { label: 'SGPA', data: dataSGPA, backgroundColor: '#4d9990' },
            { label: 'Campo', data: dataAgro, backgroundColor: '#eead4d' }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true, title: { display: true, text: 'Hectares' } } }
    }
});
</script>

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>