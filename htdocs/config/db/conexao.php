<?php
$host = "sql107.infinityfree.com";
$port = 3306;
$dbname = "if0_39840919_agrodash";
$user = "if0_39840919";
$password = "QQs4kbmVS7Z";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;
    charset=utf8mb4", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}






?>

