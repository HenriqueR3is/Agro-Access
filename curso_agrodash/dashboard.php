<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// --- Verifica se o usuário está logado ---
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'operador';
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';

error_log("Iniciando dashboard para Usuario ID: $usuario_id, Tipo: $usuario_tipo");

// --- Buscar itens da loja ---
$itens_loja = [];
try {
    $stmt_loja = $pdo->prepare("SELECT * FROM loja_xp WHERE status = 'ativo' ORDER BY custo_xp ASC");
    $stmt_loja->execute();
    $itens_loja = $stmt_loja->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar itens da loja: " . $e->getMessage());
}

// --- Processar troca de pontos ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trocar_item'])) {
    $item_id = $_POST['item_id'];
    
    try {
        // Buscar informações do item
        $stmt_item = $pdo->prepare("SELECT * FROM loja_xp WHERE id = ? AND status = 'ativo'");
        $stmt_item->execute([$item_id]);
        $item = $stmt_item->fetch(PDO::FETCH_ASSOC);
        
        if ($item) {
            // Verificar se usuário tem XP suficiente
            if ($total_xp >= $item['custo_xp']) {
                // Registrar a troca
                $stmt_troca = $pdo->prepare("INSERT INTO trocas_xp (usuario_id, item_id, custo_xp, data_troca) VALUES (?, ?, ?, NOW())");
                $stmt_troca->execute([$usuario_id, $item_id, $item['custo_xp']]);
                
                // Atualizar estoque se aplicável
                if ($item['estoque'] > 0) {
                    $stmt_estoque = $pdo->prepare("UPDATE loja_xp SET estoque = estoque - 1 WHERE id = ?");
                    $stmt_estoque->execute([$item_id]);
                }
                
                $_SESSION['feedback_message'] = "Troca realizada com sucesso! Você adquiriu: " . $item['nome'];
                header("Location: dashboard.php");
                exit;
            } else {
                $_SESSION['error_message'] = "XP insuficiente para realizar esta troca!";
            }
        }
    } catch (PDOException $e) {
        error_log("Erro ao processar troca: " . $e->getMessage());
        $_SESSION['error_message'] = "Erro ao processar troca. Tente novamente.";
    }
}

try {
    // --- 1. Verifica se a coluna publico_alvo existe ---
    $checkColumn = $pdo->query("SHOW COLUMNS FROM cursos LIKE 'publico_alvo'");
    $hasPublicoAlvo = $checkColumn->rowCount() > 0;
    error_log("Coluna 'publico_alvo' existe: " . ($hasPublicoAlvo ? 'SIM' : 'NÃO'));

    // --- 2. Monta a consulta SQL baseada no tipo de usuário ---
    
    // Lista de status que consideramos "visíveis"
    $statusVisiveis = ['nao_iniciado', 'em_andamento', 'concluido'];

    if ($usuario_tipo !== 'operador') {
        // --- ADMIN (ou cia_dev, etc.) ---
        // Admins veem TODOS os cursos visíveis, independente do publico_alvo.
        error_log("Query para ADMIN/DEV: Buscando todos os cursos visíveis.");
        
        // CORREÇÃO AQUI: Trocamos "status = 'ativo'" pela lista de status
        $stmt = $pdo->prepare("SELECT id, titulo, descricao, imagem, status 
                                FROM cursos 
                                WHERE status IN (?, ?, ?)
                                ORDER BY id DESC");
        
        // Passamos os valores para o execute
        $stmt->execute($statusVisiveis);
        $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } else {
        // --- OPERADOR ---
        // Operador tem filtros.

        if ($hasPublicoAlvo) {
            // --- Lógica SQL (Ideal) ---
            error_log("Query para OPERADOR (via SQL): Buscando cursos filtrados.");
            
            // Esta é a sua consulta, que já está correta com o IN(...)
            $stmt = $pdo->prepare("SELECT id, titulo, descricao, imagem, status, publico_alvo 
                                    FROM cursos 
                                    WHERE (publico_alvo = 'operador' OR publico_alvo = 'todos' OR publico_alvo IS NULL OR publico_alvo = '') 
                                    AND status IN (?, ?, ?)
                                    ORDER BY id DESC");
            $stmt->execute($statusVisiveis);
            $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } else {
            // --- Lógica Fallback (IDs manuais) ---
            error_log("Query para OPERADOR (via Fallback): Buscando cursos visíveis e filtrando por array.");
            
            // CORREÇÃO AQUI: Trocamos "status = 'ativo'" pela lista de status
            $stmt = $pdo->prepare("SELECT id, titulo, descricao, imagem, status 
                                    FROM cursos 
                                    WHERE status IN (?, ?, ?) 
                                    ORDER BY id DESC");
            $stmt->execute($statusVisiveis);
            $todosCursosVisiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // DEFINA AQUI OS IDs DOS CURSOS PARA OPERADORES
            $cursosOperadorPermitidos = [1, 2, 3]; // <-- ATUALIZE ISSO!
            
            $cursos = [];
            foreach ($todosCursosVisiveis as $curso) {
                if (in_array($curso['id'], $cursosOperadorPermitidos)) {
                    $cursos[] = $curso;
                }
            }
            error_log("Total de cursos visíveis: " . count($todosCursosVisiveis) . ". Cursos permitidos para operador: " . count($cursos));
        }
    }
    
    // --- 3. Buscar total de XP do usuário ---
    $total_xp = 0;
    try {
        // Buscar todos os módulos concluídos pelo usuário
        $check_curso_column = $pdo->query("SHOW COLUMNS FROM progresso_curso LIKE 'curso_id'");
        $has_curso_column = $check_curso_column->rowCount() > 0;
        
        if ($has_curso_column) {
            $stmt_modulos = $pdo->prepare("SELECT COUNT(*) as total_modulos FROM progresso_curso 
                                          WHERE usuario_id = ? AND tipo = 'modulo'");
            $stmt_modulos->execute([$usuario_id]);
        } else {
            $stmt_modulos = $pdo->prepare("SELECT COUNT(*) as total_modulos FROM progresso_curso 
                                          WHERE usuario_id = ? AND tipo = 'modulo'");
            $stmt_modulos->execute([$usuario_id]);
        }
        
        $resultado_modulos = $stmt_modulos->fetch(PDO::FETCH_ASSOC);
        $modulos_concluidos = $resultado_modulos['total_modulos'] ?? 0;
        
        // Buscar provas aprovadas
        if ($has_curso_column) {
            $stmt_provas = $pdo->prepare("SELECT COUNT(*) as total_provas FROM progresso_curso 
                                         WHERE usuario_id = ? AND tipo = 'prova' AND aprovado = 1");
            $stmt_provas->execute([$usuario_id]);
        } else {
            $stmt_provas = $pdo->prepare("SELECT COUNT(*) as total_provas FROM progresso_curso 
                                         WHERE usuario_id = ? AND tipo = 'prova' AND aprovado = 1");
            $stmt_provas->execute([$usuario_id]);
        }
        
        $resultado_provas = $stmt_provas->fetch(PDO::FETCH_ASSOC);
        $provas_aprovadas = $resultado_provas['total_provas'] ?? 0;
        
        // Calcular XP: 100 XP por módulo + 500 XP por prova aprovada
        $total_xp = ($modulos_concluidos * 100) + ($provas_aprovadas * 500);
        
        error_log("XP calculado: $modulos_concluidos módulos * 100 + $provas_aprovadas provas * 500 = $total_xp XP");
        
    } catch (PDOException $e) {
        error_log("Erro ao calcular XP do usuário: " . $e->getMessage());
        $total_xp = 0;
    }
    
    // --- 4. Buscar conquistas do usuário ---
    $conquistas_usuario = [];
    try {
        $check_table = $pdo->query("SHOW TABLES LIKE 'conquistas_usuario'");
        if ($check_table->rowCount() > 0) {
            $check_curso_column = $pdo->query("SHOW COLUMNS FROM conquistas_usuario LIKE 'curso_id'");
            $has_curso_column_conquistas = $check_curso_column->rowCount() > 0;
            
            if ($has_curso_column_conquistas) {
                $stmt_conquistas = $pdo->prepare("SELECT conquista_id FROM conquistas_usuario WHERE usuario_id = ?");
                $stmt_conquistas->execute([$usuario_id]);
            } else {
                $stmt_conquistas = $pdo->prepare("SELECT conquista_id FROM conquistas_usuario WHERE usuario_id = ?");
                $stmt_conquistas->execute([$usuario_id]);
            }
            $conquistas_db = $stmt_conquistas->fetchAll(PDO::FETCH_ASSOC);
            $conquistas_usuario = array_column($conquistas_db, 'conquista_id');
        }
    } catch (PDOException $e) {
        error_log("Erro ao buscar conquistas do usuário: " . $e->getMessage());
    }
    
    // --- 5. Buscar estatísticas gerais do usuário ---
    $estatisticas_usuario = [
        'total_cursos' => count($cursos),
        'cursos_concluidos' => 0,
        'cursos_andamento' => 0,
        'tempo_estudo' => '0h 0min'
    ];
    
    // Calcular cursos concluídos e em andamento
    foreach ($cursos as $curso) {
        $progresso = calcularProgresso($pdo, $curso['id'], $usuario_id);
        if ($progresso >= 100) {
            $estatisticas_usuario['cursos_concluidos']++;
        } elseif ($progresso > 0) {
            $estatisticas_usuario['cursos_andamento']++;
        }
    }

} catch (PDOException $e) {
    error_log("Erro CRÍTICO ao consultar cursos: " . $e->getMessage());
    $cursos = [];
    $total_xp = 0;
    $conquistas_usuario = [];
    $estatisticas_usuario = [
        'total_cursos' => 0,
        'cursos_concluidos' => 0,
        'cursos_andamento' => 0,
        'tempo_estudo' => '0h 0min'
    ];
}

// --- Função para verificar aprovação na prova final ---
function verificarAprovacaoProva($pdo, $curso_id, $usuario_id) {
    try {
        $item_id_final = 'final-curso-' . $curso_id;
        
        // Verificar se existe a coluna curso_id
        $check_curso_column = $pdo->query("SHOW COLUMNS FROM progresso_curso LIKE 'curso_id'");
        $has_curso_column = $check_curso_column->rowCount() > 0;
        
        if ($has_curso_column) {
            $stmt = $pdo->prepare("SELECT aprovado FROM progresso_curso 
                                    WHERE usuario_id = ? AND curso_id = ? AND tipo = 'prova' AND item_id = ?");
            $stmt->execute([$usuario_id, $curso_id, $item_id_final]);
        } else {
            $stmt = $pdo->prepare("SELECT aprovado FROM progresso_curso 
                                    WHERE usuario_id = ? AND tipo = 'prova' AND item_id = ?");
            $stmt->execute([$usuario_id, $item_id_final]);
        }
        
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        return $resultado && $resultado['aprovado'] == 1;
        
    } catch (PDOException $e) {
        error_log("Erro ao verificar aprovação: " . $e->getMessage());
        return false;
    }
}

// --- Função de Progresso (Versão Corrigida) ---
function calcularProgresso($pdo, $curso_id, $usuario_id) {
    try {
        // Verifica se existe tabela de progresso
        $checkTable = $pdo->query("SHOW TABLES LIKE 'progresso_usuario'");
        if ($checkTable->rowCount() > 0) {
            // Usa a nova estrutura se existir
            $stmt = $pdo->prepare("SELECT progresso FROM progresso_usuario 
                                    WHERE usuario_id = ? AND curso_id = ?");
            $stmt->execute([$usuario_id, $curso_id]);
            $progresso = $stmt->fetchColumn();
            return $progresso ? (int)$progresso : 0;
        } else {
            // FALLBACK: Usar a mesma lógica do curso.php baseada em módulos concluídos
            error_log("Tabela 'progresso_usuario' não encontrada. Usando cálculo por módulos.");
            
            // Buscar módulos do curso
            $stmt_modulos = $pdo->prepare("SELECT id FROM modulos WHERE curso_id = ?");
            $stmt_modulos->execute([$curso_id]);
            $total_modulos = $stmt_modulos->rowCount();
            
            if ($total_modulos === 0) return 0;
            
            // Verificar coluna curso_id na tabela progresso_curso
            $check_curso_column = $pdo->query("SHOW COLUMNS FROM progresso_curso LIKE 'curso_id'");
            $has_curso_column = $check_curso_column->rowCount() > 0;
            
            // Buscar módulos concluídos
            if ($has_curso_column) {
                $stmt_progresso = $pdo->prepare("SELECT COUNT(*) FROM progresso_curso 
                                                WHERE usuario_id = ? AND curso_id = ? AND tipo = 'modulo'");
                $stmt_progresso->execute([$usuario_id, $curso_id]);
            } else {
                // Fallback: buscar todos os módulos concluídos (pode ser impreciso)
                $stmt_progresso = $pdo->prepare("SELECT COUNT(DISTINCT item_id) FROM progresso_curso 
                                                WHERE usuario_id = ? AND tipo = 'modulo'");
                $stmt_progresso->execute([$usuario_id]);
            }
            
            $modulos_concluidos = $stmt_progresso->fetchColumn();
            
            // Calcular porcentagem
            $progresso_porcentagem = $total_modulos > 0 ? ($modulos_concluidos / $total_modulos) * 100 : 0;
            
            return (int)$progresso_porcentagem;
        }
    } catch (PDOException $e) {
        error_log("Erro ao calcular progresso: " . $e->getMessage());
        return 0;
    }
}

// --- Verifica se há mensagem de feedback ---
$feedback_message = '';
if (isset($_SESSION['feedback_message'])) {
    $feedback_message = $_SESSION['feedback_message'];
    unset($_SESSION['feedback_message']);
}

$error_message = '';
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

error_log("Total de cursos a exibir no HTML: " . count($cursos));
?>
<!DOCTYPE html>
<html lang="pt-br" class="dark">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Meus Cursos - AgroDash</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">


<style>
    .scrollbar-hide {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
    .scrollbar-hide::-webkit-scrollbar {
        display: none;
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
    
    .course-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(50, 205, 50, 0.2);
        border-color: rgba(50, 205, 50, 0.3);
    }
    
    .status-badge {
        position: relative;
        overflow: hidden;
    }
    
    .status-badge::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
        transition: left 0.5s;
    }
    
    .status-badge:hover::before {
        left: 100%;
    }
    
    @media (max-width: 768px) {
        .stats-grid-mobile {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        
        .stat-card-mobile {
            padding: 1rem 0.75rem;
        }
        
        .menu-item-mobile {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .course-card {
            margin-bottom: 1rem;
        }
    }
  </style>
<script>
tailwind.config = {
    darkMode: "class",
    theme: {
        extend: {
            colors: {
                primary: { DEFAULT: "#32CD32", light: "#7CFC00" },
                secondary: "#FFD700",
                background: "#0f0f0f",
                card: "#1e1e1e",
                progress: "#2e7d32",
                complete: "#00C853",
                xp: "#FF6B00",
                loja: "#8B5CF6",
            },
            fontFamily: { display: ["Poppins", "sans-serif"] },
        },
    },
}

document.addEventListener('DOMContentLoaded', function() {
    const feedback = document.getElementById('feedback-message');
    if (feedback) {
        setTimeout(() => {
            feedback.style.transition = 'opacity 0.5s ease';
            feedback.style.opacity = '0';
            setTimeout(() => feedback.remove(), 500);
        }, 5000);
    }
    
    const error = document.getElementById('error-message');
    if (error) {
        setTimeout(() => {
            error.style.transition = 'opacity 0.5s ease';
            error.style.opacity = '0';
            setTimeout(() => error.remove(), 500);
        }, 5000);
    }
    
    // Menu mobile
    const menuToggle = document.getElementById('menu-toggle');
    const mobileMenu = document.getElementById('mobile-menu');
    
    if (menuToggle && mobileMenu) {
        menuToggle.addEventListener('click', function() {
            mobileMenu.classList.toggle('hidden');
            mobileMenu.classList.toggle('flex');
        });
    }
    
    // Navegação entre abas
    const tabButtons = document.querySelectorAll('[data-tab]');
    const tabContents = document.querySelectorAll('[data-tab-content]');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const tabName = this.getAttribute('data-tab');
            
            // Atualizar botões
            tabButtons.forEach(btn => {
                btn.classList.remove('bg-primary', 'text-white');
                btn.classList.add('bg-card', 'text-gray-400');
            });
            this.classList.remove('bg-card', 'text-gray-400');
            this.classList.add('bg-primary', 'text-white');
            
            // Atualizar conteúdos
            tabContents.forEach(content => {
                content.classList.add('hidden');
            });
            document.getElementById(tabName + '-tab').classList.remove('hidden');
        });
    });
    
    // DEBUG no console
    console.log('Cursos carregados: <?php echo count($cursos); ?>');
    console.log('Tipo de usuário: <?php echo $usuario_tipo; ?>');
    console.log('Total XP: <?php echo $total_xp; ?>');
});
</script>
<style>
    .scrollbar-hide {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
    .scrollbar-hide::-webkit-scrollbar {
        display: none;
    }
    
    @media (max-width: 768px) {
        .stats-grid-mobile {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        
        .stat-card-mobile {
            padding: 1rem 0.75rem;
        }
        
        .menu-item-mobile {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
    }
</style>
</head>
<body class="bg-background font-display text-white">

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
                <span class="text-xs font-bold text-xp"><?php echo number_format($total_xp); ?></span>
            </div>
            
            <!-- Menu Mobile Toggle -->
            <button id="menu-toggle" class="p-2 rounded-lg bg-card border border-white/10">
                <span class="material-symbols-outlined text-white">menu</span>
            </button>
        </div>
    </div>
    
    <!-- Menu Mobile -->
    <div id="mobile-menu" class="hidden flex-col absolute top-16 left-0 right-0 bg-card border-b border-white/10 z-40">
        <div class="menu-item-mobile flex items-center gap-3">
            <span class="material-symbols-outlined text-primary">person</span>
            <div>
                <div class="font-semibold text-white"><?php echo htmlspecialchars($usuario_nome); ?></div>
                <div class="text-xs text-gray-400 capitalize"><?php echo htmlspecialchars($usuario_tipo); ?></div>
            </div>
        </div>
        
        <?php if ($usuario_tipo === 'cia_dev' || $usuario_tipo === 'admin'): ?>
        <a href="/curso_agrodash/admin_dashboard.php" class="menu-item-mobile flex items-center gap-3 text-purple-400">
            <span class="material-symbols-outlined">admin_panel_settings</span>
            <span>Painel Admin</span>
        </a>
        <?php endif; ?>
        
        <a href="logout.php" class="menu-item-mobile flex items-center gap-3 text-red-400">
            <span class="material-symbols-outlined">logout</span>
            <span>Sair</span>
        </a>
    </div>
</header>

<!-- Header Desktop -->
<header class="sticky top-0 z-20 bg-card/80 backdrop-blur-lg border-b border-white/10 hidden lg:block">
  <div class="max-w-screen-xl mx-auto flex items-center justify-between h-20 px-6">
    <div class="flex items-center gap-3">
      <span class="material-symbols-outlined text-primary text-4xl">agriculture</span>
      <h1 class="text-2xl font-bold">AgroDash Cursos</h1>
    </div>

    <div class="flex items-center gap-4">
      <!-- Display do XP do usuário -->
      <div class="flex items-center gap-2 bg-xp/20 px-4 py-2 rounded-full border border-xp/30">
        <span class="material-symbols-outlined text-xp">military_tech</span>
        <div class="text-right">
          <span class="text-sm font-bold text-xp"><?php echo number_format($total_xp); ?> XP</span>
          <div class="text-xs text-xp/80">Pontuação Total</div>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <span class="material-symbols-outlined text-primary">person</span>
        <div class="text-right">
          <span class="text-sm font-semibold text-white"><?php echo htmlspecialchars($usuario_nome); ?></span>
          <div class="flex items-center gap-1">
            <span class="text-xs text-gray-300 capitalize"><?php echo htmlspecialchars($usuario_tipo); ?></span>
            <?php if ($usuario_tipo !== 'operador'): ?>
              <span class="material-symbols-outlined text-secondary text-sm">verified</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Botão do Painel Admin para cia_dev e admin -->
      <?php if ($usuario_tipo === 'cia_dev' || $usuario_tipo === 'admin'): ?>
      <a href="/curso_agrodash/admin_dashboard.php" class="bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold px-4 py-2 rounded-full transition flex items-center gap-2">
          <span class="material-symbols-outlined text-sm">admin_panel_settings</span>
          Painel Admin
      </a>
      <?php endif; ?>
      
      <a href="logout.php" class="bg-primary hover:bg-green-700 text-white text-sm font-semibold px-4 py-2 rounded-full transition flex items-center gap-2">
        <span class="material-symbols-outlined text-sm">logout</span>
        Sair
      </a>
    </div>
  </div>
</header>

<main class="max-w-screen-xl mx-auto p-4 lg:p-8">
  <?php if ($feedback_message): ?>
  <div id="feedback-message" class="bg-green-600 text-white p-4 rounded-lg mb-6 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <span class="material-symbols-outlined">check_circle</span>
      <span><?php echo htmlspecialchars($feedback_message); ?></span>
    </div>
    <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">
      <span class="material-symbols-outlined">close</span>
    </button>
  </div>
  <?php endif; ?>

  <?php if ($error_message): ?>
  <div id="error-message" class="bg-red-600 text-white p-4 rounded-lg mb-6 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <span class="material-symbols-outlined">error</span>
      <span><?php echo htmlspecialchars($error_message); ?></span>
    </div>
    <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">
      <span class="material-symbols-outlined">close</span>
    </button>
  </div>
  <?php endif; ?>


 <!-- Welcome Section -->
        <div class="glass-card rounded-2xl p-6 mb-8 animate-fade-in">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <h1 class="text-2xl lg:text-3xl font-bold text-white mb-2">Bem-vindo, <?php echo htmlspecialchars($usuario_nome); ?>! 👋</h1>
                    <p class="text-gray-400">Continue sua jornada de aprendizado e descubra novos cursos.</p>
                </div>


                </div>
            </div>
        </div>



  <!-- Navegação por Abas -->
  <div class="flex overflow-x-auto scrollbar-hide mb-6 bg-card rounded-lg p-1 border border-white/5">
    <button data-tab="cursos" class="flex-1 px-4 py-2 rounded-md transition-all bg-primary text-white font-medium whitespace-nowrap">
      <span class="material-symbols-outlined align-middle text-sm mr-2">school</span>
      Meus Cursos
    </button>
    <button data-tab="loja" class="flex-1 px-4 py-2 rounded-md transition-all bg-card text-gray-400 font-medium whitespace-nowrap">
      <span class="material-symbols-outlined align-middle text-sm mr-2">store</span>
      Loja XP
    </button>
  </div>

  <!-- Conteúdo da Aba Cursos -->
  <div id="cursos-tab" data-tab-content class="space-y-6">
    <!-- Seção de Estatísticas Rápidas -->
    <div class="stats-grid-mobile lg:grid lg:grid-cols-4 gap-4 lg:gap-6">
      <div class="stat-card-mobile bg-card rounded-xl border border-white/5">
        <div class="flex items-center gap-3">
          <div class="bg-primary/20 p-2 lg:p-3 rounded-lg">
            <span class="material-symbols-outlined text-primary text-lg lg:text-xl">school</span>
          </div>
          <div>
            <div class="text-xl lg:text-2xl font-bold text-white"><?php echo $estatisticas_usuario['total_cursos']; ?></div>
            <div class="text-xs lg:text-sm text-gray-400">Total de Cursos</div>
          </div>
        </div>
      </div>
      
      <div class="stat-card-mobile bg-card rounded-xl border border-white/5">
        <div class="flex items-center gap-3">
          <div class="bg-complete/20 p-2 lg:p-3 rounded-lg">
            <span class="material-symbols-outlined text-complete text-lg lg:text-xl">task_alt</span>
          </div>
          <div>
            <div class="text-xl lg:text-2xl font-bold text-white"><?php echo $estatisticas_usuario['cursos_concluidos']; ?></div>
            <div class="text-xs lg:text-sm text-gray-400">Concluídos</div>
          </div>
        </div>
      </div>
      
      <div class="stat-card-mobile bg-card rounded-xl border border-white/5">
        <div class="flex items-center gap-3">
          <div class="bg-primary/20 p-2 lg:p-3 rounded-lg">
            <span class="material-symbols-outlined text-primary text-lg lg:text-xl">play_circle</span>
          </div>
          <div>
            <div class="text-xl lg:text-2xl font-bold text-white"><?php echo $estatisticas_usuario['cursos_andamento']; ?></div>
            <div class="text-xs lg:text-sm text-gray-400">Em Andamento</div>
          </div>
        </div>
      </div>
      
      <div class="stat-card-mobile bg-card rounded-xl border border-white/5">
        <div class="flex items-center gap-3">
          <div class="bg-xp/20 p-2 lg:p-3 rounded-lg">
            <span class="material-symbols-outlined text-xp text-lg lg:text-xl">military_tech</span>
          </div>
          <div>
            <div class="text-xl lg:text-2xl font-bold text-white"><?php echo number_format($total_xp); ?></div>
            <div class="text-xs lg:text-sm text-gray-400">Pontos XP</div>
          </div>
        </div>
      </div>
    </div>
       
    <div class="flex items-center justify-between mb-6">
      <h2 class="text-xl lg:text-3xl font-bold text-primary flex items-center gap-2">
        <span class="material-symbols-outlined">school</span>
        Meus Cursos
      </h2>
      <div class="text-xs lg:text-sm text-gray-400 hidden lg:block">
        <?php echo count($cursos); ?> curso(s) disponível(is) - 
        <span class="capitalize text-primary"><?php echo htmlspecialchars($usuario_tipo); ?></span>
      </div>
    </div>

    <?php if (empty($cursos)): ?>
      <div class="text-center py-8 lg:py-16">
        <span class="material-symbols-outlined text-4xl lg:text-6xl text-gray-500 mb-4">book</span>
        <h3 class="text-lg lg:text-xl font-semibold text-gray-400 mb-2">Nenhum curso disponível</h3>
        <p class="text-gray-500 mb-4 text-sm lg:text-base">Não há cursos liberados para o seu perfil no momento.</p>
        <div class="text-xs text-gray-600 bg-gray-800 p-4 rounded-lg max-w-md mx-auto">
          <p><strong>DEBUG Info:</strong></p>
          <p>Usuário: <?php echo htmlspecialchars($usuario_nome); ?></p>
          <p>Tipo: <?php echo htmlspecialchars($usuario_tipo); ?></p>
          <p>ID: <?php echo htmlspecialchars($usuario_id); ?></p>
          <p>XP Total: <?php echo number_format($total_xp); ?></p>
          <p class="text-primary-light">Verifique o log de erros do PHP para mais detalhes.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6">
        <?php foreach ($cursos as $curso): 
            // Calcular progresso baseado em módulos concluídos
            $progresso = calcularProgresso($pdo, $curso['id'], $usuario_id);

            // Se o usuário foi aprovado na prova final, forçar 100%
            $aprovado_na_prova = verificarAprovacaoProva($pdo, $curso['id'], $usuario_id);
            if ($aprovado_na_prova) {
                $progresso = 100;
            }

            // Determina status e cores
            if ($progresso >= 100) {
                $status = "concluido";
                $statusTexto = "Concluído";
                $corStatus = "bg-complete";
                $iconeStatus = "task_alt";
            } elseif ($progresso > 0) {
                $status = "em_andamento";
                $statusTexto = "Em Andamento";
                $corStatus = "bg-primary";
                $iconeStatus = "play_circle";
            } else {
                $status = "nao_iniciado";
                $statusTexto = "Não Iniciado";
                $corStatus = "bg-gray-600";
                $iconeStatus = "schedule";
            }
        ?>
        <div class="flex flex-col bg-card rounded-xl shadow-lg hover:shadow-[0_0_15px_rgba(50,205,50,0.3)] transition-all duration-300 transform hover:-translate-y-1 overflow-hidden group border border-white/5">
          <div class="relative w-full aspect-video overflow-hidden">
            <div class="w-full h-full bg-center bg-no-repeat bg-cover transition-transform duration-300 group-hover:scale-105 bg-gray-800" 
                 style="background-image: url('<?php echo htmlspecialchars($curso['imagem'] ?: 'https://via.placeholder.com/400x225/1e1e1e/32CD32?text=Curso+Agro'); ?>');">
            </div>
            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent"></div>
            
            <div class="absolute top-2 left-2 lg:top-3 lg:left-3">
              <span class="text-xs font-semibold text-white py-1 px-2 lg:px-3 rounded-full <?php echo $corStatus; ?> flex items-center gap-1">
                <span class="material-symbols-outlined text-xs lg:text-sm"><?php echo $iconeStatus; ?></span>
                <span class="hidden sm:inline"><?php echo $statusTexto; ?></span>
              </span>
            </div>
            
            <!-- Badge de Aprovação se aplicável -->
            <?php if ($aprovado_na_prova): ?>
            <div class="absolute top-2 right-2 lg:top-3 lg:right-3">
              <span class="text-xs font-semibold text-white py-1 px-2 lg:px-3 rounded-full bg-complete flex items-center gap-1">
                <span class="material-symbols-outlined text-xs lg:text-sm">verified</span>
                <span class="hidden sm:inline">Aprovado</span>
              </span>
            </div>
            <?php endif; ?>
          </div>

          <div class="p-4 lg:p-6 flex flex-col flex-1 justify-between gap-3 lg:gap-4">
            <div>
              <h3 class="text-lg lg:text-xl font-bold mb-2 text-white group-hover:text-primary transition-colors line-clamp-2">
                <?php echo htmlspecialchars($curso['titulo']); ?>
              </h3>
              <p class="text-gray-400 text-xs lg:text-sm leading-relaxed line-clamp-3">
                <?php echo htmlspecialchars($curso['descricao']); ?>
              </p>
            </div>

            <div class="flex flex-col gap-2 lg:gap-3">
              <div class="flex justify-between items-center text-xs lg:text-sm">
                <span class="font-semibold text-gray-300">Progresso</span>
                <span class="font-bold text-primary"><?php echo $progresso; ?>%</span>
              </div>
              <div class="w-full bg-gray-800 rounded-full h-2 lg:h-2.5 overflow-hidden">
                <div class="bg-primary h-2 lg:h-2.5 rounded-full transition-all duration-700 ease-out" 
                     style="width: <?php echo $progresso; ?>%"></div>
              </div>
              
              <div class="flex justify-between items-center pt-1 lg:pt-2">
                <span class="text-xs text-gray-500">
                  <?php
                  if ($status === 'concluido') {
                      echo 'Curso finalizado';
                  } elseif ($status === 'em_andamento') {
                      echo 'Continue aprendendo';
                  } else {
                      echo 'Comece agora';
                  }
                  ?>
                </span>
                <a href="curso.php?id=<?php echo $curso['id']; ?>" 
                   class="flex items-center gap-1 lg:gap-2 text-white font-semibold hover:text-primary transition-colors group-hover:bg-primary/10 px-2 lg:px-3 py-1 lg:py-2 rounded-lg text-xs lg:text-sm">
                  <?php 
                  if ($status === 'concluido') {
                      echo 'Revisar';
                  } elseif ($status === 'em_andamento') {
                      echo 'Continuar';
                  } else {
                      echo 'Iniciar';
                  }
                  ?>
                  <span class="material-symbols-outlined text-base lg:text-lg transition-transform group-hover:translate-x-1">arrow_forward</span>
                </a>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Conteúdo da Aba Loja -->
  <div id="loja-tab" data-tab-content class="hidden space-y-6">
    <!-- Header da Loja -->
    <div class="bg-gradient-to-r from-loja/20 to-purple-600/20 rounded-xl p-6 border border-loja/30">
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="flex items-center gap-3">
          <div class="bg-loja/20 p-3 rounded-lg">
            <span class="material-symbols-outlined text-loja text-2xl">store</span>
          </div>
          <div>
            <h2 class="text-xl lg:text-2xl font-bold text-white">Loja de Pontos XP</h2>
            <p class="text-gray-300 text-sm lg:text-base">Troque seus pontos por recompensas exclusivas!</p>
          </div>
        </div>
        <div class="bg-xp/20 px-4 py-3 rounded-lg border border-xp/30">
          <div class="text-center">
            <div class="text-xs text-xp/80">Seu Saldo</div>
            <div class="text-2xl lg:text-3xl font-bold text-xp flex items-center justify-center gap-2">
              <span class="material-symbols-outlined">military_tech</span>
              <?php echo number_format($total_xp); ?> XP
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if (empty($itens_loja)): ?>
      <div class="text-center py-12">
        <span class="material-symbols-outlined text-6xl text-gray-500 mb-4">inventory_2</span>
        <h3 class="text-xl font-semibold text-gray-400 mb-2">Loja em Manutenção</h3>
        <p class="text-gray-500">Novos itens estarão disponíveis em breve.</p>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6">
        <?php foreach ($itens_loja as $item): 
            $pode_comprar = $total_xp >= $item['custo_xp'];
            $sem_estoque = $item['estoque'] == 0;
        ?>
        <div class="bg-card rounded-xl border border-white/5 overflow-hidden group hover:shadow-lg transition-all duration-300 <?php echo $sem_estoque ? 'opacity-60' : ''; ?>">
          <div class="relative">
            <div class="w-full h-48 bg-gray-800 bg-center bg-cover bg-no-repeat" 
                 style="background-image: url('<?php echo htmlspecialchars($item['imagem'] ?: 'https://via.placeholder.com/400x200/1e1e1e/8B5CF6?text=Item+Loja'); ?>');">
            </div>
            <div class="absolute top-3 right-3">
              <span class="bg-black/80 text-white text-xs font-bold py-1 px-2 rounded-full">
                <?php echo number_format($item['custo_xp']); ?> XP
              </span>
            </div>
            <?php if ($sem_estoque): ?>
            <div class="absolute inset-0 bg-black/60 flex items-center justify-center">
              <span class="bg-red-600 text-white font-bold py-2 px-4 rounded-lg">ESGOTADO</span>
            </div>
            <?php endif; ?>
          </div>
          
          <div class="p-4 lg:p-6">
            <h3 class="text-lg lg:text-xl font-bold text-white mb-2"><?php echo htmlspecialchars($item['nome']); ?></h3>
            <p class="text-gray-400 text-sm lg:text-base mb-4"><?php echo htmlspecialchars($item['descricao']); ?></p>
            
            <div class="flex items-center justify-between text-xs text-gray-500 mb-4">
              <span>Estoque: <?php echo $item['estoque'] > 0 ? $item['estoque'] : 'Esgotado'; ?></span>
              <span>Categoria: <?php echo htmlspecialchars($item['categoria']); ?></span>
            </div>
            
            <form method="POST" class="space-y-2">
              <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
              <button type="submit" name="trocar_item" 
                      class="w-full py-2 lg:py-3 px-4 rounded-lg font-semibold transition-all duration-300 flex items-center justify-center gap-2 text-sm lg:text-base
                             <?php echo ($pode_comprar && !$sem_estoque) 
                                 ? 'bg-loja hover:bg-purple-700 text-white cursor-pointer' 
                                 : 'bg-gray-600 text-gray-400 cursor-not-allowed'; ?>"
                      <?php echo (!$pode_comprar || $sem_estoque) ? 'disabled' : ''; ?>>
                <span class="material-symbols-outlined text-sm lg:text-base">
                  <?php echo $pode_comprar ? 'shopping_cart' : 'lock'; ?>
                </span>
                <?php if ($sem_estoque): ?>
                  ESGOTADO
                <?php elseif ($pode_comprar): ?>
                  TROCAR AGORA
                <?php else: ?>
                  XP INSUFICIENTE
                <?php endif; ?>
              </button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Informações da Loja -->
    <div class="bg-card rounded-xl p-6 border border-white/5 mt-8">
      <h3 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-loja">info</span>
        Como Funciona a Loja XP?
      </h3>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-gray-300">
        <div class="flex items-start gap-3">
          <span class="material-symbols-outlined text-primary text-sm mt-0.5">school</span>
          <div>
            <strong>Ganhe XP</strong>
            <p class="text-gray-400">Complete módulos (100 XP) e prove finais (500 XP) para acumular pontos.</p>
          </div>
        </div>
        <div class="flex items-start gap-3">
          <span class="material-symbols-outlined text-loja text-sm mt-0.5">swap_horiz</span>
          <div>
            <strong>Troque Pontos</strong>
            <p class="text-gray-400">Use seus XP para adquirir recompensas exclusivas na loja.</p>
          </div>
        </div>
        <div class="flex items-start gap-3">
          <span class="material-symbols-outlined text-xp text-sm mt-0.5">update</span>
          <div>
            <strong>Atualizações Constantes</strong>
            <p class="text-gray-400">Novos itens são adicionados regularmente. Fique de olho!</p>
          </div>
        </div>
        <div class="flex items-start gap-3">
          <span class="material-symbols-outlined text-complete text-sm mt-0.5">inventory</span>
          <div>
            <strong>Estoque Limitado</strong>
            <p class="text-gray-400">Alguns itens têm quantidade limitada. Não perca a oportunidade!</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<footer class="border-t border-white/10 mt-12 lg:mt-16">
  <div class="max-w-screen-xl mx-auto px-4 lg:px-6 py-6 lg:py-8">
    <div class="flex flex-col md:flex-row justify-between items-center">
      <div class="flex items-center gap-3 mb-4 md:mb-0">
        <span class="material-symbols-outlined text-primary text-xl lg:text-2xl">agriculture</span>
        <span class="text-base lg:text-lg font-semibold">AgroDash Cursos</span>
      </div>
      <div class="text-xs lg:text-sm text-gray-400 text-center md:text-right">
        &copy; <?php echo date('Y'); ?> AgroDash. Todos os direitos reservados.
      </div>
    </div>
  </div>
</footer>

</body>
</html>