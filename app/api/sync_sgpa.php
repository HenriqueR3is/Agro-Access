<?php
// --- MODO DETETIVE (DEBUG) ---
// Mantenha ativado apenas enquanto estiver testando. Em produção, comente estas 3 linhas.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// -----------------------------

// app/api/sync_sgpa.php

// 1. Cabeçalhos de API
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Cache-Control: no-cache, no-store, must-revalidate");

// --- FUNÇÃO AUXILIAR PARA CORRIGIR O ERRO DO INFINITYFREE ---
function obter_auth_header() {
    $header = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $header = $value;
                break;
            }
        }
    }
    return $header;
}

// --- 2. CONFIGURAÇÃO E SEGURANÇA ---
$secret_key = "@agrodash123"; 
$auth_recebido = obter_auth_header();

if ($auth_recebido !== $secret_key) {
    http_response_code(403);
    echo json_encode([
        'status' => 'erro',
        'mensagem' => 'Acesso negado. Token inválido ou não fornecido.'
    ]);
    exit;
}

// --- 3. CONEXÃO COM BANCO DE DADOS ---
$host    = 'sql107.infinityfree.com';
$port    = 3306;
$db      = 'if0_39840919_agrodash';
$user    = 'if0_39840919';
$pass    = 'QQs4kbmVS7Z';
$charset = 'utf8mb4'; 

$dsn = "mysql:host=$host;dbname=$db;charset=$charset;port=$port";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Falha ao conectar no Banco de Dados.']);
    exit;
}

// --- 4. RECEBE E PROCESSA O JSON ---
$json = file_get_contents('php://input');
$dados = json_decode($json, true);

if (!$dados || empty($dados)) {
    http_response_code(400);
    echo json_encode(['status' => 'erro', 'mensagem' => 'JSON vazio ou inválido.']);
    exit;
}

// --- 5. UPSERT (INSERIR OU ATUALIZAR) ---

$sql = "INSERT INTO producao_sgpa 
        (equipamento_id, data, operacao_id, hectares_sgpa, rpm_medio, velocidade_media, consumo_litros, horas_efetivas, importado_em)
        VALUES 
        (:equip, :data, :oper, :hec, :rpm, :vel, :litros, :horas, NOW())
        ON DUPLICATE KEY UPDATE
        hectares_sgpa    = VALUES(hectares_sgpa),
        rpm_medio        = VALUES(rpm_medio),
        velocidade_media = VALUES(velocidade_media),
        consumo_litros   = VALUES(consumo_litros),
        horas_efetivas   = VALUES(horas_efetivas),
        importado_em     = NOW()";

$stmt = $pdo->prepare($sql);

$sucesso = 0;
$erro_count = 0;
$log_erros = [];

// --- A "OPÇÃO NUCLEAR" ---
// Desliga verificação de chave estrangeira temporariamente para esta sessão
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");

// Inicia Transação
$pdo->beginTransaction();

foreach ($dados as $linha) {
    try {
        // Tratamento do ID
        $equip_id_raw = $linha['equipamento'] ?? '';
        $equip_id = (int) preg_replace('/\D/', '', $equip_id_raw);

        if ($equip_id <= 0) continue;

        // Validação de Data
        $data_op = $linha['data'] ?? null;
        if (!$data_op) continue; 

        // Cast de variáveis
        $operacao_id = isset($linha['operacao_id']) ? (int)$linha['operacao_id'] : 1;
        $hectares    = (float) ($linha['hectares'] ?? 0);
        $rpm         = (int)   ($linha['rpm'] ?? 0);
        $velocidade  = (float) ($linha['velocidade'] ?? 0);
        $consumo     = (float) ($linha['consumo'] ?? 0);
        $horas       = (float) ($linha['horas'] ?? 0);

        $stmt->execute([
            ':equip'  => $equip_id,
            ':data'   => $data_op,
            ':oper'   => $operacao_id,
            ':hec'    => $hectares,
            ':rpm'    => $rpm,
            ':vel'    => $velocidade,
            ':litros' => $consumo,
            ':horas'  => $horas
        ]);
        
        $sucesso++;

    } catch (Exception $e) {
        $erro_count++;
        if (count($log_erros) < 5) {
            $log_erros[] = "Equip $equip_id: " . $e->getMessage();
        }
    }
}

// Comita as alterações no banco
$pdo->commit();

// Reativa a verificação de chave estrangeira (Boa prática)
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

// --- 6. RETORNO ---
echo json_encode([
    'status'              => 'Concluido',
    'recebidos'           => count($dados),
    'processados_sucesso' => $sucesso,
    'falhas'              => $erro_count,
    'erros_amostra'       => $log_erros,
    'modo_nuclear'        => 'ativado' // Flag para confirmar que rodou a versão nova
]);
?>