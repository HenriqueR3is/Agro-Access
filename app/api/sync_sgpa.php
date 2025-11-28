<?php
// app/api/sync_sgpa.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

// Função Auth
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

$host = 'sql107.infinityfree.com'; $db = 'if0_39840919_agrodash'; $user = 'if0_39840919'; $pass = 'QQs4kbmVS7Z';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4;port=3306", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (\PDOException $e) {
    http_response_code(500); exit(json_encode(['status' => 'erro', 'mensagem' => 'Erro Banco']));
}

$dados = json_decode(file_get_contents('php://input'), true);
if (!$dados) { http_response_code(400); exit(json_encode(['status' => 'erro', 'mensagem' => 'JSON inválido'])); }

// UPSERT com nome_equipamento
$sql = "INSERT INTO producao_sgpa 
        (equipamento_id, nome_equipamento, unidade, frente, nome_operacao, operador, data, operacao_id, hectares_sgpa, rpm_medio, velocidade_media, consumo_litros, horas_efetivas, importado_em)
        VALUES 
        (:equip, :nome_equip, :uni, :frente, :nome_op, :operador, :data, :oper_id, :hec, :rpm, :vel, :litros, :horas, NOW())
        ON DUPLICATE KEY UPDATE
        nome_equipamento = VALUES(nome_equipamento),
        unidade          = VALUES(unidade),
        frente           = VALUES(frente),
        nome_operacao    = VALUES(nome_operacao),
        operador         = VALUES(operador),
        hectares_sgpa    = VALUES(hectares_sgpa),
        rpm_medio        = VALUES(rpm_medio),
        velocidade_media = VALUES(velocidade_media),
        consumo_litros   = VALUES(consumo_litros),
        horas_efetivas   = VALUES(horas_efetivas),
        importado_em     = NOW()";

$stmt = $pdo->prepare($sql);
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");

$sucesso = 0; $erros = [];

foreach ($dados as $linha) {
    try {
        // ID numérico para o vínculo
        $equip_id = (int) preg_replace('/\D/', '', $linha['equipamento'] ?? '');
        
        if ($equip_id <= 0 || empty($linha['data'])) continue;

        $stmt->execute([
            ':equip'      => $equip_id,
            ':nome_equip' => $linha['nome_equipamento'] ?? $linha['equipamento'], // Salva o nome bonito
            ':uni'        => $linha['unidade'] ?? null,
            ':frente'     => $linha['frente'] ?? null,
            ':nome_op'    => $linha['nome_operacao'] ?? null,
            ':operador'   => $linha['operador'] ?? null,
            ':data'       => $linha['data'],
            ':oper_id'    => 1,
            ':hec'        => $linha['hectares'],
            ':rpm'        => $linha['rpm'],
            ':vel'        => $linha['velocidade'],
            ':litros'     => $linha['consumo'],
            ':horas'      => $linha['horas']
        ]);
        $sucesso++;
    } catch (Exception $e) {
        if(count($erros) < 3) $erros[] = $e->getMessage();
    }
}

$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
echo json_encode(['status' => 'Concluido', 'processados' => $sucesso, 'erros' => $erros]);
?>