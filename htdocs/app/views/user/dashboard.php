<?php
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

    // Busca dados de progresso diário
    $stmt_daily = $pdo->prepare("
        SELECT SUM(hectares) as total_ha, COUNT(*) as total_entries 
        FROM apontamentos 
        WHERE usuario_id = ? AND DATE(data_hora) = CURDATE()
    ");
    $stmt_daily->execute([$usuario_id]);
    $daily_data = $stmt_daily->fetch(PDO::FETCH_ASSOC);
    $daily_hectares = $daily_data['total_ha'] ?? 0;
    $daily_entries = $daily_data['total_entries'] ?? 0;

    // Busca dados de progresso mensal
    $stmt_monthly = $pdo->prepare("
        SELECT SUM(hectares) as total_ha, COUNT(*) as total_entries 
        FROM apontamentos 
        WHERE usuario_id = ? AND MONTH(data_hora) = MONTH(CURDATE()) AND YEAR(data_hora) = YEAR(CURDATE())
    ");
    $stmt_monthly->execute([$usuario_id]);
    $monthly_data = $stmt_monthly->fetch(PDO::FETCH_ASSOC);
    $monthly_hectares = $monthly_data['total_ha'] ?? 0;
    $monthly_entries = $monthly_data['total_entries'] ?? 0;
    
    // Coleta dados para o formulário
    $equipamentos = $pdo->query("SELECT id, nome FROM equipamentos")->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar unidades
    $unidades = $pdo->query("SELECT id, nome, cluster FROM unidades ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    
    // Passo 2: Buscar as fazendas da UNIDADE do usuário
    $fazendas = [];
    if ($unidade_do_usuario) {
        $stmt_fazendas = $pdo->prepare("SELECT id, nome, codigo_fazenda FROM fazendas WHERE unidade_id = ? ORDER BY nome");
        $stmt_fazendas->execute([$unidade_do_usuario]);
        $fazendas = $stmt_fazendas->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $tipos_operacao = $pdo->query("SELECT id, nome FROM tipos_operacao")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedback_message = "Erro de conexão com o banco de dados: " . $e->getMessage();
    $equipamentos = [];
    $unidades = [];
    $fazendas = [];
    $tipos_operacao = [];
}

// 1. Horas para o apontamento
$report_hours = ['06:00', '08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00', '22:00', '00:00', '02:00', '04:00'];
$username = $_SESSION['usuario_nome'];
$user_role = $_SESSION['usuario_tipo'];
$usuario_id = $_SESSION['usuario_id'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo $user_role; ?></title>
    <link rel="stylesheet" href="/public/static/css/styleDash.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
/* Base Styles */
:root {
  --primary-color: #00796b;
  --primary-dark: #004d40;
  --primary-light: #80cbc4;
  --secondary-color: #ff9800;
  --error-color: #d32f2f;
  --success-color: #388e3c;
  --text-color: #333;
  --text-light: #666;
  --bg-color: #f4f7f6;
  --card-bg: #fff;
  --border-color: #e0e0e0;
  --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
  --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
  --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
  --radius-sm: 8px;
  --radius-md: 12px;
  --radius-lg: 16px;
  --transition: all 0.3s ease;
}

.dashboard-body {
  font-family: 'Poppins', sans-serif;
  background-color: var(--bg-color);
  margin: 0;
  color: var(--text-color);
  line-height: 1.6;
  min-height: 100vh;
}

/* Header Styles */
.main-header {
  background-color: var(--primary-dark);
  color: #fff;
  padding: 1rem 2rem;
  box-shadow: var(--shadow-md);
  position: sticky;
  top: 0;
  z-index: 100;
}

.header-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
  max-width: 1200px;
  margin: 0 auto;
}

.logo {
  display: flex;
  align-items: center;
  font-weight: 600;
  font-size: 1.5rem;
  color: #fff;
}

.logo svg {
  margin-right: 10px;
  width: 24px;
  height: 24px;
}

.user-info {
  display: flex;
  align-items: center;
  gap: 15px;
}

.user-info span {
  font-size: 0.95rem;
}

.logout-btn {
  background-color: var(--error-color);
  color: #fff;
  padding: 8px 16px;
  border-radius: var(--radius-sm);
  text-decoration: none;
  font-weight: 500;
  transition: var(--transition);
  font-size: 0.9rem;
  display: flex;
  align-items: center;
  gap: 5px;
}

.logout-btn:hover {
  background-color: #b71c1c;
  transform: translateY(-2px);
}

/* Main Content */
.container {
  max-width: 1200px;
  margin: 2rem auto;
  padding: 0 2rem;
}

.grid-container {
  display: grid;
  gap: 2rem;
  margin-bottom: 2rem;
}

/* Card Styles */
.card {
  background-color: var(--card-bg);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  padding: 1.5rem;
  transition: var(--transition);
  border: 1px solid rgba(0,0,0,0.05);
}

.card:hover {
  transform: translateY(-5px);
  box-shadow: var(--shadow-lg);
}

.card h3 {
  color: var(--primary-dark);
  margin-top: 0;
  margin-bottom: 1.5rem;
  padding-bottom: 0.75rem;
  border-bottom: 2px solid var(--border-color);
  font-size: 1.25rem;
  font-weight: 600;
}

/* Metric Cards */
.metric-card {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.progress-chart {
  position: relative;
  width: 150px;
  height: 150px;
  margin: 0 auto 1.5rem;
}

.progress-text {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--primary-color);
}

.progress-bar-container {
  background-color: #e0e0e0;
  border-radius: 50px;
  height: 12px;
  margin: 1.5rem 0;
  overflow: hidden;
  width: 100%;
}

.progress-bar {
  height: 100%;
  width: 0%;
  background: linear-gradient(90deg, var(--primary-color), var(--primary-light));
  border-radius: 50px;
  transition: width 0.5s ease;
  position: relative;
  overflow: hidden;
}

.progress-bar::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: linear-gradient(90deg, 
    rgba(255,255,255,0.1) 0%, 
    rgba(255,255,255,0.3) 50%, 
    rgba(255,255,255,0.1) 100%);
  animation: shimmer 2s infinite;
}

@keyframes shimmer {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}

.progress-bar-label {
  font-weight: 600;
  color: var(--text-light);
  font-size: 0.95rem;
}

/* Form Styles */
.form-row {
  display: flex;
  gap: 1rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}

.input-group {
  flex: 1;
  min-width: 200px;
  display: flex;
  flex-direction: column;
}

.input-group label {
  font-weight: 600;
  margin-bottom: 8px;
  color: var(--text-light);
  font-size: 0.9rem;
}

.input-group select, 
.input-group input, 
.input-group textarea {
  padding: 12px;
  border: 1px solid var(--border-color);
  border-radius: var(--radius-sm);
  font-family: 'Poppins', sans-serif;
  font-size: 0.95rem;
  transition: var(--transition);
  background-color: #f9f9f9;
}

.input-group select:focus, 
.input-group input:focus, 
.input-group textarea:focus {
  outline: none;
  border-color: var(--primary-color);
  box-shadow: 0 0 0 2px rgba(0,121,107,0.2);
}

.input-group textarea {
  resize: vertical;
  min-height: 80px;
}

.btn-submit {
  background-color: var(--primary-color);
  color: #fff;
  border: none;
  padding: 14px 24px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-weight: 600;
  font-size: 1rem;
  transition: var(--transition);
  width: 100%;
  margin-top: 1rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.btn-submit:hover {
  background-color: var(--primary-dark);
  transform: translateY(-2px);
}

/* Alert Messages */
.alert-message {
  padding: 1rem;
  background-color: #e8f5e9;
  color: var(--success-color);
  border-radius: var(--radius-sm);
  margin-bottom: 1.5rem;
  border: 1px solid #c8e6c9;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 10px;
}

.alert-message.error {
  background-color: #ffebee;
  color: var(--error-color);
  border-color: #ffcdd2;
}

/* List Styles */
.list-card {
  display: flex;
  flex-direction: column;
}

.list-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
}

#filter-date {
  padding: 8px 12px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border-color);
  font-family: 'Poppins', sans-serif;
}

#recent-entries-list {
  list-style: none;
  padding: 0;
  margin: 0;
  max-height: 800px;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: var(--primary-light) #f1f1f1;
}

#recent-entries-list::-webkit-scrollbar {
  width: 6px;
}

#recent-entries-list::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 3px;
}

#recent-entries-list::-webkit-scrollbar-thumb {
  background-color: var(--primary-light);
  border-radius: 3px;
}

.entry-item {
  background-color: #f9f9f9;
  border: 1px solid var(--border-color);
  border-radius: var(--radius-sm);
  padding: 1rem;
  margin-bottom: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  transition: var(--transition);
}

.entry-item:hover {
  background-color: #f0f0f0;
  border-color: var(--primary-light);
}

.entry-details strong {
  display: block;
  color: var(--primary-dark);
  margin-bottom: 4px;
}

.entry-details p {
  margin: 4px 0;
  color: var(--text-light);
  font-size: 0.9rem;
}

.entry-details small {
  color: var(--text-light);
  font-size: 0.85rem;
  opacity: 0.8;
}

.entry-action button {
  background-color: var(--primary-color);
  color: #fff;
  border: none;
  padding: 8px 16px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-weight: 500;
  font-size: 0.9rem;
  transition: var(--transition);
  display: flex;
  align-items: center;
  gap: 5px;
}

.entry-action button:hover {
  background-color: var(--primary-dark);
}

.entry-details{
    padding-left: 10px;
}

/* Modal Styles */
.modal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  overflow: auto;
  background-color: rgba(0,0,0,0.5);
  backdrop-filter: blur(3px);
  padding: 2rem;
  box-sizing: border-box;
}

.modal-content {
  background-color: var(--card-bg);
  margin: 0 auto;
  padding: 2rem;
  border-radius: var(--radius-md);
  width: 100%;
  max-width: 600px;
  box-shadow: var(--shadow-lg);
  position: relative;
  animation: modalFadeIn 0.3s ease-out;
}

@keyframes modalFadeIn {
  from { opacity: 0; transform: translateY(-20px); }
  to { opacity: 1; transform: translateY(0); }
}

.close-btn {
  color: #aaa;
  position: absolute;
  top: 1rem;
  right: 1.5rem;
  font-size: 1.75rem;
  font-weight: bold;
  cursor: pointer;
  transition: var(--transition);
}

.close-btn:hover,
.close-btn:focus {
  color: var(--text-color);
  transform: rotate(90deg);
}

#editForm input[readonly],
#editForm textarea[readonly] {
  background-color: #f5f5f5;
  cursor: not-allowed;
}

/* Responsive Design */
@media (min-width: 768px) {
  .grid-container {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .header-content {
    padding: 0 1rem;
  }
  
  .logo {
    font-size: 1.4rem;
  }
}

@media (min-width: 1024px) {
  .grid-container {
    grid-template-columns: 1fr 2fr;
  }
  
  .metric-card {
    grid-column: span 1;
  }
  
  .modal-content {
    margin: 2rem auto;
  }
}

/* Utility Classes */
.no-entries {
  text-align: center;
  color: var(--text-light);
  padding: 2rem;
  font-size: 0.95rem;
}

.loading {
  display: inline-block;
  width: 20px;
  height: 20px;
  border: 3px solid rgba(0,121,107,0.3);
  border-radius: 50%;
  border-top-color: var(--primary-color);
  animation: spin 1s ease-in-out infinite;
  margin-right: 8px;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Select2 Customization */
.select2-container--default .select2-selection--single {
  height: 44px;
  border: 1px solid var(--border-color);
  border-radius: var(--radius-sm);
  padding: 8px 12px;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
  height: 42px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
  line-height: 42px;
  padding-left: 0;
}

.select2-container--default .select2-results__option--highlighted[aria-selected] {
  background-color: var(--primary-color);
}

.select2-container--default .select2-results__option[aria-selected=true] {
  background-color: #e0f2f1;
  color: var(--primary-dark);
}

.select2-container--default .select2-search--dropdown .select2-search__field {
  border: 1px solid var(--border-color);
  border-radius: var(--radius-sm);
  padding: 6px 12px;
}
    </style>
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
        <?php if ($feedback_message): ?>
            <div class="alert-message">
                <?php echo htmlspecialchars($feedback_message); ?>
            </div>
        <?php endif; ?>

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
                <form action="/submit" method="POST" id="entryForm">
                    <input type="hidden" name="usuario_id" value="<?php echo htmlspecialchars($usuario_id); ?>">

                    <div class="form-row">
                        <div class="input-group">
                            <label for="report_time">Horário</label>
                            <select id="report_time" name="report_time" required>
                                <?php foreach ($report_hours as $hour) echo "<option value='$hour'>$hour</option>"; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" required>
                                <option value="ativo">Ativo</option>
                                <option value="parado">Parado</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="input-group">
                            <label for="farm">Fazenda</label>
                            <select id="farm" name="fazenda_id" class="select-search" required>
                                <option value="">Selecione uma fazenda...</option>
                                <?php 
                                foreach ($fazendas as $fazenda) {
                                    echo "<option value='{$fazenda['id']}'>{$fazenda['nome']} ({$fazenda['codigo_fazenda']})</option>";
                                } 
                                ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="equipment">Equipamento</label>
                            <select id="equipment" name="equipamento_id" class="select-search" required>
                                <option value="">Selecione um equipamento...</option>
                                <?php foreach ($equipamentos as $eq) {
                                    echo "<option value='{$eq['id']}'>{$eq['nome']}</option>";
                                } ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="input-group" style="width: 100%;">
                            <label for="operation">Operação</label>
                            <select id="operation" name="operacao_id" class="select-search" required>
                                <option value="">Selecione a operação...</option>
                                <?php foreach ($tipos_operacao as $op) {
                                    echo "<option value='{$op['id']}'>{$op['nome']}</option>";
                                } ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div id="hectares-group" class="input-group">
                            <label for="hectares">Hectares</label>
                            <input type="number" step="0.01" id="hectares" name="hectares" placeholder="Ex: 15.5">
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
                        <label>Data/Hora</label>
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
            // Inicializa o Select2 nos dropdowns
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
            }).trigger('change'); // Dispara o evento no carregamento da página

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
                const list = $('#recent-entries-list'); // <<< VARIÁVEL MOVIDA PARA CIMA
                
                list.html('<div style="text-align: center;">Carregando...</div>');
                $('#no-entries-message').hide();
                
                $.ajax({
                    url: '/apontamentos',
                    type: 'GET',
                    data: { date: date, usuario_id: userId },
                    dataType: 'json',
                    success: function(response) {
                        list.empty(); // Agora a variável 'list' já existe aqui
                        if (response.length > 0) {
                            response.forEach(function(entry) {
                                const entryJson = JSON.stringify(entry);
                                const listItem = `
                                    <li class="entry-item" data-entry-id="${entry.id}" data-entry='${entryJson}'>
                                        <div class="entry-details">
                                            <strong>${entry.equipamento_nome}</strong>
                                            <p>${entry.unidade_nome} - ${entry.operacao_nome}</p>
                                            <small>Hectares: ${entry.hectares} | Hora: ${entry.data_hora.split(' ')[1].substring(0, 5)}</small>
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
                        // Agora a variável 'list' também existe aqui
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
                $('#modal-datetime').val(entry.data_hora);
                $('#modal-farm').val(entry.unidade_nome);
                $('#modal-equipment').val(entry.equipamento_nome);
                $('#modal-operation').val(entry.operacao_nome);
                $('#modal-hectares').val(entry.hectares);
                $('#modal-reason').val(entry.observacoes);
                modal.show();
            };

            // Event listener para abrir o modal quando o botão é clicado
            $('#recent-entries-list').on('click', '.open-modal-btn', function() {
                // Pega o elemento <li> pai do botão clicado
                const listItem = $(this).closest('.entry-item');
                // Pega a string JSON do atributo data-entry
                const entryJson = listItem.data('entry');
                // Analisa a string JSON para obter o objeto e abre o modal
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
        });
    </script>
</body>
</html>