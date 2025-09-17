<?php
$host = 'localhost';
$port = 3306;
$db   = 'agrodash';
$user = 'root';
$pass = '';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_general_ci"
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Reforço defensivo (alguns hosts precisam)
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET character_set_client = utf8mb4");
    $pdo->exec("SET character_set_connection = utf8mb4");
    $pdo->exec("SET character_set_results = utf8mb4");
} catch (PDOException $e) {
    exit('Erro de conexão: ' . $e->getMessage());
}
