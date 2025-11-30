<?php
// ver_banco.php
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

$host = 'sql107.infinityfree.com'; $db = 'if0_39840919_agrodash'; $user = 'if0_39840919'; $pass = 'QQs4kbmVS7Z';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4;port=3306", $user, $pass);
} catch (PDOException $e) { die("Erro Conexão: " . $e->getMessage()); }

echo "<h2>📊 Raio-X do Banco de Dados</h2>";

// 1. SOMA TOTAL GERAL (Sem filtro de data)
$total = $pdo->query("SELECT SUM(hectares_sgpa) FROM producao_sgpa")->fetchColumn();
echo "<h3>Total Absoluto no Banco: <span style='color:red'>" . number_format($total, 2, ',', '.') . " ha</span></h3>";
echo "<p><em>(Se este número for 688, o erro é no filtro do Painel. Se for menor, o erro é na gravação).</em></p>";

echo "<hr>";

// 2. SOMA POR DATA (Para ver se o relatório quebrou em dois dias)
$sql = "SELECT data, SUM(hectares_sgpa) as hec, COUNT(*) as qtd 
        FROM producao_sgpa 
        GROUP BY data 
        ORDER BY data DESC";
$lista = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr style='background:#ccc'><th>Data</th><th>Hectares</th><th>Qtd Máquinas</th></tr>";

foreach($lista as $l) {
    echo "<tr>";
    echo "<td>" . date('d/m/Y', strtotime($l['data'])) . "</td>";
    echo "<td><strong>" . number_format($l['hec'], 2, ',', '.') . "</strong></td>";
    echo "<td>" . $l['qtd'] . "</td>";
    echo "</tr>";
}
echo "</table>";
?>