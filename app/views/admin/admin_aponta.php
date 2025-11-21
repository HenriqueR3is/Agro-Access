<?php
date_default_timezone_set('America/Sao_Paulo');
session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';
require_once __DIR__ . '/../../../app/lib/Audit.php';
require_once __DIR__ . '/../../../app/includes/header.php';

// Segurança: apenas admin, cia_admin ou cia_dev
$tipoSess = strtolower($_SESSION['usuario_tipo'] ?? '');
$ADMIN_LIKE = ['admin', 'cia_admin', 'cia_dev'];
if (!isset($_SESSION['usuario_id']) || !in_array($tipoSess, $ADMIN_LIKE, true)) {
    header("Location: /");
    exit();
}

$feedback_message = '';
if (isset($_SESSION['feedback_message'])) {
    $feedback_message = $_SESSION['feedback_message'];
    unset($_SESSION['feedback_message']);
}

try {
    // Dados do usuário logado
    $current_user_id = (int)$_SESSION['usuario_id'];
    $stmt_user = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ?");
    $stmt_user->execute([$current_user_id]);
    $current_user = $stmt_user->fetch(PDO::FETCH_ASSOC);
    $current_user_name = $current_user['nome'] ?? 'Usuário Desconhecido';

    // Dropdowns do formulário
    $unidades_list = $pdo->query("SELECT id, nome FROM unidades ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $operacoes_list = $pdo->query("SELECT id, nome FROM tipos_operacao ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $equipamentos_list = $pdo->query("SELECT id, nome FROM equipamentos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $fazendas_list = $pdo->query("SELECT id, nome, codigo_fazenda FROM fazendas ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

    // Horas fixas do dropdown
    $report_hours = ['02:00', '04:00', '06:00', '08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00', '22:00', '00:00'];

    // Hoje em Brasília para filtro inicial
    $today_br = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');

    // Salvar novo apontamento
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
        try {
            $unidade_id = (int)$_POST['unidade_id'];
            $fazenda_id = (int)$_POST['fazenda_id'];
            $equipamento_id = (int)$_POST['equipamento_id'];
            $operacao_id = (int)$_POST['operacao_id'];
            $report_time = $_POST['report_time'];
            $status = $_POST['status'];
            $hectares = ($status === 'ativo') ? (float)$_POST['hectares'] : 0;
            $observacoes = ($status === 'parado') ? ($_POST['observacoes'] ?? '') : '';

            // Usa a data do "hoje" em Brasília + hora selecionada
            $hoje_brt = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
            $data_hora_local = (new DateTime($hoje_brt->format('Y-m-d') . ' ' . $report_time . ':00', new DateTimeZone('America/Sao_Paulo')))
                              ->format('Y-m-d H:i:s');

            $stmt = $pdo->prepare("
                INSERT INTO apontamentos
                (usuario_id, unidade_id, fazenda_id, equipamento_id, operacao_id, hectares, data_hora, hora_selecionada, observacoes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $current_user_id, $unidade_id, $fazenda_id, $equipamento_id, $operacao_id,
                $hectares, $data_hora_local, $report_time, $observacoes
            ]);

            $_SESSION['feedback_message'] = "Apontamento salvo com sucesso para às " . substr($report_time, 0, 5) . "!";
            header("Location: /admin_manual_entry");
            exit;
        } catch (PDOException $e) {
            $feedback_message = "Erro ao salvar apontamento: " . $e->getMessage();
        }
    }

    // Editar apontamento
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
        try {
            $apontamento_id = (int)$_POST['apontamento_id'];
            $unidade_id = (int)$_POST['unidade_id'];
            $fazenda_id = (int)$_POST['fazenda_id'];
            $equipamento_id = (int)$_POST['equipamento_id'];
            $operacao_id = (int)$_POST['operacao_id'];
            $report_time = $_POST['report_time'];
            $status = $_POST['status'];
            $hectares = ($status === 'ativo') ? (float)$_POST['hectares'] : 0;
            $observacoes = ($status === 'parado') ? ($_POST['observacoes'] ?? '') : '';

            // Usa a data do "hoje" em Brasília + hora selecionada
            $hoje_brt = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
            $data_hora_local = (new DateTime($hoje_brt->format('Y-m-d') . ' ' . $report_time . ':00', new DateTimeZone('America/Sao_Paulo')))
                              ->format('Y-m-d H:i:s');

            $stmt = $pdo->prepare("
                UPDATE apontamentos
                SET unidade_id = ?, fazenda_id = ?, equipamento_id = ?, operacao_id = ?, hectares = ?,
                    data_hora = ?, hora_selecionada = ?, observacoes = ?
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([
                $unidade_id, $fazenda_id, $equipamento_id, $operacao_id, $hectares,
                $data_hora_local, $report_time, $observacoes, $apontamento_id, $current_user_id
            ]);

            $_SESSION['feedback_message'] = "Apontamento atualizado com sucesso!";
            header("Location: /admin_manual_entry");
            exit;
        } catch (PDOException $e) {
            $feedback_message = "Erro ao atualizar apontamento: " . $e->getMessage();
        }
    }

    // Carregar apontamentos iniciais
    $sql_apontamentos = "
        SELECT 
            a.id, a.data_hora, a.hora_selecionada, DATE_FORMAT(a.data_hora, '%H:%i') AS hora_brasilia,
            a.hectares, a.observacoes, u.nome AS unidade, t.nome AS operacao,
            eq.nome AS equipamento, f.nome AS nome_fazenda, f.codigo_fazenda,
            a.unidade_id, a.fazenda_id, a.equipamento_id, a.operacao_id
        FROM apontamentos a
        JOIN unidades u ON a.unidade_id = u.id
        JOIN tipos_operacao t ON a.operacao_id = t.id
        JOIN equipamentos eq ON a.equipamento_id = eq.id
        JOIN fazendas f ON a.fazenda_id = f.id
        WHERE a.usuario_id = ? AND DATE(a.data_hora) = ?
        ORDER BY a.data_hora DESC
    ";
    $stmt_apontamentos = $pdo->prepare($sql_apontamentos);
    $stmt_apontamentos->execute([$current_user_id, $today_br]);
    $apontamentos = $stmt_apontamentos->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $feedback_message = "Erro de conexão com o banco de dados: " . $e->getMessage();
    $unidades_list = [];
    $operacoes_list = [];
    $equipamentos_list = [];
    $fazendas_list = [];
    $apontamentos = [];
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apontamento Manual - Administrador</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="/public/static/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.2/css/all.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
     <link rel="stylesheet" href="/public/static/css/admin.css">
    <link rel="stylesheet" href="/public/static/css/admin_dashboard.css">
</head>

<body>
    <h2>Apontamento Manual - <?php echo htmlspecialchars($current_user_name); ?></h2>

    <div class="card">
        <?php if ($feedback_message): ?>
            <div class="alert-message <?php echo (stripos($feedback_message, 'Erro') !== false ? 'error' : 'success'); ?>">
                <?php echo htmlspecialchars($feedback_message); ?>
            </div>
        <?php endif; ?>

        <div class="tab-navigation">
            <button class="tab-button active" data-tab="create">Novo Apontamento</button>
            <button class="tab-button" data-tab="list">Lista de Apontamentos</button>
        </div>

        <!-- Novo Apontamento -->
        <div class="tab-content active" id="create-tab">
            <div class="form-card">
                <h3>Novo Apontamento</h3>
                <form action="/admin_manual_entry" method="POST" id="entryForm">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="usuario_id" value="<?php echo (int)$current_user_id; ?>">

                    <div class="filters-group">
                        <div class="filter-item">
                            <label for="unidade_id">Unidade</label>
                            <select id="unidade_id" name="unidade_id" class="select-search" required>
                                <option value="">Selecione uma unidade...</option>
                                <?php foreach ($unidades_list as $unidade): ?>
                                    <option value="<?php echo (int)$unidade['id']; ?>">
                                        <?php echo htmlspecialchars($unidade['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label for="fazenda_id">Fazenda</label>
                            <select id="fazenda_id" name="fazenda_id" class="select-search" required>
                                <option value="">Selecione uma unidade primeiro...</option>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label for="report_time">Horário</label>
                            <select id="report_time" name="report_time" required>
                                <?php foreach ($report_hours as $hour): ?>
                                    <option value="<?php echo $hour; ?>" <?php echo $hour === '08:00' ? 'selected' : ''; ?>>
                                        <?php echo $hour; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label for="status">Status</label>
                            <select id="status" name="status" required>
                                <option value="ativo" selected>Ativo</option>
                                <option value="parado">Parado</option>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label for="operacao_id">Operação</label>
                            <select id="operacao_id" name="operacao_id" class="select-search" required>
                                <option value="">Selecione uma operação...</option>
                                <?php foreach ($operacoes_list as $op): ?>
                                    <option value="<?php echo (int)$op['id']; ?>">
                                        <?php echo htmlspecialchars($op['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label for="equipamento_id">Equipamento</label>
                            <select id="equipamento_id" name="equipamento_id" class="select-search" required>
                                <option value="">Selecione uma operação primeiro...</option>
                            </select>
                        </div>
                        <div class="filter-item" id="hectares-group">
                            <label for="hectares">Hectares</label>
                            <input type="number" step="0.01" id="hectares" name="hectares" placeholder="Ex: 15.5" required>
                        </div>
                        <div class="filter-item" id="reason-group" style="display: none;">
                            <label for="observacoes">Motivo da Parada</label>
                            <textarea id="observacoes" name="observacoes" rows="3" placeholder="Descreva o motivo da parada..."></textarea>
                        </div>
                    </div>
                    <button type="submit" class="button-excel">Salvar Apontamento</button>
                </form>
            </div>
        </div>

        <!-- Lista de Apontamentos -->
        <div class="tab-content" id="list-tab">
            <div class="filters-group">
                <div class="filter-item">
                    <label for="filter-date">Data</label>
                    <input type="date" id="filter-date" value="<?php echo htmlspecialchars($today_br); ?>">
                </div>
                <div class="filter-item">
                    <label for="filter-unidade">Unidade</label>
                    <select id="filter-unidade" class="select-search">
                        <option value="">Todas</option>
                        <?php foreach ($unidades_list as $unidade): ?>
                            <option value="<?php echo (int)$unidade['id']; ?>">
                                <?php echo htmlspecialchars($unidade['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label for="filter-operacao">Operação</label>
                    <select id="filter-operacao" class="select-search">
                        <option value="">Todas</option>
                        <?php foreach ($operacoes_list as $op): ?>
                            <option value="<?php echo (int)$op['id']; ?>">
                                <?php echo htmlspecialchars($op['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label for="filter-equipamento">Equipamento</label>
                    <select id="filter-equipamento" class="select-search">
                        <option value="">Todos</option>
                        <?php foreach ($equipamentos_list as $equip): ?>
                            <option value="<?php echo (int)$equip['id']; ?>">
                                <?php echo htmlspecialchars($equip['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="table-container">
                <table id="apontamentos-table">
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Unidade</th>
                            <th>Fazenda</th>
                            <th>Equipamento</th>
                            <th>Operação</th>
                            <th>Hectares</th>
                            <th>Observações</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($apontamentos as $row): ?>
                            <tr data-entry='<?php echo json_encode($row, JSON_UNESCAPED_UNICODE); ?>'>
                                <td><?php echo htmlspecialchars($row['hora_selecionada'] ?: $row['hora_brasilia']); ?></td>
                                <td><?php echo htmlspecialchars($row['unidade']); ?></td>
                                <td><?php echo htmlspecialchars($row['nome_fazenda']); ?> (<?php echo htmlspecialchars($row['codigo_fazenda']); ?>)</td>
                                <td><?php echo htmlspecialchars($row['equipamento']); ?></td>
                                <td><?php echo htmlspecialchars($row['operacao']); ?></td>
                                <td><?php echo number_format($row['hectares'], 2, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($row['observacoes'] ?: 'N/A'); ?></td>
                                <td>
                                    <button class="action-btn edit-btn" title="Editar"><i class="fas fa-edit"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal de Edição -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close-btn">&times;</span>
            <h3>Editar Apontamento</h3>
            <form id="editForm" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" id="edit-apontamento-id" name="apontamento_id">
                <div class="filters-group">
                    <div class="filter-item">
                        <label for="edit-unidade_id">Unidade</label>
                        <select id="edit-unidade_id" name="unidade_id" class="select-search" required>
                            <option value="">Selecione uma unidade...</option>
                            <?php foreach ($unidades_list as $unidade): ?>
                                <option value="<?php echo (int)$unidade['id']; ?>">
                                    <?php echo htmlspecialchars($unidade['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label for="edit-fazenda_id">Fazenda</label>
                        <select id="edit-fazenda_id" name="fazenda_id" class="select-search" required>
                            <option value="">Selecione uma unidade primeiro...</option>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label for="edit-report_time">Horário</label>
                        <select id="edit-report_time" name="report_time" required>
                            <?php foreach ($report_hours as $hour): ?>
                                <option value="<?php echo $hour; ?>">
                                    <?php echo $hour; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label for="edit-status">Status</label>
                        <select id="edit-status" name="status" required>
                            <option value="ativo">Ativo</option>
                            <option value="parado">Parado</option>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label for="edit-operacao_id">Operação</label>
                        <select id="edit-operacao_id" name="operacao_id" class="select-search" required>
                            <option value="">Selecione uma operação...</option>
                            <?php foreach ($operacoes_list as $op): ?>
                                <option value="<?php echo (int)$op['id']; ?>">
                                    <?php echo htmlspecialchars($op['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label for="edit-equipamento_id">Equipamento</label>
                        <select id="edit-equipamento_id" name="equipamento_id" class="select-search" required>
                            <option value="">Selecione uma operação primeiro...</option>
                        </select>
                    </div>
                    <div class="filter-item" id="edit-hectares-group">
                        <label for="edit-hectares">Hectares</label>
                        <input type="number" step="0.01" id="edit-hectares" name="hectares" placeholder="Ex: 15.5" required>
                    </div>
                    <div class="filter-item" id="edit-reason-group" style="display: none;">
                        <label for="edit-observacoes">Motivo da Parada</label>
                        <textarea id="edit-observacoes" name="observacoes" rows="3" placeholder="Descreva o motivo da parada..."></textarea>
                    </div>
                </div>
                <button type="submit" class="button-excel">Atualizar Apontamento</button>
            </form>
        </div>
    </div>

    <!-- Créditos -->
    <div class="signature-credit">
        <p class="sig-text">
            Desenvolvido por 
            <span class="sig-name">Bruno Carmo</span> & 
            <span class="sig-name">Henrique Reis</span>
        </p>
    </div>

    <script>
    $(document).ready(function () {
        // Inicializar Select2
        $('.select-search').select2({
            placeholder: "Clique ou digite para pesquisar...",
            allowClear: true,
            width: '100%'
        });

        // Carregar fazendas por unidade (para formulário de criação)
        function carregarFazendasPorUnidade(unidadeId, $select, callback) {
            $select.prop('disabled', true).html('<option value="">Carregando fazendas...</option>').trigger('change');

            if (!unidadeId) {
                $select.html('<option value="">Selecione uma unidade primeiro...</option>').prop('disabled', true).trigger('change');
                if (callback) callback();
                return;
            }

            $.ajax({
                url: '/get_fazendas',
                type: 'GET',
                data: { unidade_id: unidadeId },
                dataType: 'json'
            }).done(function (response) {
                $select.empty();
                if (response.success && response.fazendas.length) {
                    $select.append('<option value="">Selecione uma fazenda...</option>');
                    response.fazendas.forEach(f => $select.append(new Option(`${f.nome} (${f.codigo_fazenda || 's/ código'})`, f.id)));
                    $select.prop('disabled', false).trigger('change');
                } else {
                    $select.html('<option value="">Nenhuma fazenda disponível</option>').prop('disabled', true).trigger('change');
                }
                if (callback) callback();
            }).fail(function () {
                $select.html('<option value="">Erro ao carregar fazendas</option>').prop('disabled', true).trigger('change');
                if (callback) callback();
            });
        }

        // Carregar equipamentos por operação
        function carregarEquipamentosPorOperacao(operacaoId, $select, callback) {
            $select.prop('disabled', true).html('<option value="">Carregando equipamentos...</option>').trigger('change');

            if (!operacaoId) {
                $select.html('<option value="">Selecione uma operação primeiro...</option>').prop('disabled', true).trigger('change');
                if (callback) callback();
                return;
            }

            $.ajax({
                url: '/equipamentos',
                type: 'GET',
                data: { operacao_id: operacaoId },
                dataType: 'json'
            }).done(function (response) {
                $select.empty();
                if (response.success && response.equipamentos.length) {
                    $select.append('<option value="">Selecione um equipamento...</option>');
                    response.equipamentos.forEach(e => $select.append(new Option(e.nome, e.id)));
                    $select.prop('disabled', false).trigger('change');
                } else {
                    $select.html('<option value="">Nenhum equipamento disponível</option>').prop('disabled', true).trigger('change');
                }
                if (callback) callback();
            }).fail(function () {
                $select.html('<option value="">Erro ao carregar equipamentos</option>').prop('disabled', true).trigger('change');
                if (callback) callback();
            });
        }

        // Formulário de Criação
        $('#unidade_id').on('change', function () {
            carregarFazendasPorUnidade($(this).val(), $('#fazenda_id'));
        });
        $('#operacao_id').on('change', function () {
            carregarEquipamentosPorOperacao($(this).val(), $('#equipamento_id'));
        });

        // Status Ativo/Parado (Criação)
        $('#status').on('change', function () {
            const parado = ($(this).val() === 'parado');
            $('#hectares-group').toggle(!parado).find('input').prop('required', !parado).val(parado ? 0 : '');
            $('#reason-group').toggle(parado).find('textarea').prop('required', parado).val('');
        }).trigger('change');

        // Formulário de Edição
        $('#edit-unidade_id').on('change', function () {
            carregarFazendasPorUnidade($(this).val(), $('#edit-fazenda_id'));
        });
        $('#edit-operacao_id').on('change', function () {
            carregarEquipamentosPorOperacao($(this).val(), $('#edit-equipamento_id'));
        });
        $('#edit-status').on('change', function () {
            const parado = ($(this).val() === 'parado');
            $('#edit-hectares-group').toggle(!parado).find('input').prop('required', !parado).val(parado ? 0 : '');
            $('#edit-reason-group').toggle(parado).find('textarea').prop('required', parado).val('');
        });

        // Abas
        $('.tab-button').on('click', function () {
            $('.tab-button').removeClass('active');
            $('.tab-content').removeClass('active');
            $(this).addClass('active');
            const tabId = $(this).data('tab');
            $(`#${tabId}-tab`).addClass('active');
            if (tabId === 'list') {
                fetchApontamentos();
            }
        });

        // Filtrar apontamentos
        function fetchApontamentos() {
            const date = $('#filter-date').val();
            const unidade = $('#filter-unidade').val();
            const operacao = $('#filter-operacao').val();
            const equipamento = $('#filter-equipamento').val();

            $.ajax({
                url: '/admin_manual_entry',
                type: 'GET',
                data: {
                    ajax_data: 1,
                    type: 'table',
                    date: date,
                    unidade_id: unidade,
                    operacao_id: operacao,
                    equipamento_id: equipamento
                },
                dataType: 'json'
            }).done(function (data) {
                updateTable(data);
            }).fail(function () {
                console.error('Erro ao buscar apontamentos');
                updateTable([]);
            });
        }

        function updateTable(apontamentos) {
            const tbody = $('#apontamentos-table tbody').empty();
            if (!apontamentos.length) {
                tbody.append('<tr><td colspan="8" style="text-align: center;">Nenhum apontamento encontrado.</td></tr>');
                return;
            }

            apontamentos.forEach(row => {
                const hora = row.hora_selecionada || row.hora_brasilia || '--:--';
                const tr = $(`<tr data-entry='${JSON.stringify(row)}'></tr>`).append(`
                    <td>${hora}</td>
                    <td>${row.unidade}</td>
                    <td>${row.nome_fazenda} (${row.codigo_fazenda})</td>
                    <td>${row.equipamento}</td>
                    <td>${row.operacao}</td>
                    <td>${parseFloat(row.hectares).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</td>
                    <td>${row.observacoes || 'N/A'}</td>
                    <td>
                        <button class="action-btn edit-btn" title="Editar"><i class="fas fa-edit"></i></button>
                    </td>
                `);
                tbody.append(tr);
            });
        }

        // Filtros da tabela
        $('#filter-date, #filter-unidade, #filter-operacao, #filter-equipamento').on('change', fetchApontamentos);

        // Modal de Edição
        const modal = $('#editModal');
        const closeBtn = $('.close-btn');

        $(document).on('click', '.edit-btn', function () {
            const row = $(this).closest('tr');
            const entryData = JSON.parse(row.attr('data-entry'));

            // Preencher formulário de edição
            $('#edit-apontamento-id').val(entryData.id);
            $('#edit-unidade_id').val(entryData.unidade_id).trigger('change');
            $('#edit-report_time').val(entryData.hora_selecionada || entryData.hora_brasilia);
            $('#edit-status').val(entryData.hectares > 0 ? 'ativo' : 'parado').trigger('change');
            $('#edit-operacao_id').val(entryData.operacao_id).trigger('change');
            $('#edit-hectares').val(entryData.hectares || '');
            $('#edit-observacoes').val(entryData.observacoes || '');

            // Carregar fazendas e equipamentos
            setTimeout(() => {
                $('#edit-fazenda_id').val(entryData.fazenda_id).trigger('change');
                setTimeout(() => {
                    $('#edit-equipamento_id').val(entryData.equipamento_id).trigger('change');
                }, 300);
            }, 300);

            modal.show();
        });

        closeBtn.on('click', () => modal.hide());
        $(window).on('click', (e) => {
            if ($(e.target).is(modal)) modal.hide();
        });

        // Validação do formulário
        $('#entryForm, #editForm').on('submit', function (e) {
            const form = $(this);
            const status = form.find('select[name="status"]').val();
            const hectares = form.find('input[name="hectares"]');
            const observacoes = form.find('textarea[name="observacoes"]');

            if (status === 'ativo' && (!hectares.val() || parseFloat(hectares.val()) <= 0)) {
                e.preventDefault();
                alert('Para status ATIVO, informe os hectares trabalhados.');
                hectares.focus();
                return;
            }

            if (status === 'parado' && !observacoes.val().trim()) {
                e.preventDefault();
                alert('Para status PARADO, informe o motivo da parada.');
                observacoes.focus();
                return;
            }
        });
    });
    </script>
</body>
</html>