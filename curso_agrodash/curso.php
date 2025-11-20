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
$data_inicio_curso = null; // Nova variável para armazenar a data de início
$db_error_message = null;

try {
    // Buscar informações do curso
    $stmt_curso = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
    $stmt_curso->execute([$curso_id]);
    $curso_info = $stmt_curso->fetch(PDO::FETCH_ASSOC);
    
    if (!$curso_info) {
        header("Location: dashboard.php");
        exit;
    }

    // Buscar data de início do curso (se a coluna existir)
    $check_column = $pdo->query("SHOW COLUMNS FROM progresso_curso LIKE 'inicio_curso'");
    if ($check_column->rowCount() > 0) {
        $stmt_inicio = $pdo->prepare("SELECT inicio_curso FROM progresso_curso 
                                     WHERE usuario_id = ? AND curso_id = ? 
                                     ORDER BY id LIMIT 1");
        $stmt_inicio->execute([$usuario_id, $curso_id]);
        $resultado_inicio = $stmt_inicio->fetch(PDO::FETCH_ASSOC);
        $data_inicio_curso = $resultado_inicio['inicio_curso'] ?? null;
    }

    // Buscar nome do usuário
    $stmt_user = $pdo->prepare("SELECT nome, email, tipo FROM usuarios WHERE id = ?");
    $stmt_user->execute([$usuario_id]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
    $username = $user_data['nome'] ?? ($_SESSION['usuario_nome'] ?? 'Usuário');
    $user_email = $user_data['email'] ?? '';
    $user_tipo = $user_data['tipo'] ?? 'operador';

    // Buscar módulos do curso
    $stmt_modulos = $pdo->prepare("SELECT * FROM modulos WHERE curso_id = ? ORDER BY ordem ASC");
    $stmt_modulos->execute([$curso_id]);
    $modulos_db = $stmt_modulos->fetchAll(PDO::FETCH_ASSOC);

    // Buscar progresso dos módulos (específico por curso)
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

    // Buscar progresso da prova (específico por curso)
    $item_id_final = 'final-curso-' . $curso_id;
    $stmt_prova = $pdo->prepare("SELECT * FROM progresso_curso WHERE usuario_id = ? AND curso_id = ? AND tipo = 'prova' AND item_id = ?");
    $stmt_prova->execute([$usuario_id, $curso_id, $item_id_final]);
    if ($prova_db = $stmt_prova->fetch()) {
        $prova_final_info = $prova_db;
        $prova_final_info['aprovado'] = (bool)$prova_db['aprovado'];
        $prova_final_info['tentativas'] = intval($prova_db['tentativas']);
    } else {
        // Se não existe, inicializar com valores padrão
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
        
        // Processar conteúdos do quiz
        $conteudos_processados = [];
        
        foreach ($conteudos as $conteudo) {
            // Processar quebras de linha no conteúdo
            if (!empty($conteudo['conteudo'])) {
                $conteudo['conteudo'] = nl2br(htmlspecialchars($conteudo['conteudo']));
            }
            
            // Verificar se é um quiz e processar as perguntas
            if ($conteudo['tipo'] === 'quiz' && !empty($conteudo['perguntas_quiz'])) {
                try {
                    $perguntas = json_decode($conteudo['perguntas_quiz'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($perguntas) && count($perguntas) > 0) {
                        $conteudo['perguntas_array'] = $perguntas;
                        $conteudo['total_perguntas'] = count($perguntas);
                        $conteudo['is_quiz'] = true;
                        $conteudo['is_prova_fixacao'] = true;
                        
                        // Debug: verificar se as perguntas estão sendo processadas
                        error_log("Quiz encontrado: " . $conteudo['titulo'] . " com " . count($perguntas) . " perguntas");
                    } else {
                        $conteudo['is_quiz'] = false;
                        error_log("Quiz inválido ou sem perguntas: " . $conteudo['titulo']);
                    }
                } catch (Exception $e) {
                    $conteudo['is_quiz'] = false;
                    error_log("Erro ao processar quiz: " . $e->getMessage());
                }
            } else {
                $conteudo['is_quiz'] = false;
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

    // Buscar conquistas do usuário
    $check_table = $pdo->query("SHOW TABLES LIKE 'conquistas_usuario'");
    if ($check_table->rowCount() > 0) {
        if ($has_curso_column) {
            $stmt_conquistas = $pdo->prepare("SELECT conquista_id, data_conquista FROM conquistas_usuario WHERE usuario_id = ? AND curso_id = ?");
            $stmt_conquistas->execute([$usuario_id, $curso_id]);
        } else {
            $stmt_conquistas = $pdo->prepare("SELECT conquista_id, data_conquista FROM conquistas_usuario WHERE usuario_id = ?");
            $stmt_conquistas->execute([$usuario_id]);
        }
        $conquistas = $stmt_conquistas->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $conquistas = [];
    }

} catch(PDOException $e) {
    $username = $_SESSION['usuario_nome'] ?? 'Usuário';
    $db_error_message = "Erro ao carregar o progresso do curso: " . $e->getMessage();
    error_log("Erro no curso.php: " . $e->getMessage());
}

// Conquistas disponíveis
$conquistas_disponiveis = [
    'primeiro_modulo' => ['nome' => 'Iniciante', 'icone' => 'fas fa-seedling', 'descricao' => 'Completou o primeiro módulo'],
    'metade_curso' => ['nome' => 'Em Progresso', 'icone' => 'fas fa-trophy', 'descricao' => 'Completou 50% do curso'],
    'curso_concluido' => ['nome' => 'Concluído', 'icone' => 'fas fa-graduation-cap', 'descricao' => 'Finalizou todos os módulos'],
    'prova_aprovada' => ['nome' => 'Aprovado', 'icone' => 'fas fa-award', 'descricao' => 'Aprovado na prova final'],
    'nota_maxima' => ['nome' => 'Excelência', 'icone' => 'fas fa-star', 'descricao' => 'Nota máxima na prova'],
];

// Calcular estatísticas
$total_modulos = count($modulos_info);
$modulos_concluidos = count(array_filter($modulos_info, fn($mod) => $mod['concluido']));
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
    'usuario_email' => $user_email ?? '',
    'data_inicio_curso' => $data_inicio_curso,
    'progresso_modulos' => array_keys(array_filter($progresso_modulos, fn($mod) => $mod['concluido'])),
    'total_modulos' => $total_modulos,
    'modulos_info' => $modulos_info,
    'prova_final_info' => $prova_final_info,
    'conquistas' => $conquistas,
    'conquistas_disponiveis' => $conquistas_disponiveis
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($curso_info['titulo'] ?? 'Treinamento AgroDash') ?> - Plataforma</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/curso_style.css">
    <style>
        .badge-conquista {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin: 2px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-item {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-left: 4px solid #32CD32;
        }
        .stat-item.aprovado {
            border-left-color: #00C853;
            background: #f8fff9;
        }
        .stat-item .value {
            font-size: 2rem;
            font-weight: bold;
            color: #32CD32;
        }
        .stat-item .label {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.5rem;
        }
        .conquistas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .conquista-item {
            text-align: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px solid #e9ecef;
        }
        .conquista-item.conquistada {
            border-color: #32CD32;
            background: #f0fff4;
        }
        .conquista-icone {
            font-size: 2rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }
        .conquista-item.conquistada .conquista-icone {
            color: #32CD32;
        }
        .admin-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        .admin-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .quiz-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #32CD32;
        }

        .quiz-stats {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }

        .quiz-stats span {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
            color: #6c757d;
        }

        .pergunta {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
        }

        .pergunta-cabecalho {
            margin-bottom: 15px;
        }

        .pergunta-cabecalho .numero {
            background: #32CD32;
            color: white;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            font-weight: bold;
        }

        .pergunta-cabecalho .texto {
            font-weight: 500;
            color: #2c3e50;
            display: inline;
        }

        .opcoes {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .opcao {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .opcao:hover {
            border-color: #32CD32;
            background: #f8fff9;
        }

        .opcao input[type="radio"] {
            margin: 0;
        }

        .opcao label {
            cursor: pointer;
            flex: 1;
            margin: 0;
        }

        .btn-finalizar-quiz {
            width: 100%;
            margin-top: 20px;
            padding: 12px;
            font-size: 1.1rem;
        }

        .quiz-item.oculto {
            display: none !important;
        }

        #toastContainer {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .toast {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #333;
            color: #fff;
            padding: 12px 16px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.4s ease;
            min-width: 260px;
            pointer-events: auto;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }

        .toast.hide {
            opacity: 0;
            transform: translateX(100%);
        }

        .toast i {
            font-size: 18px;
        }

        .toast.success { background: #ffffffff; }
        .toast.error { background: #e74c3c; }
        .toast.info { background: #f8f8f8ff; }
        .toast.warning { background: #fffbebff; color: #222; }

        .toast-close {
            background: transparent;
            border: none;
            color: inherit;
            cursor: pointer;
            margin-left: auto;
            font-size: 14px;
            opacity: 0.8;
            transition: opacity 0.2s;
        }

        .toast-close:hover {
            opacity: 1;
        }

        .btn-certificado {
            display: block !important;
            margin-bottom: 15px;
        }

        .btn-certificado.oculto {
            display: none !important;
        }

        /* ======= ESTILOS PARA RESULTADO DA PROVA ======= */
        .resultado-quiz {
            background: linear-gradient(135deg, #f8fff9 0%, #e8f5e9 100%);
            padding: 25px;
            border-radius: 12px;
            border-left: 6px solid #32CD32;
            margin: 20px 0;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .resultado-quiz.reprovado {
            background: linear-gradient(135deg, #fff8f8 0%, #ffebee 100%);
            border-left-color: #e74c3c;
        }

        .resultado-titulo {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #2c3e50;
        }

        .resultado-quiz.reprovado .resultado-titulo {
            color: #e74c3c;
        }

        .resultado-nota {
            font-size: 48px;
            font-weight: 800;
            margin: 20px 0;
            color: #32CD32;
        }

        .resultado-quiz.reprovado .resultado-nota {
            color: #e74c3c;
        }

        .resultado-mensagem {
            font-size: 18px;
            margin: 15px 0;
            color: #555;
            line-height: 1.5;
        }

        .resultado-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .stat-item-resultado {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #32CD32;
            display: block;
        }

        .resultado-quiz.reprovado .stat-value {
            color: #e74c3c;
        }

        .stat-label {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }

        .btn-revisar {
            background: #3498db;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            margin: 10px;
            transition: all 0.3s ease;
        }

        .btn-revisar:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .btn-continuar {
            background: #32CD32;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            margin: 10px;
            transition: all 0.3s ease;
        }

        .btn-continuar:hover {
            background: #28a428;
            transform: translateY(-2px);
        }

        /* ======= ESTILOS PARA QUIZ TRAVADO ======= */
        .quiz-travado {
            opacity: 0.7;
            pointer-events: none;
            position: relative;
        }

        .quiz-travado::before {
            content: "✓ CONCLUÍDO";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #32CD32;
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 16px;
            z-index: 10;
            box-shadow: 0 4px 15px rgba(50, 205, 50, 0.3);
        }

        .pergunta-travada {
            opacity: 0.6;
            pointer-events: none;
        }

        .opcao-travada {
            opacity: 0.6;
            pointer-events: none;
            background: #f8f9fa !important;
            border-color: #dee2e6 !important;
        }

        .opcao-travada label {
            cursor: not-allowed !important;
        }

        .btn-travado {
            opacity: 0.5;
            pointer-events: none;
            cursor: not-allowed !important;
        }

        .badge-concluido {
            background: #32CD32;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }

        /* Estilos específicos para prova final travada */
        .prova-final-travada {
            opacity: 0.7;
            pointer-events: none;
            position: relative;
        }

        .prova-final-travada::before {
            content: "🎓 PROVA FINALIZADA";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: linear-gradient(135deg, #32CD32, #00C853);
            color: white;
            padding: 15px 30px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 18px;
            z-index: 10;
            box-shadow: 0 6px 20px rgba(50, 205, 50, 0.4);
        }

        /* ======= ESTILOS PARA TELA DE RESULTADOS ======= */
        .revisao-prova-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }

        .pergunta-revisao {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 5px solid #e74c3c;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .pergunta-revisao.acertou {
            border-left-color: #32CD32;
            background: #f8fff9;
        }

        .pergunta-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .pergunta-numero {
            font-weight: bold;
            color: #2c3e50;
            font-size: 1.1em;
        }

        .pergunta-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .pergunta-status.acerto {
            background: #d4edda;
            color: #155724;
        }

        .pergunta-status.erro {
            background: #f8d7da;
            color: #721c24;
        }

        .pergunta-texto {
            font-weight: 500;
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 1.05em;
            line-height: 1.4;
        }

        .resposta-usuario, .resposta-correta, .explicacao {
            margin: 8px 0;
            padding: 10px;
            border-radius: 6px;
            font-size: 0.95em;
        }

        .resposta-usuario {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }

        .resposta-correta {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        .explicacao {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            color: #495057;
            font-style: italic;
        }

        .acoes-resultado {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .btn-certificado-resultado {
            background: linear-gradient(135deg, #32CD32, #28a428);
            color: white;
            padding: 12px 25px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-certificado-resultado:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(50, 205, 50, 0.4);
            color: white;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .revisao-prova-container {
                padding: 10px;
            }
            
            .resultado-stats {
                grid-template-columns: 1fr;
            }
            
            .acoes-resultado {
                flex-direction: column;
                align-items: center;
            }
            
            .pergunta-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }

        /* NOVOS ESTILOS PARA ESTATÍSTICAS */
        .estatisticas-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .estatisticas-container h2 {
            text-align: center;
            color: #2c3e50;
            margin-bottom: 40px;
            font-size: 2.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .estatisticas-container h2 i {
            color: #3498db;
        }

        .estatisticas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .estatistica-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-left: 5px solid #3498db;
            transition: all 0.3s ease;
        }

        .estatistica-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .estatistica-card:nth-child(2) {
            border-left-color: #32CD32;
        }

        .estatistica-card:nth-child(3) {
            border-left-color: #e74c3c;
        }

        .estatistica-card:nth-child(4) {
            border-left-color: #f39c12;
        }

        .estatistica-card h3 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .estatistica-valor {
            font-size: 2.2rem;
            font-weight: 800;
            margin: 15px 0;
            min-height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .estatistica-card:nth-child(1) .estatistica-valor {
            color: #3498db;
            font-size: 1.8rem;
        }

        .estatistica-card:nth-child(2) .estatistica-valor {
            color: #32CD32;
        }

        .estatistica-card:nth-child(3) .estatistica-valor {
            color: #e74c3c;
        }

        .estatistica-card:nth-child(4) .estatistica-valor {
            color: #f39c12;
        }

        .estatistica-desc {
            color: #7f8c8d;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .resumo-estatisticas {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-top: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .resumo-estatisticas h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .lista-resumo {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .item-resumo {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .item-resumo i {
            font-size: 1.3rem;
            color: #3498db;
        }

        .info-resumo h4 {
            color: #2c3e50;
            margin: 0 0 4px 0;
            font-size: 0.95rem;
        }

        .info-resumo p {
            color: #7f8c8d;
            margin: 0;
            font-size: 0.85rem;
        }

        @media (max-width: 768px) {
            .estatisticas-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .estatistica-card {
                padding: 20px;
            }
            
            .estatistica-valor {
                font-size: 1.8rem;
            }
            
            .lista-resumo {
                grid-template-columns: 1fr;
            }
        }

        /* Estilos para provas de fixação */
        .prova-fixacao-section {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }

        .section-title {
            color: #2c3e50;
            font-size: 1.3rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .prova-fixacao-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .prova-fixacao-card.concluida {
            background: linear-gradient(135deg, #32CD32 0%, #228B22 100%);
        }

        .prova-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
        }

        .prova-info h4 {
            margin: 0 0 10px 0;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .prova-info p {
            margin: 0 0 15px 0;
            opacity: 0.9;
            line-height: 1.5;
        }

        .prova-stats {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .prova-stats span {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .prova-actions {
            flex-shrink: 0;
        }

        .btn-iniciar-prova {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid white;
            color: white;
            padding: 12px 20px;
            font-weight: 600;
        }

        .btn-iniciar-prova:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
        }

        .prova-concluida {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 15px;
            border-radius: 25px;
            font-weight: 600;
        }

        .prova-resultado {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .resultado-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        /* Estilos para o modo prova de fixação */
        .prova-fixacao-detalhe {
            max-width: 100%;
        }

        .prova-header-detalhe {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 25px;
        }

        .prova-header-detalhe h3 {
            margin: 0 0 15px 0;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .prova-meta-detalhe {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .prova-meta-detalhe span {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
            opacity: 0.9;
        }

        .prova-descricao {
            font-size: 1.1rem;
            line-height: 1.6;
            opacity: 0.9;
            margin: 0;
        }

        .prova-body-detalhe {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .quiz-instructions {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
            border-left: 4px solid #32CD32;
        }

        .instruction-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
            color: #2c3e50;
        }

        .instruction-item:last-child {
            margin-bottom: 0;
        }

        .instruction-item i {
            color: #32CD32;
            font-size: 1.1rem;
        }

        .quiz-placeholder {
            text-align: center;
            padding: 40px 20px;
        }

        .placeholder-info i {
            font-size: 3rem;
            color: #6c757d;
            margin-bottom: 15px;
        }

        .placeholder-info h4 {
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .placeholder-info p {
            color: #6c757d;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .prova-fixacao-resultado .resultado-titulo {
            font-size: 1.8rem;
        }

        .prova-fixacao-resultado .resultado-nota {
            font-size: 3.5rem;
        }

        /* Estilos para conteúdo formatado */
        .conteudo-texto {
            line-height: 1.6;
            font-size: 1rem;
        }

        .conteudo-texto p {
            margin-bottom: 1rem;
        }

        .conteudo-texto ul, .conteudo-texto ol {
            margin-bottom: 1rem;
            padding-left: 1.5rem;
        }

        .conteudo-texto li {
            margin-bottom: 0.5rem;
        }

        .conteudo-texto h1, .conteudo-texto h2, .conteudo-texto h3, .conteudo-texto h4 {
            margin: 1.5rem 0 1rem 0;
            color: #2c3e50;
        }

        .conteudo-texto blockquote {
            border-left: 4px solid #32CD32;
            padding-left: 1rem;
            margin: 1rem 0;
            font-style: italic;
            color: #555;
        }

        .conteudo-texto code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
        }

        .conteudo-texto pre {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
            margin: 1rem 0;
        }

        .opcao-letra {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-weight: bold;
            margin-right: 10px;
            font-size: 0.8rem;
        }

        .opcao-texto {
            flex: 1;
        }

        .quiz-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .detalhes-resultado {
            margin-top: 20px;
            text-align: left;
        }

        .pergunta-resultado {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid #e74c3c;
        }

        .pergunta-resultado.acerto {
            border-left-color: #32CD32;
        }

        .pergunta-titulo-resultado {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .status.acerto {
            color: #32CD32;
            font-weight: bold;
        }

        .status.erro {
            color: #e74c3c;
            font-weight: bold;
        }

        .resposta-info {
            margin-top: 10px;
        }

        .resposta-usuario, .resposta-correta {
            padding: 8px;
            border-radius: 4px;
            margin: 5px 0;
        }

        .resposta-usuario {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
        }

        .resposta-correta {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
        }

        .acoes-resultado {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="logo-header">
            <img src="imagem/logo.png" alt="Logo AgroDash" />
            <h1>AgroDash</h1>
        </div>
        <div class="header-search">
            <i class="fas fa-search"></i>
            <input type="text" placeholder="Buscar lição ou módulo..." id="search-input">
        </div>
        <div class="user-profile">
            <span class="user-name">Olá, <?= htmlspecialchars($username) ?></span> 
            <div class="user-xp">
                <i class="fas fa-star"></i>
                <span><?= $pontos_xp ?> XP</span>
            </div>
            <?php if ($user_tipo === 'cia_dev' || $user_tipo === 'admin'): ?>
                <a href="admin/admin_dashboard.php" class="admin-btn">
                    <i class="fas fa-cog"></i> Painel Admin
                </a>
            <?php endif; ?>
            <img src="imagem/avatar.png" alt="Avatar" class="user-avatar" />
            <a href="dashboard.php" class="dashboard-icon" title="Voltar aos Cursos"><i class="fas fa-th"></i></a>
            <a href="logout.php" class="logout-icon" title="Sair"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </header>

    <aside class="sidebar">
        <nav>
            <p class="menu-titulo">Navegação</p>
            <ul id="menu-principal">
                <li id="menu-dashboard" class="ativo" data-nav="dashboard"><i class="fas fa-chart-bar"></i><span>Dashboard</span></li>
                <li id="menu-conquistas" data-nav="conquistas"><i class="fas fa-trophy"></i><span>Conquistas</span></li>
                <li id="menu-estatisticas" data-nav="estatisticas"><i class="fas fa-chart-line"></i><span>Estatísticas</span></li>
            </ul>
            <p class="menu-titulo"><?= htmlspecialchars($curso_info['titulo'] ?? 'Curso') ?></p>
            <ul id="menu-modulos">
                <?php foreach ($modulos_info as $id => $info): ?>
                    <li data-nav="modulo" data-modulo-id="<?= $id ?>" class="<?= $info['concluido'] ? 'concluido' : '' ?>">
                        <i class="<?= $info['icone'] ?>"></i>
                        <span><?= htmlspecialchars($info['nome']) ?></span>
                        <div class="modulo-meta">
                            <span class="modulo-duracao"><?= $info['duracao'] ?></span>
                            <i class="status-icon <?= $info['concluido'] ? 'fas fa-check-circle' : 'far fa-circle' ?>"></i>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            
            <div id="menu-avaliacao-container">
                <?php if ($todos_modulos_concluidos && !$prova_final_info['aprovado']): ?>
                <p class="menu-titulo">Avaliação</p>
                <ul>
                    <li id="iniciar-prova-final" data-nav="prova">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Prova Final</span>
                        <?php if ($prova_final_info['tentativas'] > 0): ?>
                            <span class="tentativas-badge"><?= $prova_final_info['tentativas'] ?>/2</span>
                        <?php endif; ?>
                    </li>
                </ul>
                <?php endif; ?>
            </div>
        </nav>
        
        <div class="sidebar-footer">
            <!-- SEMPRE mostrar botão do certificado se aprovado, sem classe 'oculto' -->
            <?php if ($prova_final_info['aprovado']): ?>
                <a href="certificado.php?curso_id=<?= $curso_id ?>" class="btn-certificado">
                    <i class="fas fa-certificate"></i> Ver Certificado
                </a>
            <?php endif; ?>
            
            <div class="curso-progresso">
                <div class="progresso-texto">Progresso do Curso</div>
                <div class="progress-bar-fundo mini">
                    <div class="progress-bar-preenchimento" style="width: <?= number_format($progresso_porcentagem, 0) ?>%;"></div>
                </div>
                <div class="progresso-porcentagem"><?= number_format($progresso_porcentagem, 0) ?>%</div>
            </div>
        </div>
    </aside>

    <main class="main-content" data-initial-state='<?= htmlspecialchars(json_encode($initial_state), ENT_QUOTES, 'UTF-8') ?>'>
        
        <?php if ($db_error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($db_error_message) ?>
            </div>
        <?php endif; ?>

        <div id="conteudo-curso">
            
            <div id="dashboard-view">
                <div class="dashboard-header">
                    <h2><?= htmlspecialchars($curso_info['titulo'] ?? 'Dashboard do Curso') ?></h2>
                    <div class="curso-meta">
                        <span class="nivel-curso">Nível: <?= htmlspecialchars($curso_info['nivel'] ?? 'Iniciante') ?></span>
                        <span class="duracao-curso">Duração: <?= htmlspecialchars($curso_info['duracao_estimada'] ?? '2 horas') ?></span>
                    </div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="value" id="stat-concluidos"><?= $modulos_concluidos ?></div>
                        <div class="label">Módulos Concluídos</div>
                    </div>
                    <div class="stat-item">
                        <div class="value" id="stat-total"><?= $total_modulos ?></div>
                        <div class="label">Total de Módulos</div>
                    </div>
                    <div class="stat-item">
                        <div class="value" id="stat-pontos"><?= $pontos_xp ?></div>
                        <div class="label">Pontos XP</div>
                    </div>
                    <div class="stat-item <?= $prova_final_info['aprovado'] ? 'aprovado' : '' ?>">
                        <div class="value" id="stat-aprovacao"><?= $prova_final_info['aprovado'] ? 'SIM' : 'NÃO' ?></div>
                        <div class="label">Aprovação Final</div>
                    </div>
                </div>

                <div class="dashboard-grid">
                    <div class="card-modulos">
                        <div class="dashboard-card">
                            <div class="dashboard-header"><h3>Seu Progresso Atual</h3></div>
                            <div class="progress-container">
                                <div class="progress-text">
                                    <span>Progresso Geral</span>
                                    <span id="progresso-porcentagem"><?= number_format($progresso_porcentagem, 0) ?>% Completo</span>
                                </div>
                                <div class="progress-bar-fundo">
                                    <div class="progress-bar-preenchimento" style="width: <?= number_format($progresso_porcentagem, 0) ?>%;"></div>
                                </div>
                            </div>

                            <ul class="dashboard-modulos">
                                <?php foreach ($modulos_info as $id => $info): ?>
                                    <li class="<?= $info['concluido'] ? 'concluido' : '' ?>">
                                        <div class="modulo-info">
                                            <i class="<?= $info['icone'] ?>"></i>
                                            <div class="modulo-detalhes">
                                                <span class="modulo-nome"><?= htmlspecialchars($info['nome']) ?></span>
                                                <span class="modulo-meta"><?= $info['duracao'] ?> • 
                                                    <?php if ($info['concluido'] && $info['data_conclusao']): ?>
                                                        Concluído em <?= date('d/m/Y', strtotime($info['data_conclusao'])) ?>
                                                    <?php else: ?>
                                                        Não iniciado
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <button class="btn btn-sm btn-carregar-modulo" data-modulo-id="<?= $id ?>">
                                            <?= $info['concluido'] ? 'Revisar' : 'Continuar' ?>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="card-info">
                        <div class="dashboard-card">
                            <div class="dashboard-header"><h3>Próximos Passos</h3></div>
                            
                            <div id="proximos-passos-content">
                                <?php if ($prova_final_info['aprovado']): ?>
                                    <div class="alerta-progresso alerta-sucesso">
                                        <i class="fas fa-trophy"></i> Você está Aprovado! Emita seu certificado.
                                    </div>
                                    <a href="certificado.php?curso_id=<?= $curso_id ?>" class="btn btn-success">
                                        <i class="fas fa-certificate"></i> Emitir Certificado
                                    </a>
                                <?php elseif ($todos_modulos_concluidos): ?>
                                    <div class="alerta-progresso">
                                        <i class="fas fa-exclamation-triangle"></i> Parabéns! Você já pode iniciar a prova final.
                                    </div>
                                    <button class="btn btn-primary" id="iniciar-prova-final-dashboard" data-nav="prova">
                                        <i class="fas fa-graduation-cap"></i> Iniciar Prova Final
                                    </button>
                                    <?php if ($prova_final_info['tentativas'] > 0): ?>
                                        <div class="tentativas-info">
                                            Tentativas utilizadas: <?= $prova_final_info['tentativas'] ?>/2
                                        </div>
                                    <?php endif; ?>
                                <?php else: 
                                    $proximo_modulo_id = null;
                                    foreach ($modulos_info as $id => $info) {
                                        if (!$info['concluido']) {
                                            $proximo_modulo_id = $id;
                                            break;
                                        }
                                    }
                                ?>
                                    <div class="alerta-progresso">
                                        <i class="fas fa-forward"></i> Restam <strong><?= $total_modulos - $modulos_concluidos ?> módulos</strong> para completar.
                                    </div>
                                    <?php if ($proximo_modulo_id): ?>
                                        <button class="btn btn-primary btn-carregar-modulo" data-modulo-id="<?= $proximo_modulo_id ?>">
                                            <i class="fas fa-arrow-right"></i> Ir para o Próximo Módulo
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="conquistas-rapidas">
                                <h4>Suas Conquistas</h4>
                                <div class="conquistas-grid">
                                    <?php 
                                    $conquistas_usuario = array_column($conquistas, 'conquista_id');
                                    foreach ($conquistas_disponiveis as $key => $conquista): 
                                        $conquistada = in_array($key, $conquistas_usuario);
                                    ?>
                                        <div class="conquista-item <?= $conquistada ? 'conquistada' : '' ?>">
                                            <div class="conquista-icone">
                                                <i class="<?= $conquista['icone'] ?>"></i>
                                            </div>
                                            <div class="conquista-nome"><?= $conquista['nome'] ?></div>
                                            <?php if (!$conquistada): ?>
                                                <div class="conquista-bloqueada"><i class="fas fa-lock"></i></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="modulo-view-container" class="oculto"></div>
            <div id="conquistas-view" class="oculto"></div>
            <div id="estatisticas-view" class="oculto"></div>
        </div>
    </main>
    
    <div id="toast-container"></div>

    <div id="templates" class="oculto">
        <!-- Templates serão gerados dinamicamente pelo JavaScript -->
        <div id="modulo-template-base">
            <div class="indice-modulo">
                <h3 class="modulo-titulo-template">Módulo: {TITULO}</h3>
                <div class="modulo-meta">
                    <span><i class="fas fa-clock"></i> {DURACAO}</span>
                    <span><i class="fas fa-play-circle"></i> {TOTAL_LICOES} lições</span>
                </div>
                <ul class="lista-licoes" data-total-licoes="{TOTAL_LICOES}">
                    <!-- Lições serão adicionadas aqui -->
                </ul>
            </div>
            <div class="conteudo-licao-container" id="container-licao-{MODULO_ID}"></div>
        </div>

        <div id="licao-template-base">
            <div class="conteudo-licao">
                <h4>{TITULO}</h4>
                <div class="conteudo-body">
                    {CONTEUDO}
                </div>
                <?php if ($user_tipo === 'cia_dev' || $user_tipo === 'admin'): ?>
                <div class="admin-actions" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                    <small style="color: #666;">
                        <i class="fas fa-cog"></i> Ações do Administrador:
                        <a href="admin/admin_conteudos.php?modulo_id={MODULO_ID}" target="_blank" style="color: #32CD32; margin-left: 10px;">Editar Conteúdo</a>
                    </small>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Template de Conquistas -->
        <div id="conquistas-template">
            <div class="conquistas-container">
                <h2><i class="fas fa-trophy"></i> Suas Conquistas</h2>
                <div class="conquistas-grid-expandido"></div>
            </div>
        </div>

        <!-- Template de Estatísticas -->
        <div id="estatisticas-template">
            <div class="estatisticas-container">
                <h2><i class="fas fa-chart-line"></i> Estatísticas Detalhadas</h2>
                <div class="estatisticas-grid">
                    <div class="estatistica-card">
                        <h3>Tempo de Estudo</h3>
                        <div class="estatistica-valor">0h 0min</div>
                        <div class="estatistica-desc">Total dedicado ao curso</div>
                    </div>
                    <div class="estatistica-card">
                        <h3>Taxa de Conclusão</h3>
                        <div class="estatistica-valor"><?= number_format($progresso_porcentagem, 1) ?>%</div>
                        <div class="estatistica-desc">Progresso geral</div>
                    </div>
                    <div class="estatistica-card">
                        <h3>Média de Acertos</h3>
                        <div class="estatistica-valor">0%</div>
                        <div class="estatistica-desc">Nos quizzes</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Template de Quiz -->
        <div id="quiz-template">
            <div class="quiz-container">
                <h4><i class="fas fa-spell-check"></i> Prova de Fixação do Módulo</h4>
                <div class="quiz-info">
                    <p>Parabéns! Você completou todas as lições deste módulo. Agora é hora de testar seu conhecimento.</p>
                    <div class="quiz-stats">
                        <span><i class="fas fa-question-circle"></i> <span class="total-perguntas">1</span> pergunta(s)</span>
                        <span><i class="fas fa-trophy"></i> Complete para avançar</span>
                    </div>
                </div>
                
                <!-- Área do resultado (inicialmente oculta) -->
                <div id="resultado-quiz-{QUIZ_ID}" class="resultado-quiz oculto">
                    <!-- Conteúdo gerado dinamicamente pelo JavaScript -->
                </div>
                
                <!-- Área das perguntas (travada após conclusão) -->
                <div class="perguntas-wrapper" id="perguntas-wrapper-{QUIZ_ID}">
                    <!-- Perguntas serão inseridas aqui -->
                </div>
                
                <button class="btn btn-primary btn-finalizar-quiz" id="btn-finalizar-{QUIZ_ID}">
                    <i class="fas fa-paper-plane"></i> Enviar Respostas
                </button>
                
                <button class="btn btn-revisar oculto" id="btn-revisar-{QUIZ_ID}">
                    <i class="fas fa-eye"></i> Revisar Respostas
                </button>
                
                <button class="btn btn-continuar oculto" id="btn-continuar-{QUIZ_ID}">
                    <i class="fas fa-arrow-right"></i> Continuar
                </button>
            </div>
        </div>

        <!-- Template de Resultado da Prova Final -->
        <div id="resultado-prova-template">
            <div class="resultado-quiz">
                <div class="resultado-titulo">{TITULO}</div>
                <div class="resultado-nota">{NOTA}%</div>
                <div class="resultado-mensagem">{MENSAGEM}</div>
                
                <div class="resultado-stats">
                    <div class="stat-item-resultado">
                        <span class="stat-value">{ACERTOS}</span>
                        <span class="stat-label">Acertos</span>
                    </div>
                    <div class="stat-item-resultado">
                        <span class="stat-value">{TOTAL}</span>
                        <span class="stat-label">Total</span>
                    </div>
                    <div class="stat-item-resultado">
                        <span class="stat-value">{TAXA}%</span>
                        <span class="stat-label">Taxa de Acerto</span>
                    </div>
                </div>
                
                <div class="resultado-detalhes">
                    <h5>Detalhes das Respostas:</h5>
                    <div class="detalhes-perguntas">
                        <!-- Detalhes das perguntas serão inseridos aqui -->
                    </div>
                </div>
                
                <div class="acoes-resultado">
                    <button class="btn btn-revisar" id="btn-revisar-prova">
                        <i class="fas fa-eye"></i> Revisar Respostas
                    </button>
                    <button class="btn btn-continuar" id="btn-continuar-prova">
                        <i class="fas fa-arrow-right"></i> Continuar
                    </button>
                    <a href="certificado.php?curso_id={CURSO_ID}" class="btn btn-certificado-resultado {CERTIFICADO_CLASS}">
                        <i class="fas fa-certificate"></i> Emitir Certificado
                    </a>
                </div>
            </div>
        </div>

        <!-- Template de Prova Final -->
        <div id="prova-final-template">
            <h2><i class="fas fa-graduation-cap"></i> Prova Final do Curso</h2>
            <p>Você precisa de <strong>70% de acertos</strong> para aprovação. Você tem <strong>2 tentativas</strong>.</p>
            <div id="timer-bloqueio" class="oculto"></div>
            <div class="perguntas-wrapper"></div>
            <button class="btn" id="btn-finalizar-prova">Enviar Respostas</button>
        </div>
    </div>

    <script>
    // Funções JavaScript para renderizar quizzes e conteúdo formatado
    function renderizarQuiz(conteudo) {
        console.log('Iniciando renderização do quiz:', conteudo);
        
        // Verificar se existem perguntas válidas
        if (!conteudo.perguntas_array || !Array.isArray(conteudo.perguntas_array) || conteudo.perguntas_array.length === 0) {
            console.error('Quiz sem perguntas válidas:', conteudo);
            return `
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Quiz não configurado:</strong> Este quiz não possui perguntas válidas.
                </div>
            `;
        }

        // Validar cada pergunta
        const perguntasValidas = conteudo.perguntas_array.filter(pergunta => {
            return pergunta.pergunta && 
                   Array.isArray(pergunta.opcoes) && 
                   pergunta.opcoes.length >= 2 &&
                   typeof pergunta.resposta === 'number' &&
                   pergunta.resposta >= 0 && 
                   pergunta.resposta < pergunta.opcoes.length;
        });

        if (perguntasValidas.length === 0) {
            return `
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Quiz inválido:</strong> Nenhuma pergunta válida encontrada.
                </div>
            `;
        }

        console.log('Perguntas válidas encontradas:', perguntasValidas.length);

        // Armazenar dados do quiz globalmente para acesso posterior
        if (!window.quizData) window.quizData = {};
        window.quizData[conteudo.id] = perguntasValidas;

        let html = `
            <div class="quiz-container" id="quiz-container-${conteudo.id}">
                <div class="quiz-info">
                    <p><strong>Instruções:</strong> Responda todas as perguntas abaixo. Você precisa de 70% de acertos para aprovação.</p>
                    <div class="quiz-stats">
                        <span><i class="fas fa-question-circle"></i> ${perguntasValidas.length} pergunta(s)</span>
                        <span><i class="fas fa-trophy"></i> 70% para aprovação</span>
                    </div>
                </div>
                
                <form class="quiz-form" id="quiz-form-${conteudo.id}">
        `;

        perguntasValidas.forEach((pergunta, index) => {
            console.log('Renderizando pergunta:', pergunta);
            
            html += `
                <div class="pergunta" id="pergunta-${conteudo.id}-${index}">
                    <div class="pergunta-cabecalho">
                        <span class="numero">${index + 1}</span>
                        <span class="texto">${pergunta.pergunta}</span>
                    </div>
                    <div class="opcoes">
            `;

            pergunta.opcoes.forEach((opcao, opcaoIndex) => {
                const opcaoId = `q${conteudo.id}_p${index}_o${opcaoIndex}`;
                html += `
                    <div class="opcao">
                        <input type="radio" id="${opcaoId}" 
                               name="pergunta_${index}" value="${opcaoIndex}"
                               onchange="validarQuiz(${conteudo.id})">
                        <label for="${opcaoId}">
                            <span class="opcao-letra">${String.fromCharCode(65 + opcaoIndex)}</span>
                            <span class="opcao-texto">${opcao}</span>
                        </label>
                    </div>
                `;
            });

            html += `
                    </div>
                </div>
            `;
        });

        html += `
                    <div class="quiz-actions">
                        <button type="button" class="btn btn-primary btn-finalizar-quiz" 
                                id="btn-finalizar-${conteudo.id}" 
                                onclick="submeterQuiz(${conteudo.id})"
                                disabled>
                            <i class="fas fa-paper-plane"></i> Enviar Respostas
                            <span id="contador-${conteudo.id}"> (0/${perguntasValidas.length})</span>
                        </button>
                        <button type="button" class="btn btn-secondary" 
                                onclick="reiniciarQuiz(${conteudo.id})">
                            <i class="fas fa-redo"></i> Reiniciar
                        </button>
                    </div>
                </form>
                
                <div id="resultado-quiz-${conteudo.id}" class="resultado-quiz oculto"></div>
            </div>
        `;

        console.log('Quiz renderizado com sucesso');
        return html;
    }

    function validarQuiz(conteudoId) {
        const form = document.getElementById(`quiz-form-${conteudoId}`);
        const perguntas = window.quizData[conteudoId];
        
        if (!perguntas) {
            console.error('Dados do quiz não encontrados para:', conteudoId);
            return;
        }

        const totalPerguntas = perguntas.length;
        let respostasRespondidas = 0;

        for (let i = 0; i < totalPerguntas; i++) {
            if (form.querySelector(`input[name="pergunta_${i}"]:checked`)) {
                respostasRespondidas++;
            }
        }

        const btnFinalizar = document.getElementById(`btn-finalizar-${conteudoId}`);
        const contador = document.getElementById(`contador-${conteudoId}`);
        
        btnFinalizar.disabled = respostasRespondidas !== totalPerguntas;
        
        if (contador) {
            contador.textContent = ` (${respostasRespondidas}/${totalPerguntas})`;
        }
        
        console.log(`Quiz ${conteudoId}: ${respostasRespondidas}/${totalPerguntas} respondidas`);
    }

    function submeterQuiz(conteudoId, isProvaFixacao = false) {
        const form = document.getElementById(`quiz-form-${conteudoId}`);
        const perguntas = window.quizData[conteudoId];
        
        if (!perguntas) {
            mostrarToast('Dados do quiz não encontrados.', 'error');
            return;
        }

        let acertos = 0;
        const resultados = [];

        perguntas.forEach((pergunta, index) => {
            const respostaSelecionada = form.querySelector(`input[name="pergunta_${index}"]:checked`);
            const respostaCorreta = pergunta.resposta;
            const respostaUsuario = respostaSelecionada ? parseInt(respostaSelecionada.value) : null;
            
            const acertou = respostaUsuario === respostaCorreta;
            if (acertou) acertos++;
            
            resultados.push({
                pergunta: pergunta.pergunta,
                opcoes: pergunta.opcoes,
                respostaCorreta: respostaCorreta,
                respostaUsuario: respostaUsuario,
                acertou: acertou
            });
        });

        const percentual = Math.round((acertos / perguntas.length) * 100);
        exibirResultadoQuiz(conteudoId, percentual, acertos, perguntas.length, resultados, isProvaFixacao);
    }

    function exibirResultadoQuiz(conteudoId, percentual, acertos, total, resultados, isProvaFixacao = false) {
        const resultadoDiv = document.getElementById(`resultado-quiz-${conteudoId}`);
        const aprovado = percentual >= 70;
        
        let detalhesHTML = '';
        resultados.forEach((resultado, index) => {
            detalhesHTML += `
                <div class="pergunta-resultado ${resultado.acertou ? 'acerto' : 'erro'}">
                    <div class="pergunta-titulo-resultado">
                        <strong>${index + 1}. ${resultado.pergunta}</strong>
                        <span class="status ${resultado.acertou ? 'acerto' : 'erro'}">
                            ${resultado.acertou ? '✓ Acertou' : '✗ Errou'}
                        </span>
                    </div>
                    <div class="resposta-info">
                        <div class="resposta-usuario">
                            <strong>Sua resposta:</strong> ${resultado.opcoes[resultado.respostaUsuario] || 'Não respondida'}
                        </div>
                        ${!resultado.acertou ? `
                            <div class="resposta-correta">
                                <strong>Resposta correta:</strong> ${resultado.opcoes[resultado.respostaCorreta]}
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
        });
        
        resultadoDiv.innerHTML = `
            <div class="resultado-quiz ${aprovado ? '' : 'reprovado'} ${isProvaFixacao ? 'prova-fixacao-resultado' : ''}">
                <div class="resultado-titulo">
                    ${isProvaFixacao ? 
                        (aprovado ? '🎉 Módulo Concluído!' : '📝 Módulo Não Aprovado') :
                        (aprovado ? '🎉 Parabéns!' : '📝 Precisa Melhorar')
                    }
                </div>
                <div class="resultado-nota">${percentual}%</div>
                <div class="resultado-mensagem">
                    ${isProvaFixacao ? 
                        (aprovado ? 
                            `Parabéns! Você aprovou na prova de fixação com ${acertos} de ${total} acertos.` :
                            `Você acertou ${acertos} de ${total} questões. Precisa de 70% para aprovar o módulo.`
                        ) :
                        (aprovado ? 
                            `Você acertou ${acertos} de ${total} perguntas e foi aprovado no quiz!` :
                            `Você acertou ${acertos} de ${total} perguntas. Precisa de 70% para aprovação.`
                        )
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
                        <span class="stat-value">${percentual}%</span>
                        <span class="stat-label">Taxa de Acerto</span>
                    </div>
                </div>
                
                <div class="detalhes-resultado">
                    <h5>Detalhes das Respostas:</h5>
                    ${detalhesHTML}
                </div>
                
                <div class="acoes-resultado">
                    ${isProvaFixacao ? `
                        ${aprovado ? `
                            <button class="btn btn-continuar" onclick="finalizarModulo(${conteudoId})">
                                <i class="fas fa-check"></i> Concluir Módulo
                            </button>
                        ` : `
                            <button class="btn btn-revisar" onclick="reiniciarQuiz(${conteudoId})">
                                <i class="fas fa-redo"></i> Tentar Novamente
                            </button>
                        `}
                    ` : `
                        <button class="btn ${aprovado ? 'btn-continuar' : 'btn-revisar'}" 
                                onclick="${aprovado ? `marcarComoConcluido(null, ${conteudoId})` : `reiniciarQuiz(${conteudoId})`}">
                            <i class="fas fa-${aprovado ? 'check' : 'redo'}"></i>
                            ${aprovado ? 'Continuar' : 'Tentar Novamente'}
                        </button>
                    `}
                </div>
            </div>
        `;
        
        resultadoDiv.classList.remove('oculto');
        document.getElementById(`quiz-form-${conteudoId}`).classList.add('oculto');
        
        // Salvar progresso se aprovado
        if (aprovado && isProvaFixacao) {
            salvarProgressoModulo(conteudoId);
        }
    }

    function reiniciarQuiz(conteudoId) {
        const form = document.getElementById(`quiz-form-${conteudoId}`);
        const resultadoDiv = document.getElementById(`resultado-quiz-${conteudoId}`);
        
        // Resetar formulário
        form.reset();
        form.classList.remove('oculto');
        resultadoDiv.classList.add('oculto');
        resultadoDiv.innerHTML = '';
        
        // Resetar botão
        const btnFinalizar = document.getElementById(`btn-finalizar-${conteudoId}`);
        btnFinalizar.disabled = true;
        btnFinalizar.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Respostas (0/' + window.quizData[conteudoId].length + ')';
    }

    function mostrarToast(mensagem, tipo = 'info') {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${tipo}`;
        toast.innerHTML = `
            <i class="fas fa-${tipo === 'success' ? 'check-circle' : tipo === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${mensagem}</span>
            <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
        `;
        
        toastContainer.appendChild(toast);
        
        // Mostrar toast
        setTimeout(() => toast.classList.add('show'), 100);
        
        // Remover toast após 5 segundos
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400);
        }, 5000);
    }

    // Funções placeholder para compatibilidade
    function finalizarModulo(conteudoId) {
        mostrarToast('Módulo concluído com sucesso!', 'success');
        // Implementar lógica para finalizar o módulo
    }

    function salvarProgressoModulo(conteudoId) {
        // Implementar lógica para salvar progresso do módulo
        console.log('Salvando progresso do módulo:', conteudoId);
    }

    function marcarComoConcluido(moduloId, conteudoId) {
        mostrarToast('Conteúdo marcado como concluído!', 'success');
        // Implementar lógica para marcar conteúdo como concluído
    }
    </script>
    <script src="js/curso_app.js" defer></script>
</body>
</html>