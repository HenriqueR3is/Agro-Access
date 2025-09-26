<?php
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin','cia_admin','cia_dev'];

if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
    header("Location: /");
    exit();
}

// Inclui o cabeçalho compartilhado
require_once __DIR__ . '/../../../app/includes/header.php';
require_once __DIR__.'/../../../app/lib/Audit.php';

$feedback_message = '';
if (isset($_SESSION['feedback_message'])) {
    $feedback_message = $_SESSION['feedback_message'];
    unset($_SESSION['feedback_message']);
}

// Lógica para salvar/atualizar metas em massa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['metas'])) {
    $metas = $_POST['metas'];
    $success_count = 0;
    
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO metas_unidade_operacao (unidade_id, operacao_id, meta_diaria, meta_mensal)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE meta_diaria = VALUES(meta_diaria), meta_mensal = VALUES(meta_mensal)
        ");

        foreach ($metas as $meta) {
            $unidade_id = $meta['unidade_id'];
            $operacao_id = $meta['operacao_id'];
            // Garante que os valores são numéricos e tratam vírgula como decimal
            $meta_diaria = trim($meta['meta_diaria']) !== '' ? (float)str_replace(',', '.', $meta['meta_diaria']) : 0;
            $meta_mensal = trim($meta['meta_mensal']) !== '' ? (float)str_replace(',', '.', $meta['meta_mensal']) : 0;

            // Evita salvar metas com valores inválidos
            if ($unidade_id && $operacao_id) {
                $stmt->execute([$unidade_id, $operacao_id, $meta_diaria, $meta_mensal]);
                $success_count++;
            }
        }
        $pdo->commit();
        $_SESSION['feedback_message'] = "{$success_count} meta(s) salva(s) com sucesso!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $feedback_message = "Erro ao salvar metas: " . $e->getMessage();
    }
    header("Location: /metas");
    exit();
}

// Lógica para buscar todas as combinações de unidades e operações com suas metas
try {
    $unidades = $pdo->query("SELECT id, nome FROM unidades ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $tipos_operacao = $pdo->query("SELECT id, nome FROM tipos_operacao ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

    $metas_existentes = [];
    $stmt_metas = $pdo->query("SELECT unidade_id, operacao_id, meta_diaria, meta_mensal FROM metas_unidade_operacao");
    while ($row = $stmt_metas->fetch(PDO::FETCH_ASSOC)) {
        $metas_existentes[$row['unidade_id'] . '-' . $row['operacao_id']] = $row;
    }

    $metas_para_exibir = [];
    foreach ($unidades as $unidade) {
        foreach ($tipos_operacao as $operacao) {
            $key = $unidade['id'] . '-' . $operacao['id'];
            $meta = $metas_existentes[$key] ?? ['meta_diaria' => '', 'meta_mensal' => ''];
            $metas_para_exibir[] = [
                'unidade_id' => $unidade['id'],
                'unidade_nome' => $unidade['nome'],
                'operacao_id' => $operacao['id'],
                'operacao_nome' => $operacao['nome'],
                'meta_diaria' => $meta['meta_diaria'],
                'meta_mensal' => $meta['meta_mensal']
            ];
        }
    }

} catch (PDOException $e) {
    $feedback_message = "Erro de conexão com o banco de dados: " . $e->getMessage();
    $metas_para_exibir = [];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Metas</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="/public/static/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
        <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link rel="stylesheet" href="/public/static/css/admin.css">
    <link rel="stylesheet" href="/public/static/css/admin_farms.css">
    <link rel="stylesheet" href="/public/static/css/admin_metas.css">
</head>
<body>

<main class="container">
    <h1>Gerenciar Metas por Unidade e Operação</h1>

    <?php if ($feedback_message): ?>
        <div class="alert-message <?php echo strpos($feedback_message, 'Erro') !== false ? 'error' : ''; ?>">
            <?php echo htmlspecialchars($feedback_message); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h3>Tabela de Edição de Metas</h3>
        <p class="instruction">Use os filtros para encontrar as metas que deseja editar. Altere os valores diretamente na tabela e clique em "Salvar" para atualizar todas as metas de uma vez.</p>
        
        <div class="filter-container">
            <div class="filter-group">
                <label for="filter-unidade">Unidade</label>
                <select id="filter-unidade" class="select-search">
                    <option value="">Todas as Unidades</option>
                    <?php foreach ($unidades as $unidade): ?>
                        <option value="<?= $unidade['id']; ?>"><?= htmlspecialchars($unidade['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="filter-operacao">Operação</label>
                <select id="filter-operacao" class="select-search">
                    <option value="">Todas as Operações</option>
                    <?php foreach ($tipos_operacao as $operacao): ?>
                        <option value="<?= $operacao['id']; ?>"><?= htmlspecialchars($operacao['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <form action="/metas" method="POST" id="metasForm">
            <div class="table-actions">
                <button type="submit" name="save_all_goals" class="btn-submit">Salvar Todas as Metas</button>
            </div>
            <div class="table-responsive">
                <table id="metasTable">
                    <thead>
                        <tr>
                            <th>Unidade</th>
                            <th>Operação</th>
                            <th>Meta Diária (ha)</th>
                            <th>Meta Mensal (ha)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($metas_para_exibir)): ?>
                            <?php foreach ($metas_para_exibir as $meta): ?>
                                <tr data-unidade-id="<?= $meta['unidade_id']; ?>" data-operacao-id="<?= $meta['operacao_id']; ?>">
                                    <td>
                                        <?= htmlspecialchars($meta['unidade_nome']); ?>
                                        <input type="hidden" name="metas[<?= $meta['unidade_id']; ?>-<?= $meta['operacao_id']; ?>][unidade_id]" value="<?= $meta['unidade_id']; ?>">
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($meta['operacao_nome']); ?>
                                        <input type="hidden" name="metas[<?= $meta['unidade_id']; ?>-<?= $meta['operacao_id']; ?>][operacao_id]" value="<?= $meta['operacao_id']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" name="metas[<?= $meta['unidade_id']; ?>-<?= $meta['operacao_id']; ?>][meta_diaria]" value="<?= htmlspecialchars($meta['meta_diaria']); ?>" placeholder="0.00">
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" name="metas[<?= $meta['unidade_id']; ?>-<?= $meta['operacao_id']; ?>][meta_mensal]" value="<?= htmlspecialchars($meta['meta_mensal']); ?>" placeholder="0.00">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center;">Não foi possível carregar as unidades ou operações.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </form>
    </div>
</main>

<script>
$(document).ready(function() {
    $('.select-search').select2({
        placeholder: "Selecione...",
        allowClear: true,
        width: '100%'
    });

    function applyFilters() {
        const selectedUnidade = $('#filter-unidade').val();
        const selectedOperacao = $('#filter-operacao').val();
        
        $('#metasTable tbody tr').each(function() {
            const row = $(this);
            const unidadeId = row.data('unidade-id');
            const operacaoId = row.data('operacao-id');
            
            const matchUnidade = !selectedUnidade || selectedUnidade == unidadeId;
            const matchOperacao = !selectedOperacao || selectedOperacao == operacaoId;
            
            if (matchUnidade && matchOperacao) {
                row.show();
            } else {
                row.hide();
            }
        });
    }

    $('#filter-unidade, #filter-operacao').on('change', applyFilters);
    applyFilters();
});
</script>

</body>
</html>
