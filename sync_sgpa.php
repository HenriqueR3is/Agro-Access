<?php
// app/api/sync_sgpa.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// --- AUTENTICAÇÃO ---
function obter_auth_header() {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) return $_SERVER['HTTP_AUTHORIZATION'];
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) if (strtolower($key) === 'authorization') return $value;
    }
    return null;
}

$secret_key = "@agrodash123"; 
if (obter_auth_header() !== $secret_key) {
    http_response_code(403); exit(json_encode(['status' => 'erro', 'mensagem' => 'Token inválido']));
}

// --- CONEXÃO ---
$host = 'sql107.infinityfree.com'; $db = 'if0_39840919_agrodash'; $user = 'if0_39840919'; $pass = 'QQs4kbmVS7Z';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4;port=3306", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (\PDOException $e) {
    http_response_code(500); exit(json_encode(['status' => 'erro', 'mensagem' => 'Erro Banco']));
}

$dados = json_decode(file_get_contents('php://input'), true);
if (!$dados) { http_response_code(400); exit(json_encode(['status' => 'erro', 'mensagem' => 'JSON vazio'])); }

// --- MODO LIMPEZA (Acionado apenas na primeira chamada) ---
if (isset($_GET['modo']) && $_GET['modo'] === 'limpar') {
    $datas_para_limpar = [];
    foreach ($dados as $d) {
        if (!empty($d['data'])) $datas_para_limpar[$d['data']] = true;
    }
    
    if (!empty($datas_para_limpar)) {
        $lista_datas = array_keys($datas_para_limpar);
        $inQuery = implode(',', array_fill(0, count($lista_datas), '?'));
        $stmt_del = $pdo->prepare("DELETE FROM producao_sgpa WHERE data IN ($inQuery)");
        $stmt_del->execute($lista_datas);
        echo json_encode(['status' => 'Limpeza Concluida', 'datas' => $lista_datas]);
    } else {
        echo json_encode(['status' => 'Nada a limpar']);
    }
    exit; // Para aqui, não insere nada
}

// --- MODO GRAVAÇÃO (Inserção Normal) ---
// Note que removemos o DELETE daqui para não apagar os lotes anteriores
$sql = "INSERT INTO producao_sgpa 
        (equipamento_id, nome_equipamento, unidade, frente, nome_operacao, operador, data, hectares_sgpa, rpm_medio, velocidade_media, consumo_litros, horas_efetivas, importado_em)
        VALUES 
        (:id, :nome, :uni, :frente, :op_nome, :operador, :data, :hec, :rpm, :vel, :litros, :horas, NOW())";

$stmt = $pdo->prepare($sql);
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");

$sucesso = 0; $erros = [];

foreach ($dados as $linha) {
    try {
        $equip_id = (int) preg_replace('/\D/', '', $linha['equipamento'] ?? '');
        if ($equip_id <= 0 || empty($linha['data'])) continue;

        $stmt->execute([
            ':id'       => $equip_id,
            ':nome'     => $linha['nome_equipamento'] ?? $linha['equipamento'],
            ':uni'      => $linha['unidade'] ?? null,
            ':frente'   => $linha['frente'] ?? null,
            ':op_nome'  => $linha['nome_operacao'] ?? null,
            ':operador' => $linha['operador'] ?? null,
            ':data'     => $linha['data'],
            ':hec'      => $linha['hectares'],
            ':rpm'      => $linha['rpm'],
            ':vel'      => $linha['velocidade'],
            ':litros'   => $linha['consumo'],
            ':horas'    => $linha['horas']
        ]);
        $sucesso++;
    } catch (Exception $e) {
        if(count($erros) < 3) $erros[] = $e->getMessage();
    }
}

$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
echo json_encode(['status' => 'Gravado', 'processados' => $sucesso, 'erros' => $erros]);
?>