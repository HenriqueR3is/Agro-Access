<?php
// FERRAMENTA DE IMPORTAÇÃO MANUAL DE SQL
// Segurança básica: Mude isso para algo difícil depois
$senha_acesso = "12345"; 

ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- SEUS DADOS DO INFINITYFREE ---
$host = 'sql107.infinityfree.com';
$port = 3306;
$db   = 'if0_39840919_agrodash';
$user = 'if0_39840919';
$pass = 'QQs4kbmVS7Z';
// ----------------------------------

$msg = "";

if (isset($_POST['sql_cmd']) && $_POST['access'] === $senha_acesso) {
    $mysqli = new mysqli($host, $user, $pass, $db);
    
    if ($mysqli->connect_error) {
        $msg = "<div style='color:red'>Erro de Conexão: " . $mysqli->connect_error . "</div>";
    } else {
        $sql = $_POST['sql_cmd'];
        
        // Tenta rodar múltiplos comandos (ex: dump inteiro)
        if ($mysqli->multi_query($sql)) {
            $msg = "<div style='color:green'><strong>Sucesso!</strong> Comandos executados. Verifique se houve erros abaixo.</div>";
            do {
                if ($result = $mysqli->store_result()) {
                    $result->free();
                }
            } while ($mysqli->more_results() && $mysqli->next_result());
        } else {
            $msg = "<div style='color:red'>Erro no SQL: " . $mysqli->error . "</div>";
        }
        $mysqli->close();
    }
}
?>

<!DOCTYPE html>
<html>
<head><title>SQL Runner</title></head>
<body style="font-family: sans-serif; padding: 20px;">
    <h2>Executor SQL de Emergência</h2>
    <?= $msg ?>
    
    <form method="post">
        <p>Senha de segurança: <input type="password" name="access" required></p>
        <p>Cole seu SQL abaixo (CREATE TABLE, INSERT, etc):</p>
        <textarea name="sql_cmd" style="width:100%; height:300px;" placeholder="Cole o conteúdo do seu arquivo .sql aqui..."></textarea>
        <br><br>
        <button type="submit" style="padding: 10px 20px; cursor:pointer;">EXECUTAR NO BANCO</button>
    </form>
</body>
</html>