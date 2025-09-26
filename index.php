<?php
session_start();
require_once __DIR__ . '/config/db/conexao.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $senha = $_POST['password'];

    try {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE nome = ? AND ativo = 1");
        $stmt->execute([$username]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verifica a senha com password_verify
        if ($usuario && password_verify($senha, $usuario['senha'])) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_tipo'] = $usuario['tipo'];

            if ($usuario['tipo'] === 'admin') {
                header("Location: /admin_dashboard");
            } else if ($usuario['tipo'] === 'operador') {
                header("Location: /user_dashboard");
            } else if ($usuario['tipo'] === 'cia_admin') {
                header("Location: /dashboard");
            } else if ($usuario['tipo'] === 'cia_user') {
                header("Location: /dashboard");
            } else if ($usuario['tipo'] === 'cia_dev') {
                header("Location: /dashboard");
            }
            exit;
        } else {
            $erro = "Usuário ou senha inválidos.";
        }
    } catch (PDOException $e) {
        $erro = "Erro ao processar login: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Acompanhamento Agrícola</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/public/static/css/index.css">
    <link rel="icon" type="image/x-icon" href="./public/static/favicon.ico">
</head>
<body>
    <!-- Video de fundo animado -->
    <video autoplay muted loop class="video-background">
        <source src="https://assets.mixkit.co/videos/preview/mixkit-countryside-meadow-4075-large.mp4" type="video/mp4">
        Seu navegador não suporta vídeos HTML5.
    </video>
    
    <!-- Fallback para imagem de fundo -->
    <div class="image-background"></div>
    
    <!-- Overlay escuro -->
    <div class="overlay"></div>
    
    <!-- Efeito de folhas flutuantes -->
    <div class="leaf" style="top: -50px; left: 10%; animation-delay: 0s; font-size: 20px;">🍃</div>
    <div class="leaf" style="top: -50px; left: 30%; animation-delay: 2s; font-size: 25px;">🍂</div>
    <div class="leaf" style="top: -50px; left: 50%; animation-delay: 4s; font-size: 18px;">🌿</div>
    <div class="leaf" style="top: -50px; left: 70%; animation-delay: 6s; font-size: 22px;">🍁</div>
    <div class="leaf" style="top: -50px; left: 90%; animation-delay: 8s; font-size: 15px;">🍃</div>
    
    <!-- Container do formulário -->
    <div class="login-container">
        <div class="login-header">
            <i class="fas fa-leaf"></i>
            <h1>Agro-Access</h1>
            <p>Acompanhamento de Operações</p>
        </div>
        
        <form method="POST">
            <div class="input-group">
                <label for="username">Usuário</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="input-group">
                <label for="password">Senha</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <?php if (isset($erro)): ?>
                <p class='error-message'><?php echo htmlspecialchars($erro); ?></p>
            <?php endif; ?>
            
            <button type="submit" class="btn-login">Entrar</button>
        </form>
    </div>

    <script>
        // Adiciona mais folhas flutuantes dinamicamente
        document.addEventListener('DOMContentLoaded', function() {
            const leaves = ['🍃', '🍂', '🌿', '🍁'];
            const body = document.body;
            
            for (let i = 0; i < 8; i++) {
                const leaf = document.createElement('div');
                leaf.className = 'leaf';
                leaf.innerHTML = leaves[Math.floor(Math.random() * leaves.length)];
                leaf.style.left = Math.random() * 100 + '%';
                leaf.style.top = -50 + 'px';
                leaf.style.fontSize = (15 + Math.random() * 15) + 'px';
                leaf.style.animationDuration = (8 + Math.random() * 7) + 's';
                leaf.style.animationDelay = Math.random() * 10 + 's';
                body.appendChild(leaf);
            }
            
            // Verifica se o vídeo está funcionando, caso contrário mostra apenas a imagem
            const video = document.querySelector('.video-background');
            video.addEventListener('error', function() {
                document.querySelector('.image-background').style.opacity = '0.3';
            });
        });
    </script>
</body>
</html>