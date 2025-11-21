<?php
session_start();
require_once __DIR__ . '/../config/db/conexao.php';

$email = $_POST['email'] ?? '';
$senha = $_POST['senha'] ?? '';

if ($email && $senha) {
    // Buscar usuário pelo email
    $sql = $pdo->prepare("SELECT id, nome, email, senha, tipo FROM usuarios WHERE email = ?");
    $sql->execute([$email]);
    $user = $sql->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Verificar a senha usando password_verify
        if (password_verify($senha, $user['senha'])) {
            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['usuario_nome'] = $user['nome'];
            $_SESSION['usuario_tipo'] = $user['tipo'];
            header("Location: curso.php");
            exit;
        }
    }
}

// Se chegou aqui, login falhou
echo "<script>alert('Usuário ou senha incorretos.');window.location='login.php';</script>";
