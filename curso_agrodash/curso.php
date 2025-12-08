<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// --- Verifica se o usuário está logado ---
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// --- Obtém o ID do curso da URL ---
$curso_id = $_GET['id'] ?? null;
if (!$curso_id) {
    header("Location: dashboard.php");
    exit;
}

// Adicione isso após a definição das variáveis iniciais (após linha 73)
$cupons_disponiveis = [];
$cupons_usuario = [];

try {
    // Buscar cupons disponíveis para resgate com XP
    $stmt_cupons = $pdo->prepare("
        SELECT cd.*, 
               (cd.usos_maximos - COALESCE((SELECT COUNT(*) FROM cupons_resgatados WHERE cupom_id = cd.id), 0)) as usos_restantes
        FROM cupons_desconto cd
        WHERE cd.ativo = 1 
          AND (cd.validade IS NULL OR cd.validade >= CURDATE())
          AND (cd.usos_maximos IS NULL OR cd.usos_maximos > COALESCE((SELECT COUNT(*) FROM cupons_resgatados WHERE cupom_id = cd.id), 0))
        ORDER BY cd.valor DESC
    ");
    $stmt_cupons->execute();
    $cupons_disponiveis = $stmt_cupons->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar cupons já resgatados pelo usuário
    $stmt_cupons_usuario = $pdo->prepare("
        SELECT cr.*, cd.codigo, cd.tipo, cd.valor, cd.validade, cd.descricao
        FROM cupons_resgatados cr
        JOIN cupons_desconto cd ON cr.cupom_id = cd.id
        WHERE cr.usuario_id = ?
        ORDER BY cr.data_resgate DESC
        LIMIT 10
    ");
    $stmt_cupons_usuario->execute([$usuario_id]);
    $cupons_usuario = $stmt_cupons_usuario->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Erro ao buscar cupons: " . $e->getMessage());
}

// Adicione esta variável ao $initial_state (procure a linha onde está $initial_state = [...] e adicione)
// Encontre esta seção (por volta da linha 250-260):
$initial_state = [
    'curso_id' => $curso_id,
    'curso_info' => $curso_info,
    // ... outras variáveis existentes ...
];

// Adicione estas linhas no array $initial_state:
$initial_state = [
    'curso_id' => $curso_id,
    'curso_info' => $curso_info,
    'usuario_id' => $usuario_id,
    'usuario_nome' => $username,
    'usuario_tipo' => $user_tipo,
    'data_inicio_curso' => $data_inicio_curso,
    'progresso_modulos' => array_keys(array_filter($progresso_modulos, function($mod) { 
        return $mod['concluido']; 
    })),
    'total_modulos' => $total_modulos,
    'modulos_info' => $modulos_info,
    'prova_final_info' => $prova_final_info,
    'prova_final_perguntas' => $perguntas_final_formatadas,
    'prova_final_config' => $prova_final_db,
    'pontos_xp' => $pontos_xp, // Adicione isso
    'cupons_disponiveis' => $cupons_disponiveis, // Adicione isso
    'cupons_usuario' => $cupons_usuario // Adicione isso
];

$_SESSION['usuario_id'] = $_SESSION['usuario_id'] ?? 1;
$usuario_id = $_SESSION['usuario_id'];
$_SESSION['curso_atual'] = $curso_id;

// --- REGISTRAR DATA DE INÍCIO DO CURSO (PRIMEIRO ACESSO) ---
try {
    // Primeiro verifica se a coluna inicio_curso existe
    $check_column = $pdo->query("SHOW COLUMNS FROM progresso_curso LIKE 'inicio_curso'");
    $coluna_existe = $check_column->rowCount() > 0;
    
    if ($coluna_existe) {
        // Verificar se já existe um registro de progresso para este curso
        $check_progresso = $pdo->prepare("SELECT COUNT(*) FROM progresso_curso 
                                         WHERE usuario_id = ? AND curso_id = ?");
        $check_progresso->execute([$usuario_id, $curso_id]);
        $progresso_existente = $check_progresso->fetchColumn();
        
        if (!$progresso_existente) {
            // É o primeiro acesso - criar registro com data de início
            $registrar_inicio = $pdo->prepare("INSERT INTO progresso_curso 
                                              (usuario_id, curso_id, tipo, item_id, inicio_curso, data_conclusao, aprovado, tentativas) 
                                              VALUES (?, ?, 'curso', 'curso_geral', NOW(), NULL, 0, 0)");
            $registrar_inicio->execute([$usuario_id, $curso_id]);
            
            error_log("Data de início registrada para usuário $usuario_id no curso $curso_id");
            
            // Opcional: Feedback para o usuário
            $_SESSION['feedback_message'] = "Bem-vindo ao curso! Sua jornada de aprendizado começou agora.";
        } else {
            // Já existe progresso, mas vamos atualizar a data de início se estiver NULL
            $check_inicio = $pdo->prepare("SELECT inicio_curso FROM progresso_curso 
                                          WHERE usuario_id = ? AND curso_id = ? 
                                          ORDER BY id LIMIT 1");
            $check_inicio->execute([$usuario_id, $curso_id]);
            $inicio_atual = $check_inicio->fetch(PDO::FETCH_ASSOC);
            
            if (!$inicio_atual || $inicio_atual['inicio_curso'] === null) {
                $atualizar_inicio = $pdo->prepare("UPDATE progresso_curso 
                                                  SET inicio_curso = NOW() 
                                                  WHERE usuario_id = ? AND curso_id = ? 
                                                  ORDER BY id LIMIT 1");
                $atualizar_inicio->execute([$usuario_id, $curso_id]);
                error_log("Data de início atualizada para usuário $usuario_id no curso $curso_id");
            }
        }
    } else {
        error_log("AVISO: Coluna 'inicio_curso' não existe na tabela progresso_curso");
    }
} catch (PDOException $e) {
    error_log("Erro ao registrar data de início do curso: " . $e->getMessage());
}

// --- LÓGICA PARA BUSCAR DADOS DO CURSO, USUÁRIO E PROGRESSO ---
$username = 'Usuário';
$curso_info = null;
$modulos_info = [];
$progresso_modulos = [];
$prova_final_info = [ 'tentativas' => 0, 'bloqueado_ate' => null, 'aprovado' => false ];
$data_inicio_curso = null;
$db_error_message = null;

// Buscar informações do curso
$stmt_curso = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
$stmt_curso->execute([$curso_id]);
$curso_info = $stmt_curso->fetch(PDO::FETCH_ASSOC);

if (!$curso_info) {
    header("Location: dashboard.php");
    exit;
}

// Buscar nome do usuário
$stmt_user = $pdo->prepare("SELECT nome, email, tipo FROM usuarios WHERE id = ?");
$stmt_user->execute([$usuario_id]);
$user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
$username = $user_data['nome'] ?? ($_SESSION['usuario_nome'] ?? 'Usuário');
$user_tipo = $user_data['tipo'] ?? 'operador';

// Buscar módulos do curso
$stmt_modulos = $pdo->prepare("SELECT * FROM modulos WHERE curso_id = ? ORDER BY ordem ASC");
$stmt_modulos->execute([$curso_id]);
$modulos_db = $stmt_modulos->fetchAll(PDO::FETCH_ASSOC);

// Buscar progresso dos módulos
$check_curso_column = $pdo->query("SHOW COLUMNS FROM progresso_curso LIKE 'curso_id'");
$has_curso_column = $check_curso_column->rowCount() > 0;

if ($has_curso_column) {
    $stmt_progresso = $pdo->prepare("SELECT item_id, data_conclusao FROM progresso_curso WHERE usuario_id = ? AND curso_id = ? AND tipo = 'modulo'");
    $stmt_progresso->execute([$usuario_id, $curso_id]);
} else {
    $stmt_progresso = $pdo->prepare("SELECT item_id, data_conclusao FROM progresso_curso WHERE usuario_id = ? AND tipo = 'modulo'");
    $stmt_progresso->execute([$usuario_id]);
}

foreach ($stmt_progresso->fetchAll(PDO::FETCH_ASSOC) as $mod) {
    $progresso_modulos[$mod['item_id']] = [
        'concluido' => true,
        'data_conclusao' => $mod['data_conclusao']
    ];
}

// Buscar progresso da prova final
$item_id_final = 'final-curso-' . $curso_id;
$stmt_prova = $pdo->prepare("SELECT * FROM progresso_curso WHERE usuario_id = ? AND curso_id = ? AND tipo = 'prova' AND item_id = ?");
$stmt_prova->execute([$usuario_id, $curso_id, $item_id_final]);
if ($prova_db = $stmt_prova->fetch()) {
    $prova_final_info = $prova_db;
    $prova_final_info['aprovado'] = (bool)$prova_db['aprovado'];
    $prova_final_info['tentativas'] = intval($prova_db['tentativas']);
} else {
    $prova_final_info = [ 
        'tentativas' => 0, 
        'bloqueado_ate' => null, 
        'aprovado' => false,
        'nota' => 0
    ];
}

// Buscar conteúdos de cada módulo
$modulos_info = [];
foreach ($modulos_db as $modulo) {
    $stmt_conteudos = $pdo->prepare("SELECT * FROM conteudos WHERE modulo_id = ? ORDER BY ordem ASC");
    $stmt_conteudos->execute([$modulo['id']]);
    $conteudos = $stmt_conteudos->fetchAll(PDO::FETCH_ASSOC);
    
    // Processar conteúdos normais
$conteudos_processados = [];

foreach ($conteudos as $conteudo) {
    // Processar baseado no tipo
    switch ($conteudo['tipo']) {
        case 'texto':
            if (!empty($conteudo['conteudo'])) {
                $conteudo['conteudo'] = nl2br(htmlspecialchars($conteudo['conteudo']));
            }
            break;
            
        case 'video':
            // Preparar dados do vídeo
            $conteudo['video_data'] = [
                'url' => $conteudo['url_video'] ?? '',
                'arquivo' => $conteudo['arquivo'] ?? ''
            ];
            break;
            
        case 'imagem':
            // Preparar dados da imagem
            $conteudo['imagem_data'] = [
                'arquivo' => $conteudo['arquivo'] ?? '',
                'legenda' => $conteudo['descricao'] ?? ''
            ];
            break;
            
        case 'quiz':
            // Processar quiz se existir
            if (!empty($conteudo['conteudo'])) {
                $conteudo['quiz_data'] = json_decode($conteudo['conteudo'], true);
            }
            break;
    }
    
    $conteudos_processados[] = $conteudo;
}
    
    $modulos_info[$modulo['id']] = [
        'id' => $modulo['id'],
        'nome' => $modulo['titulo'],
        'descricao' => $modulo['descricao'],
        'concluido' => isset($progresso_modulos[$modulo['id']]),
        'duracao' => $modulo['duracao'] ?? '30 min',
        'icone' => $modulo['icone'] ?? 'fas fa-book',
        'data_conclusao' => $progresso_modulos[$modulo['id']]['data_conclusao'] ?? null,
        'conteudos' => $conteudos_processados
    ];
}

// Buscar prova final do curso
$prova_final_db = null;
$perguntas_final_formatadas = [];
try {
    $stmt_prova_final = $pdo->prepare("
        SELECT pf.* 
        FROM provas_finais pf 
        WHERE pf.curso_id = ?
    ");
    $stmt_prova_final->execute([$curso_id]);
    $prova_final_db = $stmt_prova_final->fetch(PDO::FETCH_ASSOC);
    
    if ($prova_final_db) {
        // Buscar perguntas da prova final
        $stmt_perguntas_final = $pdo->prepare("
            SELECT * 
            FROM prova_final_perguntas 
            WHERE prova_id = ? 
            ORDER BY ordem ASC
        ");
        $stmt_perguntas_final->execute([$prova_final_db['id']]);
        $perguntas_final_db = $stmt_perguntas_final->fetchAll(PDO::FETCH_ASSOC);
        
        // Formatar perguntas para o JavaScript
        foreach ($perguntas_final_db as $pergunta) {
            $opcoes = json_decode($pergunta['opcoes'], true);
            if (is_array($opcoes)) {
                $perguntas_final_formatadas[] = [
                    'pergunta' => $pergunta['pergunta'],
                    'opcoes' => $opcoes,
                    'resposta_correta' => (int)$pergunta['resposta_correta'],
                    'explicacao' => $pergunta['explicacao'] ?? ''
                ];
            }
        }
    }
} catch (PDOException $e) {
    error_log("Erro ao carregar prova final: " . $e->getMessage());
}

// Calcular estatísticas
$total_modulos = count($modulos_info);
$modulos_concluidos = count(array_filter($modulos_info, function($mod) { 
    return $mod['concluido']; 
}));
$todos_modulos_concluidos = $modulos_concluidos === $total_modulos;
$progresso_porcentagem = $total_modulos > 0 ? ($modulos_concluidos / $total_modulos) * 100 : 0;
$pontos_xp = ($modulos_concluidos * 100) + ($prova_final_info['aprovado'] ? 500 : 0);

// Agrupar todos os dados iniciais
$initial_state = [
    'curso_id' => $curso_id,
    'curso_info' => $curso_info,
    'usuario_id' => $usuario_id,
    'usuario_nome' => $username,
    'usuario_tipo' => $user_tipo,
    'data_inicio_curso' => $data_inicio_curso,
    'progresso_modulos' => array_keys(array_filter($progresso_modulos, function($mod) { 
        return $mod['concluido']; 
    })),
    'total_modulos' => $total_modulos,
    'modulos_info' => $modulos_info,
    'prova_final_info' => $prova_final_info,
    'prova_final_perguntas' => $perguntas_final_formatadas,
    'prova_final_config' => $prova_final_db
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($curso_info['titulo'] ?? 'Treinamento AgroDash') ?> - Plataforma</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="css/curso_style.css">
    <style>

        /* ===== VARIÁVEIS E CONFIGURAÇÕES GLOBAIS ===== */
:root {
  --primary: #32CD32;
  --primary-light: #7CFC00;
  --secondary: #FFD700;
  --background: #0f0f0f;
  --card: #1e1e1e;
  --progress: #2e7d32;
  --complete: #00C853;
  --xp: #FF6B00;
  --loja: #8B5CF6;
  --text-primary: #ffffff;
  --text-secondary: #a0a0a0;
  --text-muted: #666666;
  --border: rgba(255, 255, 255, 0.1);
  --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

/* ===== RESET E BASE ===== */
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: 'Roboto', 'Poppins', sans-serif;
  background-color: var(--background);
  color: var(--text-primary);
  line-height: 1.6;
  overflow-x: hidden;
}

/* ===== LAYOUT PRINCIPAL ===== */
.curso-container {
  display: grid;
  grid-template-columns: 300px 1fr;
  min-height: 100vh;
  gap: 0;
}

/* ===== HEADER ===== */
.top-header {
  grid-column: 1 / -1;
  background: var(--card);
  border-bottom: 1px solid var(--border);
  padding: 1rem 2rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  position: sticky;
  top: 0;
  z-index: 100;
  backdrop-filter: blur(10px);
}

.logo-header {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.logo-header img {
  height: 40px;
  width: auto;
}

.logo-header h1 {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--primary);
}

.user-profile {
  display: flex;
  align-items: center;
  gap: 1.5rem;
}

.user-name {
  font-weight: 500;
}

.user-xp {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  background: rgba(255, 107, 0, 0.1);
  padding: 0.5rem 1rem;
  border-radius: 20px;
  border: 1px solid rgba(255, 107, 0, 0.3);
}

.user-xp i {
  color: var(--xp);
}

.user-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  object-fit: cover;
}

.dashboard-icon {
  color: var(--text-primary);
  font-size: 1.2rem;
  transition: color 0.3s ease;
}

.dashboard-icon:hover {
  color: var(--primary);
}

/* ===== SIDEBAR ===== */
.sidebar {
  background: var(--card);
  border-right: 1px solid var(--border);
  padding: 2rem 1rem;
  height: calc(100vh - 80px);
  position: sticky;
  top: 80px;
  overflow-y: auto;
}

.menu-titulo {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 1rem;
  padding-left: 1rem;
}

#menu-principal,
#menu-modulos {
  list-style: none;
  margin-bottom: 2rem;
}

#menu-principal li,
#menu-modulos li {
  margin-bottom: 0.5rem;
}

#menu-principal li a,
#menu-modulos li {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem;
  border-radius: 8px;
  color: var(--text-secondary);
  text-decoration: none;
  transition: all 0.3s ease;
  cursor: pointer;
}

#menu-principal li.ativo,
#menu-modulos li.ativo {
  background: rgba(50, 205, 50, 0.1);
  color: var(--primary);
  border-left: 3px solid var(--primary);
}

#menu-principal li:hover,
#menu-modulos li:hover {
  background: rgba(255, 255, 255, 0.05);
  color: var(--text-primary);
}

#menu-principal li i,
#menu-modulos li i {
  width: 20px;
  text-align: center;
}

.modulo-meta {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-left: auto;
  font-size: 0.75rem;
}

.modulo-duracao {
  color: var(--text-muted);
}

.status-icon {
  font-size: 0.875rem;
}

.status-icon.fa-check-circle {
  color: var(--complete);
}

/* ===== SIDEBAR FOOTER ===== */
.sidebar-footer {
  margin-top: auto;
  padding-top: 2rem;
  border-top: 1px solid var(--border);
}

.btn-certificado {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  padding: 1rem;
  background: rgba(0, 200, 83, 0.1);
  color: var(--complete);
  text-decoration: none;
  border-radius: 8px;
  border: 1px solid rgba(0, 200, 83, 0.3);
  transition: all 0.3s ease;
  margin-bottom: 1.5rem;
}

.btn-certificado:hover {
  background: rgba(0, 200, 83, 0.2);
  transform: translateY(-2px);
}

.curso-progresso {
  text-align: center;
}

.progresso-texto {
  font-size: 0.875rem;
  color: var(--text-secondary);
  margin-bottom: 0.5rem;
}

.progress-bar-fundo {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 10px;
  height: 6px;
  overflow: hidden;
  margin-bottom: 0.5rem;
}

.progress-bar-fundo.mini {
  height: 4px;
}

.progress-bar-preenchimento {
  background: linear-gradient(90deg, var(--primary), var(--primary-light));
  height: 100%;
  border-radius: 10px;
  transition: width 0.5s ease;
}

.progresso-porcentagem {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--primary);
}

/* ===== CONTEÚDO PRINCIPAL ===== */
.main-content {
  padding: 2rem;
  background: var(--background);
  height: calc(100vh - 80px);
  overflow-y: auto;
}

/* ===== DASHBOARD VIEW ===== */
.dashboard-header {
  margin-bottom: 2rem;
}

.dashboard-header h2 {
  font-size: 2rem;
  font-weight: 700;
  color: var(--text-primary);
  margin-bottom: 0.5rem;
}

.curso-meta {
  display: flex;
  gap: 2rem;
}

.nivel-curso,
.duracao-curso {
  color: var(--text-secondary);
  font-size: 0.875rem;
}

/* ===== STATS GRID ===== */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1.5rem;
  margin-bottom: 2rem;
}

.stat-item {
  background: var(--card);
  padding: 1.5rem;
  border-radius: 12px;
  border: 1px solid var(--border);
  text-align: center;
  transition: all 0.3s ease;
}

.stat-item:hover {
  transform: translateY(-5px);
  box-shadow: var(--shadow);
}

.stat-item.aprovado {
  background: rgba(0, 200, 83, 0.1);
  border-color: rgba(0, 200, 83, 0.3);
}

.value {
  font-size: 2rem;
  font-weight: 700;
  color: var(--primary);
  margin-bottom: 0.5rem;
}

.stat-item.aprovado .value {
  color: var(--complete);
}

.label {
  font-size: 0.875rem;
  color: var(--text-secondary);
}

/* ===== DASHBOARD GRID ===== */
.dashboard-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 2rem;
}

.card-modulos,
.card-info {
  background: var(--card);
  border-radius: 12px;
  border: 1px solid var(--border);
  overflow: hidden;
}

.dashboard-card .dashboard-header {
  padding: 1.5rem;
  border-bottom: 1px solid var(--border);
  margin-bottom: 0;
}

.dashboard-card .dashboard-header h3 {
  font-size: 1.25rem;
  font-weight: 600;
  color: var(--text-primary);
  margin: 0;
}

/* ===== PROGRESSO ===== */
.progress-container {
  padding: 1.5rem;
  border-bottom: 1px solid var(--border);
}

.progress-text {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
  font-size: 0.875rem;
}

#progresso-porcentagem {
  color: var(--primary);
  font-weight: 600;
}

/* ===== LISTA DE MÓDULOS ===== */
.dashboard-modulos {
  list-style: none;
  padding: 1.5rem;
}

.dashboard-modulos li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 0;
  border-bottom: 1px solid var(--border);
}

.dashboard-modulos li:last-child {
  border-bottom: none;
}

.dashboard-modulos li.concluido {
  opacity: 0.7;
}

.modulo-info {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex: 1;
}

.modulo-info i {
  font-size: 1.25rem;
  color: var(--primary);
  width: 24px;
  text-align: center;
}

.modulo-detalhes {
  flex: 1;
}

.modulo-nome {
  display: block;
  font-weight: 500;
  color: var(--text-primary);
  margin-bottom: 0.25rem;
}

.modulo-meta {
  font-size: 0.75rem;
  color: var(--text-muted);
}

/* ===== BOTÕES ===== */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1.5rem;
  border: none;
  border-radius: 8px;
  font-weight: 500;
  text-decoration: none;
  cursor: pointer;
  transition: all 0.3s ease;
  font-family: inherit;
  font-size: 0.875rem;
}

.btn-sm {
  padding: 0.5rem 1rem;
  font-size: 0.75rem;
}

.btn-primary {
  background: var(--primary);
  color: white;
}

.btn-primary:hover {
  background: var(--primary-light);
  transform: translateY(-2px);
}

.btn-success {
  background: var(--complete);
  color: white;
}

.btn-success:hover {
  background: #00b347;
  transform: translateY(-2px);
}

.btn-carregar-modulo {
  background: rgba(50, 205, 50, 0.1);
  color: var(--primary);
  border: 1px solid rgba(50, 205, 50, 0.3);
}

.btn-carregar-modulo:hover {
  background: rgba(50, 205, 50, 0.2);
  transform: translateY(-2px);
}

/* ===== PRÓXIMOS PASSOS ===== */
#proximos-passos-content {

}

.alerta-progresso {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem;
  border-radius: 8px;
  margin-bottom: 1rem;
  background: rgba(59, 130, 246, 0.1);
  border: 1px solid rgba(59, 130, 246, 0.3);
}

.alerta-progresso.alerta-sucesso {
  background: rgba(0, 200, 83, 0.1);
  border-color: rgba(0, 200, 83, 0.3);
}

.alerta-progresso i {
  font-size: 1.25rem;
}

.alerta-progresso.alerta-sucesso i {
  color: var(--complete);
}

.tentativas-info {
  font-size: 0.875rem;
  color: var(--text-muted);
  margin-top: 1rem;
  text-align: center;
}

/* ===== MÓDULO VIEW ===== */
#modulo-view-container {
  background: var(--card);
  border-radius: 12px;
  border: 1px solid var(--border);

}

.modulo-header {
  margin-bottom: 2rem;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid var(--border);
}

.modulo-header h2 {
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--text-primary);
  margin-bottom: 0.5rem;
  display: flex;
  align-items: center;
  gap: 1rem;
}

.modulo-header h2 i {
  color: var(--primary);
}

.modulo-descricao {
  color: var(--text-secondary);
  font-size: 1.1rem;
  line-height: 1.6;
}

.conteudos-lista {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.conteudo-item {
  background: rgba(255, 255, 255, 0.05);
  border-radius: 8px;
  padding: 1.5rem;
  border: 1px solid var(--border);
  transition: all 0.3s ease;
}

.conteudo-item:hover {
  background: rgba(255, 255, 255, 0.08);
  transform: translateY(-2px);
}

.conteudo-item h4 {
  font-size: 1.25rem;
  font-weight: 600;
  color: var(--text-primary);
  margin-bottom: 1rem;
}

.conteudo-texto {
  color: var(--text-secondary);
  line-height: 1.7;
}

.conteudo-texto p {
  margin-bottom: 1rem;
}

.conteudo-texto p:last-child {
  margin-bottom: 0;
}

/* ===== PROVA FINAL VIEW ===== */
#prova-final-view {
  background: var(--card);
  border-radius: 12px;
  border: 1px solid var(--border);
  padding: 2rem;
}

.prova-final-container {
  max-width: 800px;
  margin: 0 auto;
}

.prova-header {
  text-align: center;
  margin-bottom: 2rem;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid var(--border);
}

.prova-header h2 {
  font-size: 2rem;
  font-weight: 700;
  color: var(--text-primary);
  margin-bottom: 0.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
}

.prova-header h2 i {
  color: var(--primary);
}

.curso-info {
  color: var(--text-secondary);
  font-size: 1.1rem;
}

.prova-info {
  background: rgba(50, 205, 50, 0.1);
  border: 1px solid rgba(50, 205, 50, 0.3);
  border-radius: 8px;
  padding: 1.5rem;
  margin-bottom: 2rem;
}

.prova-info h3 {
  font-size: 1.25rem;
  font-weight: 600;
  color: var(--text-primary);
  margin-bottom: 1rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
}

.info-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.875rem;
}

.info-item i {
  color: var(--primary);
  width: 16px;
  text-align: center;
}

/* ===== PERGUNTAS ===== */
.pergunta {
  background: rgba(255, 255, 255, 0.05);
  border-radius: 8px;
  padding: 1.5rem;
  border: 1px solid var(--border);
  margin-bottom: 1.5rem;
}

.pergunta-cabecalho {
  display: flex;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.pergunta-numero {
  background: var(--primary);
  color: white;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 0.875rem;
  flex-shrink: 0;
}

.pergunta-texto {
  font-size: 1.1rem;
  font-weight: 500;
  color: var(--text-primary);
  line-height: 1.5;
}

.opcoes {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.opcao {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.3s ease;
}

.opcao:hover {
  background: rgba(255, 255, 255, 0.05);
  border-color: var(--primary);
}

.opcao input[type="radio"] {
  display: none;
}

.opcao-letra {
  background: rgba(255, 255, 255, 0.1);
  color: var(--text-primary);
  width: 24px;
  height: 24px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 0.75rem;
  flex-shrink: 0;
}

.opcao-texto {
  color: var(--text-secondary);
  flex: 1;
}

.opcao input[type="radio"]:checked + label .opcao-letra {
  background: var(--primary);
  color: white;
}

.opcao input[type="radio"]:checked + label .opcao-texto {
  color: var(--text-primary);
  font-weight: 500;
}

/* ===== PROGRESSO DE RESPOSTAS ===== */
.progresso-respostas {
  background: var(--card);
  padding: 1rem 1.5rem;
  border-radius: 8px;
  border: 1px solid var(--border);
  margin-bottom: 2rem;
  font-weight: 500;
}

#contador-respostas {
  color: var(--primary);
  font-weight: 700;
}

#total-perguntas {
  color: var(--text-secondary);
}

/* ===== AÇÕES DA PROVA ===== */
.prova-actions {
  display: flex;
  gap: 1rem;
  justify-content: center;
  margin-top: 2rem;
  padding-top: 1.5rem;
  border-top: 1px solid var(--border);
}

/* ===== RESULTADOS ===== */
.resultado-prova {
  text-align: center;
  padding: 2rem;
}

.resultado-titulo {
  font-size: 2rem;
  font-weight: 700;
  color: var(--text-primary);
  margin-bottom: 1rem;
}

.resultado-nota {
  font-size: 3rem;
  font-weight: 700;
  color: var(--primary);
  margin-bottom: 1rem;
}

.resultado-mensagem {
  font-size: 1.1rem;
  color: var(--text-secondary);
  margin-bottom: 2rem;
  line-height: 1.6;
}

.resultado-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
  margin-bottom: 2rem;
}

.stat-item-resultado {
  background: rgba(255, 255, 255, 0.05);
  padding: 1.5rem;
  border-radius: 8px;
  border: 1px solid var(--border);
}

.stat-value {
  display: block;
  font-size: 2rem;
  font-weight: 700;
  color: var(--primary);
  margin-bottom: 0.5rem;
}

.stat-label {
  font-size: 0.875rem;
  color: var(--text-secondary);
}

.acoes-resultado {
  display: flex;
  gap: 1rem;
  justify-content: center;
  flex-wrap: wrap;
}

/* ===== TOASTS ===== */
#toast-container {
  position: fixed;
  top: 100px;
  right: 2rem;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.toast {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1rem 1.5rem;
  box-shadow: var(--shadow);
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 300px;
  transform: translateX(400px);
  transition: transform 0.3s ease;
}

.toast.show {
  transform: translateX(0);
}

.toast.success {
  border-left: 4px solid var(--complete);
}

.toast.error {
  border-left: 4px solid #ef4444;
}

.toast.info {
  border-left: 4px solid #3b82f6;
}

.toast.warning {
  border-left: 4px solid #f59e0b;
}

.toast-close {
  background: none;
  border: none;
  color: var(--text-secondary);
  cursor: pointer;
  margin-left: auto;
  padding: 0.25rem;
  border-radius: 4px;
  transition: all 0.3s ease;
}

.toast-close:hover {
  background: rgba(255, 255, 255, 0.1);
  color: var(--text-primary);
}

/* ===== UTILITÁRIOS ===== */
.oculto {
  display: none !important;
}

.text-center {
  text-align: center;
}

.mb-4 {
  margin-bottom: 1rem;
}

.mt-4 {
  margin-top: 1rem;
}

.flex {
  display: flex;
}

.items-center {
  align-items: center;
}

.justify-between {
  justify-content: space-between;
}

.gap-4 {
  gap: 1rem;
}

/* ===== RESPONSIVIDADE ===== */
@media (max-width: 1024px) {
  .curso-container {
    grid-template-columns: 1fr;
  }
  
  .sidebar {
    display: none;
  }
  
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .dashboard-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .top-header {
    padding: 1rem;
    flex-direction: column;
    gap: 1rem;
  }
  
  .user-profile {
    width: 100%;
    justify-content: space-between;
  }
  
  .main-content {
    padding: 1rem;
  }
  
  .stats-grid {
    grid-template-columns: 1fr;
    gap: 1rem;
  }
  
  .dashboard-header h2 {
    font-size: 1.5rem;
  }
  
  .curso-meta {
    flex-direction: column;
    gap: 0.5rem;
  }
  
  .pergunta-cabecalho {
    flex-direction: column;
    gap: 0.5rem;
  }
  
  .info-grid {
    grid-template-columns: 1fr;
  }
  
  .resultado-stats {
    grid-template-columns: 1fr;
  }
  
  .acoes-resultado {
    flex-direction: column;
  }
  
  .prova-actions {
    flex-direction: column;
  }
  
  #toast-container {
    right: 1rem;
    left: 1rem;
  }
  
  .toast {
    min-width: auto;
    width: 100%;
  }
}

@media (max-width: 480px) {
  .modulo-info {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.5rem;
  }
  
  .dashboard-modulos li {
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
  }
  
  .btn-carregar-modulo {
    width: 100%;
    justify-content: center;
  }
  
  .value {
    font-size: 1.5rem;
  }
  
  .stat-item {
    padding: 1rem;
  }
}

/* ===== ANIMAÇÕES ===== */
@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes slideIn {
  from {
    transform: translateX(-100%);
  }
  to {
    transform: translateX(0);
  }
}

@keyframes pulse {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.7;
  }
}

.fade-in {
  animation: fadeIn 0.5s ease-in-out;
}

.slide-in {
  animation: slideIn 0.3s ease-out;
}

.pulse {
  animation: pulse 2s infinite;
}

/* ===== ESTADOS DE CARREGAMENTO ===== */
.loading {
  position: relative;
  overflow: hidden;
}

.loading::after {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
  animation: loading 1.5s infinite;
}

@keyframes loading {
  0% {
    left: -100%;
  }
  100% {
    left: 100%;
  }
}

/* ===== SCROLLBAR PERSONALIZADA ===== */
::-webkit-scrollbar {
  width: 6px;
}

::-webkit-scrollbar-track {
  background: rgba(255, 255, 255, 0.05);
}

::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.2);
  border-radius: 3px;
}

::-webkit-scrollbar-thumb:hover {
  background: rgba(255, 255, 255, 0.3);
}

/* ===== FOCUS STATES ===== */
button:focus,
a:focus,
input:focus,
select:focus,
textarea:focus {
  outline: 2px solid var(--primary);
  outline-offset: 2px;
}

/* ===== PRINT STYLES ===== */
@media print {
  .top-header,
  .sidebar,
  .btn,
  .toast {
    display: none !important;
  }
  
  body {
    background: white;
    color: black;
  }
  
  .main-content {
    padding: 0;
    height: auto;
  }
  
  .curso-container {
    grid-template-columns: 1fr;
  }
}

        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .course-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: linear-gradient(145deg, #1a1a1a 0%, #2d2d2d 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        
        .slide {
            display: none;
            animation: fadeIn 0.5s ease-in-out;
        }
        
        .slide.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .progress-ring {
            transition: stroke-dashoffset 0.5s ease;
        }
        
        .conteudo-slide {
            min-height: 400px;
        }
        
        .btn-nav {
            transition: all 0.3s ease;
        }
        
        .btn-nav:hover {
            transform: scale(1.05);
        }
        
        .btn-nav:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            color: white;
            z-index: 1000;
            transform: translateX(150%);
            transition: transform 0.3s ease;
        }
        
        .toast.show {
            transform: translateX(0);
        }
        
        .toast.success {
            background-color: #10B981;
        }
        
        .toast.error {
            background-color: #EF4444;
        }
        
        .toast.info {
            background-color: #3B82F6;
        }
        
        .toast.warning {
            background-color: #F59E0B;
        }
        
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }

:root {
  --primary: #32CD32;
  --primary-light: #7CFC00;
  --primary-dark: #228B22;
  --secondary: #FFD700;
  --secondary-light: #FFE55C;
  --background: #0f0f0f;
  --background-light: #1a1a1a;
  --card: #1e1e1e;
  --card-light: #2a2a2a;
  --progress: #2e7d32;
  --complete: #00C853;
  --complete-light: #00E676;
  --xp: #FF6B00;
  --loja: #8B5CF6;
  --text-primary: #ffffff;
  --text-secondary: #e0e0e0;
  --text-muted: #a0a0a0;
  --border: rgba(255, 255, 255, 0.12);
  --border-light: rgba(255, 255, 255, 0.08);
  --shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.2);
  --shadow-lg: 0 20px 40px -10px rgba(0, 0, 0, 0.4);
  --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
  --gradient-card: linear-gradient(145deg, #1a1a1a 0%, #2d2d2d 100%);
  --gradient-dark: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 100%);
}

/* ===== RESET E BASE ===== */
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

html {
  scroll-behavior: smooth;
}

body {
  font-family: 'Roboto', 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
  background: var(--gradient-dark);
  color: var(--text-primary);
  line-height: 1.6;
  overflow-x: hidden;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

/* ===== LAYOUT PRINCIPAL ===== */
.curso-container {
  display: grid;
  grid-template-columns: 320px 1fr;
  min-height: 100vh;
  gap: 0;
  background: var(--gradient-dark);
}

/* ===== HEADER ===== */
.top-header {
  grid-column: 1 / -1;
  background: rgba(30, 30, 30, 0.95);
  backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border);
  padding: 1.25rem 2.5rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  position: sticky;
  top: 0;
  z-index: 1000;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
}

.logo-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  transition: transform 0.3s ease;
}

.logo-header:hover {
  transform: translateY(-2px);
}

.logo-header img {
  height: 45px;
  width: auto;
  border-radius: 12px;
}

.logo-header h1 {
  font-size: 1.75rem;
  font-weight: 800;
  background: var(--gradient-primary);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  letter-spacing: -0.5px;
}

.user-profile {
  display: flex;
  align-items: center;
  gap: 1.5rem;
}

.user-name {
  font-weight: 600;
  color: var(--text-primary);
  font-size: 1.1rem;
}

.user-xp {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  background: rgba(255, 107, 0, 0.15);
  padding: 0.75rem 1.25rem;
  border-radius: 50px;
  border: 1px solid rgba(255, 107, 0, 0.3);
  backdrop-filter: blur(10px);
  transition: all 0.3s ease;
}

.user-xp:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(255, 107, 0, 0.2);
}

.user-xp i {
  color: var(--xp);
  font-size: 1.3rem;
}

.user-avatar {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid var(--primary);
  transition: all 0.3s ease;
}

.user-avatar:hover {
  transform: scale(1.1);
  box-shadow: 0 0 0 3px rgba(50, 205, 50, 0.3);
}

.dashboard-icon {
  color: var(--text-primary);
  font-size: 1.4rem;
  transition: all 0.3s ease;
  padding: 0.75rem;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.05);
}

.dashboard-icon:hover {
  color: var(--primary);
  background: rgba(50, 205, 50, 0.1);
  transform: translateY(-2px);
}

/* ===== SIDEBAR ===== */
.sidebar {
  background: var(--card);
  border-right: 1px solid var(--border);
  padding: 2.5rem 1.5rem;
  height: calc(100vh - 80px);
  position: sticky;
  top: 80px;
  overflow-y: auto;
  background: var(--gradient-card);
  box-shadow: 4px 0 20px rgba(0, 0, 0, 0.2);
}

.menu-titulo {
  font-size: 0.875rem;
  font-weight: 700;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-bottom: 1.5rem;
  padding-left: 1rem;
  position: relative;
}

.menu-titulo::before {
  content: '';
  position: absolute;
  left: 0;
  top: 50%;
  transform: translateY(-50%);
  width: 4px;
  height: 16px;
  background: var(--gradient-primary);
  border-radius: 2px;
}

#menu-principal,
#menu-modulos {
  list-style: none;
  margin-bottom: 2.5rem;
}

#menu-principal li,
#menu-modulos li {
  margin-bottom: 0.75rem;
  position: relative;
}

#menu-principal li a,
#menu-modulos li {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.25rem 1.5rem;
  border-radius: 16px;
  color: var(--text-secondary);
  text-decoration: none;
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  cursor: pointer;
  position: relative;
  overflow: hidden;
  border: 1px solid transparent;
}

#menu-principal li a::before,
#menu-modulos li::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(50, 205, 50, 0.1), transparent);
  transition: left 0.6s ease;
}

#menu-principal li a:hover::before,
#menu-modulos li:hover::before {
  left: 100%;
}

#menu-principal li.ativo,
#menu-modulos li.ativo {
  background: rgba(50, 205, 50, 0.15);
  color: var(--primary);
  border: 1px solid rgba(50, 205, 50, 0.3);
  box-shadow: 0 8px 25px rgba(50, 205, 50, 0.15);
  transform: translateX(8px);
}

#menu-principal li:hover,
#menu-modulos li:hover {
  background: rgba(255, 255, 255, 0.08);
  color: var(--text-primary);
  border: 1px solid var(--border-light);
  transform: translateX(4px);
}

#menu-principal li i,
#menu-modulos li i {
  width: 24px;
  text-align: center;
  font-size: 1.2rem;
  transition: transform 0.3s ease;
}

#menu-principal li:hover i,
#menu-modulos li:hover i {
  transform: scale(1.2);
}

.modulo-meta {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-left: auto;
  font-size: 0.8rem;
}

.modulo-duracao {
  color: var(--text-muted);
  font-weight: 500;
}

.status-icon {
  font-size: 1rem;
  transition: transform 0.3s ease;
}

.status-icon.fa-check-circle {
  color: var(--complete);
  filter: drop-shadow(0 0 8px rgba(0, 200, 83, 0.4));
}

/* ===== SIDEBAR FOOTER ===== */
.sidebar-footer {
  margin-top: auto;
  padding-top: 2.5rem;
  border-top: 1px solid var(--border);
}

.btn-certificado {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  padding: 1.25rem 1.5rem;
  background: rgba(0, 200, 83, 0.15);
  color: var(--complete);
  text-decoration: none;
  border-radius: 16px;
  border: 1px solid rgba(0, 200, 83, 0.3);
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  font-weight: 600;
  position: relative;
  overflow: hidden;
}

.btn-certificado::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(0, 200, 83, 0.2), transparent);
  transition: left 0.6s ease;
}

.btn-certificado:hover::before {
  left: 100%;
}

.btn-certificado:hover {
  background: rgba(0, 200, 83, 0.25);
  transform: translateY(-3px);
  box-shadow: 0 12px 30px rgba(0, 200, 83, 0.25);
}

.curso-progresso {
  text-align: center;
  padding: 1.5rem 0;
}

.progresso-texto {
  font-size: 0.9rem;
  color: var(--text-secondary);
  margin-bottom: 1rem;
  font-weight: 500;
}

.progress-bar-fundo {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  height: 8px;
  overflow: hidden;
  margin-bottom: 0.75rem;
  position: relative;
}

.progress-bar-fundo::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
  animation: shimmer 2s infinite;
}

.progress-bar-fundo.mini {
  height: 6px;
}

.progress-bar-preenchimento {
  background: var(--gradient-primary);
  height: 100%;
  border-radius: 12px;
  transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  z-index: 2;
  box-shadow: 0 0 20px rgba(50, 205, 50, 0.3);
}

.progresso-porcentagem {
  font-size: 0.9rem;
  font-weight: 700;
  color: var(--primary);
  text-shadow: 0 0 10px rgba(50, 205, 50, 0.3);
}

/* ===== CONTEÚDO PRINCIPAL ===== */
.main-content {
  padding: 2.5rem;
  background: var(--gradient-dark);
  height: calc(100vh - 80px);
  overflow-y: auto;
  position: relative;
}

.main-content::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, var(--primary), transparent);
  opacity: 0.3;
}

/* ===== DASHBOARD VIEW ===== */
.dashboard-header {
  margin-bottom: 2.5rem;
  position: relative;
}

.dashboard-header h2 {
  font-size: 2.5rem;
  font-weight: 800;
  background: linear-gradient(135deg, var(--text-primary) 0%, var(--text-secondary) 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  margin-bottom: 0.75rem;
  letter-spacing: -0.5px;
}

.curso-meta {
  display: flex;
  gap: 2.5rem;
  flex-wrap: wrap;
}

.nivel-curso,
.duracao-curso {
  color: var(--text-secondary);
  font-size: 1rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 12px;
  border: 1px solid var(--border-light);
}

/* ===== STATS GRID ===== */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 1.75rem;
  margin-bottom: 2.5rem;
}

.stat-item {
  background: var(--gradient-card);
  padding: 2rem 1.5rem;
  border-radius: 20px;
  border: 1px solid var(--border);
  text-align: center;
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  overflow: hidden;
}

.stat-item::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: var(--gradient-primary);
  transform: scaleX(0);
  transition: transform 0.4s ease;
}

.stat-item:hover::before {
  transform: scaleX(1);
}

.stat-item:hover {
  transform: translateY(-8px);
  box-shadow: var(--shadow-lg);
  border-color: rgba(50, 205, 50, 0.3);
}

.stat-item.aprovado {
  background: linear-gradient(135deg, rgba(0, 200, 83, 0.15) 0%, rgba(0, 200, 83, 0.05) 100%);
  border-color: rgba(0, 200, 83, 0.4);
}

.stat-item.aprovado::before {
  background: var(--complete);
}

.value {
  font-size: 2.5rem;
  font-weight: 800;
  color: var(--primary);
  margin-bottom: 0.75rem;
  text-shadow: 0 0 20px rgba(50, 205, 50, 0.3);
  position: relative;
  display: inline-block;
}

.stat-item.aprovado .value {
  color: var(--complete);
  text-shadow: 0 0 20px rgba(0, 200, 83, 0.3);
}

.value::after {
  content: '';
  position: absolute;
  bottom: -4px;
  left: 50%;
  transform: translateX(-50%);
  width: 30px;
  height: 3px;
  background: var(--gradient-primary);
  border-radius: 2px;
  opacity: 0.7;
}

.label {
  font-size: 0.95rem;
  color: var(--text-secondary);
  font-weight: 500;
  letter-spacing: 0.5px;
}

/* ===== DASHBOARD GRID ===== */
.dashboard-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 2.5rem;
}

.card-modulos,
.card-info {
  background: var(--gradient-card);
  border-radius: 20px;
  border: 1px solid var(--border);
  overflow: hidden;
  box-shadow: var(--shadow);
  transition: all 0.3s ease;
}

.card-modulos:hover,
.card-info:hover {
  box-shadow: var(--shadow-lg);
  transform: translateY(-2px);
}

.dashboard-card .dashboard-header {
  padding: 1.75rem 2rem;
  border-bottom: 1px solid var(--border);
  margin-bottom: 0;
  background: rgba(255, 255, 255, 0.02);
}

.dashboard-card .dashboard-header h3 {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.dashboard-card .dashboard-header h3::before {
  content: '';
  width: 4px;
  height: 24px;
  background: var(--gradient-primary);
  border-radius: 2px;
}

/* ===== PROGRESSO ===== */
.progress-container {
  padding: 2rem;
  border-bottom: 1px solid var(--border);
  background: rgba(255, 255, 255, 0.02);
}

.progress-text {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.25rem;
  font-size: 0.95rem;
}

#progresso-porcentagem {
  color: var(--primary);
  font-weight: 700;
  font-size: 1.1rem;
  text-shadow: 0 0 10px rgba(50, 205, 50, 0.3);
}

/* ===== LISTA DE MÓDULOS ===== */
.dashboard-modulos {
  list-style: none;
  padding: 2rem;
}

.dashboard-modulos li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.5rem 0;
  border-bottom: 1px solid var(--border-light);
  transition: all 0.3s ease;
  position: relative;
}

.dashboard-modulos li::before {
  content: '';
  position: absolute;
  left: -2rem;
  top: 50%;
  transform: translateY(-50%);
  width: 4px;
  height: 0;
  background: var(--gradient-primary);
  border-radius: 2px;
  transition: height 0.3s ease;
}

.dashboard-modulos li:hover::before {
  height: 60%;
}

.dashboard-modulos li:last-child {
  border-bottom: none;
}

.dashboard-modulos li.concluido {
  opacity: 0.8;
}

.dashboard-modulos li.concluido::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: linear-gradient(90deg, transparent, rgba(0, 200, 83, 0.05), transparent);
  pointer-events: none;
}

.modulo-info {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  flex: 1;
}

.modulo-info i {
  font-size: 1.5rem;
  color: var(--primary);
  width: 32px;
  text-align: center;
  transition: all 0.3s ease;
}

.dashboard-modulos li:hover .modulo-info i {
  transform: scale(1.2) rotate(5deg);
  filter: drop-shadow(0 0 8px rgba(50, 205, 50, 0.4));
}

.modulo-detalhes {
  flex: 1;
}

.modulo-nome {
  display: block;
  font-weight: 600;
  color: var(--text-primary);
  margin-bottom: 0.5rem;
  font-size: 1.1rem;
}

.modulo-meta {
  font-size: 0.85rem;
  color: var(--text-muted);
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

/* ===== BOTÕES ===== */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem 2rem;
  border: none;
  border-radius: 14px;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  font-family: inherit;
  font-size: 0.95rem;
  position: relative;
  overflow: hidden;
}

.btn::before {
  content: '';
  position: absolute;
  top: 50%;
  left: 50%;
  width: 0;
  height: 0;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 50%;
  transition: all 0.6s ease;
  transform: translate(-50%, -50%);
}

.btn:hover::before {
  width: 300px;
  height: 300px;
}

.btn-sm {
  padding: 0.75rem 1.5rem;
  font-size: 0.85rem;
}

.btn-primary {
  background: var(--gradient-primary);
  color: white;
  box-shadow: 0 8px 25px rgba(50, 205, 50, 0.3);
}

.btn-primary:hover {
  transform: translateY(-3px);
  box-shadow: 0 12px 35px rgba(50, 205, 50, 0.4);
}

.btn-success {
  background: linear-gradient(135deg, var(--complete) 0%, var(--complete-light) 100%);
  color: white;
  box-shadow: 0 8px 25px rgba(0, 200, 83, 0.3);
}

.btn-success:hover {
  background: linear-gradient(135deg, var(--complete) 0%, #00b347 100%);
  transform: translateY(-3px);
  box-shadow: 0 12px 35px rgba(0, 200, 83, 0.4);
}

.btn-carregar-modulo {
  background: rgba(50, 205, 50, 0.15);
  color: var(--primary);
  border: 1px solid rgba(50, 205, 50, 0.3);
  backdrop-filter: blur(10px);
}

.btn-carregar-modulo:hover {
  background: rgba(50, 205, 50, 0.25);
  transform: translateY(-3px);
  box-shadow: 0 8px 25px rgba(50, 205, 50, 0.2);
}

/* ===== ANIMAÇÕES ===== */
@keyframes shimmer {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}

@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes slideIn {
  from {
    transform: translateX(-100%);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

@keyframes pulse {
  0%, 100% {
    opacity: 1;
    transform: scale(1);
  }
  50% {
    opacity: 0.8;
    transform: scale(1.05);
  }
}

@keyframes float {
  0%, 100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-10px);
  }
}

.fade-in {
  animation: fadeIn 0.6s ease-out;
}

.slide-in {
  animation: slideIn 0.4s ease-out;
}

.pulse {
  animation: pulse 2s infinite;
}

.float {
  animation: float 3s ease-in-out infinite;
}

/* ===== GLASS EFFECT ===== */
.glass-card {
  background: rgba(255, 255, 255, 0.08);
  backdrop-filter: blur(20px);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 24px;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
}

/* ===== RESPONSIVIDADE ===== */
@media (max-width: 1200px) {
  .dashboard-grid {
    grid-template-columns: 1fr;
    gap: 2rem;
  }
  
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 1024px) {
  .curso-container {
    grid-template-columns: 1fr;
  }
  
  .sidebar {
    display: none;
  }
  
  .top-header {
    padding: 1rem 2rem;
  }
  
  .main-content {
    padding: 2rem;
  }
}

@media (max-width: 768px) {
  .stats-grid {
    grid-template-columns: 1fr;
    gap: 1.5rem;
  }
  
  .top-header {
    padding: 1rem;
    flex-direction: column;
    gap: 1rem;
  }
  
  .user-profile {
    width: 100%;
    justify-content: space-between;
  }
  
  .main-content {
    padding: 1.5rem;
  }
  
  .dashboard-header h2 {
    font-size: 2rem;
  }
  
  .curso-meta {
    flex-direction: column;
    gap: 0.75rem;
  }
}

@media (max-width: 480px) {
  .main-content {
    padding: 1rem;
  }
  
  .dashboard-header h2 {
    font-size: 1.75rem;
  }
  
  .value {
    font-size: 2rem;
  }
  
  .stat-item {
    padding: 1.5rem 1rem;
  }
  
  .btn {
    padding: 0.875rem 1.5rem;
    font-size: 0.9rem;
  }
}

/* ===== SCROLLBAR PERSONALIZADA ===== */
::-webkit-scrollbar {
  width: 8px;
}

::-webkit-scrollbar-track {
  background: rgba(255, 255, 255, 0.05);
  border-radius: 4px;
}

::-webkit-scrollbar-thumb {
  background: var(--gradient-primary);
  border-radius: 4px;
  transition: all 0.3s ease;
}

::-webkit-scrollbar-thumb:hover {
  background: var(--primary-light);
  box-shadow: 0 0 10px rgba(50, 205, 50, 0.5);
}

/* ===== FOCUS STATES ===== */
button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: 3px;
  border-radius: 8px;
}

/* ===== UTILITY CLASSES ===== */
.text-gradient {
  background: var(--gradient-primary);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.glow {
  filter: drop-shadow(0 0 10px currentColor);
}

.hover-lift {
  transition: transform 0.3s ease;
}

.hover-lift:hover {
  transform: translateY(-5px);
}
   
/* Adicione ao seu CSS existente */
.video-container {
    position: relative;
    width: 100%;
    height: 0;
    padding-bottom: 56.25%; /* Proporção 16:9 */
    background: #000;
    border-radius: 8px;
    overflow: hidden;
}

.video-container iframe,
.video-container video {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border: none;
}

.image-container img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
}

.quiz-container {
    border-left: 4px solid #32CD32;
}

/* Estilos responsivos */
@media (max-width: 768px) {
    .video-container {
        padding-bottom: 75%; /* Proporção mais quadrada para mobile */
    }
    
    .image-container img {
        max-height: 300px;
    }
}

/* Loading states */
.video-container video:not([src]) {
    background: linear-gradient(45deg, #374151 25%, #4B5563 25%, #4B5563 50%, #374151 50%, #374151 75%, #4B5563 75%);
    background-size: 20px 20px;
    animation: loading 1s infinite linear;
}

@keyframes loading {
    0% { background-position: 0 0; }
    100% { background-position: 20px 0; }
}




/* Estilos melhorados para vídeos */
.video-container {
    position: relative;
    width: 100%;
    height: 0;
    padding-bottom: 56.25%; /* Proporção 16:9 */
    background: #000;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
}

.video-container iframe,
.video-container video {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border: none;
    border-radius: 12px;
}

/* Loading state para vídeos */
.video-loading {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: #666;
    font-size: 1.1rem;
}

/* Responsividade para vídeos */
@media (max-width: 768px) {
    .video-container {
        border-radius: 8px;
    }
    
    .video-container iframe,
    .video-container video {
        border-radius: 8px;
    }
}

/* Controles customizados para vídeos locais */
video::-webkit-media-controls-panel {
    background: linear-gradient(transparent, rgba(0,0,0,0.7));
}

video::-webkit-media-controls-play-button,
video::-webkit-media-controls-volume-slider,
video::-webkit-media-controls-mute-button {
    filter: brightness(0) invert(1);
}

    </style>
</head>
<body class="bg-background text-white font-sans">
    <!-- Header Mobile -->
    <header class="sticky top-0 z-50 bg-card/95 backdrop-blur-lg border-b border-white/10 lg:hidden">
        <div class="flex items-center justify-between h-16 px-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">agriculture</span>
                <h1 class="text-lg font-bold">AgroDash</h1>
            </div>
            
            <div class="flex items-center gap-3">
                <!-- XP Mobile -->
                <div class="flex items-center gap-1 bg-xp/20 px-2 py-1 rounded-full border border-xp/30">
                    <span class="material-symbols-outlined text-xp text-sm">military_tech</span>
                    <span class="text-xs font-bold text-xp"><?= $pontos_xp ?></span>
                </div>
                
                <!-- Menu Mobile Toggle -->
                <button id="menu-toggle" class="p-2 rounded-lg bg-card border border-white/10">
                    <span class="material-symbols-outlined text-white">menu</span>
                </button>
            </div>
        </div>
        
        <!-- Menu Mobile -->
        <div id="mobile-menu" class="hidden flex-col absolute top-16 left-0 right-0 bg-card border-b border-white/10 z-40">
            <div class="menu-item-mobile flex items-center gap-3 p-4">
                <span class="material-symbols-outlined text-primary">person</span>
                <div>
                    <div class="font-semibold text-white"><?= htmlspecialchars($username) ?></div>
                    <div class="text-xs text-gray-400 capitalize"><?= htmlspecialchars($user_tipo) ?></div>
                </div>
            </div>
            
            <a href="dashboard.php" class="menu-item-mobile flex items-center gap-3 p-4 text-white border-t border-white/10">
                <span class="material-symbols-outlined">dashboard</span>
                <span>Voltar aos Cursos</span>
            </a>
            
            <?php if ($user_tipo === 'cia_dev' || $user_tipo === 'admin'): ?>
            <a href="/curso_agrodash/admin_dashboard.php" class="menu-item-mobile flex items-center gap-3 p-4 text-purple-400">
                <span class="material-symbols-outlined">admin_panel_settings</span>
                <span>Painel Admin</span>
            </a>
            <?php endif; ?>
        </div>
    </header>

    <!-- Header Desktop -->
    <header class="sticky top-0 z-20 bg-card/80 backdrop-blur-lg border-b border-white/10 hidden lg:block">
        <div class="max-w-screen-xl mx-auto flex items-center justify-between h-20 px-6">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-primary text-4xl">agriculture</span>
                <h1 class="text-2xl font-bold"><?= htmlspecialchars($curso_info['titulo'] ?? 'Curso AgroDash') ?></h1>
            </div>

            <div class="flex items-center gap-4">
                <!-- Display do XP do usuário -->
                <div class="flex items-center gap-2 bg-xp/20 px-4 py-2 rounded-full border border-xp/30">
                    <span class="material-symbols-outlined text-xp">military_tech</span>
                    <div class="text-right">
                        <span class="text-sm font-bold text-xp"><?= $pontos_xp ?> XP</span>
                        <div class="text-xs text-xp/80">Pontuação Total</div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">person</span>
                    <div class="text-right">
                        <span class="text-sm font-semibold text-white"><?= htmlspecialchars($username) ?></span>
                        <div class="flex items-center gap-1">
                            <span class="text-xs text-gray-300 capitalize"><?= htmlspecialchars($user_tipo) ?></span>
                            <?php if ($user_tipo !== 'operador'): ?>
                                <span class="material-symbols-outlined text-secondary text-sm">verified</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Botão do Painel Admin para cia_dev e admin -->
                <?php if ($user_tipo === 'cia_dev' || $user_tipo === 'admin'): ?>
                <a href="/curso_agrodash/admin_dashboard.php" class="bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold px-4 py-2 rounded-full transition flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">admin_panel_settings</span>
                    Painel Admin
                </a>
                <?php endif; ?>
                
                <a href="dashboard.php" class="bg-primary hover:bg-green-700 text-white text-sm font-semibold px-4 py-2 rounded-full transition flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">dashboard</span>
                    Voltar aos Cursos
                </a>
            </div>
        </div>
    </header>

    <div class="max-w-screen-xl mx-auto p-4 lg:p-8">
        <!-- Breadcrumb -->
        <div class="flex items-center gap-2 text-sm text-gray-400 mb-6">
            <a href="dashboard.php" class="hover:text-primary transition">Dashboard</a>
            <span class="material-symbols-outlined text-xs">chevron_right</span>
            <span class="text-white"><?= htmlspecialchars($curso_info['titulo'] ?? 'Curso') ?></span>
        </div>

        <!-- Header do Curso -->
        <div class="glass-card rounded-2xl p-6 mb-8">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                <div class="flex-1">
                    <h1 class="text-2xl lg:text-4xl font-bold text-white mb-2"><?= htmlspecialchars($curso_info['titulo'] ?? 'Curso') ?></h1>
                    <p class="text-gray-400 text-lg mb-4"><?= htmlspecialchars($curso_info['descricao'] ?? '') ?></p>
                    
                    <div class="flex flex-wrap gap-4 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-sm">school</span>
                            <span class="text-gray-300">Nível: <?= htmlspecialchars($curso_info['nivel'] ?? 'Iniciante') ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-sm">schedule</span>
                            <span class="text-gray-300">Duração: <?= htmlspecialchars($curso_info['duracao_estimada'] ?? '2 horas') ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-sm">menu_book</span>
                            <span class="text-gray-300"><?= $total_modulos ?> módulos</span>
                        </div>
                    </div>
                </div>
                
                <div class="flex flex-col items-center">
                    <div class="relative w-24 h-24">
                        <svg class="w-24 h-24 transform -rotate-90" viewBox="0 0 100 100">
                            <circle cx="50" cy="50" r="40" stroke="#374151" stroke-width="8" fill="none" />
                            <circle cx="50" cy="50" r="40" stroke="#32CD32" stroke-width="8" fill="none" 
                                    stroke-dasharray="251.2" 
                                    stroke-dashoffset="<?= 251.2 - (251.2 * $progresso_porcentagem / 100) ?>" 
                                    class="progress-ring" />
                        </svg>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-xl font-bold text-white"><?= number_format($progresso_porcentagem, 0) ?>%</span>
                        </div>
                    </div>
                    <span class="text-sm text-gray-400 mt-2">Progresso Geral</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <!-- Sidebar Desktop -->
            <div class="hidden lg:block lg:col-span-1">
                <div class="glass-card rounded-2xl p-6 sticky top-32">
                    <h3 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">menu_book</span>
                        Conteúdo do Curso
                    </h3>
                    
                    <div class="space-y-2 max-h-[500px] overflow-y-auto scrollbar-hide">
                        <?php foreach ($modulos_info as $id => $info): ?>
                            <button class="w-full text-left p-3 rounded-lg transition-all duration-300 flex items-center justify-between 
                                          <?= $info['concluido'] ? 'bg-complete/20 border border-complete/30' : 'bg-card hover:bg-white/5 border border-white/5' ?> 
                                          modulo-nav" 
                                    data-modulo-id="<?= $id ?>">
                                <div class="flex items-center gap-3">
                                    <span class="material-symbols-outlined text-sm <?= $info['concluido'] ? 'text-complete' : 'text-gray-400' ?>">
                                        <?= $info['concluido'] ? 'check_circle' : 'radio_button_unchecked' ?>
                                    </span>
                                    <div class="text-left">
                                        <div class="text-sm font-medium text-white"><?= htmlspecialchars($info['nome']) ?></div>
                                        <div class="text-xs text-gray-400"><?= $info['duracao'] ?></div>
                                    </div>
                                </div>
                                <span class="material-symbols-outlined text-xs text-gray-400">chevron_right</span>
                            </button>
                        <?php endforeach; ?>
                        
                        <?php if ($todos_modulos_concluidos && !$prova_final_info['aprovado']): ?>
                            <button class="w-full text-left p-3 rounded-lg transition-all duration-300 flex items-center justify-between 
                                          bg-primary/20 hover:bg-primary/30 border border-primary/30 mt-4 prova-nav">
                                <div class="flex items-center gap-3">
                                    <span class="material-symbols-outlined text-sm text-primary">quiz</span>
                                    <div class="text-left">
                                        <div class="text-sm font-medium text-white">Prova Final</div>
                                        <div class="text-xs text-gray-400">
                                            <?= $prova_final_info['tentativas'] > 0 ? "Tentativa {$prova_final_info['tentativas']}/2" : 'Disponível' ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="material-symbols-outlined text-xs text-primary">chevron_right</span>
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($prova_final_info['aprovado']): ?>
                            <a href="/curso_agrodash/certificado?curso_id=<?= $curso_id ?>" 
                               class="w-full text-left p-3 rounded-lg transition-all duration-300 flex items-center justify-between 
                                      bg-complete/20 hover:bg-complete/30 border border-complete/30 mt-4">
                                <div class="flex items-center gap-3">
                                    <span class="material-symbols-outlined text-sm text-complete">verified</span>
                                    <div class="text-left">
                                        <div class="text-sm font-medium text-white">Certificado</div>
                                        <div class="text-xs text-gray-400">Emitir certificado</div>
                                    </div>
                                </div>
                                <span class="material-symbols-outlined text-xs text-complete">download</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Conteúdo Principal -->
            <div class="lg:col-span-3">
                <!-- Dashboard View -->
                <div id="dashboard-view" class="slide active">
                    <div class="glass-card rounded-2xl p-6 mb-6">
                        <h2 class="text-2xl font-bold text-white mb-6 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">dashboard</span>
                            Visão Geral do Curso
                        </h2>
                        
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                            <div class="bg-card rounded-xl p-4 border border-white/5">
                                <div class="text-2xl font-bold text-white mb-1"><?= $modulos_concluidos ?></div>
                                <div class="text-sm text-gray-400">Módulos Concluídos</div>
                            </div>
                            <div class="bg-card rounded-xl p-4 border border-white/5">
                                <div class="text-2xl font-bold text-white mb-1"><?= $total_modulos ?></div>
                                <div class="text-sm text-gray-400">Total de Módulos</div>
                            </div>
                            <div class="bg-card rounded-xl p-4 border border-white/5">
                                <div class="text-2xl font-bold text-white mb-1"><?= $pontos_xp ?></div>
                                <div class="text-sm text-gray-400">Pontos XP</div>
                            </div>
                            <div class="bg-card rounded-xl p-4 border border-white/5 <?= $prova_final_info['aprovado'] ? 'bg-complete/20 border-complete/30' : '' ?>">
                                <div class="text-2xl font-bold text-white mb-1"><?= $prova_final_info['aprovado'] ? 'SIM' : 'NÃO' ?></div>
                                <div class="text-sm text-gray-400">Aprovação Final</div>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <h3 class="text-lg font-bold text-white">Seu Progresso</h3>
                            
                            <?php foreach ($modulos_info as $id => $info): ?>
                                <div class="bg-card rounded-xl p-4 border border-white/5 flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="w-12 h-12 rounded-lg bg-primary/20 flex items-center justify-center">
                                            <span class="material-symbols-outlined text-primary"><?= 
                                                $info['concluido'] ? 'check_circle' : 'radio_button_unchecked' 
                                            ?></span>
                                        </div>
                                        <div>
                                            <div class="font-medium text-white"><?= htmlspecialchars($info['nome']) ?></div>
                                            <div class="text-sm text-gray-400">
                                                <?php if ($info['concluido'] && $info['data_conclusao']): ?>
                                                    Concluído em <?= date('d/m/Y', strtotime($info['data_conclusao'])) ?>
                                                <?php else: ?>
                                                    <?= $info['duracao'] ?> • Não iniciado
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <button class="bg-primary hover:bg-green-700 text-white px-4 py-2 rounded-lg transition btn-carregar-modulo" 
                                            data-modulo-id="<?= $id ?>">
                                        <?= $info['concluido'] ? 'Revisar' : 'Continuar' ?>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Próximos Passos -->
                    <div class="glass-card rounded-2xl p-6">
                        <h3 class="text-xl font-bold text-white mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">flag</span>
                            Próximos Passos
                        </h3>
                        
                        <div id="proximos-passos-content">
                            <?php if ($prova_final_info['aprovado']): ?>
                                <div class="bg-complete/20 border border-complete/30 rounded-xl p-6 text-center">
                                    <span class="material-symbols-outlined text-complete text-4xl mb-4">emoji_events</span>
                                    <h4 class="text-xl font-bold text-white mb-2">Parabéns! Você está Aprovado!</h4>
                                    <p class="text-gray-300 mb-4">Você concluiu com sucesso este curso e está apto para emitir seu certificado.</p>
                                    <a href="certificado.php?curso_id=<?= $curso_id ?>" class="bg-complete hover:bg-green-600 text-white px-6 py-3 rounded-lg transition inline-flex items-center gap-2">
                                        <span class="material-symbols-outlined">verified</span>
                                        Emitir Certificado
                                    </a>
                                </div>
                            <?php elseif ($todos_modulos_concluidos): ?>
                                <div class="bg-primary/20 border border-primary/30 rounded-xl p-6 text-center">
                                    <span class="material-symbols-outlined text-primary text-4xl mb-4">quiz</span>
                                    <h4 class="text-xl font-bold text-white mb-2">Parabéns! Você pode iniciar a prova final.</h4>
                                    <p class="text-gray-300 mb-4">Complete todos os módulos e teste seus conhecimentos na prova final.</p>
                                    <button class="bg-primary hover:bg-green-700 text-white px-6 py-3 rounded-lg transition inline-flex items-center gap-2 iniciar-prova-final-dashboard">
                                        <span class="material-symbols-outlined">play_arrow</span>
                                        Iniciar Prova Final
                                    </button>
                                    <?php if ($prova_final_info['tentativas'] > 0): ?>
                                        <div class="text-sm text-gray-400 mt-3">
                                            Tentativas utilizadas: <?= $prova_final_info['tentativas'] ?>/2
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: 
                                $proximo_modulo_id = null;
                                foreach ($modulos_info as $id => $info) {
                                    if (!$info['concluido']) {
                                        $proximo_modulo_id = $id;
                                        break;
                                    }
                                }
                            ?>
                                <div class="bg-card border border-white/10 rounded-xl p-6">
                                    <div class="flex items-center gap-4 mb-4">
                                        <div class="w-12 h-12 rounded-lg bg-primary/20 flex items-center justify-center">
                                            <span class="material-symbols-outlined text-primary">forward</span>
                                        </div>
                                        <div>
                                            <h4 class="text-lg font-bold text-white">Continue sua Jornada</h4>
                                            <p class="text-gray-400">Restam <strong><?= $total_modulos - $modulos_concluidos ?> módulos</strong> para completar.</p>
                                        </div>
                                    </div>
                                    <?php if ($proximo_modulo_id): ?>
                                        <button class="bg-primary hover:bg-green-700 text-white px-6 py-3 rounded-lg transition w-full flex items-center justify-center gap-2 btn-carregar-modulo" 
                                                data-modulo-id="<?= $proximo_modulo_id ?>">
                                            <span class="material-symbols-outlined">play_arrow</span>
                                            Ir para o Próximo Módulo
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Módulo View -->
                <div id="modulo-view-container" class="slide"></div>

                <!-- Prova Final View -->
                <div id="prova-final-view" class="slide"></div>
            </div>
        </div>
    </div>

    <!-- Navegação Mobile -->
    <div class="fixed bottom-0 left-0 right-0 bg-card border-t border-white/10 p-4 lg:hidden">
        <div class="flex justify-between items-center">
            <button id="btn-prev" class="btn-nav bg-primary hover:bg-green-700 text-white p-3 rounded-full disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                <span class="material-symbols-outlined">chevron_left</span>
            </button>
            
            <div class="text-center">
                <div id="current-slide-title" class="text-sm font-medium text-white">Dashboard</div>
                <div id="slide-progress" class="text-xs text-gray-400">1/<?= $total_modulos + 1 ?></div>
            </div>
            
            <button id="btn-next" class="btn-nav bg-primary hover:bg-green-700 text-white p-3 rounded-full">
                <span class="material-symbols-outlined">chevron_right</span>
            </button>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container"></div>

    <!-- Script principal -->
    <script>
        // ===== DADOS INICIAIS DO PHP =====
        const cursoState = <?= json_encode($initial_state) ?>;
        const perguntasFinais = <?= json_encode($perguntas_final_formatadas) ?>;

        // ===== SISTEMA DE TOAST =====
        function showToast(message, type = 'info') {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icons = { 
                success: 'check_circle', 
                error: 'error', 
                info: 'info',
                warning: 'warning'
            };
            
            toast.innerHTML = `
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined">${icons[type]}</span>
                    <span>${message}</span>
                    <button class="toast-close ml-auto" onclick="this.parentElement.parentElement.remove()">
                        <span class="material-symbols-outlined text-sm">close</span>
                    </button>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            
            setTimeout(() => toast.classList.add('show'), 10);
            
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.classList.remove('show');
                    setTimeout(() => toast.remove(), 300);
                }
            }, 4000);
        }

        // ===== SISTEMA DE NAVEGAÇÃO =====
        let currentView = 'dashboard';
        let currentModuloId = null;
        let currentSlideIndex = 0;
        let totalSlides = 1;

        function navigateTo(view, moduloId = null) {
            console.log(`Navegando para: ${view}`, moduloId);
            
            // Esconder todas as views
            document.querySelectorAll('.slide').forEach(slide => {
                slide.classList.remove('active');
            });
            
            // Atualizar estado atual
            currentView = view;
            currentModuloId = moduloId;
            
            if (view === 'dashboard') {
                document.getElementById('dashboard-view').classList.add('active');
                updateSlideInfo('Dashboard', 1);
                currentSlideIndex = 0;
            } else if (view === 'modulo') {
                carregarModulo(moduloId);
                document.getElementById('modulo-view-container').classList.add('active');
                const modulo = cursoState.modulos_info[moduloId];
                currentSlideIndex = Object.keys(cursoState.modulos_info).indexOf(moduloId) + 1;
                updateSlideInfo(modulo.nome, currentSlideIndex + 1);
            } else if (view === 'prova') {
                carregarProvaFinal();
                document.getElementById('prova-final-view').classList.add('active');
                currentSlideIndex = totalSlides - 1;
                updateSlideInfo('Prova Final', totalSlides);
            }
            
            updateNavigationButtons();
        }

        function updateSlideInfo(title, slideNumber) {
            const currentSlideTitle = document.getElementById('current-slide-title');
            const slideProgress = document.getElementById('slide-progress');
            
            if (currentSlideTitle) currentSlideTitle.textContent = title;
            if (slideProgress) slideProgress.textContent = `${slideNumber}/${totalSlides}`;
        }

        function updateNavigationButtons() {
            const btnPrev = document.getElementById('btn-prev');
            const btnNext = document.getElementById('btn-next');
            
            if (btnPrev) btnPrev.disabled = currentSlideIndex === 0;
            if (btnNext) btnNext.disabled = currentSlideIndex === totalSlides - 1;
        }

// ===== SISTEMA DE MÓDULOS =====
function carregarModulo(moduloId) {
    const modulo = cursoState.modulos_info[moduloId];
    if (!modulo) {
        showToast('Módulo não encontrado', 'error');
        return;
    }
    
    const moduloViewContainer = document.getElementById('modulo-view-container');
    
    let html = `
        <div class="glass-card rounded-2xl p-6 conteudo-slide">
            <div class="flex items-center gap-4 mb-6">
                <button class="bg-primary hover:bg-green-700 text-white p-2 rounded-lg transition btn-voltar-dashboard">
                    <span class="material-symbols-outlined">arrow_back</span>
                </button>
                <div>
                    <h2 class="text-2xl font-bold text-white">${modulo.nome}</h2>
                    <p class="text-gray-400">${modulo.descricao}</p>
                </div>
            </div>
            
            <div class="space-y-6">
    `;
    
    modulo.conteudos.forEach((conteudo, index) => {
        let conteudoHTML = '';
        
        // Determinar ícone baseado no tipo
        let iconeTipo = 'description';
        switch(conteudo.tipo) {
            case 'video': iconeTipo = 'videocam'; break;
            case 'imagem': iconeTipo = 'image'; break;
            case 'quiz': iconeTipo = 'quiz'; break;
            default: iconeTipo = 'description';
        }
        
        // Renderizar conteúdo baseado no tipo
        switch (conteudo.tipo) {
            case 'texto':
                conteudoHTML = `
                    <div class="prose prose-invert max-w-none">
                        <div class="text-gray-300 leading-relaxed">${conteudo.conteudo || ''}</div>
                    </div>
                `;
                break;
                
 case 'video':
    let videoHTML = '';
    
    // Verificar se tem URL de vídeo externo
    if (conteudo.url_video) {
        let embedUrl = conteudo.url_video;
        
        // Converter URLs do YouTube para embed
        if (conteudo.url_video.includes('youtube.com') || conteudo.url_video.includes('youtu.be')) {
            const videoId = conteudo.url_video.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/);
            if (videoId) {
                embedUrl = `https://www.youtube.com/embed/${videoId[1]}?rel=0&modestbranding=1`;
            }
        }
        // Converter URLs do Vimeo para embed
        else if (conteudo.url_video.includes('vimeo.com')) {
            const videoId = conteudo.url_video.match(/(?:vimeo\.com\/|player\.vimeo\.com\/video\/)([0-9]+)/);
            if (videoId) {
                embedUrl = `https://player.vimeo.com/video/${videoId[1]}`;
            }
        }
        
        videoHTML = `
            <div class="video-container mb-4">
                <iframe 
                    src="${embedUrl}" 
                    width="100%" 
                    height="400" 
                    frameborder="0" 
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                    allowfullscreen
                    class="rounded-lg shadow-lg"
                    loading="lazy"
                ></iframe>
            </div>
        `;
    } 
    // Verificar se tem arquivo de vídeo local
    else if (conteudo.arquivo) {
        videoHTML = `
            <div class="video-container mb-4">
                <video 
                    controls 
                    width="100%" 
                    height="400"
                    class="rounded-lg shadow-lg bg-black"
                    poster="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iODAwIiBoZWlnaHQ9IjQ1MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjMTExMTExIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIyNCIgZmlsbD0iIzY2NjY2NiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPkNhcnJlZ2FuZG8gdsOtZGVvLi4uPC90ZXh0Pjwvc3ZnPg=="
                    preload="metadata"
                >
                    <source src="${conteudo.arquivo}" type="video/mp4">
                    <source src="${conteudo.arquivo}" type="video/webm">
                    <source src="${conteudo.arquivo}" type="video/ogg">
                    Seu navegador não suporta a reprodução de vídeos HTML5.
                    <a href="${conteudo.arquivo}" download>Clique aqui para baixar o vídeo</a>
                </video>
            </div>
        `;
    } else {
        videoHTML = `
            <div class="text-center py-12 bg-gray-800 rounded-lg border-2 border-dashed border-gray-600">
                <span class="material-symbols-outlined text-6xl text-gray-500 mb-4">videocam_off</span>
                <p class="text-gray-400 text-lg mb-2">Nenhum vídeo disponível</p>
                <p class="text-gray-500 text-sm">Configure um vídeo no painel administrativo</p>
            </div>
        `;
    }
    
    conteudoHTML = `
        ${videoHTML}
        ${conteudo.descricao ? `
            <div class="bg-gray-800 rounded-lg p-4 mt-4">
                <h5 class="text-white font-medium mb-2 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-sm">info</span>
                    Descrição do Vídeo
                </h5>
                <p class="text-gray-300">${conteudo.descricao}</p>
            </div>
        ` : ''}
    `;
    break;
                
            case 'imagem':
                let imagemHTML = '';
                if (conteudo.arquivo) {
                    imagemHTML = `
                        <div class="image-container mb-4 text-center">
                            <img 
                                src="${conteudo.arquivo}" 
                                alt="${conteudo.titulo}"
                                class="max-w-full h-auto rounded-lg mx-auto max-h-96 object-contain bg-gray-800 p-2"
                                loading="lazy"
                                onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgZmlsbD0iIzM3NDE1MSIvPjx0ZXh0IHg9IjEwMCIgeT0iMTAwIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTQiIGZpbGw9Ii82Yzc1N2QiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGR5PSIuM2VtIj5JbWFnZW0gbsOjbyBjYXJyZWdhZGE8L3RleHQ+PC9zdmc+'"
                            >
                            ${conteudo.descricao ? `
                                <p class="text-gray-400 text-sm mt-2 italic">${conteudo.descricao}</p>
                            ` : ''}
                        </div>
                    `;
                } else {
                    imagemHTML = `
                        <div class="text-center py-8 bg-gray-800 rounded-lg">
                            <span class="material-symbols-outlined text-4xl text-gray-500 mb-2">image_not_supported</span>
                            <p class="text-gray-400">Nenhuma imagem disponível</p>
                        </div>
                    `;
                }
                
                conteudoHTML = imagemHTML;
                break;
                
            case 'quiz':
                let quizHTML = '';
                if (conteudo.conteudo) {
                    try {
                        const quizData = JSON.parse(conteudo.conteudo);
                        if (quizData && quizData.length > 0) {
                            quizHTML = `
                                <div class="quiz-container bg-gray-800 rounded-lg p-6">
                                    <h4 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
                                        <span class="material-symbols-outlined text-primary">quiz</span>
                                        Quiz Interativo
                                    </h4>
                                    <p class="text-gray-400 mb-4">Este quiz contém ${quizData.length} pergunta(s)</p>
                                    <div class="text-center">
                                        <button class="bg-primary hover:bg-green-700 text-white px-6 py-3 rounded-lg transition iniciar-quiz" data-quiz-data='${conteudo.conteudo}'>
                                            <span class="material-symbols-outlined mr-2">play_arrow</span>
                                            Iniciar Quiz
                                        </button>
                                    </div>
                                </div>
                            `;
                        } else {
                            quizHTML = `
                                <div class="text-center py-8 bg-gray-800 rounded-lg">
                                    <span class="material-symbols-outlined text-4xl text-gray-500 mb-2">quiz</span>
                                    <p class="text-gray-400">Quiz em desenvolvimento</p>
                                </div>
                            `;
                        }
                    } catch (e) {
                        quizHTML = `
                            <div class="text-center py-8 bg-gray-800 rounded-lg">
                                <span class="material-symbols-outlined text-4xl text-gray-500 mb-2">error</span>
                                <p class="text-gray-400">Erro ao carregar quiz</p>
                            </div>
                        `;
                    }
                } else {
                    quizHTML = `
                        <div class="text-center py-8 bg-gray-800 rounded-lg">
                            <span class="material-symbols-outlined text-4xl text-gray-500 mb-2">help</span>
                            <p class="text-gray-400">Quiz interativo - Em desenvolvimento</p>
                        </div>
                    `;
                }
                
                conteudoHTML = quizHTML;
                break;
                
            default:
                conteudoHTML = `
                    <div class="text-center py-8 bg-gray-800 rounded-lg">
                        <span class="material-symbols-outlined text-4xl text-gray-500 mb-2">error</span>
                        <p class="text-gray-400">Tipo de conteúdo não suportado: ${conteudo.tipo}</p>
                    </div>
                `;
        }
        
        // Estrutura principal do conteúdo
        html += `
            <div class="bg-card rounded-xl p-6 border border-white/5">
                <h3 class="text-xl font-bold text-white mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">${iconeTipo}</span>
                    ${conteudo.titulo}
                </h3>
                ${conteudoHTML}
                
                ${index === modulo.conteudos.length - 1 ? `
                    <div class="mt-6 pt-6 border-t border-white/10 text-center">
                        ${!modulo.concluido ? `
                            <button class="bg-primary hover:bg-green-700 text-white px-6 py-3 rounded-lg transition marcar-concluido flex items-center justify-center gap-2 mx-auto" 
                                    data-modulo-id="${moduloId}">
                                <span class="material-symbols-outlined">check_circle</span>
                                Marcar como Concluído (+50 XP)
                            </button>
                            <p class="text-xs text-gray-400 mt-2">Ao concluir este módulo, você ganhará 50 pontos XP</p>
                        ` : `
                            <div class="bg-complete/20 border border-complete/30 rounded-lg p-4 max-w-md mx-auto">
                                <div class="flex items-center justify-center gap-2 text-complete mb-2">
                                    <span class="material-symbols-outlined">check_circle</span>
                                    <span class="font-medium">Módulo Concluído</span>
                                </div>
                                <p class="text-sm text-gray-400">Você completou este módulo em ${modulo.data_conclusao ? new Date(modulo.data_conclusao).toLocaleDateString('pt-BR') : 'data não disponível'}</p>
                                <p class="text-xs text-complete mt-1">+50 XP ganhos</p>
                            </div>
                        `}
                    </div>
                ` : ''}
            </div>
        `;
    });
    
    html += `</div></div>`;
    moduloViewContainer.innerHTML = html;
    
    // Adicionar eventos
    const btnVoltar = moduloViewContainer.querySelector('.btn-voltar-dashboard');
    if (btnVoltar) {
        btnVoltar.addEventListener('click', () => navigateTo('dashboard'));
    }
    
    const btnConcluir = moduloViewContainer.querySelector('.marcar-concluido');
    if (btnConcluir) {
        btnConcluir.addEventListener('click', function() {
            const moduloId = this.dataset.moduloId;
            marcarModuloConcluido(moduloId);
        });
    }
    
    // Adicionar evento para botões de quiz
    const btnIniciarQuiz = moduloViewContainer.querySelector('.iniciar-quiz');
    if (btnIniciarQuiz) {
        btnIniciarQuiz.addEventListener('click', function() {
            const quizData = JSON.parse(this.dataset.quizData);
            iniciarQuiz(quizData);
        });
    }
}

// Função auxiliar para iniciar quiz (opcional)
function iniciarQuiz(quizData) {
    showToast('Funcionalidade de quiz em desenvolvimento!', 'info');
    // Aqui você pode implementar a lógica do quiz interativo
    console.log('Iniciando quiz:', quizData);
}
async function marcarModuloConcluido(moduloId) {
    const btnConcluir = document.querySelector('.marcar-concluido');
    const originalText = btnConcluir.innerHTML;
    
    // Mostrar loading
    btnConcluir.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    btnConcluir.disabled = true;

    try {
        // Salvar no banco de dados usando o endpoint existente
        const response = await fetch('ajax/salvar_progresso.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                tipo: 'modulo',
                curso_id: cursoState.curso_id,
                id: moduloId, // Usar 'id' em vez de 'modulo_id' para compatibilidade
                acertos: 1, // Simular que acertou tudo
                total: 1    // Total de "questões" no módulo
            })
        });

        const data = await response.json();
        
        if (data.success) {
            showToast('Módulo marcado como concluído! +50 XP', 'success');
            
            // Atualizar estado local apenas se salvou no banco
            if (!cursoState.progresso_modulos.includes(moduloId)) {
                cursoState.progresso_modulos.push(moduloId);
                cursoState.modulos_info[moduloId].concluido = true;
                cursoState.modulos_info[moduloId].data_conclusao = new Date().toISOString();
                
                // Atualizar UI
                const moduloElement = document.querySelector(`.modulo-nav[data-modulo-id="${moduloId}"]`);
                if (moduloElement) {
                    moduloElement.classList.add('bg-complete/20', 'border-complete/30');
                    const icon = moduloElement.querySelector('.material-symbols-outlined');
                    if (icon) {
                        icon.textContent = 'check_circle';
                        icon.classList.add('text-complete');
                    }
                }
                
                // Atualizar progresso geral
                atualizarUIProgresso();
                
                // Atualizar estatísticas no dashboard
                atualizarEstatisticasDashboard();
            }
            
            // Voltar para o dashboard após um breve delay
            setTimeout(() => {
                navigateTo('dashboard');
            }, 1500);
        } else {
            showToast('Erro ao salvar progresso: ' + (data.message || 'Tente novamente'), 'error');
            btnConcluir.innerHTML = originalText;
            btnConcluir.disabled = false;
        }
    } catch (error) {
        console.error('Erro:', error);
        showToast('Erro de conexão. Tente novamente.', 'error');
        btnConcluir.innerHTML = originalText;
        btnConcluir.disabled = false;
    }
}
function atualizarEstatisticasDashboard() {
    const modulosConcluidos = cursoState.progresso_modulos.length;
    
    // Atualizar contador de módulos concluídos
    const modulosConcluidosElement = document.querySelector('.stats-grid .bg-card:nth-child(1) .text-2xl');
    if (modulosConcluidosElement) {
        modulosConcluidosElement.textContent = modulosConcluidos;
    }
    
    // Atualizar progresso geral
    const progressoPorcentagem = cursoState.total_modulos > 0 ? 
        (modulosConcluidos / cursoState.total_modulos) * 100 : 0;
    
    // Atualizar anel de progresso
    const progressRing = document.querySelector('.progress-ring');
    if (progressRing) {
        progressRing.style.strokeDashoffset = 251.2 - (251.2 * progressoPorcentagem / 100);
    }
    
    // Atualizar texto de progresso
    const progressText = document.querySelector('.absolute.inset-0 span');
    if (progressText) {
        progressText.textContent = Math.round(progressoPorcentagem) + '%';
    }
    
    // Verificar se todos os módulos estão concluídos para liberar prova final
    const todosConcluidos = modulosConcluidos === cursoState.total_modulos;
    if (todosConcluidos && !cursoState.prova_final_info.aprovado) {
        // Mostrar/atualizar botão da prova final
        const provaButton = document.querySelector('.prova-nav');
        if (provaButton && provaButton.style.display === 'none') {
            provaButton.style.display = 'block';
        }
        
        // Atualizar seção de próximos passos
        const proximosPassos = document.getElementById('proximos-passos-content');
        if (proximosPassos) {
            proximosPassos.innerHTML = `
                <div class="bg-primary/20 border border-primary/30 rounded-xl p-6 text-center">
                    <span class="material-symbols-outlined text-primary text-4xl mb-4">quiz</span>
                    <h4 class="text-xl font-bold text-white mb-2">Parabéns! Você pode iniciar a prova final.</h4>
                    <p class="text-gray-300 mb-4">Complete todos os módulos e teste seus conhecimentos na prova final.</p>
                    <button class="bg-primary hover:bg-green-700 text-white px-6 py-3 rounded-lg transition inline-flex items-center gap-2 iniciar-prova-final-dashboard">
                        <span class="material-symbols-outlined">play_arrow</span>
                        Iniciar Prova Final
                    </button>
                    ${cursoState.prova_final_info.tentativas > 0 ? `
                        <div class="text-sm text-gray-400 mt-3">
                            Tentativas utilizadas: ${cursoState.prova_final_info.tentativas}/2
                        </div>
                    ` : ''}
                </div>
            `;
        }
    }
}
function atualizarUIProgresso() {
    const modulosConcluidos = cursoState.progresso_modulos.length;
    const progressoPorcentagem = cursoState.total_modulos > 0 ? 
        (modulosConcluidos / cursoState.total_modulos) * 100 : 0;
    
    // Atualizar anel de progresso
    const progressRing = document.querySelector('.progress-ring');
    if (progressRing) {
        progressRing.style.strokeDashoffset = 251.2 - (251.2 * progressoPorcentagem / 100);
    }
    
    // Atualizar texto de progresso
    const progressText = document.querySelector('.absolute.inset-0 span');
    if (progressText) {
        progressText.textContent = Math.round(progressoPorcentagem) + '%';
    }
    
    // Atualizar botões na sidebar
    cursoState.progresso_modulos.forEach(moduloId => {
        const moduloElement = document.querySelector(`.modulo-nav[data-modulo-id="${moduloId}"]`);
        if (moduloElement) {
            moduloElement.classList.add('bg-complete/20', 'border-complete/30');
            const icon = moduloElement.querySelector('.material-symbols-outlined');
            if (icon) {
                icon.textContent = 'check_circle';
                icon.classList.add('text-complete');
                icon.classList.remove('text-gray-400');
            }
        }
        
        // Atualizar também no dashboard
        const dashboardItem = document.querySelector(`.btn-carregar-modulo[data-modulo-id="${moduloId}"]`);
        if (dashboardItem) {
            const card = dashboardItem.closest('.bg-card');
            if (card) {
                card.classList.add('bg-complete/20', 'border-complete/30');
                dashboardItem.textContent = 'Revisar';
            }
        }
    });
}
        // ===== SISTEMA DE PROVA FINAL =====
        function carregarProvaFinal() {
            // Verificar se todos os módulos estão concluídos
            if (!verificarModulosConcluidos()) {
                showToast('Complete todos os módulos antes de fazer a prova final.', 'error');
                navigateTo('dashboard');
                return;
            }
            
            // Verificar se já foi aprovado
            if (cursoState.prova_final_info.aprovado) {
                showToast('Você já foi aprovado na prova final!', 'info');
                navigateTo('dashboard');
                return;
            }
            
            // Verificar tentativas
            if (cursoState.prova_final_info.tentativas >= 2) {
                showToast('Você já utilizou todas as tentativas disponíveis.', 'error');
                navigateTo('dashboard');
                return;
            }
            
            renderizarProvaFinal();
        }

        function verificarModulosConcluidos() {
            return cursoState.progresso_modulos.length === cursoState.total_modulos;
        }

function renderizarProvaFinal() {
    const perguntas = perguntasFinais;
    const provaFinalView = document.getElementById('prova-final-view');
    
    if (!perguntas || perguntas.length === 0) {
        provaFinalView.innerHTML = `
            <div class="glass-card rounded-2xl p-6 text-center">
                <span class="material-symbols-outlined text-gray-500 text-6xl mb-4">error</span>
                <h3 class="text-xl font-bold text-white mb-2">Prova Final Não Disponível</h3>
                <p class="text-gray-400 mb-6">Não há questões configuradas para a prova final deste curso.</p>
                <button class="bg-primary hover:bg-green-700 text-white px-6 py-3 rounded-lg transition btn-voltar-dashboard">
                    <span class="material-symbols-outlined mr-2">arrow_back</span>
                    Voltar ao Dashboard
                </button>
            </div>
        `;
        return;
    }
    
    let html = `
        <div class="glass-card rounded-2xl p-6">
            <div class="flex items-center gap-4 mb-6">
                <button class="bg-primary hover:bg-green-700 text-white p-2 rounded-lg transition btn-voltar-dashboard">
                    <span class="material-symbols-outlined">arrow_back</span>
                </button>
                <div>
                    <h2 class="text-2xl font-bold text-white">Prova Final</h2>
                    <p class="text-gray-400">${cursoState.curso_info.titulo}</p>
                </div>
            </div>
            
            <div class="bg-primary/20 border border-primary/30 rounded-xl p-6 mb-6">
                <h3 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">info</span>
                    Instruções da Prova
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">help</span>
                        <div><strong>Total de Questões:</strong> ${perguntas.length}</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">military_tech</span>
                        <div><strong>Nota Mínima:</strong> 70%</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">schedule</span>
                        <div><strong>Tempo Estimado:</strong> 30 minutos</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">check_circle</span>
                        <div><strong>Status:</strong> Disponível</div>
                    </div>
                </div>
            </div>
            
            <div class="mb-6">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm text-gray-400">Progresso de Respostas</span>
                    <span class="text-sm font-medium text-white" id="contador-respostas">0/${perguntas.length}</span>
                </div>
                <div class="w-full bg-gray-700 rounded-full h-2">
                    <div id="progresso-respostas" class="bg-primary h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
            </div>
            
            <form id="form-prova-final" class="space-y-6">
    `;
    
    // Renderizar perguntas - USANDO A MESMA ESTRUTURA DO CÓDIGO QUE FUNCIONA
    perguntas.forEach((pergunta, index) => {
        html += `
            <div class="bg-card rounded-xl p-6 border border-white/5">
                <div class="flex items-start gap-4 mb-4">
                    <div class="bg-primary/20 text-primary font-bold rounded-full w-8 h-8 flex items-center justify-center text-sm">
                        ${index + 1}
                    </div>
                    <div class="flex-1">
                        <h4 class="text-lg font-medium text-white mb-4">${pergunta.pergunta}</h4>
                        <div class="space-y-3">
        `;
        
        pergunta.opcoes.forEach((opcao, opcaoIndex) => {
            const opcaoId = `pergunta-${index}-opcao-${opcaoIndex}`;
            html += `
                <label class="flex items-center gap-3 p-3 rounded-lg border border-white/10 hover:bg-white/5 transition cursor-pointer">
                    <input type="radio" 
                           id="${opcaoId}" 
                           name="pergunta-${index}" 
                           value="${opcaoIndex}"
                           class="hidden"
                           onchange="atualizarContadorRespostas()">
                    <span class="flex-1">
                        <span class="font-medium text-white">${String.fromCharCode(65 + opcaoIndex)}.</span>
                        <span class="text-gray-300 ml-2">${opcao}</span>
                    </span>
                    <span class="material-symbols-outlined text-gray-400 radio-icon">radio_button_unchecked</span>
                    <span class="material-symbols-outlined text-primary check-icon hidden">check_circle</span>
                </label>
            `;
        });
        
        html += `
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += `
            </form>
            
            <div class="flex gap-4 mt-8">
                <button class="bg-primary hover:bg-green-700 text-white px-6 py-3 rounded-lg transition flex-1 flex items-center justify-center gap-2" 
                        id="btn-finalizar-prova" onclick="finalizarProva()" disabled>
                    <span class="material-symbols-outlined">send</span>
                    Finalizar Prova
                </button>
            </div>
        </div>
    `;
    
    provaFinalView.innerHTML = html;
    
    // ADICIONE ESTE EVENT LISTENER SIMPLES - igual ao código que funciona
    provaFinalView.querySelectorAll('input[type="radio"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const label = this.closest('label');
            label.querySelector('.radio-icon').classList.add('hidden');
            label.querySelector('.check-icon').classList.remove('hidden');
            
            // Remover seleção de outras opções no mesmo grupo
            const groupName = this.name;
            label.parentElement.querySelectorAll(`input[name="${groupName}"]`).forEach(otherRadio => {
                if (otherRadio !== this) {
                    const otherLabel = otherRadio.closest('label');
                    otherLabel.querySelector('.radio-icon').classList.remove('hidden');
                    otherLabel.querySelector('.check-icon').classList.add('hidden');
                }
            });
        });
    });
    
    // Event listener para o botão voltar
    const btnVoltar = provaFinalView.querySelector('.btn-voltar-dashboard');
    if (btnVoltar) {
        btnVoltar.addEventListener('click', () => navigateTo('dashboard'));
    }

    
    // CORREÇÃO: Event listeners para os radio buttons
// CORREÇÃO COMPLETA: Event listeners para os radio buttons
provaFinalView.querySelectorAll('.opcao-radio').forEach(radio => {
    radio.addEventListener('change', function() {
        console.log('Opção selecionada:', this.value, this.checked);
        atualizarContadorRespostas();
        
        // Atualizar o visual da opção selecionada
        const label = this.closest('.opcao-label');
        if (label) {
            console.log('Label encontrado, atualizando ícones...');
            
            // REMOVER completamente as classes hidden e usar um approach diferente
            const radioIcon = label.querySelector('.radio-icon');
            const checkIcon = label.querySelector('.check-icon');
            
            // Esconder radio icon e mostrar check icon
            if (radioIcon) {
                radioIcon.style.display = 'none';
            }
            if (checkIcon) {
                checkIcon.style.display = 'inline-flex';
            }
            
            // Atualizar estilo visual
            label.classList.add('border-primary', 'bg-primary/10');
            label.querySelector('.opcao-letra').classList.remove('bg-gray-700', 'text-gray-300');
            label.querySelector('.opcao-letra').classList.add('bg-primary', 'text-white');
            label.querySelector('.opcao-texto').classList.remove('text-gray-300');
            label.querySelector('.opcao-texto').classList.add('text-white');
        }
        
        // Remover seleção visual de outras opções no mesmo grupo
        const groupName = this.name;
        const perguntaItem = this.closest('.pergunta-item');
        
        if (perguntaItem) {
            perguntaItem.querySelectorAll(`input[name="${groupName}"]`).forEach(otherRadio => {
                if (otherRadio !== this) {
                    const otherLabel = otherRadio.closest('.opcao-label');
                    if (otherLabel) {
                        const otherRadioIcon = otherLabel.querySelector('.radio-icon');
                        const otherCheckIcon = otherLabel.querySelector('.check-icon');
                        
                        // Mostrar radio icon e esconder check icon
                        if (otherRadioIcon) {
                            otherRadioIcon.style.display = 'inline-flex';
                        }
                        if (otherCheckIcon) {
                            otherCheckIcon.style.display = 'none';
                        }
                        
                        // Restaurar estilo visual padrão
                        otherLabel.classList.remove('border-primary', 'bg-primary/10');
                        otherLabel.querySelector('.opcao-letra').classList.remove('bg-primary', 'text-white');
                        otherLabel.querySelector('.opcao-letra').classList.add('bg-gray-700', 'text-gray-300');
                        otherLabel.querySelector('.opcao-texto').classList.remove('text-white');
                        otherLabel.querySelector('.opcao-texto').classList.add('text-gray-300');
                    }
                }
            });
        }
    });
});
    
    // CORREÇÃO: Event listener para cliques diretos nos labels (fallback)
// SOLUÇÃO ALTERNATIVA - Substitua todo o event listener por:
provaFinalView.querySelectorAll('.opcao-radio').forEach(radio => {
    radio.addEventListener('change', function() {
        console.log('Opção selecionada:', this.value);
        atualizarContadorRespostas();
        
        // Encontrar todos os elementos relevantes
        const label = this.closest('.opcao-label');
        const perguntaItem = this.closest('.pergunta-item');
        const groupName = this.name;
        
        if (label && perguntaItem) {
            // Primeiro, resetar TODAS as opções desta pergunta
            perguntaItem.querySelectorAll('.opcao-label').forEach(otherLabel => {
                otherLabel.style.borderColor = 'rgba(255, 255, 255, 0.1)';
                otherLabel.style.backgroundColor = 'transparent';
                otherLabel.querySelector('.opcao-letra').style.backgroundColor = '#374151';
                otherLabel.querySelector('.opcao-letra').style.color = '#D1D5DB';
                otherLabel.querySelector('.opcao-texto').style.color = '#D1D5DB';
                
                // Esconder check e mostrar radio para todas
                const radioIcon = otherLabel.querySelector('.radio-icon');
                const checkIcon = otherLabel.querySelector('.check-icon');
                if (radioIcon) radioIcon.style.display = 'inline-flex';
                if (checkIcon) checkIcon.style.display = 'none';
            });
            
            // Agora aplicar estilo apenas para a opção selecionada
            label.style.borderColor = '#32CD32';
            label.style.backgroundColor = 'rgba(50, 205, 50, 0.1)';
            label.querySelector('.opcao-letra').style.backgroundColor = '#32CD32';
            label.querySelector('.opcao-letra').style.color = 'white';
            label.querySelector('.opcao-texto').style.color = 'white';
            
            // Mostrar check e esconder radio
            const radioIcon = label.querySelector('.radio-icon');
            const checkIcon = label.querySelector('.check-icon');
            if (radioIcon) radioIcon.style.display = 'none';
            if (checkIcon) checkIcon.style.display = 'inline-flex';
        }
    });
});
    
    // Event listener para o botão finalizar
    const btnFinalizar = provaFinalView.querySelector('#btn-finalizar-prova');
    if (btnFinalizar) {
        btnFinalizar.addEventListener('click', finalizarProva);
    }
}

        function atualizarContadorRespostas() {
            const perguntas = perguntasFinais;
            let respondidas = 0;
            
            perguntas.forEach((pergunta, index) => {
                if (document.querySelector(`input[name="pergunta-${index}"]:checked`)) {
                    respondidas++;
                }
            });
            
            const contador = document.getElementById('contador-respostas');
            const progresso = document.getElementById('progresso-respostas');
            const btnFinalizar = document.getElementById('btn-finalizar-prova');
            
            if (contador) contador.textContent = `${respondidas}/${perguntas.length}`;
            if (progresso) progresso.style.width = `${(respondidas / perguntas.length) * 100}%`;
            if (btnFinalizar) btnFinalizar.disabled = respondidas !== perguntas.length;
        }

        async function finalizarProva() {
            const perguntas = perguntasFinais;
            let respostas = {};
            let todasRespondidas = true;

            // Coletar respostas
            perguntas.forEach((pergunta, index) => {
                const respostaSelecionada = document.querySelector(`input[name="pergunta-${index}"]:checked`);
                if (respostaSelecionada) {
                    respostas[index] = parseInt(respostaSelecionada.value);
                } else {
                    todasRespondidas = false;
                    // Destacar pergunta não respondida
                    const perguntaElement = document.getElementById(`pergunta-${index}`);
                    if (perguntaElement) {
                        perguntaElement.style.border = '2px solid #EF4444';
                        perguntaElement.style.animation = 'pulse 0.5s ease-in-out';
                    }
                }
            });

            if (!todasRespondidas) {
                showToast('Por favor, responda todas as questões antes de finalizar.', 'error');
                return;
            }

            // Calcular resultado
            let acertos = 0;
            const resultadoDetalhes = [];

            perguntas.forEach((pergunta, index) => {
                const respostaUsuario = respostas[index];
                const respostaCorreta = pergunta.resposta_correta;
                const acertou = respostaUsuario === respostaCorreta;
                
                if (acertou) acertos++;

                resultadoDetalhes.push({
                    pergunta: pergunta.pergunta,
                    respostaUsuario: pergunta.opcoes[respostaUsuario],
                    respostaCorreta: pergunta.opcoes[respostaCorreta],
                    acertou: acertou,
                    explicacao: pergunta.explicacao
                });
            });

            const percentual = (acertos / perguntas.length) * 100;
            const aprovado = percentual >= 70;

            // Mostrar loading
            const btnFinalizar = document.getElementById('btn-finalizar-prova');
            const originalText = btnFinalizar.innerHTML;
            btnFinalizar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
            btnFinalizar.disabled = true;

            try {
                // Salvar resultado
                const response = await fetch('ajax/salvar_progresso.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        tipo: 'prova',
                        curso_id: cursoState.curso_id,
                        nota: percentual,
                        acertos: acertos,
                        total: perguntas.length,
                        aprovado: aprovado,
                        tentativas: cursoState.prova_final_info.tentativas + 1
                    })
                });

                const data = await response.json();

                if (data.success) {
                    // Atualizar estado local
                    cursoState.prova_final_info.tentativas++;
                    cursoState.prova_final_info.aprovado = aprovado;
                    cursoState.prova_final_info.nota = percentual;

                    // Mostrar resultado
                    mostrarResultadoProva(percentual, acertos, perguntas.length, aprovado, resultadoDetalhes, data.codigo);
                    showToast(aprovado ? '🎉 Parabéns! Você foi aprovado!' : '📝 Prova finalizada. Tente novamente!', aprovado ? 'success' : 'warning');
                } else {
                    showToast('Erro ao salvar resultado da prova: ' + data.message, 'error');
                }
            } catch (error) {
                console.error('Erro:', error);
                showToast('Erro ao salvar resultado da prova.', 'error');
            } finally {
                // Restaurar botão
                btnFinalizar.innerHTML = originalText;
                btnFinalizar.disabled = false;
            }
        }

        function mostrarResultadoProva(percentual, acertos, total, aprovado, detalhes, codigoValidacao) {
            const provaFinalView = document.getElementById('prova-final-view');
            const aprovadoClass = aprovado ? 'aprovado' : 'reprovado';
            
            let html = `
                <div class="prova-final-container">
                    <div class="resultado-prova ${aprovadoClass}">
                        <div class="resultado-titulo">
                            ${aprovado ? '🎉 Parabéns! Você foi Aprovado!' : '📝 Resultado da Prova Final'}
                        </div>
                        <div class="resultado-nota">${percentual.toFixed(1)}%</div>
                        <div class="resultado-mensagem">
                            ${aprovado ? 
                                `Você demonstrou excelente compreensão do conteúdo com ${acertos} de ${total} questões corretas.` :
                                `Você acertou ${acertos} de ${total} questões. É necessário 70% para aprovação.`
                            }
                        </div>
                        
                        <div class="resultado-stats">
                            <div class="stat-item-resultado">
                                <span class="stat-value">${acertos}</span>
                                <span class="stat-label">Acertos</span>
                            </div>
                            <div class="stat-item-resultado">
                                <span class="stat-value">${total}</span>
                                <span class="stat-label">Total</span>
                            </div>
                            <div class="stat-item-resultado">
                                <span class="stat-value">${percentual.toFixed(1)}%</span>
                                <span class="stat-label">Taxa de Acerto</span>
                            </div>
                        </div>
            `;

            // Mostrar código de validação se aprovado
            if (aprovado && codigoValidacao) {
                html += `
                    <div class="codigo-validacao mt-4 p-4 bg-complete/20 rounded-lg border border-complete/30">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="material-symbols-outlined text-complete">certificate</span>
                            <strong class="text-white">Código de Validação:</strong>
                        </div>
                        <code class="block bg-black/50 p-3 rounded text-complete font-mono text-lg">${codigoValidacao}</code>
                        <small class="text-gray-400 text-sm">Guarde este código para validar seu certificado</small>
                    </div>
                `;
            }

            // Adicionar detalhes das respostas
            if (detalhes && detalhes.length > 0) {
                html += `
                    <div class="resultado-detalhes mt-6">
                        <h5 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined">list</span>
                            Detalhes das Respostas
                        </h5>
                        <div class="detalhes-perguntas space-y-4">
                `;
                
                detalhes.forEach((detalhe, index) => {
                    html += `
                        <div class="pergunta-revisao ${detalhe.acertou ? 'acertou bg-complete/10 border-complete/30' : 'errou bg-red-500/10 border-red-500/30'} border rounded-lg p-4">
                            <div class="pergunta-header flex items-center gap-3 mb-3">
                                <span class="pergunta-numero bg-primary/20 text-primary font-bold rounded-full w-6 h-6 flex items-center justify-center text-sm">${index + 1}</span>
                                <span class="pergunta-status ${detalhe.acertou ? 'text-complete' : 'text-red-500'} font-medium flex items-center gap-2">
                                    <span class="material-symbols-outlined text-sm">${detalhe.acertou ? 'check' : 'close'}</span>
                                    ${detalhe.acertou ? 'Acertou' : 'Errou'}
                                </span>
                            </div>
                            <div class="pergunta-texto text-white mb-3">${detalhe.pergunta}</div>
                            <div class="resposta-usuario text-gray-300 mb-2">
                                <strong>Sua resposta:</strong> ${detalhe.respostaUsuario}
                            </div>
                            ${!detalhe.acertou ? `
                                <div class="resposta-correta text-complete mb-2">
                                    <strong>Resposta correta:</strong> ${detalhe.respostaCorreta}
                                </div>
                            ` : ''}
                            ${detalhe.explicacao ? `
                                <div class="explicacao bg-black/20 p-3 rounded border border-white/10">
                                    <strong class="text-primary">Explicação:</strong> 
                                    <span class="text-gray-300">${detalhe.explicacao}</span>
                                </div>
                            ` : ''}
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            }

            // Adicionar ações
            html += `
                        <div class="acoes-resultado mt-6 flex gap-4">
                            <button class="btn btn-primary flex items-center gap-2" onclick="navigateTo('dashboard')">
                                <span class="material-symbols-outlined">dashboard</span>
                                Voltar ao Dashboard
                            </button>
                            ${aprovado ? `
                                <a href="certificado.php?curso_id=${cursoState.curso_id}&codigo=${codigoValidacao}" class="btn btn-success flex items-center gap-2">
                                    <span class="material-symbols-outlined">certificate</span>
                                    Emitir Certificado
                                </a>
                            ` : `
                                <button class="btn btn-warning flex items-center gap-2" onclick="carregarProvaFinal()">
                                    <span class="material-symbols-outlined">redo</span>
                                    Tentar Novamente
                                </button>
                            `}
                        </div>
                    </div>
                </div>
            `;

            provaFinalView.innerHTML = html;
        }

        // ===== INICIALIZAÇÃO =====
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Sistema de curso inicializado');
            
            // Configurar total de slides
            totalSlides = Object.keys(cursoState.modulos_info).length + 2;
            
            // Event Listeners globais
            document.addEventListener('click', function(e) {
                // Navegação da Sidebar - Módulos
                const navItem = e.target.closest('.modulo-nav');
                if (navItem) {
                    navigateTo('modulo', navItem.dataset.moduloId);
                    return;
                }
                
                // Navegação da Sidebar - Prova
                const provaNav = e.target.closest('.prova-nav');
                if (provaNav) {
                    navigateTo('prova');
                    return;
                }
                
                // Botões "Continuar/Revisar" Módulo no Dashboard
                const btnCarregarModulo = e.target.closest('.btn-carregar-modulo');
                if (btnCarregarModulo) {
                    navigateTo('modulo', btnCarregarModulo.dataset.moduloId);
                    return;
                }
                
                // Prova Final do Dashboard
                const btnProvaDashboard = e.target.closest('.iniciar-prova-final-dashboard');
                if (btnProvaDashboard) {
                    navigateTo('prova');
                    return;
                }
            });
            
            // Navegação Mobile
            const btnPrev = document.getElementById('btn-prev');
            const btnNext = document.getElementById('btn-next');
            
            if (btnPrev) {
                btnPrev.addEventListener('click', function() {
                    if (currentSlideIndex > 0) {
                        if (currentView === 'modulo') {
                            const moduloIds = Object.keys(cursoState.modulos_info);
                            const prevModuloId = moduloIds[currentSlideIndex - 2];
                            navigateTo('modulo', prevModuloId);
                        } else if (currentView === 'prova') {
                            const lastModuloId = Object.keys(cursoState.modulos_info).pop();
                            navigateTo('modulo', lastModuloId);
                        } else {
                            navigateTo('dashboard');
                        }
                    }
                });
            }
            
            if (btnNext) {
                btnNext.addEventListener('click', function() {
                    if (currentSlideIndex < totalSlides - 1) {
                        if (currentView === 'dashboard') {
                            const firstModuloId = Object.keys(cursoState.modulos_info)[0];
                            navigateTo('modulo', firstModuloId);
                        } else if (currentView === 'modulo') {
                            const moduloIds = Object.keys(cursoState.modulos_info);
                            if (currentSlideIndex < moduloIds.length) {
                                const nextModuloId = moduloIds[currentSlideIndex];
                                navigateTo('modulo', nextModuloId);
                            } else {
                                navigateTo('prova');
                            }
                        }
                    }
                });
            }
            
            // Menu Mobile Toggle
            const menuToggle = document.getElementById('menu-toggle');
            const mobileMenu = document.getElementById('mobile-menu');
            
            if (menuToggle && mobileMenu) {
                menuToggle.addEventListener('click', function() {
                    mobileMenu.classList.toggle('hidden');
                    mobileMenu.classList.toggle('flex');
                });
            }
            
            // Inicializar na view do dashboard
            navigateTo('dashboard');
        });

        // ===== FUNÇÃO PARA SALVAR PROGRESSO NO BANCO =====
async function salvarProgressoModulo(moduloId) {
    try {
        const response = await fetch('ajax/salvar_progresso.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                tipo: 'modulo',
                curso_id: cursoState.curso_id,
                modulo_id: moduloId,
                usuario_id: cursoState.usuario_id
            })
        });

        const data = await response.json();
        return data.success;
    } catch (error) {
        console.error('Erro ao salvar progresso:', error);
        return false;
    }
}

    </script>
</body>
</html>