<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: /login");
    exit();
}

// Lógica de Importação do CSV (Nova Abordagem)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_csv'])) {
    if ($_FILES['arquivo_csv']['error'] == UPLOAD_ERR_OK) {
        $arquivoTmp = $_FILES['arquivo_csv']['tmp_name'];
        $handle = fopen($arquivoTmp, "r");

        // 1. Ignora as 3 primeiras linhas do arquivo
        fgetcsv($handle);
        fgetcsv($handle);
        fgetcsv($handle);

        $processados = 0; $ignorados = 0; $erros = 0;
        $equipamentos_nao_encontrados = [];

        $pdo->beginTransaction();
        try {
            $stmtEquip = $pdo->prepare("SELECT id FROM equipamentos WHERE nome LIKE ?");
            $stmtUpsert = $pdo->prepare(
                "INSERT INTO producao_solinftec (data, equipamento_id, hectares_solinftec) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE hectares_solinftec = VALUES(hectares_solinftec)"
            );

            // 2. Lê os dados a partir da 4ª linha
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // Verificação para pular linhas mal formatadas
                if (count($data) < 9) {
                    $erros++;
                    continue;
                }

                // 3. Mapeamento das colunas corretas
                // Unidade = $data[0] | Equipamento = $data[4] | Data = $data[7] | Área = $data[8]
                $nome_completo_equipamento = trim($data[4]);
                $codigo_equipamento = trim(explode('-', $nome_completo_equipamento)[0]);
                
                if (empty($codigo_equipamento)) {
                    $erros++;
                    continue;
                }

                // Converte a data de DD/MM/AAAA para AAAA-MM-DD
                $data_producao_str = trim($data[7]);
                $date_parts = explode('/', $data_producao_str);
                $data_producao = count($date_parts) === 3 ? "{$date_parts[2]}-{$date_parts[1]}-{$date_parts[0]}" : null;

                // Limpa e formata os hectares
                $hectares = (float)str_replace(',', '.', trim($data[8]));

                if (!$data_producao) {
                    $erros++;
                    continue;
                }

                // Busca o equipamento no nosso banco de dados
                $stmtEquip->execute(['%' . $codigo_equipamento . '%']);
                $equipamento = $stmtEquip->fetch();

                if ($equipamento) {
                    $equipamento_id = $equipamento['id'];
                    $stmtUpsert->execute([$data_producao, $equipamento_id, $hectares]);
                    $processados++;
                } else {
                    $ignorados++;
                    if (!in_array($nome_completo_equipamento, $equipamentos_nao_encontrados)) {
                        $equipamentos_nao_encontrados[] = $nome_completo_equipamento;
                    }
                }
            }
            
            $pdo->commit();
            $_SESSION['success_message'] = "Importação concluída! $processados registros processados.";
            if ($ignorados > 0) {
                $_SESSION['info_message'] = "$ignorados registros foram ignorados pois os seguintes equipamentos não foram encontrados: " . implode(', ', $equipamentos_nao_encontrados);
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Erro na importação: " . $e->getMessage();
        }
        fclose($handle);
    } else {
        $_SESSION['error_message'] = "Erro no upload do arquivo.";
    }
    header("Location: /comparativo");
    exit();
}

require_once __DIR__ . '/../../../app/includes/header.php';

// Lógica de Comparação
$data_filtro = $_GET['data'] ?? date('Y-m-d');
$resultados = [];

try {
    $sql = "
        SELECT 
            e.nome AS equipamento_nome,
            COALESCE(agro_access.total_ha, 0) AS ha_agro_access,
            COALESCE(solinftec.hectares_solinftec, 0) AS ha_solinftec
        FROM equipamentos e
        LEFT JOIN (
            SELECT equipamento_id, SUM(hectares) as total_ha
            FROM apontamentos
            WHERE DATE(data_hora) = :data_filtro
            GROUP BY equipamento_id
        ) AS agro_access ON e.id = agro_access.equipamento_id
        LEFT JOIN producao_solinftec solinftec ON e.id = solinftec.equipamento_id AND solinftec.data = :data_filtro
        WHERE agro_access.total_ha IS NOT NULL OR solinftec.hectares_solinftec IS NOT NULL
        ORDER BY e.nome
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':data_filtro' => $data_filtro]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Erro ao buscar dados: " . $e->getMessage();
}
?>

<link rel="stylesheet" href="/public/static/css/admin.css">

<div class="container">
    <div class="page-header">
        <h2>Conciliação de Produção</h2>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3>Importar Dados do Solinftec</h3></div>
        <div class="card-body">
            <form action="/comparativo" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Selecione o arquivo CSV exportado:</label>
                    <input type="file" name="arquivo_csv" accept=".csv" required class="form-input">
                </div>
                <button type="submit" class="btn btn-primary">Importar e Processar</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header flex-between">
            <h3>Comparativo do Dia</h3>
            <form method="GET" action="/comparativo" class="flex">
                <label>Filtrar por data:</label>
                <input type="date" name="data" value="<?= htmlspecialchars($data_filtro) ?>" class="form-input">
                <button type="submit" class="btn btn-secondary">Filtrar</button>
            </form>
        </div>
        <div class="card-body table-container">
            <table>
                <thead>
                    <tr>
                        <th>Equipamento</th>
                        <th>Produção Apontada (Agro-Access)</th>
                        <th>Produção Oficial (Solinftec)</th>
                        <th>Diferença (ha)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultados as $row): 
                        $diferenca = $row['ha_agro_access'] - $row['ha_solinftec'];
                        $cor_diferenca = $diferenca >= 0 ? 'var(--accent)' : 'var(--danger)';
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($row['equipamento_nome']) ?></td>
                            <td><?= number_format($row['ha_agro_access'], 2, ',', '.') ?> ha</td>
                            <td><?= number_format($row['ha_solinftec'], 2, ',', '.') ?> ha</td>
                            <td style="color: <?= $cor_diferenca ?>; font-weight: bold;">
                                <?= ($diferenca > 0 ? '+' : '') . number_format($diferenca, 2, ',', '.') ?> ha
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../app/includes/footer.php'; ?>