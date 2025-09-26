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

    <style>
:root {
            --primary-green: #2e7d32;
            --light-green: #4caf50;
            --dark-green: #1b5e20;
            --background-color: #f0f4f8;
            --card-bg: #ffffff;
            --text-color: #131313ff;
            --light-text: #000000ff;
            --border-color: #e0e0e0;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --transition-speed: 0.4s;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--background-light);
            color: var(--text-color-dark);
        }



        .card {
            background-color: var(--card-background);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            padding: 25px;
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
        }

        h1 {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            padding-bottom: 15px;
            margin-bottom: 25px;
            font-weight: 600;
        }

        h3 {
            margin-top: 0;
            font-weight: 600;
            color: var(--text-color-dark);
        }

        .instruction {
            font-size: 0.95em;
            color: var(--light-green);
            margin-bottom: 25px;
        }

        .alert-message {
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 8px;
            font-weight: 500;
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-message.error {
            background-color: #ffebee;
            color: #c62828;
            border-color: #c62828;
        }
        .alert-message:not(.error) {
            background-color: #e8f5e9;
            color: #2e7d32;
            border-color: #2e7d32;
        }

        .filter-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }
        @media (max-width: 768px) {
            .filter-container {
                grid-template-columns: 1fr;
            }
        }
        .filter-group label {
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
            color: var(--text-color-dark);
        }

        .btn-submit {
            background-color: var(--primary-color);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
            transition: background-color 0.3s ease, transform 0.2s ease;
        }
        .btn-submit:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .table-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        th {
            background-color: var(--background-light);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9em;
            color: var(--text-color-light);
        }

        tbody tr:nth-child(even) {
            background-color: rgba(0, 121, 107, 0.03);
        }

        tbody tr:hover {
            background-color: rgba(0, 121, 107, 0.08);
        }

        td input[type="number"] {
            width: 100%;
            max-width: 120px;
            padding: 8px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            box-sizing: border-box;
            transition: border-color 0.3s ease;
        }
        td input[type="number"]:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .select2-container--default .select2-selection--single {
            border: 1px solid var(--border-color) !important;
            border-radius: 6px !important;
            height: 42px !important;
            display: flex !important;
            align-items: center !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 40px;
        }
        
    </style>
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
