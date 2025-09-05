<?php
$host = "localhost";
$port = 3306;
$dbname = "agrodash";
$user = "root";
$password = "";

try {
    $pdo = new PDO("mysql:charset=utf8mb4;host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}

?>
