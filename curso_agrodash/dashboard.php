<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// --- Verifica se o usuário está logado ---
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login");
    exit;
}


// Teste a conexão logo no início
try {
    $pdo->query("SELECT 1");
    error_log("Conexão com banco de dados OK");
} catch (PDOException $e) {
    error_log("ERRO DE CONEXÃO COM BANCO DE DADOS: " . $e->getMessage());
    die("Erro de conexão com o banco de dados");
}

$usuario_id = $_SESSION['usuario_id'];
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'operador';
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';

error_log("Iniciando dashboard para Usuario ID: $usuario_id, Tipo: $usuario_tipo");

// --- Função para calcular XP do usuário ---
function calcularXPUsuario($pdo, $usuario_id) {
    try {
        // Buscar módulos concluídos
        $stmt_modulos = $pdo->prepare("SELECT COUNT(DISTINCT item_id) as total FROM progresso_curso WHERE usuario_id = ? AND tipo = 'modulo'");
        $stmt_modulos->execute([$usuario_id]);
        $modulos_concluidos = $stmt_modulos->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
        // Buscar provas aprovadas
        $stmt_provas = $pdo->prepare("SELECT COUNT(DISTINCT item_id) as total FROM progresso_curso WHERE usuario_id = ? AND tipo = 'prova' AND aprovado = 1");
        $stmt_provas->execute([$usuario_id]);
        $provas_aprovadas = $stmt_provas->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
        // Calcular XP: 100 XP por módulo + 500 XP por prova aprovada
        $xp_ganho = ($modulos_concluidos * 100) + ($provas_aprovadas * 500);
        
        // Buscar XP gasto em trocas
        $stmt_gasto = $pdo->prepare("SELECT SUM(custo_xp) as total_gasto FROM trocas_xp WHERE usuario_id = ? AND status = 'concluido'");
        $stmt_gasto->execute([$usuario_id]);
        $xp_gasto = $stmt_gasto->fetch(PDO::FETCH_ASSOC)['total_gasto'] ?? 0;
        
        // XP total = XP ganho - XP gasto
        $total_xp = $xp_ganho - $xp_gasto;
        
        error_log("XP calculado para usuário $usuario_id: $modulos_concluidos módulos * 100 + $provas_aprovadas provas * 500 = $xp_ganho - $xp_gasto = $total_xp XP");
        
        return max(0, $total_xp); // Não pode ser negativo
    } catch (PDOException $e) {
        error_log("Erro ao calcular XP do usuário: " . $e->getMessage());
        return 0;
    }
}

// --- Calcular XP do usuário atual ---
$total_xp = calcularXPUsuario($pdo, $usuario_id);

// --- Buscar itens da loja ---
// --- Buscar itens da loja ---
$itens_loja = [];
$itens_esgotados = [];
try {
    // Itens ativos
    $stmt_loja = $pdo->prepare("SELECT * FROM loja_xp WHERE status = 'ativo' ORDER BY custo_xp ASC");
    $stmt_loja->execute();
    $itens_loja = $stmt_loja->fetchAll(PDO::FETCH_ASSOC);
    
    // Itens esgotados
    $stmt_esgotados = $pdo->prepare("SELECT * FROM loja_xp WHERE status = 'esgotado' ORDER BY custo_xp ASC");
    $stmt_esgotados->execute();
    $itens_esgotados = $stmt_esgotados->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar itens da loja: " . $e->getMessage());
}

// --- Buscar prêmios resgatados pelo usuário ---
// --- Buscar prêmios resgatados pelo usuário ---
$premios_resgatados = [];
$xp_gasto_total = 0;
try {
    $stmt_premios = $pdo->prepare("
        SELECT t.*, l.nome, l.descricao, l.imagem, l.categoria, t.custo_xp,
               DATE_FORMAT(t.data_troca, '%d/%m/%Y %H:%i') as data_formatada
        FROM trocas_xp t 
        JOIN loja_xp l ON t.item_id = l.id 
        WHERE t.usuario_id = ? AND t.status = 'concluido'
        ORDER BY t.data_troca DESC
    ");
    $stmt_premios->execute([$usuario_id]);
    $premios_resgatados = $stmt_premios->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular XP gasto total
    foreach ($premios_resgatados as $premio) {
        $xp_gasto_total += $premio['custo_xp'];
    }
    
    error_log("Prêmios resgatados encontrados para usuário $usuario_id: " . count($premios_resgatados));
    
} catch (PDOException $e) {
    error_log("Erro ao buscar prêmios resgatados: " . $e->getMessage());
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
                // Verificar estoque
                if ($item['estoque'] == 0) {
                    $_SESSION['error_message'] = "Este item está esgotado!";
                    header("Location: /dashboard");
                    exit;
                }
                
                // Iniciar transação
                $pdo->beginTransaction();
                
                // Registrar a troca
                $stmt_troca = $pdo->prepare("INSERT INTO trocas_xp (usuario_id, item_id, custo_xp, status, data_troca) VALUES (?, ?, ?, 'concluido', NOW())");
                $stmt_troca->execute([$usuario_id, $item_id, $item['custo_xp']]);
                
                // Atualizar estoque se aplicável (não for ilimitado)
                if ($item['estoque'] > 0) {
                    $stmt_estoque = $pdo->prepare("UPDATE loja_xp SET estoque = estoque - 1 WHERE id = ?");
                    $stmt_estoque->execute([$item_id]);
                    
                    // Verificar se esgotou
                    $novo_estoque = $item['estoque'] - 1;
                    if ($novo_estoque == 0) {
                        $stmt_status = $pdo->prepare("UPDATE loja_xp SET status = 'esgotado' WHERE id = ?");
                        $stmt_status->execute([$item_id]);
                    }
                }
                
                $pdo->commit();
                
                $_SESSION['feedback_message'] = "Troca realizada com sucesso! Você resgatou: " . $item['nome'] . ". " . number_format($item['custo_xp']) . " XP foram descontados da sua conta.";
                header("Location: /dashboard");
                exit;
            } else {
                $_SESSION['error_message'] = "XP insuficiente para realizar esta troca! Você tem " . number_format($total_xp) . " XP e precisa de " . number_format($item['custo_xp']) . " XP.";
            }
        } else {
            $_SESSION['error_message'] = "Item não encontrado ou indisponível!";
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
        
        $stmt = $pdo->prepare("SELECT id, titulo, descricao, imagem, status 
                                FROM cursos 
                                WHERE status IN (?, ?, ?)
                                ORDER BY id DESC");
        
        $stmt->execute($statusVisiveis);
        $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } else {
        // --- OPERADOR ---
        // Operador tem filtros.

        if ($hasPublicoAlvo) {
            // --- Lógica SQL (Ideal) ---
            error_log("Query para OPERADOR (via SQL): Buscando cursos filtrados.");
            
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
    $conquistas_usuario = [];
    $estatisticas_usuario = [
        'total_cursos' => 0,
        'cursos_concluidos' => 0,
        'cursos_andamento' => 0,
        'tempo_estudo' => '0h 0min'
    ];
}


// Adicione esta função para testar antes da função principal
function testarQueryRanking($pdo) {
    error_log("=== TESTANDO QUERY RANKING ===");
    
    // Teste 1: Query simples
    try {
        $query_simples = "SELECT COUNT(*) as total FROM usuarios";
        $stmt = $pdo->query($query_simples);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("Total de usuários: " . $result['total']);
    } catch (PDOException $e) {
        error_log("Erro na query simples: " . $e->getMessage());
    }
    
    // Teste 2: Query do ranking (exatamente como no teste.php)
    try {
        $query = "
            SELECT 
                u.id,
                u.nome,
                COUNT(DISTINCT CASE WHEN pc.tipo = 'modulo' THEN pc.item_id END) as modulos_concluidos,
                COUNT(DISTINCT CASE WHEN pc.tipo = 'prova' AND pc.aprovado = 1 THEN pc.item_id END) as provas_aprovadas,
                (COUNT(DISTINCT CASE WHEN pc.tipo = 'modulo' THEN pc.item_id END) * 100) +
                (COUNT(DISTINCT CASE WHEN pc.tipo = 'prova' AND pc.aprovado = 1 THEN pc.item_id END) * 500) as total_xp
            FROM usuarios u
            LEFT JOIN progresso_curso pc ON u.id = pc.usuario_id
            GROUP BY u.id, u.nome
            ORDER BY total_xp DESC
        ";
        
        $stmt = $pdo->query($query);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Query do ranking retornou: " . count($resultados) . " resultados");
        
        if (count($resultados) > 0) {
            error_log("Primeiro resultado: " . json_encode($resultados[0]));
        }
        
        return $resultados;
        
    } catch (PDOException $e) {
        error_log("Erro na query do ranking: " . $e->getMessage());
        return [];
    }
}

// --- Função SIMPLES para buscar ranking de XP ---
function buscarRankingXP($pdo) {
    try {
        // Usar a MESMA query exata do teste.php
        $query = "
            SELECT 
                u.id,
                u.nome,
                COUNT(DISTINCT CASE WHEN pc.tipo = 'modulo' THEN pc.item_id END) as modulos_concluidos,
                COUNT(DISTINCT CASE WHEN pc.tipo = 'prova' AND pc.aprovado = 1 THEN pc.item_id END) as provas_aprovadas,
                (COUNT(DISTINCT CASE WHEN pc.tipo = 'modulo' THEN pc.item_id END) * 100) +
                (COUNT(DISTINCT CASE WHEN pc.tipo = 'prova' AND pc.aprovado = 1 THEN pc.item_id END) * 500) as total_xp
            FROM usuarios u
            LEFT JOIN progresso_curso pc ON u.id = pc.usuario_id
            GROUP BY u.id, u.nome
            ORDER BY total_xp DESC
        ";
        
        $stmt = $pdo->query($query);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log simples
        error_log("Ranking XP - Usuários encontrados: " . count($resultados));
        
        return $resultados;
        
    } catch (PDOException $e) {
        error_log("Erro no ranking: " . $e->getMessage());
        return [];
    }
}

// --- Buscar ranking ---
$ranking = buscarRankingXP($pdo);
$posicao_usuario = buscarPosicaoUsuario($pdo, $usuario_id);

// DEBUG: Verificar o que está sendo retornado
error_log("DEBUG Ranking - Total de usuários: " . count($ranking));
if (!empty($ranking)) {
    error_log("DEBUG Ranking - Primeiro usuário: " . json_encode($ranking[0]));
    error_log("DEBUG Ranking - XP total do primeiro: " . $ranking[0]['total_xp']);
}
// --- Função para buscar posição do usuário no ranking ---
function buscarPosicaoUsuario($pdo, $usuario_id) {
    try {
        $ranking = buscarRankingXP($pdo);
        
        foreach ($ranking as $index => $usuario) {
            if ($usuario['id'] == $usuario_id) {
                return $index + 1;
            }
        }
        
        return 'N/A';
        
    } catch (PDOException $e) {
        error_log("Erro ao buscar posição: " . $e->getMessage());
        return 'N/A';
    }
}
// --- Buscar ranking ---
$ranking = buscarRankingXP($pdo);
$posicao_usuario = buscarPosicaoUsuario($pdo, $usuario_id);

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
error_log("Usuários no ranking: " . count($ranking));
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
    
    .ranking-table tr:nth-child(even) {
        background: rgba(255, 255, 255, 0.03);
    }
    
    .podium-1 {
        background: linear-gradient(135deg, rgba(255, 215, 0, 0.1) 0%, rgba(255, 215, 0, 0.2) 100%);
        border: 1px solid rgba(255, 215, 0, 0.3);
    }
    
    .podium-2 {
        background: linear-gradient(135deg, rgba(192, 192, 192, 0.1) 0%, rgba(192, 192, 192, 0.2) 100%);
        border: 1px solid rgba(192, 192, 192, 0.3);
    }
    
    .podium-3 {
        background: linear-gradient(135deg, rgba(205, 127, 50, 0.1) 0%, rgba(205, 127, 50, 0.2) 100%);
        border: 1px solid rgba(205, 127, 50, 0.3);
    }
    
    .user-highlight {
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0% {
            box-shadow: 0 0 0 0 rgba(50, 205, 50, 0.4);
        }
        70% {
            box-shadow: 0 0 0 10px rgba(50, 205, 50, 0);
        }
        100% {
            box-shadow: 0 0 0 0 rgba(50, 205, 50, 0);
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
                bronze: "#CD7F32",
                silver: "#C0C0C0",
                gold: "#FFD700",
                resgatado: "#10B981",
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
    console.log('Total XP: <?php echo number_format($total_xp); ?>');
    console.log('Posição no ranking: <?php echo $posicao_usuario; ?>');
    console.log('Usuários no ranking: <?php echo count($ranking); ?>');
});
</script>
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
        <a href="/adminpainel" class="menu-item-mobile flex items-center gap-3 text-purple-400">
            <span class="material-symbols-outlined">admin_panel_settings</span>
            <span>Painel Admin</span>
        </a>
        <?php endif; ?>
        
        <a href="/sair" class="menu-item-mobile flex items-center gap-3 text-red-400">
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
          <div class="text-xs text-xp/80">Saldo Disponível</div>
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
      <a href="/curso_agrodash/adminpainel" class="bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold px-4 py-2 rounded-full transition flex items-center gap-2">
          <span class="material-symbols-outlined text-sm">admin_panel_settings</span>
          Painel Admin
      </a>
      <?php endif; ?>
      
      <a href="/curso_agrodash/sair" class="bg-primary hover:bg-green-700 text-white text-sm font-semibold px-4 py-2 rounded-full transition flex items-center gap-2">
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
  <div class="glass-card rounded-2xl p-6 mb-8">
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
              <h1 class="text-2xl lg:text-3xl font-bold text-white mb-2">Bem-vindo, <?php echo htmlspecialchars($usuario_nome); ?>! 👋</h1>
              <p class="text-gray-400">Continue sua jornada de aprendizado e descubra novos cursos.</p>
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
    <button data-tab="ranking" class="flex-1 px-4 py-2 rounded-md transition-all bg-card text-gray-400 font-medium whitespace-nowrap">
      <span class="material-symbols-outlined align-middle text-sm mr-2">leaderboard</span>
      Ranking XP
    </button>
    <button data-tab="premios" class="flex-1 px-4 py-2 rounded-md transition-all bg-card text-gray-400 font-medium whitespace-nowrap">
      <span class="material-symbols-outlined align-middle text-sm mr-2">redeem</span>
      Meus Prêmios
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
                <a href="/curso_agrodash/curso?id=<?php echo $curso['id']; ?>" 
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
                    <div class="text-xs text-xp/80">Seu Saldo Disponível</div>
                    <div class="text-2xl lg:text-3xl font-bold text-xp flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined">military_tech</span>
                        <?php echo number_format($total_xp); ?> XP
                    </div>
                    <div class="text-xs text-xp/60 mt-1">(XP ganho - XP gasto)</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($itens_loja) && empty($itens_esgotados)): ?>
        <div class="text-center py-12">
            <span class="material-symbols-outlined text-6xl text-gray-500 mb-4">inventory_2</span>
            <h3 class="text-xl font-semibold text-gray-400 mb-2">Loja em Manutenção</h3>
            <p class="text-gray-500">Novos itens estarão disponíveis em breve.</p>
        </div>
    <?php else: ?>
        <!-- Seção de Itens Disponíveis -->
        <?php if (!empty($itens_loja)): ?>
        <div>
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-bold text-white flex items-center gap-2">
                    <span class="material-symbols-outlined text-green-400">check_circle</span>
                    Itens Disponíveis
                </h3>
                <span class="text-sm text-gray-400 bg-gray-800/50 px-3 py-1 rounded-full">
                    <?php echo count($itens_loja); ?> itens
                </span>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6">
                <?php foreach ($itens_loja as $item): 
                    $pode_comprar = $total_xp >= $item['custo_xp'];
                    $sem_estoque = $item['estoque'] == 0;
                    $estoque_ilimitado = $item['estoque'] == -1;
                    
                    // Determinar cor do estoque
                    $estoque_texto = '';
                    $estoque_cor = '';
                    if ($estoque_ilimitado) {
                        $estoque_texto = 'Ilimitado';
                        $estoque_cor = 'text-green-400';
                    } elseif ($sem_estoque) {
                        $estoque_texto = 'Esgotado';
                        $estoque_cor = 'text-red-400';
                    } else {
                        $estoque_texto = $item['estoque'] . ' unidades';
                        if ($item['estoque'] <= 3) {
                            $estoque_cor = 'text-orange-400';
                        } else {
                            $estoque_cor = 'text-green-400';
                        }
                    }
                ?>
                <div class="bg-card rounded-xl border border-white/5 overflow-hidden group hover:shadow-lg transition-all duration-300 <?php echo $sem_estoque ? 'opacity-80' : 'hover:-translate-y-1'; ?>">
                    <div class="relative">
                        <div class="w-full h-48 bg-gray-800 bg-center bg-cover bg-no-repeat transition-transform duration-300 group-hover:scale-105" 
                             style="background-image: url('../<?php echo htmlspecialchars($item['imagem'] ?: 'uploads/loja/default-item.jpg'); ?>');">
                        </div>
                        
                        <!-- Badge de XP -->
                        <div class="absolute top-3 right-3">
                            <span class="bg-black/90 text-white text-xs font-bold py-1.5 px-3 rounded-full border border-xp/30 shadow-lg">
                                <span class="material-symbols-outlined text-xp align-middle text-sm mr-1">military_tech</span>
                                <?php echo number_format($item['custo_xp']); ?> XP
                            </span>
                        </div>
                        
                        <!-- Badge de Categoria -->
                        <div class="absolute top-3 left-3">
                            <span class="bg-black/80 text-gray-300 text-xs font-medium py-1 px-2 rounded-full capitalize">
                                <?php echo htmlspecialchars($item['categoria']); ?>
                            </span>
                        </div>
                        
                        <!-- Overlay de Esgotado -->
                        <?php if ($sem_estoque): ?>
                        <div class="absolute inset-0 bg-black/70 flex items-center justify-center">
                            <span class="bg-red-600 text-white font-bold py-2 px-4 rounded-lg flex items-center gap-2">
                                <span class="material-symbols-outlined">block</span>
                                ESGOTADO
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="p-4 lg:p-6">
                        <h3 class="text-lg lg:text-xl font-bold text-white mb-2 group-hover:text-loja transition-colors">
                            <?php echo htmlspecialchars($item['nome']); ?>
                        </h3>
                        
                        <p class="text-gray-400 text-sm lg:text-base mb-4 line-clamp-2">
                            <?php echo htmlspecialchars($item['descricao']); ?>
                        </p>
                        
                        <!-- Informações do Item -->
                        <div class="space-y-3 mb-4">
                            <div class="flex items-center justify-between text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-gray-500 text-sm">inventory</span>
                                    <span>Estoque:</span>
                                </div>
                                <span class="font-medium <?php echo $estoque_cor; ?>"><?php echo $estoque_texto; ?></span>
                            </div>
                            
                            <?php if (!$estoque_ilimitado && !$sem_estoque): ?>
                            <div class="w-full bg-gray-800 rounded-full h-1.5">
                                <div class="bg-primary h-1.5 rounded-full" 
                                     style="width: <?php echo min(100, ($item['estoque'] / 10) * 100); ?>%">
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Botão de Resgate -->
                        <form method="POST" class="space-y-2">
                            <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                            
                            <button type="submit" name="trocar_item" 
                                    class="w-full py-3 px-4 rounded-lg font-semibold transition-all duration-300 flex items-center justify-center gap-2 text-sm lg:text-base relative overflow-hidden
                                           <?php if ($pode_comprar && !$sem_estoque): ?>
                                               bg-gradient-to-r from-loja to-purple-700 hover:from-purple-600 hover:to-purple-800 text-white cursor-pointer shadow-lg hover:shadow-xl
                                           <?php else: ?>
                                               bg-gray-800 text-gray-400 cursor-not-allowed
                                           <?php endif; ?>"
                                    <?php echo (!$pode_comprar || $sem_estoque) ? 'disabled' : ''; ?>
                                    <?php if ($pode_comprar && !$sem_estoque): ?>
                                    onmouseover="this.querySelector('.ripple').style.transform = 'scale(10)'"
                                    onmouseout="this.querySelector('.ripple').style.transform = 'scale(0)'"
                                    <?php endif; ?>>
                                
                                <?php if ($pode_comprar && !$sem_estoque): ?>
                                <span class="ripple absolute top-1/2 left-1/2 w-3 h-3 bg-white/30 rounded-full -translate-x-1/2 -translate-y-1/2 scale-0 transition-transform duration-500"></span>
                                <?php endif; ?>
                                
                                <span class="material-symbols-outlined text-sm lg:text-base relative z-10">
                                    <?php if ($sem_estoque): ?>
                                        block
                                    <?php elseif ($pode_comprar): ?>
                                        shopping_cart
                                    <?php else: ?>
                                        lock
                                    <?php endif; ?>
                                </span>
                                
                                <span class="relative z-10">
                                    <?php if ($sem_estoque): ?>
                                        ESGOTADO
                                    <?php elseif ($pode_comprar): ?>
                                        RESGATAR AGORA
                                    <?php else: ?>
                                        XP INSUFICIENTE
                                    <?php endif; ?>
                                </span>
                            </button>
                            
                            <?php if (!$pode_comprar && !$sem_estoque): ?>
                            <div class="text-center">
                                <span class="text-xs text-red-400">
                                    Faltam <?php echo number_format($item['custo_xp'] - $total_xp); ?> XP
                                </span>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Seção de Itens Esgotados -->
        <?php if (!empty($itens_esgotados)): ?>
        <div class="mt-8 pt-8 border-t border-white/10">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-bold text-white flex items-center gap-2">
                    <span class="material-symbols-outlined text-gray-400">inventory_2</span>
                    Itens Esgotados
                </h3>
                <span class="text-sm text-gray-400 bg-gray-800/50 px-3 py-1 rounded-full">
                    <?php echo count($itens_esgotados); ?> itens
                </span>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6">
                <?php foreach ($itens_esgotados as $item): ?>
                <div class="bg-card rounded-xl border border-white/5 overflow-hidden group opacity-80 hover:opacity-100 transition-opacity duration-300">
                    <div class="relative">
                        <div class="w-full h-48 bg-gray-800 bg-center bg-cover bg-no-repeat" 
                             style="background-image: url('../<?php echo htmlspecialchars($item['imagem'] ?: 'uploads/loja/default-item.jpg'); ?>'); filter: grayscale(70%) brightness(0.7);">
                        </div>
                        
                        <!-- Badge de XP (esgotado) -->
                        <div class="absolute top-3 right-3">
                            <span class="bg-black/90 text-gray-400 text-xs font-bold py-1.5 px-3 rounded-full border border-gray-700">
                                <span class="material-symbols-outlined align-middle text-sm mr-1">military_tech</span>
                                <?php echo number_format($item['custo_xp']); ?> XP
                            </span>
                        </div>
                        
                        <!-- Overlay de Esgotado -->
                        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent flex items-end justify-center pb-4">
                            <span class="bg-red-600/90 text-white font-bold py-2 px-4 rounded-lg flex items-center gap-2 text-sm">
                                <span class="material-symbols-outlined text-sm">block</span>
                                ESGOTADO
                            </span>
                        </div>
                    </div>
                    
                    <div class="p-4 lg:p-6">
                        <h3 class="text-lg lg:text-xl font-bold text-gray-400 mb-2">
                            <?php echo htmlspecialchars($item['nome']); ?>
                        </h3>
                        
                        <p class="text-gray-500 text-sm lg:text-base mb-4 line-clamp-2">
                            <?php echo htmlspecialchars($item['descricao']); ?>
                        </p>
                        
                        <!-- Informações do Item -->
                        <div class="space-y-3 mb-4">
                            <div class="flex items-center justify-between text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-gray-600 text-sm">inventory</span>
                                    <span class="text-gray-500">Estoque:</span>
                                </div>
                                <span class="font-medium text-red-400">Esgotado</span>
                            </div>
                            
                            <div class="flex items-center justify-between text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-gray-600 text-sm">category</span>
                                    <span class="text-gray-500">Categoria:</span>
                                </div>
                                <span class="text-gray-400 capitalize"><?php echo htmlspecialchars($item['categoria']); ?></span>
                            </div>
                        </div>
                        
                        <!-- Botão Desabilitado -->
                        <div class="w-full py-3 px-4 rounded-lg bg-gray-800 text-gray-500 text-center text-sm lg:text-base border border-gray-700 flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-sm">block</span>
                            INDISPONÍVEL NO MOMENTO
                        </div>
                        
                        <div class="text-center mt-3">
                            <span class="text-xs text-gray-600">
                                Este item pode retornar em breve!
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Informações da Loja -->
    <div class="bg-card rounded-xl p-6 border border-white/5 mt-8">
        <h3 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-loja">info</span>
            Como Funciona a Loja XP?
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm text-gray-300">
            <div class="flex items-start gap-3">
                <div class="bg-primary/20 p-2 rounded-lg flex-shrink-0">
                    <span class="material-symbols-outlined text-primary text-sm">school</span>
                </div>
                <div>
                    <strong class="text-white">Ganhe XP</strong>
                    <p class="text-gray-400 mt-1">Complete módulos (100 XP) e prove finais (500 XP) para acumular pontos.</p>
                </div>
            </div>
            
            <div class="flex items-start gap-3">
                <div class="bg-loja/20 p-2 rounded-lg flex-shrink-0">
                    <span class="material-symbols-outlined text-loja text-sm">swap_horiz</span>
                </div>
                <div>
                    <strong class="text-white">Troque Pontos</strong>
                    <p class="text-gray-400 mt-1">Use seus XP para adquirir recompensas exclusivas na loja.</p>
                </div>
            </div>
            
            <div class="flex items-start gap-3">
                <div class="bg-xp/20 p-2 rounded-lg flex-shrink-0">
                    <span class="material-symbols-outlined text-xp text-sm">update</span>
                </div>
                <div>
                    <strong class="text-white">Atualizações Constantes</strong>
                    <p class="text-gray-400 mt-1">Novos itens são adicionados regularmente. Fique de olho!</p>
                </div>
            </div>
            
            <div class="flex items-start gap-3">
                <div class="bg-red-600/20 p-2 rounded-lg flex-shrink-0">
                    <span class="material-symbols-outlined text-red-400 text-sm">inventory</span>
                </div>
                <div>
                    <strong class="text-white">Estoque Limitado</strong>
                    <p class="text-gray-400 mt-1">Alguns itens têm quantidade limitada. Não perca a oportunidade!</p>
                </div>
            </div>
        </div>
        
        <!-- Dicas -->
        <div class="mt-6 pt-6 border-t border-white/5">
            <h4 class="text-md font-semibold text-white mb-3 flex items-center gap-2">
                <span class="material-symbols-outlined text-yellow-400 text-sm">lightbulb</span>
                Dicas Importantes
            </h4>
            <ul class="space-y-2 text-sm text-gray-400">
                <li class="flex items-start gap-2">
                    <span class="material-symbols-outlined text-green-400 text-sm mt-0.5">check_circle</span>
                    <span>XP gasto em resgates é descontado do seu saldo total</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="material-symbols-outlined text-blue-400 text-sm mt-0.5">refresh</span>
                    <span>Para ganhar mais XP, complete cursos e aprova em provas</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="material-symbols-outlined text-orange-400 text-sm mt-0.5">warning</span>
                    <span>Itens esgotados podem retornar ao estoque futuramente</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="material-symbols-outlined text-purple-400 text-sm mt-0.5">visibility</span>
                    <span>Acompanhe seus resgates na aba "Meus Prêmios"</span>
                </li>
            </ul>
        </div>
    </div>
    
    <!-- Seu Progresso -->
    <div class="bg-gradient-to-r from-xp/20 to-orange-600/20 rounded-xl p-6 border border-xp/30">
        <h3 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-xp">track_changes</span>
            Seu Progresso na Loja
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-300">Saldo Atual</span>
                    <span class="text-xl font-bold text-xp"><?php echo number_format($total_xp); ?> XP</span>
                </div>
                <div class="w-full bg-gray-800 rounded-full h-2">
                    <div class="bg-xp h-2 rounded-full" style="width: <?php echo min(100, ($total_xp / 10000) * 100); ?>%"></div>
                </div>
                <div class="text-xs text-gray-400 mt-1">
                    <?php 
                    $itens_compraveis = array_filter($itens_loja, function($item) use ($total_xp) {
                        return $total_xp >= $item['custo_xp'] && $item['estoque'] != 0;
                    });
                    ?>
                    Você pode comprar <?php echo count($itens_compraveis); ?> de <?php echo count($itens_loja); ?> itens disponíveis
                </div>
            </div>
            
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-300">Resgates Realizados</span>
                    <span class="text-xl font-bold text-green-400"><?php echo count($premios_resgatados); ?></span>
                </div>
                <div class="text-sm text-gray-400">
                    Total gasto em resgates: <span class="font-bold text-xp"><?php echo number_format($xp_gasto_total); ?> XP</span>
                </div>
                <div class="text-xs text-gray-500 mt-1">
                    Verifique todos os seus resgates na aba "Meus Prêmios"
                </div>
            </div>
        </div>
    </div>
</div>


  <!-- Conteúdo da Aba Prêmios Resgatados -->
<div id="premios-tab" data-tab-content class="hidden space-y-6">
    <!-- Header -->
    <div class="bg-gradient-to-r from-green-600/20 to-emerald-600/20 rounded-xl p-6 border border-green-600/30">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="bg-green-600/20 p-3 rounded-lg">
                    <span class="material-symbols-outlined text-green-400 text-2xl">redeem</span>
                </div>
                <div>
                    <h2 class="text-xl lg:text-2xl font-bold text-white">Meus Prêmios Resgatados</h2>
                    <p class="text-gray-300 text-sm lg:text-base">Histórico de itens adquiridos com seus pontos XP</p>
                </div>
            </div>
            <div class="bg-xp/20 px-4 py-3 rounded-lg border border-xp/30">
                <div class="text-center">
                    <div class="text-xs text-xp/80">Total de XP Gastos</div>
                    <div class="text-2xl lg:text-3xl font-bold text-xp"><?php echo number_format($xp_gasto_total); ?> XP</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($premios_resgatados)): ?>
        <div class="text-center py-12">
            <span class="material-symbols-outlined text-6xl text-gray-500 mb-4">inventory_2</span>
            <h3 class="text-xl font-semibold text-gray-400 mb-2">Nenhum prêmio resgatado</h3>
            <p class="text-gray-500 mb-6">Você ainda não resgatou nenhum item na loja XP.</p>
            <a href="javascript:void(0);" onclick="document.querySelector('[data-tab=\"loja\"]').click();" 
               class="inline-flex items-center gap-2 bg-primary hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg transition">
                <span class="material-symbols-outlined">store</span>
                Visitar Loja
            </a>
        </div>
    <?php else: ?>
        <!-- Estatísticas -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-card rounded-xl border border-white/5 p-6">
                <div class="flex items-center gap-3">
                    <div class="bg-green-600/20 p-3 rounded-lg">
                        <span class="material-symbols-outlined text-green-400">redeem</span>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-white"><?php echo count($premios_resgatados); ?></div>
                        <div class="text-sm text-gray-400">Itens Resgatados</div>
                    </div>
                </div>
            </div>
            
            <div class="bg-card rounded-xl border border-white/5 p-6">
                <div class="flex items-center gap-3">
                    <div class="bg-xp/20 p-3 rounded-lg">
                        <span class="material-symbols-outlined text-xp">military_tech</span>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-white"><?php echo number_format($xp_gasto_total); ?> XP</div>
                        <div class="text-sm text-gray-400">Total Gastos</div>
                    </div>
                </div>
            </div>
            
            <?php 
            // Contar categorias únicas
            $categorias = array_unique(array_column($premios_resgatados, 'categoria'));
            ?>
            <div class="bg-card rounded-xl border border-white/5 p-6">
                <div class="flex items-center gap-3">
                    <div class="bg-purple-600/20 p-3 rounded-lg">
                        <span class="material-symbols-outlined text-purple-400">category</span>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-white"><?php echo count($categorias); ?></div>
                        <div class="text-sm text-gray-400">Categorias</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lista de Prêmios -->
        <div class="bg-card rounded-xl border border-white/5 overflow-hidden">
            <div class="p-6 border-b border-white/5">
                <h3 class="text-xl font-bold text-white flex items-center gap-2">
                    <span class="material-symbols-outlined text-green-400">history</span>
                    Histórico de Resgates
                </h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-black/20">
                        <tr>
                            <th class="py-3 px-4 text-left text-gray-400 font-medium text-sm">Item</th>
                            <th class="py-3 px-4 text-left text-gray-400 font-medium text-sm">Categoria</th>
                            <th class="py-3 px-4 text-left text-gray-400 font-medium text-sm">Data</th>
                            <th class="py-3 px-4 text-left text-gray-400 font-medium text-sm">XP Gastos</th>
                            <th class="py-3 px-4 text-left text-gray-400 font-medium text-sm">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($premios_resgatados as $premio): ?>
                        <tr class="border-t border-white/5 hover:bg-white/5 transition-colors">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-3">
                                    <?php if ($premio['imagem']): ?>
                                    <div class="w-10 h-10 rounded-lg bg-gray-800 bg-cover bg-center" 
                                         style="background-image: url('../<?php echo htmlspecialchars($premio['imagem']); ?>');">
                                    </div>
                                    <?php else: ?>
                                    <div class="w-10 h-10 rounded-lg bg-gray-800 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-gray-400">redeem</span>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="font-medium text-white"><?php echo htmlspecialchars($premio['nome']); ?></div>
                                        <div class="text-xs text-gray-400 truncate max-w-xs"><?php echo htmlspecialchars($premio['descricao']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="capitalize text-gray-300"><?php echo htmlspecialchars($premio['categoria']); ?></span>
                            </td>
                            <td class="py-3 px-4">
                                <div class="text-sm text-gray-400"><?php echo $premio['data_formatada']; ?></div>
                            </td>
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xp text-sm">military_tech</span>
                                    <span class="font-bold text-xp"><?php echo number_format($premio['custo_xp']); ?></span>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="bg-green-600/20 text-green-400 text-xs px-3 py-1 rounded-full">Resgatado</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Cards de Prêmios (visual alternativo) -->
        <h3 class="text-xl font-bold text-white mt-8 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-green-400">collections_bookmark</span>
            Coleção de Prêmios
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6">
            <?php foreach ($premios_resgatados as $premio): ?>
            <div class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-xl border border-white/5 p-5">
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center gap-3">
                        <?php if ($premio['imagem']): ?>
                        <div class="w-12 h-12 rounded-lg bg-gray-700 bg-cover bg-center" 
                             style="background-image: url('../<?php echo htmlspecialchars($premio['imagem']); ?>');">
                        </div>
                        <?php else: ?>
                        <div class="w-12 h-12 rounded-lg bg-gray-700 flex items-center justify-center">
                            <span class="material-symbols-outlined text-gray-400">redeem</span>
                        </div>
                        <?php endif; ?>
                        <div>
                            <h4 class="font-bold text-white"><?php echo htmlspecialchars($premio['nome']); ?></h4>
                            <span class="text-xs text-gray-400 capitalize"><?php echo htmlspecialchars($premio['categoria']); ?></span>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-400">Resgatado em</div>
                        <div class="text-sm font-semibold text-white"><?php echo explode(' ', $premio['data_formatada'])[0]; ?></div>
                    </div>
                </div>
                
                <p class="text-gray-300 text-sm mb-4 line-clamp-2"><?php echo htmlspecialchars($premio['descricao']); ?></p>
                
                <div class="flex items-center justify-between pt-4 border-t border-white/5">
                    <div class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-xp text-sm">military_tech</span>
                        <span class="font-bold text-xp"><?php echo number_format($premio['custo_xp']); ?> XP</span>
                    </div>
                    <span class="bg-green-600/20 text-green-400 text-xs px-2 py-1 rounded-full">Adquirido</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Conteúdo da Aba Ranking -->
<!-- Conteúdo da Aba Ranking -->
<div id="ranking-tab" data-tab-content class="hidden space-y-6">
    <!-- Header do Ranking -->
    <div class="bg-gradient-to-r from-secondary/20 to-xp/20 rounded-xl p-6 border border-secondary/30">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="bg-secondary/20 p-3 rounded-lg">
                    <span class="material-symbols-outlined text-secondary text-2xl">leaderboard</span>
                </div>
                <div>
                    <h2 class="text-xl lg:text-2xl font-bold text-white">Ranking de XP</h2>
                    <p class="text-gray-300 text-sm lg:text-base">Todos os usuários ordenados por pontos acumulados (XP ganho - XP gasto)</p>
                </div>
            </div>
            <div class="bg-xp/20 px-4 py-3 rounded-lg border border-xp/30">
                <div class="text-center">
                    <div class="text-xs text-xp/80">Sua Posição</div>
                    <div class="text-2xl lg:text-3xl font-bold text-xp">
                        <?php echo $posicao_usuario; ?>º
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($ranking)): ?>
        <div class="text-center py-12">
            <span class="material-symbols-outlined text-6xl text-gray-500 mb-4">leaderboard</span>
            <h3 class="text-xl font-semibold text-gray-400 mb-2">Não foi possível carregar o ranking</h3>
            <p class="text-gray-500 mb-4">O sistema está com problemas ao carregar o ranking de XP.</p>
            <div class="text-xs text-gray-600 bg-gray-800 p-4 rounded-lg max-w-md mx-auto">
                <p><strong>DEBUG Info:</strong></p>
                <p>Usuários na tabela: <?php 
                    try {
                        $count = $pdo->query("SELECT COUNT(*) as total FROM usuarios")->fetch(PDO::FETCH_ASSOC);
                        echo $count['total'] ?? 'Erro ao contar';
                    } catch (Exception $e) {
                        echo 'Erro: ' . $e->getMessage();
                    }
                ?></p>
                <p>Progresso na tabela: <?php 
                    try {
                        $count = $pdo->query("SELECT COUNT(*) as total FROM progresso_curso")->fetch(PDO::FETCH_ASSOC);
                        echo $count['total'] ?? 'Erro ao contar';
                    } catch (Exception $e) {
                        echo 'Erro: ' . $e->getMessage();
                    }
                ?></p>
                <p class="text-primary-light mt-2">Verifique o log de erros do PHP para mais detalhes.</p>
            </div>
        </div>
    <?php else: ?>
        <!-- TOP 3 PODIUM -->
        <?php 
        // Pegar apenas os 3 primeiros do ranking
        $top_3 = array_slice($ranking, 0, 3);
        ?>
        
        <?php if (count($top_3) > 0): ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <?php foreach ($top_3 as $index => $user): 
                $medal_class = '';
                $medal_icon = '';
                $medal_emoji = '';
                
                switch ($index) {
                    case 0:
                        $medal_class = 'podium-1';
                        $medal_icon = 'emoji_events';
                        $medal_emoji = '🥇';
                        $medal_color = 'text-gold';
                        break;
                    case 1:
                        $medal_class = 'podium-2';
                        $medal_icon = 'military_tech';
                        $medal_emoji = '🥈';
                        $medal_color = 'text-silver';
                        break;
                    case 2:
                        $medal_class = 'podium-3';
                        $medal_icon = 'workspace_premium';
                        $medal_emoji = '🥉';
                        $medal_color = 'text-bronze';
                        break;
                }
            ?>
            <div class="bg-card rounded-xl border border-white/5 p-6 relative overflow-hidden <?php echo $medal_class; ?>">
                <div class="absolute top-4 right-4 text-3xl"><?php echo $medal_emoji; ?></div>
                <div class="flex flex-col items-center text-center">
                    <div class="mb-4">
                        <div class="text-4xl lg:text-6xl font-bold text-white mb-2"><?php echo $index + 1; ?>º</div>
                        <div class="text-sm text-gray-400">Posição</div>
                    </div>
                    
                    <div class="w-16 h-16 lg:w-20 lg:h-20 rounded-full bg-gradient-to-r from-gray-800 to-gray-900 flex items-center justify-center mb-4 border-4 border-white/10 <?php echo $user['id'] == $usuario_id ? 'user-highlight border-primary/50' : ''; ?>">
                        <span class="material-symbols-outlined text-2xl lg:text-3xl text-white">person</span>
                    </div>
                    
                    <h3 class="text-lg font-bold text-white mb-1 truncate w-full px-2"><?php echo htmlspecialchars($user['nome']); ?></h3>
                    <div class="text-sm text-gray-400 capitalize mb-2"><?php echo htmlspecialchars($user['usuario_tipo'] ?? 'operador'); ?></div>
                    
                    <?php if ($user['id'] == $usuario_id): ?>
                        <div class="mb-2">
                            <span class="bg-primary/20 text-primary text-xs px-3 py-1 rounded-full">Você</span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="bg-xp/20 px-4 py-2 rounded-lg border border-xp/30 mt-2">
                        <div class="text-xl lg:text-2xl font-bold text-xp"><?php echo number_format($user['total_xp']); ?> XP</div>
                        <div class="text-xs text-xp/80">Pontuação Total</div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mt-4 w-full">
                        <div class="text-center">
                            <div class="text-lg font-bold text-primary"><?php echo $user['modulos_concluidos'] ?? 0; ?></div>
                            <div class="text-xs text-gray-400">Módulos</div>
                        </div>
                        <div class="text-center">
                            <div class="text-lg font-bold text-complete"><?php echo $user['provas_aprovadas'] ?? 0; ?></div>
                            <div class="text-xs text-gray-400">Provas</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Ranking Completo -->
        <div class="bg-card rounded-xl border border-white/5 overflow-hidden">
            <div class="p-4 lg:p-6 border-b border-white/5">
                <h3 class="text-lg lg:text-xl font-bold text-white flex items-center gap-2">
                    <span class="material-symbols-outlined text-secondary">format_list_numbered</span>
                    Ranking Completo
                </h3>
                <p class="text-gray-400 text-sm mt-1">Lista completa de todos os usuários</p>
                <div class="text-xs text-gray-500 mt-2">
                    Total: <?php echo count($ranking); ?> usuários
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full ranking-table">
                    <thead class="bg-black/20">
                        <tr>
                            <th class="py-3 px-4 text-left text-gray-400 font-medium text-sm">Posição</th>
                            <th class="py-3 px-4 text-left text-gray-400 font-medium text-sm">Usuário</th>

                            <th class="py-3 px-4 text-left text-gray-400 font-medium text-sm">Módulos</th>
                            <th class="py-3 px-4 text-left text-gray-400 font-medium text-sm hidden md:table-cell">Provas</th>
                            <th class="py-3 px-4 text-left text-gray-400 font-medium text-sm">Total XP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ranking as $index => $user): 
                            $is_current_user = $user['id'] == $usuario_id;
                            $posicao = $index + 1;
                            
                            // Determinar classe da medalha
                            $medal_bg = '';
                            if ($posicao == 1) {
                                $medal_bg = 'bg-gradient-to-r from-gold/20 to-gold/40 border border-gold/30';
                            } elseif ($posicao == 2) {
                                $medal_bg = 'bg-gradient-to-r from-silver/20 to-silver/40 border border-silver/30';
                            } elseif ($posicao == 3) {
                                $medal_bg = 'bg-gradient-to-r from-bronze/20 to-bronze/40 border border-bronze/30';
                            } elseif ($posicao <= 10) {
                                $medal_bg = 'bg-gradient-to-r from-gray-700 to-gray-800 border border-gray-600';
                            }
                        ?>
                        <tr class="border-t border-white/5 hover:bg-white/5 transition-colors <?php echo $is_current_user ? 'bg-primary/10' : ''; ?>">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm <?php echo $medal_bg; ?>">
                                        <?php echo $posicao; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 lg:w-10 lg:h-10 rounded-full bg-gray-800 flex items-center justify-center <?php echo $is_current_user ? 'user-highlight' : ''; ?>">
                                        <span class="material-symbols-outlined text-gray-400 text-sm lg:text-base">person</span>
                                    </div>
                                    <div>
                                        <div class="font-medium text-white text-sm truncate max-w-[150px] lg:max-w-[200px]">
                                            <?php echo htmlspecialchars($user['nome']); ?>
                                            <?php if ($is_current_user): ?>
                                                <span class="text-primary ml-1">(Você)</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-xs text-gray-400">
                                            <?php echo $user['modulos_concluidos'] ?? 0; ?> módulos • 
                                            <?php echo $user['provas_aprovadas'] ?? 0; ?> provas
                                        </div>
                                    </div>
                                </div>
                            </td>

                            </td>
                            <td class="py-3 px-4">
                                <div class="text-center">
                                    <div class="text-base font-bold <?php echo ($user['modulos_concluidos'] ?? 0) > 0 ? 'text-primary' : 'text-gray-500'; ?>">
                                        <?php echo $user['modulos_concluidos'] ?? 0; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4 hidden md:table-cell">
                                <div class="text-center">
                                    <div class="text-base font-bold <?php echo ($user['provas_aprovadas'] ?? 0) > 0 ? 'text-complete' : 'text-gray-500'; ?>">
                                        <?php echo $user['provas_aprovadas'] ?? 0; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <div class="font-bold <?php echo ($user['total_xp'] ?? 0) > 0 ? 'text-xp' : 'text-gray-500'; ?> text-base">
                                    <?php echo number_format($user['total_xp'] ?? 0); ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Estatísticas do Ranking -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8">
            <?php
            // Calcular estatísticas
            $total_usuarios = count($ranking);
            $ranking_com_xp = array_filter($ranking, function($user) {
                return ($user['total_xp'] ?? 0) > 0;
            });
            $usuarios_com_xp = count($ranking_com_xp);
            $media_xp = $usuarios_com_xp > 0 ? 
                array_sum(array_column($ranking_com_xp, 'total_xp')) / $usuarios_com_xp : 0;
            ?>
            
            <div class="bg-card rounded-xl border border-white/5 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="bg-primary/20 p-2 rounded-lg">
                        <span class="material-symbols-outlined text-primary">timeline</span>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400">Média de XP</div>
                        <div class="text-xl font-bold text-white">
                            <?php echo number_format($media_xp, 0); ?> XP
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-card rounded-xl border border-white/5 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="bg-secondary/20 p-2 rounded-lg">
                        <span class="material-symbols-outlined text-secondary">emoji_events</span>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400">Líder Atual</div>
                        <div class="text-lg font-bold text-white truncate">
                            <?php echo !empty($ranking) ? htmlspecialchars($ranking[0]['nome']) : 'N/A'; ?>
                        </div>
                        <div class="text-sm text-gray-400">
                            <?php echo !empty($ranking) ? number_format($ranking[0]['total_xp'] ?? 0) . ' XP' : ''; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-card rounded-xl border border-white/5 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="bg-xp/20 p-2 rounded-lg">
                        <span class="material-symbols-outlined text-xp">groups</span>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400">Total de Usuários</div>
                        <div class="text-lg font-bold text-white"><?php echo $total_usuarios; ?></div>
                        <div class="text-sm text-gray-400">Com XP: <?php echo $usuarios_com_xp; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dicas para subir no ranking -->
        <div class="bg-card rounded-xl border border-white/5 p-6 mt-6">
            <h3 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">tips_and_updates</span>
                Como subir no ranking?
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-gray-300">
                <div class="flex items-start gap-3">
                    <div class="bg-primary/20 p-2 rounded-lg">
                        <span class="material-symbols-outlined text-primary text-sm">play_lesson</span>
                    </div>
                    <div>
                        <strong>Complete Módulos</strong>
                        <p class="text-gray-400">Cada módulo concluído vale 100 XP.</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <div class="bg-complete/20 p-2 rounded-lg">
                        <span class="material-symbols-outlined text-complete text-sm">verified</span>
                    </div>
                    <div>
                        <strong>Aprove Provas</strong>
                        <p class="text-gray-400">Cada prova aprovada vale 500 XP.</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <div class="bg-secondary/20 p-2 rounded-lg">
                        <span class="material-symbols-outlined text-secondary text-sm">speed</span>
                    </div>
                    <div>
                        <strong>Seja Consistente</strong>
                        <p class="text-gray-400">Estude regularmente para acumular mais pontos.</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <div class="bg-red-600/20 p-2 rounded-lg">
                        <span class="material-symbols-outlined text-red-400 text-sm">shopping_cart</span>
                    </div>
                    <div>
                        <strong>Resgate com Sabedoria</strong>
                        <p class="text-gray-400">Cada resgate reduz seu XP total no ranking.</p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Animar cards do ranking
    const rankingCards = document.querySelectorAll('.ranking-table tr');
    rankingCards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.05}s`;
    });
    
    // Verificar se há mensagem de erro específica
    const errorMessage = document.getElementById('error-message');
    if (errorMessage && errorMessage.textContent.includes('XP insuficiente')) {
        // Scroll para aba loja se houver erro de XP
        setTimeout(() => {
            document.querySelector('[data-tab="loja"]').click();
        }, 100);
    }
});
</script>
</body>
</html>