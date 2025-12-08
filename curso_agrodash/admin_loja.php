<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login.php");
    exit;
}

// Verificar se é administrador
if ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'cia_dev') {
    header("Location: /dashboard");
    exit;
}

// ============ FUNÇÕES AUXILIARES ============
function logAcao($acao, $detalhes = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO logs_admin (usuario_id, acao, detalhes, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_SESSION['usuario_id'], $acao, $detalhes, $_SERVER['REMOTE_ADDR']]);
        return true;
    } catch (PDOException $e) {
        error_log("Erro no log: " . $e->getMessage());
        return false;
    }
}

function processarUploadImagem($file, $pasta = 'loja') {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    
    $uploadDir = __DIR__ . "/../uploads/$pasta/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (!in_array($ext, $allowed)) {
        return '';
    }
    
    $filename = uniqid() . '_' . time() . '.' . $ext;
    $destination = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return "uploads/$pasta/" . $filename;
    }
    
    return '';
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function getConfig($chave, $default = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT valor FROM config_sistema WHERE chave = ?");
        $stmt->execute([$chave]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['valor'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function setConfig($chave, $valor) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO config_sistema (chave, valor) VALUES (?, ?) 
                              ON DUPLICATE KEY UPDATE valor = ?");
        $stmt->execute([$chave, $valor, $valor]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function gerarBackup() {
    global $pdo, $host, $user, $pass, $db;
    $backup_dir = __DIR__ . '/../backups/';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0777, true);
    }
    
    $backup_file = $backup_dir . 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    // Construir o comando mysqldump com as variáveis do conexao.php
    $command = "mysqldump --user=" . escapeshellarg($user) . 
               " --password=" . escapeshellarg($pass) . 
               " --host=" . escapeshellarg($host) . 
               " " . escapeshellarg($db) . 
               " > " . escapeshellarg($backup_file) . " 2>&1";
    
    system($command, $output);
    
    if (file_exists($backup_file) && filesize($backup_file) > 0) {
        // Limitar número de backups
        $files = glob($backup_dir . 'backup_*.sql');
        if (count($files) > 30) { // Manter apenas 30 backups
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            for ($i = 0; $i < count($files) - 30; $i++) {
                unlink($files[$i]);
            }
        }
        return $backup_file;
    }
    
    // Log de erro se o backup falhar
    error_log("Falha ao gerar backup. Comando executado: $command. Saída: " . print_r($output, true));
    return false;
}
// ============ PROCESSAMENTO DE AÇÕES ============

// Processar múltiplas ações em lote
if (isset($_POST['batch_action'])) {
    $action_type = $_POST['batch_action_type'];
    $selected_ids = $_POST['selected_items'] ?? [];
    
    if (!empty($selected_ids)) {
        try {
            $placeholders = str_repeat('?,', count($selected_ids) - 1) . '?';
            $detalhes = '';
            
            switch ($action_type) {
                case 'ativar':
                    $stmt = $pdo->prepare("UPDATE loja_xp SET status = 'ativo' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $detalhes = "Ativou " . count($selected_ids) . " itens";
                    $_SESSION['success'] = count($selected_ids) . " itens ativados com sucesso!";
                    break;
                    
                case 'inativar':
                    $stmt = $pdo->prepare("UPDATE loja_xp SET status = 'inativo' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $detalhes = "Inativou " . count($selected_ids) . " itens";
                    $_SESSION['success'] = count($selected_ids) . " itens inativados com sucesso!";
                    break;
                    
                case 'esgotar':
                    $stmt = $pdo->prepare("UPDATE loja_xp SET status = 'esgotado' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $detalhes = "Marcou como esgotado " . count($selected_ids) . " itens";
                    $_SESSION['success'] = count($selected_ids) . " itens marcados como esgotados!";
                    break;
                    
                case 'delete':
                    // Buscar imagens para deletar
                    $stmt = $pdo->prepare("SELECT imagem FROM loja_xp WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $imagens = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    foreach ($imagens as $imagem) {
                        if ($imagem && file_exists(__DIR__ . '/../' . $imagem)) {
                            unlink(__DIR__ . '/../' . $imagem);
                        }
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM loja_xp WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $detalhes = "Excluiu " . count($selected_ids) . " itens";
                    $_SESSION['success'] = count($selected_ids) . " itens excluídos com sucesso!";
                    break;
                    
                case 'update_estoque':
                    $novo_estoque = $_POST['batch_estoque'];
                    $stmt = $pdo->prepare("UPDATE loja_xp SET estoque = ? WHERE id IN ($placeholders)");
                    $stmt->execute(array_merge([$novo_estoque], $selected_ids));
                    $detalhes = "Atualizou estoque para " . count($selected_ids) . " itens";
                    $_SESSION['success'] = "Estoque atualizado para " . count($selected_ids) . " itens!";
                    break;
            }
            
            logAcao('batch_' . $action_type, $detalhes);
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao processar ação em lote: " . $e->getMessage();
            logAcao('batch_error', "Erro: " . $e->getMessage());
        }
    }
}

// Processar cupons de desconto
if (isset($_POST['add_cupom'])) {
    $codigo = strtoupper(trim($_POST['codigo_cupom']));
    $tipo = $_POST['tipo_cupom'];
    $valor = $_POST['valor_cupom'];
    $validade = !empty($_POST['validade_cupom']) ? $_POST['validade_cupom'] : null;
    $usos_maximos = $_POST['usos_maximos'];
    $categorias = isset($_POST['categorias_cupom']) ? json_encode($_POST['categorias_cupom']) : null;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO cupons_desconto (codigo, tipo, valor, validade, usos_maximos, categorias, criado_por) 
                               VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$codigo, $tipo, $valor, $validade, $usos_maximos, $categorias, $_SESSION['usuario_id']]);
        
        $_SESSION['success'] = "Cupom criado com sucesso!";
        logAcao('cupom_add', "Cupom: {$codigo}");
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao criar cupom: " . $e->getMessage();
    }
}

// Excluir cupom
if (isset($_POST['delete_cupom'])) {
    $cupom_id = $_POST['cupom_id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM cupons_desconto WHERE id = ?");
        $stmt->execute([$cupom_id]);
        
        $_SESSION['success'] = "Cupom excluído com sucesso!";
        logAcao('cupom_delete', "ID: {$cupom_id}");
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao excluir cupom: " . $e->getMessage();
    }
}

// Atualizar status da troca
if (isset($_POST['update_status'])) {
    $troca_id = $_POST['troca_id'];
    $novo_status = $_POST['novo_status'];
    $observacoes = $_POST['observacoes'] ?? '';
    $status_atual = $_POST['status_atual'] ?? '';
    
    try {
        $stmt = $pdo->prepare("UPDATE trocas_xp SET status = ?, observacoes = ?, atualizado_em = NOW() WHERE id = ?");
        $stmt->execute([$novo_status, $observacoes, $troca_id]);
        
        // Log da ação
        $stmt_log = $pdo->prepare("INSERT INTO logs_troca (troca_id, usuario_admin_id, acao, status_anterior, status_novo, observacoes) 
                                  VALUES (?, ?, 'status_update', ?, ?, ?)");
        $stmt_log->execute([$troca_id, $_SESSION['usuario_id'], $status_atual, $novo_status, $observacoes]);
        
        logAcao('troca_status', "Troca #{$troca_id}: {$status_atual} -> {$novo_status}");
        
        $_SESSION['success'] = "Status atualizado com sucesso!";
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao atualizar status: " . $e->getMessage();
    }
}

// Adicionar rastreamento de entrega
if (isset($_POST['add_rastreamento'])) {
    $troca_id = $_POST['troca_id'];
    $codigo_rastreamento = $_POST['codigo_rastreamento'];
    $transportadora = $_POST['transportadora'];
    $previsao_entrega = $_POST['previsao_entrega'];
    
    try {
        $stmt = $pdo->prepare("UPDATE trocas_xp SET codigo_rastreamento = ?, transportadora = ?, previsao_entrega = ? WHERE id = ?");
        $stmt->execute([$codigo_rastreamento, $transportadora, $previsao_entrega, $troca_id]);
        
        $_SESSION['success'] = "Rastreamento adicionado com sucesso!";
        logAcao('troca_rastreamento', "Troca #{$troca_id}");
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao adicionar rastreamento: " . $e->getMessage();
    }
}

// Sistema de backup automático
if (isset($_POST['gerar_backup'])) {
    $backup_file = gerarBackup();
    
    if ($backup_file) {
        $_SESSION['success'] = "Backup gerado com sucesso! Arquivo: " . basename($backup_file);
        logAcao('backup', "Backup criado");
    } else {
        $_SESSION['error'] = "Erro ao gerar backup!";
    }
}

// Configurações do sistema
if (isset($_POST['save_settings'])) {
    $settings = [
        'loja_nome' => $_POST['loja_nome'],
        'loja_descricao' => $_POST['loja_descricao'],
        'xp_minimo_troca' => $_POST['xp_minimo_troca'],
        'limite_trocas_diarias' => $_POST['limite_trocas_diarias'],
        'email_notificacoes' => $_POST['email_notificacoes'],
        'manutencao' => isset($_POST['manutencao']) ? 1 : 0,
        'taxa_servico' => $_POST['taxa_servico'],
        'backup_frequency' => $_POST['backup_frequency'],
        'backup_retention' => $_POST['backup_retention']
    ];
    
    try {
        foreach ($settings as $key => $value) {
            setConfig($key, $value);
        }
        $_SESSION['success'] = "Configurações salvas com sucesso!";
        logAcao('config_update', "Configurações atualizadas");
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Erro ao salvar configurações: " . $e->getMessage();
    }
}

// ============ PROCESSAMENTO ORIGINAL MANTIDO ============

// Atualizar status da troca (original)
if (isset($_POST['update_status'])) {
    $troca_id = $_POST['troca_id'];
    $novo_status = $_POST['novo_status'];
    $observacoes = $_POST['observacoes'] ?? '';
    
    try {
        $stmt = $pdo->prepare("UPDATE trocas_xp SET status = ?, observacoes = ?, atualizado_em = NOW() WHERE id = ?");
        $stmt->execute([$novo_status, $observacoes, $troca_id]);
        
        // Notificar usuário sobre mudança de status
        if (isset($_POST['notificar_usuario'])) {
            $stmt_user = $pdo->prepare("
                SELECT u.id, u.nome, u.email, l.nome as item_nome 
                FROM trocas_xp t
                JOIN usuarios u ON t.usuario_id = u.id
                JOIN loja_xp l ON t.item_id = l.id
                WHERE t.id = ?
            ");
            $stmt_user->execute([$troca_id]);
            $troca_info = $stmt_user->fetch(PDO::FETCH_ASSOC);
        }
        
        $_SESSION['success'] = "Status atualizado com sucesso!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao atualizar status: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_item'])) {
        // Adicionar novo item
        $nome = $_POST['nome'];
        $descricao = $_POST['descricao'];
        $custo_xp = $_POST['custo_xp'];
        $estoque = $_POST['estoque'];
        $categoria = $_POST['categoria'];
        $status = $_POST['status'];
        $tipo_entrega = $_POST['tipo_entrega'];
        
        $imagem = '';
        if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === 0) {
            $imagem = processarUploadImagem($_FILES['imagem'], 'loja');
        }
        
        // Processar múltiplas imagens
        $imagens_adicionais = [];
        if (isset($_FILES['imagens_adicionais'])) {
            foreach ($_FILES['imagens_adicionais']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['imagens_adicionais']['error'][$key] === 0) {
                    $file = [
                        'tmp_name' => $tmp_name,
                        'name' => $_FILES['imagens_adicionais']['name'][$key],
                        'type' => $_FILES['imagens_adicionais']['type'][$key]
                    ];
                    $img_path = processarUploadImagem($file, 'loja/adicionais');
                    if ($img_path) {
                        $imagens_adicionais[] = $img_path;
                    }
                }
            }
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO loja_xp (nome, descricao, imagem, imagens_adicionais, custo_xp, estoque, categoria, status, tipo_entrega) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $imagens_json = !empty($imagens_adicionais) ? json_encode($imagens_adicionais) : null;
            $stmt->execute([$nome, $descricao, $imagem, $imagens_json, $custo_xp, $estoque, $categoria, $status, $tipo_entrega]);
            
            $_SESSION['success'] = "Item adicionado com sucesso!";
            logAcao('item_add', "Item: {$nome}");
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao adicionar item: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['edit_item'])) {
        // Editar item existente
        $item_id = $_POST['item_id'];
        $nome = $_POST['nome'];
        $descricao = $_POST['descricao'];
        $custo_xp = $_POST['custo_xp'];
        $estoque = $_POST['estoque'];
        $categoria = $_POST['categoria'];
        $status = $_POST['status'];
        $tipo_entrega = $_POST['tipo_entrega'];
        
        $imagem = $_POST['imagem_atual'];
        if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === 0) {
            // Remover imagem antiga se existir
            if ($imagem && file_exists(__DIR__ . '/../' . $imagem)) {
                unlink(__DIR__ . '/../' . $imagem);
            }
            $imagem = processarUploadImagem($_FILES['imagem'], 'loja');
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE loja_xp SET nome = ?, descricao = ?, imagem = ?, custo_xp = ?, estoque = ?, categoria = ?, status = ?, tipo_entrega = ? WHERE id = ?");
            $stmt->execute([$nome, $descricao, $imagem, $custo_xp, $estoque, $categoria, $status, $tipo_entrega, $item_id]);
            
            $_SESSION['success'] = "Item atualizado com sucesso!";
            logAcao('item_edit', "Item ID: {$item_id}");
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao atualizar item: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_item'])) {
        $item_id = $_POST['item_id'];
        
        try {
            // Buscar imagem para deletar
            $stmt_img = $pdo->prepare("SELECT imagem FROM loja_xp WHERE id = ?");
            $stmt_img->execute([$item_id]);
            $imagem = $stmt_img->fetchColumn();
            
            if ($imagem && file_exists(__DIR__ . '/../' . $imagem)) {
                unlink(__DIR__ . '/../' . $imagem);
            }
            
            $stmt = $pdo->prepare("DELETE FROM loja_xp WHERE id = ?");
            $stmt->execute([$item_id]);
            
            $_SESSION['success'] = "Item excluído com sucesso!";
            logAcao('item_delete', "Item ID: {$item_id}");
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao excluir item: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['gerar_codigos'])) {
        // Gerar códigos de resgate para estoque
        $item_id = $_POST['item_id'];
        $quantidade = $_POST['quantidade_codigos'];
        $tipo_codigo = $_POST['tipo_codigo'];
        
        try {
            $codigos_gerados = [];
            for ($i = 0; $i < $quantidade; $i++) {
                $codigo = 'AGR' . strtoupper(substr(md5(uniqid()), 0, 10));
                $stmt = $pdo->prepare("INSERT INTO estoque_itens (item_id, codigo_resgate, tipo) VALUES (?, ?, ?)");
                $stmt->execute([$item_id, $codigo, $tipo_codigo]);
                $codigos_gerados[] = $codigo;
            }
            
            $_SESSION['success'] = "$quantidade códigos gerados com sucesso!";
            logAcao('codigos_add', "{$quantidade} códigos para item ID: {$item_id}");
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao gerar códigos: " . $e->getMessage();
        }
    }
    
    // Redirecionar para evitar reenvio do formulário
    if (isset($_POST['add_item']) || isset($_POST['edit_item']) || isset($_POST['delete_item']) || isset($_POST['gerar_codigos'])) {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ============ BUSCAR DADOS PARA DASHBOARD ============

try {
    // Buscar estatísticas avançadas
    $stmt_stats = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM loja_xp) as total_itens,
            (SELECT COUNT(*) FROM loja_xp WHERE status = 'ativo') as ativos,
            (SELECT COUNT(*) FROM loja_xp WHERE status = 'esgotado') as esgotados,
            (SELECT SUM(custo_xp) FROM trocas_xp WHERE status = 'concluido') as total_xp_gasto,
            (SELECT COUNT(DISTINCT usuario_id) FROM trocas_xp WHERE status = 'concluido') as usuarios_ativos,
            (SELECT COUNT(*) FROM trocas_xp WHERE status = 'pendente') as trocas_pendentes,
            (SELECT COUNT(*) FROM trocas_xp WHERE DATE(data_troca) = CURDATE()) as trocas_hoje,
            (SELECT SUM(estoque) FROM loja_xp WHERE estoque > 0) as total_estoque,
            (SELECT COUNT(*) FROM cupons_desconto WHERE status = 'ativo') as cupons_ativos,
            (SELECT AVG(custo_xp) FROM loja_xp WHERE status = 'ativo') as media_xp_itens
    ");
    $stmt_stats->execute();
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    
    // Buscar notificações de novas trocas
    $stmt_notificacoes = $pdo->prepare("
        SELECT t.*, u.nome as usuario_nome, l.nome as item_nome, l.imagem
        FROM trocas_xp t
        JOIN usuarios u ON t.usuario_id = u.id
        JOIN loja_xp l ON t.item_id = l.id
        WHERE t.status = 'pendente'
        ORDER BY t.data_troca DESC
        LIMIT 10
    ");
    $stmt_notificacoes->execute();
    $notificacoes = $stmt_notificacoes->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar todas as trocas para dashboard
    $stmt_trocas_dash = $pdo->prepare("
        SELECT t.*, u.nome as usuario_nome, u.email, l.nome as item_nome, l.categoria, l.imagem,
               CASE 
                   WHEN t.status = 'pendente' THEN 1
                   WHEN t.status = 'processando' THEN 2
                   WHEN t.status = 'separacao' THEN 3
                   WHEN t.status = 'transporte' THEN 4
                   WHEN t.status = 'entrega' THEN 5
                   WHEN t.status = 'concluido' THEN 6
                   ELSE 7
               END as status_order
        FROM trocas_xp t
        JOIN usuarios u ON t.usuario_id = u.id
        JOIN loja_xp l ON t.item_id = l.id
        ORDER BY status_order, t.data_troca DESC
        LIMIT 50
    ");
    $stmt_trocas_dash->execute();
    $trocas_dash = $stmt_trocas_dash->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar todos os itens
    $stmt = $pdo->prepare("SELECT * FROM loja_xp ORDER BY status, custo_xp ASC");
    $stmt->execute();
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar logs recentes
    $stmt_logs = $pdo->prepare("
        SELECT l.*, u.nome as admin_nome 
        FROM logs_admin l
        LEFT JOIN usuarios u ON l.usuario_id = u.id
        WHERE u.id = ? OR ? = 'cia_dev'
        ORDER BY l.data_hora DESC
        LIMIT 10
    ");
    $stmt_logs->execute([$_SESSION['usuario_id'], $_SESSION['usuario_tipo']]);
    $logs_recentes = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar cupons ativos
    $stmt_cupons = $pdo->prepare("
        SELECT * FROM cupons_desconto 
        WHERE validade >= CURDATE() OR validade IS NULL
        ORDER BY criado_em DESC
    ");
    $stmt_cupons->execute();
    $cupons = $stmt_cupons->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar configurações do sistema
    $stmt_config = $pdo->prepare("SELECT * FROM config_sistema");
    $stmt_config->execute();
    $configs_raw = $stmt_config->fetchAll(PDO::FETCH_ASSOC);
    
    $configs = [];
    foreach ($configs_raw as $config) {
        $configs[$config['chave']] = $config['valor'];
    }
    
    // Buscar backups disponíveis
    $backup_dir = __DIR__ . '/../backups/';
    $backups = [];
    if (is_dir($backup_dir)) {
        $files = scandir($backup_dir);
        foreach ($files as $file) {
            if (preg_match('/backup_.*\.sql$/', $file)) {
                $backups[] = [
                    'nome' => $file,
                    'tamanho' => filesize($backup_dir . $file),
                    'data' => filemtime($backup_dir . $file)
                ];
            }
        }
        usort($backups, function($a, $b) {
            return $b['data'] - $a['data'];
        });
    }
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Erro ao carregar dados: " . $e->getMessage();
    $itens = [];
    $stats = [];
    $notificacoes = [];
    $trocas_dash = [];
    $logs_recentes = [];
    $cupons = [];
    $configs = [];
    $backups = [];
}

// Buscar item para edição
$item_edit = null;
$item_id = $_GET['id'] ?? 0;
$action = $_GET['action'] ?? '';

if ($item_id && $action === 'edit') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM loja_xp WHERE id = ?");
        $stmt->execute([$item_id]);
        $item_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao carregar item: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Loja XP - AgroDash</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #7c3aed;
            --primary-dark: #5b21b6;
            --primary-light: #8b5cf6;
            --secondary: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #f1f5f9;
            --border: #e2e8f0;
            --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius: 12px;
            --transition: all 0.3s ease;
        }

        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }

        body { 
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--dark);
        }

        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-right: 1px solid var(--border);
            padding: 25px 0;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            position: fixed;
            height: 100vh;
            z-index: 100;
        }

        .sidebar-header {
            padding: 0 25px 25px;
            border-bottom: 1px solid var(--border);
        }

        .sidebar-header h2 {
            color: var(--primary);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-menu {
            padding: 25px 0;
            flex-grow: 1;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 25px;
            color: var(--gray);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            cursor: pointer;
            position: relative;
        }

        .nav-item:hover, .nav-item.active {
            background: var(--gray-light);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .nav-item i {
            width: 20px;
            text-align: center;
        }

        .nav-badge {
            position: absolute;
            right: 20px;
            background: var(--danger);
            color: white;
            border-radius: 10px;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 600;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            background: var(--gray-light);
            min-height: 100vh;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .notifications {
            position: relative;
        }

        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
        }

        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 400px;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            display: none;
            z-index: 1000;
        }

        .notifications:hover .notification-dropdown {
            display: block;
            animation: fadeInDown 0.3s;
        }

        .notification-item {
            padding: 15px;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 12px;
            align-items: center;
            cursor: pointer;
        }

        .notification-item.unread {
            background: #f0f9ff;
        }

        .notification-item:hover {
            background: var(--gray-light);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border-top: 4px solid var(--primary);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card.danger { border-color: var(--danger); }
        .stat-card.success { border-color: var(--secondary); }
        .stat-card.warning { border-color: var(--warning); }
        .stat-card.info { border-color: var(--info); }

        .stat-card h3 {
            font-size: 14px;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .stat-change {
            font-size: 14px;
            color: var(--gray);
        }

        .card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }

        .card-body {
            padding: 20px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: var(--gray-light);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--border);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table td {
            padding: 15px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .table tr:hover {
            background: var(--gray-light);
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-processing { background: #dbeafe; color: #1e40af; }
        .badge-separacao { background: #f3e8ff; color: #5b21b6; }
        .badge-transporte { background: #f0f9ff; color: #0c4a6e; }
        .badge-entrega { background: #f0fdf4; color: #166534; }
        .badge-concluido { background: #dcfce7; color: #166534; }
        .badge-cancelado { background: #fee2e2; color: #991b1b; }
        .badge-ativo { background: #dcfce7; color: #166534; }
        .badge-inativo { background: #f1f5f9; color: #64748b; }
        .badge-esgotado { background: #fef3c7; color: #92400e; }

        .status-selector {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .status-option {
            padding: 8px 16px;
            border: 2px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: var(--transition);
        }

        .status-option:hover, .status-option.active {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            justify-content: center;
            border-radius: 50%;
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .modal.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius);
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: var(--transition);
        }

        .modal.active .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .item-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid var(--border);
        }

        .chart-container {
            height: 300px;
            margin-top: 20px;
        }

        .progress-bar {
            height: 6px;
            background: var(--border);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            transition: width 0.3s ease;
        }

        /* NOVOS ESTILOS */
        .batch-actions {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .batch-actions.active {
            display: flex;
        }

        .select-all-checkbox {
            margin-right: 10px;
        }

        .multi-select {
            width: 200px;
        }

        .activity-feed {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            padding: 10px;
            border-bottom: 1px solid #f1f1f1;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .activity-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }

        .activity-icon.item_add { background: #28a745; }
        .activity-icon.item_edit { background: #ffc107; }
        .activity-icon.item_delete { background: #dc3545; }
        .activity-icon.troca_status { background: #17a2b8; }
        .activity-icon.batch_ativar { background: #28a745; }
        .activity-icon.batch_inativar { background: #ffc107; }
        .activity-icon.batch_delete { background: #dc3545; }
        .activity-icon.cupom_add { background: #6610f2; }
        .activity-icon.config_update { background: #20c997; }

        .config-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background-color: #28a745;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }

        .import-box {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
        }

        .import-box:hover {
            border-color: #007bff;
            background: #e9ecef;
        }

        .export-options {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .export-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
        }

        .export-btn:hover {
            background: #f8f9fa;
            border-color: #007bff;
        }

        .dashboard-widget {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            height: 100%;
        }

        .widget-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .widget-title {
            font-size: 16px;
            font-weight: 600;
            color: #495057;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
        }

        .quick-action {
            text-align: center;
            padding: 15px 10px;
            border-radius: 8px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            cursor: pointer;
            transition: all 0.3s;
        }

        .quick-action:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .quick-action i {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .tag-cloud {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .tag {
            background: #e9ecef;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            color: #495057;
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 10px 0;
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 1024px) {
            .sidebar {
                width: 80px;
            }
            
            .sidebar-header h2 span,
            .nav-item span {
                display: none;
            }
            
            .nav-badge {
                display: none;
            }
            
            .main-content {
                margin-left: 80px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .top-bar {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .card-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .table th, .table td {
                padding: 10px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-store"></i> <span>Loja XP Admin</span></h2>
                <small style="color: var(--gray); margin-top: 5px;">v2.0</small>
            </div>
            
            <nav class="nav-menu">
                <div class="nav-item active" onclick="showSection('dashboard')">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </div>
                <div class="nav-item" onclick="showSection('itens')">
                    <i class="fas fa-box"></i>
                    <span>Itens da Loja</span>
                    <?php if (($stats['ativos'] ?? 0) > 0): ?>
                        <span class="nav-badge" style="background: var(--secondary);"><?php echo $stats['ativos']; ?></span>
                    <?php endif; ?>
                </div>
                <div class="nav-item" onclick="showSection('trocas')">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Trocas</span>
                    <?php if (($stats['trocas_pendentes'] ?? 0) > 0): ?>
                        <span class="nav-badge"><?php echo $stats['trocas_pendentes']; ?></span>
                    <?php endif; ?>
                </div>
                <div class="nav-item" onclick="showSection('estoque')">
                    <i class="fas fa-warehouse"></i>
                    <span>Estoque & Códigos</span>
                </div>
                <div class="nav-item" onclick="showSection('cupons')">
                    <i class="fas fa-tag"></i>
                    <span>Cupons</span>
                    <?php if (($stats['cupons_ativos'] ?? 0) > 0): ?>
                        <span class="nav-badge" style="background: var(--info);"><?php echo $stats['cupons_ativos']; ?></span>
                    <?php endif; ?>
                </div>
                <div class="nav-item" onclick="showSection('relatorios')">
                    <i class="fas fa-chart-bar"></i>
                    <span>Relatórios</span>
                </div>
                <div class="nav-item" onclick="showSection('logs')">
                    <i class="fas fa-history"></i>
                    <span>Logs</span>
                </div>
                <div class="nav-item" onclick="showSection('config')">
                    <i class="fas fa-cog"></i>
                    <span>Configurações</span>
                </div>
                <div class="nav-item" onclick="showSection('backup')">
                    <i class="fas fa-database"></i>
                    <span>Backup</span>
                </div>
            </nav>
            
            <div style="padding: 25px; margin-top: auto;">
                <div class="dashboard-widget" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin: 0;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h3 style="font-size: 14px; margin-bottom: 0;">Status Sistema</h3>
                            <span class="badge badge-ativo" style="font-size: 10px; background: rgba(255,255,255,0.2);">
                                <?php echo ($configs['manutencao'] ?? 0) ? 'MANUTENÇÃO' : 'ONLINE'; ?>
                            </span>
                        </div>
                        <div style="font-size: 24px; font-weight: 700;"><?php echo number_format($stats['total_xp_gasto'] ?? 0); ?> XP</div>
                        <small>Gastos totais</small>
                        <div class="progress-bar" style="margin-top: 10px; background: rgba(255,255,255,0.2);">
                            <div class="progress-fill" style="width: <?php echo min(100, (($stats['trocas_hoje'] ?? 0) / 50) * 100); ?>%; background: white;"></div>
                        </div>
                        <small style="font-size: 11px;"><?php echo $stats['trocas_hoje'] ?? 0; ?> trocas hoje</small>
                    </div>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <button class="btn btn-icon btn-secondary" onclick="toggleSidebar()" style="display: none;">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 id="section-title" style="font-size: 24px; font-weight: 700; color: var(--dark);">
                        Dashboard da Loja XP
                    </h1>
                </div>
                
                <div style="display: flex; gap: 15px; align-items: center;">
                    <!-- Quick Search -->
                    <div style="position: relative;">
                        <input type="text" class="form-control" style="width: 250px; padding-left: 40px;" 
                               placeholder="Buscar itens, usuários..." onkeyup="quickSearch(this.value)">
                        <i class="fas fa-search" style="position: absolute; left: 15px; top: 12px; color: var(--gray);"></i>
                    </div>
                    
                    <!-- Notifications -->
                    <div class="notifications">
                        <button class="btn btn-icon btn-primary">
                            <i class="fas fa-bell"></i>
                            <?php if (count($notificacoes) > 0): ?>
                                <span class="notification-badge"><?php echo count($notificacoes); ?></span>
                            <?php endif; ?>
                        </button>
                        
                        <div class="notification-dropdown">
                            <div class="card-header">
                                <span>Notificações</span>
                                <small><?php echo count($notificacoes); ?> novas</small>
                            </div>
                            <div class="card-body" style="padding: 0;">
                                <?php if (empty($notificacoes)): ?>
                                    <div style="padding: 20px; text-align: center; color: var(--gray);">
                                        <i class="fas fa-bell-slash" style="font-size: 24px; margin-bottom: 10px;"></i>
                                        <p>Nenhuma notificação</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notificacoes as $notif): ?>
                                        <div class="notification-item unread" onclick="openTrocaModal(<?php echo $notif['id']; ?>)">
                                            <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary-light); display: flex; align-items: center; justify-content: center; color: white;">
                                                <i class="fas fa-gift"></i>
                                            </div>
                                            <div style="flex: 1;">
                                                <strong><?php echo htmlspecialchars($notif['usuario_nome']); ?></strong>
                                                <p style="font-size: 13px; color: var(--gray); margin-top: 2px;">
                                                    Solicitou: <?php echo htmlspecialchars($notif['item_nome']); ?>
                                                </p>
                                                <small style="color: var(--gray);"><?php echo date('H:i', strtotime($notif['data_troca'])); ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- User Menu -->
                    <div style="position: relative;">
                        <button class="btn btn-icon btn-primary" onclick="toggleUserMenu()">
                            <i class="fas fa-user"></i>
                        </button>
                        <div id="userMenu" style="display: none; position: absolute; right: 0; top: 100%; background: white; border-radius: 8px; box-shadow: var(--shadow); z-index: 1000; min-width: 200px;">
                            <div style="padding: 15px; border-bottom: 1px solid var(--border);">
                                <strong><?php echo htmlspecialchars($_SESSION['usuario_nome'] ?? 'Admin'); ?></strong><br>
                                <small style="color: var(--gray);"><?php echo $_SESSION['usuario_tipo']; ?></small>
                            </div>
                            <a href="/logout.php" style="display: block; padding: 10px 15px; text-decoration: none; color: var(--danger);"
                               onmouseover="this.style.background='var(--gray-light)'" 
                               onmouseout="this.style.background='white'">
                                <i class="fas fa-sign-out-alt"></i> Sair
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success animate__animated animate__fadeIn">
                    <i class="fas fa-check-circle"></i>
                    <div style="flex: 1;"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                    <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; cursor: pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error animate__animated animate__fadeIn">
                    <i class="fas fa-exclamation-circle"></i>
                    <div style="flex: 1;"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                    <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; cursor: pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <!-- ============ SEÇÕES ============ -->

            <!-- Dashboard Section -->
            <section id="dashboard" class="content-section">
                <div class="stats-grid">
                    <div class="stat-card success">
                        <h3>Total de Itens</h3>
                        <div class="stat-value"><?php echo $stats['total_itens'] ?? 0; ?></div>
                        <div class="stat-change"><?php echo $stats['ativos'] ?? 0; ?> ativos</div>
                        <div class="progress-bar" style="margin-top: 10px;">
                            <div class="progress-fill" style="width: <?php echo ($stats['ativos'] ?? 0) > 0 ? (($stats['ativos'] / max(1, $stats['total_itens'])) * 100) : 0; ?>%;"></div>
                        </div>
                    </div>
                    
                    <div class="stat-card warning">
                        <h3>Trocas Pendentes</h3>
                        <div class="stat-value"><?php echo $stats['trocas_pendentes'] ?? 0; ?></div>
                        <div class="stat-change"><?php echo $stats['trocas_hoje'] ?? 0; ?> hoje</div>
                    </div>
                    
                    <div class="stat-card info">
                        <h3>Usuários Ativos</h3>
                        <div class="stat-value"><?php echo $stats['usuarios_ativos'] ?? 0; ?></div>
                        <div class="stat-change"><?php echo $stats['total_itens'] ?? 0; ?> itens</div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>XP Total Gastos</h3>
                        <div class="stat-value"><?php echo number_format($stats['total_xp_gasto'] ?? 0); ?></div>
                        <div class="stat-change">Média: <?php echo number_format($stats['media_xp_itens'] ?? 0); ?> XP/item</div>
                    </div>
                </div>

                <!-- Widgets -->
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px;">
                    <div class="dashboard-widget">
                        <div class="widget-header">
                            <div class="widget-title">Ações Rápidas</div>
                        </div>
                        <div class="quick-actions">
                            <div class="quick-action" onclick="openItemModal()">
                                <i class="fas fa-plus-circle"></i>
                                <div>Novo Item</div>
                            </div>
                            <div class="quick-action" onclick="openCodigosModal()">
                                <i class="fas fa-key"></i>
                                <div>Gerar Códigos</div>
                            </div>
                            <div class="quick-action" onclick="openCupomModal()">
                                <i class="fas fa-tag"></i>
                                <div>Novo Cupom</div>
                            </div>
                            <div class="quick-action" onclick="exportData('csv')">
                                <i class="fas fa-file-export"></i>
                                <div>Exportar</div>
                            </div>
                            <div class="quick-action" onclick="showSection('config')">
                                <i class="fas fa-cog"></i>
                                <div>Configurações</div>
                            </div>
                            <div class="quick-action" onclick="window.print()">
                                <i class="fas fa-print"></i>
                                <div>Imprimir</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="dashboard-widget">
                        <div class="widget-header">
                            <div class="widget-title">Atividade Recente</div>
                            <button class="btn btn-sm btn-secondary" onclick="showSection('logs')">Ver todos</button>
                        </div>
                        <div class="activity-feed" style="max-height: 200px;">
                            <?php if (empty($logs_recentes)): ?>
                                <div style="text-align: center; padding: 20px; color: var(--gray);">
                                    Nenhuma atividade recente
                                </div>
                            <?php else: ?>
                                <?php foreach (array_slice($logs_recentes, 0, 5) as $log): ?>
                                    <div class="activity-item" style="padding: 8px 0;">
                                        <div class="activity-icon <?php echo $log['acao']; ?>" style="width: 20px; height: 20px; font-size: 10px;">
                                            <i class="fas fa-<?php echo strpos($log['acao'], 'item') !== false ? 'box' : (strpos($log['acao'], 'cupom') !== false ? 'tag' : 'info-circle'); ?>"></i>
                                        </div>
                                        <div style="flex: 1; overflow: hidden;">
                                            <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 12px;">
                                                <?php echo htmlspecialchars(substr($log['detalhes'] ?? '', 0, 50)); ?>...
                                            </div>
                                            <small style="color: var(--gray); font-size: 10px;">
                                                <?php echo date('H:i', strtotime($log['data_hora'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Trocas Recentes -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Trocas Recentes</div>
                        <div class="status-selector" id="statusFilter">
                            <span class="status-option active" onclick="filterTrocas('all')">Todas</span>
                            <span class="status-option" onclick="filterTrocas('pendente')">Pendente</span>
                            <span class="status-option" onclick="filterTrocas('processando')">Processando</span>
                            <span class="status-option" onclick="filterTrocas('concluido')">Concluído</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Usuário</th>
                                        <th>Item</th>
                                        <th>XP</th>
                                        <th>Data</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="trocasTable">
                                    <?php foreach ($trocas_dash as $troca): ?>
                                    <tr class="troca-row" data-status="<?php echo $troca['status']; ?>">
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary-light); display: flex; align-items: center; justify-content: center; color: white;">
                                                    <?php echo strtoupper(substr($troca['usuario_nome'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($troca['usuario_nome']); ?></div>
                                                    <small style="color: var(--gray); font-size: 12px;"><?php echo $troca['email']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <?php if ($troca['imagem']): ?>
                                                    <img src="../<?php echo $troca['imagem']; ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px;">
                                                <?php endif; ?>
                                                <div>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($troca['item_nome']); ?></div>
                                                    <small style="color: var(--gray); font-size: 12px;"><?php echo $troca['categoria']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><strong style="color: var(--primary);"><?php echo number_format($troca['custo_xp']); ?> XP</strong></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($troca['data_troca'])); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $troca['status']; ?>">
                                                <?php 
                                                $status_names = [
                                                    'pendente' => 'Pendente',
                                                    'processando' => 'Processando',
                                                    'separacao' => 'Separação',
                                                    'transporte' => 'Transporte',
                                                    'entrega' => 'Em Entrega',
                                                    'concluido' => 'Concluído',
                                                    'cancelado' => 'Cancelado'
                                                ];
                                                echo $status_names[$troca['status']] ?? ucfirst($troca['status']);
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="openTrocaModal(<?php echo $troca['id']; ?>)">
                                                <i class="fas fa-eye"></i> Ver
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Itens Section -->
            <section id="itens" class="content-section" style="display: none;">
                <!-- Barra de ações em lote -->
                <div class="batch-actions" id="batchActions">
                    <input type="checkbox" class="select-all-checkbox" onclick="toggleSelectAll(this)">
                    <span id="selectedCount">0 itens selecionados</span>
                    
                    <select class="form-control multi-select" id="batchActionSelect" style="width: 200px;">
                        <option value="">Ação em lote...</option>
                        <option value="ativar">Ativar selecionados</option>
                        <option value="inativar">Inativar selecionados</option>
                        <option value="esgotar">Marcar como esgotado</option>
                        <option value="delete">Excluir selecionados</option>
                        <option value="update_estoque">Atualizar estoque</option>
                    </select>
                    
                    <input type="number" id="batchEstoqueInput" placeholder="Novo estoque" 
                           style="display: none; width: 150px;" class="form-control">
                    
                    <button class="btn btn-primary btn-sm" onclick="executeBatchAction()">Executar</button>
                    <button class="btn btn-secondary btn-sm" onclick="clearSelection()">Limpar</button>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Gerenciar Itens da Loja</div>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn btn-primary" onclick="openItemModal()">
                                <i class="fas fa-plus"></i> Novo Item
                            </button>
                            <button class="btn btn-success" onclick="importItens()">
                                <i class="fas fa-file-import"></i> Importar
                            </button>
                            <div class="export-options">
                                <div class="export-btn" onclick="exportData('csv')">
                                    <i class="fas fa-file-csv"></i> CSV
                                </div>
                                <div class="export-btn" onclick="exportData('excel')">
                                    <i class="fas fa-file-excel"></i> Excel
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Filtros -->
                        <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
                            <input type="text" class="form-control" placeholder="Buscar item..." 
                                   style="width: 200px;" onkeyup="filterItens(this.value)">
                            <select class="form-control" style="width: 150px;" onchange="filterByCategory(this.value)">
                                <option value="">Todas categorias</option>
                                <option value="digital">Digital</option>
                                <option value="fisico">Físico</option>
                                <option value="curso">Curso</option>
                                <option value="certificado">Certificado</option>
                                <option value="brinde">Brinde</option>
                            </select>
                            <select class="form-control" style="width: 150px;" onchange="filterByStatus(this.value)">
                                <option value="">Todos status</option>
                                <option value="ativo">Ativo</option>
                                <option value="inativo">Inativo</option>
                                <option value="esgotado">Esgotado</option>
                            </select>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table" id="itensTable">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <input type="checkbox" onclick="toggleSelectAllTable(this)">
                                        </th>
                                        <th>Imagem</th>
                                        <th>Nome</th>
                                        <th>Categoria</th>
                                        <th>Custo XP</th>
                                        <th>Estoque</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($itens as $item): ?>
                                    <tr id="item-<?php echo $item['id']; ?>">
                                        <td>
                                            <input type="checkbox" value="<?php echo $item['id']; ?>" 
                                                   onchange="toggleItemSelection(this, <?php echo $item['id']; ?>)">
                                        </td>
                                        <td>
                                            <?php if ($item['imagem']): ?>
                                                <img src="../<?php echo $item['imagem']; ?>" class="item-image">
                                            <?php else: ?>
                                                <div style="width: 60px; height: 60px; background: var(--gray-light); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-image" style="color: var(--gray);"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($item['nome']); ?></div>
                                            <small style="color: var(--gray);"><?php echo substr($item['descricao'], 0, 50); ?>...</small>
                                        </td>
                                        <td>
                                            <span class="badge" style="background: var(--gray-light); color: var(--dark);">
                                                <?php echo htmlspecialchars($item['categoria']); ?>
                                            </span>
                                        </td>
                                        <td><strong style="color: var(--primary);"><?php echo number_format($item['custo_xp']); ?> XP</strong></td>
                                        <td>
                                            <?php if ($item['estoque'] == -1): ?>
                                                <span style="color: var(--secondary);">Ilimitado</span>
                                            <?php else: ?>
                                                <div>
                                                    <?php echo $item['estoque']; ?> unidades
                                                    <?php if ($item['estoque'] < 10 && $item['estoque'] > 0): ?>
                                                        <div class="progress-bar">
                                                            <div class="progress-fill" style="width: <?php echo ($item['estoque'] / 10) * 100; ?>%; background: var(--warning);"></div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $item['status']; ?>">
                                                <?php echo ucfirst($item['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 5px;">
                                                <button class="btn btn-sm btn-warning" onclick="editItem(<?php echo $item['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['nome']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Trocas Section -->
            <section id="trocas" class="content-section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Histórico de Trocas</div>
                        <div style="display: flex; gap: 10px;">
                            <input type="date" class="form-control" style="width: auto;" onchange="filterByDate(this.value)">
                            <input type="text" class="form-control" style="width: 200px;" placeholder="Buscar usuário ou item..." onkeyup="searchTrocas(this.value)">
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="trocasList">
                            <?php if (empty($trocas_dash)): ?>
                                <div style="text-align: center; padding: 40px; color: var(--gray);">
                                    <i class="fas fa-exchange-alt" style="font-size: 48px; margin-bottom: 20px;"></i>
                                    <p>Nenhuma troca encontrada</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Usuário</th>
                                                <th>Item</th>
                                                <th>XP</th>
                                                <th>Data</th>
                                                <th>Status</th>
                                                <th>Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($trocas_dash as $troca): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($troca['usuario_nome']); ?></td>
                                                <td><?php echo htmlspecialchars($troca['item_nome']); ?></td>
                                                <td><strong><?php echo number_format($troca['custo_xp']); ?> XP</strong></td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($troca['data_troca'])); ?></td>
                                                <td><span class="badge badge-<?php echo $troca['status']; ?>"><?php echo $troca['status']; ?></span></td>
                                                <td><button class="btn btn-sm btn-primary" onclick="openTrocaModal(<?php echo $troca['id']; ?>)">Ver</button></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Estoque Section -->
            <section id="estoque" class="content-section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Gerenciar Estoque e Códigos</div>
                        <button class="btn btn-success" onclick="openCodigosModal()">
                            <i class="fas fa-key"></i> Gerar Códigos
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Estoque Atual</th>
                                        <th>Códigos Gerados</th>
                                        <th>Códigos Usados</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($itens as $item): 
                                        try {
                                            $stmt_total = $pdo->prepare("SELECT COUNT(*) as total FROM estoque_itens WHERE item_id = ?");
                                            $stmt_total->execute([$item['id']]);
                                            $total_codigos = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];
                                            
                                            $stmt_used = $pdo->prepare("SELECT COUNT(*) as total FROM estoque_itens WHERE item_id = ? AND status = 'resgatado'");
                                            $stmt_used->execute([$item['id']]);
                                            $used_codigos = $stmt_used->fetch(PDO::FETCH_ASSOC)['total'];
                                        } catch (PDOException $e) {
                                            $total_codigos = $used_codigos = 0;
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($item['nome']); ?></div>
                                            <small style="color: var(--gray);">Categoria: <?php echo $item['categoria']; ?></small>
                                        </td>
                                        <td>
                                            <?php if ($item['estoque'] == -1): ?>
                                                <span style="color: var(--secondary); font-weight: 600;">Ilimitado</span>
                                            <?php else: ?>
                                                <div>
                                                    <span style="font-weight: 600;"><?php echo $item['estoque']; ?></span> unidades
                                                    <div class="progress-bar">
                                                        <div class="progress-fill" style="width: <?php echo min(100, ($item['estoque'] / 50) * 100); ?>%;"></div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; color: var(--primary);"><?php echo $total_codigos; ?></span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; color: var(--<?php echo $used_codigos > 0 ? 'secondary' : 'gray'; ?>);">
                                                <?php echo $used_codigos; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="viewCodigos(<?php echo $item['id']; ?>)">
                                                <i class="fas fa-eye"></i> Ver Códigos
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Cupons Section -->
            <section id="cupons" class="content-section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Gerenciar Cupons de Desconto</div>
                        <button class="btn btn-success" onclick="openCupomModal()">
                            <i class="fas fa-plus"></i> Novo Cupom
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Código</th>
                                        <th>Tipo</th>
                                        <th>Valor</th>
                                        <th>Validade</th>
                                        <th>Usos</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cupons as $cupom): ?>
                                    <tr>
                                        <td><strong><?php echo $cupom['codigo']; ?></strong></td>
                                        <td>
                                            <span class="badge badge-info">
                                                <?php echo $cupom['tipo'] == 'percentual' ? '%' : 'Fixo'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $cupom['tipo'] == 'percentual' ? $cupom['valor'] . '%' : $cupom['valor'] . ' XP'; ?>
                                        </td>
                                        <td>
                                            <?php echo $cupom['validade'] ? date('d/m/Y', strtotime($cupom['validade'])) : 'Ilimitada'; ?>
                                            <?php if ($cupom['validade'] && strtotime($cupom['validade']) < time()): ?>
                                                <span class="badge badge-danger" style="margin-left: 5px;">Expirado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo $cupom['usos_atual'] . '/' . $cupom['usos_maximos']; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $cupom['status']; ?>">
                                                <?php echo ucfirst($cupom['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-warning" onclick="editCupom(<?php echo $cupom['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteCupom(<?php echo $cupom['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Relatórios Section -->
            <section id="relatorios" class="content-section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Relatórios e Análises</div>
                        <div style="display: flex; gap: 10px;">
                            <select class="form-control" style="width: auto;" id="periodoRelatorio">
                                <option value="7">Últimos 7 dias</option>
                                <option value="30">Últimos 30 dias</option>
                                <option value="90">Últimos 90 dias</option>
                                <option value="365">Este ano</option>
                            </select>
                            <button class="btn btn-primary" onclick="gerarRelatorio()">
                                <i class="fas fa-download"></i> Exportar
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Logs Section -->
            <section id="logs" class="content-section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Logs de Atividade do Sistema</div>
                        <div>
                            <input type="date" class="form-control" style="width: auto;" id="filterDate">
                            <select class="form-control" style="width: auto;" id="filterUser">
                                <option value="">Todos os usuários</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="activity-feed">
                            <?php if (empty($logs_recentes)): ?>
                                <div style="text-align: center; padding: 40px; color: var(--gray);">
                                    <i class="fas fa-history" style="font-size: 48px; margin-bottom: 20px;"></i>
                                    <p>Nenhum log encontrado</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($logs_recentes as $log): ?>
                                <div class="activity-item">
                                    <div class="activity-icon <?php echo $log['acao']; ?>">
                                        <?php 
                                        $icons = [
                                            'item_add' => 'fas fa-plus',
                                            'item_edit' => 'fas fa-edit',
                                            'item_delete' => 'fas fa-trash',
                                            'troca_status' => 'fas fa-exchange-alt',
                                            'cupom_add' => 'fas fa-tag',
                                            'config_update' => 'fas fa-cog'
                                        ];
                                        echo '<i class="' . ($icons[$log['acao']] ?? 'fas fa-info-circle') . '"></i>';
                                        ?>
                                    </div>
                                    <div style="flex: 1;">
                                        <div>
                                            <strong><?php echo $log['admin_nome'] ?? 'Sistema'; ?></strong>
                                            <small style="color: var(--gray); margin-left: 10px;">
                                                <?php echo date('d/m/Y H:i', strtotime($log['data_hora'])); ?>
                                            </small>
                                        </div>
                                        <div><?php echo htmlspecialchars($log['detalhes']); ?></div>
                                        <small style="color: var(--gray);">IP: <?php echo $log['ip_address']; ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Configurações Section -->
            <section id="config" class="content-section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Configurações do Sistema</div>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="configForm">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                                <div>
                                    <h3 style="margin-bottom: 20px; color: var(--primary);">Configurações Gerais</h3>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Nome da Loja</label>
                                        <input type="text" class="form-control" name="loja_nome" 
                                               value="<?php echo htmlspecialchars($configs['loja_nome'] ?? 'AgroDash Loja XP'); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Descrição da Loja</label>
                                        <textarea class="form-control" name="loja_descricao" rows="3"><?php echo htmlspecialchars($configs['loja_descricao'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">XP Mínimo para Troca</label>
                                        <input type="number" class="form-control" name="xp_minimo_troca" 
                                               value="<?php echo $configs['xp_minimo_troca'] ?? 1000; ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Limite de Trocas Diárias por Usuário</label>
                                        <input type="number" class="form-control" name="limite_trocas_diarias" 
                                               value="<?php echo $configs['limite_trocas_diarias'] ?? 3; ?>">
                                    </div>
                                </div>
                                
                                <div>
                                    <h3 style="margin-bottom: 20px; color: var(--primary);">Configurações Avançadas</h3>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Email para Notificações</label>
                                        <input type="email" class="form-control" name="email_notificacoes" 
                                               value="<?php echo htmlspecialchars($configs['email_notificacoes'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Taxa de Serviço (%)</label>
                                        <input type="number" class="form-control" name="taxa_servico" step="0.01" 
                                               value="<?php echo $configs['taxa_servico'] ?? 0; ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Frequência de Backup Automático</label>
                                        <select class="form-control" name="backup_frequency">
                                            <option value="daily" <?php echo ($configs['backup_frequency'] ?? 'weekly') == 'daily' ? 'selected' : ''; ?>>Diário</option>
                                            <option value="weekly" <?php echo ($configs['backup_frequency'] ?? 'weekly') == 'weekly' ? 'selected' : ''; ?>>Semanal</option>
                                            <option value="monthly" <?php echo ($configs['backup_frequency'] ?? 'weekly') == 'monthly' ? 'selected' : ''; ?>>Mensal</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Retenção de Backups (dias)</label>
                                        <input type="number" class="form-control" name="backup_retention" 
                                               value="<?php echo $configs['backup_retention'] ?? 30; ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                            <div class="toggle-switch">
                                                <input type="checkbox" name="manutencao" <?php echo ($configs['manutencao'] ?? 0) ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </div>
                                            <span>Modo Manutenção</span>
                                        </label>
                                        <small style="color: var(--gray);">A loja ficará indisponível para usuários comuns</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="margin-top: 30px; display: flex; gap: 10px;">
                                <button type="submit" name="save_settings" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Salvar Configurações
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="resetConfig()">
                                    <i class="fas fa-undo"></i> Restaurar Padrão
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Backup Section -->
            <section id="backup" class="content-section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Backup do Sistema</div>
                        <form method="POST" style="display: inline;">
                            <button type="submit" name="gerar_backup" class="btn btn-success">
                                <i class="fas fa-database"></i> Gerar Backup Agora
                            </button>
                        </form>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                            <div>
                                <h3 style="margin-bottom: 20px; color: var(--primary);">Backups Disponíveis</h3>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Arquivo</th>
                                                <th>Tamanho</th>
                                                <th>Data</th>
                                                <th>Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($backups as $backup): ?>
                                            <tr>
                                                <td>
                                                    <i class="fas fa-file-archive"></i>
                                                    <?php echo $backup['nome']; ?>
                                                </td>
                                                <td><?php echo formatBytes($backup['tamanho']); ?></td>
                                                <td><?php echo date('d/m/Y H:i', $backup['data']); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-primary" onclick="downloadBackup('<?php echo $backup['nome']; ?>')">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" onclick="deleteBackup('<?php echo $backup['nome']; ?>')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <div>
                                <h3 style="margin-bottom: 20px; color: var(--primary);">Informações do Sistema</h3>
                                
                                <div class="dashboard-widget">
                                    <div class="widget-header">
                                        <div class="widget-title">Status do Sistema</div>
                                    </div>
                                    <div style="padding: 15px;">
                                        <div style="margin-bottom: 10px;">
                                            <strong>Espaço em disco:</strong>
                                            <div class="progress-bar" style="margin-top: 5px;">
                                                <?php
                                                $total_space = disk_total_space(__DIR__);
                                                $free_space = disk_free_space(__DIR__);
                                                $used_percent = (($total_space - $free_space) / $total_space) * 100;
                                                ?>
                                                <div class="progress-fill" style="width: <?php echo $used_percent; ?>%;"></div>
                                            </div>
                                            <small><?php echo formatBytes($total_space - $free_space); ?> de <?php echo formatBytes($total_space); ?> usado</small>
                                        </div>
                                        
                                        <div style="margin-bottom: 10px;">
                                            <strong>Último backup:</strong><br>
                                            <small><?php echo !empty($backups) ? date('d/m/Y H:i', $backups[0]['data']) : 'Nunca'; ?></small>
                                        </div>
                                        
                                        <div>
                                            <strong>Próximo backup automático:</strong><br>
                                            <small>
                                                <?php
                                                $frequency = $configs['backup_frequency'] ?? 'weekly';
                                                $next_date = '';
                                                switch ($frequency) {
                                                    case 'daily': $next_date = date('d/m/Y', strtotime('+1 day')); break;
                                                    case 'weekly': $next_date = date('d/m/Y', strtotime('+1 week')); break;
                                                    case 'monthly': $next_date = date('d/m/Y', strtotime('+1 month')); break;
                                                }
                                                echo $next_date;
                                                ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="margin-top: 30px;">
                                    <h4>Restauração</h4>
                                    <div class="import-box" onclick="document.getElementById('restoreFile').click()">
                                        <i class="fas fa-file-upload" style="font-size: 48px; color: var(--gray); margin-bottom: 15px;"></i>
                                        <div>Clique para selecionar arquivo de backup (.sql)</div>
                                        <small style="color: var(--gray);">A restauração substituirá todos os dados atuais</small>
                                    </div>
                                    <input type="file" id="restoreFile" accept=".sql" style="display: none;" 
                                           onchange="uploadBackup(this)">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- Modal Adicionar/Editar Item -->
    <div id="itemModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Adicionar Novo Item</h3>
                <button class="btn btn-icon btn-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="itemForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" id="itemId" name="item_id">
                    <input type="hidden" id="formAction" name="add_item" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Nome do Item *</label>
                        <input type="text" class="form-control" name="nome" id="itemNome" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Descrição *</label>
                        <textarea class="form-control" name="descricao" id="itemDescricao" rows="3" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Imagem</label>
                        <div style="border: 2px dashed var(--border); border-radius: 8px; padding: 20px; text-align: center; margin-bottom: 10px;">
                            <i class="fas fa-cloud-upload-alt" style="font-size: 24px; color: var(--gray); margin-bottom: 10px;"></i>
                            <div style="margin-bottom: 10px;">Clique para selecionar imagem</div>
                            <input type="file" class="form-control" name="imagem" id="itemImagem" accept="image/*" onchange="previewImage(this)">
                        </div>
                        <div id="imagePreview" style="display: none;">
                            <img id="previewImg" style="max-width: 100px; max-height: 100px; border-radius: 8px; margin-top: 10px;">
                        </div>
                        <input type="hidden" id="imagemAtual" name="imagem_atual">
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label class="form-label">Custo XP *</label>
                            <input type="number" class="form-control" name="custo_xp" id="itemCustoXp" min="0" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Estoque</label>
                            <input type="number" class="form-control" name="estoque" id="itemEstoque" min="-1" value="-1">
                            <small style="color: var(--gray); font-size: 12px;">-1 = estoque ilimitado</small>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label class="form-label">Categoria *</label>
                            <select class="form-control" name="categoria" id="itemCategoria" required>
                                <option value="geral">Geral</option>
                                <option value="digital">Digital</option>
                                <option value="fisico">Físico</option>
                                <option value="curso">Curso</option>
                                <option value="certificado">Certificado</option>
                                <option value="brinde">Brinde</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Tipo de Entrega</label>
                            <select class="form-control" name="tipo_entrega" id="itemTipoEntrega">
                                <option value="digital">Digital (Email)</option>
                                <option value="fisico">Físico (Correios)</option>
                                <option value="retirada">Retirada no Local</option>
                                <option value="codigo">Código de Resgate</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status *</label>
                        <select class="form-control" name="status" id="itemStatus" required>
                            <option value="ativo">Ativo</option>
                            <option value="inativo">Inativo</option>
                            <option value="esgotado">Esgotado</option>
                        </select>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Salvar Item
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Gerenciar Troca -->
    <div id="trocaModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Detalhes da Troca</h3>
                <button class="btn btn-icon btn-secondary" onclick="closeTrocaModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="trocaDetails">
                <!-- Detalhes da troca serão carregados aqui -->
            </div>
        </div>
    </div>

    <!-- Modal Gerar Códigos -->
    <div id="codigosModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Gerar Códigos de Resgate</h3>
                <button class="btn btn-icon btn-secondary" onclick="closeCodigosModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="codigosForm" method="POST">
                    <input type="hidden" id="codigoItemId" name="item_id">
                    <input type="hidden" name="gerar_codigos" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Item</label>
                        <select class="form-control" name="item_id" id="codigosItemSelect" onchange="updateItemInfo(this.value)" required>
                            <option value="">Selecione um item</option>
                            <?php foreach ($itens as $item): ?>
                                <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Tipo de Código</label>
                        <select class="form-control" name="tipo_codigo" required>
                            <option value="unico">Uso Único</option>
                            <option value="multiplo">Uso Múltiplo</option>
                            <option value="temporario">Temporário</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Quantidade *</label>
                        <input type="number" class="form-control" name="quantidade_codigos" min="1" max="1000" value="10" required>
                        <small style="color: var(--gray); font-size: 12px;">Máximo 1000 códigos por vez</small>
                    </div>
                    
                    <div id="itemInfo" class="alert" style="background: var(--gray-light);">
                        Selecione um item para ver as informações
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-key"></i> Gerar Códigos
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeCodigosModal()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Cupons -->
    <div id="cupomModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Novo Cupom de Desconto</h3>
                <button class="btn btn-icon btn-secondary" onclick="closeCupomModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="cupomForm" method="POST">
                    <div class="form-group">
                        <label class="form-label">Código do Cupom *</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" class="form-control" name="codigo_cupom" id="codigoCupom" 
                                   style="text-transform: uppercase;" required>
                            <button type="button" class="btn btn-secondary" onclick="gerarCodigoCupom()">
                                <i class="fas fa-sync-alt"></i> Gerar
                            </button>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label class="form-label">Tipo *</label>
                            <select class="form-control" name="tipo_cupom" required>
                                <option value="percentual">Percentual (%)</option>
                                <option value="fixo">Valor Fixo (XP)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Valor *</label>
                            <input type="number" class="form-control" name="valor_cupom" min="1" step="0.01" required>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label class="form-label">Validade</label>
                            <input type="date" class="form-control" name="validade_cupom">
                            <small style="color: var(--gray);">Deixe em branco para cupom ilimitado</small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Usos Máximos</label>
                            <input type="number" class="form-control" name="usos_maximos" min="1" value="100">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Categorias Aplicáveis</label>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;">
                            <label style="display: flex; align-items: center; gap: 5px;">
                                <input type="checkbox" name="categorias_cupom[]" value="digital">
                                <span>Digital</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 5px;">
                                <input type="checkbox" name="categorias_cupom[]" value="fisico">
                                <span>Físico</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 5px;">
                                <input type="checkbox" name="categorias_cupom[]" value="curso">
                                <span>Curso</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 5px;">
                                <input type="checkbox" name="categorias_cupom[]" value="certificado">
                                <span>Certificado</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 5px;">
                                <input type="checkbox" name="categorias_cupom[]" value="brinde">
                                <span>Brinde</span>
                            </label>
                        </div>
                        <small style="color: var(--gray);">Deixe vazio para aplicar a todas as categorias</small>
                    </div>
                    
                    <div style="margin-top: 30px; display: flex; gap: 10px;">
                        <button type="submit" name="add_cupom" class="btn btn-success">
                            <i class="fas fa-save"></i> Criar Cupom
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeCupomModal()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // ============ VARIÁVEIS GLOBAIS ============
        let selectedItems = new Set();
        let currentPage = 1;
        const itemsPerPage = 10;
        
        // ============ FUNÇÕES DE SELEÇÃO ============
        function toggleSelectAll(checkbox) {
            const tableCheckboxes = document.querySelectorAll('#itensTable tbody input[type="checkbox"]');
            tableCheckboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                if (checkbox.checked) {
                    selectedItems.add(cb.value);
                } else {
                    selectedItems.delete(cb.value);
                }
            });
            updateBatchActions();
        }
        
        function toggleSelectAllTable(checkbox) {
            toggleSelectAll(checkbox);
        }
        
        function toggleItemSelection(checkbox, itemId) {
            if (checkbox.checked) {
                selectedItems.add(itemId.toString());
            } else {
                selectedItems.delete(itemId.toString());
            }
            updateBatchActions();
        }
        
        function updateBatchActions() {
            const count = selectedItems.size;
            const batchActionsDiv = document.getElementById('batchActions');
            
            if (count > 0) {
                batchActionsDiv.classList.add('active');
                document.getElementById('selectedCount').textContent = count + ' itens selecionados';
            } else {
                batchActionsDiv.classList.remove('active');
            }
        }
        
        function clearSelection() {
            selectedItems.clear();
            document.querySelectorAll('#itensTable tbody input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            updateBatchActions();
        }
        
        function executeBatchAction() {
            const actionType = document.getElementById('batchActionSelect').value;
            if (!actionType) {
                alert('Selecione uma ação');
                return;
            }
            
            if (selectedItems.size === 0) {
                alert('Selecione pelo menos um item');
                return;
            }
            
            if (actionType === 'update_estoque') {
                const estoque = document.getElementById('batchEstoqueInput').value;
                if (!estoque) {
                    alert('Informe o novo estoque');
                    return;
                }
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            selectedItems.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_items[]';
                input.value = id;
                form.appendChild(input);
            });
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'batch_action_type';
            actionInput.value = actionType;
            form.appendChild(actionInput);
            
            if (actionType === 'update_estoque') {
                const estoqueInput = document.createElement('input');
                estoqueInput.type = 'hidden';
                estoqueInput.name = 'batch_estoque';
                estoqueInput.value = document.getElementById('batchEstoqueInput').value;
                form.appendChild(estoqueInput);
            }
            
            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'batch_action';
            submitInput.value = '1';
            form.appendChild(submitInput);
            
            document.body.appendChild(form);
            
            if (actionType === 'delete' && !confirm(`Tem certeza que deseja excluir ${selectedItems.size} itens?`)) {
                form.remove();
                return;
            }
            
            form.submit();
        }
        
        // ============ FUNÇÕES DE MODAIS ============
        function openItemModal(editId = null) {
            const modal = document.getElementById('itemModal');
            const form = document.getElementById('itemForm');
            
            if (editId) {
                // Carregar dados do item para edição via AJAX
                fetch(`ajax/get_item.php?id=${editId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('modalTitle').textContent = 'Editar Item';
                            document.getElementById('itemId').value = data.id;
                            document.getElementById('itemNome').value = data.nome;
                            document.getElementById('itemDescricao').value = data.descricao;
                            document.getElementById('itemCustoXp').value = data.custo_xp;
                            document.getElementById('itemEstoque').value = data.estoque;
                            document.getElementById('itemCategoria').value = data.categoria;
                            document.getElementById('itemTipoEntrega').value = data.tipo_entrega || 'digital';
                            document.getElementById('itemStatus').value = data.status;
                            
                            // Alterar o formulário para edição
                            const actionInput = document.getElementById('formAction');
                            actionInput.name = 'edit_item';
                            actionInput.value = '1';
                            
                            document.getElementById('imagemAtual').value = data.imagem || '';
                            
                            if (data.imagem) {
                                document.getElementById('imagePreview').style.display = 'block';
                                document.getElementById('previewImg').src = '../' + data.imagem;
                            }
                        } else {
                            alert('Erro ao carregar item');
                        }
                    })
                    .catch(error => {
                        console.error('Erro:', error);
                        alert('Erro ao carregar item');
                    });
            } else {
                // Novo item
                document.getElementById('modalTitle').textContent = 'Adicionar Novo Item';
                form.reset();
                document.getElementById('itemId').value = '';
                document.getElementById('formAction').name = 'add_item';
                document.getElementById('formAction').value = '1';
                document.getElementById('imagePreview').style.display = 'none';
            }
            
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function editItem(id) {
            openItemModal(id);
        }
        
        function closeModal() {
            document.getElementById('itemModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        function closeCodigosModal() {
            document.getElementById('codigosModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        function openCodigosModal(itemId = null) {
            const modal = document.getElementById('codigosModal');
            if (itemId) {
                document.getElementById('codigosItemSelect').value = itemId;
                updateItemInfo(itemId);
            }
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function updateItemInfo(itemId) {
            if (!itemId) return;
            
            fetch(`ajax/get_item_info.php?id=${itemId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('itemInfo').innerHTML = `
                            <strong>${data.nome}</strong><br>
                            Estoque atual: ${data.estoque == -1 ? 'Ilimitado' : data.estoque + ' unidades'}<br>
                            Custo: ${data.custo_xp} XP<br>
                            Status: ${data.status}
                        `;
                        document.getElementById('codigoItemId').value = itemId;
                    }
                });
        }
        
        function openCupomModal() {
            document.getElementById('cupomModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeCupomModal() {
            document.getElementById('cupomModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        // ============ FUNÇÕES DE CRUD ============
        function deleteItem(id, nome) {
            if (confirm(`Tem certeza que deseja excluir o item "${nome}"?\nEsta ação não pode ser desfeita.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'item_id';
                inputId.value = id;
                
                const inputAction = document.createElement('input');
                inputAction.type = 'hidden';
                inputAction.name = 'delete_item';
                inputAction.value = '1';
                
                form.appendChild(inputId);
                form.appendChild(inputAction);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function deleteCupom(id) {
            if (confirm('Tem certeza que deseja excluir este cupom?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'cupom_id';
                inputId.value = id;
                
                const inputAction = document.createElement('input');
                inputAction.type = 'hidden';
                inputAction.name = 'delete_cupom';
                inputAction.value = '1';
                
                form.appendChild(inputId);
                form.appendChild(inputAction);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function editCupom(id) {
            // Implementar edição de cupom via AJAX
            fetch(`ajax/get_cupom.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('codigoCupom').value = data.codigo;
                        // ... preencher outros campos
                        openCupomModal();
                    }
                });
        }
        
        // ============ FUNÇÕES DE FILTRO ============
        function filterTrocas(status) {
            const rows = document.querySelectorAll('.troca-row');
            const filterOptions = document.querySelectorAll('.status-option');
            
            filterOptions.forEach(option => option.classList.remove('active'));
            event.target.classList.add('active');
            
            rows.forEach(row => {
                if (status === 'all' || row.dataset.status === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function filterItens(query) {
            const rows = document.querySelectorAll('#itensTable tbody tr');
            rows.forEach(row => {
                const nome = row.cells[2].textContent.toLowerCase();
                if (nome.includes(query.toLowerCase())) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function filterByCategory(category) {
            const rows = document.querySelectorAll('#itensTable tbody tr');
            rows.forEach(row => {
                const cat = row.cells[3].textContent.toLowerCase();
                if (!category || cat === category.toLowerCase()) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function filterByStatus(status) {
            const rows = document.querySelectorAll('#itensTable tbody tr');
            rows.forEach(row => {
                const stat = row.cells[5].textContent.toLowerCase();
                if (!status || stat === status.toLowerCase()) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        // ============ FUNÇÕES DE TROCAS ============
        function openTrocaModal(trocaId) {
            fetch(`ajax/get_troca_details.php?id=${trocaId}`)
                .then(response => response.json())
                .then(troca => {
                    const modal = document.getElementById('trocaModal');
                    const detailsDiv = document.getElementById('trocaDetails');
                    
                    let statusOptions = '';
                    const statuses = {
                        'pendente': 'Pendente',
                        'processando': 'Processando',
                        'separacao': 'Em Separação',
                        'transporte': 'Em Transporte',
                        'entrega': 'Em Entrega',
                        'concluido': 'Concluído',
                        'cancelado': 'Cancelado'
                    };
                    
                    for (const [value, label] of Object.entries(statuses)) {
                        statusOptions += `<option value="${value}" ${troca.status === value ? 'selected' : ''}>${label}</option>`;
                    }
                    
                    detailsDiv.innerHTML = `
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                            <div>
                                <strong>Usuário:</strong><br>
                                ${troca.usuario_nome}<br>
                                <small>${troca.usuario_email}</small>
                            </div>
                            <div>
                                <strong>Item:</strong><br>
                                ${troca.item_nome}<br>
                                <small>Categoria: ${troca.categoria}</small>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                            <div>
                                <strong>Valor:</strong><br>
                                ${troca.custo_xp} XP
                            </div>
                            <div>
                                <strong>Data:</strong><br>
                                ${new Date(troca.data_troca).toLocaleDateString('pt-BR')} ${new Date(troca.data_troca).toLocaleTimeString('pt-BR')}
                            </div>
                        </div>
                        
                        <form method="POST" onsubmit="return confirm('Atualizar status da troca?')">
                            <input type="hidden" name="troca_id" value="${troca.id}">
                            <input type="hidden" name="status_atual" value="${troca.status}">
                            
                            <div class="form-group">
                                <label class="form-label">Status da Troca</label>
                                <select class="form-control" name="novo_status" required>
                                    ${statusOptions}
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Observações</label>
                                <textarea class="form-control" name="observacoes" rows="3" placeholder="Adicione observações sobre a troca...">${troca.observacoes || ''}</textarea>
                            </div>
                            
                            <div class="form-group" style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" id="notificar" name="notificar_usuario" checked>
                                <label for="notificar" class="form-label" style="margin: 0;">Notificar usuário sobre a mudança</label>
                            </div>
                            
                            <div style="display: flex; gap: 10px; margin-top: 20px;">
                                <button type="submit" name="update_status" value="1" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Atualizar Status
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="closeTrocaModal()">
                                    <i class="fas fa-times"></i> Fechar
                                </button>
                            </div>
                        </form>
                    `;
                    
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao carregar detalhes da troca');
                });
        }
        
        function closeTrocaModal() {
            document.getElementById('trocaModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        // ============ FUNÇÕES DE CÓDIGOS ============
        function viewCodigos(itemId) {
            fetch(`ajax/get_codigos.php?item_id=${itemId}`)
                .then(response => response.json())
                .then(data => {
                    let html = '<div class="table-responsive"><table class="table"><thead><tr><th>Código</th><th>Tipo</th><th>Status</th><th>Usuário</th><th>Data</th></tr></thead><tbody>';
                    
                    data.forEach(codigo => {
                        html += `
                            <tr>
                                <td><code>${codigo.codigo_resgate}</code></td>
                                <td>${codigo.tipo || 'unico'}</td>
                                <td><span class="badge ${codigo.status === 'resgatado' ? 'badge-success' : 'badge-info'}">${codigo.status}</span></td>
                                <td>${codigo.usuario_nome || '-'}</td>
                                <td>${codigo.data_resgate ? new Date(codigo.data_resgate).toLocaleDateString('pt-BR') : '-'}</td>
                            </tr>
                        `;
                    });
                    
                    html += '</tbody></table></div>';
                    
                    const modal = document.getElementById('trocaModal');
                    const detailsDiv = document.getElementById('trocaDetails');
                    
                    detailsDiv.innerHTML = `
                        <h4>Códigos de Resgate</h4>
                        ${html}
                        <div style="margin-top: 20px;">
                            <button class="btn btn-secondary" onclick="closeTrocaModal()">Fechar</button>
                        </div>
                    `;
                    
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao carregar códigos');
                });
        }
        
        // ============ FUNÇÕES AUXILIARES ============
        function previewImage(input) {
            const preview = document.getElementById('previewImg');
            const previewDiv = document.getElementById('imagePreview');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    previewDiv.style.display = 'block';
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function gerarCodigoCupom() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let codigo = '';
            for (let i = 0; i < 8; i++) {
                codigo += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('codigoCupom').value = 'CUPOM' + codigo;
        }
        
        function toggleUserMenu() {
            const menu = document.getElementById('userMenu');
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }
        
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
            document.querySelector('.main-content').classList.toggle('expanded');
        }
        
        function quickSearch(query) {
            if (query.length < 2) return;
            
            fetch(`ajax/search.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    // Implementar resultados da busca
                    console.log(data);
                });
        }
        
        function exportData(format) {
            let url = 'ajax/export.php?format=' + format;
            
            if (selectedItems.size > 0) {
                url += '&items=' + Array.from(selectedItems).join(',');
            }
            
            window.open(url, '_blank');
        }
        
        function importItens() {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = '.csv,.xlsx,.json';
            input.onchange = function(e) {
                const file = e.target.files[0];
                if (!file) return;
                
                if (!confirm('Importar itens do arquivo?')) {
                    return;
                }
                
                const formData = new FormData();
                formData.append('file', file);
                formData.append('import_type', 'itens');
                
                fetch('ajax/import.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert('Erro: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao importar arquivo');
                });
            };
            input.click();
        }
        
        function downloadBackup(filename) {
            window.open(`../backups/${filename}`, '_blank');
        }
        
        function deleteBackup(filename) {
            if (confirm('Tem certeza que deseja excluir este backup?')) {
                fetch(`ajax/delete_backup.php?file=${filename}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert('Erro ao excluir backup');
                        }
                    })
                    .catch(error => {
                        console.error('Erro:', error);
                        alert('Erro ao excluir backup');
                    });
            }
        }
        
        function uploadBackup(input) {
            if (!input.files[0]) return;
            
            if (!confirm('ATENÇÃO: Restaurar backup irá substituir dados atuais. Continuar?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('backup_file', input.files[0]);
            
            fetch('ajax/restore_backup.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    setTimeout(() => location.reload(), 2000);
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao restaurar backup');
            });
        }
        
        function resetConfig() {
            if (confirm('Restaurar configurações padrão?')) {
                fetch('ajax/reset_config.php', {method: 'POST'})
                    .then(response => response.json())
                    .then(data => {
                        alert(data.message);
                        location.reload();
                    })
                    .catch(error => {
                        console.error('Erro:', error);
                        alert('Erro ao restaurar configurações');
                    });
            }
        }
        
        function gerarRelatorio() {
            const periodo = document.getElementById('periodoRelatorio').value;
            window.open(`ajax/gerar_relatorio.php?periodo=${periodo}`, '_blank');
        }
        
        // ============ FUNÇÕES DE NAVEGAÇÃO ============
        function showSection(sectionId) {
            // Esconder todas as seções
            document.querySelectorAll('.content-section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Mostrar seção selecionada
            document.getElementById(sectionId).style.display = 'block';
            
            // Atualizar título
            const titles = {
                'dashboard': 'Dashboard da Loja XP',
                'itens': 'Gerenciar Itens da Loja',
                'trocas': 'Histórico de Trocas',
                'estoque': 'Gerenciar Estoque e Códigos',
                'cupons': 'Gerenciar Cupons de Desconto',
                'relatorios': 'Relatórios e Análises',
                'logs': 'Logs de Atividade',
                'config': 'Configurações do Sistema',
                'backup': 'Backup e Restauração'
            };
            document.getElementById('section-title').textContent = titles[sectionId] || 'Dashboard';
            
            // Atualizar menu ativo
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            event.currentTarget.classList.add('active');
            
            // Limpar seleções quando mudar de seção
            if (sectionId !== 'itens') {
                clearSelection();
            }
        }
        
        // ============ INICIALIZAÇÃO ============
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar Select2
            $('.multi-select').select2();
            
            // Configurar evento do select de ações em lote
            document.getElementById('batchActionSelect').addEventListener('change', function() {
                const estoqueInput = document.getElementById('batchEstoqueInput');
                estoqueInput.style.display = this.value === 'update_estoque' ? 'inline-block' : 'none';
            });
            
            // Fechar menus ao clicar fora
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#userMenu') && !e.target.closest('#userMenu + button')) {
                    document.getElementById('userMenu').style.display = 'none';
                }
            });
            
            // Inicializar gráfico
            const ctx = document.getElementById('salesChart')?.getContext('2d');
            if (ctx) {
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun'],
                        datasets: [{
                            label: 'Trocas Realizadas',
                            data: [<?php echo $stats['trocas_hoje'] ?? 0; ?>, 19, 3, 5, 2, 3],
                            borderColor: '#7c3aed',
                            backgroundColor: 'rgba(124, 58, 237, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            }
            
            // Se houver ação de edição, abrir modal
            <?php if ($item_edit): ?>
            setTimeout(() => editItem(<?php echo $item_edit['id']; ?>), 500);
            <?php endif; ?>
            
            // Atualizar notificações periodicamente
            setInterval(() => {
                fetch('ajax/get_notificacoes_count.php')
                    .then(response => response.json())
                    .then(data => {
                        const badge = document.querySelector('.notification-badge');
                        if (badge && data.count !== undefined) {
                            badge.textContent = data.count;
                            badge.style.display = data.count > 0 ? 'flex' : 'none';
                        }
                    })
                    .catch(error => console.error('Erro:', error));
            }, 30000);
        });
    </script>
</body>
</html>