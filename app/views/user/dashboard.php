<?php
// Configurar o fuso horário para Brasília
date_default_timezone_set('America/Sao_Paulo');

session_start();
require_once __DIR__ . '/../../../config/db/conexao.php';

// Verifica a sessão para garantir que o usuário está logado
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

// Mensagens de feedback (sucesso ou erro)
$feedback_message = '';
if (isset($_SESSION['feedback_message'])) {
    $feedback_message = $_SESSION['feedback_message'];
    unset($_SESSION['feedback_message']);
}

// Configurações de metas (fixas para o exemplo)
$daily_goal = 25;
$monthly_goal = 500;

// Variáveis para armazenar os dados do dashboard
$daily_hectares = 0;
$monthly_hectares = 0;
$daily_entries = 0;
$monthly_entries = 0;

try {
    // Passo 1: Obter a unidade_id do usuário logado
    $usuario_id = $_SESSION['usuario_id'];
    $stmt_user = $pdo->prepare("SELECT unidade_id FROM usuarios WHERE id = ?");
    $stmt_user->execute([$usuario_id]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    $unidade_do_usuario = null;
    if ($user_data) {
        $unidade_do_usuario = $user_data['unidade_id'];
    }

    // Buscar operações baseadas nas permissões do usuário
    $tipos_operacao = [];
    $stmt_operacoes = $pdo->prepare("
        SELECT t.id, t.nome 
        FROM tipos_operacao t
        INNER JOIN usuario_operacao uo ON t.id = uo.operacao_id
        WHERE uo.usuario_id = ?
        ORDER BY t.nome
    ");
    $stmt_operacoes->execute([$usuario_id]);
    $tipos_operacao = $stmt_operacoes->fetchAll(PDO::FETCH_ASSOC);
    
    // Se não tiver operações específicas, buscar todas
    if (empty($tipos_operacao)) {
        $tipos_operacao = $pdo->query("SELECT id, nome FROM tipos_operacao ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Buscar todas as operações para o dropdown
    $todas_operacoes = $tipos_operacao;

    // Buscar todos os equipamentos da unidade do usuário inicialmente (vazio até selecionar operação)
    $todos_equipamentos = [];

    // Obter a data atual no fuso horário de Brasília
    $data_hoje_brasilia = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $data_hoje = $data_hoje_brasilia->format('Y-m-d');
    $mes_atual = $data_hoje_brasilia->format('m');
    $ano_atual = $data_hoje_brasilia->format('Y');

    // Busca dados de progresso diário (usando data de Brasília)
    $stmt_daily = $pdo->prepare("
        SELECT SUM(hectares) as total_ha, COUNT(*) as total_entries
        FROM apontamentos
        WHERE usuario_id = ? AND DATE(CONVERT_TZ(data_hora, '+00:00', '-03:00')) = ?
    ");
    $stmt_daily->execute([$usuario_id, $data_hoje]);
    $daily_data = $stmt_daily->fetch(PDO::FETCH_ASSOC);
    $daily_hectares = $daily_data['total_ha'] ?? 0;
    $daily_entries = $daily_data['total_entries'] ?? 0;

    // Busca dados de progresso mensal (usando data de Brasília)
    $stmt_monthly = $pdo->prepare("
        SELECT SUM(hectares) as total_ha, COUNT(*) as total_entries
        FROM apontamentos
        WHERE usuario_id = ? AND MONTH(CONVERT_TZ(data_hora, '+00:00', '-03:00')) = ? 
        AND YEAR(CONVERT_TZ(data_hora, '+00:00', '-03:00')) = ?
    ");
    $stmt_monthly->execute([$usuario_id, $mes_atual, $ano_atual]);
    $monthly_data = $stmt_monthly->fetch(PDO::FETCH_ASSOC);
    $monthly_hectares = $monthly_data['total_ha'] ?? 0;
    $monthly_entries = $monthly_data['total_entries'] ?? 0;

    // Buscar unidades
    $unidades = $pdo->query("SELECT id, nome, cluster FROM unidades ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

    // Passo 2: Buscar as fazendas da UNIDADE do usuário
    $fazendas = [];
    if ($unidade_do_usuario) {
        $stmt_fazendas = $pdo->prepare("SELECT id, nome, codigo_fazenda FROM fazendas WHERE unidade_id = ? ORDER BY nome");
        $stmt_fazendas->execute([$unidade_do_usuario]);
        $fazendas = $stmt_fazendas->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Se o usuário não tem unidade definida, buscar todas as fazendas
        $fazendas = $pdo->query("SELECT id, nome, codigo_fazenda FROM fazendas ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Dados de exemplo para a aba de gráficos comparativos
    $comparison_data = [];
    $current_date = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));

    // Gerar dados de exemplo para os últimos 7 dias
    for ($i = 6; $i >= 0; $i--) {
        $date = clone $current_date;
        $date->modify("-$i days");

        // Valores aleatórios para demonstração
        $ha_manual = rand(15, 35);
        $ha_solinftec = $ha_manual + rand(-8, 8); // Valor próximo ao manual com variação

        $comparison_data[] = [
            'data' => $date->format('Y-m-d'),
            'ha_manual' => $ha_manual,
            'ha_solinftec' => $ha_solinftec
        ];
    }

} catch (PDOException $e) {
    $feedback_message = "Erro de conexão com o banco de dados: " . $e->getMessage();
    $equipamentos = [];
    $unidades = [];
    $fazendas = [];
    $tipos_operacao = [];
    $comparison_data = [];
}

// 1. Horas para o apontamento
$report_hours = ['06:00', '08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00', '22:00', '00:00', '02:00', '04:00'];
$username = $_SESSION['usuario_nome'];
$user_role = $_SESSION['usuario_tipo'];
$usuario_id = $_SESSION['usuario_id'];

// Processar o formulário de apontamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario_id'])) {
    try {
        $usuario_id = $_POST['usuario_id'];
        $report_time = $_POST['report_time'];
        $status = $_POST['status'];
        $fazenda_id = $_POST['fazenda_id'];
        $equipamento_id = $_POST['equipamento_id'];
        $operacao_id = $_POST['operacao_id'];
        $hectares = ($status === 'ativo') ? $_POST['hectares'] : 0;
        $observacoes = ($status === 'parado') ? $_POST['observacoes'] : '';

        // Obter unidade_id da fazenda selecionada
        $stmt_unidade = $pdo->prepare("SELECT unidade_id FROM fazendas WHERE id = ?");
        $stmt_unidade->execute([$fazenda_id]);
        $fazenda_data = $stmt_unidade->fetch(PDO::FETCH_ASSOC);
        $unidade_id = $fazenda_data['unidade_id'] ?? $unidade_do_usuario;

        // Combinar data atual com o horário selecionado (usando fuso de Brasília)
        $data_atual = date('Y-m-d');
        $data_hora = $data_atual . ' ' . $report_time . ':00';
        
        // Converter para UTC antes de salvar no banco
        $datetime_brasilia = new DateTime($data_hora, new DateTimeZone('America/Sao_Paulo'));
        $datetime_utc = $datetime_brasilia->setTimezone(new DateTimeZone('UTC'));
        $data_hora_utc = $datetime_utc->format('Y-m-d H:i:s');

        // Inserir o apontamento no banco de dados
        $stmt = $pdo->prepare("
            INSERT INTO apontamentos
            (usuario_id, unidade_id, equipamento_id, operacao_id, hectares, data_hora, observacoes, fazenda_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $usuario_id,
            $unidade_id,
            $equipamento_id,
            $operacao_id,
            $hectares,
            $data_hora_utc, // Usando data/hora em UTC
            $observacoes,
            $fazenda_id // Adicionado o ID da fazenda
        ]);

        $_SESSION['feedback_message'] = "Apontamento salvo com sucesso!";
        header("Location: /dashboard");
        exit();

    } catch (PDOException $e) {
        $feedback_message = "Erro ao salvar apontamento: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo $user_role; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link rel="stylesheet" href="/public/static/css/styleDash.css">
    <link rel="stylesheet" href="/public/static/css/dashboard.css">

</head>
<body class="dashboard-body">
    <header class="main-header">
        <div class="header-content">
            <div class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M17.59 13.41a6 6 0 0 0-8.49-8.49L3 11v8h8l6.59-6.59zM19 12l-7-7l-2 2l7 7l2-2z"/></svg>
                <span>Agro-Dash</span>
            </div>
            <div class="user-info">
                <span>Olá, <strong><?php echo htmlspecialchars($username); ?></strong>! (<?php echo htmlspecialchars($user_role); ?>)</span>
                <a href="/" class="logout-btn">Sair</a>
            </div>
        </div>
    </header>

    <main class="container">
        <div class="timezone-info">
            <strong>Atenção</strong> Sistema em desenvolvimento pode haver problemas.

        </div>

        <?php if ($feedback_message): ?>
            <div class="alert-message <?php echo strpos($feedback_message, 'Erro') !== false ? 'error' : ''; ?>">
                <?php echo htmlspecialchars($feedback_message); ?>
            </div>
        <?php endif; ?>

        <div class="tab-navigation">
            <button class="tab-button active" data-tab="dashboard">Dashboard</button>
            <button class="tab-button" data-tab="comparison">Comparativos</button>
        </div>

        <div class="tab-content active" id="dashboard-tab">
            <section class="grid-container">
                <div class="card metric-card">
                    <h3>Progresso Diário</h3>
                    <div class="progress-chart">
                        <canvas id="dailyProgressChart"></canvas>
                        <div class="progress-text" id="dailyProgressText">0%</div>
                    </div>
                    <p><span id="dailyHectares"><?php echo number_format($daily_hectares, 2, ',', '.'); ?></span> ha de <span id="dailyGoal"><?php echo number_format($daily_goal, 0, ',', '.'); ?></span> ha</p>
                    <p><strong>Apontamentos hoje:</strong> <span id="dailyEntries"><?php echo htmlspecialchars($daily_entries); ?></span></p>
                </div>
                <div class="card metric-card">
                    <h3>Progresso Mensal</h3>
                    <div class="progress-bar-container">
                        <div class="progress-bar" id="monthlyProgressBar" style="width: <?php echo min(100, ($monthly_hectares / $monthly_goal) * 100); ?>%"></div>
                    </div>
                    <p class="progress-bar-label"><span id="monthlyHectares"><?php echo number_format($monthly_hectares, 2, ',', '.'); ?></span> ha de <span id="monthlyGoal"><?php echo number_format($monthly_goal, 0, ',', '.'); ?></span> ha (<span id="monthlyProgress"><?php echo min(100, number_format(($monthly_hectares / $monthly_goal) * 100, 2, ',', '.')); ?></span>%)</p>
                    <p><strong>Apontamentos no mês:</strong> <span id="monthlyEntries"><?php echo htmlspecialchars($monthly_entries); ?></span></p>
                </div>
            </section>

            <section class="grid-container">
                <div class="card form-card">
                    <h3>Novo Apontamento</h3>
                    <form action="/dashboard" method="POST" id="entryForm">
                        <input type="hidden" name="usuario_id" value="<?php echo htmlspecialchars($usuario_id); ?>">

                        <div class="form-row">
                            <div class="input-group">
                                <label for="fazenda_id">Fazenda</label>
                                <select id="fazenda_id" name="fazenda_id" class="select-search" required>
                                    <option value="">Selecione uma fazenda...</option>
                                    <?php
                                    foreach ($fazendas as $fazenda) {
                                        echo "<option value='{$fazenda['id']}'>{$fazenda['nome']} ({$fazenda['codigo_fazenda']})</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="input-group">
                                <label for="report_time">Horário</label>
                                <select id="report_time" name="report_time" required>
                                    <?php foreach ($report_hours as $hour): ?>
                                        <option value="<?php echo $hour; ?>" <?php echo $hour === '08:00' ? 'selected' : ''; ?>>
                                            <?php echo $hour; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="input-group">
                                <label for="status">Status</label>
                                <select id="status" name="status" required>
                                    <option value="ativo" selected>Ativo</option>
                                    <option value="parado">Parado</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="input-group" style="width: 100%;">
                                <label for="operation">Operação</label>
                                <select id="operation" name="operacao_id" class="select-search" required>
                                    <option value="">Selecione a operação...</option>
                                    <?php foreach ($todas_operacoes as $op) {
                                        echo "<option value='{$op['id']}' data-nome='{$op['nome']}'>{$op['nome']}</option>";
                                    } ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="input-group">
                                <label for="equipment">Equipamento</label>
                                <select id="equipment" name="equipamento_id" class="select-search" required disabled>
                                    <option value="">Primeiro selecione uma operação</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div id="hectares-group" class="input-group">
                                <label for="hectares">Hectares</label>
                                <input type="number" step="0.01" id="hectares" name="hectares" placeholder="Ex: 15.5" required>
                            </div>
                        </div>

                        <div id="reason-group" class="input-group" style="display: none;">
                            <label for="reason">Motivo da Parada</label>
                            <textarea id="reason" name="observacoes" rows="3" placeholder="Descreva o motivo da parada..."></textarea>
                        </div>

                        <button type="submit" class="btn-submit">Salvar Apontamento</button>
                    </form>
                </div>

                <div class="card list-card">
                    <div class="list-header">
                        <h3>Últimos Lançamentos</h3>
                        <div class="date-filter">
                            <input type="date" id="filter-date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <div id="no-entries-message" style="display: none; text-align: center; color: #777;">
                        Nenhum apontamento encontrado para esta data.
                    </div>
                    <ul id="recent-entries-list"></ul>
                </div>
            </section>
        </div>

        <div class="tab-content" id="comparison-tab">
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Comparativo de Hectares - Solinftec vs Manual</h3>
                    <div class="chart-filter">
                        <select id="comparison-period">
                            <option value="7">Últimos 7 dias</option>
                            <option value="15">Últimos 15 dias</option>
                            <option value="30">Últimos 30 dias</option>
                        </select>
                    </div>
                </div>
                <div class="chart-canvas-container">
                    <canvas id="comparisonChart"></canvas>
                </div>
            </div>

            <div class="card">
                <h3>Dados Detalhados</h3>
                <div class="table-responsive">
                    <table class="comparison-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Hectares Solinftec</th>
                                <th>Hectares Manual</th>
                                <th>Diferença</th>
                                <th>Variação</th>
                            </tr>
                        </thead>
                        <tbody id="comparison-table-body">
                            <?php if (!empty($comparison_data)): ?>
                                <?php foreach ($comparison_data as $data): ?>
                                    <?php
                                    $ha_solinftec = $data['ha_solinftec'] ?? 0;
                                    $ha_manual = $data['ha_manual'] ?? 0;
                                    $diferenca = $ha_solinftec - $ha_manual;
                                    $variacao = $ha_solinftec > 0 ? (($diferenca / $ha_solinftec) * 100) : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($data['data'])); ?></td>
                                        <td><?php echo number_format($ha_solinftec, 2, ',', '.'); ?></td>
                                        <td><?php echo number_format($ha_manual, 2, ',', '.'); ?></td>
                                        <td class="<?php echo $diferenca >= 0 ? 'difference-positive' : 'difference-negative'; ?>">
                                            <?php echo number_format($diferenca, 2, ',', '.'); ?>
                                        </td>
                                        <td class="<?php echo $diferenca >= 0 ? 'difference-positive' : 'difference-negative'; ?>">
                                            <?php echo number_format($variacao, 2, ',', '.'); ?>%
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center;">Nenhum dado disponível para comparação</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close-btn">&times;</span>
            <h3>Detalhes do Apontamento</h3>
            <form id="editForm">
                <input type="hidden" id="edit-id" name="id">
                <div class="form-row">
                    <div class="input-group">
                        <label>ID do Apontamento</label>
                        <input type="text" id="modal-id" readonly>
                    </div>
                </div>
                <div class="form-row">
                    <div class="input-group">
                        <label>Data/Hora (Brasília)</label>
                        <input type="text" id="modal-datetime" readonly>
                    </div>
                </div>
                <div class="form-row">
                    <div class="input-group">
                        <label>Fazenda</label>
                        <input type="text" id="modal-farm" readonly>
                    </div>
                    <div class="input-group">
                        <label>Equipamento</label>
                        <input type="text" id="modal-equipment" readonly>
                    </div>
                </div>
                <div class="form-row">
                    <div class="input-group">
                        <label>Operação</label>
                        <input type="text" id="modal-operation" readonly>
                    </div>
                    <div class="input-group">
                        <label>Hectares</label>
                        <input type="text" id="modal-hectares" readonly>
                    </div>
                </div>
                <div class="form-row">
                    <div class="input-group">
                        <label>Observações</label>
                        <textarea id="modal-reason" rows="3" readonly></textarea>
                    </div>
                </div>
                <p>O recurso de edição está em desenvolvimento.</p>
            </form>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        // Atualizar relógio com fuso horário de Brasília
        function updateBrasiliaTime() {
            const now = new Date();
            // Ajustar para o fuso horário de Brasília (UTC-3)
            const brasiliaOffset = -3 * 60; // UTC-3 em minutos
            const localOffset = now.getTimezoneOffset();
            const brasiliaTime = new Date(now.getTime() + (localOffset - brasiliaOffset) * 60000);
            
            const options = { 
                timeZone: 'America/Sao_Paulo',
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit',
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            };
            
            const formatter = new Intl.DateTimeFormat('pt-BR', options);
            const parts = formatter.formatToParts(brasiliaTime);
            
            let day, month, year, hour, minute, second;
            for (const part of parts) {
                if (part.type === 'day') day = part.value;
                if (part.type === 'month') month = part.value;
                if (part.type === 'year') year = part.value;
                if (part.type === 'hour') hour = part.value;
                if (part.type === 'minute') minute = part.value;
                if (part.type === 'second') second = part.value;
            }
            
            $('#current-time').text(`Horário atual: ${day}/${month}/${year} ${hour}:${minute}:${second} (Brasília)`);
        }
        
        // Atualizar a cada segundo
        setInterval(updateBrasiliaTime, 1000);
        updateBrasiliaTime();
        
        // Variável para armazenar a unidade do usuário
        const userUnidadeId = <?php echo $unidade_do_usuario ? $unidade_do_usuario : 'null'; ?>;
        
        // Função para carregar equipamentos baseado na operação selecionada
        function carregarEquipamentosPorOperacao(operacaoId, operacaoNome) {
            if (!operacaoId || !operacaoNome) {
                // Se nenhuma operação selecionada, desabilitar equipamentos
                $('#equipment').html('<option value="">Primeiro selecione uma operação</option>');
                $('#equipment').prop('disabled', true).trigger('change');
                return;
            }
            
            // Habilitar dropdown de equipamentos
            $('#equipment').prop('disabled', false);
            
            // Mostrar loading
            $('#equipment').html('<option value="">Carregando equipamentos...</option>').trigger('change');
            
            // Fazer requisição AJAX
            $.ajax({
                url: '/equipamentos',
                type: 'GET',
                data: {
                    operacao_nome: operacaoNome,
                    unidade_id: userUnidadeId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#equipment').html('<option value="">Selecione um equipamento...</option>');
                        if (response.equipamentos.length > 0) {
                            response.equipamentos.forEach(function(equipamento) {
                                $('#equipment').append('<option value="' + equipamento.id + '">' + equipamento.nome + '</option>');
                            });
                        } else {
                            $('#equipment').html('<option value="">Nenhum equipamento encontrado para ' + response.operacao_nome + '</option>');
                        }
                        $('#equipment').trigger('change');
                    } else {
                        alert('Erro ao carregar equipamentos: ' + response.error);
                        $('#equipment').html('<option value="">Erro ao carregar equipamentos</option>');
                    }
                },
                error: function() {
                    alert('Erro na requisição. Tente novamente.');
                    $('#equipment').html('<option value="">Erro ao carregar equipamentos</option>');
                }
            });
        }
        
        // Event listener para mudança de operação
        $('#operation').on('change', function() {
            const operacaoId = $(this).val();
            const operacaoNome = $(this).find('option:selected').data('nome');
            carregarEquipamentosPorOperacao(operacaoId, operacaoNome);
        });
        
        // Inicializar Select2
        $('.select-search').select2({
            placeholder: "Clique ou digite para pesquisar...",
            allowClear: true,
            width: '100%'
        });

        // Lógica para mostrar/esconder o campo de motivo de parada
        $('#status').on('change', function() {
            const status = $(this).val();
            const hectaresGroup = $('#hectares-group');
            const reasonGroup = $('#reason-group');

            if (status === 'parado') {
                hectaresGroup.hide();
                hectaresGroup.find('input').prop('required', false).val(0);
                reasonGroup.show();
                reasonGroup.find('textarea').prop('required', true);
            } else {
                hectaresGroup.show();
                hectaresGroup.find('input').prop('required', true).val('');
                reasonGroup.hide();
                reasonGroup.find('textarea').prop('required', false).val('');
            }
        }).trigger('change');

        // Gráfico de Progresso Diário (Chart.js)
        const dailyProgressCtx = document.getElementById('dailyProgressChart').getContext('2d');
        const dailyGoal = <?php echo $daily_goal; ?>;
        const dailyHectares = <?php echo $daily_hectares; ?>;
        let dailyPercentage = Math.min(100, (dailyHectares / dailyGoal) * 100);

        const dailyProgressChart = new Chart(dailyProgressCtx, {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [dailyHectares, Math.max(0, dailyGoal - dailyHectares)],
                    backgroundColor: ['#00796b', '#e0e0e0'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '80%',
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                }
            }
        });
        $('#dailyProgressText').text(`${Math.round(dailyPercentage)}%`);

        // Função para buscar e exibir os apontamentos
        function fetchApontamentos(date) {
            const userId = <?php echo json_encode($_SESSION['usuario_id']); ?>;
            $('#recent-entries-list').html('<div style="text-align: center;">Carregando...</div>');
            $('#no-entries-message').hide();

            $.ajax({
                url: '/apontamentos',
                type: 'GET',
                data: { date: date, usuario_id: userId },
                dataType: 'json',
                success: function(response) {
                    const list = $('#recent-entries-list');
                    list.empty();
                    if (response.length > 0) {
                        response.forEach(function(entry) {
                            const entryJson = JSON.stringify(entry);
                            
                            // Converter data/hora UTC para fuso de Brasília
                            const dataHoraUTC = new Date(entry.data_hora + 'Z');
                            const options = { 
                                timeZone: 'America/Sao_Paulo',
                                hour: '2-digit', 
                                minute: '2-digit'
                            };
                            const formatter = new Intl.DateTimeFormat('pt-BR', options);
                            const horaBrasilia = formatter.format(dataHoraUTC);
                            
                            const listItem = `
                                <li class="entry-item" data-entry-id="${entry.id}" data-entry='${entryJson}'>
                                    <div class="entry-details">
                                        <strong>${entry.equipamento_nome}</strong>
                                        <p>${entry.unidade_nome} - ${entry.operacao_nome}</p>
                                        <small>Hectares: ${entry.hectares} | Hora: ${horaBrasilia}</small>
                                    </div>
                                    <div class="entry-action">
                                        <button class="open-modal-btn">Detalhes</button>
                                    </div>
                                </li>
                            `;
                            list.append(listItem);
                        });
                    } else {
                        $('#no-entries-message').show();
                    }
                },
                error: function() {
                    list.html('<div style="text-align: center; color: red;">Erro ao carregar os apontamentos.</div>');
                }
            });
        }

        // Ação ao mudar a data no filtro
        $('#filter-date').on('change', function() {
            fetchApontamentos($(this).val());
        });

        // Carrega os apontamentos da data atual ao carregar a página
        fetchApontamentos($('#filter-date').val());

        // Lógica do Modal
        const modal = $('#editModal');
        const closeBtn = $('.close-btn');

        // Função para abrir o modal
        window.openModal = function(entry) {
            $('#modal-id').val(entry.id);
            
            // Converter data/hora UTC para fuso de Brasília
            const dataHoraUTC = new Date(entry.data_hora + 'Z');
            const options = { 
                timeZone: 'America/Sao_Paulo',
                day: '2-digit', 
                month: '2-digit', 
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            const formatter = new Intl.DateTimeFormat('pt-BR', options);
            const dataHoraBrasilia = formatter.format(dataHoraUTC);
            
            $('#modal-datetime').val(dataHoraBrasilia);
            $('#modal-farm').val(entry.unidade_nome);
            $('#modal-equipment').val(entry.equipamento_nome);
            $('#modal-operation').val(entry.operacao_nome);
            $('#modal-hectares').val(entry.hectares);
            $('#modal-reason').val(entry.observacoes);
            modal.show();
        };

        // Event listener para abrir o modal quando o botão é clicado
        $('#recent-entries-list').on('click', '.open-modal-btn', function() {
            const listItem = $(this).closest('.entry-item');
            const entryJson = listItem.data('entry');
            openModal(entryJson);
        });

        closeBtn.on('click', function() {
            modal.hide();
        });

        $(window).on('click', function(event) {
            if ($(event.target).is(modal)) {
                modal.hide();
            }
        });

        // Controle das abas
        $('.tab-button').on('click', function() {
            $('.tab-button').removeClass('active');
            $('.tab-content').removeClass('active');

            $(this).addClass('active');
            const tabId = $(this).data('tab');
            $(`#${tabId}-tab`).addClass('active');

            if (tabId === 'comparison') {
                updateComparisonChart();
            }
        });

        // Inicializar o gráfico de comparação
        updateComparisonChart();
    });

    // Dados de exemplo para os gráficos comparativos
    const comparisonData = <?php echo json_encode($comparison_data); ?>;

    // Função para atualizar o gráfico de comparação
    function updateComparisonChart() {
        const period = $('#comparison-period').val();
        renderComparisonChart(comparisonData);
    }

    // Renderizar gráfico de comparação
    function renderComparisonChart(data) {
        const ctx = document.getElementById('comparisonChart').getContext('2d');

        if (window.comparisonChartInstance) {
            window.comparisonChartInstance.destroy();
        }

        const labels = data.map(item => {
            const date = new Date(item.data);
            return date.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
        });

        const manualData = data.map(item => parseFloat(item.ha_manual) || 0);
        const solinftecData = data.map(item => parseFloat(item.ha_solinftec) || 0);

        window.comparisonChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Hectares Solinftec',
                        data: solinftecData,
                        backgroundColor: 'rgba(0, 121, 107, 0.7)',
                        borderColor: 'rgba(0, 121, 107, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Hectares Manual',
                        data: manualData,
                        backgroundColor: 'rgba(255, 152, 0, 0.7)',
                        borderColor: 'rgba(255, 152, 0, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Hectares'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Data'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Comparativo de Hectares - Solinftec vs Manual'
                    }
                }
            }
        });
    }

    // Event listener para o filtro de período
    $('#comparison-period').on('change', updateComparisonChart);
    </script>
</body>

<!-- Créditos -->
<div class="signature-credit">
  <p class="sig-text">
    Desenvolvido por 
    <span class="sig-name">Bruno Carmo</span> & 
    <span class="sig-name">Henrique Reis</span>
  </p>
</div>

</html>